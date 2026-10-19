// Main application JS
document.addEventListener('DOMContentLoaded', () => {
    const setupForm = document.getElementById('setupForm');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(setupForm);
            const data = Object.fromEntries(formData.entries());

            const masterPass = document.getElementById('masterPassword').value;
            const initRes = await E2EEClient.initClient(masterPass);

            // Save E2EE envelope first
            const gqlRes = await fetch('/graphql', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    query: 'mutation($env: E2eeEnvelopeInput!) { e2ee_init(envelope: $env) }',
                    variables: { env: initRes.envelope }
                })
            });

            // Submit setup credentials
            const res = await fetch('/setup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await res.json();
            const msgDiv = document.getElementById('setupMsg');
            if (result.success) {
                msgDiv.innerHTML = `<p style="color:green">Setup complete! Recovery Key: <b>${initRes.recoveryKey}</b>. Save it safely!</p>`;
            } else {
                msgDiv.innerHTML = `<p style="color:red">Setup failed: ${result.error}</p>`;
            }
        });
    }
});
