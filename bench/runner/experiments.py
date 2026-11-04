"""Experiment definitions. A cell is one scenario that is run several times, each time in a clean server process."""
from dataclasses import dataclass, field


@dataclass(frozen=True)
class Cell:
    name: str
    scenario: dict
    samples: int = 5
    tags: tuple[str, ...] = field(default=())


def scenario(mode: str, type_: str = "mixed", count: int = 0, *, layout: str = "spread", viewers: int = 0, warmup: float = 8.0,
             window: float = 20.0, workload: dict | None = None, managers: int = 0, count_packets: bool = False, pulse: bool = True) -> dict:
    # creating 1000 entities takes a while, so the hard limit grows with the population and the window
    limit = int(warmup + window + 90 + count * 0.05 + viewers * 10)
    return {
        "id": "", "mode": mode, "type": type_, "count": count, "layout": layout, "viewers": viewers, "warmup_seconds": warmup,
        "window_seconds": window, "count_packets": count_packets, "pulse": pulse, "managers": managers,
        "workload": workload or {"kind": "idle"}, "max_seconds": limit, "max_memory_mb": 3500,
    }


def smoke() -> list[Cell]:
    return [Cell("smoke-npclib-mixed-100", scenario("npclib", count=100, warmup=3, window=5), 1), Cell("smoke-native-mixed-100", scenario("native", count=100, warmup=3, window=5), 1)]


def calibration() -> list[Cell]:
    """Known per-tick cost, used to validate tick time and the CPU counters."""
    return [Cell(f"calibration-burn-{ms}ms", scenario("empty", workload={"kind": "burn", "milliseconds": ms}), 3) for ms in (0, 2, 5, 10, 20)]


def control() -> list[Cell]:
    cells = [Cell(f"control-empty-v{v}", scenario("empty", viewers=v)) for v in (0, 1, 2, 4)]
    cells.append(Cell("control-empty-v0-nopulse", scenario("empty", pulse=False), 3))
    cells.append(Cell("control-npclib-mixed-500-nopulse", scenario("npclib", count=500, pulse=False), 3))
    return cells


def scale() -> list[Cell]:
    cells = []
    for count in (100, 500, 1000):
        for type_ in ("player", "villager", "zombie", "mixed"):
            for mode in ("npclib", "native"):
                cells.append(Cell(f"scale-{mode}-{type_}-{count}", scenario(mode, type_, count)))
        cells.append(Cell(f"scale-native_nogravity-mixed-{count}", scenario("native_nogravity", "mixed", count), 3))
    return cells


def viewers() -> list[Cell]:
    cells = []
    for layout in ("spread", "concentrated"):
        for count_viewers in (1, 2, 4):
            for mode in ("npclib", "native"):
                cells.append(Cell(f"viewers-{mode}-mixed-500-{layout}-v{count_viewers}", scenario(mode, "mixed", 500, layout=layout, viewers=count_viewers)))
    return cells


def scale_viewers() -> list[Cell]:
    return [Cell(f"scalev-{mode}-mixed-{count}-v2", scenario(mode, "mixed", count, viewers=2), 3) for count in (100, 1000) for mode in ("npclib", "native")]


def operations() -> list[Cell]:
    cells = []
    for operation in ("equip", "scale", "rotate", "teleport", "skin"):
        for mode in ("npclib", "native"):
            cells.append(Cell(f"ops-{mode}-{operation}", scenario(mode, "player", 100, viewers=2, count_packets=True, window=15,
                                                                    workload={"kind": "mutate", "operation": operation, "per_tick": 10}), 3))
    return cells


def scale_traffic() -> list[Cell]:
    """Same scale changes against native entities as they are, woken after each change, and the lib, in both layouts."""
    cells = []
    for layout in ("spread", "concentrated"):
        for mode, wake in (("native", False), ("native", True), ("npclib", False)):
            tag = f"{mode}{'-woken' if wake else ''}"
            cells.append(Cell(f"scaletraffic-{tag}-{layout}", scenario(mode, "player", 100, layout=layout, viewers=2, count_packets=True, window=15,
                                                                         workload={"kind": "mutate", "operation": "scale", "per_tick": 10, "wake": wake}), 3))
    return cells


