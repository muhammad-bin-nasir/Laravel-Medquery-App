<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User</title>
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
            --info-bg: #dbeafe;
            --info: #1d4ed8;
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
            max-width: 760px;
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

        p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
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

        input, select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
        }

        .actions {
            margin-top: 14px;
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

        .status.info {
            display: block;
            background: var(--info-bg);
            color: var(--info);
        }

        .hint {
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <main class="wrap">
        <section class="card">
            <h1>Create User</h1>
            <p>This page only works for a currently logged-in admin. New accounts are created with the user role in both Laravel and FastAPI databases.</p>
            <div class="actions">
                <a href="/login"><button class="secondary" type="button">Go to Login</button></a>
                <a href="/chat"><button class="secondary" type="button">Go to Chat</button></a>
            </div>
            <div id="authStatus" class="status info">Checking current admin session...</div>
        </section>

        <section class="card">
            <form id="createUserForm">
                <div class="row">
                    <label for="businessClientId">Business</label>
                    <select id="businessClientId" required disabled>
                        <option value="">Select business</option>
                    </select>
                </div>

                <div class="row">
                    <label for="workspaceId">Workspace</label>
                    <select id="workspaceId" required disabled>
                        <option value="">Select workspace</option>
                    </select>
                </div>

                <div class="row">
                    <label for="name">Name</label>
                    <input id="name" type="text" placeholder="John Doe" required disabled>
                </div>

                <div class="row">
                    <label for="username">Username / Email</label>
                    <input id="username" type="email" placeholder="user@example.com" required disabled>
                </div>

                <div class="row">
                    <label for="password">Password</label>
                    <input id="password" type="password" placeholder="Minimum 6 characters" minlength="6" required disabled>
                </div>

                <div class="actions">
                    <button id="submitBtn" type="submit" disabled>Create User</button>
                </div>
            </form>

            <div id="formStatus" class="status"></div>
            <div class="hint">If no valid admin is logged in, this page redirects to the login screen.</div>
        </section>
    </main>

    <script>
        const authStatus = document.getElementById('authStatus');
        const formStatus = document.getElementById('formStatus');
        const form = document.getElementById('createUserForm');
        const submitBtn = document.getElementById('submitBtn');
        const businessSelect = document.getElementById('businessClientId');
        const workspaceSelect = document.getElementById('workspaceId');
        const nameInput = document.getElementById('name');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');

        function setStatus(el, message, type) {
            el.textContent = message;
            el.className = 'status ' + type;
        }

        function getToken() {
            return (localStorage.getItem('api_token') || '').trim();
        }

        function setFormEnabled(enabled) {
            businessSelect.disabled = !enabled;
            workspaceSelect.disabled = !enabled;
            nameInput.disabled = !enabled;
            usernameInput.disabled = !enabled;
            passwordInput.disabled = !enabled;
            submitBtn.disabled = !enabled;
        }

        async function checkAdminSession() {
            const token = getToken();
            if (!token) {
                setStatus(authStatus, 'No admin is logged in. Redirecting to login...', 'err');
                setFormEnabled(false);
                setTimeout(() => { window.location.href = '/login'; }, 1200);
                return false;
            }

            const response = await fetch('/api/auth/me', {
                headers: {
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.role || !['admin', 'super_admin'].includes(data.role)) {
                localStorage.removeItem('api_token');
                setStatus(authStatus, 'Admin login required. Redirecting to login...', 'err');
                setFormEnabled(false);
                setTimeout(() => { window.location.href = '/login'; }, 1200);
                return false;
            }

            setStatus(authStatus, 'Admin session verified. You can create a new user now.', 'ok');
            setFormEnabled(true);
            return true;
        }

        async function loadBusinesses() {
            const token = getToken();
            const response = await fetch('/api/admin/businesses', {
                headers: {
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
            });

            const data = await response.json().catch(() => []);
            if (!response.ok) {
                throw new Error((data && data.detail) ? data.detail : 'Unable to load businesses');
            }

            businessSelect.innerHTML = '<option value="">Select business</option>';
            (Array.isArray(data) ? data : []).forEach((item) => {
                const option = document.createElement('option');
                option.value = item.business_client_id;
                option.textContent = (item.name || item.business_client_id) + ' (' + item.business_client_id + ')';
                businessSelect.appendChild(option);
            });

            if (businessSelect.options.length > 1) {
                businessSelect.selectedIndex = 1;
                await loadWorkspaces();
            }
        }

        async function loadWorkspaces() {
            const token = getToken();
            const businessId = businessSelect.value;

            workspaceSelect.innerHTML = '<option value="">Select workspace</option>';
            if (!businessId) {
                return;
            }

            const response = await fetch('/api/admin/businesses/' + encodeURIComponent(businessId) + '/workspaces', {
                headers: {
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
            });

            const data = await response.json().catch(() => []);
            if (!response.ok) {
                throw new Error((data && data.detail) ? data.detail : 'Unable to load workspaces');
            }

            (Array.isArray(data) ? data : []).forEach((item) => {
                const option = document.createElement('option');
                option.value = item.workspace_id;
                option.textContent = (item.name || item.workspace_id) + ' (' + item.workspace_id + ')';
                workspaceSelect.appendChild(option);
            });

            if (workspaceSelect.options.length > 1) {
                workspaceSelect.selectedIndex = 1;
            }
        }

        businessSelect.addEventListener('change', async () => {
            try {
                await loadWorkspaces();
            } catch (error) {
                setStatus(formStatus, error.message || 'Unable to load workspaces', 'err');
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            formStatus.className = 'status';
            formStatus.style.display = 'none';

            const token = getToken();
            if (!token) {
                setStatus(formStatus, 'Admin login required.', 'err');
                return;
            }

            try {
                const response = await fetch('/api/admin/auth/create-user', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': 'Bearer ' + token,
                    },
                    body: JSON.stringify({
                        name: nameInput.value.trim(),
                        username: usernameInput.value.trim(),
                        password: passwordInput.value,
                        business_client_id: businessSelect.value,
                        workspace_id: workspaceSelect.value,
                    }),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.detail || 'Unable to create user');
                }

                setStatus(formStatus, 'User created successfully in both Laravel and FastAPI databases.', 'ok');
                nameInput.value = '';
                usernameInput.value = '';
                passwordInput.value = '';
            } catch (error) {
                setStatus(formStatus, error.message || 'User creation failed.', 'err');
            }
        });

        (async function init() {
            try {
                const ok = await checkAdminSession();
                if (!ok) {
                    return;
                }
                await loadBusinesses();
            } catch (error) {
                setStatus(authStatus, error.message || 'Unable to validate admin session.', 'err');
                setFormEnabled(false);
            }
        })();
    </script>
</body>
</html>
