#!/usr/bin/env bash
# End-to-end test of install.sh in a clean Ubuntu/Debian container against a
# locally running ServerScope (see docs/AUDIT.md §6 for the setup):
#   - the app on https://serverscope.test:8443 (php -S behind
#     scripts/e2e/tls_proxy.py), MySQL database $E2E_DB;
#   - $E2E_DIR with ca.crt (test CA) and newtoken.sh (prints a fresh
#     enrollment token for the default team);
#   - docker with access to the distribution's package mirror.
# The container has no systemd: scripts/e2e/systemctl-shim.sh starts the
# agent exactly like serverscope-agent.service (same user, directory and
# command). systemd itself and the unit's sandboxing are not exercised.
#
# Usage: scripts/e2e/install-test.sh <image>   e.g. mirror.gcr.io/library/ubuntu:22.04
set -euo pipefail

IMAGE="$1"
E2E_DIR="${E2E_DIR:?set E2E_DIR}"
E2E_DB="${E2E_DB:-serverscope_e2e}"
URL=https://serverscope.test:8443
NAME="ss-e2e-$(echo "${IMAGE}" | tr -c 'a-z0-9' '-' | tail -c 20)"
HERE="$(cd "$(dirname "$0")" && pwd)"
LOG="${E2E_DIR}/${NAME}.log"
FAILURES=0

