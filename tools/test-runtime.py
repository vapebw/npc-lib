import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import threading
import time

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument("--php", type=Path, default=root / "work/quark-runtime/bin/php/php.exe")
parser.add_argument("--name", required=True)
parser.add_argument("--suite", action="store_true")
parser.add_argument("--artifact", type=Path, default=root / "work/artifacts/NpcLibP0Consumer.phar")
args = parser.parse_args()
if not re.fullmatch(r"[a-zA-Z0-9_-]+", args.name):
    parser.error("name must be a simple directory identifier")
run = root / "work/integration-runs" / args.name
run.mkdir(parents=True, exist_ok=True)
data = run / "server"
plugins = data / "plugins"
plugins.mkdir(parents=True, exist_ok=True)
php = args.php.resolve(strict=True)
ini = php.parent / "php.ini"
php_args = [str(php), "-c", str(ini), "-d", "extension_dir=" + str(php.parent / "ext")]
env = os.environ.copy()
env["PHP_INI_SCAN_DIR"] = ""
env["OPENSSL_CONF"] = str(php.parent / "extras/ssl/openssl.cnf")
env["OPENSSL_MODULES"] = str(php.parent / "extras/ssl")
env.pop("COMPOSER", None)
if args.suite:
    env["NPCLIB_SUITE"] = "1"
quark = root / "work/quark"
sha = subprocess.check_output(["git", "-C", str(quark), "rev-parse", "HEAD"], text=True).strip()
expected_sha = json.loads((root / "composer.json").read_text(encoding="utf8"))["extra"]["quark-source"]["ref"]
if sha != expected_sha:
    raise RuntimeError("Quark revision mismatch")
subprocess.run(["git", "-C", str(quark), "diff", "--exit-code", "HEAD"], check=True)
metadata_code = 'require $argv[1]; echo json_encode(["root"=>\\Composer\\InstalledVersions::getRootPackage(),"ref"=>\\Composer\\InstalledVersions::getReference("quark/quark"),"bedwire"=>phpversion("bedwire"),"toml"=>phpversion("toml"),"chunkutils2"=>phpversion("chunkutils2"),"protocol"=>\\Bedwire\\Protocol::VERSION], JSON_THROW_ON_ERROR);'
metadata = json.loads(subprocess.check_output(php_args + ["-r", metadata_code, str(quark / "vendor/autoload.php")], cwd=root, env=env))
if metadata["ref"] != expected_sha:
    raise RuntimeError("Composer reference does not match Quark source")
metadata["php_sha256"] = hashlib.sha256(php.read_bytes()).hexdigest()
metadata["phar_sha256"] = hashlib.sha256(args.artifact.read_bytes()).hexdigest()
metadata["ini_sha256"] = hashlib.sha256(ini.read_bytes()).hexdigest()
(run / "runtime.json").write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf8")
init_code = 'require $argv[1]; (new \\quark\\FilesystemMigration(new \\quark\\FilesystemPaths($argv[2], $argv[3])))->prepare();'
init = php_args + ["-r", init_code, str(quark / "vendor/autoload.php"), str(data), str(plugins)]
with (run / "initialize.log").open("wb") as log:
    subprocess.run(init, cwd=root, env=env, check=True, stdout=log, stderr=subprocess.STDOUT)
shutil.copy2(args.artifact, plugins / "NpcLibP0Consumer.phar")
if args.suite:
    shutil.copy2(root / "work/artifacts/NpcLibOwnerProbe.phar", plugins / "NpcLibOwnerProbe.phar")
    (data / "data/plugins/NpcLibP0Consumer/p1p2-result.json").unlink(missing_ok=True)
config = (quark / "resources/server.toml").read_text(encoding="utf8").replace("\n[server]\n", "\n[server]\nserver-ip = '127.0.0.1'\n")
for before, after in {
    "transport.nethernet.lan-discovery = true": "transport.nethernet.lan-discovery = false",
    "server-port = 19132": "server-port = 29174",
    "server-portv6 = 19133": "server-portv6 = 29175",
    "enable-ipv6 = true": "enable-ipv6 = false",
    "enable-dev-builds = false": "enable-dev-builds = true",
    "async-workers = 'auto'": "async-workers = 2",
    "level-type = 'DEFAULT'": "level-type = 'FLAT'",
    "view-distance = 16": "view-distance = 4",
    "enable-query = true": "enable-query = false",
}.items():
    config = config.replace(before, after)
(data / "config/server.toml").write_text(config, encoding="utf8")
command = php_args + [str(quark / "src/Quark.php"), "--no-wizard", "--disable-ansi", "--disable-readline", "--data=" + str(data), "--plugins=" + str(plugins), "--server.server-ip=127.0.0.1"]
(run / "commands.json").write_text(json.dumps({"initialize": init, "server": command, "stdin": ["version", "status", "stop"]}, indent=2) + "\n", encoding="utf8")
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
            if "P1P2 CORE READY" in line:
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
        suite_done.wait(30)
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
checks = {
    "ready": ready.is_set(),
    "enabled": "Enabling NpcLibP0Consumer" in output and "npc-lib P0 LOAD PASS" in output,
    "internal_phar_autoload": "npc-lib P0 AUTOLOAD PASS api=6.0.0 source=phar://" in output,
    "disabled": "Disabling NpcLibP0Consumer" in output and "npc-lib P0 DISABLE PASS" in output,
    "world_unloaded": 'Unloading world "world"' in output,
    "exit_zero": code == 0,
    "not_force_killed": not forced,
    "no_errors": re.search(r"Fatal error|Uncaught |\[ERROR\]|\b(?:ERROR|CRIT|ALERT|EMERG)\s", output) is None,
    "no_crash_reports": not any((data / "diagnostics/crashes").glob("*")),
}
if args.suite:
    suite_file = data / "data/plugins/NpcLibP0Consumer/p1p2-result.json"
    suite = json.loads(suite_file.read_text(encoding="utf8")) if suite_file.exists() else {"result": "MISSING"}
    (run / "suite.json").write_text(json.dumps(suite, indent=2) + "\n", encoding="utf8")
    checks["core_suite"] = suite["result"] == "PASS" and suite_done.is_set()
summary = {"quark": sha, "exit": code, "checks": checks, "result": "PASS" if all(checks.values()) else "FAIL"}
(run / "result.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf8")
print(json.dumps(summary, indent=2), flush=True)
raise SystemExit(0 if summary["result"] == "PASS" else 1)
