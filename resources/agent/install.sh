#!/usr/bin/env bash
# ServerScope agent installer for Ubuntu 22.04+ and Debian 12+ (amd64, arm64).
#
# Gebruik:
#   sudo bash serverscope-install.sh             installeren en koppelen
#   sudo bash serverscope-install.sh --uninstall  verwijderen
#
# Het koppeltoken staat bewust niet in het commando (dan belandt het in de
# shell-geschiedenis en in de proceslijst). Het script vraagt erom, of leest
# het uit de omgevingsvariabele SERVERSCOPE_ENROLL_TOKEN (automatisering).
#
# Wat dit script doet, als root:
#   - installeert python3, python3-venv, python3-psutil, python3-requests en
#     python3-dotenv uit de pakketbronnen van je distributie (geen pip);
#   - maakt de systeemgebruiker 'serverscope' zonder shell (als die nog niet
#     bestaat);
#   - installeert de agent in /opt/serverscope-agent (venv met
#     --system-site-packages) na controle van de SHA-256-checksum;
#   - koppelt de server aan je team en schrijft /opt/serverscope-agent/.env
#     (root:serverscope, 640);
#   - installeert en start de systemd-service serverscope-agent.
# De agent leest alleen systeemgegevens en voert geen opdrachten van de
# server uit. De checksum komt van dezelfde server als het pakket: hij
# beschermt tegen een kapotte download, niet tegen een gehackte server.

set -euo pipefail
umask 022

readonly SERVERSCOPE_URL_DEFAULT='__SERVERSCOPE_URL__'
readonly AGENT_VERSION='__AGENT_VERSION__'
readonly ALLOW_INSECURE='__ALLOW_INSECURE__'

readonly INSTALL_DIR=/opt/serverscope-agent
readonly ENV_FILE="${INSTALL_DIR}/.env"
readonly MARKER="${INSTALL_DIR}/.installed-by-serverscope-script"
readonly UNIT_NAME=serverscope-agent.service
readonly UNIT_FILE="/etc/systemd/system/${UNIT_NAME}"
readonly AGENT_USER=serverscope
readonly BASE_URL="${SERVERSCOPE_URL_DEFAULT%/}"

WORK_DIR=''

info() { printf '==> %s\n' "$*"; }
warn() { printf 'LET OP: %s\n' "$*" >&2; }
die() {
    printf 'FOUT: %s\n' "$1" >&2
    if [ "$#" -gt 1 ]; then
        printf 'Oplossing: %s\n' "$2" >&2
    fi
    exit 1
}

cleanup() {
    if [ -n "${WORK_DIR}" ] && [ -d "${WORK_DIR}" ]; then
        rm -rf -- "${WORK_DIR}"
    fi
}
trap cleanup EXIT

# --- curl: HTTPS only (TLS 1.2+), unless explicitly allowed for local tests.
curl_safe() {
    if [ "${ALLOW_INSECURE}" = "1" ] && [[ "${BASE_URL}" == http://* ]]; then
        curl -sS --connect-timeout 10 --max-time 60 "$@"
    else
        curl --proto '=https' --tlsv1.2 -sS --connect-timeout 10 --max-time 60 "$@"
    fi
}

check_url() {
    case "${BASE_URL}" in
        https://*) ;;
        http://*)
            if [ "${ALLOW_INSECURE}" != "1" ]; then
                die "ServerScope-adres ${BASE_URL} gebruikt geen https; het script weigert onversleutelde verbindingen." \
                    "Stel APP_URL van ServerScope in op een https-adres en download het script opnieuw."
            fi
            warn "Onversleutelde verbinding naar ${BASE_URL} (alleen bedoeld voor lokaal testen)."
            ;;
        *) die "Ongeldig ServerScope-adres in dit script." "Download het script opnieuw vanuit ServerScope." ;;
    esac
}

check_system() {
    [ "$(id -u)" -eq 0 ] || die "Dit script moet als root draaien." "Start het met: sudo bash $0"

    [ -r /etc/os-release ] || die "Kan het besturingssysteem niet bepalen (/etc/os-release ontbreekt)." \
        "Alleen Ubuntu 22.04+ en Debian 12+ worden ondersteund; installeer de agent anders handmatig (zie de documentatie)."
    # shellcheck disable=SC1091
    . /etc/os-release
    local id="${ID:-}" version="${VERSION_ID:-0}" major
    major="${version%%.*}"

    case "${id}" in
        ubuntu) [ "${major}" -ge 22 ] 2>/dev/null || die "Ubuntu ${version} wordt niet ondersteund." "Gebruik Ubuntu 22.04 of nieuwer, of installeer de agent handmatig." ;;
        debian) [ "${major}" -ge 12 ] 2>/dev/null || die "Debian ${version} wordt niet ondersteund." "Gebruik Debian 12 of nieuwer, of installeer de agent handmatig." ;;
        *) die "Besturingssysteem '${PRETTY_NAME:-${id}}' wordt niet ondersteund." "Alleen Ubuntu 22.04+ en Debian 12+; gebruik anders de handmatige installatie of de Bash-agent." ;;
    esac

    ARCH="$(dpkg --print-architecture 2>/dev/null || true)"
    case "${ARCH}" in
        amd64 | arm64) ;;
        *) die "Processorarchitectuur '${ARCH:-onbekend}' wordt niet ondersteund." "Alleen amd64 en arm64." ;;
    esac

    [ -d /run/systemd/system ] || die "systemd draait niet op dit systeem." \
        "De agent draait als systemd-service. Gebruik in containers zonder systemd de Bash-agent (zie de documentatie)."

    OS_NAME="$(printf '%s' "${PRETTY_NAME:-${id} ${version}}" | tr -cd 'A-Za-z0-9 ._()/+-' | cut -c1-100)"
}