ok() { printf '  OK   %s\n' "$*"; }
fail() { printf '  FOUT %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
check() { if eval "$2"; then ok "$1"; else fail "$1"; fi; }
sql() { mysql -uroot -N "${E2E_DB}" -e "$1"; }
in_container() { docker exec "${NAME}" bash -c "$1"; }

# Runs the documented command; the token (if any) is typed at the hidden
# prompt through a pseudo terminal. Output is appended to the log.
run_install() {
    local token="$1" args="${2:-}" env="${3:-}"
    rm -f "${LOG}.step" "${LOG}.done" "${LOG}.rc"
    # The token is sent only after the prompt is shown, like a person
    # pasting it; typeahead would be echoed by the terminal before the
    # script switches echo off.
    {
        while [ ! -f "${LOG}.done" ] && ! grep -q 'Plak het koppeltoken' "${LOG}.step" 2>/dev/null; do sleep 0.5; done
        [ -f "${LOG}.done" ] || printf '%s\n' "${token}"
    } | {
        docker exec -i ${env:+-e "$env"} "${NAME}" bash -c "cd /root && curl --proto '=https' --tlsv1.2 -fsSL ${URL}/install.sh -o serverscope-install.sh && script -qfec 'bash serverscope-install.sh ${args}' /dev/null" >"${LOG}.step" 2>&1
        echo $? >"${LOG}.rc"
        touch "${LOG}.done"
    } || true
    cat "${LOG}.step" >>"${LOG}"
    return "$(cat "${LOG}.rc")"
}

no_secret_in_output() {
    local secret="$1" label="$2"
    if [ -n "${secret}" ] && grep -qF "${secret}" "${LOG}.step"; then fail "${label} staat in de uitvoer"; else ok "${label} staat niet in de uitvoer"; fi
}

echo "== ${IMAGE}"
: >"${LOG}"
docker rm -f "${NAME}" >/dev/null 2>&1 || true
docker run -d --name "${NAME}" --network host --add-host serverscope.test:127.0.0.1 \
    -v "${HERE}/systemctl-shim.sh:/shim/systemctl:ro" -v "${E2E_DIR}/ca.crt:/shim/ca.crt:ro" \
    "${IMAGE}" sleep infinity >/dev/null

# Optional (test environments whose package mirrors are only reachable
# through an HTTPS proxy): point apt, and only apt, at that proxy and use
# https package sources. install.sh, curl and the agent still connect to
# ServerScope directly.
if [ -n "${E2E_APT_PROXY:-}" ]; then
    docker cp "${E2E_APT_CA:?set E2E_APT_CA with E2E_APT_PROXY}" "${NAME}:/etc/apt/e2e-proxy-ca.crt"
    in_container "printf 'Acquire::https::Proxy \"%s\";\nAcquire::https::CAInfo \"/etc/apt/e2e-proxy-ca.crt\";\n' '${E2E_APT_PROXY}' > /etc/apt/apt.conf.d/99e2e-proxy \
        && sed -i 's#http://#https://#g' /etc/apt/sources.list.d/*.sources /etc/apt/sources.list 2>/dev/null; true"
fi

# Test harness only (not part of install.sh): curl for the documented
# command, the test CA, and the systemctl stand-in.
in_container "export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null && apt-get install -y -qq --no-install-recommends curl ca-certificates procps >/dev/null \
    && cp /shim/ca.crt /usr/local/share/ca-certificates/serverscope-test.crt && update-ca-certificates >/dev/null 2>&1 \
    && install -m 0755 /shim/systemctl /usr/local/bin/systemctl && mkdir -p /run/systemd/system"

# 1. First installation through the hidden prompt.
TOKEN="$("${E2E_DIR}/newtoken.sh")"
before="$(sql 'SELECT COUNT(*) FROM servers')"
if run_install "${TOKEN}"; then ok "installatie geslaagd"; else fail "installatie mislukt (zie ${LOG})"; fi
grep -q 'Gelukt:' "${LOG}.step" && ok "succesmelding getoond" || fail "geen succesmelding"
SERVER_ID="$(sql 'SELECT MAX(id) FROM servers')"
check "precies één nieuwe server" "[ \"$(sql 'SELECT COUNT(*) FROM servers')\" = \"$((before + 1))\" ]"
check "server staat online (eerste meting ontvangen)" "[ \"$(sql "SELECT status FROM servers WHERE id=${SERVER_ID}")\" = online ]"
check "server in het team van het token (standaardteam)" "[ \"$(sql "SELECT t.is_default FROM servers s JOIN teams t ON t.id=s.team_id WHERE s.id=${SERVER_ID}")\" = 1 ]"
AGENT_TOKEN="$(in_container "sed -n 's/^SERVERSCOPE_TOKEN=//p' /opt/serverscope-agent/.env")"
no_secret_in_output "${TOKEN}" "koppeltoken"
no_secret_in_output "${AGENT_TOKEN}" "agentcredential"
check ".env is root:serverscope 640" "[ \"$(in_container 'stat -c "%U:%G %a" /opt/serverscope-agent/.env')\" = 'root:serverscope 640' ]"
check ".env zonder aanhalingstekens of spaties rond =" "in_container \"! grep -qE '\\\"|'\\\"'\\\"'| =|= ' /opt/serverscope-agent/.env\""
check "indeling: pakket naast .env" "in_container 'test -f /opt/serverscope-agent/serverscope_agent/agent.py && test -f /opt/serverscope-agent/.venv/bin/python'"
check "venv met system-site-packages laadt psutil, requests en dotenv" "in_container '/opt/serverscope-agent/.venv/bin/python -c \"import psutil, requests, dotenv\"'"
check "gebruiker serverscope zonder shell" "[ \"$(in_container 'getent passwd serverscope | cut -d: -f7')\" = /usr/sbin/nologin ]"
check "unit ongewijzigd en zonder EnvironmentFile" "in_container '! grep -q EnvironmentFile /etc/systemd/system/serverscope-agent.service'"
check "agent draait als serverscope" "in_container 'pgrep -u serverscope -f \"[s]erverscope_agent.agent\" >/dev/null'"

# 2. Running it again changes nothing and needs no token. A leftover
#    temporary .env file (interrupted earlier run) is planted, and a watcher
#    looks every 50 ms for any .env* that others can read.
in_container "umask 077; printf 'SERVERSCOPE_TOKEN=achtergebleven\n' > /opt/serverscope-agent/.env.Ab12Cd"
docker exec -d "${NAME}" bash -c 'echo $$ > /tmp/watch.pid; while true; do find /opt/serverscope-agent -maxdepth 1 -name ".env*" -perm /o=r -printf "%p %m\n" >> /tmp/env-watch 2>/dev/null; sleep 0.05; done'
if run_install ""; then ok "tweede keer draaien geslaagd"; else fail "tweede keer draaien mislukt"; fi
in_container 'kill "$(cat /tmp/watch.pid)"'
grep -q 'al gekoppeld' "${LOG}.step" && ok "herkent bestaande koppeling" || fail "herkent bestaande koppeling niet"
check "geen extra server" "[ \"$(sql 'SELECT COUNT(*) FROM servers')\" = \"$((before + 1))\" ]"
check "credential ongewijzigd" "[ \"$(in_container "sed -n 's/^SERVERSCOPE_TOKEN=//p' /opt/serverscope-agent/.env")\" = \"${AGENT_TOKEN}\" ]"
check ".env na de tweede run root:serverscope 640" "[ \"$(in_container 'stat -c "%U:%G %a" /opt/serverscope-agent/.env')\" = 'root:serverscope 640' ]"
check "geen .env* leesbaar voor other (na afloop)" "[ -z \"$(in_container 'find /opt/serverscope-agent -maxdepth 1 -name ".env*" -perm /o=r')\" ]"
check "nooit een .env* leesbaar voor other tijdens de run" "[ -z \"$(in_container 'cat /tmp/env-watch 2>/dev/null')\" ]"
check "achtergebleven tijdelijk .env-bestand verwijderd" "in_container '! test -e /opt/serverscope-agent/.env.Ab12Cd'"

# 3. Credential revoked in the dashboard: a new token creates a NEW server
#    (token from the environment variable, the automation path).
sql "UPDATE agent_credentials SET revoked_at = NOW() WHERE server_id = ${SERVER_ID}"
TOKEN2="$("${E2E_DIR}/newtoken.sh")"
if run_install "" "" "SERVERSCOPE_ENROLL_TOKEN=${TOKEN2}"; then ok "opnieuw koppelen geslaagd"; else fail "opnieuw koppelen mislukt"; fi
grep -q 'NIEUWE server' "${LOG}.step" && ok "meldt dat er een nieuwe server komt" || fail "geen melding over nieuwe server"
SERVER2="$(sql 'SELECT MAX(id) FROM servers')"
check "nieuwe server aangemaakt en online" "[ \"${SERVER2}\" != \"${SERVER_ID}\" ] && [ \"$(sql "SELECT status FROM servers WHERE id=${SERVER2}")\" = online ]"
AGENT_TOKEN2="$(in_container "sed -n 's/^SERVERSCOPE_TOKEN=//p' /opt/serverscope-agent/.env")"
no_secret_in_output "${TOKEN2}" "koppeltoken (omgevingsvariabele)"
no_secret_in_output "${AGENT_TOKEN2}" "nieuwe agentcredential"

# 4. Uninstall: revokes the credential, removes only what the script made.
if run_install "" "--uninstall"; then ok "--uninstall geslaagd"; else fail "--uninstall mislukt"; fi
check "credential ingetrokken via de API" "[ \"$(sql "SELECT COUNT(*) FROM agent_credentials WHERE server_id=${SERVER2} AND revoked_at IS NULL")\" = 0 ]"
check "installatiemap verwijderd" "in_container '! test -e /opt/serverscope-agent'"
check "unit verwijderd" "in_container '! test -e /etc/systemd/system/serverscope-agent.service'"
check "gebruiker verwijderd (door het script aangemaakt)" "in_container '! id serverscope >/dev/null 2>&1'"
check "agent gestopt" "in_container '! pgrep -f \"[s]erverscope_agent.agent\" >/dev/null'"
check "server blijft in het dashboard" "[ \"$(sql "SELECT COUNT(*) FROM servers WHERE id=${SERVER2}")\" = 1 ]"

# 5. A manual installation is left alone.
in_container "mkdir -p /opt/serverscope-agent && echo SERVERSCOPE_TOKEN=handmatig > /opt/serverscope-agent/.env"
TOKEN3="$("${E2E_DIR}/newtoken.sh")"
if run_install "${TOKEN3}"; then fail "handmatige installatie werd overschreven"; else ok "handmatige installatie wordt niet aangeraakt"; fi
check "handmatige .env ongewijzigd" "[ \"$(in_container 'cat /opt/serverscope-agent/.env')\" = 'SERVERSCOPE_TOKEN=handmatig' ]"
in_container "rm -rf /opt/serverscope-agent"

# 6. An invalid token gives the neutral message and changes nothing.
before="$(sql 'SELECT COUNT(*) FROM servers')"
if run_install "sse_$(printf '0%.0s' $(seq 1 48))"; then fail "ongeldig token geaccepteerd"; else ok "ongeldig token geweigerd"; fi
grep -q 'ongeldig, verlopen, al gebruikt of ingetrokken' "${LOG}.step" && ok "duidelijke melding met oplossing" || fail "onduidelijke melding"
check "geen server aangemaakt" "[ \"$(sql 'SELECT COUNT(*) FROM servers')\" = \"${before}\" ]"

docker rm -f "${NAME}" >/dev/null
echo "== ${IMAGE}: ${FAILURES} fout(en); log: ${LOG}"
[ "${FAILURES}" -eq 0 ]
