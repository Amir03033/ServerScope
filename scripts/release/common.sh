#!/usr/bin/env bash
# Shared helpers for the release scripts (sourced, not run).
# Works on macOS (Homebrew openssl@3, the stock bash 3.2) and Linux.

info() { printf '==> %s\n' "$*"; }
die() {
    printf 'FOUT: %s\n' "$1" >&2
    if [ "$#" -gt 1 ]; then printf 'Oplossing: %s\n' "$2" >&2; fi
    exit 1
}

# Sets OPENSSL to an OpenSSL 3 binary. macOS ships LibreSSL as
# /usr/bin/openssl, which cannot make Ed25519 signatures: refuse it.
find_openssl() {
    local candidate version
    for candidate in "${SERVERSCOPE_OPENSSL:-}" /opt/homebrew/opt/openssl@3/bin/openssl /usr/local/opt/openssl@3/bin/openssl "$(command -v openssl 2>/dev/null || true)"; do
        { [ -n "${candidate}" ] && [ -x "${candidate}" ]; } || continue
        version="$("${candidate}" version 2>/dev/null || true)"
        case "${version}" in
            "OpenSSL 3."*)
                OPENSSL="${candidate}"
                info "OpenSSL: ${candidate} (${version})"
                return 0
                ;;
            *LibreSSL*)
                die "${candidate} is LibreSSL (${version}); daarmee kunnen geen Ed25519-releases worden ondertekend." \
                    "Installeer OpenSSL 3 met: brew install openssl@3 (het script vindt /opt/homebrew/opt/openssl@3/bin/openssl dan vanzelf), of wijs hem aan met SERVERSCOPE_OPENSSL=/pad/naar/openssl."
                ;;
        esac
    done
    die "Geen OpenSSL 3 gevonden." "Installeer het met: brew install openssl@3"
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

size_of() { wc -c <"$1" | tr -d ' '; }

# The passphrase: from the terminal (default), or from a file for
# automated tests only (SERVERSCOPE_RELEASE_PASSFILE).
# Bash 3.2 (macOS) compatible: sets PASS_IN and PASS_OUT arrays; use
# them as ${PASS_IN[@]+${PASS_IN[@]+"${PASS_IN[@]}"}}.
# shellcheck disable=SC2034 # used by the scripts that source this file
set_pass_args() {
    PASS_IN=()
    PASS_OUT=()
    if [ -n "${SERVERSCOPE_RELEASE_PASSFILE:-}" ]; then
        PASS_IN=(-passin "file:${SERVERSCOPE_RELEASE_PASSFILE}")
        PASS_OUT=(-passout "file:${SERVERSCOPE_RELEASE_PASSFILE}")
    fi
}

key_fingerprint() {
    "${OPENSSL}" pkey -pubin -in "$1" -outform DER 2>/dev/null | { if command -v sha256sum >/dev/null 2>&1; then sha256sum; else shasum -a 256; fi; } | cut -d' ' -f1
}