install_packages() {
    info "Benodigde pakketten installeren (python3, venv, psutil, requests, dotenv)…"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null || die "apt-get update is mislukt." "Controleer de internetverbinding en de pakketbronnen (apt-get update)."
    apt-get install -y -qq --no-install-recommends \
        python3 python3-venv python3-psutil python3-requests python3-dotenv \
        ca-certificates curl tar coreutils >/dev/null ||
        die "Installeren van de pakketten is mislukt." "Draai 'apt-get install python3-venv python3-psutil python3-requests python3-dotenv' en bekijk de foutmelding."
}

read_env_value() {
    # Value of KEY=value in the agent's .env (no quotes, see write_env).
    local key="$1"
    [ -r "${ENV_FILE}" ] || return 0
    sed -n "s/^${key}=//p" "${ENV_FILE}" | tail -n 1
}

# Writes an Authorization header file, so the agent credential never
# appears on a command line.
auth_header_file() {
    local token="$1" file="${WORK_DIR}/auth-header"
    (umask 077 && printf 'Authorization: Bearer %s\n' "${token}" >"${file}")
    printf '%s' "${file}"
}

# HTTP status of GET /api/agent/self with the credential; the body goes to
# a file.
agent_self() {
    local token="$1" header
    header="$(auth_header_file "${token}")"
    curl_safe -o "${WORK_DIR}/self.json" -w '%{http_code}' -H @"${header}" -H 'Accept: application/json' \
        "${BASE_URL}/api/agent/self" 2>/dev/null || printf '000'
}

json_field() {
    python3 -I -c 'import json, sys
try:
    value = json.load(open(sys.argv[1]))
    for key in sys.argv[2].split("."):
        value = value[key]
    print("" if value is None else value)
except Exception:
    pass' "$1" "$2"
}

download_agent() {
    local name="serverscope-agent-${AGENT_VERSION}.tar.gz"
    info "Agent ${AGENT_VERSION} downloaden en checksum controleren…"
    curl_safe -fo "${WORK_DIR}/${name}" "${BASE_URL}/agent/${name}" ||
        die "Downloaden van de agent is mislukt." "Controleer of deze server ${BASE_URL} via https kan bereiken (firewall, proxy, DNS)."
    curl_safe -fo "${WORK_DIR}/${name}.sha256" "${BASE_URL}/agent/${name}.sha256" ||
        die "Downloaden van de checksum is mislukt." "Controleer de verbinding met ${BASE_URL} en probeer het opnieuw."
    (cd "${WORK_DIR}" && sha256sum --check --status "${name}.sha256") ||
        die "De checksum van het agentpakket klopt niet; de download is beschadigd of gewijzigd." "Probeer het opnieuw. Blijft dit gebeuren, neem dan contact op met de beheerder van ServerScope."

    mkdir -p "${WORK_DIR}/agent"
    tar -xzf "${WORK_DIR}/${name}" -C "${WORK_DIR}/agent" --no-same-owner --no-same-permissions
    if [ ! -f "${WORK_DIR}/agent/serverscope_agent/agent.py" ] || [ ! -f "${WORK_DIR}/agent/${UNIT_NAME}" ]; then
        die "Het agentpakket is onvolledig." "Probeer het opnieuw."
    fi
}

