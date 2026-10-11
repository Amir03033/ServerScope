<section class="panel register-section">
    <div class="register-head">
        <span class="register-icon">
            <svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="18" height="7" rx="2"/>
                <rect x="3" y="14" width="18" height="7" rx="2"/>
                <path d="M7 6.5h.01M7 17.5h.01"/>
            </svg>
        </span>
        <div>
            <h2>Server registreren</h2>
            <p class="muted small">
                Voeg een Ubuntu-server of een andere Linux-server toe aan ServerScope.
            </p>
        </div>
    </div>

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

        <button class="button button-primary" id="register-server-button" type="submit">
            Server registreren
        </button>

        <p id="registration-error" class="registration-error"
           role="alert" hidden></p>
    </form>

    <div id="registration-success" class="token-area" hidden>
        <h3>Server geregistreerd</h3>
        <p id="registered-server-details" class="muted small"></p>

        <p class="token-warning">
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

        <button class="button button-primary" id="finish-registration" type="button">
            Token veilig opgeslagen — terug naar overzicht
        </button>
    </div>
</section>

<style>
    .register-section[hidden],
    .token-area[hidden] { display: none; }

    .register-head {
        display: flex;
        align-items: center;
        gap: 13px;
        margin-bottom: 4px;
    }

    .register-head h2 {
        margin: 0;
        font-size: 17px;
    }

    .register-head p { margin: 3px 0 0; }

    .register-icon {
        display: grid;
        place-items: center;
        flex: 0 0 44px;
        width: 44px;
        height: 44px;
        border: 1px solid #25577a;
        border-radius: 12px;
        background: #0c2236;
        color: #38bdf8;
        font-size: 22px;
    }

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
        color: #c3d1e4;
        font-size: 12px;
        font-weight: 600;
    }

    .registration-grid input,
    .token-row input {
        width: 100%;
        min-width: 0;
        padding: 11px 12px;
        border: 1px solid #2a3c58;
        border-radius: 10px;
        outline: none;
        background: #080f1c;
        color: #edf4ff;
        font: inherit;
        font-weight: 400;
        transition: border-color .15s, box-shadow .15s;
    }

    .registration-grid input:focus,
    .token-row input:focus {
        border-color: #0891b2;
        box-shadow: 0 0 0 3px #0891b230;
    }

    .full-width { grid-column: 1 / -1; }

    .registration-error {
        margin: 12px 0 0;
        padding: 11px 14px;
        border: 1px solid #7f1d1d;
        border-radius: 10px;
        background: #2a121a;
        color: #fca5a5;
        white-space: pre-line;
    }

    .registration-error[hidden] { display: none; }

    .token-area {
        margin-top: 8px;
        padding: 18px;
        border: 1px solid #854d0e;
        border-radius: 12px;
        background: #29200d;
    }

    .token-area h3 {
        margin: 0 0 4px;
        font-size: 16px;
    }

    .token-warning { margin: 14px 0 4px; }

    .token-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 14px 0 12px;
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
