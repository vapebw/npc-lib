"""Small descriptive statistics; no external dependencies."""
import math
import statistics


def percentile(values: list[float], fraction: float) -> float:
    """Linear-interpolated percentile (fraction in 0..1)."""
    ordered = sorted(values)
    if not ordered:
        raise ValueError("no values")
    position = (len(ordered) - 1) * fraction
    low, high = math.floor(position), math.ceil(position)
    return ordered[low] + (ordered[high] - ordered[low]) * (position - low)


def summarize(values: list[float]) -> dict[str, float | int | None]:
    """Median, p95, dispersion and range. p95 and stdev are None when there are too few samples."""
    if not values:
        return {"n": 0, "median": None, "mean": None, "p95": None, "min": None, "max": None, "stdev": None, "mad": None}
    median = statistics.median(values)
    return {
        "n": len(values),
        "median": median,
        "mean": statistics.fmean(values),
        "p95": percentile(values, 0.95) if len(values) >= 20 else None,
        "min": min(values),
        "max": max(values),
        "stdev": statistics.stdev(values) if len(values) > 1 else None,
        "mad": statistics.median(abs(v - median) for v in values),
    }
