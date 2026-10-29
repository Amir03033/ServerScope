#!/usr/bin/env bash
# ServerScope agent installer for Ubuntu 22.04+ and Debian 12+ (amd64, arm64).
#
# Gebruik:
#   sudo bash serverscope-install.sh                    installeren en koppelen
#   sudo bash serverscope-install.sh --updates=on|off   idem, zonder de vraag over updates
#   sudo bash serverscope-install.sh --disable-updates  noodknop: updates lokaal uitzetten
#   sudo bash serverscope-install.sh --enable-updates   updates weer aanzetten
#   sudo bash serverscope-install.sh --uninstall        verwijderen
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
#   - downloadt de nieuwste ondertekende release en controleert de
#     handtekening met de publieke sleutel hieronder (RELEASE_PUBLIC_KEY),
#     daarna de SHA-256 van het pakket;
#   - installeert de agent in /opt/serverscope-agent/releases/<versie>
#     (venv met --system-site-packages) en legt de publieke sleutel vast in
#     /etc/serverscope/release.pub;
#   - installeert, als je dat toestaat, het updateprogramma
#     serverscope-updater (root, systemd-timer) dat alleen releases
#     installeert die met deze sleutel zijn ondertekend;
#   - koppelt de server aan je team en schrijft /opt/serverscope-agent/.env
#     (root:serverscope, 640);
#   - installeert en start de systemd-service serverscope-agent.
# De agent leest alleen systeemgegevens en voert geen opdrachten van de
# server uit. Let op: bij een EERSTE installatie komt de publieke sleutel
# zelf uit dit script, dus van de server. Vergelijk de vingerafdruk die
# het script toont met die in de documentatie. Bestaande installaties
# houden hun eigen, eerder vastgelegde sleutel.

set -euo pipefail
umask 022

readonly SERVERSCOPE_URL_DEFAULT='__SERVERSCOPE_URL__'
readonly AGENT_VERSION='__AGENT_VERSION__'
readonly ALLOW_INSECURE='__ALLOW_INSECURE__'
# The public key of the offline release key. Every release is checked
# against it, and it is stored in /etc/serverscope/release.pub for the
# updater. Compare the fingerprint with the one in the documentation.
readonly RELEASE_PUBLIC_KEY='__RELEASE_PUBLIC_KEY__'
readonly RELEASE_KEY_FINGERPRINT='__RELEASE_KEY_FINGERPRINT__'

readonly INSTALL_DIR=/opt/serverscope-agent
readonly ENV_FILE="${INSTALL_DIR}/.env"
readonly MARKER="${INSTALL_DIR}/.installed-by-serverscope-script"
readonly UNIT_NAME=serverscope-agent.service
readonly UNIT_FILE="/etc/systemd/system/${UNIT_NAME}"
readonly AGENT_USER=serverscope
readonly BASE_URL="${SERVERSCOPE_URL_DEFAULT%/}"
readonly RELEASES_DIR="${INSTALL_DIR}/releases"
readonly CURRENT_LINK="${INSTALL_DIR}/serverscope_agent"
readonly ETC_DIR=/etc/serverscope
readonly KEY_FILE="${ETC_DIR}/release.pub"
readonly DISABLE_FILE="${ETC_DIR}/updates-disabled"
readonly UPDATER_BIN=/usr/local/sbin/serverscope-updater
readonly UPDATER_SERVICE=/etc/systemd/system/serverscope-updater.service
readonly UPDATER_TIMER_NAME=serverscope-updater.timer
readonly UPDATER_TIMER=/etc/systemd/system/${UPDATER_TIMER_NAME}
readonly UPDATER_STATE_DIR=/var/lib/serverscope-updater
readonly VERSION_RE='^(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})$'

# --updates=on|off or SERVERSCOPE_UPDATES; empty = ask.
UPDATES_CHOICE="${SERVERSCOPE_UPDATES:-}"
REPLACE_KEY=0

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
        ca-certificates curl tar coreutils openssl >/dev/null ||
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

