<?php
require 'config.php';

// ── QR CODE REDIRECT HANDLER ─────────────────────────────────────────────
// If request matches /p/<uuid>, log the scan and redirect to the destination.
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/p/([a-f0-9]+)$#i', $requestUri, $m)) {
    $uuid = $m[1];

    // Fetch QR code from DB
    $stmt = $db->prepare("SELECT * FROM products WHERE uuid = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$uuid]);
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        die('QR code not found.');
    }

    if (!$item['is_active']) {
        if (DISABLED_REDIRECT_URL) {
            header('Location: ' . DISABLED_REDIRECT_URL, true, 302);
        } else {
            http_response_code(410);
            include THEME_PATH . '/qr-disabled.php';
        }
        exit;
    }

    // ── Scam Protection: skip logging if this device has spammed in the last hour ──
        $stmt = $db->prepare("SELECT COUNT(*) FROM scans WHERE ip_address = ? AND user_agent = ? AND scanned_at > datetime('now', '-1 hour')");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
        $recentCount = (int)$stmt->fetchColumn();

        if ($recentCount < 10) {
            // Log the scan
            $stmt = $db->prepare("INSERT INTO scans (product_uuid, ip_address, user_agent, scan_status) VALUES (?, ?, ?, 'success')");
            $stmt->execute([
                $uuid,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ]);
        }

    // Determine destination and redirect
    if ($item['type'] === 'url') {
        $dest = $item['target_data'];
    } else {
        // For non-URL types, redirect to the base URL (user would need app-based scan)
        $dest = BASE_URL;
    }

    header('Location: ' . $dest, true, 302);
    exit;
}

require_auth();

define('PAGE_TITLE', 'Dashboard | Tracking LaB');

const ALLOWED_TYPES = ['url', 'phone', 'map', 'vcard', 'wifi', 'sms', 'email', 'social'];

