<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Tester</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #cbd5e1;
            --primary: #0369a1;
            --primary-hover: #075985;
            --active: #0f766e;
            --active-hover: #0f5f58;
            --ok: #14532d;
            --ok-bg: #dcfce7;
            --err: #991b1b;
            --err-bg: #fee2e2;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 12%, #dbeafe, transparent 30%),
                radial-gradient(circle at 92% 3%, #cffafe, transparent 30%),
                var(--bg);
            min-height: 100vh;
            padding: 24px;
        }

        .wrap {
            max-width: 1080px;
            margin: 0 auto;
            display: grid;
            gap: 18px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.07);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        h2 {
            margin: 0;
            font-size: 20px;
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .token {
            width: 100%;
            min-height: 92px;
            margin-top: 10px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: Consolas, monospace;
            font-size: 13px;
            word-break: break-all;
            background: #f8fafc;
        }

        .row {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 10px;
            margin-top: 12px;
            align-items: center;
        }

        input, textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
            background: #ffffff;
        }

        input[readonly] {
            background: #f1f5f9;
            color: #334155;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
            font-family: Consolas, monospace;
            font-size: 13px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .endpoint-grid {
            margin-top: 12px;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 700;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover { background: var(--primary-hover); }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .endpoint-btn {
            text-align: left;
            border: 1px solid #dbe4ee;
            background: #f8fafc;
            color: #0f172a;
        }

        .endpoint-btn.active {
            background: var(--active);
            border-color: var(--active);
            color: #ffffff;
        }

        .endpoint-btn.active:hover {
            background: var(--active-hover);
        }

        .method-chip {
            display: inline-block;
            min-width: 56px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: #e2e8f0;
            color: #0f172a;
            margin-right: 8px;
        }

        .status {
            margin-top: 12px;
            padding: 10px;
            border-radius: 8px;
            font-size: 14px;
            display: none;
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
            background: #020617;
            color: #f8fafc;
            border-radius: 10px;
            padding: 14px;
            overflow: auto;
            min-height: 140px;
            margin-top: 10px;
        }

        .small {
            font-size: 12px;
            color: var(--muted);
            margin-top: 6px;
        }

        .endpoint-meta {
            margin-top: 6px;
            font-size: 13px;
            color: var(--muted);
        }

        .two-col {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr 1fr;
        }

        .stack {
            display: grid;
            gap: 10px;
        }

        .table-list {
            display: grid;
            gap: 8px;
            max-height: 220px;
            overflow: auto;
            margin-top: 8px;
        }

        .table-btn {
            background: #f8fafc;
            border: 1px solid var(--border);
            text-align: left;
        }

        .table-btn.active {
            background: var(--active);
            border-color: var(--active);
            color: #fff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            min-width: 700px;
        }

        th, td {
            border: 1px solid var(--border);
            padding: 6px 8px;
            font-size: 12px;
            text-align: left;
            vertical-align: top;
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media (max-width: 720px) {
            .row {
                grid-template-columns: 1fr;
            }

            .two-col {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="card">
            <h1>API Tester</h1>
            <p>Pick an API button, edit only the required fields, and run.</p>

            <div class="row">
                <label for="chatBusiness">Chat business_client_id</label>
                <input id="chatBusiness" type="text" value="acme">
            </div>

            <div class="row">
                <label for="chatWorkspace">Chat workspace_id</label>
                <input id="chatWorkspace" type="text" value="main">
            </div>

            <div class="row">
                <label for="chatUser">Chat user_id</label>
                <input id="chatUser" type="text" value="admin@acme.test">
            </div>

            <div class="actions">
                <a href="/login"><button class="btn-secondary" type="button">Back to Login</button></a>
            </div>
            <div class="small">Chat defaults are stored as api_chat_defaults.</div>
        </section>

        <section class="card">
            <h2>API Endpoints</h2>
            <div id="endpointButtons" class="endpoint-grid"></div>

            <div id="endpointMeta" class="endpoint-meta"></div>

            <div id="dynamicFields"></div>

            <div class="actions">
                <button id="sendBtn" type="button" class="btn-primary">Send Request</button>
            </div>

            <div id="status" class="status"></div>
            <pre id="output">Run a request to see response here.</pre>
        </section>

        <section id="chat-tester" class="card">
            <h2>Chat Tester</h2>
            <p>Single-click chat test using default business, workspace, and user.</p>

            <div class="row">
                <label for="chatPrompt">Prompt</label>
                <textarea id="chatPrompt">What does the uploaded document explain about clustering?</textarea>
            </div>

            <div class="actions">
                <button id="chatAskBtn" type="button" class="btn-primary">Ask</button>
            </div>

            <div id="chatStatus" class="status"></div>

            <div class="two-col">
                <div class="stack">
                    <h2>Retrieved Chunks</h2>
                    <pre id="chatSourcesOutput">No request yet.</pre>
                </div>
                <div class="stack">
                    <h2>Chat Response</h2>
                    <pre id="chatAnswerOutput">No request yet.</pre>
                </div>
            </div>
        </section>

        <section id="db-browser" class="card">
            <h2>Database Browser</h2>
            <p>Open any table and inspect current rows directly.</p>

            <div class="actions">
                <button id="dbRefreshBtn" type="button" class="btn-secondary">Refresh Tables</button>
            </div>
            <div id="dbStatus" class="status"></div>

            <div class="two-col">
                <div>
                    <h2>Tables</h2>
                    <div id="dbTableList" class="table-list"></div>
                </div>
                <div>
                    <h2 id="dbTableTitle">No table selected</h2>
                    <div style="overflow:auto; max-height: 400px;">
                        <table id="dbTable" style="display:none;">
                            <thead id="dbHead"></thead>
                            <tbody id="dbBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        const chatBusinessEl = document.getElementById('chatBusiness');
        const chatWorkspaceEl = document.getElementById('chatWorkspace');
        const chatUserEl = document.getElementById('chatUser');

        const endpointButtonsEl = document.getElementById('endpointButtons');
        const endpointMetaEl = document.getElementById('endpointMeta');
        const dynamicFieldsEl = document.getElementById('dynamicFields');
        const sendBtn = document.getElementById('sendBtn');
        const statusEl = document.getElementById('status');
        const outputEl = document.getElementById('output');

        const chatPromptEl = document.getElementById('chatPrompt');
        const chatAskBtn = document.getElementById('chatAskBtn');
        const chatStatusEl = document.getElementById('chatStatus');
        const chatSourcesOutputEl = document.getElementById('chatSourcesOutput');
        const chatAnswerOutputEl = document.getElementById('chatAnswerOutput');

        const dbRefreshBtn = document.getElementById('dbRefreshBtn');
        const dbStatusEl = document.getElementById('dbStatus');
        const dbTableListEl = document.getElementById('dbTableList');
        const dbTableTitleEl = document.getElementById('dbTableTitle');
        const dbTableEl = document.getElementById('dbTable');
        const dbHeadEl = document.getElementById('dbHead');
        const dbBodyEl = document.getElementById('dbBody');

        let activeDbTable = '';

        const endpointDefinitions = [
            {
                key: 'adminAuthLogin',
                label: '/api/admin/auth/login',
                method: 'POST',
                endpoint: '/api/admin/auth/login',
                useAuth: false,
                description: 'Admin auth login. Saves returned token automatically.',
                fields: [
                    { name: 'email', label: 'Email', type: 'text', defaultValue: 'admin@acme.test' },
                    { name: 'password', label: 'Password', type: 'text', defaultValue: 'Admin@12345' },
                ],
                buildBody(values) {
                    return {
                        email: values.email,
                        password: values.password,
                    };
                },
            },
            {
                key: 'adminCreateAdmin',
                label: '/api/admin/auth/create-admin',
                method: 'POST',
                endpoint: '/api/admin/auth/create-admin',
                useAuth: false,
                description: 'Creates a new global admin account.',
                fields: [
                    { name: 'email', label: 'New Admin Email', type: 'text', defaultValue: '' },
                    { name: 'password', label: 'New Admin Password', type: 'text', defaultValue: '' },
                ],
                buildBody(values) {
                    return {
                        email: values.email,
                        password: values.password,
                    };
                },
            },
            {
                key: 'adminCreateUser',
                label: '/api/admin/auth/create-user',
                method: 'POST',
                endpoint: '/api/admin/auth/create-user',
                useAuth: true,
                description: 'Creates a user in selected business/workspace.',
                fields: [
                    { name: 'email', label: 'New User Email', type: 'text', defaultValue: '' },
                    { name: 'password', label: 'New User Password', type: 'text', defaultValue: '' },
                ],
                buildBody(values) {
                    return {
                        email: values.email,
                        password: values.password,
                        business_client_id: chatBusinessEl.value.trim(),
                        workspace_id: chatWorkspaceEl.value.trim(),
                    };
                },
            },
            {
                key: 'adminSystemConfigGetOpenAiKey',
                label: '/api/admin/system-config/openai-api-key',
                method: 'GET',
                endpoint: '/api/admin/system-config/openai-api-key',
                useAuth: true,
                description: 'Get OpenAI API key status and masked preview.',
                fields: [],
            },
            {
                key: 'adminSystemConfigUpdateOpenAiKey',
                label: '/api/admin/system-config/openai-api-key (update)',
                method: 'PUT',
                endpoint: '/api/admin/system-config/openai-api-key',
                useAuth: true,
                description: 'Update OpenAI API key in system_config.',
                fields: [
                    { name: 'value', label: 'OpenAI API Key', type: 'text', defaultValue: '' },
                ],
                buildBody(values) {
                    return {
                        value: values.value,
                    };
                },
            },
            {
                key: 'adminBusinessesCreate',
                label: '/api/admin/businesses',
                method: 'POST',
                endpoint: '/api/admin/businesses',
                useAuth: true,
                description: 'Create a business and assign current admin as owner.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: '' },
                    { name: 'name', label: 'Business Name', type: 'text', defaultValue: '' },
                ],
                buildBody(values) {
                    return {
                        business_client_id: values.business_client_id,
                        name: values.name,
                    };
                },
            },
            {
                key: 'adminBusinessesList',
                label: '/api/admin/businesses (list)',
                method: 'GET',
                endpoint: '/api/admin/businesses',
                useAuth: true,
                description: 'List businesses visible to current admin.',
                fields: [],
            },
            {
                key: 'adminBusinessesGetOne',
                label: '/api/admin/businesses/{business_client_id}',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}',
                useAuth: true,
                description: 'Get a single business by business_client_id.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id);
                },
            },
            {
                key: 'adminWorkspacesCreate',
                label: '/api/admin/businesses/{business_client_id}/workspaces',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces',
                useAuth: true,
                description: 'Create workspace under a business.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'name', label: 'Workspace Name', type: 'text', defaultValue: 'Main Workspace' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces';
                },
                buildBody(values) {
                    return {
                        workspace_id: values.workspace_id,
                        name: values.name,
                    };
                },
            },
            {
                key: 'adminWorkspacesList',
                label: '/api/admin/businesses/{business_client_id}/workspaces (list)',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces',
                useAuth: true,
                description: 'List workspaces for a business.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces';
                },
            },
            {
                key: 'adminWorkspacesGetOne',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}',
                useAuth: true,
                description: 'Get one workspace by business and workspace id.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id);
                },
            },
            {
                key: 'adminWorkspacesDelete',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id} (delete)',
                method: 'DELETE',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}',
                useAuth: true,
                description: 'Delete workspace by business and workspace id.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id);
                },
            },
            {
                key: 'adminWorkspaceConfigGet',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/config',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/config',
                useAuth: true,
                description: 'Get workspace config for a business/workspace.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/config';
                },
            },
            {
                key: 'adminWorkspaceConfigUpdate',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/config (update)',
                method: 'PUT',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/config',
                useAuth: true,
                description: 'Update workspace config using JSON payload.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    {
                        name: 'config_json',
                        label: 'Config JSON',
                        type: 'textarea',
                        defaultValue: '{\n  "chunk_words": 300,\n  "overlap_words": 50,\n  "top_k": 5,\n  "similarity_threshold": 0.2,\n  "max_context_chars": 12000,\n  "embedding_model": "text-embedding-3-small",\n  "use_local_embeddings": false,\n  "chat_model_default": "gpt-4.1-mini",\n  "chat_temperature_default": 0.2,\n  "chat_max_tokens_default": 600,\n  "prompt_engineering": "You are a medical assistant. Provide concise answers based on the context."\n}'
                    },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/config';
                },
                buildBody(values) {
                    return JSON.parse(values.config_json || '{}');
                },
            },
            {
                key: 'adminDocumentsUpload',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/upload',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/upload',
                useAuth: true,
                description: 'Upload PDF/TXT document (multipart/form-data).',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'file', label: 'File', type: 'file', defaultValue: '' },
                    { name: 'chunk_words', label: 'Chunk Words (optional)', type: 'text', defaultValue: '' },
                    { name: 'overlap_words', label: 'Overlap Words (optional)', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/upload';
                },
                buildFormData(values) {
                    const formData = new FormData();
                    formData.append('file', values.file);
                    if (values.chunk_words) {
                        formData.append('chunk_words', values.chunk_words);
                    }
                    if (values.overlap_words) {
                        formData.append('overlap_words', values.overlap_words);
                    }
                    return formData;
                },
            },
            {
                key: 'adminDocumentsList',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents',
                useAuth: true,
                description: 'List documents in a workspace.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents';
                },
            },
            {
                key: 'adminDocumentsGetOne',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}',
                useAuth: true,
                description: 'Get document status/details.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id);
                },
            },
            {
                key: 'adminDocumentsDelete',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id} (delete)',
                method: 'DELETE',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}',
                useAuth: true,
                description: 'Delete document and chunks.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id);
                },
            },
            {
                key: 'adminDocumentsChunks',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/chunks',
                method: 'GET',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/chunks',
                useAuth: true,
                description: 'List document chunks (with pagination).',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                    { name: 'limit', label: 'Limit (optional)', type: 'text', defaultValue: '50' },
                    { name: 'offset', label: 'Offset (optional)', type: 'text', defaultValue: '0' },
                ],
                buildEndpoint(values) {
                    const params = new URLSearchParams();
                    if (values.limit) params.set('limit', values.limit);
                    if (values.offset) params.set('offset', values.offset);
                    const qs = params.toString();
                    let url = '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id) + '/chunks';
                    if (qs) {
                        url += '?' + qs;
                    }
                    return url;
                },
            },
            {
                key: 'adminDocumentsReindexOne',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/reindex',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/reindex',
                useAuth: true,
                description: 'Reindex one document.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id) + '/reindex';
                },
            },
            {
                key: 'adminDocumentsReindexAll',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/reindex-all',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/reindex-all',
                useAuth: true,
                description: 'Reindex all documents in workspace.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/reindex-all';
                },
            },
            {
                key: 'adminDocumentsCancel',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/cancel',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/cancel',
                useAuth: true,
                description: 'Cancel processing for one document.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id) + '/cancel';
                },
            },
            {
                key: 'adminDocumentsReset',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/reset',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/{document_id}/reset',
                useAuth: true,
                description: 'Reset one stuck/processing document.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'document_id', label: 'Document ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/' + encodeURIComponent(values.document_id) + '/reset';
                },
            },
            {
                key: 'adminDocumentsResetStuck',
                label: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/reset-stuck',
                method: 'POST',
                endpoint: '/api/admin/businesses/{business_client_id}/workspaces/{workspace_id}/documents/reset-stuck',
                useAuth: true,
                description: 'Reset all stuck processing documents.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                ],
                buildEndpoint(values) {
                    return '/api/admin/businesses/' + encodeURIComponent(values.business_client_id) + '/workspaces/' + encodeURIComponent(values.workspace_id) + '/documents/reset-stuck';
                },
            },
            {
                key: 'authMe',
                label: '/api/auth/me',
                method: 'GET',
                endpoint: '/api/auth/me',
                useAuth: true,
                description: 'Returns the currently authenticated admin user.',
                fields: [],
            },
            {
                key: 'authRefresh',
                label: '/api/auth/refresh',
                method: 'POST',
                endpoint: '/api/auth/refresh',
                useAuth: true,
                description: 'Creates a fresh session token for current admin.',
                fields: [],
            },
            {
                key: 'chatGenerate',
                label: '/api/chat/generate',
                method: 'POST',
                endpoint: '/api/chat/generate',
                useAuth: true,
                description: 'Send one prompt and receive a generated answer.',
                fields: [
                    { name: 'query', label: 'Prompt', type: 'textarea', defaultValue: 'What is DNA?' },
                ],
                buildBody(values) {
                    return {
                        business_client_id: chatBusinessEl.value.trim(),
                        workspace_id: chatWorkspaceEl.value.trim(),
                        user_id: chatUserEl.value.trim(),
                        query: values.query,
                    };
                },
            },
            {
                key: 'chatStream',
                label: '/api/chat/stream',
                method: 'POST',
                endpoint: '/api/chat/stream',
                useAuth: true,
                description: 'Streams the answer token-by-token as SSE.',
                fields: [
                    { name: 'query', label: 'Prompt', type: 'textarea', defaultValue: 'Explain blood pressure.' },
                ],
                buildBody(values) {
                    return {
                        business_client_id: chatBusinessEl.value.trim(),
                        workspace_id: chatWorkspaceEl.value.trim(),
                        user_id: chatUserEl.value.trim(),
                        query: values.query,
                    };
                },
            },
            {
                key: 'chatHeadersMe',
                label: '/api/chat/headers/me',
                method: 'GET',
                endpoint: '/api/chat/headers/me',
                useAuth: true,
                description: 'Gets chat headers for the logged-in admin.',
                fields: [],
            },
            {
                key: 'chatHistory',
                label: '/api/chat/history/{user_id}',
                method: 'GET',
                endpoint: '/api/chat/history/{user_id}',
                useAuth: true,
                description: 'Gets chat history for a specific user id/email.',
                fields: [
                    { name: 'user_id', label: 'User ID or Email', type: 'text', defaultValue: 'admin@acme.test' },
                ],
                buildEndpoint(values) {
                    return '/api/chat/history/' + encodeURIComponent(values.user_id);
                },
            },
            {
                key: 'chatDeleteHeader',
                label: '/api/chat/headers/{chat_id}',
                method: 'DELETE',
                endpoint: '/api/chat/headers/{chat_id}',
                useAuth: true,
                description: 'Deletes one chat header by chat id.',
                fields: [
                    { name: 'chat_id', label: 'Chat ID', type: 'text', defaultValue: '' },
                ],
                buildEndpoint(values) {
                    return '/api/chat/headers/' + encodeURIComponent(values.chat_id);
                },
            },
            {
                key: 'chatTestStream',
                label: '/api/chat/test-stream',
                method: 'GET',
                endpoint: '/api/chat/test-stream',
                useAuth: false,
                description: 'Open test stream endpoint (no token required).',
                fields: [],
            },
            {
                key: 'ragRetrieve',
                label: '/api/rag/retrieve',
                method: 'POST',
                endpoint: '/api/rag/retrieve',
                useAuth: true,
                description: 'Retrieve relevant chunks for query from a workspace.',
                fields: [
                    { name: 'business_client_id', label: 'Business Client ID', type: 'text', defaultValue: 'acme' },
                    { name: 'workspace_id', label: 'Workspace ID', type: 'text', defaultValue: 'main' },
                    { name: 'user_id', label: 'User ID', type: 'text', defaultValue: 'admin@acme.test' },
                    { name: 'query', label: 'Query', type: 'textarea', defaultValue: 'What does this document say about treatment?' },
                    { name: 'top_k', label: 'Top K (optional)', type: 'text', defaultValue: '7' },
                ],
                buildBody(values) {
                    const body = {
                        business_client_id: values.business_client_id,
                        workspace_id: values.workspace_id,
                        user_id: values.user_id,
                        query: values.query,
                    };
                    if (values.top_k) {
                        body.top_k = Number(values.top_k);
                    }
                    return body;
                },
            },
        ];

        let activeEndpoint = endpointDefinitions[0];

        function setStatus(message, ok) {
            statusEl.textContent = message;
            statusEl.className = ok ? 'status ok' : 'status err';
        }

        function setChatStatus(message, ok) {
            chatStatusEl.textContent = message;
            chatStatusEl.className = ok ? 'status ok' : 'status err';
        }

        function setDbStatus(message, ok) {
            dbStatusEl.textContent = message;
            dbStatusEl.className = ok ? 'status ok' : 'status err';
        }

        function formatOutput(status, headers, body) {
            return JSON.stringify({ status, headers, body }, null, 2);
        }

        function restoreStoredData() {
            const userRaw = localStorage.getItem('api_user');
            if (userRaw) {
                try {
                    const user = JSON.parse(userRaw);
                    if (user && user.email) {
                        chatUserEl.value = user.email;
                    }
                } catch (error) {
                    // Ignore invalid user payload in storage.
                }
            }

            const chatDefaultsRaw = localStorage.getItem('api_chat_defaults');
            if (chatDefaultsRaw) {
                try {
                    const defaults = JSON.parse(chatDefaultsRaw);
                    if (defaults.business_client_id) {
                        chatBusinessEl.value = defaults.business_client_id;
                    }
                    if (defaults.workspace_id) {
                        chatWorkspaceEl.value = defaults.workspace_id;
                    }
                    if (defaults.user_id) {
                        chatUserEl.value = defaults.user_id;
                    }
                } catch (error) {
                    // Ignore invalid defaults payload in storage.
                }
            }
        }

        function storeDefaults() {
            localStorage.setItem('api_chat_defaults', JSON.stringify({
                business_client_id: chatBusinessEl.value.trim(),
                workspace_id: chatWorkspaceEl.value.trim(),
                user_id: chatUserEl.value.trim(),
            }));
        }

        function renderEndpointButtons() {
            endpointButtonsEl.innerHTML = '';
            endpointDefinitions.forEach((endpointDef) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'endpoint-btn';
                button.dataset.key = endpointDef.key;
                button.innerHTML = '<span class="method-chip">' + endpointDef.method + '</span>' + endpointDef.label;
                button.addEventListener('click', () => {
                    activeEndpoint = endpointDef;
                    renderActiveEndpoint();
                });
                endpointButtonsEl.appendChild(button);
            });
        }

        function renderActiveEndpoint() {
            const buttons = endpointButtonsEl.querySelectorAll('button');
            buttons.forEach((button) => {
                button.classList.toggle('active', button.dataset.key === activeEndpoint.key);
            });

            endpointMetaEl.textContent = activeEndpoint.description;
            dynamicFieldsEl.innerHTML = '';

            if (activeEndpoint.fields.length === 0) {
                const info = document.createElement('div');
                info.className = 'small';
                info.textContent = 'No input needed for this API call.';
                dynamicFieldsEl.appendChild(info);
                return;
            }

            activeEndpoint.fields.forEach((field) => {
                const row = document.createElement('div');
                row.className = 'row';

                const label = document.createElement('label');
                label.htmlFor = 'field_' + field.name;
                label.textContent = field.label;

                const input = field.type === 'textarea'
                    ? document.createElement('textarea')
                    : document.createElement('input');

                input.id = 'field_' + field.name;
                input.name = field.name;
                if (field.type !== 'textarea') {
                    input.type = field.type === 'file' ? 'file' : 'text';
                }
                if (field.type !== 'file') {
                    input.value = field.defaultValue || '';
                }

                row.appendChild(label);
                row.appendChild(input);
                dynamicFieldsEl.appendChild(row);
            });
        }

        function collectFieldValues() {
            const values = {};
            (activeEndpoint.fields || []).forEach((field) => {
                const el = document.getElementById('field_' + field.name);
                if (!el) {
                    values[field.name] = '';
                    return;
                }

                if (field.type === 'file') {
                    values[field.name] = el.files && el.files.length > 0 ? el.files[0] : null;
                    return;
                }

                values[field.name] = (el.value || '').trim();
            });
            return values;
        }

        function validateRequiredContext() {
            if (!chatBusinessEl.value.trim() || !chatWorkspaceEl.value.trim() || !chatUserEl.value.trim()) {
                setStatus('Please fill chat business_client_id, workspace_id, and user_id.', false);
                return false;
            }
            return true;
        }

        async function sendRequest() {
            const values = collectFieldValues();

            if (activeEndpoint.key === 'chatGenerate' || activeEndpoint.key === 'chatStream') {
                if (!values.query) {
                    setStatus('Prompt cannot be empty.', false);
                    return;
                }
                if (!validateRequiredContext()) {
                    return;
                }
            }

            if (activeEndpoint.key === 'chatHistory' && !values.user_id) {
                setStatus('User ID is required.', false);
                return;
            }

            if ((activeEndpoint.key === 'adminAuthLogin' || activeEndpoint.key === 'adminCreateAdmin' || activeEndpoint.key === 'adminCreateUser') && (!values.email || !values.password)) {
                setStatus('Email and password are required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminSystemConfigUpdateOpenAiKey' && !values.value) {
                setStatus('OpenAI API key value is required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminBusinessesCreate' && (!values.business_client_id || !values.name)) {
                setStatus('Business client id and name are required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminBusinessesGetOne' && !values.business_client_id) {
                setStatus('Business client id is required.', false);
                return;
            }

            if ((activeEndpoint.key === 'adminWorkspacesCreate' || activeEndpoint.key === 'adminWorkspacesList' || activeEndpoint.key === 'adminWorkspacesGetOne' || activeEndpoint.key === 'adminWorkspacesDelete') && !values.business_client_id) {
                setStatus('Business client id is required.', false);
                return;
            }

            if ((activeEndpoint.key === 'adminWorkspacesCreate' || activeEndpoint.key === 'adminWorkspacesGetOne' || activeEndpoint.key === 'adminWorkspacesDelete') && !values.workspace_id) {
                setStatus('Workspace id is required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminWorkspacesCreate' && !values.name) {
                setStatus('Workspace name is required.', false);
                return;
            }

            if ((activeEndpoint.key === 'adminWorkspaceConfigGet' || activeEndpoint.key === 'adminWorkspaceConfigUpdate') && !values.business_client_id) {
                setStatus('Business client id is required.', false);
                return;
            }

            if ((activeEndpoint.key === 'adminWorkspaceConfigGet' || activeEndpoint.key === 'adminWorkspaceConfigUpdate') && !values.workspace_id) {
                setStatus('Workspace id is required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminWorkspaceConfigUpdate' && !values.config_json) {
                setStatus('Config JSON is required.', false);
                return;
            }

            if (activeEndpoint.key === 'chatDeleteHeader' && !values.chat_id) {
                setStatus('Chat ID is required.', false);
                return;
            }

            if (activeEndpoint.key === 'ragRetrieve') {
                if (!values.business_client_id || !values.workspace_id || !values.user_id || !values.query) {
                    setStatus('business_client_id, workspace_id, user_id and query are required.', false);
                    return;
                }
                if (values.top_k && Number.isNaN(Number(values.top_k))) {
                    setStatus('top_k must be a number when provided.', false);
                    return;
                }
            }

            const documentScopeKeys = [
                'adminDocumentsUpload',
                'adminDocumentsList',
                'adminDocumentsGetOne',
                'adminDocumentsDelete',
                'adminDocumentsChunks',
                'adminDocumentsReindexOne',
                'adminDocumentsReindexAll',
                'adminDocumentsCancel',
                'adminDocumentsReset',
                'adminDocumentsResetStuck',
            ];

            if (documentScopeKeys.includes(activeEndpoint.key) && !values.business_client_id) {
                setStatus('Business client id is required.', false);
                return;
            }

            if (documentScopeKeys.includes(activeEndpoint.key) && !values.workspace_id) {
                setStatus('Workspace id is required.', false);
                return;
            }

            const documentIdKeys = [
                'adminDocumentsGetOne',
                'adminDocumentsDelete',
                'adminDocumentsChunks',
                'adminDocumentsReindexOne',
                'adminDocumentsCancel',
                'adminDocumentsReset',
            ];

            if (documentIdKeys.includes(activeEndpoint.key) && !values.document_id) {
                setStatus('Document id is required.', false);
                return;
            }

            if (activeEndpoint.key === 'adminDocumentsUpload' && !values.file) {
                setStatus('Please choose a file to upload.', false);
                return;
            }

            if (activeEndpoint.key === 'adminCreateUser') {
                if (!chatBusinessEl.value.trim() || !chatWorkspaceEl.value.trim()) {
                    setStatus('Please fill chat business_client_id and workspace_id.', false);
                    return;
                }
            }

            const endpoint = activeEndpoint.buildEndpoint
                ? activeEndpoint.buildEndpoint(values)
                : activeEndpoint.endpoint;

            const headers = { 'Accept': 'application/json' };

            let body;
            if (activeEndpoint.buildFormData) {
                body = activeEndpoint.buildFormData(values);
            } else if (activeEndpoint.buildBody) {
                let payload;
                try {
                    payload = activeEndpoint.buildBody(values);
                } catch (error) {
                    setStatus('JSON body is invalid. Please fix and retry.', false);
                    return;
                }

                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(payload);
            }

            storeDefaults();

            try {
                const response = await fetch(endpoint, {
                    method: activeEndpoint.method,
                    headers,
                    body,
                });

                const text = await response.text();
                let parsedBody = text;

                try {
                    parsedBody = JSON.parse(text);
                } catch (error) {
                    // Keep plain text body for non-json responses.
                }

                const responseHeaders = {};
                response.headers.forEach((value, key) => {
                    responseHeaders[key] = value;
                });

                outputEl.textContent = formatOutput(response.status, responseHeaders, parsedBody);
                setStatus(activeEndpoint.method + ' ' + endpoint + ' -> ' + response.status, response.ok);
            } catch (error) {
                outputEl.textContent = error.message || 'Request failed';
                setStatus('Request failed. See output.', false);
            }
        }

        chatAskBtn.addEventListener('click', async () => {
            const payload = {
                business_client_id: chatBusinessEl.value.trim(),
                workspace_id: chatWorkspaceEl.value.trim(),
                user_id: chatUserEl.value.trim(),
                query: chatPromptEl.value.trim(),
            };

            if (!payload.business_client_id || !payload.workspace_id || !payload.user_id || !payload.query) {
                setChatStatus('Please fill business, workspace, user and prompt.', false);
                return;
            }

            try {
                const response = await fetch('/api/chat/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const text = await response.text();
                let data = text;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    // Keep plain text response.
                }

                chatAnswerOutputEl.textContent = JSON.stringify(data, null, 2);

                if (response.ok && data && typeof data === 'object') {
                    chatSourcesOutputEl.textContent = JSON.stringify(data.sources || [], null, 2);
                } else {
                    chatSourcesOutputEl.textContent = '[]';
                }

                setChatStatus('Chat request finished with status ' + response.status + '.', response.ok);
            } catch (error) {
                setChatStatus(error.message || 'Chat request failed.', false);
            }
        });

        async function loadDbTables() {
            setDbStatus('Loading tables...', true);
            try {
                const response = await fetch('/db/tables', { headers: { 'Accept': 'application/json' } });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.detail || 'Failed to load tables');
                }

                const tables = Array.isArray(data.tables) ? data.tables : [];
                dbTableListEl.innerHTML = '';

                tables.forEach((table) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'table-btn';
                    btn.textContent = table;
                    if (table === activeDbTable) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => loadDbTable(table));
                    dbTableListEl.appendChild(btn);
                });

                setDbStatus('Loaded ' + tables.length + ' table(s).', true);
            } catch (error) {
                setDbStatus(error.message || 'Failed to load tables.', false);
            }
        }

        async function loadDbTable(table) {
            activeDbTable = table;
            await loadDbTables();
            setDbStatus('Loading table ' + table + '...', true);

            try {
                const response = await fetch('/db/tables/' + encodeURIComponent(table), { headers: { 'Accept': 'application/json' } });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.detail || 'Failed to load table');
                }

                const columns = Array.isArray(data.columns) ? data.columns : [];
                const rows = Array.isArray(data.rows) ? data.rows : [];

                dbTableTitleEl.textContent = table + ' (' + rows.length + ' rows)';

                dbHeadEl.innerHTML = '';
                dbBodyEl.innerHTML = '';

                if (columns.length === 0) {
                    dbTableEl.style.display = 'none';
                    setDbStatus('No columns found for table ' + table + '.', true);
                    return;
                }

                const headRow = document.createElement('tr');
                columns.forEach((column) => {
                    const th = document.createElement('th');
                    th.textContent = column;
                    headRow.appendChild(th);
                });
                dbHeadEl.appendChild(headRow);

                rows.forEach((row) => {
                    const tr = document.createElement('tr');
                    columns.forEach((column) => {
                        const td = document.createElement('td');
                        const value = row[column];
                        td.textContent = value === null || value === undefined
                            ? ''
                            : (typeof value === 'object' ? JSON.stringify(value) : String(value));
                        tr.appendChild(td);
                    });
                    dbBodyEl.appendChild(tr);
                });

                dbTableEl.style.display = 'table';
                setDbStatus('Loaded table ' + table + '.', true);
            } catch (error) {
                dbTableEl.style.display = 'none';
                setDbStatus(error.message || 'Failed to load table.', false);
            }
        }

        dbRefreshBtn.addEventListener('click', loadDbTables);

        sendBtn.addEventListener('click', sendRequest);

        restoreStoredData();
        renderEndpointButtons();
        renderActiveEndpoint();
        loadDbTables();
    </script>
</body>
</html>
