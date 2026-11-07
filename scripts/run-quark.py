import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import threading
import time

import quarkserver

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument("--php", type=Path, default=root / "work/quark-runtime/bin/php/php.exe")
parser.add_argument("--name", required=True)
parser.add_argument("--suite", action="store_true")
parser.add_argument("--artifact", type=Path, default=root / "work/artifacts/NpcLibHarness.phar")
args = parser.parse_args()
if not re.fullmatch(r"[a-zA-Z0-9_-]+", args.name):
    parser.error("name must be a simple directory identifier")
run = root / "work/runs" / args.name
run.mkdir(parents=True, exist_ok=True)
data = run / "server"
plugins = data / "plugins"
plugins.mkdir(parents=True, exist_ok=True)
php = args.php.resolve(strict=True)
env = quarkserver.environment(php, **({"NPCLIB_SUITE": "1"} if args.suite else {}))
sha = quarkserver.verify_source()
metadata = quarkserver.metadata(php, env)
metadata["phar_sha256"] = hashlib.sha256(args.artifact.read_bytes()).hexdigest()
(run / "runtime.json").write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf8")
quarkserver.initialize(data, plugins, env, php, run / "initialize.log")
shutil.copy2(args.artifact, plugins / "NpcLibHarness.phar")
if args.suite:
    shutil.copy2(args.artifact.parent / "NpcLibPeer.phar", plugins / "NpcLibPeer.phar")
    for stale in ("lifecycle-result.json", "feature-result.json", "environment-result.json", "persistence-result.json"):
        (data / "data/plugins/NpcLibHarness" / stale).unlink(missing_ok=True)
quarkserver.write_config(data, 29174)
command = quarkserver.command(data, plugins, php)
(run / "commands.json").write_text(json.dumps({"server": command, "stdin": ["version", "status", "stop"]}, indent=2) + "\n", encoding="utf8")
ready = threading.Event()
suite_done = threading.Event()
lines = []
process = subprocess.Popen(command, cwd=root, env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

def read_output():
    with (run / "console.log").open("wb") as log:
        for raw in process.stdout:
            log.write(raw)
            log.flush()
            line = raw.decode("utf8", errors="replace")
            lines.append(line)
            print(line, end="", flush=True)
            if "Done (" in line:
                ready.set()
            if "feature suite done" in line or "lifecycle suite FAIL" in line:
                suite_done.set()

reader = threading.Thread(target=read_output)
reader.start()
deadline = time.monotonic() + 120
while process.poll() is None and not ready.is_set() and time.monotonic() < deadline:
    ready.wait(0.1)
if process.poll() is None and ready.is_set():
    process.stdin.write(b"version\nstatus\n")
    process.stdin.flush()
    if args.suite:
        suite_done.wait(90)
    else:
        time.sleep(2)
if process.poll() is None:
    process.stdin.write(b"stop\n")
    process.stdin.flush()
forced = False
try:
    code = process.wait(timeout=45)
except subprocess.TimeoutExpired:
    forced = True
    process.kill()
    code = process.wait()
reader.join()
output = "".join(lines)
expected_snapshot_errors = [line for line in lines if any(f"NPC snapshot failed for {id}; keeping its last good state: Invalid or oversized NPC string" in line for id in ("guide", "invalidState", "secondInvalidState"))]
expected_callback_errors = [line for index, line in enumerate(lines[:-1]) if "Unhandled exception" in line and any(token in lines[index + 1] for token in ("runtime callback sentinel", "chunk restore sentinel"))]
error_output = "".join(line for line in lines if line not in expected_snapshot_errors and line not in expected_callback_errors)
checks = {
    "ready": ready.is_set(),
    "enabled": "Enabling NpcLibHarness" in output and "harness LOAD PASS" in output,
    "internal_phar_autoload": "harness AUTOLOAD PASS api=6.0.0 source=phar://" in output,
    "disabled": "Disabling NpcLibHarness" in output and "harness DISABLE PASS" in output,
    "world_unloaded": 'Unloading world "world"' in output,
    "exit_zero": code == 0,
    "not_force_killed": not forced,
    "no_errors": re.search(r"Fatal error|Uncaught |\[ERROR\]|\b(?:ERROR|CRIT|ALERT|EMERG)\s", error_output) is None,
    "no_crash_reports": not any((data / "diagnostics/crashes").glob("*")),
}
if args.suite:
    suite_file = data / "data/plugins/NpcLibHarness/lifecycle-result.json"
    suite = json.loads(suite_file.read_text(encoding="utf8")) if suite_file.exists() else {"result": "MISSING"}
    (run / "lifecycle.json").write_text(json.dumps(suite, indent=2) + "\n", encoding="utf8")
    checks["lifecycle_suite"] = suite["result"] == "PASS" and suite_done.is_set()
    feature_file = data / "data/plugins/NpcLibHarness/feature-result.json"
    features = json.loads(feature_file.read_text(encoding="utf8")) if feature_file.exists() else {"result": "MISSING"}
    (run / "features.json").write_text(json.dumps(features, indent=2) + "\n", encoding="utf8")
    checks["feature_suite"] = features["result"] == "PASS"
    environment_file = data / "data/plugins/NpcLibHarness/environment-result.json"
    environment = json.loads(environment_file.read_text(encoding="utf8")) if environment_file.exists() else {"result": "MISSING"}
    (run / "environment.json").write_text(json.dumps(environment, indent=2) + "\n", encoding="utf8")
    checks["environment_suite"] = environment["result"] == "PASS"
    persistence_file = data / "data/plugins/NpcLibHarness/persistence-result.json"
    persistence = json.loads(persistence_file.read_text(encoding="utf8")) if persistence_file.exists() else {"result": "MISSING"}
    (run / "persistence.json").write_text(json.dumps(persistence, indent=2) + "\n", encoding="utf8")
    checks["persistence_suite"] = persistence["result"] == "PASS"
    checks["expected_snapshot_failure"] = len(expected_snapshot_errors) == 3 and "invalid state does not block the unload or native cleanup" in persistence.get("passed", [])
    checks["expected_callback_failure"] = len(expected_callback_errors) == 3 and "definition kept after a callback failure restores later" in persistence.get("passed", []) and "failed definition in a chunk load stays dormant without blocking the rest" in persistence.get("passed", [])
summary = {"quark": sha, "exit": code, "checks": checks, "result": "PASS" if all(checks.values()) else "FAIL"}
(run / "result.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf8")
print(json.dumps(summary, indent=2), flush=True)
raise SystemExit(0 if summary["result"] == "PASS" else 1)
