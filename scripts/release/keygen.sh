#!/usr/bin/env bash
# Creates the release key pair ONCE, on your own (offline) Mac.
#
#   scripts/release/keygen.sh <map>     e.g. /Volumes/RELEASEKEY
#
# Writes <map>/release-key.pem (private, encrypted with a passphrase,
# mode 600) and <map>/release.pub (public). The private key never leaves
# that map; only release.pub goes into the repository
# (resources/agent/release-keys/<key-id>.pub). See docs/RELEASE_KEYS.md.
set -euo pipefail
umask 077
# shellcheck source=scripts/release/common.sh
. "$(cd "$(dirname "$0")" && pwd)/common.sh"

DIR="${1:-}"
{ [ -n "${DIR}" ] && [ -d "${DIR}" ]; } || die "Geef een bestaande map op." "Bijvoorbeeld: scripts/release/keygen.sh /Volumes/RELEASEKEY"
KEY="${DIR}/release-key.pem"
PUB="${DIR}/release.pub"
{ [ ! -e "${KEY}" ] && [ ! -e "${PUB}" ]; } || die "Er staat al een sleutel in ${DIR}; die wordt nooit overschreven." "Gebruik een lege map, of de bestaande sleutel."

find_openssl
set_pass_args

info "Ed25519-sleutel maken; kies een sterke wachtwoordzin (die vraagt OpenSSL zo twee keer)…"
# The unencrypted key only exists in a pipe, never on disk.
"${OPENSSL}" genpkey -algorithm ed25519 | "${OPENSSL}" pkcs8 -topk8 -v2 aes-256-cbc -scrypt ${PASS_OUT[@]+"${PASS_OUT[@]}"} -out "${KEY}"
chmod 600 "${KEY}"
info "Publieke sleutel afleiden (wachtwoordzin nog één keer)…"
"${OPENSSL}" pkey -in "${KEY}" ${PASS_IN[@]+"${PASS_IN[@]}"} -pubout -out "${PUB}"
chmod 644 "${PUB}"

FINGERPRINT="$(key_fingerprint "${PUB}")"
printf '\nKlaar.\n'
# Non-ASCII text goes in an argument, never in the format string: the
# printf of bash 3.2 (macOS) mangles it there.
printf '  %s\n' "privésleutel: ${KEY} (versleuteld; maak nu een back-up, zie docs/RELEASE_KEYS.md)"
printf '  publieke sleutel: %s\n' "${PUB}"
printf '  sleutel-ID: %s\n' "${FINGERPRINT:0:16}"
printf '  vingerafdruk (SHA-256): %s\n' "${FINGERPRINT}"
printf '\nVoeg alleen de publieke sleutel toe aan de repository:\n  cp %s resources/agent/release-keys/%s.pub\n' "${PUB}" "${FINGERPRINT:0:16}"