# True when version $1 is strictly higher than version $2.
version_gt() {
    local a b i
    IFS=. read -r -a a <<<"$1"
    IFS=. read -r -a b <<<"$2"
    for i in 0 1 2; do
        if [ "$((10#${a[i]}))" -gt "$((10#${b[i]}))" ]; then return 0; fi
        if [ "$((10#${a[i]}))" -lt "$((10#${b[i]}))" ]; then return 1; fi
    done
    return 1
}

# Version the agent runs now: the release the symlink points to (0.3.0+),
# or the AGENT_VERSION of a 0.2.0-style directory.
current_version() {
    local target version
    if [ -L "${CURRENT_LINK}" ]; then
        target="$(readlink "${CURRENT_LINK}")"
        version="${target#releases/}"
        version="${version%/serverscope_agent}"
    elif [ -f "${CURRENT_LINK}/collector.py" ]; then
        version="$(sed -n 's/^AGENT_VERSION = "\(.*\)"$/\1/p' "${CURRENT_LINK}/collector.py" | head -n 1)"
    fi
    if [[ "${version:-}" =~ ${VERSION_RE} ]]; then printf '%s' "${version}"; fi
}

# Before anything on this server is changed: a key that was stored
# earlier is never replaced silently.
check_release_key() {
    if [ -f "${KEY_FILE}" ] && [ "$(cat "${KEY_FILE}")" != "${RELEASE_PUBLIC_KEY}" ]; then
        if [ "${REPLACE_KEY}" != "1" ]; then
            die "De releasesleutel in dit script verschilt van de sleutel die eerder op deze server is vastgelegd (${KEY_FILE}). Er is niets gewijzigd." \
                "Dit gebeurt alleen bij een geplande sleutelwissel. Controleer de vingerafdruk in de documentatie van ServerScope en draai het script dan met --replace-release-key. Twijfel je, neem dan contact op met ServerScope."
        fi
        warn "De vastgelegde releasesleutel wordt vervangen (--replace-release-key)."
    fi
}

# Only the expected regular files and their two directories.
check_archive() {
    local tarball="$1" types names line i required
    local -a type_list
    types="$(tar -tvzf "${tarball}" 2>/dev/null | cut -c1)" || return 1
    names="$(tar -tzf "${tarball}" 2>/dev/null)" || return 1
    [ "$(printf '%s\n' "${names}" | wc -l)" -eq "$(printf '%s\n' "${types}" | wc -l)" ] || return 1
    [ "$(printf '%s\n' "${names}" | sort | uniq -d)" = '' ] || return 1
    mapfile -t type_list <<<"${types}"
    i=0
    while IFS= read -r line; do
        case "${type_list[i]}" in
            -) [[ "${line}" =~ ^(requirements\.txt|serverscope-agent\.service|serverscope_agent/[a-z_]{1,40}\.py|updater/serverscope-updater(\.service|\.timer)?)$ ]] || return 1 ;;
            d) [[ "${line}" =~ ^(serverscope_agent|updater)/$ ]] || return 1 ;;
            *) return 1 ;;
        esac
        i=$((i + 1))
    done <<<"${names}"
    for required in requirements.txt serverscope-agent.service serverscope_agent/agent.py serverscope_agent/collector.py \
        updater/serverscope-updater updater/serverscope-updater.service updater/serverscope-updater.timer; do
        printf '%s\n' "${names}" | grep -qxF "${required}" || return 1
    done
}

manifest_value() {
    sed -n "s/^$1=//p" "$2" | head -n 1
}

