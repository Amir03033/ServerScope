# ServerScope agent

Collects read-only system metrics (CPU, load, memory, swap, disks, network
counters, uptime) every 60 seconds and sends them over HTTPS to
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

## Token rotation

Use **Token roteren** on the server page, put the new token in `.env` and
run `sudo systemctl restart serverscope-agent`. The old token stops
working immediately.

## Tests

```sh
pip install -r requirements.txt && python -m pytest -q   # from the repository root
```
