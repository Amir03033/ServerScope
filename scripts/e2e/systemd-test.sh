#!/usr/bin/env bash
# With REAL systemd (a privileged container running systemd as PID 1):
# install.sh, the agent unit, the updater timer and one update through
# the hardened serverscope-updater.service. Run update-test.sh first in the
# same E2E_DIR (it leaves the database, releases and app.env in place);
# this script starts the application itself.
#
# Usage: E2E_DIR=/tmp/e2e scripts/e2e/systemd-test.sh [image]   (default ubuntu:24.04)
set -euo pipefail

E2E_DIR="${E2E_DIR:?set E2E_DIR}"
IMAGE="${1:-mirror.gcr.io/library/ubuntu:24.04}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="${REPO}/scripts/e2e"
NAME=ss-systemd
URL=https://serverscope.test:8443
# shellcheck disable=SC1091
. "${E2E_DIR}/app.env"
FAILURES=0
PIDS=()
ok() { printf '  OK   %s\n' "$*"; }
fail() { printf '  FOUT %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
check() { if eval "$2"; then ok "$1"; else fail "$1"; fi; }
sql() { mysql -uroot -N "${DB_DATABASE}" -e "$1"; }
in_container() { docker exec "${NAME}" bash -c "$1"; }
cleanup() {
    local pid
    for pid in "${PIDS[@]}"; do kill "${pid}" 2>/dev/null || true; done
    docker rm -f "${NAME}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

(cd "${REPO}/public" && exec php -S 127.0.0.1:8001 "${REPO}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >>"${E2E_DIR}/php-systemd.log" 2>&1) &
PIDS+=($!)
mkdir -p "${E2E_DIR}/evil-systemd/files"
python3 "${HERE}/evil_proxy.py" 8443 8001 "${E2E_DIR}/server.crt" "${E2E_DIR}/server.key" "${E2E_DIR}/evil-systemd" &
PIDS+=($!)
sleep 2

sql "UPDATE agent_releases SET withdrawn_at = IF(version IN ('0.3.0','0.3.1'), NULL, NOW())"
# shellcheck disable=SC2016 # PHP code
TOKEN="$(cd "${REPO}" && php artisan tinker --execute='
    $team = App\Models\Team::query()->where("is_default", true)->first();
    $user = App\Models\User::query()->where("email", "e2e-admin@example.test")->first();
    [, $plain] = App\Tenancy\Tenant::run($team->id, fn () => app(App\Services\EnrollmentService::class)->create($user));
    echo $plain;' 2>/dev/null | tail -n 1)"

# An image with systemd as init (built once).
if ! docker image inspect ss-systemd-image >/dev/null 2>&1; then
    docker rm -f ss-systemd-build >/dev/null 2>&1 || true
    docker create --name ss-systemd-build --network host "${IMAGE}" sleep infinity >/dev/null
    docker start ss-systemd-build >/dev/null
    if [ -n "${E2E_APT_PROXY:-}" ]; then
        docker cp "${E2E_APT_CA}" ss-systemd-build:/etc/apt/e2e-proxy-ca.crt
        docker exec ss-systemd-build bash -c "printf 'Acquire::https::Proxy \"%s\";\nAcquire::https::CAInfo \"/etc/apt/e2e-proxy-ca.crt\";\n' '${E2E_APT_PROXY}' > /etc/apt/apt.conf.d/99e2e-proxy && sed -i 's#http://#https://#g' /etc/apt/sources.list.d/*.sources /etc/apt/sources.list 2>/dev/null; true"
    fi
    docker exec ss-systemd-build bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null && apt-get install -y -qq --no-install-recommends systemd systemd-sysv dbus curl ca-certificates procps >/dev/null'
    docker cp "${E2E_DIR}/ca.crt" ss-systemd-build:/usr/local/share/ca-certificates/serverscope-test.crt
    docker exec ss-systemd-build update-ca-certificates >/dev/null 2>&1
    docker commit ss-systemd-build ss-systemd-image >/dev/null
    docker rm -f ss-systemd-build >/dev/null
fi

echo "== systemd (${IMAGE})"
docker rm -f "${NAME}" >/dev/null 2>&1 || true
docker run -d --name "${NAME}" --privileged --cgroupns=host --network host --add-host serverscope.test:127.0.0.1 \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock ss-systemd-image /sbin/init >/dev/null
for _ in $(seq 1 30); do
    state="$(in_container 'systemctl is-system-running 2>/dev/null' || true)"
    case "${state}" in running | degraded) break ;; esac
    sleep 1
done
check "systemd draait als PID 1" "[ \"$(in_container 'ps -p 1 -o comm=')\" = systemd ]"

in_container "curl --proto '=https' --tlsv1.2 -fsSL ${URL}/install.sh -o /root/serverscope-install.sh"
if docker exec -e SERVERSCOPE_ENROLL_TOKEN="${TOKEN}" "${NAME}" bash /root/serverscope-install.sh --updates=on >"${E2E_DIR}/systemd.log" 2>&1; then ok "installatie met echte systemd"; else fail "installatie mislukt (zie ${E2E_DIR}/systemd.log)"; fi
SERVER_ID="$(sql 'SELECT MAX(id) FROM servers')"
check "agent-service actief" "in_container 'systemctl is-active --quiet serverscope-agent'"
check "updatetimer actief en ingepland" "in_container 'systemctl is-active --quiet serverscope-updater.timer && systemctl list-timers serverscope-updater.timer --no-legend | grep -q serverscope-updater'"

sql "INSERT INTO agent_update_requests (team_id, server_id, agent_release_id, from_version, target_version, status, source, created_at, updated_at)
     SELECT s.team_id, s.id, r.id, s.agent_version, r.version, 'pending', 'manual', NOW(), NOW() FROM servers s, agent_releases r WHERE s.id=${SERVER_ID} AND r.version='0.3.1'"
# Start the oneshot service the timer would start: the real, hardened unit.
in_container 'systemctl start serverscope-updater.service' || true
check "update via de geharde unit gelukt" "[ \"$(sql "SELECT status FROM agent_update_requests WHERE server_id=${SERVER_ID} ORDER BY id DESC LIMIT 1")\" = succeeded ]"
check "agent draait 0.3.1 onder systemd" "[ \"$(in_container 'readlink /opt/serverscope-agent/serverscope_agent')\" = releases/0.3.1/serverscope_agent ] && in_container 'systemctl is-active --quiet serverscope-agent'"
check "journal van de updater zonder credential" "! in_container 'journalctl -u serverscope-updater --no-pager' | grep -qE '[a-f0-9]{64}'"
in_container 'journalctl -u serverscope-updater --no-pager -o cat' >>"${E2E_DIR}/systemd.log" 2>&1 || true
in_container 'systemd-analyze security serverscope-updater.service --no-pager 2>/dev/null | tail -n 1' | sed 's/^/  info: /' || true
in_container 'systemd-analyze security serverscope-agent.service --no-pager 2>/dev/null | tail -n 1' | sed 's/^/  info: /' || true

touch "${E2E_DIR}/evil-systemd/requests.log"
if docker exec "${NAME}" bash /root/serverscope-install.sh --disable-updates >>"${E2E_DIR}/systemd.log" 2>&1; then ok "--disable-updates"; else fail "--disable-updates mislukt"; fi
check "timer uit na de noodknop" "! in_container 'systemctl is-active --quiet serverscope-updater.timer'"
: >"${E2E_DIR}/evil-systemd/requests.log"
in_container 'systemctl start serverscope-updater.service' || true
check "service start niet bij de noodknop (ConditionPathExists)" "[ ! -s \"${E2E_DIR}/evil-systemd/requests.log\" ]"

echo "== systemd: ${FAILURES} fout(en); log: ${E2E_DIR}/systemd.log"
[ "${FAILURES}" -eq 0 ]