// --- HANDLE FORM SUBMISSIONS & ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    verify_csrf();

    // Action: Add New QR Code
    if ($_POST['action'] === 'add') {
        $uuid  = bin2hex(random_bytes(6));
        $title = trim($_POST['title'] ?? '');
        $type  = $_POST['type'] ?? '';

        if ($title === '' || !in_array($type, ALLOWED_TYPES, true)) {
            http_response_code(400);
            die('Invalid input.');
        }

        // Construct target data based on type
        $target = '';
        if ($type === 'vcard') {
            $target = json_encode([
                'fname'   => trim($_POST['v_fname']    ?? ''),
                'lname'   => trim($_POST['v_lname']    ?? ''),
                'phone'   => trim($_POST['v_phone']    ?? ''),
                'email'   => trim($_POST['v_email']    ?? ''),
                'company' => trim($_POST['v_company']  ?? ''),
            ]);
        } elseif ($type === 'wifi') {
            $target = json_encode([
                'ssid' => trim($_POST['wifi_ssid'] ?? ''),
                'pass' => trim($_POST['wifi_pass'] ?? ''),
                'enc'  => trim($_POST['wifi_enc']  ?? 'WPA'),
            ]);
        } elseif ($type === 'sms') {
            $target = json_encode([
                'phone' => trim($_POST['sms_phone'] ?? ''),
                'body'  => trim($_POST['sms_body']  ?? ''),
            ]);
        } elseif ($type === 'email') {
            $target = json_encode([
                'email'   => trim($_POST['email_addr'] ?? ''),
                'subject' => trim($_POST['email_sub']  ?? ''),
                'body'    => trim($_POST['email_body'] ?? ''),
            ]);
        } else {
            $target = trim($_POST['target'] ?? '');
        }

        // Handle logo upload with MIME validation
        $logoPath = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
            $finfo        = new finfo(FILEINFO_MIME_TYPE);
            $mime         = $finfo->file($_FILES['logo']['tmp_name']);

            if (isset($allowedMimes[$mime])) {
                $ext      = $allowedMimes[$mime];
                $filename = 'logo_' . $uuid . '.' . $ext;
                $destPath = LOGO_DIR . '/' . $filename;
                if (!is_dir(LOGO_DIR)) {
                    mkdir(LOGO_DIR, 0755, true);
                }
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $destPath)) {
                    $logoPath = $filename;
                } else {
                    error_log("File upload error: Unable to move file to " . $destPath);
                }
            }
        }

        $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data, logo_path, dot_modules, logo_frame, color_body, color_finders, color_bg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$uuid, $title, $type, $target, $logoPath,
            (int)($_POST['dot_modules'] ?? 0),
            (int)($_POST['logo_frame'] ?? 0),
            $_POST['color_body'] ?? '#000000',
            $_POST['color_finders'] ?? '#000000',
            $_POST['color_bg'] ?? '#ffffff',
        ]);

        header("Location: " . BASE_URL);
        exit;
    }

    // Action: Edit QR Code (update title + target data only — type never changes)
    if ($_POST['action'] === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($id <= 0 || $title === '') {
            http_response_code(400);
            die('Invalid input.');
        }

        // Fetch current type so we know how to rebuild target_data
        $row = $db->prepare("SELECT type FROM products WHERE id = ? AND is_deleted = 0");
        $row->execute([$id]);
        $current = $row->fetch();
        if (!$current) { http_response_code(404); die('Not found.'); }

        $type = $current['type'];
        $target = '';
        if ($type === 'vcard') {
            $target = json_encode([
                'fname'   => trim($_POST['v_fname']   ?? ''),
                'lname'   => trim($_POST['v_lname']   ?? ''),
                'phone'   => trim($_POST['v_phone']   ?? ''),
                'email'   => trim($_POST['v_email']   ?? ''),
                'company' => trim($_POST['v_company'] ?? ''),
            ]);
        } elseif ($type === 'wifi') {
            $target = json_encode([
                'ssid' => trim($_POST['wifi_ssid'] ?? ''),
                'pass' => trim($_POST['wifi_pass'] ?? ''),
                'enc'  => trim($_POST['wifi_enc']  ?? 'WPA'),
            ]);
        } elseif ($type === 'sms') {
            $target = json_encode([
                'phone' => trim($_POST['sms_phone'] ?? ''),
                'body'  => trim($_POST['sms_body']  ?? ''),
            ]);
        } elseif ($type === 'email') {
            $target = json_encode([
                'email'   => trim($_POST['email_addr'] ?? ''),
                'subject' => trim($_POST['email_sub']  ?? ''),
                'body'    => trim($_POST['email_body'] ?? ''),
            ]);
        } else {
            $target = trim($_POST['target'] ?? '');
        }

        $stmt = $db->prepare("UPDATE products SET title = ?, target_data = ?, dot_modules = ?, logo_frame = ?, color_body = ?, color_finders = ?, color_bg = ? WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$title, $target,
            (int)($_POST['dot_modules'] ?? 0),
            (int)($_POST['logo_frame'] ?? 0),
            $_POST['color_body'] ?? '#000000',
            $_POST['color_finders'] ?? '#000000',
            $_POST['color_bg'] ?? '#ffffff',
            $id,
        ]);

        header("Location: " . BASE_URL);
        exit;
    }

    // Action: Toggle Active Status
    if ($_POST['action'] === 'toggle') {
        $stmt = $db->prepare("UPDATE products SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        exit;
    }

    // Action: Soft Delete
    if ($_POST['action'] === 'delete') {
        $stmt = $db->prepare("UPDATE products SET is_active = 0, is_deleted = 1 WHERE id = ?");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        exit;
    }

    // Action: Restore Deleted
    if ($_POST['action'] === 'restore') {
        $stmt = $db->prepare("UPDATE products SET is_deleted = 0, is_active = 1 WHERE id = ?");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        header("Location: " . BASE_URL);
        exit;
    }
}

