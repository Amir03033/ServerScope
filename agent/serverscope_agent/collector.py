from datetime import datetime, timezone
import time

import psutil


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
        "uptime_seconds": max(
            0,
            int(time.time() - psutil.boot_time()),
        ),
        "agent_version": "0.1.0",
    }