download_agent() {
    local base="${BASE_URL}/agent/releases/${AGENT_VERSION}" name="serverscope-agent-${AGENT_VERSION}.tar.gz"
    local manifest="${WORK_DIR}/manifest" fingerprint
    info "Agent ${AGENT_VERSION} downloaden en de handtekening controleren…"

    printf '%s\n' "${RELEASE_PUBLIC_KEY}" >"${WORK_DIR}/release.pub"
    fingerprint="$(openssl pkey -pubin -in "${WORK_DIR}/release.pub" -outform DER 2>/dev/null | sha256sum | cut -d' ' -f1)"
    [ "${fingerprint}" = "${RELEASE_KEY_FINGERPRINT}" ] ||
        die "De releasesleutel in dit script is ongeldig." "Download het script opnieuw vanuit ServerScope."
    info "Releasesleutel: vingerafdruk ${fingerprint}"

    { curl_safe -fo "${manifest}" "${base}/manifest" && curl_safe -fo "${WORK_DIR}/manifest.sig.b64" "${base}/manifest.sig"; } ||
        die "Downloaden van de agentrelease is mislukt." "Controleer of deze server ${BASE_URL} via https kan bereiken (firewall, proxy, DNS)."
    { base64 -d <"${WORK_DIR}/manifest.sig.b64" >"${WORK_DIR}/manifest.sig" 2>/dev/null &&
        openssl pkeyutl -verify -pubin -inkey "${WORK_DIR}/release.pub" -rawin -in "${manifest}" -sigfile "${WORK_DIR}/manifest.sig" >/dev/null 2>&1; } ||
        die "De handtekening van de agentrelease klopt niet. Er is niets geïnstalleerd." "Probeer het later opnieuw. Blijft dit gebeuren, neem dan contact op met ServerScope: dit kan op een probleem met de server wijzen."

    { [ "$(manifest_value format "${manifest}")" = serverscope-agent-release-1 ] &&
        [ "$(manifest_value product "${manifest}")" = serverscope-agent ] &&
        [ "$(manifest_value version "${manifest}")" = "${AGENT_VERSION}" ] &&
        [ "$(manifest_value filename "${manifest}")" = "${name}" ] &&
        [ "$(manifest_value key_id "${manifest}")" = "${fingerprint:0:16}" ]; } ||
        die "Het manifest van de agentrelease is ongeldig." "Probeer het opnieuw of neem contact op met ServerScope."

    curl_safe --max-time 300 -fo "${WORK_DIR}/${name}" "${base}/${name}" ||
        die "Downloaden van de agent is mislukt." "Controleer de verbinding met ${BASE_URL} en probeer het opnieuw."
    { [ "$(stat -c %s "${WORK_DIR}/${name}")" = "$(manifest_value size "${manifest}")" ] &&
        [ "$(sha256sum "${WORK_DIR}/${name}" | cut -d' ' -f1)" = "$(manifest_value sha256 "${manifest}")" ]; } ||
        die "Het agentpakket komt niet overeen met de ondertekende checksum; de download is beschadigd of gewijzigd." "Probeer het opnieuw. Blijft dit gebeuren, neem dan contact op met ServerScope."
    check_archive "${WORK_DIR}/${name}" || die "Het agentpakket bevat onverwachte bestanden." "Neem contact op met ServerScope."

    mkdir -p "${WORK_DIR}/agent"
    tar -xzf "${WORK_DIR}/${name}" -C "${WORK_DIR}/agent" --no-same-owner --no-same-permissions
    info "Handtekening en checksum kloppen (release ${AGENT_VERSION}, commit $(manifest_value commit "${manifest}"))."
}

