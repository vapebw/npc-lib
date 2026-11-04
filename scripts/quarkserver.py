import hashlib
import json
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
QUARK = ROOT / "work/quark"
RUNTIME = ROOT / "work/quark-runtime/bin/php"

BASE_CONFIG = {
    "transport.nethernet.lan-discovery = true": "transport.nethernet.lan-discovery = false",
    "enable-ipv6 = true": "enable-ipv6 = false",
    "enable-query = true": "enable-query = false",
    "enable-dev-builds = false": "enable-dev-builds = true",
    "async-workers = 'auto'": "async-workers = 2",
    "level-type = 'DEFAULT'": "level-type = 'FLAT'",
}

INIT_CODE = r"require $argv[1]; (new \quark\FilesystemMigration(new \quark\FilesystemPaths($argv[2], $argv[3])))->prepare();"
METADATA_CODE = (
    r'require $argv[1]; echo json_encode(["root"=>\Composer\InstalledVersions::getRootPackage(),'
    r'"ref"=>\Composer\InstalledVersions::getReference("quark/quark"),"bedwire"=>phpversion("bedwire"),'
    r'"toml"=>phpversion("toml"),"chunkutils2"=>phpversion("chunkutils2"),"protocol"=>\Bedwire\Protocol::VERSION], JSON_THROW_ON_ERROR);'
)


def php_args(php: Path = RUNTIME / "php.exe") -> list[str]:
    return [str(php), "-c", str(php.parent / "php.ini"), "-d", "extension_dir=" + str(php.parent / "ext")]


def environment(php: Path = RUNTIME / "php.exe", **extra: str) -> dict[str, str]:
    env = os.environ.copy()
    env["PHP_INI_SCAN_DIR"] = ""
    env["OPENSSL_CONF"] = str(php.parent / "extras/ssl/openssl.cnf")
    env["OPENSSL_MODULES"] = str(php.parent / "extras/ssl")
    for name in ("COMPOSER", "NPCLIB_WIRE", "NPCLIB_SUITE"):
        env.pop(name, None)
    env.update(extra)
    return env


def pinned_ref() -> str:
    return json.loads((ROOT / "composer.json").read_text(encoding="utf8"))["extra"]["quark-source"]["ref"]


def verify_source() -> str:
    sha = subprocess.check_output(["git", "-C", str(QUARK), "rev-parse", "HEAD"], text=True).strip()
    if sha != pinned_ref():
        raise RuntimeError("Quark revision mismatch")
    subprocess.run(["git", "-C", str(QUARK), "diff", "--exit-code", "HEAD"], check=True, stdout=subprocess.DEVNULL)
    return sha


def metadata(php: Path, env: dict[str, str]) -> dict[str, object]:
    out = subprocess.check_output(php_args(php) + ["-r", METADATA_CODE, str(QUARK / "vendor/autoload.php")], cwd=ROOT, env=env)
    data = json.loads(out)
    if data["ref"] != pinned_ref():
        raise RuntimeError("Composer reference does not match Quark source")
    data["php_sha256"] = hashlib.sha256(php.read_bytes()).hexdigest()
    data["ini_sha256"] = hashlib.sha256((php.parent / "php.ini").read_bytes()).hexdigest()
    return data


def initialize(data: Path, plugins: Path, env: dict[str, str], php: Path = RUNTIME / "php.exe", log: Path | None = None) -> None:
    command = php_args(php) + ["-r", INIT_CODE, str(QUARK / "vendor/autoload.php"), str(data), str(plugins)]
    with (log.open("wb") if log is not None else open(os.devnull, "wb")) as sink:
        subprocess.run(command, cwd=ROOT, env=env, check=True, stdout=sink, stderr=subprocess.STDOUT)


def write_config(data: Path, port: int, view_distance: int = 4, raknet: bool = False, extra: dict[str, str] | None = None) -> None:
    config = (QUARK / "resources/server.toml").read_text(encoding="utf8").replace("\n[server]\n", "\n[server]\nserver-ip = '127.0.0.1'\n")
    replacements = {
        **BASE_CONFIG,
        "server-port = 19132": f"server-port = {port}",
        "server-portv6 = 19133": f"server-portv6 = {port + 1}",
        "view-distance = 16": f"view-distance = {view_distance}",
    }
    if raknet:
        replacements["transport.name = 'nethernet'"] = "transport.name = 'raknet'"
        replacements["xbox-auth = true"] = "xbox-auth = false"
    replacements.update(extra or {})
    for before, after in replacements.items():
        config = config.replace(before, after)
    (data / "config").mkdir(parents=True, exist_ok=True)
    (data / "config/server.toml").write_text(config, encoding="utf8")


def command(data: Path, plugins: Path, php: Path = RUNTIME / "php.exe") -> list[str]:
    return php_args(php) + [str(QUARK / "src/Quark.php"), "--no-wizard", "--disable-ansi", "--disable-readline", "--data=" + str(data), "--plugins=" + str(plugins), "--server.server-ip=127.0.0.1"]
