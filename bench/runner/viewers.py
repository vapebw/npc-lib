"""Controls the Node process that holds the Bedrock protocol viewers."""
import json
import os
import queue
import subprocess
import threading
import time
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent.parent / "viewers/viewers.cjs"


class Viewers:
    def __init__(self, port: int, names: list[str]) -> None:
        self.names = names
        self._process = subprocess.Popen(
            ["node", str(SCRIPT)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            env={**os.environ, "NPC_BENCH_PORT": str(port)}, text=True, bufsize=1,
        )
        self._replies: queue.Queue[dict] = queue.Queue()
        threading.Thread(target=self._read, daemon=True).start()

    def _read(self) -> None:
        assert self._process.stdout is not None
        for line in self._process.stdout:
            try:
                self._replies.put(json.loads(line))
            except json.JSONDecodeError:
                continue

    def _send(self, message: dict) -> None:
        assert self._process.stdin is not None
        self._process.stdin.write(json.dumps(message) + "\n")
        self._process.stdin.flush()

    def join(self, names: list[str] | None = None) -> None:
        self._send({"op": "join", "names": names or self.names})

    def leave(self, names: list[str]) -> None:
        self._send({"op": "leave", "names": names})

    def stats(self, timeout: float = 15.0) -> dict:
        while not self._replies.empty():
            self._replies.get_nowait()
        self._send({"op": "stats"})
        return self._replies.get(timeout=timeout)

    def wait_spawned(self, count: int, timeout: float) -> bool:
        deadline = time.time() + timeout
        while time.time() < deadline:
            stats = self.stats()["stats"]
            if sum(1 for item in stats.values() if item["spawned"]) >= count:
                return True
            time.sleep(0.5)
        return False

    def close(self) -> None:
        if self._process.poll() is None:
            try:
                self._send({"op": "exit"})
                self._process.wait(timeout=5)
            except (subprocess.TimeoutExpired, OSError):
                self._process.kill()
