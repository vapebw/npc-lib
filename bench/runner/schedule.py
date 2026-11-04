"""Run ordering: samples of different cells are interleaved and the order is rotated each round so that
no cell is always measured first (cold disk cache, thermal state) or always right after the same neighbour."""
from experiments import Cell


def interleave(cells: list[Cell], samples: int | None) -> list[tuple[Cell, int]]:
    rounds = samples if samples is not None else max((cell.samples for cell in cells), default=0)
    plan: list[tuple[Cell, int]] = []
    for sample in range(1, rounds + 1):
        active = [cell for cell in cells if samples is not None or cell.samples >= sample]
        if not active:
            continue
        shift = (sample - 1) * 7 % len(active)
        ordered = active[shift:] + active[:shift]
        plan += [(cell, sample) for cell in (reversed(ordered) if sample % 2 == 0 else ordered)]
    return plan
