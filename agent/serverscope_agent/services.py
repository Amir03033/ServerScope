"""Read-only service state checks.

Only the services listed in SERVERSCOPE_SERVICES are checked; nothing is
assumed to exist on a server. systemd is queried with `systemctl show`
(no shell, fixed arguments, short time-out). Without systemd the agent
falls back to looking for a process with the same name.
"""

import re
import shutil
import subprocess

import psutil

UNIT_PATTERN = re.compile(r"^[A-Za-z0-9@._:-]{1,100}$")

# systemd ActiveState -> ServerScope state
ACTIVE_STATES = {
    "active": "running",
    "reloading": "running",
    "activating": "starting",
    "deactivating": "stopped",
    "inactive": "stopped",
    "failed": "failed",
}


def parse_services(value: str) -> list:
    """Parse a comma separated list of unit names, dropping invalid ones."""
    names = []

    for name in (part.strip() for part in value.split(",")):
        if name and UNIT_PATTERN.match(name) and name not in names:
            names.append(name)

    return names[:50]


def check_services(names: list) -> list:
    """Return [{name, state, detail}] for the configured services."""
    systemctl = shutil.which("systemctl")

    return [
        _systemd_state(systemctl, name) if systemctl else _process_state(name)
        for name in names
    ]


def _systemd_state(systemctl: str, name: str) -> dict:
    try:
        completed = subprocess.run(
            [systemctl, "show", name, "--property=LoadState,ActiveState,SubState", "--no-pager"],
            capture_output=True,
            text=True,
            timeout=3,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return {"name": name, "state": "unknown", "detail": "systemctl niet beschikbaar"}

    properties = dict(
        line.split("=", 1)
        for line in completed.stdout.splitlines()
        if "=" in line
    )

    if properties.get("LoadState") == "not-found":
        return {"name": name, "state": "not_found", "detail": "unit niet gevonden"}

    active = properties.get("ActiveState", "")
    sub = properties.get("SubState", "")

    return {
        "name": name,
        "state": ACTIVE_STATES.get(active, "unknown"),
        "detail": f"{active}/{sub}"[:100] if active else None,
    }


def _process_state(name: str) -> dict:
    target = name.removesuffix(".service")

    for process in psutil.process_iter(["name"]):
        try:
            if process.info.get("name") == target:
                return {"name": name, "state": "running", "detail": "proces gevonden"}
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue

    return {"name": name, "state": "stopped", "detail": "geen proces gevonden"}