install_files() {
    info "Agent installeren in ${INSTALL_DIR}…"
    mkdir -p "${INSTALL_DIR}"
    # The package directory is replaced as a whole; .env and .venv stay.
    rm -rf -- "${INSTALL_DIR}/serverscope_agent.new"
    cp -R "${WORK_DIR}/agent/serverscope_agent" "${INSTALL_DIR}/serverscope_agent.new"
    rm -rf -- "${INSTALL_DIR}/serverscope_agent"
    mv "${INSTALL_DIR}/serverscope_agent.new" "${INSTALL_DIR}/serverscope_agent"
    install -m 0644 "${WORK_DIR}/agent/requirements.txt" "${INSTALL_DIR}/requirements.txt"

    if [ ! -x "${INSTALL_DIR}/.venv/bin/python" ]; then
        python3 -m venv --system-site-packages "${INSTALL_DIR}/.venv" ||
            die "Aanmaken van de Python-omgeving is mislukt." "Controleer of python3-venv geïnstalleerd is."
    fi
    "${INSTALL_DIR}/.venv/bin/python" -I -c 'import psutil, requests, dotenv' ||
        die "De agent kan zijn Python-pakketten niet laden." "Installeer python3-psutil, python3-requests en python3-dotenv opnieuw."

    chown -R root:"${AGENT_USER}" "${INSTALL_DIR}"
    chmod -R u=rwX,g=rX,o=rX "${INSTALL_DIR}"
    if [ -f "${ENV_FILE}" ]; then
        chmod 640 "${ENV_FILE}"
    fi

    # The unit from the package, unchanged: the agent reads its own .env.
    install -m 0644 "${WORK_DIR}/agent/${UNIT_NAME}" "${UNIT_FILE}"
}

read_enroll_token() {
    ENROLL_TOKEN="${SERVERSCOPE_ENROLL_TOKEN:-}"
    unset SERVERSCOPE_ENROLL_TOKEN

    if [ -z "${ENROLL_TOKEN}" ]; then
        [ -r /dev/tty ] || die "Geen koppeltoken: er is geen terminal om erom te vragen." \
            "Zet het token in de omgevingsvariabele SERVERSCOPE_ENROLL_TOKEN (niet in een getypt commando)."
        printf 'Plak het koppeltoken uit ServerScope (de invoer blijft verborgen): ' >/dev/tty
        IFS= read -rs ENROLL_TOKEN </dev/tty || true
        printf '\n' >/dev/tty
    fi

    ENROLL_TOKEN="$(printf '%s' "${ENROLL_TOKEN}" | tr -d '[:space:]')"
    [[ "${ENROLL_TOKEN}" =~ ^sse_[a-f0-9]{48}$ ]] ||
        die "Dit is geen geldig koppeltoken." "Kopieer het token opnieuw uit ServerScope (Servers → Server toevoegen); het begint met 'sse_'."
}

enroll() {
    local host status response="${WORK_DIR}/enroll.json"
    host="$(hostname -f 2>/dev/null || true)"
    if [ -z "${host}" ] || [ "${host}" = "localhost" ]; then
        host="$(hostname)"
    fi

    info "Server ${host} koppelen aan ServerScope…"
    # The token goes through stdin into the JSON body: never on a command line.
    status="$(printf '%s' "${ENROLL_TOKEN}" | python3 -I -c 'import json, sys
print(json.dumps({"token": sys.stdin.read(), "hostname": sys.argv[1], "os": sys.argv[2], "arch": sys.argv[3], "agent_version": sys.argv[4]}))' \
        "${host}" "${OS_NAME}" "${ARCH}" "${AGENT_VERSION}" |
        curl_safe -o "${response}" -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' \
            --data-binary @- "${BASE_URL}/api/agent/enroll" 2>/dev/null || printf '000')"
    ENROLL_TOKEN=''

    case "${status}" in
        201) ;;
        401) die "Het koppeltoken is ongeldig, verlopen, al gebruikt of ingetrokken." "Maak in ServerScope een nieuw koppeltoken aan (Servers → Server toevoegen) en draai het script opnieuw." ;;
        422) die "ServerScope weigerde de gegevens van deze server: $(json_field "${response}" message)" "Controleer de hostname van deze server (hostname -f)." ;;
        429) die "Te veel koppelpogingen." "Wacht een minuut en probeer het opnieuw." ;;
        000) die "ServerScope (${BASE_URL}) is niet bereikbaar." "Controleer DNS, firewall en proxy: deze server moet ${BASE_URL} via https kunnen bereiken." ;;
        *) die "Koppelen is mislukt (HTTP ${status})." "Probeer het opnieuw; blijft het mislukken, neem dan contact op met de beheerder van ServerScope." ;;
    esac

    AGENT_TOKEN="$(json_field "${response}" data.agent_token)"
    SERVER_NAME="$(json_field "${response}" data.server_name)"
    rm -f -- "${response}"
    [[ "${AGENT_TOKEN}" =~ ^[a-f0-9]{64}$ ]] || die "ServerScope gaf een onverwacht antwoord." "Probeer het opnieuw."
}

