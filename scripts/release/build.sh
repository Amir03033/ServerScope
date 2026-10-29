#!/usr/bin/env bash
# Builds, signs and bundles an agent release on your Mac.
#
#   scripts/release/build.sh --key <release-key.pem> --pub <release.pub> [--out <map>]
#
# Only from a fixed, tagged commit: the working tree must be clean, HEAD
# must carry the tag agent-v<version> (version from
# agent/serverscope_agent/collector.py) and be equal to origin/main.
# The manifest records the commit, so every release can be traced to
# its code. Output: <map>/serverscope-agent-<version>.bundle.tar, for
#   php artisan serverscope:release:import <bundle> --admin=<e-mail>
set -euo pipefail
umask 022
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/release/common.sh
. "${HERE}/common.sh"

KEY='' PUB='' OUT="${PWD}"
while [ "$#" -gt 0 ]; do
    case "$1" in
        --key) KEY="${2:-}"; shift 2 ;;
        --pub) PUB="${2:-}"; shift 2 ;;
        --out) OUT="${2:-}"; shift 2 ;;
        *) die "Onbekende optie: $1" "Gebruik: build.sh --key <release-key.pem> --pub <release.pub> [--out <map>]" ;;
    esac
done
{ [ -f "${KEY}" ] && [ -f "${PUB}" ]; } || die "Geef --key (privésleutel) en --pub (publieke sleutel) op." "Zie docs/RELEASE_KEYS.md."
[ -d "${OUT}" ] || die "Uitvoermap ${OUT} bestaat niet."

REPO="$(git -C "${HERE}" rev-parse --show-toplevel)"
cd "${REPO}"
find_openssl

# --- The code: a clean, tagged commit that is on origin/main.
[ -z "$(git status --porcelain)" ] || die "De werkmap heeft wijzigingen die niet zijn gecommit." "Commit of verwijder ze (git status)."
VERSION="$(sed -n 's/^AGENT_VERSION = "\([0-9.]*\)"$/\1/p' agent/serverscope_agent/collector.py)"
[[ "${VERSION}" =~ ^(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})$ ]] || die "Geen geldige AGENT_VERSION in collector.py."
TAG="agent-v${VERSION}"
COMMIT="$(git rev-parse HEAD)"
TAGGED="$(git rev-parse -q --verify "refs/tags/${TAG}^{commit}" || true)"
[ "${TAGGED}" = "${COMMIT}" ] || die "HEAD heeft niet de tag ${TAG}." "Maak de tag op deze commit: git tag ${TAG} && git push origin ${TAG}"
info "origin/main ophalen…"
git fetch -q origin main || die "git fetch origin main is mislukt." "Controleer de verbinding met GitHub."
[ "$(git rev-parse origin/main)" = "${COMMIT}" ] || die "HEAD (${COMMIT}) is niet gelijk aan origin/main." "Bouw releases alleen vanaf de nieuwste main: git checkout main && git pull"

# --- The tarball: exactly the files the agent and the updater need.
WORK="$(mktemp -d)"
trap 'rm -rf -- "${WORK}"' EXIT
NAME="serverscope-agent-${VERSION}.tar.gz"
git -c tar.umask=022 archive --format=tar "${COMMIT}:agent" serverscope_agent requirements.txt serverscope-agent.service updater | gzip -n -9 >"${WORK}/${NAME}"

FINGERPRINT="$(key_fingerprint "${PUB}")"
[ -n "${FINGERPRINT}" ] || die "${PUB} is geen geldige publieke sleutel."
{
    printf 'format=serverscope-agent-release-1\n'
    printf 'product=serverscope-agent\n'
    printf 'version=%s\n' "${VERSION}"
    printf 'filename=%s\n' "${NAME}"
    printf 'sha256=%s\n' "$(sha256_of "${WORK}/${NAME}")"
    printf 'size=%s\n' "$(size_of "${WORK}/${NAME}")"
    printf 'commit=%s\n' "${COMMIT}"
    printf 'released_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'min_updater_version=1\n'
    printf 'key_id=%s\n' "${FINGERPRINT:0:16}"
} >"${WORK}/manifest"

info "Manifest ondertekenen (wachtwoordzin van de releasesleutel)…"
set_pass_args
"${OPENSSL}" pkeyutl -sign -inkey "${KEY}" ${PASS_IN[@]+"${PASS_IN[@]}"} -rawin -in "${WORK}/manifest" -out "${WORK}/manifest.sig.bin"
"${OPENSSL}" base64 -A -in "${WORK}/manifest.sig.bin" >"${WORK}/manifest.sig"
printf '\n' >>"${WORK}/manifest.sig"

# Check right away with the public key, exactly like the servers will.
"${OPENSSL}" pkeyutl -verify -pubin -inkey "${PUB}" -rawin -in "${WORK}/manifest" -sigfile "${WORK}/manifest.sig.bin" >/dev/null ||
    die "De handtekening klopt niet met ${PUB}." "Gebruik de publieke sleutel die bij deze privésleutel hoort."

BUNDLE="${OUT}/serverscope-agent-${VERSION}.bundle.tar"
# ustar without macOS metadata: ServerScope accepts exactly three files.
COPYFILE_DISABLE=1 tar --format=ustar -cf "${BUNDLE}" -C "${WORK}" manifest manifest.sig "${NAME}"

printf '\nRelease %s gebouwd en ondertekend.\n' "${VERSION}"
printf '  bundel:     %s\n' "${BUNDLE}"
printf '  commit:     %s (tag %s)\n' "${COMMIT}" "${TAG}"
printf '  sha256:     %s\n' "$(sha256_of "${WORK}/${NAME}")"
printf '  sleutel-ID: %s\n' "${FINGERPRINT:0:16}"
printf '\nImporteren op de server:\n  php artisan serverscope:release:import %s --admin=<e-mailadres platformbeheerder>\n' "$(basename "${BUNDLE}")"
