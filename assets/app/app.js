// Main application JS
document.addEventListener('DOMContentLoaded', () => {
    const setupForm = document.getElementById('setupForm');
    if (setupForm) {
        setupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msgDiv = document.getElementById('setupMsg');
            msgDiv.innerHTML = '<p style="color:blue">Processing setup...</p>';

            try {
                const formData = new FormData(setupForm);
                const data = Object.fromEntries(formData.entries());

                const masterPass = document.getElementById('masterPassword').value;
                const initRes = await E2EEClient.initClient(masterPass);

                // Save E2EE envelope first
                const gqlRes = await fetch('/graphql', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        query: 'mutation($env: JSON!) { e2ee_init(envelope: $env) }',
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
                if (result.success) {
                    const adminUrl = result.adminPath || '/panel-admin';
                    msgDiv.innerHTML = `
                        <div style="margin-top:20px; padding:15px; border:1px solid green; background:#e8f8e8; border-radius:5px;">
                            <h3 style="color:green; margin-top:0;">Setup Complete!</h3>
                            <p>Recovery Key: <b>${initRes.recoveryKey}</b> (Save it safely!)</p>
                            <p><b>Admin Panel URL:</b> <a href="${adminUrl}" id="adminPanelLink">${window.location.origin}${adminUrl}</a></p>
                            <p style="font-size:0.9em; color:#555;">Save this secret URL! It will not be shown again.</p>
                        </div>`;
                } else {
                    msgDiv.innerHTML = `<p style="color:red">Setup failed: ${result.error}</p>`;
                }
            } catch (err) {
                msgDiv.innerHTML = `<p style="color:red">Setup error: ${err.message}</p>`;
            }
        });
    }

    const adminApp = document.getElementById('adminApp');
    if (adminApp) {
        initAdminDashboard(adminApp);
    }
});

async function gqlRequest(query, variables = {}) {
    const res = await fetch('/graphql', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query, variables })
    });
    return await res.json();
}

async function initAdminDashboard(container) {
    container.innerHTML = '<h3>Loading Admin Dashboard...</h3>';

    try {
        const pluginsRes = await gqlRequest('query { admin_plugins { id name version description author enabled isSystem } }');
        if (pluginsRes.errors) {
            container.innerHTML = `<p style="color:red">Access Error: ${pluginsRes.errors[0].message}</p>`;
            return;
        }

        const plugins = pluginsRes.data.admin_plugins || [];
        renderPluginsList(container, plugins);
    } catch (e) {
        container.innerHTML = `<p style="color:red">Error: ${e.message}</p>`;
    }
}

function renderPluginsList(container, plugins) {
    let html = `
        <div style="margin-top:20px;">
            <h3>Plugins Management</h3>
            <p style="color:#666; font-size:0.95em;">Manage installed plugins. Core system plugins cannot be disabled.</p>
            <div id="pluginsContainer">`;

    plugins.forEach(p => {
        const statusBadge = p.enabled
            ? '<span class="badge badge-enabled">Enabled</span>'
            : '<span class="badge badge-disabled">Disabled</span>';
        const systemBadge = p.isSystem
            ? '<span class="badge badge-system">System Core</span>'
            : '';

        const toggleBtn = p.isSystem
            ? ''
            : (p.enabled
                ? `<button class="btn-toggle btn-disable" onclick="togglePlugin('${p.id}', false)">Disable</button>`
                : `<button class="btn-toggle" onclick="togglePlugin('${p.id}', true)">Enable</button>`);

        html += `
            <div class="plugin-card">
                <div class="plugin-info">
                    <h4>${escapeHtml(p.name)} <small style="color:#888;">v${escapeHtml(p.version)}</small> ${statusBadge} ${systemBadge}</h4>
                    <p>${escapeHtml(p.description || 'No description provided.')}</p>
                    <p style="font-size:0.8em; color:#888;">ID: <code>${escapeHtml(p.id)}</code> ${p.author ? '| Author: ' + escapeHtml(p.author) : ''}</p>
                </div>
                <div>${toggleBtn}</div>
            </div>`;
    });

    html += `</div></div>`;
    container.innerHTML = html;
}

window.togglePlugin = async function(id, enable) {
    const container = document.getElementById('adminApp');
    const res = await gqlRequest(
        'mutation($id: String!, $enable: Boolean!) { admin_toggle_plugin(id: $id, enable: $enable) }',
        { id, enable }
    );

    if (res.errors) {
        alert('Failed to update plugin: ' + res.errors[0].message);
    } else {
        initAdminDashboard(container);
    }
};

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