install_files() {
    local current release="${RELEASES_DIR}/${AGENT_VERSION}" staging="${RELEASES_DIR}/.staging-${AGENT_VERSION}"
    info "Agent installeren in ${INSTALL_DIR}…"
    mkdir -p "${RELEASES_DIR}"
    current="$(current_version)"

    if [ -n "${current}" ] && [ -L "${CURRENT_LINK}" ] && version_gt "${current}" "${AGENT_VERSION}"; then
        # Never a downgrade: the updater already installed something newer.
        warn "Op deze server draait al agent ${current} (nieuwer dan ${AGENT_VERSION}); de agent zelf blijft ongewijzigd."
        INSTALLED_VERSION="${current}"
    else
        rm -rf -- "${staging}"
        mkdir -p "${staging}"
        cp -R "${WORK_DIR}/agent/serverscope_agent" "${staging}/serverscope_agent"
        # Agents up to 0.2.0 look for ../.env relative to the resolved package.
        ln -s ../../.env "${staging}/.env"
        rm -rf -- "${release}"
        mv -T "${staging}" "${release}"

        # Atomic switch of the symlink; a 0.2.0 installation has a real
        # directory there, which is replaced by the symlink.
        if [ -d "${CURRENT_LINK}" ] && [ ! -L "${CURRENT_LINK}" ]; then
            rm -rf -- "${INSTALL_DIR}/.serverscope_agent.old"
            mv -T "${CURRENT_LINK}" "${INSTALL_DIR}/.serverscope_agent.old"
        fi
        ln -sfn "releases/${AGENT_VERSION}/serverscope_agent" "${INSTALL_DIR}/.serverscope_agent.new"
        mv -Tf "${INSTALL_DIR}/.serverscope_agent.new" "${CURRENT_LINK}"
        rm -rf -- "${INSTALL_DIR}/.serverscope_agent.old"
        INSTALLED_VERSION="${AGENT_VERSION}"

        # Only the active release stays (and one previous for the updater).
        local dir
        for dir in "${RELEASES_DIR}"/*; do
            [ -d "${dir}" ] || continue
            if [ "${dir##*/}" != "${AGENT_VERSION}" ] && [ "${dir##*/}" != "${current}" ]; then
                rm -rf -- "${dir}"
            fi
        done
    fi
    install -m 0644 "${WORK_DIR}/agent/requirements.txt" "${INSTALL_DIR}/requirements.txt"

    if [ ! -x "${INSTALL_DIR}/.venv/bin/python" ]; then
        python3 -m venv --system-site-packages "${INSTALL_DIR}/.venv" ||
            die "Aanmaken van de Python-omgeving is mislukt." "Controleer of python3-venv geïnstalleerd is."
    fi
    "${INSTALL_DIR}/.venv/bin/python" -I -c 'import psutil, requests, dotenv' ||
        die "De agent kan zijn Python-pakketten niet laden." "Installeer python3-psutil, python3-requests en python3-dotenv opnieuw."

    # Temporary files of an interrupted earlier run may hold a credential.
    find "${INSTALL_DIR}" -maxdepth 1 -type f -name '.env.*' -delete

    chown -R root:"${AGENT_USER}" "${INSTALL_DIR}"
    # Everything readable except the configuration: .env* is never touched
    # by this recursive chmod, so it is not readable for others even for a
    # moment while the script runs again. Symlinks are skipped.
    find "${INSTALL_DIR}" ! -type l ! -name '.env*' -exec chmod u=rwX,g=rX,o=rX {} +
    find "${INSTALL_DIR}" ! -type l -name '.env*' -exec chmod o-rwx {} +
    if [ -f "${ENV_FILE}" ]; then
        chmod 640 "${ENV_FILE}"
    fi

    # The unit from the package, unchanged: the agent reads its own .env.
    install -m 0644 "${WORK_DIR}/agent/${UNIT_NAME}" "${UNIT_FILE}"

    # The public key for the updater: root only, not writable for the
    # agent or for ServerScope.
    install -d -m 0755 -o root -g root "${ETC_DIR}"
    printf '%s\n' "${RELEASE_PUBLIC_KEY}" >"${KEY_FILE}.tmp"
    chown root:root "${KEY_FILE}.tmp"
    chmod 0644 "${KEY_FILE}.tmp"
    mv -f "${KEY_FILE}.tmp" "${KEY_FILE}"
}

