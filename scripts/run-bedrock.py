import json
import shutil
import subprocess
import sys
import threading
from pathlib import Path

root = Path(__file__).resolve().parent.parent
shutil.rmtree(root / "work/wire/server/data/worlds/world", ignore_errors=True)

server = subprocess.Popen([sys.executable, str(root / "scripts/run-wire-server.py")], cwd=root, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
server_lines: list[str] = []
ready = threading.Event()


def read_server() -> None:
    for raw in server.stdout:
        text = raw.decode("utf8", errors="replace")
        server_lines.append(text)
        if "Done (" in text:
            ready.set()


threading.Thread(target=read_server, daemon=True).start()
if not ready.wait(120):
    server.kill()
    raise SystemExit("server not ready")

suite = subprocess.Popen(["node", str(root / "tests/bedrock/run-suite.cjs")], cwd=root, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
results: list[dict] = []
finished = threading.Event()


def read_suite() -> None:
    for raw in suite.stdout:
        text = raw.decode("utf8", errors="replace")
        print(text, end="", flush=True)
        if text.startswith('{"label"'):
            results.append(json.loads(text))
        if "SUITE_FINISHED" in text or "SUITE_FAILED" in text:
            finished.set()


threading.Thread(target=read_suite, daemon=True).start()
finished.wait(600)
suite.stdin.write(b"close\n")
suite.stdin.flush()
try:
    suite_exit = suite.wait(30)
except subprocess.TimeoutExpired:
    suite.kill()
    suite_exit = -1
server.stdin.write(b"stop\n")
server.stdin.flush()
try:
    server_exit = server.wait(60)
except subprocess.TimeoutExpired:
    server.kill()
    server_exit = -1

error_lines = [line.rstrip() for line in server_lines if any(word in line for word in ("ERROR", "CRITICAL", "Uncaught", "EMERG"))]
failed = [item["label"] for item in results if item["result"] != "PASS"]
summary = {"checks": len(results), "failed": failed, "suite_exit": suite_exit, "server_exit": server_exit, "server_error_lines": error_lines}
print(json.dumps(summary, indent=2))
raise SystemExit(0 if finished.is_set() and results and not failed and suite_exit == 0 and server_exit == 0 and not error_lines else 1)
