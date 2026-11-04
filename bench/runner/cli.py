"""Benchmark entry point: run experiments, summarize their results.

  python bench/runner/cli.py run scale --samples 5
  python bench/runner/cli.py summarize work/bench/<run id>
"""
import argparse
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from experiments import EXPERIMENTS, Cell  # noqa: E402
from report import summarize_directory  # noqa: E402
from schedule import interleave  # noqa: E402
from server import execute  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]


def headline(record: dict) -> str:
    result_ok = "ok" if record["result_present"] and record["exit_code"] == 0 and not record["timed_out"] else "FAILED"
    cpu = record["os"].get("cpu_seconds")
    window = record["os"].get("window_seconds")
    cpu_text = f"cpu {100 * cpu / window:5.1f}%" if cpu is not None and window else "cpu n/a"
    return f"{result_ok} {record['wall_seconds']:6.1f}s {cpu_text}"


def run(args: argparse.Namespace) -> int:
    cells: list[Cell] = []
    for name in args.experiments:
        cells += EXPERIMENTS[name]()
    if args.only:
        cells = [cell for cell in cells if args.only in cell.name]
    out = ROOT / "work/bench" / args.run_id
    plan = interleave(cells, args.samples)
    print(f"{len(plan)} runs into {out}", flush=True)
    failures = 0
    for index, (cell, sample) in enumerate(plan, 1):
        target = out / cell.name / f"sample-{sample:02d}"
        existing = target / "run.json"
        if existing.exists() and not args.force:
            print(f"[{index}/{len(plan)}] {cell.name} #{sample} cached", flush=True)
            continue
        started = time.time()
        try:
            record = execute({**cell.scenario, "id": f"{cell.name}#{sample}"}, target)
            status = headline(record)
            failures += status.startswith("FAILED")
        except Exception as error:  # noqa: BLE001 a failed run is recorded, the matrix continues
            failures += 1
            target.mkdir(parents=True, exist_ok=True)
            (target / "run.json").write_text(json.dumps({"scenario": cell.scenario, "runner_error": repr(error), "result_present": False}), encoding="utf8")
            status = f"FAILED {error!r}"
        print(f"[{index}/{len(plan)}] {cell.name} #{sample} {status} ({time.time() - started:.0f}s)", flush=True)
    return 1 if failures else 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)
    runner = commands.add_parser("run")
    runner.add_argument("experiments", nargs="+", choices=sorted(EXPERIMENTS))
    runner.add_argument("--samples", type=int, default=None, help="override the samples per cell")
    runner.add_argument("--only", help="substring filter on cell names")
    runner.add_argument("--run-id", default=time.strftime("%Y%m%d-%H%M%S"))
    runner.add_argument("--force", action="store_true", help="rerun cells that already have results")
    summary = commands.add_parser("summarize")
    summary.add_argument("directory", type=Path)
    args = parser.parse_args()
    if args.command == "run":
        return run(args)
    summarize_directory(args.directory)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
