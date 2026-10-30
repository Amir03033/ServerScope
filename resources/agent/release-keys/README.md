# Trusted release keys

Public Ed25519 keys (PEM, `*.pub`) whose signatures ServerScope accepts
for agent releases. Only **public** keys belong here; the private key
stays offline (see `docs/RELEASE_KEYS.md`). The file name is the key ID
(`<key_id>.pub`). install.sh embeds the key from this directory.