# The question (or --updates / SERVERSCOPE_UPDATES); sets UPDATES_ON.
ask_updates() {
    local answer default=J
    case "${UPDATES_CHOICE}" in
        on) UPDATES_ON=1; return 0 ;;
        off) UPDATES_ON=0; return 0 ;;
        "") ;;
        *) die "Ongeldige waarde voor --updates of SERVERSCOPE_UPDATES: ${UPDATES_CHOICE}" "Gebruik on of off." ;;
    esac

    # Switched off earlier on this server: that stays the default.
    if [ -e "${DISABLE_FILE}" ]; then
        default=N
    fi

    if [ ! -r /dev/tty ]; then
        UPDATES_ON=$([ "${default}" = J ] && echo 1 || echo 0)
        info "Geen terminal: automatische updates $([ "${UPDATES_ON}" = 1 ] && echo AAN || echo UIT) (kies met --updates=on of --updates=off)."
        return 0
    fi

    {
        printf '\nAutomatische updates van de agent\n'
        printf 'ServerScope kan deze agent bijwerken wanneer een beheerder van je team\n'
        printf 'daarom vraagt (of als je dat per server aanzet). Er worden alleen\n'
        printf 'releases geïnstalleerd die zijn ondertekend met de releasesleutel van\n'
        printf 'ServerScope; die sleutel wordt nu lokaal vastgelegd. Mislukt een\n'
        printf 'update, dan komt de vorige versie terug. Je kunt dit altijd lokaal\n'
        printf 'uitzetten met: sudo bash serverscope-install.sh --disable-updates\n'
        if [ "${default}" = J ]; then printf 'Updateprogramma installeren? [J/n] '; else printf 'Updates zijn hier eerder uitgezet. Weer aanzetten? [j/N] '; fi
    } >/dev/tty
    IFS= read -r answer </dev/tty || answer=''
    case "${answer:-${default}}" in
        [JjYy]*) UPDATES_ON=1 ;;
        *) UPDATES_ON=0 ;;
    esac
}

# One check-in that tells ServerScope the updater is switched off here.
report_updates_disabled() {
    local token header version
    token="$(read_env_value SERVERSCOPE_TOKEN)"
    version="$(current_version)"
    [ -n "${token}" ] && [ -n "${version}" ] || return 0
    header="$(auth_header_file "${token}")"
    curl_safe -o /dev/null -G -H @"${header}" -H 'Accept: application/json' \
        --data-urlencode "installed=${version}" --data-urlencode 'updater=1' --data-urlencode 'state=disabled' \
        "${BASE_URL}/api/agent/update" 2>/dev/null || true
}

install_updater() {
    install -m 0755 -o root -g root "${WORK_DIR}/agent/updater/serverscope-updater" "${UPDATER_BIN}"
    install -m 0644 -o root -g root "${WORK_DIR}/agent/updater/serverscope-updater.service" "${UPDATER_SERVICE}"
    install -m 0644 -o root -g root "${WORK_DIR}/agent/updater/${UPDATER_TIMER_NAME}" "${UPDATER_TIMER}"
    install -d -m 0700 -o root -g root "${UPDATER_STATE_DIR}"

    # The updater refuses anything not newer than this.
    local highest
    highest="$(head -n 1 "${UPDATER_STATE_DIR}/highest-version" 2>/dev/null || true)"
    if ! [[ "${highest}" =~ ${VERSION_RE} ]] || version_gt "${INSTALLED_VERSION}" "${highest}"; then
        printf '%s\n' "${INSTALLED_VERSION}" >"${UPDATER_STATE_DIR}/highest-version"
    fi
    systemctl daemon-reload
}

enable_updates() {
    rm -f -- "${DISABLE_FILE}"
    systemctl enable --now "${UPDATER_TIMER_NAME}" >/dev/null 2>&1 ||
        die "De updatetimer kon niet worden ingeschakeld." "Bekijk: systemctl status ${UPDATER_TIMER_NAME}"
    info "Automatische updates staan AAN (alleen ondertekende releases). Uitzetten: sudo bash $0 --disable-updates"
}

