#!/usr/bin/env bash
# Test stand-in for systemctl in containers without systemd. Starts the
# agent exactly like serverscope-agent.service does (same user, working
# directory and command) and answers the calls install.sh and
# serverscope-updater make.
set -euo pipefail
PIDFILE=/run/serverscope-agent.pid
start() {
    stop
    cd /opt/serverscope-agent
    nohup runuser -u serverscope -- /opt/serverscope-agent/.venv/bin/python -m serverscope_agent.agent >>/var/log/serverscope-agent.log 2>&1 &
    echo $! >"${PIDFILE}"
}
stop() {
    # Like systemctl stop: return only when the agent has exited.
    if [ -f "${PIDFILE}" ]; then
        local pid children _
        pid="$(cat "${PIDFILE}")"
        children="$(pgrep -P "${pid}" || true)"
        # shellcheck disable=SC2086 # several pids, one per word
        kill ${children} "${pid}" 2>/dev/null || true
        for _ in $(seq 1 50); do
            # shellcheck disable=SC2086
            kill -0 ${children} "${pid}" 2>/dev/null || break
            sleep 0.1
        done
        # shellcheck disable=SC2086
        kill -9 ${children} "${pid}" 2>/dev/null || true
        rm -f "${PIDFILE}"
    fi
}
args=("$@")
# The updater timer is only recorded (the test runs the updater itself);
# every other unit is the agent.
if [[ " ${args[*]} " == *" serverscope-updater.timer "* ]]; then
    case "${args[0]}" in
        enable) echo enabled >/run/shim-updater-timer ;;
        disable) echo disabled >/run/shim-updater-timer ;;
        *) echo "shim: unsupported: ${args[*]}" >&2; exit 1 ;;
    esac
    exit 0
fi
case "${args[0]:-}" in
    daemon-reload) ;;
    enable) ;;
    restart | start) start ;;
    is-active) [ -f "${PIDFILE}" ] && kill -0 "$(cat "${PIDFILE}")" 2>/dev/null ;;
    disable) if [[ " ${args[*]} " == *" --now "* ]]; then stop; fi ;;
    stop) stop ;;
    *) echo "shim: unsupported: ${args[*]}" >&2; exit 1 ;;
esac
