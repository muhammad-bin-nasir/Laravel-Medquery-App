<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        :root {
            --bg: #f2f4f8;
            --panel: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #d1d5db;
            --primary: #0f766e;
            --primary-hover: #115e59;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at top right, #d1fae5 0%, var(--bg) 45%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 20px 36px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 26px;
        }

        p {
            margin: 0 0 18px;
            color: var(--muted);
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }

        button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            padding: 11px 14px;
            font-weight: 700;
            background: var(--primary);
            color: #ffffff;
            cursor: pointer;
        }

        button:hover {
            background: var(--primary-hover);
        }

        #status {
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 8px;
            display: none;
            font-size: 14px;
        }

        #status.error {
            display: block;
            background: var(--error-bg);
            color: var(--error-text);
        }

        .hint {
            margin-top: 10px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Admin Login</h1>
        <p>Sign in with admin credentials. You will be redirected to the chat page.</p>

        <form id="loginForm">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="username" placeholder="admin@example.com">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="********">

            <button type="submit">Sign In</button>
        </form>

        <div id="status"></div>
        <div class="hint">This page calls POST /api/auth/login and stores session token in localStorage.</div>
    </main>

    <script>
        const form = document.getElementById('loginForm');
        const statusEl = document.getElementById('status');

        function extractToken(value) {
            if (typeof value === 'string') {
                return value.trim();
            }

            if (value && typeof value === 'object') {
                if (typeof value.access_token === 'string') {
                    return value.access_token.trim();
                }
                if (typeof value.token === 'string') {
                    return value.token.trim();
                }
            }

            return '';
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            statusEl.className = '';
            statusEl.style.display = 'none';

            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            try {
                const response = await fetch('/api/admin/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password }),
                });

                const data = await response.json().catch(() => ({}));

                const token = extractToken(data.session) || extractToken(data.access_token) || extractToken(data.token);

                if (!response.ok || !token) {
                    const detail = data.detail || 'Login failed';
                    throw new Error(detail);
                }

                localStorage.setItem('api_token', token);
                localStorage.setItem('api_session', JSON.stringify(data.session || null));
                localStorage.setItem('api_user', JSON.stringify(data.user || null));
                localStorage.setItem('api_chat_defaults', JSON.stringify({
                    business_client_id: (data.business_client_id || '').toString(),
                    workspace_id: (data.workspace_id || '').toString(),
                    user_id: (data.user && data.user.email) ? data.user.email : email,
                }));
                window.location.href = '/chat';
            } catch (error) {
                statusEl.textContent = error.message || 'Unable to login';
                statusEl.className = 'error';
            }
        });
    </script>
</body>
</html>
