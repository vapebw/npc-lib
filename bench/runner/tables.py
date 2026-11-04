"""Markdown comparison tables built from summary.json. Pairs cells that differ only by the mode token."""
import json
from pathlib import Path

COLUMNS = {
    "tick_median_ms": ("tick median ms", 3),
    "tick_p99_ms": ("tick p99 ms", 3),
    "ticks_per_second": ("ticks/s", 2),
    "mcycles_per_s": ("Mcycles/s", 1),
    "cpu_percent_core_est": ("CPU % of one core (est.)", 2),
    "heap_used_mb": ("heap used MB", 1),
    "private_mb": ("private MB", 1),
    "create_per_entity_us": ("create µs/entity", 0),
    "remove_total_ms": ("remove ms", 1),
    "transport_sent_kb_s": ("sent KB/s", 2),
    "population_queued": ("queued", 0),
    "harness_step_mean_us": ("harness step µs", 1),
    "retained_after_gc_kb": ("retained after gc KB", 0),
}


def counterpart(name: str) -> str | None:
    return name.replace("npclib", "native", 1) if "npclib" in name else None


def cell_text(metric: dict | None, digits: int) -> str:
    if not metric or metric["median"] is None:
        return "n/a"
    spread = f" ±{metric['mad']:.{digits}f}" if metric["mad"] is not None and metric["n"] > 1 else ""
    return f"{metric['median']:.{digits}f}{spread}"


def verdict(left: dict | None, right: dict | None) -> str:
    if not left or not right or left["median"] is None or right["median"] is None:
        return ""
    delta = left["median"] - right["median"]
    noise = max(left["mad"] or 0.0, right["mad"] or 0.0) * 2
    if left["n"] < 3 or right["n"] < 3:
        return "n<3"
    return "within spread" if abs(delta) <= noise else ("higher" if delta > 0 else "lower")


def comparison(summary: dict, prefix: str, columns: list[str]) -> str:
    """One table per experiment prefix: for every npclib cell its native counterpart and the difference."""
    lines = ["| cell | n (lib/native) | " + " | ".join(f"{COLUMNS[c][0]} lib / native / Δ" for c in columns) + " |", "|---|---|" + "---|" * len(columns)]
    for name in sorted(summary):
        other = counterpart(name)
        if not name.startswith(prefix) or other is None or other not in summary:
            continue
        lib, native = summary[name], summary[other]
        cells = []
        for column in columns:
            digits = COLUMNS[column][1]
            a, b = lib["metrics"].get(column), native["metrics"].get(column)
            delta = "" if not a or not b or a["median"] is None or b["median"] is None else f"{a['median'] - b['median']:+.{digits}f}"
            cells.append(f"{cell_text(a, digits)} / {cell_text(b, digits)} / {delta} {verdict(a, b)}".strip())
        lines.append(f"| {name.replace('npclib-', '')} | {lib['valid_runs']}/{native['valid_runs']} | " + " | ".join(cells) + " |")
    return "\n".join(lines)


def standalone(summary: dict, prefix: str, columns: list[str]) -> str:
    lines = ["| cell | n | " + " | ".join(COLUMNS[c][0] for c in columns) + " |", "|---|---|" + "---|" * len(columns)]
    for name in sorted(summary):
        if name.startswith(prefix):
            metrics = summary[name]["metrics"]
            lines.append(f"| {name} | {summary[name]['valid_runs']}/{summary[name]['runs']} | " + " | ".join(cell_text(metrics.get(c), COLUMNS[c][1]) for c in columns) + " |")
    return "\n".join(lines)


def load(directory: Path) -> dict:
    return json.loads((directory / "summary.json").read_text(encoding="utf8"))
