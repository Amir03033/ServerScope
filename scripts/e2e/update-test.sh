#!/usr/bin/env bash
# End-to-end test of signed releases and serverscope-updater in clean
# Ubuntu/Debian containers (amd64), against a local ServerScope over HTTPS.
# Run scripts/e2e/update-setup.sh first (same E2E_DIR).
#
# The ServerScope side runs behind scripts/e2e/evil_proxy.py, which can
# play a COMPROMISED server: it can offer any version and replace any
# download. The updater must refuse everything that is not signed with
# the key stored on the customer server.
#
# The containers have no systemd: scripts/e2e/systemctl-shim.sh starts the
# agent like serverscope-agent.service, and the test starts the updater
# itself where the timer would (the shim is also /usr/bin/systemctl: the
# updater runs with a fixed PATH without /usr/local). systemd, the timer and the units'
# sandboxing are therefore not exercised here.
#
# Usage: E2E_DIR=/tmp/e2e scripts/e2e/update-test.sh <image> [<image>…]
set -euo pipefail

E2E_DIR="${E2E_DIR:?set E2E_DIR}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="${REPO}/scripts/e2e"
URL=https://serverscope.test:8443
NEW_PORT=8001
OLD_PORT=8002
EVIL="${E2E_DIR}/evil"
# shellcheck disable=SC1091
. "${E2E_DIR}/app.env"
FAILURES=0
PIDS=()