write_env() {
    # KEY=value without quotes or spaces: read identically by python-dotenv
    # (Python agent) and by the shell (Bash agent).
    local services interval tmp
    services="$(read_env_value SERVERSCOPE_SERVICES)"
    interval="$(read_env_value SERVERSCOPE_INTERVAL)"
    [[ "${interval}" =~ ^[0-9]+$ ]] || interval=60

    tmp="$(mktemp "${INSTALL_DIR}/.env.XXXXXX")"
    chmod 600 "${tmp}"
    {
        printf 'SERVERSCOPE_URL=%s\n' "${BASE_URL}"
        printf 'SERVERSCOPE_TOKEN=%s\n' "${AGENT_TOKEN}"
        printf 'SERVERSCOPE_INTERVAL=%s\n' "${interval}"
        if [ -n "${services}" ]; then
            printf 'SERVERSCOPE_SERVICES=%s\n' "${services}"
        fi
    } >"${tmp}"
    chown root:"${AGENT_USER}" "${tmp}"
    chmod 640 "${tmp}"
    mv -f "${tmp}" "${ENV_FILE}"
}

write_marker() {
    local created_user="$1"
    {
        printf 'created_user=%s\n' "${created_user}"
        printf 'installed_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf 'agent_version=%s\n' "${AGENT_VERSION}"
    } >"${MARKER}"
    chmod 644 "${MARKER}"
}

ensure_user() {
    if id -u "${AGENT_USER}" >/dev/null 2>&1; then
        CREATED_USER="$(sed -n 's/^created_user=//p' "${MARKER}" 2>/dev/null || true)"
        CREATED_USER="${CREATED_USER:-0}"
    else
        info "Systeemgebruiker ${AGENT_USER} aanmaken (zonder shell)…"
        useradd --system --home-dir "${INSTALL_DIR}" --no-create-home --shell /usr/sbin/nologin "${AGENT_USER}"
        CREATED_USER=1
    fi
}

start_service() {
    systemctl daemon-reload
    systemctl enable "${UNIT_NAME}" >/dev/null 2>&1 || die "De service kon niet worden ingeschakeld." "Bekijk: systemctl status ${UNIT_NAME}"
    systemctl restart "${UNIT_NAME}" || die "De service kon niet worden gestart." "Bekijk: journalctl -u serverscope-agent -n 50"
}

wait_for_first_sample() {
    local token status seen i
    token="$(read_env_value SERVERSCOPE_TOKEN)"
    info "Wachten op de eerste meting (maximaal 90 seconden)…"

    for i in $(seq 1 18); do
        status="$(agent_self "${token}")"
        if [ "${status}" = "200" ]; then
            seen="$(json_field "${WORK_DIR}/self.json" data.last_seen_at)"
            if [ -n "${seen}" ]; then
                printf '\nGelukt: %s is gekoppeld en stuurt metingen naar ServerScope.\n' "$(json_field "${WORK_DIR}/self.json" data.server_name)"
                printf 'De server staat nu als online in het dashboard: %s/servers/%s\n' "${BASE_URL}" "$(json_field "${WORK_DIR}/self.json" data.server_id)"
                return 0
            fi
        fi
        if [ "${i}" -lt 18 ]; then
            sleep 5
        fi
    done

    printf '\n' >&2
    if ! systemctl is-active --quiet "${UNIT_NAME}"; then
        die "De service serverscope-agent draait niet." "Bekijk de oorzaak met: journalctl -u serverscope-agent -n 50"
    fi
    if [ "${status}" = "000" ]; then
        die "ServerScope (${BASE_URL}) is niet bereikbaar vanaf deze server." "Controleer DNS, firewall en proxy. Daarna: systemctl restart serverscope-agent"
    fi
    die "De agent draait, maar ServerScope heeft binnen 90 seconden nog geen meting ontvangen." \
        "Controleer de klok (timedatectl; zet NTP aan met: timedatectl set-ntp true) en de logboeken: journalctl -u serverscope-agent -n 50. Het script opnieuw draaien is veilig."
}

