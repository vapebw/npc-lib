import json
import shutil
import subprocess
import sys
import threading
from pathlib import Path

import quarkserver

root = quarkserver.ROOT
base = root / "work/wire"
data = base / "server"
plugins = data / "plugins"
logs = base / "logs"
plugins.mkdir(parents=True, exist_ok=True)
logs.mkdir(parents=True, exist_ok=True)

env = quarkserver.environment(NPCLIB_WIRE="1")
quarkserver.initialize(data, plugins, env)
shutil.copy2(root / "work/artifacts/NpcLibHarness.phar", plugins / "NpcLibHarness.phar")
quarkserver.write_config(data, 29184, raknet=True)

command = quarkserver.command(data, plugins)
(logs / "command.json").write_text(json.dumps(command, indent=2), encoding="utf8")
server = subprocess.Popen(command, cwd=root, env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)


def pump_output() -> None:
    with (logs / "console.log").open("wb") as log:
        for line in server.stdout:
            log.write(line)
            log.flush()
            print(line.decode("utf8", errors="replace"), end="", flush=True)


def pump_input() -> None:
    for line in sys.stdin:
        if server.poll() is not None:
            break
        server.stdin.write(line.encode("utf8"))
        server.stdin.flush()


reader = threading.Thread(target=pump_output)
reader.start()
threading.Thread(target=pump_input, daemon=True).start()
code = server.wait()
reader.join()
(logs / "exit.json").write_text(json.dumps({"exit": code}), encoding="utf8")
print("SERVER_EXIT", code, flush=True)
raise SystemExit(code)
