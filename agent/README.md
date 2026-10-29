# ServerScope agent

Collects read-only system metrics (CPU, load, memory, swap, disks, disk I/O,
network counters and errors, process count, uptime) and, optionally, the
state of configured services every 60 seconds and sends them over HTTPS to
`POST /api/agent/metrics`. It executes no remote commands.

## Install with one command (Ubuntu 22.04+ / Debian 12+, amd64 / arm64)

1. In ServerScope (team administrators): **Servers → Server toevoegen →
   Linux → Koppeltoken aanmaken**. The page shows a command and a one-time
   enrollment token (valid 60 minutes, works once, can be revoked there).
2. On the server, as a user with sudo rights:

   ```sh
   curl --proto '=https' --tlsv1.2 -fsSL https://<your ServerScope>/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh
   ```

   Read the script first if you like: open `https://<your ServerScope>/install.sh`
   in a browser. It installs software as root.
3. Paste the enrollment token when the script asks for it (the input is
   hidden). The token is deliberately not part of the command, so it never
   ends up in the shell history or the process list. For automation, put it
   in the environment variable `SERVERSCOPE_ENROLL_TOKEN` instead, e.g. from
   a secret store; do not type it into a command line.
4. The script waits for the first sample and reports success; the server
   appears as "online" in the dashboard of the team that created the token.

What the script does:

- stops on anything but Ubuntu 22.04+ or Debian 12+ on amd64/arm64 with
  systemd (the agent needs Python 3.10+);
- installs `python3`, `python3-venv`, `python3-psutil`, `python3-requests`
  and `python3-dotenv` from the distribution's package sources (no pip, no
  PyPI) and creates a venv with `--system-site-packages`;
- creates the system user `serverscope` without a shell (if missing);
- downloads `serverscope-agent-<version>.tar.gz` and its `.sha256` from
  ServerScope over HTTPS only (TLS 1.2+) and checks the checksum. The
  checksum comes from the same server as the package: it protects against a
  corrupt or truncated download, **not** against a compromised ServerScope
  server. Signed releases are planned;
- installs the agent exactly like the manual installation:
  `/opt/serverscope-agent/serverscope_agent/` with `/opt/serverscope-agent/.env`
  next to it (`root:serverscope`, `640`, `KEY=value` without quotes) and the
  unchanged `serverscope-agent.service` (the agent reads its own `.env`; the
  unit has no `EnvironmentFile`);
- exchanges the enrollment token for a normal agent credential, enables and
  starts the service and waits up to 90 seconds for the first sample, with
  a clear message and a fix when it does not arrive.

Running it again is safe: an installation made by the script with a working
credential is only updated, never enrolled a second time. If the credential
was revoked, the script asks for a new enrollment token and **creates a new
server**; delete the old one in the dashboard. An installation that was made
by hand (no `/opt/serverscope-agent/.installed-by-serverscope-script`) is
left alone.

Uninstall:

```sh
sudo bash serverscope-install.sh --uninstall
```

This revokes the agent's own credential in ServerScope, stops and removes
the service, `/opt/serverscope-agent`, and the `serverscope` user if the
script created it. The server and its history stay in ServerScope. The
Python packages from apt are kept.

## Manual install (Ubuntu/Debian)

1. In ServerScope: **Servers → Server toevoegen → Handmatig registreren**.
   Copy the agent token (shown once). Tokens can later be rotated or
   revoked on the server page.
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
