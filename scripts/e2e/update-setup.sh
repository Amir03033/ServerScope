#!/usr/bin/env bash
# One-time setup for scripts/e2e/update-test.sh (test environments only):
#   - a test CA and a certificate for serverscope.test;
#   - a MySQL/MariaDB database for a local ServerScope;
#   - two key pairs made now: the "release key" and an "attacker key";
#   - signed test releases, built with scripts/release/build.sh from a
#     temporary git repository (tag + origin/main, like the real thing):
#       0.3.0  the current agent code
#       0.3.1  same code, new version number
#       0.3.2  same code, new version number
#       0.3.4  an agent that exits at once (never sends a sample)
#       0.3.3  signed with the attacker key
#   - the phase 2 code (E2E_OLD_REF, default origin/main) to install an
#     agent 0.2.0 the old way;
#   - newtoken.sh, so scripts/e2e/install-test.sh can use the same E2E_DIR.
# Nothing here is a real key; everything lives in $E2E_DIR.
#
# Usage: E2E_DIR=/tmp/e2e scripts/e2e/update-setup.sh
set -euo pipefail

E2E_DIR="${E2E_DIR:?set E2E_DIR}"
E2E_DB="${E2E_DB:-serverscope_e2e}"
OLD_REF="${E2E_OLD_REF:-origin/main}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "${E2E_DIR}"
cd "${E2E_DIR}"

# --- TLS
if [ ! -f ca.crt ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj '/CN=ServerScope e2e CA' -keyout ca.key -out ca.crt 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj '/CN=serverscope.test' -keyout server.key -out server.csr 2>/dev/null
    printf 'subjectAltName=DNS:serverscope.test\n' >san.ext
    openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 30 -extfile san.ext -out server.crt 2>/dev/null
fi

# --- Application environment (environment variables beat .env)
if [ ! -f app.env ]; then
    cat >app.env <<EOF
export APP_ENV=local APP_DEBUG=false APP_URL=https://serverscope.test:8443
export APP_KEY=base64:$(openssl rand -base64 32)
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=${E2E_DB} DB_USERNAME=e2e DB_PASSWORD=e2e
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=single
export SERVERSCOPE_RELEASE_KEY_PATH=${E2E_DIR}/trusted-keys
export PHP_CLI_SERVER_WORKERS=4
EOF
fi
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS ${E2E_DB}; CREATE USER IF NOT EXISTS 'e2e'@'127.0.0.1' IDENTIFIED BY 'e2e'; GRANT ALL ON ${E2E_DB}.* TO 'e2e'@'127.0.0.1'; FLUSH PRIVILEGES;"

# --- The phase 2 application (same dependencies: composer.lock unchanged)
if [ ! -d old ]; then
    mkdir old
    git -C "${REPO}" archive "${OLD_REF}" | tar -x -C old
    # The packages of the repository, but the application classes of the
    # old code: Composer's class map points App\ at the repository, so an
    # autoloader for old/app runs first.
    mkdir -p old/vendor
    cat >old/vendor/autoload.php <<PHP
<?php
\$loader = require '${REPO}/vendor/autoload.php';
spl_autoload_register(function (string \$class): void {
    foreach (['App\\\\' => '${E2E_DIR}/old/app/', 'Database\\\\Factories\\\\' => '${E2E_DIR}/old/database/factories/', 'Database\\\\Seeders\\\\' => '${E2E_DIR}/old/database/seeders/'] as \$prefix => \$dir) {
        if (str_starts_with(\$class, \$prefix) && is_file(\$file = \$dir.str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))).'.php')) {
            require \$file;
            return;
        }
    }
}, true, true);
return \$loader;
PHP
fi

# --- Keys (test only) and releases
export SERVERSCOPE_RELEASE_PASSFILE="${E2E_DIR}/passphrase"
printf 'e2e-only\n' >"${SERVERSCOPE_RELEASE_PASSFILE}"
mkdir -p keys attacker trusted-keys bundles
[ -f keys/release-key.pem ] || bash "${REPO}/scripts/release/keygen.sh" keys >/dev/null
[ -f attacker/release-key.pem ] || bash "${REPO}/scripts/release/keygen.sh" attacker >/dev/null
cp keys/release.pub trusted-keys/release.pub

if [ ! -f bundles/serverscope-agent-0.3.4.bundle.tar ]; then
    export GIT_AUTHOR_NAME=e2e GIT_AUTHOR_EMAIL=e2e@example.test GIT_COMMITTER_NAME=e2e GIT_COMMITTER_EMAIL=e2e@example.test
    rm -rf src origin.git
    git init -q --bare origin.git
    git init -q -b main src
    mkdir -p src/agent src/scripts
    cp -R "${REPO}/agent/serverscope_agent" "${REPO}/agent/updater" "${REPO}/agent/requirements.txt" "${REPO}/agent/serverscope-agent.service" src/agent/
    cp -R "${REPO}/scripts/release" src/scripts/
    rm -rf src/agent/serverscope_agent/__pycache__
    git -C src remote add origin "${E2E_DIR}/origin.git"

    release() {
        local version="$1" key="$2"
        sed -i "s/^AGENT_VERSION = .*/AGENT_VERSION = \"${version}\"/" src/agent/serverscope_agent/collector.py
        git -C src add -A
        git -C src commit -qm "agent ${version}"
        git -C src push -q origin main
        git -C src tag "agent-v${version}"
        (cd src && bash scripts/release/build.sh --key "../${key}/release-key.pem" --pub "../${key}/release.pub" --out ../bundles >/dev/null)
    }

    release 0.3.0 keys
    release 0.3.1 keys
    release 0.3.2 keys
    release 0.3.3 attacker
    # Broken on purpose: exits before the first sample.
    printf 'import sys\nsys.exit("e2e: deze versie start niet")\n' >src/agent/serverscope_agent/agent.py
    release 0.3.4 keys
fi

# --- newtoken.sh for install-test.sh: a fresh enrollment token for the
#     default team (with an administrator made for the test).
cat >newtoken.sh <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/app.env"
cd "${E2E_REPO}"
php artisan tinker --execute='
    $team = App\Models\Team::query()->where("is_default", true)->first();
    $user = App\Models\User::query()->firstOrCreate(["email" => "e2e-admin@example.test"], ["name" => "e2e beheerder", "password" => bcrypt(Illuminate\Support\Str::random(32))]);
    if (! $user->teams()->whereKey($team->id)->exists()) { $user->teams()->attach($team->id, ["role" => "owner"]); }
    $user->forceFill(["current_team_id" => $team->id])->save();
    [, $plain] = App\Tenancy\Tenant::run($team->id, fn () => app(App\Services\EnrollmentService::class)->create($user));
    echo $plain;' 2>/dev/null | tail -n 1
SCRIPT
chmod 755 newtoken.sh
grep -q '^export E2E_REPO=' app.env || printf 'export E2E_REPO=%s\n' "${REPO}" >>app.env

echo "Klaar: ${E2E_DIR}"