do_install() {
    check_url
    check_system

    if [ -d "${INSTALL_DIR}" ] && [ ! -f "${MARKER}" ] && { [ -f "${ENV_FILE}" ] || [ -d "${INSTALL_DIR}/serverscope_agent" ]; }; then
        die "Er staat al een handmatig geïnstalleerde agent in ${INSTALL_DIR}; dit script laat die ongemoeid." \
            "Werk die installatie bij volgens de handmatige instructie, of verwijder hem eerst (systemctl disable --now serverscope-agent; rm -rf ${INSTALL_DIR})."
    fi

    WORK_DIR="$(mktemp -d)"
    chmod 700 "${WORK_DIR}"

    # Already installed by this script with a working credential: update
    # the files only, never enroll a second server.
    local existing="" status=""
    if [ -f "${MARKER}" ]; then
        existing="$(read_env_value SERVERSCOPE_TOKEN)"
    fi
    if [ -n "${existing}" ]; then
        status="$(agent_self "${existing}")"
        case "${status}" in
            200)
                info "Deze server is al gekoppeld als $(json_field "${WORK_DIR}/self.json" data.server_name); de agent wordt bijgewerkt."
                ;;
            401)
                warn "De bestaande agentcredential is ingetrokken. Er wordt een NIEUWE server aangemaakt; de oude kun je in het dashboard verwijderen."
                existing=""
                ;;
            *)
                die "Kan niet controleren of deze server al gekoppeld is (HTTP ${status})." \
                    "Controleer of ${BASE_URL} bereikbaar is en draai het script opnieuw. Er is niets gewijzigd."
                ;;
        esac
    fi

    if [ -z "${existing}" ]; then
        read_enroll_token
    fi

    install_packages
    ensure_user
    download_agent
    install_files

    if [ -z "${existing}" ]; then
        enroll
        write_env
        AGENT_TOKEN=''
        info "Gekoppeld als ${SERVER_NAME}."
    fi

    write_marker "${CREATED_USER}"
    start_service
    wait_for_first_sample
}

do_uninstall() {
    check_url
    [ "$(id -u)" -eq 0 ] || die "Dit script moet als root draaien." "Start het met: sudo bash $0 --uninstall"
    [ -f "${MARKER}" ] || die "Geen installatie van dit script gevonden in ${INSTALL_DIR}." \
        "Een handmatige installatie verwijder je volgens de handmatige instructie."

    WORK_DIR="$(mktemp -d)"
    chmod 700 "${WORK_DIR}"

    local token header status created_user
    token="$(read_env_value SERVERSCOPE_TOKEN)"
    if [ -n "${token}" ]; then
        info "Agentcredential intrekken in ServerScope…"
        header="$(auth_header_file "${token}")"
        status="$(curl_safe -o /dev/null -w '%{http_code}' -X POST -H @"${header}" -H 'Accept: application/json' \
            "${BASE_URL}/api/agent/revoke" 2>/dev/null || printf '000')"
        case "${status}" in
            200 | 401) ;;
            *) warn "Intrekken via ServerScope is niet gelukt (HTTP ${status}). Trek de credential in op de serverpagina in ServerScope." ;;
        esac
    fi

    created_user="$(sed -n 's/^created_user=//p' "${MARKER}")"

    info "Service stoppen en verwijderen…"
    systemctl disable --now "${UNIT_NAME}" >/dev/null 2>&1 || true
    rm -f -- "${UNIT_FILE}"
    systemctl daemon-reload || true

    rm -rf -- "${INSTALL_DIR}"

    if [ "${created_user}" = "1" ] && id -u "${AGENT_USER}" >/dev/null 2>&1; then
        userdel "${AGENT_USER}" || warn "Gebruiker ${AGENT_USER} kon niet worden verwijderd."
    fi

    printf '\nDe agent is verwijderd. De server en zijn geschiedenis blijven in ServerScope staan; verwijder hem daar als je hem niet meer nodig hebt.\n'
    printf 'Pakketten zoals python3-psutil zijn niet verwijderd; andere software kan ze gebruiken.\n'
}

case "${1:-}" in
    "") do_install ;;
    --uninstall) do_uninstall ;;
    -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//' ;;
    *) die "Onbekende optie: $1" "Gebruik zonder optie om te installeren, of --uninstall om te verwijderen." ;;
esac
