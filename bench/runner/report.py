"""Aggregation of run records into summary.json (machine readable) and summary.md (tables)."""
import json
from pathlib import Path

from stats import summarize

CALIBRATION = Path(__file__).resolve().parents[2] / "work/bench/calibration/validation.json"


def cycles_per_core_second() -> float | None:
    """Mcycles per core-second measured by the calibration runs, None when they were not run."""
    return json.loads(CALIBRATION.read_text(encoding="utf8"))["mcycles_per_core_second"] if CALIBRATION.exists() else None


def load_runs(directory: Path) -> dict[str, list[dict]]:
    cells: dict[str, list[dict]] = {}
    for run_file in sorted(directory.glob("*/sample-*/run.json")):
        record = json.loads(run_file.read_text(encoding="utf8"))
        result_file = run_file.parent / "result.json"
        record["result"] = json.loads(result_file.read_text(encoding="utf8")) if result_file.exists() else None
        record["sample"] = run_file.parent.name
        cells.setdefault(run_file.parent.parent.name, []).append(record)
    return cells


def ok(record: dict) -> bool:
    return bool(record.get("result_present")) and record.get("exit_code") == 0 and not record.get("timed_out") and not (record["result"] or {}).get("errors")


def tick_milliseconds(result: dict) -> list[float]:
    return [duration / 1e6 for _, duration in result["window"]["ticks"]["ticks"]]


def run_metrics(record: dict) -> dict[str, float | None]:
    """One flat row of comparable numbers for a successful run."""
    result = record["result"]
    window = result["window"]
    ticks = tick_milliseconds(result)
    elapsed = window["cpu"]["wall_ns"] / 1e9
    calibration = cycles_per_core_second()
    ordered = sorted(ticks)
    phases = result["phases"]
    memory = window["memory"]
    os_data = record["os"]
    row: dict[str, float | None] = {
        "tick_median_ms": ordered[len(ordered) // 2] if ordered else None,
        "tick_mean_ms": sum(ticks) / len(ticks) if ticks else None,
        "tick_p95_ms": ordered[int(len(ordered) * 0.95)] if ordered else None,
        "tick_p99_ms": ordered[int(len(ordered) * 0.99)] if ordered else None,
        "tick_max_ms": ordered[-1] if ordered else None,
        "ticks_per_second": window["ticks"]["tick_count"] / elapsed if elapsed else None,
        "mcycles_per_s": os_data["cycles"] / os_data["window_seconds"] / 1e6 if os_data.get("cycles") is not None and os_data.get("window_seconds") else None,
        "cpu_percent_core_est": 100 * os_data["cycles"] / os_data["window_seconds"] / 1e6 / calibration if calibration and os_data.get("cycles") is not None and os_data.get("window_seconds") else None,
        "heap_used_mb": memory["used"] / 1048576,
        "heap_allocated_mb": memory["allocated"] / 1048576,
        "private_mb": os_data["private_end"] / 1048576 if "private_end" in os_data else None,
        "working_set_mb": os_data["working_set_end"] / 1048576 if "working_set_end" in os_data else None,
        "transport_sent_kb_s": window["network"]["transport_sent_bytes"] / 1024 / elapsed if elapsed else None,
    }
    if "create" in phases:
        row["create_total_ms"] = phases["create"]["total_ns"] / 1e6
        row["create_per_entity_us"] = phases["create"]["total_ns"] / max(1, phases["create"]["count"]) / 1e3
        row["populate_heap_mb"] = (phases["memory_after_populate"]["used"] - phases["memory_before_populate"]["used"]) / 1048576
    if "remove" in phases:
        row["remove_total_ms"] = phases["remove"]["total_ns"] / 1e6
        row["retained_after_gc_kb"] = (phases["memory_after_gc"]["used"] - phases["memory_before_populate"]["used"]) / 1024
        row["weak_alive"] = phases["weak_references"]["alive_after_gc"]
    state = result.get("window_start_state", {})
    row["population_queued"] = state.get("population_queued")
    harness = window.get("harness_step_ns") or []
    row["harness_step_mean_us"] = sum(harness) / len(harness) / 1e3 if harness else None
    return row


def summarize_cell(records: list[dict]) -> dict:
    good = [record for record in records if ok(record)]
    rows = [run_metrics(record) for record in good]
    metrics = {key: summarize([row[key] for row in rows if row.get(key) is not None]) for key in (rows[0] if rows else {})}
    return {
        "runs": len(records), "valid_runs": len(good),
        "failed": [{"sample": r["sample"], "exit_code": r.get("exit_code"), "timed_out": r.get("timed_out"), "errors": (r.get("result") or {}).get("errors"), "runner_error": r.get("runner_error"), "console_errors": r.get("console_error_lines")} for r in records if not ok(r)],
        "metrics": metrics,
        "samples": {record["sample"]: row for record, row in zip(good, rows)},
    }


def summarize_directory(directory: Path) -> dict:
    summary = {name: summarize_cell(records) for name, records in load_runs(directory).items()}
    (directory / "summary.json").write_text(json.dumps(summary, indent=2), encoding="utf8")
    print(f"{len(summary)} cells summarized into {directory / 'summary.json'}")
    return summary
