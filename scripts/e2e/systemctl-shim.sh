#!/usr/bin/env bash
# Test stand-in for systemctl in containers without systemd. Starts the
# agent exactly like serverscope-agent.service does (same user, working
# directory and command) and answers the calls install.sh makes.
set -euo pipefail
PIDFILE=/run/serverscope-agent.pid
start() {
    stop
    cd /opt/serverscope-agent
    nohup runuser -u serverscope -- /opt/serverscope-agent/.venv/bin/python -m serverscope_agent.agent >>/var/log/serverscope-agent.log 2>&1 &
    echo $! >"${PIDFILE}"
}
stop() {
    if [ -f "${PIDFILE}" ]; then
        pkill -P "$(cat "${PIDFILE}")" 2>/dev/null || true
        kill "$(cat "${PIDFILE}")" 2>/dev/null || true
        rm -f "${PIDFILE}"
    fi
}
args=("$@")
case "${args[0]:-}" in
    daemon-reload) ;;
    enable) ;;
    restart | start) start ;;
    is-active) [ -f "${PIDFILE}" ] && kill -0 "$(cat "${PIDFILE}")" 2>/dev/null ;;
    disable) [[ " ${args[*]} " == *" --now "* ]] && stop ;;
    stop) stop ;;
    *) echo "shim: unsupported: ${args[*]}" >&2; exit 1 ;;
esac
