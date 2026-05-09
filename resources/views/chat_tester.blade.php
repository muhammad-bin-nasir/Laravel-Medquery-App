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

        .image-info {
            margin-top: 10px;
            font-size: 13px;
            color: var(--muted);
        }

        .image-info.err {
            color: var(--err);
        }

        .image-preview {
            margin-top: 10px;
            max-width: 240px;
            max-height: 160px;
            border-radius: 8px;
            border: 1px solid var(--border);
            display: none;
        }

    </style>
</head>
<body>
    <div class="wrap">
        <section class="card">
            <h1>Chat</h1>
            <p>Enter a prompt, optionally attach an image, and send to `/api/ai/chat`.</p>

            <input id="query" type="text" placeholder="Type your message here..." value="What does the uploaded document explain about clustering?">
            <input id="imagePicker" type="file" accept="image/*" style="display:none;">
            <div class="actions">
                <button id="selectImageBtn" class="btn-secondary" type="button">Select Image</button>
                <button id="clearImageBtn" class="btn-secondary" type="button">Clear Image</button>
                <button id="resetDefaultsBtn" class="btn-secondary" type="button">Reset Defaults</button>
                <button id="askBtn" class="btn-primary" type="button">Send</button>
            </div>

            <div id="imageInfo" class="image-info">No image selected.</div>
            <img id="imagePreview" class="image-preview" alt="Selected image preview">

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
        const imagePickerEl = document.getElementById('imagePicker');
        const imageInfoEl = document.getElementById('imageInfo');
        const imagePreviewEl = document.getElementById('imagePreview');
        const selectImageBtn = document.getElementById('selectImageBtn');
        const clearImageBtn = document.getElementById('clearImageBtn');
        const resetDefaultsBtn = document.getElementById('resetDefaultsBtn');

        const askBtn = document.getElementById('askBtn');

        const statusEl = document.getElementById('status');
        const ragOutputEl = document.getElementById('ragOutput');
        const chatOutputEl = document.getElementById('chatOutput');

        const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
        const FALLBACK_BUSINESS_CLIENT_ID = 'test';
        const FALLBACK_WORKSPACE_ID = 'test';
        const FALLBACK_USER_ID = 'admin@admin.com';
        let selectedImageDataUrl = '';

        function setStatus(message, ok) {
            statusEl.textContent = message;
            statusEl.className = ok ? 'status ok' : 'status err';
        }

        function pretty(value) {
            return JSON.stringify(value, null, 2);
        }

        function setImageInfo(message, isError) {
            imageInfoEl.textContent = message;
            imageInfoEl.className = isError ? 'image-info err' : 'image-info';
        }

        function fileToDataUrl(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result || ''));
                reader.onerror = () => reject(new Error('Failed to read image file.'));
                reader.readAsDataURL(file);
            });
        }

        function clearImageSelection() {
            selectedImageDataUrl = '';
            imagePickerEl.value = '';
            imagePreviewEl.src = '';
            imagePreviewEl.style.display = 'none';
            setImageInfo('No image selected.', false);
        }

        function resetChatDefaults() {
            const userRaw = localStorage.getItem('api_user');
            let user = {};
            if (userRaw) {
                try {
                    user = JSON.parse(userRaw) || {};
                } catch (error) {
                    user = {};
                }
            }

            const nextDefaults = {
                business_client_id: FALLBACK_BUSINESS_CLIENT_ID,
                workspace_id: FALLBACK_WORKSPACE_ID,
                user_id: (user.email || FALLBACK_USER_ID).toString().trim(),
            };

            localStorage.setItem('api_chat_defaults', JSON.stringify(nextDefaults));
            setStatus('Chat defaults reset to test/test.', true);
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

            let businessClientId = (defaults.business_client_id || FALLBACK_BUSINESS_CLIENT_ID).toString().trim();
            let workspaceId = (defaults.workspace_id || FALLBACK_WORKSPACE_ID).toString().trim();

            const staleLegacyPair = businessClientId === 'acme' && workspaceId === 'main';
            if (!businessClientId || !workspaceId || staleLegacyPair) {
                businessClientId = FALLBACK_BUSINESS_CLIENT_ID;
                workspaceId = FALLBACK_WORKSPACE_ID;
            }

            localStorage.setItem('api_chat_defaults', JSON.stringify({
                business_client_id: businessClientId,
                workspace_id: workspaceId,
                user_id: (defaults.user_id || user.email || FALLBACK_USER_ID).toString().trim(),
            }));

            const body = {
                business_client_id: businessClientId,
                workspace_id: workspaceId,
                user_id: (defaults.user_id || user.email || FALLBACK_USER_ID).toString().trim(),
                query: queryEl.value.trim(),
            };

            if (selectedImageDataUrl) {
                body.image_data_url = selectedImageDataUrl;
            }

            return body;
        }

        async function callApi(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
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

            const result = await callApi('/api/ai/chat', body);
            chatOutputEl.textContent = pretty(result);

            if (result.ok && result.body && typeof result.body === 'object') {
                ragOutputEl.textContent = pretty(result.body.sources || []);
            } else {
                ragOutputEl.textContent = '[]';
            }

            setStatus('AI chat returned ' + result.status, result.ok);
            return result;
        }

        selectImageBtn.addEventListener('click', () => {
            imagePickerEl.click();
        });

        clearImageBtn.addEventListener('click', () => {
            clearImageSelection();
        });

        resetDefaultsBtn.addEventListener('click', () => {
            resetChatDefaults();
        });

        imagePickerEl.addEventListener('change', async () => {
            const file = imagePickerEl.files && imagePickerEl.files[0];
            if (!file) {
                clearImageSelection();
                return;
            }

            if (!file.type.startsWith('image/')) {
                clearImageSelection();
                setImageInfo('Only image files are allowed.', true);
                return;
            }

            if (file.size > MAX_IMAGE_BYTES) {
                clearImageSelection();
                setImageInfo('Image is too large. Max size is 5MB.', true);
                return;
            }

            try {
                selectedImageDataUrl = await fileToDataUrl(file);
                const fileSizeKb = Math.round(file.size / 1024);
                setImageInfo('Selected: ' + file.name + ' (' + fileSizeKb + ' KB)', false);
                imagePreviewEl.src = selectedImageDataUrl;
                imagePreviewEl.style.display = 'block';
            } catch (error) {
                clearImageSelection();
                setImageInfo(error.message || 'Failed to process image.', true);
            }
        });

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

        // Avoid stale localStorage defaults from older test pages.
        resetChatDefaults();
    </script>
</body>
</html>
