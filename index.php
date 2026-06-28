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

    // Log the scan
    $stmt = $db->prepare("INSERT INTO scans (product_uuid, ip_address, user_agent, scan_status) VALUES (?, ?, ?, 'success')");
    $stmt->execute([
        $uuid,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    ]);

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

define('PAGE_TITLE', 'Dashboard | Tuxxin QR Track');

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

        $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data, logo_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$uuid, $title, $type, $target, $logoPath]);

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

        $stmt = $db->prepare("UPDATE products SET title = ?, target_data = ? WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$title, $target, $id]);

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

$csrfToken = csrf_token();

// --- RENDER VIEW ---
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>

<?php if ($showTrash): ?>
<div style="margin-bottom:15px; display:flex; align-items:center; gap:15px;">
    <a href="<?= htmlspecialchars(BASE_URL) ?>" class="btn btn-sm" style="background:#444;">&larr; Back to Dashboard</a>
    <h2 style="margin:0; color:#ff4444;">Trash</h2>
</div>
<?php else: ?>
<div style="margin-bottom:15px; display:flex; justify-content:flex-end;">
    <a href="?trash" class="btn btn-sm" style="background:#444;" title="View deleted QR codes">&#128465; Trash</a>
</div>
<?php endif; ?>

<div class="qr-list">
    <?php if (empty($products)): ?>
        <p style="text-align:center; color:#666; padding:40px 0;">
            <?= $showTrash ? 'Trash is empty.' : 'No QR codes yet. Click "+ New QR Code" to create one.' ?>
        </p>
    <?php endif; ?>

    <?php foreach($products as $p): ?>
    <div class="qr-item">
        <div class="qr-info">
            <h3><?= htmlspecialchars($p['title']) ?> <span style="font-size:0.7em; opacity:0.6">[<?= strtoupper(htmlspecialchars($p['type'])) ?>]</span></h3>
            <div class="qr-meta">Created: <?= date('M d, Y', strtotime($p['created_at'])) ?></div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">

            <?php if ($showTrash): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#28a745;">Restore</button>
                </form>
            <?php else: ?>
                <?php if ($p['type'] === 'wifi'): ?>
                    <div class="qr-stats" style="color: #666; text-decoration: none; cursor: default;">Not Trackable</div>
                <?php else: ?>
                    <div onclick="loadStats('<?= htmlspecialchars($p['uuid']) ?>')" class="qr-stats"><?= (int)$p['scan_count'] ?> Scans</div>
                <?php endif; ?>

                <label class="switch">
                    <input type="checkbox" onchange="toggleQR(<?= (int)$p['id'] ?>, '<?= htmlspecialchars($csrfToken) ?>')" <?= $p['is_active'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>

                <button class="btn btn-sm" onclick="showQR('<?= htmlspecialchars($p['uuid']) ?>', <?= htmlspecialchars(json_encode($p['title']), ENT_QUOTES) ?>)">Get Code</button>

                <button class="btn btn-sm btn-info" onclick='openEditModal(<?= htmlspecialchars(json_encode([
                    'id'          => $p['id'],
                    'title'       => $p['title'],
                    'type'        => $p['type'],
                    'target_data' => $p['target_data'],
                ]), ENT_QUOTES) ?>)' title="Edit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293l6.5-6.5zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z"/>
                    </svg>
                </button>

                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= (int)$p['id'] ?>)" title="Delete" style="display:flex; align-items:center; padding: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                    </svg>
                </button>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>
</div>

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

// ── Modal utilities ──────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
});

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
