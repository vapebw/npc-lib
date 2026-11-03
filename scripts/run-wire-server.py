import json
import os
import shutil
import subprocess
import sys
import threading
from pathlib import Path

root = Path(__file__).resolve().parent.parent
base = root / "work/wire"
data = base / "server"
plugins = data / "plugins"
logs = base / "logs"
plugins.mkdir(parents=True, exist_ok=True)
logs.mkdir(parents=True, exist_ok=True)

bin_dir = root / "work/quark-runtime/bin/php"
env = os.environ.copy()
env["PHP_INI_SCAN_DIR"] = ""
env["OPENSSL_CONF"] = str(bin_dir / "extras/ssl/openssl.cnf")
env["OPENSSL_MODULES"] = str(bin_dir / "extras/ssl")
env["NPCLIB_WIRE"] = "1"
env.pop("NPCLIB_SUITE", None)
env.pop("COMPOSER", None)
php = [str(bin_dir / "php.exe"), "-c", str(bin_dir / "php.ini"), "-d", "extension_dir=" + str(bin_dir / "ext")]

init = r"require $argv[1]; (new \quark\FilesystemMigration(new \quark\FilesystemPaths($argv[2], $argv[3])))->prepare();"
subprocess.run(php + ["-r", init, str(root / "work/quark/vendor/autoload.php"), str(data), str(plugins)], cwd=root, env=env, check=True)
shutil.copy2(root / "work/artifacts/NpcLibHarness.phar", plugins / "NpcLibHarness.phar")

config = (root / "work/quark/resources/server.toml").read_text(encoding="utf8").replace("\n[server]\n", "\n[server]\nserver-ip = '127.0.0.1'\n")
for before, after in {
    "transport.name = 'nethernet'": "transport.name = 'raknet'",
    "transport.nethernet.lan-discovery = true": "transport.nethernet.lan-discovery = false",
    "server-port = 19132": "server-port = 29184",
    "server-portv6 = 19133": "server-portv6 = 29185",
    "enable-ipv6 = true": "enable-ipv6 = false",
    "enable-query = true": "enable-query = false",
    "enable-dev-builds = false": "enable-dev-builds = true",
    "async-workers = 'auto'": "async-workers = 2",
    "level-type = 'DEFAULT'": "level-type = 'FLAT'",
    "view-distance = 16": "view-distance = 4",
    "xbox-auth = true": "xbox-auth = false",
}.items():
    config = config.replace(before, after)
(data / "config/server.toml").write_text(config, encoding="utf8")

command = php + [str(root / "work/quark/src/Quark.php"), "--no-wizard", "--disable-ansi", "--disable-readline", "--data=" + str(data), "--plugins=" + str(plugins), "--server.server-ip=127.0.0.1"]
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