ok() { printf '  OK   %s\n' "$*"; }
fail() { printf '  FOUT %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
check() { if eval "$2"; then ok "$1"; else fail "$1"; fi; }
found() { if grep -q "$1" "${LOG}.step"; then ok "$2"; else fail "$3"; fi; }
sql() { mysql -uroot -N "${DB_DATABASE}" -e "$1"; }
artisan() { (cd "${REPO}" && php artisan "$@"); }

stop_all() {
    local pid
    for pid in "${PIDS[@]}"; do kill "${pid}" 2>/dev/null || true; done
    PIDS=()
}
trap stop_all EXIT

# The ServerScope at ${URL}: the new code (default) or the phase 2 code.
serve() {
    local which="$1" pid
    stop_all
    (cd "${REPO}/public" && exec php -S 127.0.0.1:${NEW_PORT} "${REPO}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >>"${E2E_DIR}/php-new.log" 2>&1) &
    PIDS+=($!)
    (cd "${E2E_DIR}/old/public" && exec php -S 127.0.0.1:${OLD_PORT} "${REPO}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >>"${E2E_DIR}/php-old.log" 2>&1) &
    PIDS+=($!)
    local backend=${NEW_PORT}
    [ "${which}" = old ] && backend=${OLD_PORT}
    python3 "${HERE}/evil_proxy.py" 8443 "${backend}" "${E2E_DIR}/server.crt" "${E2E_DIR}/server.key" "${EVIL}" &
    PIDS+=($!)
    for pid in $(seq 1 50); do
        curl -s --noproxy '*' --cacert "${E2E_DIR}/ca.crt" --resolve serverscope.test:8443:127.0.0.1 -o /dev/null "${URL}/up" && return 0
        sleep 0.2
    done
    echo "ServerScope start niet" >&2
    exit 1
}

# Only these releases are offered (the rest is withdrawn).
offer_only() {
    local list="'${1:-none}'" version
    shift || true
    for version in "$@"; do list="${list},'${version}'"; done
    sql "UPDATE agent_releases SET withdrawn_at = IF(version IN (${list}), NULL, NOW()), withdraw_reason = IF(version IN (${list}), NULL, 'e2e')"
}

# A real update request for server $1 to version $2; prints its id.
request() {
    sql "UPDATE agent_update_requests SET status='expired' WHERE server_id=$1 AND status IN ('pending','in_progress')"
    sql "INSERT INTO agent_update_requests (team_id, server_id, agent_release_id, from_version, target_version, status, source, created_at, updated_at)
         SELECT s.team_id, s.id, r.id, s.agent_version, r.version, 'pending', 'manual', NOW(), NOW() FROM servers s, agent_releases r WHERE s.id=$1 AND r.version='$2'"
    sql "SELECT MAX(id) FROM agent_update_requests WHERE server_id=$1"
}
request_state() { sql "SELECT CONCAT(status, ' ', IFNULL(reason, '-')) FROM agent_update_requests WHERE id=$1"; }

evil_reset() { rm -rf "${EVIL}"; mkdir -p "${EVIL}/files"; : >"${EVIL}/requests.log"; }
evil_offer() { printf '{"data":{"update":{"request_id":%s,"version":"%s"}}}' "$1" "$2" >"${EVIL}/offer"; }
evil_file() { mkdir -p "$(dirname "${EVIL}/files/$1")"; cp "$2" "${EVIL}/files/$1"; }
bundle_file() { tar -xOf "${E2E_DIR}/bundles/serverscope-agent-$1.bundle.tar" "$2"; }

in_container() { docker exec "${NAME}" bash -c "$1"; }
updater() { docker exec "${NAME}" /usr/local/sbin/serverscope-updater >"${LOG}.step" 2>&1 || true; cat "${LOG}.step" >>"${LOG}"; }
current() { in_container 'readlink /opt/serverscope-agent/serverscope_agent' 2>/dev/null || true; }
server_version() { sql "SELECT IFNULL(agent_version, '') FROM servers WHERE id=${SERVER_ID}"; }

wait_for_version() {
    local _
    for _ in $(seq 1 30); do
        [ "$(server_version)" = "$1" ] && return 0
        sleep 2
    done
    return 1
}

# Runs a command in the container with a pseudo terminal. $2: a prompt
# to wait for, $3: what to type there.
run_tty() {
    local command="$1" prompt="${2:-}" answer="${3:-}"
    rm -f "${LOG}.step" "${LOG}.done" "${LOG}.rc"
    {
        if [ -n "${prompt}" ]; then
            while [ ! -f "${LOG}.done" ] && ! grep -q "${prompt}" "${LOG}.step" 2>/dev/null; do sleep 0.5; done
            [ -f "${LOG}.done" ] || printf '%s\n' "${answer}"
        fi
        while [ ! -f "${LOG}.done" ]; do sleep 0.5; done
    } | {
        docker exec -i "${NAME}" bash -c "cd /root && script -qfec '${command}' /dev/null" >"${LOG}.step" 2>&1
        echo $? >"${LOG}.rc"
        touch "${LOG}.done"
    } || true
    cat "${LOG}.step" >>"${LOG}"
    return "$(cat "${LOG}.rc")"
}
fetch_script() { in_container "curl --proto '=https' --tlsv1.2 -fsSL ${URL}/install.sh -o /root/serverscope-install.sh"; }
newtoken() {
    # shellcheck disable=SC2016 # PHP code
    artisan tinker --execute='
        $team = App\Models\Team::query()->where("is_default", true)->first();
        $user = App\Models\User::query()->firstOrCreate(["email" => "e2e-admin@example.test"], ["name" => "e2e beheerder", "password" => bcrypt(Illuminate\Support\Str::random(32))]);
        if (! $user->teams()->whereKey($team->id)->exists()) { $user->teams()->attach($team->id, ["role" => "owner"]); }
        $user->forceFill(["current_team_id" => $team->id])->save();
        [, $plain] = App\Tenancy\Tenant::run($team->id, fn () => app(App\Services\EnrollmentService::class)->create($user));
        echo $plain;' 2>/dev/null | tail -n 1
}

# --- Once: database, releases, platform administrator.
artisan migrate --force >/dev/null
sql "INSERT IGNORE INTO users (name, email, password, is_platform_admin, created_at, updated_at) VALUES ('e2e platform', 'e2e-platform@example.test', 'x', 1, NOW(), NOW())"
for bundle in "${E2E_DIR}"/bundles/serverscope-agent-0.3.[0124].bundle.tar; do
    artisan serverscope:release:import "${bundle}" --admin=e2e-platform@example.test >/dev/null 2>&1 || true
done
evil_reset
serve new
RELEASE_PUB="$(cat "${E2E_DIR}/keys/release.pub")"

for IMAGE in "$@"; do
    NAME="ss-upd-$(echo "${IMAGE}" | tr -c 'a-z0-9' '-' | tail -c 20)"
    LOG="${E2E_DIR}/${NAME}.log"
    : >"${LOG}"
    before_failures=${FAILURES}
    echo "== ${IMAGE}"
    docker rm -f "${NAME}" >/dev/null 2>&1 || true
    docker run -d --name "${NAME}" --network host --add-host serverscope.test:127.0.0.1 \
        -v "${HERE}/systemctl-shim.sh:/shim/systemctl:ro" -v "${E2E_DIR}/ca.crt:/shim/ca.crt:ro" "${IMAGE}" sleep infinity >/dev/null
    if [ -n "${E2E_APT_PROXY:-}" ]; then
        docker cp "${E2E_APT_CA:?set E2E_APT_CA with E2E_APT_PROXY}" "${NAME}:/etc/apt/e2e-proxy-ca.crt"
        in_container "printf 'Acquire::https::Proxy \"%s\";\nAcquire::https::CAInfo \"/etc/apt/e2e-proxy-ca.crt\";\n' '${E2E_APT_PROXY}' > /etc/apt/apt.conf.d/99e2e-proxy \
            && sed -i 's#http://#https://#g' /etc/apt/sources.list.d/*.sources /etc/apt/sources.list 2>/dev/null; true"
    fi
    in_container "export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >/dev/null && apt-get install -y -qq --no-install-recommends curl ca-certificates procps >/dev/null \
        && cp /shim/ca.crt /usr/local/share/ca-certificates/serverscope-test.crt && update-ca-certificates >/dev/null 2>&1 \
        && install -m 0755 /shim/systemctl /usr/local/bin/systemctl && install -m 0755 /shim/systemctl /usr/bin/systemctl && mkdir -p /run/systemd/system"

    # A. No signed release yet: install.sh changes nothing.
    offer_only
    fetch_script
    packages_before="$(in_container 'dpkg -l | md5sum')"
    servers_before="$(sql 'SELECT COUNT(*) FROM servers')"
    if run_tty "bash serverscope-install.sh" 'Plak het koppeltoken' "$(newtoken)"; then fail "A: installatie zonder release geaccepteerd"; else ok "A: installatie zonder release geweigerd"; fi
    found 'nog geen ondertekende agentrelease' "A: duidelijke melding" "A: onduidelijke melding"
    found 'niets op deze server gewijzigd' "A: meldt dat er niets is gewijzigd" "A: meldt niet dat er niets is gewijzigd"
    check "A: geen pakketten geïnstalleerd" "[ \"$(in_container 'dpkg -l | md5sum')\" = \"${packages_before}\" ]"
    check "A: geen bestanden of gebruiker" "in_container '! test -e /opt/serverscope-agent && ! test -e /etc/serverscope && ! test -e /usr/local/sbin/serverscope-updater && ! id serverscope >/dev/null 2>&1'"
    check "A: geen server aangemaakt" "[ \"$(sql 'SELECT COUNT(*) FROM servers')\" = \"${servers_before}\" ]"

    # B1. An agent 0.2.0, installed with the phase 2 script and code.
    serve old
    fetch_script
    if run_tty "bash serverscope-install.sh" 'Plak het koppeltoken' "$(newtoken)"; then ok "B: 0.2.0 geïnstalleerd met het oude script"; else fail "B: oude installatie mislukt (zie ${LOG})"; fi
    SERVER_ID="$(sql 'SELECT MAX(id) FROM servers')"
    TOKEN_BEFORE="$(in_container "sed -n 's/^SERVERSCOPE_TOKEN=//p' /opt/serverscope-agent/.env")"
    check "B: 0.2.0 in de oude indeling (echte map)" "in_container 'test -d /opt/serverscope-agent/serverscope_agent && ! test -L /opt/serverscope-agent/serverscope_agent'"
    check "B: server meldt 0.2.0" "wait_for_version 0.2.0"

    # ServerScope is upgraded; agent 0.2.0 keeps working without doing anything.
    serve new
    seen="$(sql "SELECT last_seen_at FROM servers WHERE id=${SERVER_ID}")"
    sleep 65
    check "B: 0.2.0 stuurt na de upgrade van ServerScope nog metingen" "[ \"$(sql "SELECT last_seen_at FROM servers WHERE id=${SERVER_ID}")\" != \"${seen}\" ]"

    # B2. Running the new install.sh again: the question (Enter = yes).
    offer_only 0.3.0
    fetch_script
    if run_tty "bash serverscope-install.sh" 'Updateprogramma installeren' ''; then ok "B: install.sh opnieuw gedraaid"; else fail "B: opnieuw draaien mislukt (zie ${LOG})"; fi
    found 'al gekoppeld' "B: herkent de bestaande koppeling" "B: herkent de koppeling niet"
    found "vingerafdruk $(openssl pkey -pubin -in "${E2E_DIR}/keys/release.pub" -outform DER | sha256sum | cut -d' ' -f1)" "B: toont de vingerafdruk" "B: geen vingerafdruk"
    found 'Automatische updates staan AAN' "B: standaard Ja" "B: standaard niet Ja"
    check "B: dezelfde server, zelfde credential" "[ \"$(sql 'SELECT MAX(id) FROM servers')\" = \"${SERVER_ID}\" ] && [ \"$(in_container "sed -n 's/^SERVERSCOPE_TOKEN=//p' /opt/serverscope-agent/.env")\" = \"${TOKEN_BEFORE}\" ]"
    check "B: omgezet naar releases/0.3.0 met symlink" "[ \"$(current)\" = releases/0.3.0/serverscope_agent ]"
    check "B: .env-link in de releasemap" "[ \"$(in_container 'readlink /opt/serverscope-agent/releases/0.3.0/.env')\" = ../../.env ]"
    check "B: release.pub root:root 644 met de sleutel uit install.sh" "[ \"$(in_container 'stat -c "%U:%G %a" /etc/serverscope/release.pub')\" = 'root:root 644' ] && [ \"$(in_container 'cat /etc/serverscope/release.pub')\" = \"${RELEASE_PUB}\" ]"
    check "B: updateprogramma root 755, timer aan" "[ \"$(in_container 'stat -c "%U:%G %a" /usr/local/sbin/serverscope-updater')\" = 'root:root 755' ] && [ \"$(in_container 'cat /run/shim-updater-timer')\" = enabled ]"
    check "B: agent niet schrijfbaar voor serverscope" "in_container '! runuser -u serverscope -- touch /opt/serverscope-agent/releases/0.3.0/serverscope_agent/x 2>/dev/null && ! runuser -u serverscope -- touch /etc/serverscope/release.pub 2>/dev/null'"
    check "B: .env 640, niets leesbaar voor other" "[ \"$(in_container 'stat -c %a /opt/serverscope-agent/.env')\" = 640 ] && [ -z \"$(in_container 'find /opt/serverscope-agent -maxdepth 1 -name ".env*" -perm /o=r')\" ]"
    check "B: de agent stuurt metingen als 0.3.0" "wait_for_version 0.3.0"
    in_container "sed -i 's/^SERVERSCOPE_INTERVAL=.*/SERVERSCOPE_INTERVAL=10/' /opt/serverscope-agent/.env && systemctl restart serverscope-agent"

    # C. A successful update 0.3.0 → 0.3.1.
    offer_only 0.3.0 0.3.1
    id="$(request "${SERVER_ID}" 0.3.1)"
    updater
    check "C: verzoek gelukt" "[ \"$(request_state "${id}")\" = 'succeeded -' ]"
    check "C: symlink naar 0.3.1" "[ \"$(current)\" = releases/0.3.1/serverscope_agent ]"
    check "C: de agent stuurt metingen als 0.3.1" "wait_for_version 0.3.1"
    check "C: credential niet in de uitvoer" "! grep -qF \"${TOKEN_BEFORE}\" \"${LOG}\""
    check "C: hoogste versie 0.3.1" "[ \"$(in_container 'cat /var/lib/serverscope-updater/highest-version')\" = 0.3.1 ]"

    # D. Compromised server: a different tarball under the signed manifest.
    offer_only 0.3.0 0.3.1 0.3.2
    id="$(request "${SERVER_ID}" 0.3.2)"
    bundle_file 0.3.4 serverscope-agent-0.3.4.tar.gz >"${E2E_DIR}/other.tar.gz"
    evil_file agent/releases/0.3.2/serverscope-agent-0.3.2.tar.gz "${E2E_DIR}/other.tar.gz"
    updater
    check "D: gemanipuleerde tarball geweigerd (bad_checksum)" "[ \"$(request_state "${id}")\" = 'rejected bad_checksum' ]"
    check "D: niets gewijzigd" "[ \"$(current)\" = releases/0.3.1/serverscope_agent ] && in_container '! test -e /opt/serverscope-agent/releases/0.3.2'"

    # E. Compromised server: a release signed with another key, and a
    #    changed manifest under the real signature.
    evil_reset
    id="$(request "${SERVER_ID}" 0.3.2)"
    evil_offer "${id}" 0.3.3
    bundle_file 0.3.3 manifest >"${E2E_DIR}/m"; evil_file agent/releases/0.3.3/manifest "${E2E_DIR}/m"
    bundle_file 0.3.3 manifest.sig >"${E2E_DIR}/m"; evil_file agent/releases/0.3.3/manifest.sig "${E2E_DIR}/m"
    bundle_file 0.3.3 serverscope-agent-0.3.3.tar.gz >"${E2E_DIR}/m"; evil_file agent/releases/0.3.3/serverscope-agent-0.3.3.tar.gz "${E2E_DIR}/m"
    updater
    check "E: andere sleutel geweigerd (bad_signature)" "[ \"$(request_state "${id}")\" = 'rejected bad_signature' ]"
    evil_reset
    id="$(request "${SERVER_ID}" 0.3.2)"
    bundle_file 0.3.2 manifest | sed "s/^sha256=.*/sha256=$(sha256sum "${E2E_DIR}/other.tar.gz" | cut -d' ' -f1)/; s/^size=.*/size=$(stat -c %s "${E2E_DIR}/other.tar.gz")/" >"${E2E_DIR}/m"
    evil_file agent/releases/0.3.2/manifest "${E2E_DIR}/m"
    evil_file agent/releases/0.3.2/serverscope-agent-0.3.2.tar.gz "${E2E_DIR}/other.tar.gz"
    updater
    check "E: gewijzigd manifest geweigerd (bad_signature)" "[ \"$(request_state "${id}")\" = 'rejected bad_signature' ]"
    check "E: niets gewijzigd" "[ \"$(current)\" = releases/0.3.1/serverscope_agent ]"
    evil_reset

    # F. Compromised server: an older and the same version (both validly signed).
    for version in 0.3.0 0.3.1; do
        id="$(request "${SERVER_ID}" 0.3.2)"
        evil_offer "${id}" "${version}"
        updater
        check "F: ${version} geweigerd (downgrade)" "[ \"$(request_state "${id}")\" = 'rejected downgrade' ]"
    done
    check "F: niets gewijzigd" "[ \"$(current)\" = releases/0.3.1/serverscope_agent ]"
    evil_reset

    # G. A signed release that never sends a sample: rolled back.
    offer_only 0.3.0 0.3.1 0.3.2 0.3.4
    id="$(request "${SERVER_ID}" 0.3.4)"
    updater
    check "G: mislukt en teruggezet (no_sample)" "[ \"$(request_state "${id}")\" = 'rolled_back no_sample' ]"
    check "G: symlink terug op 0.3.1" "[ \"$(current)\" = releases/0.3.1/serverscope_agent ]"
    check "G: agent draait weer" "in_container 'systemctl is-active serverscope-agent'"
    seen="$(sql "SELECT last_seen_at FROM servers WHERE id=${SERVER_ID}")"
    sleep 15
    check "G: 0.3.1 stuurt weer metingen" "[ \"$(sql "SELECT last_seen_at FROM servers WHERE id=${SERVER_ID}")\" != \"${seen}\" ] && [ \"$(server_version)\" = 0.3.1 ]"
    id="$(request "${SERVER_ID}" 0.3.4)"
    updater
    check "G: mislukte versie niet opnieuw (already_failed)" "[ \"$(request_state "${id}")\" = 'rejected already_failed' ]"

    # H. The local emergency switch wins, even when the server offers an update.
    if run_tty "bash serverscope-install.sh --disable-updates"; then ok "H: --disable-updates"; else fail "H: --disable-updates mislukt"; fi
    check "H: noodknop-bestand en timer uit" "in_container 'test -f /etc/serverscope/updates-disabled' && [ \"$(in_container 'cat /run/shim-updater-timer')\" = disabled ]"
    check "H: ServerScope weet dat updates lokaal uit staan" "[ \"$(sql "SELECT updater_state FROM servers WHERE id=${SERVER_ID}")\" = disabled ]"
    id="$(request "${SERVER_ID}" 0.3.2)"
    evil_offer "${id}" 0.3.2
    : >"${EVIL}/requests.log"
    updater
    found 'lokaal uitgeschakeld' "H: updateprogramma meldt de noodknop" "H: geen melding van de noodknop"
    check "H: geen enkel verzoek naar de server" "[ ! -s \"${EVIL}/requests.log\" ]"
    check "H: verzoek blijft wachtend, niets gewijzigd" "[ \"$(request_state "${id}")\" = 'pending -' ] && [ \"$(current)\" = releases/0.3.1/serverscope_agent ]"
    rm -f "${EVIL}/offer"
    if run_tty "bash serverscope-install.sh --enable-updates"; then ok "H: --enable-updates"; else fail "H: --enable-updates mislukt"; fi
    updater
    check "H: daarna wel bijgewerkt naar 0.3.2" "[ \"$(request_state "${id}")\" = 'succeeded -' ] && [ \"$(current)\" = releases/0.3.2/serverscope_agent ]"
    check "H: de agent stuurt metingen als 0.3.2" "wait_for_version 0.3.2"
    check "H: alleen huidige en vorige release bewaard" "[ \"$(in_container 'ls /opt/serverscope-agent/releases | tr "\n" " "')\" = '0.3.1 0.3.2 ' ]"

    # I. Uninstall removes the updater too.
    if run_tty "bash serverscope-install.sh --uninstall"; then ok "I: --uninstall"; else fail "I: --uninstall mislukt"; fi
    check "I: alles verwijderd" "in_container '! test -e /opt/serverscope-agent && ! test -e /etc/serverscope && ! test -e /usr/local/sbin/serverscope-updater && ! test -e /etc/systemd/system/serverscope-updater.timer && ! test -e /var/lib/serverscope-updater'"

    docker rm -f "${NAME}" >/dev/null
    echo "== ${IMAGE}: $((FAILURES - before_failures)) fout(en); log: ${LOG}"
done

[ "${FAILURES}" -eq 0 ]
