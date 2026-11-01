# ServerScope agent

Collects read-only system metrics (CPU, load, memory, swap, disks, disk I/O,
network counters and errors, process count, logical CPU count, uptime) and,
optionally, the
state of configured services every 60 seconds and sends them over HTTPS to
`POST /api/agent/metrics`. It executes no remote commands.

`cpu_count` (logical CPUs, `psutil.cpu_count()` / `nproc` in the Bash agent)
is stored as server metadata, not per sample. Agents that do not send it
keep working; the server then shows no CPU count. It ships with the next
signed agent release (the release process sets the version number).

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
- stops **before changing anything** when ServerScope has no signed
  release yet, or when this server stored a different release key earlier;
- installs `python3`, `python3-venv`, `python3-psutil`, `python3-requests`,
  `python3-dotenv` and `openssl` from the distribution's package sources
  (no pip, no PyPI) and creates a venv with `--system-site-packages`;
- creates the system user `serverscope` without a shell (if missing);
- downloads the newest **signed release** (manifest, signature, tarball)
  over HTTPS only (TLS 1.2+), checks the signature with the public key
  written in the script (and prints its fingerprint), then the SHA-256 and
  size of the tarball and its contents;
- installs it in `/opt/serverscope-agent/releases/<version>/` with the
  symlink `/opt/serverscope-agent/serverscope_agent` pointing to it, and
  `/opt/serverscope-agent/.env` next to it (`root:serverscope`, `640`,
  `KEY=value` without quotes). Each release directory has a `.env` link to
  `../../.env`. The `serverscope-agent.service` unit is unchanged (the
  agent reads its own `.env`; the unit has no `EnvironmentFile`);
- stores the public release key in `/etc/serverscope/release.pub`
  (`root:root`, `644`);
- asks whether the **updater** may be installed (see below; default yes;
  `--updates=on|off` or `SERVERSCOPE_UPDATES=on|off` for automation);
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
the service, the updater, `/opt/serverscope-agent`, `/etc/serverscope`,
`/var/lib/serverscope-updater`, and the `serverscope` user if the
script created it. The server and its history stay in ServerScope. The
Python packages from apt are kept.

## Signed releases and the updater (agent 0.3.0+)

Every agent release is signed with a private key that is kept **offline**
by the ServerScope maintainer, never on the ServerScope server, in the
repository or in CI (`docs/RELEASE_KEYS.md`).

**Release key fingerprint (SHA-256 of the public key, DER):**
`511215f3eccc6640c9ee8d42443d13501e2f291e03aa566e21e1df5efa0a1488`
(key ID `511215f3eccc6640`)

Compare it with the line `Releasesleutel: vingerafdruk …` that install.sh
prints, and on an installed server with:

```sh
openssl pkey -pubin -in /etc/serverscope/release.pub -outform DER | sha256sum
```

**Be aware:** on a FIRST installation the public key itself comes from
install.sh, which comes from the ServerScope server. A compromised
ServerScope server could therefore mislead NEW installations (as it could
with any install script you run as root). It cannot take over EXISTING
installations through the updater: they keep the key they stored at
installation, which ServerScope cannot change. Compare the fingerprint
above on a first installation.

### The updater (`serverscope-updater`)

The agent runs as the unprivileged user `serverscope` with a read-only
installation directory, so it cannot update itself. A small separate
program, `/usr/local/sbin/serverscope-updater`, runs as root from the
systemd timer `serverscope-updater.timer` (every 5 minutes). It does one
thing only: when a team administrator clicks **Bijwerken** in the dashboard
(or **automatisch bijwerken** is on for the server; off by default), it

1. receives a request id and a version number, nothing else (no URLs,
   scripts or commands), and downloads that release from fixed paths;
2. checks the signature over the manifest with the **local** key
   (`/etc/serverscope/release.pub`, plus `/etc/serverscope/release.d/*.pub`
   for a future key rotation), then the manifest, then the SHA-256 and the
   contents of the tarball, then the version: it refuses any version that
   is equal to or lower than the installed one or the highest one ever
   installed here (no downgrades), and a version that already failed here;
3. installs it next to the current version, switches the symlink
   atomically, restarts the agent and waits (at least 3 minutes) until
   ServerScope received a sample from the new version;
4. without that sample it switches back to the previous version and
   restarts it;
5. reports the result: succeeded, failed and rolled back, or refused (with
   a fixed reason such as `bad_signature`).

Logs: `journalctl -u serverscope-updater` (no credentials). Local state:
`sudo serverscope-updater --status`.

**Emergency switch** (ServerScope cannot override it):

```sh
sudo bash serverscope-install.sh --disable-updates
```

This creates `/etc/serverscope/updates-disabled` and stops the timer; as
long as that file exists the updater does nothing at all (not even a
network request). `--enable-updates` turns updates back on.

### Existing installations (agent 0.2.0)

They keep working without doing anything (`POST /api/agent/metrics` is
unchanged). To get signed updates, run install.sh again on the server (the
same command as for a new server; no new enrollment token, no new server):

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://<your ServerScope>/install.sh -o serverscope-install.sh && sudo bash serverscope-install.sh
```

It converts the installation to the release layout, installs the newest
signed release and asks about the updater. Manual installations (without
`/opt/serverscope-agent/.installed-by-serverscope-script`) and the Bash
agent are never touched by the updater.

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
The agent version shown as "verouderd" is compared with the newest
imported signed release.
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
