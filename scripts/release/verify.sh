#!/usr/bin/env bash
# Checks a release bundle against a public key, like the servers do.
#
#   scripts/release/verify.sh <bundle.tar> <release.pub>
set -euo pipefail
# shellcheck source=scripts/release/common.sh
. "$(cd "$(dirname "$0")" && pwd)/common.sh"

BUNDLE="${1:-}" PUB="${2:-}"
{ [ -f "${BUNDLE}" ] && [ -f "${PUB}" ]; } || die "Gebruik: verify.sh <bundle.tar> <release.pub>"
find_openssl
WORK="$(mktemp -d)"
trap 'rm -rf -- "${WORK}"' EXIT
tar -xf "${BUNDLE}" -C "${WORK}" manifest manifest.sig || die "De bundel bevat geen manifest en handtekening."
"${OPENSSL}" base64 -d -A -in "${WORK}/manifest.sig" -out "${WORK}/manifest.sig.bin" 2>/dev/null || die "De handtekening is geen base64."
"${OPENSSL}" pkeyutl -verify -pubin -inkey "${PUB}" -rawin -in "${WORK}/manifest" -sigfile "${WORK}/manifest.sig.bin" >/dev/null ||
    die "ONGELDIGE handtekening."
NAME="$(sed -n 's/^filename=//p' "${WORK}/manifest")"
[[ "${NAME}" =~ ^serverscope-agent-[0-9.]+\.tar\.gz$ ]] || die "Ongeldige bestandsnaam in het manifest."
tar -xf "${BUNDLE}" -C "${WORK}" "${NAME}" || die "De tarball ontbreekt in de bundel."
[ "$(sha256_of "${WORK}/${NAME}")" = "$(sed -n 's/^sha256=//p' "${WORK}/manifest")" ] || die "De tarball klopt NIET met het manifest."
printf 'Geldig: handtekening en tarball kloppen.\n'
cat "${WORK}/manifest"
