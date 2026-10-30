# Release key: making, storing, signing, emergencies

Every agent release is signed with **one private key that only exists
offline** (on your Mac and its backups). Customer servers install a
release only when its signature checks out against the **public** key
they stored at installation (`/etc/serverscope/release.pub`, root:root
644). ServerScope itself never has the private key; even a fully
compromised ServerScope server cannot make a release the servers accept.

Method: Ed25519 signatures made and checked with OpenSSL 3
(`openssl pkeyutl -rawin`). ServerScope checks them again with PHP sodium.
Why not minisign: it is not available on Ubuntu 22.04.

## 1. One-time: make the key (on your Mac)

```bash
brew install openssl@3
```

```bash
/opt/homebrew/opt/openssl@3/bin/openssl version
```

This must print `OpenSSL 3.x`. The scripts look for that binary themselves
(also `/usr/local/opt/openssl@3/bin/openssl` on Intel Macs) and **stop** with
an explanation when they meet LibreSSL (macOS `/usr/bin/openssl`). To use
another binary: `SERVERSCOPE_OPENSSL=/path/to/openssl`.

Make an encrypted APFS volume or USB stick (Disk Utility → APFS
(Encrypted)), for example `/Volumes/RELEASEKEY`, then from the repository:

```bash
scripts/release/keygen.sh /Volumes/RELEASEKEY
```

You choose a passphrase (asked twice, then once more). The result:

- `release-key.pem`: the private key, encrypted (PKCS#8, scrypt,
  AES-256), mode 600. **Never** copy it into the repository, a server,
  CI, iCloud, Dropbox or e-mail.
- `release.pub`: the public key (not secret).
- the key ID (16 characters) and the fingerprint (SHA-256, 64 characters).

Store the passphrase in your password manager. Make a **second** encrypted
stick with a copy of `release-key.pem` and keep it somewhere else (another
building). Test once that the copy works (`build.sh` with the copy).

## 2. Put the public key into ServerScope

`release.pub` is public. Commit it as
`resources/agent/release-keys/<key-id>.pub` (the script prints the exact
`cp` command), push to a branch and merge it. Or give the file to Claude
and ask for that commit. Then fill in the fingerprint in
`agent/README.md` ("Release key fingerprint") in the same commit.

Check afterwards that what servers get is your key:

```bash
openssl pkey -pubin -in /Volumes/RELEASEKEY/release.pub -outform DER | shasum -a 256
```

must equal the fingerprint that `install.sh` prints ("Releasesleutel:
vingerafdruk …") and the one in `agent/README.md`. On a customer server:

```bash
openssl pkey -pubin -in /etc/serverscope/release.pub -outform DER | sha256sum
```

## 3. Every release

1. Raise `AGENT_VERSION` in `agent/serverscope_agent/collector.py`, merge
   to `main`.
2. On your Mac: `git checkout main && git pull`, then tag that commit:
   `git tag agent-v<version> && git push origin agent-v<version>`.
3. Build and sign (the stick must be mounted):

   ```bash
   scripts/release/build.sh --key /Volumes/RELEASEKEY/release-key.pem --pub /Volumes/RELEASEKEY/release.pub --out ~/Desktop
   ```

   It refuses a dirty working tree, a HEAD without the tag, and a HEAD that
   is not `origin/main`. The manifest records the commit hash.
4. Optional check: `scripts/release/verify.sh ~/Desktop/serverscope-agent-<version>.bundle.tar /Volumes/RELEASEKEY/release.pub`
5. Upload the bundle to the server (scp or the Plesk file manager) and as
   root:

   ```bash
   /opt/plesk/php/8.4/bin/php artisan serverscope:release:import /path/serverscope-agent-<version>.bundle.tar --admin=<your e-mail>
   ```

   ServerScope checks the signature, the manifest and the tarball before
   storing anything. Unmount and store the stick.

Withdraw a bad release: `php artisan serverscope:release:withdraw <version> --admin=<e-mail> --reason="…"`.
It is no longer offered; servers that already run it keep it (there are
no downgrades; publish a fixed, higher version).

## 4. If the private key leaks (someone else may have it)

Treat it as serious: with the key **and** control over ServerScope (or the
network path of a server), someone could install code on customer servers.

1. Stop importing releases. Turn off automatic updates where you can.
2. Make a new key (§1) on a clean machine.
3. Replace the public key in `resources/agent/release-keys/` (remove the
   old file) and deploy. New installations then trust only the new key.
4. Existing servers still trust the old key. Tell customers to run
   `sudo bash serverscope-install.sh --replace-release-key` (after
   checking the new fingerprint in the README), or to put the new
   `release.pub` in `/etc/serverscope/` themselves. Until then they can
   disable updates locally: `sudo bash serverscope-install.sh --disable-updates`.
5. Publish what happened and the new fingerprint.

## 5. If the key is lost (nobody has it)

Nothing is at risk, but no new release can be installed by the updater on
existing servers. Use the backup stick. Without a backup: make a new key
and follow §4 steps 3–5 (servers need the new key once, by hand or via
`install.sh --replace-release-key`).

## 6. Room for key rotation (designed, minimally built)

- ServerScope trusts every `*.pub` in `resources/agent/release-keys/`
  (`SERVERSCOPE_RELEASE_KEY_PATH`), so old and new key can be trusted at
  the same time.
- Every manifest names its `key_id`; the updater tries
  `/etc/serverscope/release.pub` and every `/etc/serverscope/release.d/*.pub`
  and checks that the key that verified is the one the manifest names.
- `min_updater_version` in the manifest lets a future release require a
  newer updater.
- A planned rotation can later work like this (not built yet): a release
  signed with the OLD key carries the NEW public key, and an updater with
  version 2 places it in `/etc/serverscope/release.d/`. After all servers
  have it, releases are signed with the new key only and the old one is
  removed. Nothing in the current design blocks this.
