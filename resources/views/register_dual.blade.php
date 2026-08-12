<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #dbe1ea;
            --primary: #0f766e;
            --primary-hover: #0d5f59;
            --ok-bg: #dcfce7;
            --ok: #14532d;
            --err-bg: #fee2e2;
            --err: #991b1b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 12%, #dbeafe, transparent 28%),
                radial-gradient(circle at 92% 0%, #cffafe, transparent 25%),
                var(--bg);
            min-height: 100vh;
            padding: 24px;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.07);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .grid {
            display: grid;
            gap: 16px;
            grid-template-columns: 1fr 1fr;
        }

        .row {
            margin-top: 12px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        input, textarea, select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
            font-family: Consolas, monospace;
        }

        .actions {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            background: var(--primary);
            color: #fff;
        }

        button:hover { background: var(--primary-hover); }

        .secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .status {
            margin-top: 12px;
            padding: 10px;
            border-radius: 8px;
            display: none;
            font-size: 14px;
        }

        .status.ok {
            display: block;
            background: var(--ok-bg);
            color: var(--ok);
        }

        .status.err {
            display: block;
            background: var(--err-bg);
            color: var(--err);
        }

        .hint {
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        @media (max-width: 860px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="wrap">
        <section class="card">
            <h1>Register</h1>
            <p>Create admins directly. Create users only with an admin token and explicit business/workspace scope.</p>
            <div class="actions">
                <a href="/login"><button class="secondary" type="button">Go to Login</button></a>
                <a href="/chat"><button class="secondary" type="button">Go to Chat</button></a>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <h2>Admin Registration</h2>
                <p>Public registration for admin accounts.</p>

                <form id="adminRegisterForm">
                    <div class="row">
                        <label for="adminEmail">Email</label>
                        <input id="adminEmail" type="email" required placeholder="new-admin@example.com">
                    </div>
                    <div class="row">
                        <label for="adminPassword">Password</label>
                        <input id="adminPassword" type="password" required minlength="6" placeholder="Minimum 6 characters">
                    </div>
                    <div class="actions">
                        <button type="submit">Create Admin</button>
                    </div>
                </form>

                <div id="adminStatus" class="status"></div>
            </article>

            <article class="card">
                <h2>User Registration (Admin Only)</h2>
                <p>Uses the first available admin account and the scoped business/workspace you select.</p>

                <form id="userRegisterForm">
                    <div class="actions">
                        <button id="loadScopesBtn" class="secondary" type="button">Load Businesses and Workspaces</button>
                    </div>

                    <div class="row">
                        <label for="userEmail">User Email</label>
                        <input id="userEmail" type="email" required placeholder="new-user@example.com">
                    </div>

                    <div class="row">
                        <label for="userPassword">User Password</label>
                        <input id="userPassword" type="password" required minlength="6" placeholder="Minimum 6 characters">
                    </div>

                    <div class="row">
                        <label for="businessClientId">Business Client ID</label>
                        <select id="businessClientId" required>
                            <option value="">Select business</option>
                        </select>
                    </div>

                    <div class="row">
                        <label for="workspaceId">Workspace ID</label>
                        <select id="workspaceId" required>
                            <option value="">Select workspace</option>
                        </select>
                    </div>

                    <div class="actions">
                        <button type="submit">Create User</button>
                    </div>
                </form>

                <div id="userStatus" class="status"></div>
            </article>
        </section>
    </main>

    <script>
        const adminForm = document.getElementById('adminRegisterForm');
        const userForm = document.getElementById('userRegisterForm');

        const adminStatus = document.getElementById('adminStatus');
        const userStatus = document.getElementById('userStatus');

        const loadScopesBtn = document.getElementById('loadScopesBtn');
        const businessClientIdEl = document.getElementById('businessClientId');
        const workspaceIdEl = document.getElementById('workspaceId');

        function setStatus(el, message, ok) {
            el.textContent = message;
            el.className = ok ? 'status ok' : 'status err';
        }

        function seedDefaults() {
            const defaultsRaw = localStorage.getItem('api_chat_defaults');
            if (defaultsRaw) {
                try {
                    const defaults = JSON.parse(defaultsRaw) || {};
                    if (defaults.business_client_id) {
                        businessClientIdEl.value = defaults.business_client_id;
                    }
                    if (defaults.workspace_id) {
                        workspaceIdEl.value = defaults.workspace_id;
                    }
                } catch (error) {
                    // Ignore malformed localStorage payload.
                }
            }
        }

        function setBusinessOptions(items, selectedValue = '') {
            businessClientIdEl.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select business';
            businessClientIdEl.appendChild(placeholder);

            items.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.business_client_id;
                option.textContent = item.business_client_id + ' - ' + (item.name || 'Unnamed');
                businessClientIdEl.appendChild(option);
            });

            if (selectedValue) {
                businessClientIdEl.value = selectedValue;
            }
        }

        function setWorkspaceOptions(items, selectedValue = '') {
            workspaceIdEl.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select workspace';
            workspaceIdEl.appendChild(placeholder);

            items.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.workspace_id;
                option.textContent = item.workspace_id + ' - ' + (item.name || 'Unnamed');
                workspaceIdEl.appendChild(option);
            });

            if (selectedValue) {
                workspaceIdEl.value = selectedValue;
            }
        }

        async function loadBusinesses() {
            const response = await fetch('/api/admin/businesses', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                },
            });

            const data = await response.json().catch(() => []);
            if (!response.ok) {
                throw new Error((data && data.detail) ? data.detail : 'Failed to load businesses');
            }

            const businesses = Array.isArray(data) ? data : [];

            let selectedBusiness = '';
            const defaultsRaw = localStorage.getItem('api_chat_defaults');
            if (defaultsRaw) {
                try {
                    selectedBusiness = (JSON.parse(defaultsRaw) || {}).business_client_id || '';
                } catch (error) {
                    selectedBusiness = '';
                }
            }

            setBusinessOptions(businesses, selectedBusiness);
            return businesses;
        }

        async function loadWorkspacesForSelectedBusiness() {
            const businessClientId = businessClientIdEl.value.trim();

            if (!businessClientId) {
                setWorkspaceOptions([]);
                return [];
            }

            const response = await fetch('/api/admin/businesses/' + encodeURIComponent(businessClientId) + '/workspaces', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                },
            });

            const data = await response.json().catch(() => []);
            if (!response.ok) {
                throw new Error((data && data.detail) ? data.detail : 'Failed to load workspaces');
            }

            const workspaces = Array.isArray(data) ? data : [];

            let selectedWorkspace = '';
            const defaultsRaw = localStorage.getItem('api_chat_defaults');
            if (defaultsRaw) {
                try {
                    selectedWorkspace = (JSON.parse(defaultsRaw) || {}).workspace_id || '';
                } catch (error) {
                    selectedWorkspace = '';
                }
            }

            setWorkspaceOptions(workspaces, selectedWorkspace);
            return workspaces;
        }

        adminForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = document.getElementById('adminEmail').value.trim();
            const password = document.getElementById('adminPassword').value;

            try {
                const response = await fetch('/api/admin/auth/create-admin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ email, password }),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.detail || 'Unable to create admin');
                }

                setStatus(adminStatus, 'Admin created successfully.', true);
                adminForm.reset();
            } catch (error) {
                setStatus(adminStatus, error.message || 'Admin registration failed.', false);
            }
        });

        userForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = document.getElementById('userEmail').value.trim();
            const password = document.getElementById('userPassword').value;
            const business_client_id = businessClientIdEl.value.trim();
            const workspace_id = workspaceIdEl.value.trim();

            try {
                const response = await fetch('/api/admin/auth/create-user', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        email,
                        password,
                        business_client_id,
                        workspace_id,
                    }),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.detail || 'Unable to create user');
                }

                localStorage.setItem('api_chat_defaults', JSON.stringify({
                    business_client_id,
                    workspace_id,
                    user_id: email,
                }));

                setStatus(userStatus, 'User created successfully in assigned business/workspace.', true);
                document.getElementById('userEmail').value = '';
                document.getElementById('userPassword').value = '';
            } catch (error) {
                setStatus(userStatus, error.message || 'User registration failed.', false);
            }
        });

        loadScopesBtn.addEventListener('click', async () => {
            try {
                await loadBusinesses();
                await loadWorkspacesForSelectedBusiness();
                setStatus(userStatus, 'Businesses and workspaces loaded.', true);
            } catch (error) {
                setStatus(userStatus, error.message || 'Unable to load businesses/workspaces.', false);
            }
        });

        businessClientIdEl.addEventListener('change', async () => {
            try {
                await loadWorkspacesForSelectedBusiness();
            } catch (error) {
                setStatus(userStatus, error.message || 'Unable to load workspaces.', false);
            }
        });

        seedDefaults();

        loadBusinesses()
            .then(() => loadWorkspacesForSelectedBusiness())
            .catch(() => {
                setStatus(userStatus, 'Could not auto-load options. Click Load Businesses and Workspaces.', false);
            });
    </script>
</body>
</html>
