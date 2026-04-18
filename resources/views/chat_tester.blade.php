<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat</title>
    <style>
        :root {
            --bg: #f3f5fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #d6deea;
            --primary: #0369a1;
            --primary-hover: #075985;
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
                radial-gradient(circle at 10% 15%, #dbeafe 0%, transparent 30%),
                radial-gradient(circle at 90% 3%, #cffafe 0%, transparent 24%),
                var(--bg);
            min-height: 100vh;
            padding: 24px;
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.07);
        }

        h1 {
            margin: 0;
            font-size: 30px;
        }

        p {
            margin: 6px 0 0;
            color: var(--muted);
        }

        input, textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
            background: #fff;
        }

        input {
            margin-top: 12px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .status {
            margin-top: 10px;
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

        pre {
            margin: 10px 0 0;
            background: #020617;
            color: #f8fafc;
            border-radius: 10px;
            padding: 12px;
            min-height: 120px;
            overflow: auto;
        }

    </style>
</head>
<body>
    <div class="wrap">
        <section class="card">
            <h1>Chat</h1>
            <p>Enter a prompt and send. This uses the same `/api/chat/generate` flow as the chat section in `/test`.</p>

            <input id="query" type="text" placeholder="Type your message here..." value="What does the uploaded document explain about clustering?">
            <div class="actions">
                <button id="askBtn" class="btn-primary" type="button">Send</button>
            </div>

            <div id="status" class="status"></div>
        </section>

        <section class="card">
            <h2>Retrieved Chunks</h2>
            <pre id="ragOutput">No request yet.</pre>
        </section>

        <section class="card">
            <h2>Chat Response</h2>
            <pre id="chatOutput">No request yet.</pre>
        </section>
    </div>

    <script>
        const queryEl = document.getElementById('query');

        const askBtn = document.getElementById('askBtn');

        const statusEl = document.getElementById('status');
        const ragOutputEl = document.getElementById('ragOutput');
        const chatOutputEl = document.getElementById('chatOutput');

        function setStatus(message, ok) {
            statusEl.textContent = message;
            statusEl.className = ok ? 'status ok' : 'status err';
        }

        function pretty(value) {
            return JSON.stringify(value, null, 2);
        }

        function payload() {
            const defaultsRaw = localStorage.getItem('api_chat_defaults');
            let defaults = {};

            if (defaultsRaw) {
                try {
                    defaults = JSON.parse(defaultsRaw) || {};
                } catch (error) {
                    defaults = {};
                }
            }

            const userRaw = localStorage.getItem('api_user');
            let user = {};
            if (userRaw) {
                try {
                    user = JSON.parse(userRaw) || {};
                } catch (error) {
                    user = {};
                }
            }

            const businessClientId = (defaults.business_client_id || 'acme').toString().trim();
            let workspaceId = (defaults.workspace_id || 'main').toString().trim();

            if (!workspaceId || (businessClientId === 'acme' && workspaceId === 'test')) {
                workspaceId = 'main';
            }

            localStorage.setItem('api_chat_defaults', JSON.stringify({
                business_client_id: businessClientId,
                workspace_id: workspaceId,
                user_id: (defaults.user_id || user.email || 'admin@admin.com').toString().trim(),
            }));

            return {
                business_client_id: businessClientId,
                workspace_id: workspaceId,
                user_id: (defaults.user_id || user.email || 'admin@admin.com').toString().trim(),
                query: queryEl.value.trim(),
            };
        }

        async function callApi(url, body) {
            const token = (localStorage.getItem('api_token') || '').trim();
            if (!token) {
                throw new Error('Token is missing. Login first at /login.');
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + token,
                },
                body: JSON.stringify(body),
            });

            const text = await response.text();
            let data = text;
            try {
                data = JSON.parse(text);
            } catch (error) {
                // Keep plain text for non-json responses.
            }

            return {
                ok: response.ok,
                status: response.status,
                body: data,
            };
        }

        async function runAsk() {
            const body = payload();

            if (!body.query) {
                throw new Error('Prompt cannot be empty.');
            }

            if (!body.business_client_id || !body.workspace_id || !body.user_id) {
                throw new Error('Chat defaults are missing. Open /test once or login again.');
            }

            const result = await callApi('/api/chat/generate', body);
            chatOutputEl.textContent = pretty(result);

            if (result.ok && result.body && typeof result.body === 'object') {
                ragOutputEl.textContent = pretty(result.body.sources || []);
            } else {
                ragOutputEl.textContent = '[]';
            }

            setStatus('Chat generate returned ' + result.status, result.ok);
            return result;
        }

        askBtn.addEventListener('click', async () => {
            try {
                await runAsk();
            } catch (error) {
                setStatus(error.message || 'Run failed', false);
            }
        });

        queryEl.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                askBtn.click();
            }
        });
    </script>
</body>
</html>
