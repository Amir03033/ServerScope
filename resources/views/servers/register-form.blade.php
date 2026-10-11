<section class="panel">
    <h2>Server registreren</h2>
    <p class="muted small">
        Voeg een Ubuntu-server of een andere Linux-server toe aan ServerScope.
    </p>

    <form id="register-server-form">
        <div class="registration-grid">
            <label>
                Servernaam *
                <input name="name" maxlength="100" required
                       placeholder="Production VPS">
            </label>

            <label>
                Hostname *
                <input name="hostname" maxlength="255" required
                       placeholder="server.example.com">
            </label>

            <label>
                IP-adres
                <input name="ip_address" maxlength="45"
                       placeholder="203.0.113.10">
            </label>

            <label>
                Besturingssysteem
                <input name="operating_system" maxlength="100"
                       placeholder="Ubuntu 24.04">
            </label>

            <label class="full-width">
                Naam van de agentcredential
                <input name="credential_name" maxlength="100"
                       value="Primary agent" required>
            </label>
        </div>

        <button class="button" id="register-server-button" type="submit">
            Server registreren
        </button>

        <p id="registration-error" class="registration-error"
           role="alert" hidden></p>
    </form>

    <div id="registration-success" hidden>
        <h3>Server geregistreerd</h3>
        <p id="registered-server-details" class="muted small"></p>

        <p>
            <strong>Agenttoken — bewaar dit nu veilig.</strong>
        </p>
        <p class="muted small">
            Dit token wordt alleen bij registratie getoond. Als je het
            kwijtraakt, moet je een nieuwe credential aanmaken.
        </p>

        <div class="token-row">
            <input id="registered-agent-token" type="password"
                   readonly autocomplete="off"
                   aria-label="Eenmalig agenttoken">

            <button class="button" id="toggle-token" type="button">
                Tonen
            </button>

            <button class="button" id="copy-token" type="button">
                Kopiëren
            </button>
        </div>

        <p id="copy-status" class="muted small" role="status"></p>

        <button class="button" id="finish-registration" type="button">
            Token veilig opgeslagen — terug naar overzicht
        </button>
    </div>
</section>

<style>
    .registration-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin: 20px 0;
    }

    .registration-grid label {
        display: flex;
        flex-direction: column;
        gap: 7px;
        color: #cbd5e1;
        font-size: 13px;
    }

    .registration-grid input,
    .token-row input {
        width: 100%;
        min-width: 0;
        padding: 11px 12px;
        border: 1px solid #334155;
        border-radius: 8px;
        background: #080d18;
        color: #e5e7eb;
        font: inherit;
    }

    .full-width { grid-column: 1 / -1; }

    .registration-error {
        color: #fca5a5;
        white-space: pre-line;
    }

    .token-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 18px;
    }

    .token-row input { flex: 1 1 240px; }

    @media (max-width: 600px) {
        .registration-grid { grid-template-columns: 1fr; }
        .full-width { grid-column: auto; }
    }
</style>

<script>
    (() => {
        const form = document.getElementById('register-server-form');
        if (!form) return;

        const errorBox = document.getElementById('registration-error');
        const successBox = document.getElementById('registration-success');
        const submitButton = document.getElementById('register-server-button');
        const tokenInput = document.getElementById('registered-agent-token');
        const copyStatus = document.getElementById('copy-status');

        let registeredServer = null;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            errorBox.hidden = true;
            errorBox.textContent = '';
            submitButton.disabled = true;
            submitButton.textContent = 'Registreren...';

            const data = Object.fromEntries(new FormData(form).entries());

            for (const key of ['ip_address', 'operating_system']) {
                if (!data[key]) delete data[key];
            }

            try {
                const response = await fetch(@json(route('servers.register')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector(
                            'meta[name="csrf-token"]'
                        )?.content ?? '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(data),
                });

                const result = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const validationMessages = Object.values(result.errors ?? {})
                        .flat()
                        .join('\n');

                    throw new Error(
                        validationMessages ||
                        result.message ||
                        `Registratie mislukt (HTTP ${response.status}).`
                    );
                }

                registeredServer = result.server;
                tokenInput.value = result.agent_token;
                tokenInput.type = 'password';

                document.getElementById('registered-server-details').textContent =
                    `${result.server.name} · ${result.server.hostname} · Pending`;

                successBox.hidden = false;
                form.hidden = true;
                copyStatus.textContent = '';
            } catch (error) {
                errorBox.textContent =
                    error.message || 'Registreren is mislukt.';
                errorBox.hidden = false;
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Server registreren';
            }
        });

        document.getElementById('toggle-token').addEventListener('click', (event) => {
            const showing = tokenInput.type === 'text';
            tokenInput.type = showing ? 'password' : 'text';
            event.currentTarget.textContent = showing ? 'Tonen' : 'Verbergen';
        });

        document.getElementById('copy-token').addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(tokenInput.value);
                copyStatus.textContent = 'Token gekopieerd.';
            } catch {
                tokenInput.type = 'text';
                tokenInput.select();
                copyStatus.textContent =
                    'Automatisch kopiëren lukte niet. Selecteer en kopieer het token handmatig.';
            }
        });

        document.getElementById('finish-registration').addEventListener('click', () => {
            if (!registeredServer) return;

            // Verwijder het token uit de huidige pagina voordat we herladen.
            tokenInput.value = '';
            window.location.reload();
        });
    })();
</script>
