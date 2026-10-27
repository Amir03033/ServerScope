from datetime import datetime, timezone
from pathlib import Path
import hashlib
import time

import psutil

AGENT_VERSION = "0.2.0"


def agent_checksum() -> str:
    """SHA-256 over the agent's own source files (integrity indicator)."""
    digest = hashlib.sha256()

    for path in sorted(Path(__file__).resolve().parent.glob("*.py")):
        digest.update(path.name.encode())
        digest.update(path.read_bytes())

    return digest.hexdigest()


def _counter(source, name: str):
    value = getattr(source, name, None) if source is not None else None

    return int(value) if isinstance(value, (int, float)) else None


def collect_metrics() -> dict:
    """Collect read-only system metrics."""

    memory = psutil.virtual_memory()
    swap = psutil.swap_memory()
    network = psutil.net_io_counters()

    disks = []

    ignored_filesystems = {
        "autofs",
        "devfs",
        "squashfs",
        "tmpfs",
        "devtmpfs",
        "overlay",
        "proc",
        "sysfs",
    }

    for partition in psutil.disk_partitions(all=False):
        if partition.fstype.lower() in ignored_filesystems:
            continue

        try:
            usage = psutil.disk_usage(partition.mountpoint)
        except (PermissionError, OSError):
            continue

        disks.append({
            "path": partition.mountpoint,
            "total_bytes": usage.total,
            "used_bytes": usage.used,
            "available_bytes": usage.free,
            "percent": usage.percent,
        })

    disks = disks[:20]

    try:
        load_1, load_5, load_15 = psutil.getloadavg()
    except (AttributeError, OSError):
        load_1 = load_5 = load_15 = None

    try:
        disk_io = psutil.disk_io_counters()
    except (AttributeError, OSError, RuntimeError):
        disk_io = None

    try:
        process_count = len(psutil.pids())
    except (AttributeError, OSError):
        process_count = None

    return {
        "collected_at": datetime.now(timezone.utc).isoformat(),
        "cpu_percent": psutil.cpu_percent(interval=1),
        "load_1": load_1,
        "load_5": load_5,
        "load_15": load_15,
        "memory_total_bytes": memory.total,
        "memory_used_bytes": memory.used,
        "memory_available_bytes": memory.available,
        "swap_total_bytes": swap.total,
        "swap_used_bytes": swap.used,
        "disks": disks,
        "network_rx_bytes": network.bytes_recv,
        "network_tx_bytes": network.bytes_sent,
        "network_errors_in": _counter(network, "errin"),
        "network_errors_out": _counter(network, "errout"),
        "network_drops_in": _counter(network, "dropin"),
        "network_drops_out": _counter(network, "dropout"),
        "disk_read_bytes": _counter(disk_io, "read_bytes"),
        "disk_write_bytes": _counter(disk_io, "write_bytes"),
        "process_count": process_count,
        "uptime_seconds": max(
            0,
            int(time.time() - psutil.boot_time()),
        ),
        "agent_version": AGENT_VERSION,
        "agent_checksum": agent_checksum(),
    }
