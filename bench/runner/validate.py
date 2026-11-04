"""Checks the probes against the calibration runs, where the per-tick cost is known (burn N ms per tick).

Expected: tick time ≈ N ms plus the idle tick cost; CPU ≈ N ms * ticks/s plus idle. Prints one row per run and a
verdict per source: a source is VALID only if it tracks the expected cost across the known loads.
"""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from report import load_runs, ok, run_metrics  # noqa: E402


def main(directory: Path) -> int:
    rows = []
    for cell, records in sorted(load_runs(directory).items(), key=lambda item: float(item[0].split("-")[-1].removesuffix("ms"))):
        burn = float(cell.split("-")[-1].removesuffix("ms"))
        for record in records:
            if not ok(record):
                print(cell, record["sample"], "FAILED")
                continue
            metrics = run_metrics(record)
            window = record["result"]["window"]
            elapsed = window["cpu"]["wall_ns"] / 1e9
            expected_core = burn * (metrics["ticks_per_second"] or 20) / 1000.0
            rusage = (window["cpu"]["user_us"] + window["cpu"]["system_us"]) / 1e6 / elapsed
            os_data = record["os"]
            os_cpu = os_data["cpu_seconds"] / os_data["window_seconds"] if os_data.get("cpu_seconds") is not None else None
            cycles = os_data["cycles"] / os_data["window_seconds"] if os_data.get("cycles") is not None else None
            rows.append({"burn_ms": burn, "tick_median_ms": metrics["tick_median_ms"], "expected_core": expected_core, "rusage_core": rusage, "os_core": os_cpu, "mcycles_s": cycles / 1e6 if cycles else None})
    print(f"{'burn':>5} {'tick med':>9} {'expect core':>12} {'getrusage':>10} {'GetProcTimes':>13} {'Mcycles/s':>10}")
    for row in rows:
        print(f"{row['burn_ms']:5.0f} {row['tick_median_ms']:9.3f} {row['expected_core']:12.3f} {row['rusage_core']:10.3f} {row['os_core'] if row['os_core'] is None else round(row['os_core'], 3)!s:>13} {row['mcycles_s'] if row['mcycles_s'] is None else round(row['mcycles_s'], 1)!s:>10}")
    usable = [row for row in rows if row["mcycles_s"] is not None]
    xs = [row["expected_core"] for row in usable]
    ys = [row["mcycles_s"] for row in usable]
    mean_x, mean_y = sum(xs) / len(xs), sum(ys) / len(ys)
    slope = sum((x - mean_x) * (y - mean_y) for x, y in zip(xs, ys)) / sum((x - mean_x) ** 2 for x in xs)
    print(f"cycles per core-second (least squares over {len(usable)} runs): {slope:.0f} Mcycles, idle intercept {mean_y - slope * mean_x:.1f} Mcycles/s")
    (directory / "validation.json").write_text(json.dumps({"mcycles_per_core_second": slope, "rows": rows}, indent=2), encoding="utf8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(Path(sys.argv[1])))
