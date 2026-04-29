<?php

declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/config_overpass.php';

if (!is_file($configPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'config_overpass.php not found';
    exit;
}

$config = require $configPath;

if (!is_array($config)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'config_overpass.php did not return array';
    exit;
}

requireAdminAuth($config);

function requireAdminAuth(array $config): void
{
    $expectedUser = (string)($config['admin']['username'] ?? '');
    $expectedPass = (string)($config['admin']['password'] ?? '');

    if ($expectedUser === '' || $expectedPass === '') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo 'Admin auth is not configured';
        exit;
    }

    [$user, $pass] = getBasicAuthCredentials();

    if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
        header('WWW-Authenticate: Basic realm="Overpass Cache Admin"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Authentication required';
        exit;
    }
}

/**
 * @return array{0: string, 1: string}
 */
function getBasicAuthCredentials(): array
{
    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

    if ($user !== '' || $pass !== '') {
        return [$user, $pass];
    }

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded !== false) {
            $pos = strpos($decoded, ':');
            if ($pos !== false) {
                return [substr($decoded, 0, $pos), substr($decoded, $pos + 1)];
            }
        }
    }

    return ['', ''];
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overpass Cache Admin</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: sans-serif;
        }

        #app {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            height: 100%;
        }

        #map {
            height: 100%;
            min-height: 400px;
        }

        #side {
            display: flex;
            flex-direction: column;
            min-width: 320px;
            border-left: 1px solid #ccc;
            background: #fff;
        }

        .controls {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            display: grid;
            gap: 8px;
        }

        .controls .row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .controls button,
        .controls input[type="number"] {
            padding: 6px 10px;
        }

        .summary,
        .selection {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
        }

        #list {
            flex: 1;
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            border-bottom: 1px solid #eee;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }

        tr.selected {
            background: #fff3cd;
        }

        tr.expired {
            color: #777;
        }

        .small {
            color: #666;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            background: #eee;
        }

        .badge.active {
            background: #d1f5d3;
        }

        .badge.expired {
            background: #eee;
        }

        .notice {
            color: #666;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div id="app">
        <div id="map"></div>
        <div id="side">
            <div class="controls">
                <div class="row">
                    <button id="btnLoadVisible">現在の表示範囲で読込</button>
                    <button id="btnLoadAll">全件読込</button>
                </div>
                <div class="row">
                    <label><input type="checkbox" id="includeExpired"> 期限切れも含む</label>
                    <label>件数上限 <input type="number" id="limit" min="1" max="2000" value="500" style="width:90px"></label>
                </div>
                <div class="row">
                    <button id="btnSelectionMode">範囲選択: OFF</button>
                    <button id="btnClearSelection">選択解除</button>
                </div>
                <div class="row">
                    <button id="btnDeleteSelected">選択削除</button>
                    <button id="btnDeleteExpired">期限切れ削除</button>
                </div>
                <div class="notice">
                    地図上の矩形をクリックで選択。範囲選択モード中はドラッグで一括選択。
                </div>
            </div>

            <div class="summary" id="summary">集計を読込中...</div>
            <div class="selection" id="selectionInfo">選択 0 件</div>

            <div id="list">
                <table>
                    <thead>
                        <tr>
                            <th>状態</th>
                            <th>範囲</th>
                            <th>サイズ</th>
                            <th>期限</th>
                        </tr>
                    </thead>
                    <tbody id="tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const apiUrl = './cache_admin_api.php';

        const map = L.map('map').setView([34.6937, 135.5023], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const layerGroup = L.layerGroup().addTo(map);
        const cacheItems = new Map();
        const selectedKeys = new Set();

        let selectionMode = false;
        let dragStart = null;
        let dragRect = null;
        let loadedItems = [];

        const btnLoadVisible = document.getElementById('btnLoadVisible');
        const btnLoadAll = document.getElementById('btnLoadAll');
        const btnSelectionMode = document.getElementById('btnSelectionMode');
        const btnClearSelection = document.getElementById('btnClearSelection');
        const btnDeleteSelected = document.getElementById('btnDeleteSelected');
        const btnDeleteExpired = document.getElementById('btnDeleteExpired');
        const includeExpired = document.getElementById('includeExpired');
        const limitInput = document.getElementById('limit');
        const summaryEl = document.getElementById('summary');
        const selectionInfoEl = document.getElementById('selectionInfo');
        const tbody = document.getElementById('tbody');

        btnLoadVisible.addEventListener('click', () => loadCaches(true));
        btnLoadAll.addEventListener('click', () => loadCaches(false));
        btnSelectionMode.addEventListener('click', toggleSelectionMode);
        btnClearSelection.addEventListener('click', clearSelection);
        btnDeleteSelected.addEventListener('click', deleteSelected);
        btnDeleteExpired.addEventListener('click', deleteExpired);

        map.on('mousedown', onMapMouseDown);
        map.on('mousemove', onMapMouseMove);
        map.on('mouseup', onMapMouseUp);

        loadSummary();
        loadCaches(true);

        async function loadSummary() {
            const res = await fetch(apiUrl + '?action=summary', {
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!data.ok) return;

            const s = data.summary;
            summaryEl.innerHTML =
                `総数 ${s.total_count} 件 / 有効 ${s.active_count} 件 / 期限切れ ${s.expired_count} 件 / 保存サイズ ${formatBytes(s.total_bytes)}`;
        }

        async function loadCaches(visibleOnly) {
            clearMap();

            const params = new URLSearchParams();
            params.set('action', 'list');
            params.set('limit', String(Math.max(1, Math.min(2000, Number(limitInput.value) || 500))));
            params.set('includeExpired', includeExpired.checked ? '1' : '0');

            if (visibleOnly) {
                const b = map.getBounds();
                params.set('south', String(b.getSouth()));
                params.set('west', String(b.getWest()));
                params.set('north', String(b.getNorth()));
                params.set('east', String(b.getEast()));
            }

            const res = await fetch(apiUrl + '?' + params.toString(), {
                credentials: 'same-origin'
            });
            const data = await res.json();

            if (!data.ok) {
                alert(data.error || '読込失敗');
                return;
            }

            loadedItems = data.items;
            renderItems(loadedItems);

            if (!visibleOnly && loadedItems.length > 0) {
                const fg = [];
                for (const item of loadedItems) {
                    fg.push([
                        [item.south, item.west],
                        [item.north, item.east]
                    ]);
                }
                map.fitBounds(fg[0], {
                    padding: [20, 20]
                });
            }

            loadSummary();
        }

        function clearMap() {
            layerGroup.clearLayers();
            cacheItems.clear();
            clearSelection();
            tbody.innerHTML = '';
            loadedItems = [];
        }

        function renderItems(items) {
            tbody.innerHTML = '';

            for (const item of items) {
                const bounds = L.latLngBounds(
                    [item.south, item.west],
                    [item.north, item.east]
                );

                const layer = L.rectangle(bounds, getLayerStyle(item, false))
                    .addTo(layerGroup)
                    .on('click', () => toggleSelection(item.cache_key))
                    .bindTooltip(item.normalized_bbox);

                cacheItems.set(item.cache_key, {
                    item,
                    layer
                });

                const tr = document.createElement('tr');
                tr.dataset.key = item.cache_key;
                if (!item.is_active) tr.classList.add('expired');

                tr.innerHTML = `
            <td>
                <span class="badge ${item.is_active ? 'active' : 'expired'}">${item.is_active ? '有効' : '期限切れ'}</span>
                <div class="small">${escapeHtml(item.status_code + ' / ' + item.body_encoding)}</div>
            </td>
            <td>
                <div>${escapeHtml(item.normalized_bbox)}</div>
                <div class="small">${escapeHtml(item.query_hash.slice(0, 12))}...</div>
            </td>
            <td>${formatBytes(item.stored_bytes)}</td>
            <td>
                <div class="small">作成: ${formatUtc(item.created_at_utc)}</div>
                <div class="small">期限: ${formatUtc(item.expires_at_utc)}</div>
            </td>
        `;

                tr.addEventListener('click', () => {
                    map.fitBounds(bounds, {
                        padding: [20, 20]
                    });
                    toggleSelection(item.cache_key);
                });

                tbody.appendChild(tr);
            }

            updateSelectionInfo();
        }

        function getLayerStyle(item, selected) {
            if (selected) {
                return {
                    color: '#d9480f',
                    weight: 3,
                    fillColor: '#f08c00',
                    fillOpacity: 0.25
                };
            }

            if (!item.is_active) {
                return {
                    color: '#888',
                    weight: 1,
                    fillColor: '#bbb',
                    fillOpacity: 0.10
                };
            }

            return {
                color: '#1971c2',
                weight: 1,
                fillColor: '#74c0fc',
                fillOpacity: 0.12
            };
        }

        function toggleSelection(key) {
            if (!cacheItems.has(key)) return;

            if (selectedKeys.has(key)) {
                selectedKeys.delete(key);
            } else {
                selectedKeys.add(key);
            }

            refreshSelectionStyles();
        }

        function clearSelection() {
            selectedKeys.clear();
            refreshSelectionStyles();
        }

        function refreshSelectionStyles() {
            for (const [key, value] of cacheItems.entries()) {
                const selected = selectedKeys.has(key);
                value.layer.setStyle(getLayerStyle(value.item, selected));

                const tr = tbody.querySelector(`tr[data-key="${cssEscape(key)}"]`);
                if (tr) {
                    tr.classList.toggle('selected', selected);
                }
            }

            updateSelectionInfo();
        }

        function updateSelectionInfo() {
            selectionInfoEl.textContent = `選択 ${selectedKeys.size} 件`;
        }

        function toggleSelectionMode() {
            selectionMode = !selectionMode;
            btnSelectionMode.textContent = '範囲選択: ' + (selectionMode ? 'ON' : 'OFF');
            map.getContainer().style.cursor = selectionMode ? 'crosshair' : '';
        }

        function onMapMouseDown(e) {
            if (!selectionMode) return;
            dragStart = e.latlng;
            map.dragging.disable();

            if (dragRect) {
                map.removeLayer(dragRect);
                dragRect = null;
            }
        }

        function onMapMouseMove(e) {
            if (!selectionMode || !dragStart) return;

            const bounds = L.latLngBounds(dragStart, e.latlng);

            if (!dragRect) {
                dragRect = L.rectangle(bounds, {
                    color: '#2b8a3e',
                    weight: 2,
                    dashArray: '4,4',
                    fillOpacity: 0.08
                }).addTo(map);
            } else {
                dragRect.setBounds(bounds);
            }
        }

        function onMapMouseUp() {
            if (!selectionMode || !dragStart) return;

            const bounds = dragRect ? dragRect.getBounds() : null;
            if (bounds) {
                for (const [key, value] of cacheItems.entries()) {
                    if (bounds.intersects(value.layer.getBounds())) {
                        selectedKeys.add(key);
                    }
                }
            }

            if (dragRect) {
                map.removeLayer(dragRect);
                dragRect = null;
            }

            dragStart = null;
            map.dragging.enable();
            refreshSelectionStyles();
        }

        async function deleteSelected() {
            if (selectedKeys.size === 0) {
                alert('選択がありません');
                return;
            }

            if (!confirm(`${selectedKeys.size} 件を削除します。`)) {
                return;
            }

            const res = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete',
                    keys: [...selectedKeys]
                })
            });

            const data = await res.json();
            if (!data.ok) {
                alert(data.error || '削除失敗');
                return;
            }

            await loadCaches(true);
        }

        async function deleteExpired() {
            if (!confirm('期限切れキャッシュを削除します。')) {
                return;
            }

            const res = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete_expired'
                })
            });

            const data = await res.json();
            if (!data.ok) {
                alert(data.error || '削除失敗');
                return;
            }

            await loadCaches(true);
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
            return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
        }

        function formatUtc(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return iso;
            return d.toLocaleString('ja-JP');
        }

        function escapeHtml(s) {
            return String(s)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        function cssEscape(value) {
            return String(value).replace(/["\\]/g, '\\$&');
        }
    </script>
</body>

</html>