"""Runs one scenario in one clean Quark process and collects the plugin result plus external OS counters."""
import json
import re
import shutil
import subprocess
import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import quarkserver  # noqa: E402

from counters import ProcessSampler, interpolate  # noqa: E402
from viewers import Viewers  # noqa: E402

PORT = 29184
PHAR = quarkserver.ROOT / "work/artifacts/NpcLibBench.phar"
ERROR_LINE = re.compile(r"\b(ERROR|CRITICAL|EMERG|ALERT)\b|Fatal error|Uncaught")


def execute(scenario: dict, out_dir: Path) -> dict:
    """Runs the scenario and returns the runner-side record, which is also saved as run.json."""
    shutil.rmtree(out_dir, ignore_errors=True)
    data = out_dir / "server"
    plugins = data / "plugins"
    plugins.mkdir(parents=True)
    scenario = {**scenario, "out": str(out_dir / "result.json")}
    (out_dir / "scenario.json").write_text(json.dumps(scenario, indent=2), encoding="utf8")
    env = quarkserver.environment(NPCBENCH_SCENARIO=str(out_dir / "scenario.json"))
    quarkserver.initialize(data, plugins, env)
    shutil.copy2(PHAR, plugins / "NpcLibBench.phar")
    quarkserver.write_config(data, PORT, raknet=True)

    lines: list[str] = []
    ready = threading.Event()
    started = time.time()
    process = subprocess.Popen(quarkserver.command(data, plugins), cwd=quarkserver.ROOT, env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

    def read_console() -> None:
        with (out_dir / "console.log").open("wb") as log:
            for raw in process.stdout:  # type: ignore[union-attr]
                log.write(raw)
                text = raw.decode("utf8", errors="replace")
                lines.append(text)
                if "Done (" in text:
                    ready.set()

    threading.Thread(target=read_console, daemon=True).start()
    sampler = ProcessSampler(process.pid)
    sampler.start()
    viewers = Viewers(PORT, [f"V{i + 1}" for i in range(scenario["viewers"])]) if scenario["viewers"] else None
    visible_polls: list[dict] = []
    timed_out = False
    try:
        deadline_ready = time.time() + 90
        while not ready.wait(0.25):
            if process.poll() is not None or time.time() > deadline_ready:
                raise RuntimeError("server did not become ready (exit code %s)" % process.poll())
        if viewers:
            viewers.join()
        deadline = time.time() + scenario["max_seconds"] + 60
        next_poll = 0.0
        cycle = scenario.get("viewer_cycle_seconds")
        next_cycle = time.time() + 14.0 if cycle else None
        cycling_in = False
        while process.poll() is None:
            if time.time() > deadline:
                timed_out = True
                break
            if next_cycle is not None and time.time() >= next_cycle:
                # one extra viewer alternates between joining and leaving: new viewer and reconnection traffic
                if cycling_in:
                    viewers.leave(["C1"])
                else:
                    viewers.join(["C1"])
                cycling_in = not cycling_in
                next_cycle = time.time() + cycle
            if viewers and time.time() >= next_poll:
                try:
                    stats = viewers.stats(timeout=10)["stats"]
                    visible_polls.append({"t": time.time(), "visible": {name: item["visible"] for name, item in stats.items()}})
                except Exception:  # noqa: BLE001 viewer poll failures are recorded as missing polls
                    pass
                next_poll = time.time() + 2.0
            time.sleep(0.2)
        if timed_out:
            process.kill()
        code = process.wait(timeout=60)
    finally:
        if process.poll() is None:
            process.kill()
        sampler.stop()
    viewer_stats = viewers.stats(timeout=20) if viewers else None
    if viewers:
        viewers.close()
    result_path = out_dir / "result.json"
    result = json.loads(result_path.read_text(encoding="utf8")) if result_path.exists() else None
    record = {
        "scenario": scenario,
        "exit_code": code,
        "timed_out": timed_out,
        "wall_seconds": time.time() - started,
        "console_error_lines": [line.rstrip() for line in lines if ERROR_LINE.search(line)][:20],
        "result_present": result is not None,
        "os": os_summary(sampler.samples, result),
        "os_samples": sampler.samples[:: max(1, len(sampler.samples) // 100)],
        "viewers": viewer_summary(viewer_stats, visible_polls, result) if viewers else None,
    }
    (out_dir / "run.json").write_text(json.dumps(record, indent=2), encoding="utf8")
    return record


def os_summary(samples: list[dict], result: dict | None) -> dict:
    summary: dict = {"samples": len(samples)}
    window = (result or {}).get("window")
    if not window or not samples:
        return summary
    start, end = window["wall_start"], window["wall_end"]
    cpu = [interpolate(samples, t, "cpu_s") for t in (start, end)]
    cycles = [interpolate(samples, t, "cycles") for t in (start, end)]
    inside = [s for s in samples if start <= s["t"] <= end]
    if None not in cpu:
        summary["cpu_seconds"] = cpu[1] - cpu[0]
    if None not in cycles:
        summary["cycles"] = cycles[1] - cycles[0]
    if inside:
        summary["working_set_start"] = inside[0]["working_set"]
        summary["working_set_end"] = inside[-1]["working_set"]
        summary["working_set_max"] = max(s["working_set"] for s in inside)
        summary["private_start"] = inside[0]["private"]
        summary["private_end"] = inside[-1]["private"]
        summary["private_max"] = max(s["private"] for s in inside)
    summary["window_seconds"] = end - start
    return summary


def viewer_summary(stats: dict | None, polls: list[dict], result: dict | None) -> dict:
    summary: dict = {"errors": (stats or {}).get("errors", [])}
    window = (result or {}).get("window")
    if window:
        inside = [poll for poll in polls if window["wall_start"] <= poll["t"] <= window["wall_end"]]
        summary["visible_in_window"] = {name: [min(p["visible"][name] for p in inside), max(p["visible"][name] for p in inside)] for name in inside[0]["visible"]} if inside else None
    if not stats or not window:
        return summary
    start, end = int(window["wall_start"]) + 1, int(window["wall_end"])
    per_viewer: dict[str, dict] = {}
    for name, item in stats["stats"].items():
        totals: dict[str, dict] = {}
        for second, cell in item["buckets"].items():
            if start <= int(second) < end:
                for packet, entry in cell.items():
                    slot = totals.setdefault(packet, {"count": 0, "bytes": 0})
                    slot["count"] += entry["count"]
                    slot["bytes"] += entry["bytes"]
        per_viewer[name] = {"spawned": item["spawned"], "connected": item["connected"], "visible_at_end": item["visible"], "window_packets": totals}
    summary["window_seconds_counted"] = end - start
    summary["per_viewer"] = per_viewer
    return summary
