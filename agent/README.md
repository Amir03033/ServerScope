# ServerScope agent

Collects read-only system metrics (CPU, load, memory, swap, disks, disk I/O,
network counters and errors, process count, uptime) and, optionally, the
state of configured services every 60 seconds and sends them over HTTPS to
`POST /api/agent/metrics`. It executes no remote commands.

## Install (Ubuntu/Debian)

1. In ServerScope: **Servers → Server toevoegen**. Copy the agent token
   (shown once). Tokens can later be rotated or revoked on the server page.
2. On the server:

   ```sh
   sudo useradd --system --home /opt/serverscope-agent --shell /usr/sbin/nologin serverscope
   sudo mkdir -p /opt/serverscope-agent
   sudo cp -r serverscope_agent requirements.txt /opt/serverscope-agent/
   cd /opt/serverscope-agent
   sudo python3 -m venv .venv
   sudo .venv/bin/pip install -r requirements.txt
   sudo tee /opt/serverscope-agent/.env >/dev/null <<'ENV'
   SERVERSCOPE_URL=https://serverscope.nl
   SERVERSCOPE_TOKEN=<token>
   SERVERSCOPE_INTERVAL=60
   ENV
   sudo chown -R root:serverscope /opt/serverscope-agent
   sudo chmod 640 /opt/serverscope-agent/.env
   sudo cp serverscope-agent.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now serverscope-agent
   journalctl -u serverscope-agent -f
   ```

3. Keep the clock in sync (`timedatectl set-ntp true`): samples more than
   30 seconds in the future or 10 minutes in the past are rejected.

## Service checks (agent 0.2.0+)

List the systemd units to watch; nothing is assumed to exist:

```sh
SERVERSCOPE_SERVICES=nginx,mysql,php8.3-fpm,redis-server
```

The agent runs `systemctl show <unit> --property=LoadState,ActiveState,SubState`
(read-only, no shell, 3 s time-out) and reports `running`, `starting`,
`stopped`, `failed`, `not_found` or `unknown`. Without systemd it looks for
a process with the same name. ServerScope opens an incident after the
configured number of failed reports; a stopped service is reported
separately from an unreachable server or a silent agent.

## Integrity

Each sample includes a SHA-256 checksum of the agent's own source files.
Set `SERVERSCOPE_AGENT_CHECKSUMS` in ServerScope to the checksums of your
released builds to flag modified agents; without it the checksum is shown
as "not verified".

## Token rotation

Use **Token roteren** on the server page, put the new token in `.env` and
run `sudo systemctl restart serverscope-agent`. The old token stops
working immediately.

## Tests

```sh
pip install -r requirements.txt && python -m pytest -q   # from the repository root
```

## Bash agent (containers without Python or systemd)

`agent/bash/agent.sh` sends the same data using only `/proc`, `df`,
`pgrep` and `curl`. Put it with a `.env` (`SERVERSCOPE_URL`,
`SERVERSCOPE_TOKEN`, optional `SERVERSCOPE_SERVICES` as process names and
`SERVERSCOPE_INTERVAL`) in one directory outside the web root and start it
with `nohup bash agent.sh >> agent.log 2>&1 &`. Check the payload first with
`SERVERSCOPE_DRY_RUN=1 bash agent.sh`. Inside a container it measures that
container, not the host.
