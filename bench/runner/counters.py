"""Windows process counters sampled from outside the server, used to cross-check the in-server figures.

CPU time comes from GetProcessTimes (kernel + user, 100 ns units) and QueryProcessCycleTime (CPU cycles);
memory from GetProcessMemoryInfo. All of them cover every thread of the process.
"""
import ctypes
import threading
import time
from ctypes import wintypes

PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
PROCESS_VM_READ = 0x0010

kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
psapi = ctypes.WinDLL("psapi", use_last_error=True)


class MemoryCounters(ctypes.Structure):
    _fields_ = [
        ("cb", wintypes.DWORD), ("PageFaultCount", wintypes.DWORD),
        ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t),
        ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t),
        ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t), ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
        ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t), ("PrivateUsage", ctypes.c_size_t),
    ]


kernel32.OpenProcess.restype = wintypes.HANDLE
kernel32.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
kernel32.GetProcessTimes.argtypes = [wintypes.HANDLE] + [ctypes.POINTER(wintypes.FILETIME)] * 4
kernel32.QueryProcessCycleTime.argtypes = [wintypes.HANDLE, ctypes.POINTER(ctypes.c_ulonglong)]
psapi.GetProcessMemoryInfo.argtypes = [wintypes.HANDLE, ctypes.POINTER(MemoryCounters), wintypes.DWORD]


def _ticks(value: wintypes.FILETIME) -> int:
    return (value.dwHighDateTime << 32) | value.dwLowDateTime


class ProcessSampler:
    """Samples one process at a fixed interval on a background thread."""

    def __init__(self, pid: int, interval: float = 0.5) -> None:
        self.pid = pid
        self.interval = interval
        self.samples: list[dict[str, float]] = []
        self._stop = threading.Event()
        self._handle = kernel32.OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION | PROCESS_VM_READ, False, pid)
        if not self._handle:
            raise OSError(f"OpenProcess failed for pid {pid}: {ctypes.get_last_error()}")
        self._thread = threading.Thread(target=self._run, daemon=True)

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        self._thread.join()
        kernel32.CloseHandle(self._handle)

    def _run(self) -> None:
        while not self._stop.is_set():
            sample = self._read()
            if sample is not None:
                self.samples.append(sample)
            self._stop.wait(self.interval)

    def _read(self) -> dict[str, float] | None:
        creation, exit_time, kernel, user = (wintypes.FILETIME() for _ in range(4))
        if not kernel32.GetProcessTimes(self._handle, creation, exit_time, kernel, user):
            return None
        cycles = ctypes.c_ulonglong()
        counters = MemoryCounters()
        counters.cb = ctypes.sizeof(counters)
        if not kernel32.QueryProcessCycleTime(self._handle, ctypes.byref(cycles)) or not psapi.GetProcessMemoryInfo(self._handle, ctypes.byref(counters), counters.cb):
            return None
        return {
            "t": time.time(),
            "cpu_s": (_ticks(kernel) + _ticks(user)) / 1e7,
            "cycles": float(cycles.value),
            "working_set": float(counters.WorkingSetSize),
            "private": float(counters.PrivateUsage),
            "peak_working_set": float(counters.PeakWorkingSetSize),
        }


def interpolate(samples: list[dict[str, float]], when: float, key: str) -> float | None:
    """Linear interpolation of a counter at a wall-clock time, None when the time is outside the samples."""
    for before, after in zip(samples, samples[1:]):
        if before["t"] <= when <= after["t"]:
            span = after["t"] - before["t"]
            return before[key] if span == 0 else before[key] + (after[key] - before[key]) * (when - before["t"]) / span
    return None