def environmental() -> list[Cell]:
    """Cost of keeping chunk ticks (the optional environmental flag) against the sleeping default and native entities."""
    cells = []
    for count in (500, 1000):
        for tag, mode, flag in (("npclib", "npclib", False), ("npclib-env", "npclib", True), ("native", "native", False)):
            cells.append(Cell(f"environmental-{tag}-mixed-{count}-v2", {**scenario(mode, "mixed", count, viewers=2), "environmental": flag}, 3))
    return cells


def viewer_churn() -> list[Cell]:
    """One persistent viewer plus one that leaves and rejoins every few seconds (new viewer and reconnection costs)."""
    return [Cell(f"vchurn-{mode}-mixed-500", {**scenario(mode, "mixed", 500, viewers=1, window=40), "viewer_cycle_seconds": 6.0}, 3) for mode in ("npclib", "native")]


def damage() -> list[Cell]:
    cells = []
    for mode in ("npclib", "native"):
        for callbacks in (False, True):
            for count_viewers in (0, 1):
                cells.append(Cell(f"damage-{mode}-cb{int(callbacks)}-v{count_viewers}", scenario(mode, "mixed", 200, viewers=count_viewers,
                                                                                             workload={"kind": "damage", "callbacks": callbacks, "per_tick": 20}), 3))
    return cells


def damage_raw() -> list[Cell]:
    """Native entities left dormant after a hit: shows that their damage cooldown freezes (hits are ignored)."""
    return [Cell("damageraw-native-cb0-v0", scenario("native", "mixed", 200, workload={"kind": "damage", "callbacks": False, "per_tick": 20, "wake": False}), 3)]


def events() -> list[Cell]:
    cells = [Cell("events-empty-m0", scenario("empty", workload={"kind": "foreign_damage", "per_tick": 50}))]
    for managers in (1, 10, 50):
        cells.append(Cell(f"events-npclib-m{managers}", scenario("npclib", count=0, managers=managers, workload={"kind": "foreign_damage", "per_tick": 50})))
    return cells


def churn() -> list[Cell]:
    return [Cell(f"churn-{mode}-v{count_viewers}", scenario(mode, "mixed", 200, viewers=count_viewers, window=30,
                                                            workload={"kind": "churn", "per_tick": 2}), 3)
            for mode in ("npclib", "native") for count_viewers in (0, 2)]


def leaks() -> list[Cell]:
    return [Cell(f"leaks-{mode}", scenario(mode, "mixed", 200, window=60, warmup=5, workload={"kind": "churn", "per_tick": 10}), 2) for mode in ("npclib", "native")]


def soak(seconds: float = 300.0) -> list[Cell]:
    parts = [{"kind": "churn", "per_tick": 1}, {"kind": "mutate", "operation": "equip", "per_tick": 5}, {"kind": "mutate", "operation": "rotate", "per_tick": 5}]
    return [Cell(f"soak-{mode}", scenario(mode, "mixed", 300, viewers=2, window=seconds, warmup=10, count_packets=False, workload={"kind": "composite", "parts": parts}), 1)
            for mode in ("npclib", "native")]


def persistence_regression() -> list[Cell]:
    cells = [Cell("regression-empty-v2", scenario("empty", viewers=2), 2)]
    cells += [Cell(f"regression-{mode}-mixed-{count}-v2", scenario(mode, count=count, viewers=2), 2)
              for count in (100, 500, 1000) for mode in ("npclib", "native")]
    cells += [Cell("regression-persistent-mixed-100-v2", {**scenario("npclib", count=100, viewers=2), "persistent": True}, 2),
              Cell("regression-environmental-mixed-100-v2", {**scenario("npclib", count=100, viewers=2), "environmental": True}, 2)]
    return cells


EXPERIMENTS = {
    "persistence_regression": persistence_regression,"smoke": smoke, "calibration": calibration, "control": control, "scale": scale, "viewers": viewers, "scale_viewers": scale_viewers, "operations": operations, "scale_traffic": scale_traffic, "environmental": environmental,
               "viewer_churn": viewer_churn, "damage": damage, "damage_raw": damage_raw, "events": events, "churn": churn, "leaks": leaks, "soak": soak}
