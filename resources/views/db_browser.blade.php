<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Browser</title>
    <style>
        :root {
            --bg: #f4f6fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #d7deea;
            --primary: #0b6ea8;
            --primary-hover: #095985;
            --active: #0f766e;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 15%, #dbeafe 0%, transparent 28%),
                radial-gradient(circle at 88% 2%, #d1fae5 0%, transparent 26%),
                var(--bg);
            padding: 24px;
        }

        .wrap {
            max-width: 1300px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        }

        .sidebar {
            padding: 16px;
            max-height: calc(100vh - 48px);
            overflow: auto;
        }

        .main {
            padding: 16px;
            overflow: auto;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .sub {
            margin-top: 6px;
            color: var(--muted);
            font-size: 14px;
        }

        .toolbar {
            margin-top: 14px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 9px 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover { background: var(--primary-hover); }

        .table-list {
            margin-top: 14px;
            display: grid;
            gap: 8px;
        }

        .table-btn {
            background: #f8fafc;
            border: 1px solid var(--border);
            color: var(--text);
            text-align: left;
        }

        .table-btn.active {
            background: var(--active);
            color: #fff;
            border-color: var(--active);
        }

        .status {
            margin-top: 12px;
            font-size: 13px;
            color: var(--muted);
        }

        .status.error {
            color: var(--error-text);
            background: var(--error-bg);
            border-radius: 8px;
            padding: 8px 10px;
        }

        .meta {
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .table-wrap {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: auto;
            max-height: calc(100vh - 180px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        thead {
            background: #f1f5f9;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        th, td {
            border-bottom: 1px solid var(--border);
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            font-weight: 800;
            color: #1e293b;
        }

        td {
            color: #0f172a;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .empty {
            margin-top: 14px;
            font-size: 14px;
            color: var(--muted);
        }

        @media (max-width: 960px) {
            .wrap {
                grid-template-columns: 1fr;
            }

            .sidebar {
                max-height: none;
            }

            .table-wrap {
                max-height: 60vh;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <aside class="card sidebar">
            <h1>Database</h1>
            <div class="sub">Click a table to view all entries.</div>
            <div class="toolbar">
                <button id="refreshBtn" class="btn-primary" type="button">Refresh Tables</button>
                <a href="/api"><button type="button">Back to API</button></a>
            </div>
            <div id="status" class="status">Loading tables...</div>
            <div id="tableList" class="table-list"></div>
        </aside>

        <main class="card main">
            <h1 id="tableTitle">No table selected</h1>
            <div id="tableMeta" class="meta"></div>
            <div id="emptyState" class="empty">Select a table from the left panel.</div>

            <div id="tableWrap" class="table-wrap" style="display:none;">
                <table>
                    <thead id="tableHead"></thead>
                    <tbody id="tableBody"></tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        const refreshBtn = document.getElementById('refreshBtn');
        const statusEl = document.getElementById('status');
        const tableListEl = document.getElementById('tableList');

        const tableTitleEl = document.getElementById('tableTitle');
        const tableMetaEl = document.getElementById('tableMeta');
        const emptyStateEl = document.getElementById('emptyState');
        const tableWrapEl = document.getElementById('tableWrap');
        const tableHeadEl = document.getElementById('tableHead');
        const tableBodyEl = document.getElementById('tableBody');

        let activeTable = '';
        let tables = [];

        function setStatus(message, isError) {
            statusEl.textContent = message;
            statusEl.className = isError ? 'status error' : 'status';
        }

        function renderTableButtons() {
            tableListEl.innerHTML = '';

            if (tables.length === 0) {
                setStatus('No tables found.', false);
                return;
            }

            tables.forEach((tableName) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'table-btn';
                button.textContent = tableName;
                button.dataset.table = tableName;
                button.addEventListener('click', () => loadTable(tableName));

                if (tableName === activeTable) {
                    button.classList.add('active');
                }

                tableListEl.appendChild(button);
            });
        }

        function renderDataTable(tableName, columns, rows) {
            tableTitleEl.textContent = tableName;
            tableMetaEl.textContent = rows.length + ' row(s), ' + columns.length + ' column(s)';

            if (rows.length === 0) {
                tableWrapEl.style.display = 'none';
                emptyStateEl.style.display = 'block';
                emptyStateEl.textContent = 'Table is empty.';
                return;
            }

            const headRow = document.createElement('tr');
            columns.forEach((columnName) => {
                const th = document.createElement('th');
                th.textContent = columnName;
                headRow.appendChild(th);
            });
            tableHeadEl.innerHTML = '';
            tableHeadEl.appendChild(headRow);

            tableBodyEl.innerHTML = '';
            rows.forEach((row) => {
                const tr = document.createElement('tr');
                columns.forEach((columnName) => {
                    const td = document.createElement('td');
                    const value = row[columnName];
                    if (value === null || value === undefined) {
                        td.textContent = '';
                    } else if (typeof value === 'object') {
                        td.textContent = JSON.stringify(value);
                    } else {
                        td.textContent = String(value);
                    }
                    tr.appendChild(td);
                });
                tableBodyEl.appendChild(tr);
            });

            emptyStateEl.style.display = 'none';
            tableWrapEl.style.display = 'block';
        }

        async function loadTables() {
            setStatus('Loading tables...', false);
            try {
                const response = await fetch('/db/tables', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.detail || 'Failed to load tables');
                }

                tables = Array.isArray(data.tables) ? data.tables : [];
                renderTableButtons();
                setStatus('Loaded ' + tables.length + ' table(s).', false);
            } catch (error) {
                setStatus(error.message || 'Failed to load tables.', true);
            }
        }

        async function loadTable(tableName) {
            activeTable = tableName;
            renderTableButtons();
            setStatus('Loading table: ' + tableName + '...', false);

            try {
                const response = await fetch('/db/tables/' + encodeURIComponent(tableName), {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.detail || 'Failed to load table');
                }

                renderDataTable(data.table || tableName, data.columns || [], data.rows || []);
                setStatus('Loaded table ' + tableName + '.', false);
            } catch (error) {
                tableTitleEl.textContent = 'Load failed';
                tableMetaEl.textContent = '';
                tableWrapEl.style.display = 'none';
                emptyStateEl.style.display = 'block';
                emptyStateEl.textContent = 'Could not load table data.';
                setStatus(error.message || 'Failed to load table.', true);
            }
        }

        refreshBtn.addEventListener('click', loadTables);

        loadTables();
    </script>
</body>
</html>
