<?php
/**
 * WhatsApp Connector - Request & Response Admin Dashboard
 * Displays incoming requests with image preview and allows manual response submission.
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/request_queue.php';

// Serve raw request images
if (isset($_GET['action']) && $_GET['action'] === 'view_image') {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['id'] ?? '');
    $imgPath = RequestQueue::getImagePath($id);
    if ($imgPath && file_exists($imgPath)) {
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($imgPath));
        readfile($imgPath);
        exit;
    }
    http_response_code(404);
    echo "Image not found";
    exit;
}

// Handle AJAX API calls from the request/response page
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 1. Submit manual response
    if ($_GET['api'] === 'submit_response' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = trim($input['id'] ?? '');
        $text = trim($input['response_text'] ?? '');

        if (empty($id) || empty($text)) {
            echo json_encode(['success' => false, 'error' => 'ID and response text are required']);
            exit;
        }

        $ok = RequestQueue::submitResponse($id, $text);
        echo json_encode(['success' => $ok]);
        exit;
    }

    // 2. Poll pending / active requests and settings
    if ($_GET['api'] === 'list') {
        $items = RequestQueue::listRequests(50);
        $autoEnabled = RequestQueue::isAutoResponseEnabled();
        echo json_encode([
            'success' => true,
            'requests' => $items,
            'auto_response_enabled' => $autoEnabled
        ]);
        exit;
    }

    // 3. Toggle auto response on/off
    if ($_GET['api'] === 'toggle_auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $enabled = !empty($input['enabled']);
        RequestQueue::setAutoResponseEnabled($enabled);
        echo json_encode(['success' => true, 'auto_response_enabled' => $enabled]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown API action']);
    exit;
}

// Render Request/Response Dashboard View
$requests = RequestQueue::listRequests(50);
$autoResponseEnabled = RequestQueue::isAutoResponseEnabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests & Responses &bull; WhatsApp Connector</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= CM_VERSION ?>">
    <style>
        :root {
            --bg-page: #0b1329;
            --bg-card: #152238;
            --bg-card-hover: #1c2d4a;
            --accent: #25d366;
            --accent-hover: #1ebd5a;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #243553;
        }
        body {
            background: var(--bg-page);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 1.5rem;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .header h1 {
            font-size: 1.5rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: #38bdf8;
        }
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(37, 211, 102, 0.15);
            color: var(--accent);
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .pulse {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(37, 211, 102, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
        }
        .req-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1.25rem;
        }
        .req-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            transition: border-color 0.2s, background 0.2s;
        }
        .req-card.is-pending {
            border: 2px solid #eab308;
            background: #1a2215;
            box-shadow: 0 0 15px rgba(234, 179, 8, 0.15);
        }
        .req-card.is-answered {
            border-color: #10b981;
        }
        .req-card.is-fallback {
            border-color: #64748b;
            opacity: 0.85;
        }
        .img-container {
            background: #060b17;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem;
            text-align: center;
            min-height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .img-container img {
            max-width: 100%;
            max-height: 140px;
            border-radius: 4px;
            object-fit: contain;
        }
        .status-pill {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }
        .pill-pending { background: #ca8a04; color: white; }
        .pill-answered { background: #059669; color: white; }
        .pill-fallback { background: #475569; color: #cbd5e1; }
        .input-group {
            display: flex;
            gap: 0.5rem;
        }
        .input-text {
            flex: 1;
            background: #070d19;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: white;
            padding: 0.6rem 0.75rem;
            font-size: 1rem;
            outline: none;
        }
        .input-text:focus {
            border-color: #38bdf8;
        }
        .btn-send {
            background: var(--accent);
            color: #000;
            font-weight: 700;
            border: none;
            padding: 0.6rem 1.1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-send:hover {
            background: var(--accent-hover);
        }
        .timer-bar {
            height: 4px;
            background: #334155;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        .timer-fill {
            height: 100%;
            background: #eab308;
            transition: width 1s linear;
        }
        /* Toggle Switch */
        .switch-wrap {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: #101c30;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: 0.85rem;
            user-select: none;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            min-height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #334155;
            transition: .25s;
            border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .25s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: var(--accent);
        }
        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>💬 WhatsApp Connector &bull; Requests</h1>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0.25rem 0 0 0;">
                    Incoming image requests appear here live. Type and send your response; if untouched within the window (5s–30s), the automatic solver provides the answer.
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <label class="switch-wrap" title="When ON, system automatically provides an answer if no human responds. When OFF, unanswered requests fail.">
                    <span style="font-weight: 600; color: #cbd5e1;">Auto Response:</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="autoResponseToggle" <?= $autoResponseEnabled ? 'checked' : '' ?> onchange="toggleAutoResponse(this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                    <span id="autoResponseLabel" style="font-weight: 700; color: <?= $autoResponseEnabled ? '#25d366' : '#94a3b8' ?>;">
                        <?= $autoResponseEnabled ? 'ON' : 'OFF' ?>
                    </span>
                </label>
                <div class="live-badge">
                    <span class="pulse"></span> Live Listening
                </div>
            </div>
        </div>

        <div class="req-grid" id="requestsGrid">
            <!-- Dynamically populated or rendered items -->
            <?php if (empty($requests)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📥</div>
                    <div style="font-size: 1.1rem; font-weight: 600;">No requests received yet</div>
                    <p style="font-size: 0.85rem; margin-top: 0.25rem;">
                        Send an image to <code>sendwhatsapp.php</code> to see incoming requests pop up here in real time.
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($requests as $r): ?>
                    <div class="req-card is-<?= htmlspecialchars($r['status']) ?>" id="card-<?= htmlspecialchars($r['id']) ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">
                                <?= htmlspecialchars($r['id']) ?>
                            </span>
                            <span class="status-pill pill-<?= htmlspecialchars($r['status']) ?>">
                                <?= htmlspecialchars($r['status']) ?>
                            </span>
                        </div>

                        <div class="img-container">
                            <img src="requests.php?action=view_image&id=<?= urlencode($r['id']) ?>" alt="Request Image">
                        </div>

                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            Received: <?= htmlspecialchars($r['created_at']) ?>
                        </div>

                        <?php if ($r['status'] === 'pending'): ?>
                            <?php if ($autoResponseEnabled): ?>
                                <div class="timer-bar">
                                    <div class="timer-fill" id="timer-<?= htmlspecialchars($r['id']) ?>" style="width: 100%;"></div>
                                </div>
                            <?php else: ?>
                                <div style="font-size: 0.75rem; color: #38bdf8; display: flex; align-items: center; gap: 0.35rem; margin-top: 0.25rem;">
                                    <span>⏳ Waiting for manual response (No time limit)</span>
                                </div>
                            <?php endif; ?>
                            <div class="input-group">
                                <input type="text" class="input-text" id="input-<?= htmlspecialchars($r['id']) ?>" placeholder="Type response text..." autofocus>
                                <button class="btn-send" onclick="sendResponse('<?= htmlspecialchars($r['id']) ?>')">Send</button>
                            </div>
                        <?php else: ?>
                            <div style="background: rgba(0,0,0,0.3); border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.95rem;">
                                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Response (<?= htmlspecialchars($r['source'] ?? 'unknown') ?>):</span>
                                <strong style="color: <?= $r['source'] === 'manual' ? '#34d399' : '#94a3b8' ?>;">
                                    <?= htmlspecialchars($r['response_text'] ?? '') ?>
                                </strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Submit response manually
        async function sendResponse(id) {
            const input = document.getElementById('input-' + id);
            if (!input || !input.value.trim()) return;

            const text = input.value.trim();
            input.disabled = true;

            try {
                const res = await fetch('requests.php?api=submit_response', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, response_text: text })
                });
                const data = await res.json();
                if (data.success) {
                    refreshList();
                } else {
                    alert('Error: ' + (data.error || 'Failed to submit'));
                    input.disabled = false;
                }
            } catch (err) {
                alert('Network error: ' + err.message);
                input.disabled = false;
            }
        }

        // Toggle Auto Response
        async function toggleAutoResponse(enabled) {
            const label = document.getElementById('autoResponseLabel');
            if (label) {
                label.textContent = enabled ? 'ON' : 'OFF';
                label.style.color = enabled ? '#25d366' : '#94a3b8';
            }

            try {
                await fetch('requests.php?api=toggle_auto', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ enabled: enabled })
                });
            } catch (err) {
                console.error('Failed to toggle auto response:', err);
            }
        }

        // Poll list every 1.5 seconds to pick up new incoming requests
        async function refreshList() {
            try {
                const res = await fetch('requests.php?api=list');
                const data = await res.json();
                if (data.success && data.requests) {
                    renderRequests(data.requests);
                    // Sync toggle if changed remotely
                    const toggle = document.getElementById('autoResponseToggle');
                    const label = document.getElementById('autoResponseLabel');
                    if (toggle && data.auto_response_enabled !== undefined) {
                        if (document.activeElement !== toggle) {
                            toggle.checked = data.auto_response_enabled;
                            if (label) {
                                label.textContent = data.auto_response_enabled ? 'ON' : 'OFF';
                                label.style.color = data.auto_response_enabled ? '#25d366' : '#94a3b8';
                            }
                        }
                    }
                }
            } catch (e) {}
        }

        function renderRequests(items) {
            const grid = document.getElementById('requestsGrid');
            if (!grid) return;

            if (items.length === 0) {
                grid.innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                        <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📥</div>
                        <div style="font-size: 1.1rem; font-weight: 600;">No requests received yet</div>
                        <p style="font-size: 0.85rem; margin-top: 0.25rem;">
                            Send an image to <code>sendwhatsapp.php</code> to see incoming requests pop up here in real time.
                        </p>
                    </div>`;
                return;
            }

            // Keep focus if an input is being typed in
            const activeElementId = document.activeElement ? document.activeElement.id : null;
            const activeValue = document.activeElement && document.activeElement.tagName === 'INPUT' ? document.activeElement.value : '';

            // Sync toggle if changed remotely
            const isAutoOn = toggle ? toggle.checked : true;

            let html = '';
            for (const r of items) {
                const isPending = r.status === 'pending';
                const pillClass = r.status === 'pending' ? 'pill-pending' : (r.status === 'answered' ? 'pill-answered' : 'pill-fallback');
                
                let actionHtml = '';
                if (isPending) {
                    const waitInfoHtml = isAutoOn
                        ? `<div class="timer-bar"><div class="timer-fill" style="width: 100%;"></div></div>`
                        : `<div style="font-size: 0.75rem; color: #38bdf8; display: flex; align-items: center; gap: 0.35rem; margin-top: 0.25rem;">⏳ Waiting for manual response (No time limit)</div>`;

                    actionHtml = `
                        ${waitInfoHtml}
                        <div class="input-group">
                            <input type="text" class="input-text" id="input-${r.id}" placeholder="Type response text..." value="${activeElementId === 'input-' + r.id ? activeValue : ''}">
                            <button class="btn-send" onclick="sendResponse('${r.id}')">Send</button>
                        </div>`;
                } else {
                    const color = r.source === 'manual' ? '#34d399' : '#94a3b8';
                    actionHtml = `
                        <div style="background: rgba(0,0,0,0.3); border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.95rem;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Response (${r.source || 'auto'}):</span>
                            <strong style="color: ${color};">${escapeHtml(r.response_text || '')}</strong>
                        </div>`;
                }

                html += `
                    <div class="req-card is-${r.status}" id="card-${r.id}">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">${r.id}</span>
                            <span class="status-pill ${pillClass}">${r.status}</span>
                        </div>
                        <div class="img-container">
                            <img src="requests.php?action=view_image&id=${encodeURIComponent(r.id)}" alt="Request Image">
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Received: ${r.created_at}</div>
                        ${actionHtml}
                    </div>`;
            }

            grid.innerHTML = html;

            // Restore focus
            if (activeElementId && document.getElementById(activeElementId)) {
                const el = document.getElementById(activeElementId);
                el.focus();
                el.selectionStart = el.selectionEnd = el.value.length;
            }
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        setInterval(refreshList, 1500);
    </script>
</body>
</html>