// --- FETCH DATA ---
$showTrash = isset($_GET['trash']);

if ($showTrash) {
    $products = $db->query("
        SELECT p.*, (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
        FROM products p
        WHERE is_deleted = 1
        ORDER BY created_at DESC
    ")->fetchAll();
} else {
    $products = $db->query("
        SELECT p.*, (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count
        FROM products p
        WHERE is_deleted = 0
        ORDER BY created_at DESC
    ")->fetchAll();
}

// ── URL GROUP STATS FOR CARDS VIEW (non-trash only) ─────────────────────────
$urlGroups = [];
$maxGroupScans = 0;
if (!$showTrash) {
    $stmt = $db->query("
        SELECT
            p.target_data,
            COUNT(DISTINCT p.uuid) AS qr_count,
            COUNT(s.id)            AS total_scans,
            MAX(s.scanned_at)      AS last_scan
        FROM products p
        LEFT JOIN scans s ON p.uuid = s.product_uuid
        WHERE p.type = 'url'
          AND p.is_deleted = 0
          AND p.target_data != ''
        GROUP BY p.target_data
        ORDER BY total_scans DESC, p.target_data ASC
    ");
    $urlGroups = $stmt->fetchAll();
    $maxGroupScans = $urlGroups ? max(array_column($urlGroups, 'total_scans')) : 0;
}

$csrfToken = csrf_token();

// --- RENDER VIEW ---
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>
<style>
    /* ── Dashboard Client Cards ─────────────────────────────────────────── */
    .dashboard-cards {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }
    @media (min-width: 768px) {
        .dashboard-cards {
            grid-template-columns: 1fr 1fr;
        }
    }
    .card-client {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 10px;
        transition: transform 0.2s, border-color 0.2s;
        overflow: hidden;
    }
    .card-client:hover {
        transform: translateY(-2px);
        border-color: var(--accent);
    }
    .card-client-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .card-client-url {
        font-weight: 700;
        font-size: 1.1rem;
        word-break: break-all;
        color: var(--accent);
    }
    .card-client-stats {
        display: flex;
        gap: 20px;
    }
    .card-stat {
        text-align: center;
    }
    .card-stat-num {
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--text);
        display: block;
    }
    .card-stat-lbl {
        font-size: 0.7rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .card-bar {
        width: 100%;
        height: 6px;
        background: #2a2a2a;
        border-radius: 3px;
        overflow: hidden;
    }
    .card-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent), #ff9e42);
        border-radius: 3px;
        transition: width 0.4s ease;
        min-width: 1px;
    }
    .card-client-meta {
        font-size: 0.85rem;
        color: #888;
    }
    .card-view-link {
        display: inline-block;
        padding: 8px 16px;
        background: var(--accent);
        color: #fff;
        text-decoration: none;
        border-radius: 5px;
        font-weight: 600;
        font-size: 0.9rem;
        text-align: center;
        transition: opacity 0.2s;
    }
    .card-view-link:hover {
        opacity: 0.9;
    }

    /* ── Mobile Card Sizing ───────────────────────────────────────────────── */
    @media (max-width: 480px) {
        .card-client-stats { flex-direction: column; gap: 8px; }
        .card-stat-num { font-size: 1.1rem; }
        .card-client-url { font-size: 0.95rem; }
        .card-view-link { width: 100%; }
    }
</style>

<!-- ── SEARCH BAR ────────────────────────────────────────────────────────────── -->
<?php if (!$showTrash): ?>
<div style="margin-bottom:20px;">
    <input type="text" id="clientSearch" onkeyup="filterCards()" placeholder="&#128269; Search clients or target URLs..." style="width:100%; padding:14px 18px; background:#1a1a1a; border:1px solid #333; border-radius:8px; color:var(--text); font-size:1rem; box-sizing:border-box;">
</div>
<?php endif; ?>

<?php if ($showTrash): ?>
<div style="margin-bottom:15px; display:flex; align-items:center; gap:15px;">
    <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-sm" style="background:#444;">&larr; Back to Dashboard</a>
    <h2 style="margin:0; color:#ff4444;">Trash</h2>
</div>

<div class="qr-list">
    <?php if (empty($products)): ?>
        <p style="text-align:center; color:#666; padding:40px 0;">Trash is empty.</p>
    <?php endif; ?>
    <?php foreach($products as $p): ?>
    <div class="qr-item">
        <div class="qr-info">
            <h3><?= htmlspecialchars($p['title']) ?> <span style="font-size:0.7em; opacity:0.6">[<?= strtoupper(htmlspecialchars($p['type'])) ?>]</span></h3>
            <div class="qr-meta">Created: <?= date('M d, Y', strtotime($p['created_at'])) ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#28a745;">Restore</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>

<!-- ── HEADER ACTION BAR ─────────────────────────────────────────────────────── -->
<div style="margin-bottom:15px; display:flex; justify-content:flex-end; align-items:center;">
    <a href="?trash" class="btn btn-sm" style="background:#444;" title="View deleted QR codes">&#128465; Trash (<?= (int)$db->query("SELECT COUNT(*) FROM products WHERE is_deleted = 1")->fetchColumn() ?>)</a>
</div>

<!-- ── URL GROUP CARDS ───────────────────────────────────────────────────────── -->
<div class="dashboard-cards" id="cardGrid">
    <?php if (empty($urlGroups)): ?>
        <p style="text-align:center; color:#666; padding:40px 0; grid-column:1/-1;">
            No QR codes yet. Click "+ New QR Code" to create one.
        </p>
    <?php endif; ?>

    <?php foreach ($urlGroups as $group):
        $barPct = $maxGroupScans > 0 ? round(($group['total_scans'] / $maxGroupScans) * 100) : 0;
        $displayName = parse_url($group['target_data'], PHP_URL_HOST) ?: $group['target_data'];
        $isActive = (bool)$group['total_scans'];
    ?>
    <div class="card-client" data-search="<?= htmlspecialchars(strtolower($displayName . ' ' . $group['target_data'])) ?>">
        <div class="card-client-body">
            <div class="card-client-url"><?= htmlspecialchars(mb_substr($displayName, 0, 60)) ?></div>
            <div class="card-client-stats">
                <div class="card-stat">
                    <span class="card-stat-num"><?= (int)$group['qr_count'] ?></span>
                    <span class="card-stat-lbl">QR codes</span>
                </div>
                <div class="card-stat">
                    <span class="card-stat-num"><?= (int)$group['total_scans'] ?></span>
                    <span class="card-stat-lbl">scans</span>
                </div>
            </div>
            <div class="card-bar">
                <div class="card-bar-fill" style="width:<?= $barPct ?>%;"></div>
            </div>
            <div class="card-client-meta">
                Last scan: <?= $group['last_scan'] ? date('M d', strtotime($group['last_scan'])) : 'Never' ?>
            </div>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/client_analytics.php?client=<?= urlencode($group['target_data']) ?>" class="card-view-link">
                View Analytics &rarr;
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── Add Modal ──────────────────────────────────────────────────────────────── -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('addModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Add QR Code</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="add">
            <label>Title</label>
            <input type="text" name="title" required placeholder="Product Name" maxlength="255">
            <label>Type</label>
            <select name="type" id="typeSelect" onchange="toggleFields('add')">
                <option value="url">Website URL</option>
                <option value="phone">Phone Number</option>
                <option value="map">Map Location</option>
                <option value="vcard">vCard Contact</option>
                <option value="wifi">Wi-Fi Network</option>
                <option value="sms">SMS Message</option>
                <option value="email">Email Message</option>
                <option value="social">Social Media</option>
            </select>

            <div id="add-field-general" class="type-fields" style="display:block;"><input type="text" name="target" placeholder="https://example.com" maxlength="2048"></div>
            <div id="add-field-vcard" class="type-fields">
                <input type="text" name="v_fname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" placeholder="Company" maxlength="255">
            </div>
            <div id="add-field-wifi" class="type-fields">
                <input type="text" name="wifi_ssid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" placeholder="Password" maxlength="63">
                <select name="wifi_enc"><option value="WPA">WPA/WPA2</option><option value="WEP">WEP</option><option value="nopass">No Encryption</option></select>
            </div>
            <div id="add-field-sms" class="type-fields">
                <input type="tel" name="sms_phone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <div id="add-field-email" class="type-fields">
                <input type="email" name="email_addr" placeholder="Recipient" maxlength="255">
                <input type="text" name="email_sub" placeholder="Subject" maxlength="255">
                <textarea name="email_body" placeholder="Body" maxlength="2000"></textarea>
            </div>

            <label>Embedded Logo (Optional — PNG or JPG only)</label>
                        <input type="file" name="logo" accept="image/png, image/jpeg">

                        <!-- ── Design Options (collapsible) ────────────────────────────── -->
                        <div class="design-options" style="margin-top:16px; border:1px solid #333; border-radius:8px; overflow:hidden;">
                            <div class="design-toggle" onclick="toggleDesign(this)"
                                 style="padding:12px 14px; background:#1a1a1a; cursor:pointer; display:flex; justify-content:space-between; align-items:center; user-select:none;">
                                <span style="font-weight:600; font-size:0.95rem;">▼ Design Options</span>
                                <span class="design-arrow" style="font-size:0.8rem; opacity:0.6;">▼</span>
                            </div>
                            <div class="design-body" style="padding:14px; display:none; border-top:1px solid #333; background:#151515;">
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;">
                                    <input type="hidden" name="dot_modules" value="0">
                                    <input type="checkbox" name="dot_modules" value="1">
                                    <span>Round modules</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px; cursor:pointer;">
                                    <input type="hidden" name="logo_frame" value="0">
                                    <input type="checkbox" name="logo_frame" value="1">
                                    <span>Logo frame (white circle)</span>
                                </label>
                                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                                    <label style="flex:1; min-width:120px;">
                                        Body color:
                                        <input type="color" name="color_body" value="#000000" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                                    </label>
                                    <label style="flex:1; min-width:120px;">
                                        Corner color:
                                        <input type="color" name="color_finders" value="#000000" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                                    </label>
                                    <label style="flex:1; min-width:120px;">
                                        Background:
                                        <input type="color" name="color_bg" value="#ffffff" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                                    </label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn" style="width:100%; margin-top:20px;">Generate QR</button>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────────── -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('editModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Edit QR Code <span id="editTypeLabel" style="font-size:0.65em; opacity:0.5; font-weight:normal;"></span></h2>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <label>Title</label>
            <input type="text" name="title" id="editTitle" required maxlength="255">

            <!-- General (url, phone, map, social) -->
            <div id="edit-field-general" class="type-fields" style="display:none;">
                <label id="editGeneralLabel">Target</label>
                <input type="text" name="target" id="editTarget" maxlength="2048">
            </div>
            <!-- vCard -->
            <div id="edit-field-vcard" class="type-fields" style="display:none;">
                <input type="text" name="v_fname" id="editFname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" id="editLname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" id="editVphone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" id="editVemail" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" id="editVcompany" placeholder="Company" maxlength="255">
            </div>
            <!-- WiFi -->
            <div id="edit-field-wifi" class="type-fields" style="display:none;">
                <input type="text" name="wifi_ssid" id="editSsid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" id="editPass" placeholder="Password" maxlength="63">
                <select name="wifi_enc" id="editEnc">
                    <option value="WPA">WPA/WPA2</option>
                    <option value="WEP">WEP</option>
                    <option value="nopass">No Encryption</option>
                </select>
            </div>
            <!-- SMS -->
            <div id="edit-field-sms" class="type-fields" style="display:none;">
                <input type="tel" name="sms_phone" id="editSmsPhone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" id="editSmsBody" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <!-- Email -->
            <div id="edit-field-email" class="type-fields" style="display:none;">
                <input type="email" name="email_addr" id="editEmailAddr" placeholder="Recipient" maxlength="255">
                <input type="text" name="email_sub" id="editEmailSub" placeholder="Subject" maxlength="255">
                <textarea name="email_body" id="editEmailBody" placeholder="Body" maxlength="2000"></textarea>
            </div>

            <button type="submit" class="btn" style="width:100%; margin-top:20px;">Save Changes</button>

            <!-- ── Design Options (collapsible) for Edit ───────────────────── -->
            <div class="design-options" style="margin-top:16px; border:1px solid #333; border-radius:8px; overflow:hidden;">
                <div class="design-toggle" onclick="toggleDesign(this)"
                     style="padding:12px 14px; background:#1a1a1a; cursor:pointer; display:flex; justify-content:space-between; align-items:center; user-select:none;">
                    <span style="font-weight:600; font-size:0.95rem;">▼ Design Options</span>
                    <span class="design-arrow" style="font-size:0.8rem; opacity:0.6;">▼</span>
                </div>
                <div class="design-body" style="padding:14px; display:none; border-top:1px solid #333; background:#151515;">
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;">
                        <input type="hidden" name="dot_modules" value="0">
                        <input type="checkbox" name="dot_modules" value="1" id="editDotModules">
                        <span>Round modules</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:14px; cursor:pointer;">
                        <input type="hidden" name="logo_frame" value="0">
                        <input type="checkbox" name="logo_frame" value="1" id="editLogoFrame">
                        <span>Logo frame (white circle)</span>
                    </label>
                    <div style="display:flex; flex-wrap:wrap; gap:12px;">
                        <label style="flex:1; min-width:120px;">
                            Body color:
                            <input type="color" name="color_body" id="editColorBody" value="#000000" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                        </label>
                        <label style="flex:1; min-width:120px;">
                            Corner color:
                            <input type="color" name="color_finders" id="editColorFinders" value="#000000" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                        </label>
                        <label style="flex:1; min-width:120px;">
                            Background:
                            <input type="color" name="color_bg" id="editColorBg" value="#ffffff" style="display:block; width:100%; height:36px; padding:2px; border:1px solid #444; border-radius:4px; background:transparent; cursor:pointer;">
                        </label>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Stats Modal ────────────────────────────────────────────────────────────── -->
<div id="statsModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('statsModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Scan History</h2>
        <div id="statsContent" style="max-height: 400px; overflow-y: auto;">Loading...</div>
    </div>
</div>

<!-- ── QR Code Modal ──────────────────────────────────────────────────────────── -->
<div id="qrModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <svg class="close-icon" onclick="closeModal('qrModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2 id="qrTitle">QR Code</h2>
                <img id="qrImage" src="" style="width: 250px; height: 250px; border: 5px solid white; margin: 20px 0;" alt="QR Code">
                <div style="margin: 10px auto; max-width: 400px; text-align: left;">
                    <label style="font-size: 0.85em; color: #aaa;">Tracking URL (paste into QRCodeMonkey):</label>
                    <input type="text" id="qrUrl" readonly onclick="this.select(); navigator.clipboard?.writeText(this.value)"
                           style="width: 100%; padding: 8px; background: #2a2a2a; border: 1px solid #444; color: var(--accent); border-radius: 4px; font-size: 0.85em; cursor: pointer; box-sizing: border-box;">
                </div>
                <div style="display:flex; gap:10px; justify-content:center;">
            <a id="dlPng" href="#" download class="btn btn-sm">Download PNG</a>
            <a id="dlJpg" href="#" download class="btn btn-sm">Download JPG</a>
            <button onclick="printQR()" class="btn btn-sm" style="background: #444;">Print</button>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────────── -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <svg class="close-icon" onclick="closeModal('deleteModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Are you sure?</h2>
        <p>This will move the QR code to Trash. You can restore it later.</p>
        <div style="margin-top: 20px; display:flex; gap:10px; justify-content:center;">
            <button id="confirmDeleteBtn" class="btn btn-danger">Yes, Delete</button>
            <button onclick="closeModal('deleteModal')" class="btn" style="background: #444;">Cancel</button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

// ── Client card search filter ─────────────────────────────────────────────
function filterCards() {
    const query = document.getElementById('clientSearch').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.card-client');
    cards.forEach(card => {
        const searchData = card.getAttribute('data-search') || '';
        card.style.display = (!query || searchData.includes(query)) ? '' : 'none';
    });
}

// ── Modal utilities ──────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
});

// ── Design Options toggle ────────────────────────────────────────────────────
function toggleDesign(el) {
    const body = el.parentElement.querySelector('.design-body');
    const arrow = el.querySelector('.design-arrow');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        arrow.textContent = '▲';
    } else {
        body.style.display = 'none';
        arrow.textContent = '▼';
    }
}

// ── Print ────────────────────────────────────────────────────────────────────
function printQR() {
    const win = window.open('');
    win.document.write('<html><body style="text-align:center;"><h2 style="font-family:sans-serif">' +
        document.getElementById('qrTitle').innerText +
        '</h2><img src="' + document.getElementById('qrImage').src +
        '" onload="window.print();window.close()" /></body></html>');
    win.document.close();
}

// ── Add form type switcher ───────────────────────────────────────────────────
function toggleFields(prefix) {
    document.querySelectorAll('#addModal .type-fields').forEach(e => e.style.display = 'none');
    const type = document.getElementById('typeSelect').value;
    const generalInput = document.querySelector('#add-field-general input');
    if (['vcard','wifi','sms','email'].includes(type)) {
        document.getElementById('add-field-' + type).style.display = 'block';
    } else {
        document.getElementById('add-field-general').style.display = 'block';
        generalInput.type = (type === 'url' || type === 'social') ? 'url' : 'text';
        if (type === 'phone')      generalInput.placeholder = '+15550000000';
        else if (type === 'map')   generalInput.placeholder = '123 Main St, City, ST';
        else                       generalInput.placeholder = 'https://...';
    }
}

// ── Edit modal ───────────────────────────────────────────────────────────────
function openEditModal(data) {
    // Hide all edit fields
    document.querySelectorAll('#editModal .type-fields').forEach(e => e.style.display = 'none');

    document.getElementById('editId').value    = data.id;
    document.getElementById('editTitle').value = data.title;
    document.getElementById('editTypeLabel').textContent = '[' + data.type.toUpperCase() + ']';

    const type = data.type;
    let target = data.target_data;
    let parsed = null;
    try { parsed = JSON.parse(target); } catch(e) {}

    if (type === 'vcard' && parsed) {
        document.getElementById('edit-field-vcard').style.display = 'block';
        document.getElementById('editFname').value    = parsed.fname    || '';
        document.getElementById('editLname').value    = parsed.lname    || '';
        document.getElementById('editVphone').value   = parsed.phone    || '';
        document.getElementById('editVemail').value   = parsed.email    || '';
        document.getElementById('editVcompany').value = parsed.company  || '';
    } else if (type === 'wifi' && parsed) {
        document.getElementById('edit-field-wifi').style.display = 'block';
        document.getElementById('editSsid').value = parsed.ssid || '';
        document.getElementById('editPass').value = parsed.pass || '';
        const encSel = document.getElementById('editEnc');
        for (let opt of encSel.options) { if (opt.value === parsed.enc) { opt.selected = true; break; } }
    } else if (type === 'sms' && parsed) {
        document.getElementById('edit-field-sms').style.display = 'block';
        document.getElementById('editSmsPhone').value = parsed.phone || '';
        document.getElementById('editSmsBody').value  = parsed.body  || '';
    } else if (type === 'email' && parsed) {
        document.getElementById('edit-field-email').style.display = 'block';
        document.getElementById('editEmailAddr').value = parsed.email   || '';
        document.getElementById('editEmailSub').value  = parsed.subject || '';
        document.getElementById('editEmailBody').value = parsed.body    || '';
    } else {
        // url, phone, map, social
        document.getElementById('edit-field-general').style.display = 'block';
        const lbl = { url:'Target URL', phone:'Phone Number', map:'Map Address', social:'Profile URL' };
        document.getElementById('editGeneralLabel').textContent = lbl[type] || 'Target';
        document.getElementById('editTarget').value = target;
            }

            // Pre-fill design options
            document.getElementById('editDotModules').checked  = data.dot_modules == 1;
            document.getElementById('editLogoFrame').checked   = data.logo_frame == 1;
            document.getElementById('editColorBody').value     = data.color_body || '#000000';
            document.getElementById('editColorFinders').value  = data.color_finders || '#000000';
            document.getElementById('editColorBg').value       = data.color_bg || '#ffffff';

            openModal('editModal');
}

// ── Toggle QR active ─────────────────────────────────────────────────────────
function toggleQR(id, csrf) {
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    fetch('index.php', { method: 'POST', body: fd });
}

// ── Get Code modal ───────────────────────────────────────────────────────────
function showQR(uuid, title) {
    const urlBase = 'generate_image.php?id=' + uuid;
    document.getElementById('qrImage').src        = urlBase + '&format=jpg';
    document.getElementById('qrTitle').innerText  = title;
    document.getElementById('dlPng').href         = urlBase + '&format=png';
    document.getElementById('dlJpg').href         = urlBase + '&format=jpg';
    document.getElementById('dlPng').setAttribute('download', title + '-QR.png');
    document.getElementById('dlJpg').setAttribute('download', title + '-QR.jpg');
    document.getElementById('qrUrl').value = window.location.origin + '/p/' + uuid;
    openModal('qrModal');
}

// ── Scan stats ───────────────────────────────────────────────────────────────
function loadStats(uuid) {
    openModal('statsModal');
    document.getElementById('statsContent').innerHTML = '<p style="text-align:center; padding:20px;">Loading geolocation data...</p>';
    fetch('api_stats.php?uuid=' + encodeURIComponent(uuid))
        .then(res => res.json())
        .then(data => {
            let html = '';
            if (data.length === 0) {
                html = '<p>No scans yet.</p>';
            } else {
                data.forEach(row => {
                    const badge = row.scan_status === 'blocked'
                        ? '<div class="scan-badge">DISABLED SCAN</div>' : '';
                    html += `
                    <div class="scan-row">
                        <div style="padding-right:10px;">
                            <div class="scan-ip">${row.ip_address}</div>
                            <div style="color:#aaa; font-size:0.85em;">${row.geo.isp || 'Unknown ISP'}</div>
                            ${badge}
                        </div>
                        <div>
                            <div style="color: var(--accent); font-weight:bold;">${row.geo.city}, ${row.geo.region}</div>
                            <div style="color:#aaa; font-size:0.85em;">${row.geo.country}</div>
                        </div>
                        <div class="scan-meta">
                            <div>${row.scanned_at}</div>
                            <div style="font-size:0.75em; opacity:0.7; margin-top:4px; word-break:break-word;">${row.user_agent}</div>
                        </div>
                    </div>`;
                });
            }
            document.getElementById('statsContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('statsContent').innerHTML = '<p style="color:red">Error loading stats.</p>';
        });
}

// ── Delete ───────────────────────────────────────────────────────────────────
let deleteId = null;
function confirmDelete(id) { deleteId = id; openModal('deleteModal'); }
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', deleteId);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('index.php', { method: 'POST', body: fd }).then(() => location.reload());
});
</script>

<?php include THEME_PATH . '/footer.php'; ?>