disable_updates() {
    install -d -m 0755 -o root -g root "${ETC_DIR}"
    printf 'Updates van de ServerScope-agent zijn op deze server uitgeschakeld (%s).\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"${DISABLE_FILE}"
    chmod 0644 "${DISABLE_FILE}"
    systemctl disable --now "${UPDATER_TIMER_NAME}" >/dev/null 2>&1 || true
    report_updates_disabled
    info "Automatische updates staan UIT. Het updateprogramma doet niets meer zolang ${DISABLE_FILE} bestaat. Weer aanzetten: sudo bash $0 --enable-updates"
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
        printf 'agent_version=%s\n' "${INSTALLED_VERSION}"
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

# Before anything on this server is changed: is there a signed release?
check_release_available() {
    if [ -z "${AGENT_VERSION}" ] || [ -z "${RELEASE_PUBLIC_KEY}" ] || [ -z "${RELEASE_KEY_FINGERPRINT}" ]; then
        die "Er is nog geen ondertekende agentrelease beschikbaar op ${BASE_URL}. Er is niets op deze server gewijzigd." \
            "De beheerder van ServerScope moet eerst een release importeren. Probeer het later opnieuw."
    fi
}

do_install() {
    check_url
    check_release_available
    check_system

    if [ -d "${INSTALL_DIR}" ] && [ ! -f "${MARKER}" ] && { [ -f "${ENV_FILE}" ] || [ -d "${INSTALL_DIR}/serverscope_agent" ]; }; then
        die "Er staat al een handmatig geïnstalleerde agent in ${INSTALL_DIR}; dit script laat die ongemoeid." \
            "Werk die installatie bij volgens de handmatige instructie, of verwijder hem eerst (systemctl disable --now serverscope-agent; rm -rf ${INSTALL_DIR})."
    fi

    check_release_key

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
    ask_updates

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
    install_updater
    start_service
    wait_for_first_sample
    if [ "${UPDATES_ON}" = 1 ]; then enable_updates; else disable_updates; fi
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

    info "Service en updateprogramma stoppen en verwijderen…"
    systemctl disable --now "${UPDATER_TIMER_NAME}" >/dev/null 2>&1 || true
    systemctl disable --now "${UNIT_NAME}" >/dev/null 2>&1 || true
    rm -f -- "${UNIT_FILE}" "${UPDATER_SERVICE}" "${UPDATER_TIMER}" "${UPDATER_BIN}"
    rm -rf -- "${ETC_DIR}" "${UPDATER_STATE_DIR}"
    systemctl daemon-reload || true

    rm -rf -- "${INSTALL_DIR}"

    if [ "${created_user}" = "1" ] && id -u "${AGENT_USER}" >/dev/null 2>&1; then
        userdel "${AGENT_USER}" || warn "Gebruiker ${AGENT_USER} kon niet worden verwijderd."
    fi

    printf '\nDe agent is verwijderd. De server en zijn geschiedenis blijven in ServerScope staan; verwijder hem daar als je hem niet meer nodig hebt.\n'
    printf 'Pakketten zoals python3-psutil zijn niet verwijderd; andere software kan ze gebruiken.\n'
}

do_switch_updates() {
    check_url
    [ "$(id -u)" -eq 0 ] || die "Dit script moet als root draaien." "Start het met: sudo bash $0 $1"
    [ -f "${MARKER}" ] || die "Geen installatie van dit script gevonden in ${INSTALL_DIR}." \
        "Updates werken alleen voor installaties van dit script; een handmatige installatie wordt nooit automatisch bijgewerkt."
    WORK_DIR="$(mktemp -d)"
    chmod 700 "${WORK_DIR}"
    if [ "$1" = --disable-updates ]; then
        disable_updates
    else
        { [ -x "${UPDATER_BIN}" ] && [ -f "${KEY_FILE}" ]; } ||
            die "Het updateprogramma is niet geïnstalleerd." "Draai het script zonder opties om het te installeren."
        enable_updates
    fi
}

ACTION=install
for arg in "$@"; do
    case "${arg}" in
        --uninstall) ACTION=uninstall ;;
        --disable-updates | --enable-updates) ACTION="${arg}" ;;
        --updates=on | --updates=off) UPDATES_CHOICE="${arg#--updates=}" ;;
        --replace-release-key) REPLACE_KEY=1 ;;
        -h | --help) ACTION=help ;;
        *) die "Onbekende optie: ${arg}" "Zie: bash $0 --help" ;;
    esac
done

case "${ACTION}" in
    install) do_install ;;
    uninstall) do_uninstall ;;
    --disable-updates | --enable-updates) do_switch_updates "${ACTION}" ;;
    help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
