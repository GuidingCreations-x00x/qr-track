<?php
require __DIR__ . '/config.php';
require_auth();

header('X-Robots-Tag: noindex, nofollow');

define('PAGE_TITLE', 'Analytics Dashboard | Tracking LaB');

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

        // Redirect back to same client
        $clientParam = isset($_POST['client_target']) ? '?client=' . urlencode($_POST['client_target']) : '';
        header("Location: " . BASE_URL . "/client_analytics.php" . $clientParam);
        exit;
    }

    // Action: Edit QR Code
    if ($_POST['action'] === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($id <= 0 || $title === '') {
            http_response_code(400);
            die('Invalid input.');
        }

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

        $clientParam = isset($_POST['client_target']) ? '?client=' . urlencode($_POST['client_target']) : '';
        header("Location: " . BASE_URL . "/client_analytics.php" . $clientParam);
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
        $clientParam = isset($_POST['client_target']) ? '?client=' . urlencode($_POST['client_target']) : '';
        header("Location: " . BASE_URL . "/client_analytics.php" . $clientParam);
        exit;
    }
}

// ── HELPER: Parse user agent for device type ──────────────────────────────
function detectDevice(string $ua): string {
    $ua = strtolower($ua);
    if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) return 'iOS';
    if (str_contains($ua, 'android'))  return 'Android';
    if (str_contains($ua, 'windows'))  return 'Windows';
    if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) return 'Mac';
    if (str_contains($ua, 'linux'))    return 'Linux';
    return 'Other';
}

// ── FETCH UNIQUE CLIENTS (target URLs) ─────────────────────────────────────
$clients = $db->query("
    SELECT DISTINCT p.target_data
    FROM products p
    WHERE p.type = 'url'
      AND p.is_deleted = 0
      AND p.target_data != ''
    ORDER BY p.target_data ASC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedClient = $_GET['client'] ?? ($clients[0] ?? '');
if ($selectedClient === '' && !empty($clients)) {
    $selectedClient = $clients[0];
}

// ── FETCH ALL QR CODES FOR THIS CLIENT ─────────────────────────────────────
$qrCodes = [];
if ($selectedClient) {
    $stmt = $db->prepare("
        SELECT p.uuid, p.title, p.created_at, p.id, p.type, p.target_data, p.is_active
        FROM products p
        WHERE p.type = 'url'
          AND p.is_deleted = 0
          AND p.target_data = ?
        ORDER BY p.created_at ASC
    ");
    $stmt->execute([$selectedClient]);
    $qrCodes = $stmt->fetchAll();
}

// Build uuid list for queries
$uuidList = array_column($qrCodes, 'uuid');

// ── 30-DAY BAR CHART DATA ──────────────────────────────────────────────────
$chartLabels  = [];
$chartDatasets = [];
$chartColors  = [
    '#ff6600', '#00bcd4', '#9c27b0', '#4caf50', '#ff9800',
    '#e91e63', '#2196f3', '#8bc34a', '#f44336', '#607d8b',
    '#ff5722', '#03a9f4', '#cddc39', '#795548', '#673ab7',
];

$scanColorMap = []; // uuid => color index

if (!empty($uuidList)) {
    // Build 30-day date range
    for ($i = 29; $i >= 0; $i--) {
        $chartLabels[] = date('Y-m-d', strtotime("-{$i} days"));
    }
    $dateStart = $chartLabels[0];
    $dateEnd   = $chartLabels[29];

    // Fetch all scans for these QR codes in the last 30 days
    $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
    $params = [...$uuidList, $dateStart, $dateEnd];
    $stmt = $db->prepare("
        SELECT product_uuid, DATE(scanned_at) AS scan_date, COUNT(*) AS cnt
        FROM scans
        WHERE product_uuid IN ($placeholders)
          AND scanned_at >= ? AND scanned_at <= ? || ' 23:59:59'
        GROUP BY product_uuid, DATE(scanned_at)
        ORDER BY product_uuid, scan_date
    ");
    $stmt->execute($params);
    $scanRows = $stmt->fetchAll();

    // Build lookup: [uuid][date] => count
    $scanLookup = [];
    foreach ($scanRows as $row) {
        $scanLookup[$row['product_uuid']][$row['scan_date']] = (int)$row['cnt'];
    }

    // Build datasets
    $colorIdx = 0;
    foreach ($qrCodes as $qr) {
        $uid = $qr['uuid'];
        $scanColorMap[$uid] = $chartColors[$colorIdx % count($chartColors)];
        $data = [];
        foreach ($chartLabels as $d) {
            $data[] = $scanLookup[$uid][$d] ?? 0;
        }
        $chartDatasets[] = [
            'label' => $qr['title'] ?: 'Untitled QR',
            'data'  => $data,
            'backgroundColor' => $chartColors[$colorIdx % count($chartColors)],
            'borderColor'     => $chartColors[$colorIdx % count($chartColors)],
            'borderWidth'     => 1,
        ];
        $colorIdx++;
    }
}

// ── TOP PLACEMENTS ─────────────────────────────────────────────────────────
$topPlacements = [];
if (!empty($uuidList)) {
    $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
    $stmt = $db->prepare("
        SELECT p.uuid, p.title, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan
        FROM products p
        LEFT JOIN scans s ON p.uuid = s.product_uuid
        WHERE p.uuid IN ($placeholders)
        GROUP BY p.uuid
        ORDER BY scan_count DESC
    ");
    $stmt->execute($uuidList);
    $topPlacements = $stmt->fetchAll();
}

// ── GEOGRAPHIC DATA (placeholder + attempt ip-api.com lookup) ──────────────
$geoData = [];
$geoPlaceholder = true;

// Check if geo columns exist
$columns = $db->query("PRAGMA table_info(scans)")->fetchAll(PDO::FETCH_COLUMN, 1);
$hasGeoCols = in_array('geo_city', $columns);

if ($hasGeoCols) {
    $geoPlaceholder = false;
    if (!empty($uuidList)) {
        $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
        $stmt = $db->prepare("
            SELECT geo_city, geo_region, geo_country, COUNT(*) AS cnt
            FROM scans
            WHERE product_uuid IN ($placeholders)
              AND geo_city IS NOT NULL AND geo_city != ''
            GROUP BY geo_city, geo_region
            ORDER BY cnt DESC
            LIMIT 20
        ");
        $stmt->execute($uuidList);
        $geoData = $stmt->fetchAll();
    }
} else {
    // Attempt simple ip-api.com lookup per unique IP
    if (!empty($uuidList)) {
        $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
        $stmt = $db->prepare("
            SELECT DISTINCT ip_address
            FROM scans
            WHERE product_uuid IN ($placeholders)
              AND ip_address IS NOT NULL AND ip_address != 'unknown'
            LIMIT 10
        ");
        $stmt->execute($uuidList);
        $ips = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ips)) {
            // Query ip-api.com batch endpoint (no API key needed, 15/min limit for non-commercial)
            $batchPayload = json_encode(array_map(fn($ip) => ['query' => $ip, 'fields' => 'city,region,country,query'], $ips));
            $ch = curl_init('http://ip-api.com/batch');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $batchPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response && $httpCode === 200) {
                $geoResults = json_decode($response, true);
                if (is_array($geoResults)) {
                    // Group by city/region
                    $geoCounts = [];
                    foreach ($geoResults as $g) {
                        if (!empty($g['city']) && !empty($g['region'])) {
                            $key = $g['city'] . '|' . $g['region'];
                            $geoCounts[$key] = ($geoCounts[$key] ?? 0) + 1;
                        }
                    }
                    foreach ($geoCounts as $key => $cnt) {
                        [$city, $region] = explode('|', $key);
                        $geoData[] = ['geo_city' => $city, 'geo_region' => $region, 'cnt' => $cnt];
                    }
                    if (!empty($geoData)) {
                        $geoPlaceholder = false;
                    }
                }
            }
        }
    }
}

// ── DEVICE & OS DATA ───────────────────────────────────────────────────────
$deviceData = [];
if (!empty($uuidList)) {
    $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
    $stmt = $db->prepare("
        SELECT user_agent
        FROM scans
        WHERE product_uuid IN ($placeholders)
          AND user_agent IS NOT NULL AND user_agent != 'unknown'
    ");
    $stmt->execute($uuidList);
    $userAgents = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deviceCounts = [];
    foreach ($userAgents as $ua) {
        $device = detectDevice($ua);
        $deviceCounts[$device] = ($deviceCounts[$device] ?? 0) + 1;
    }
    arsort($deviceCounts);
    $deviceData = $deviceCounts;
}

// ── SCAM WARNING CHECK ─────────────────────────────────────────────────────
$scamWarning = false;
if (!empty($uuidList)) {
    $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM (
            SELECT ip_address, product_uuid, COUNT(*) AS hits
            FROM scans
            WHERE product_uuid IN ($placeholders)
              AND scanned_at > datetime('now', '-1 hour')
            GROUP BY ip_address, product_uuid
            HAVING hits > 10
        )
    ");
    $stmt->execute($uuidList);
    $scamResult = $stmt->fetch();
    if ($scamResult && (int)$scamResult['cnt'] > 0) {
        $scamWarning = true;
    }
}

// ── QUERY FOR LAST 30 DAYS TOTAL (subtitle) ────────────────────────────────
$total30d = 0;
if (!empty($uuidList)) {
    $placeholders = implode(',', array_fill(0, count($uuidList), '?'));
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt FROM scans
        WHERE product_uuid IN ($placeholders)
          AND scanned_at >= ?
    ");
    $stmt->execute([...$uuidList, date('Y-m-d', strtotime('-30 days'))]);
    $total30d = (int)$stmt->fetch()['cnt'];
}

// ── RENDER ──────────────────────────────────────────────────────────────────
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>
<style>
    .ca-back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 20px;
    }
    .ca-back-link:hover { text-decoration: underline; }

    .ca-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
    }
    .ca-header h2 {
        margin: 0;
        font-weight: 800;
        background: linear-gradient(45deg, #ff6600, #ff9e42);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .ca-header .ca-subtitle {
        color: #888;
        font-size: 0.85rem;
        margin-top: 4px;
    }

    /* Client Pills */
    .ca-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 25px;
    }
    .ca-pill {
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        background: #2a2a2a;
        color: #aaa;
        border: 1px solid #444;
        transition: all 0.2s;
    }
    .ca-pill:hover {
        border-color: var(--accent);
        color: var(--text);
    }
    .ca-pill.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }

    /* Scam Badge */
    .ca-scam-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #ff4444;
        color: #fff;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        margin-left: 10px;
    }

    /* Card */
    .ca-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .ca-card h3 {
        margin: 0 0 15px 0;
        font-size: 1.1rem;
        color: var(--text);
        font-weight: 700;
    }

    /* Chart Container */
    .ca-chart-container {
        position: relative;
        width: 100%;
        min-height: 300px;
    }

    /* Top Placements */
    .ca-rank-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .ca-rank-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        background: #1a1a1a;
        border-radius: 6px;
        border: 1px solid #2a2a2a;
        transition: border-color 0.2s;
    }
    .ca-rank-item:hover {
        border-color: var(--accent);
    }
    .ca-rank-emoji {
        font-size: 1.4rem;
        width: 30px;
        text-align: center;
        flex-shrink: 0;
    }
    .ca-rank-info {
        flex: 1;
    }
    .ca-rank-title {
        font-weight: 600;
        font-size: 0.95rem;
    }
    .ca-rank-meta {
        font-size: 0.8rem;
        color: #888;
    }
    .ca-rank-count {
        font-weight: 800;
        color: var(--accent);
        font-size: 1.1rem;
        flex-shrink: 0;
        text-align: right;
    }
    .ca-rank-count small {
        font-weight: 400;
        font-size: 0.7rem;
        color: #888;
        display: block;
    }

    /* Geo Table */
    .ca-table {
        width: 100%;
        border-collapse: collapse;
    }
    .ca-table th {
        text-align: left;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border);
        color: #aaa;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(0,0,0,0.15);
    }
    .ca-table td {
        padding: 10px 14px;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        font-size: 0.9rem;
    }
    .ca-table tr:last-child td { border-bottom: none; }
    .ca-table tr:hover td { background: rgba(255,255,255,0.02); }

    /* Placeholder */
    .ca-placeholder {
        text-align: center;
        color: #666;
        padding: 30px 0;
        font-size: 0.9rem;
    }
    .ca-placeholder code {
        background: #2a2a2a;
        padding: 2px 6px;
        border-radius: 3px;
        color: var(--accent);
        font-size: 0.85rem;
    }

    /* Device Section */
    .ca-device-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .ca-device-item {
        background: #1a1a1a;
        border: 1px solid #2a2a2a;
        border-radius: 6px;
        padding: 12px 18px;
        text-align: center;
        min-width: 100px;
        flex: 1;
    }
    .ca-device-item .ca-device-icon {
        font-size: 1.5rem;
        margin-bottom: 4px;
    }
    .ca-device-item .ca-device-name {
        font-size: 0.85rem;
        font-weight: 600;
    }
    .ca-device-item .ca-device-count {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--accent);
    }

    /* Collapsible */
    .ca-collapsible-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        user-select: none;
    }
    .ca-collapsible-header:hover { opacity: 0.8; }
    .ca-collapsible-toggle {
        font-size: 0.85rem;
        color: var(--accent);
        font-weight: 600;
    }
    .ca-collapsible-body {
        display: none;
        margin-top: 15px;
    }
    .ca-collapsible-body.open {
        display: block;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .ca-header { flex-direction: column; }
        .ca-rank-item { flex-wrap: wrap; }
        .ca-device-list { flex-direction: column; }
        .ca-table { font-size: 0.85rem; }
    }
    @media (max-width: 480px) {
        .ca-pill { font-size: 0.75rem; padding: 4px 10px; }
        .ca-header h2 { font-size: 1.2rem; }
        .ca-rank-item { flex-direction: column; align-items: flex-start; }
        #myChart { max-height: 250px; }
        .ca-qr-item { flex-direction: column; align-items: flex-start; }
    }

    /* ── QR Management Section ──────────────────────────────────────────── */
    .ca-qr-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 12px 16px;
        background: #1a1a1a;
        border: 1px solid #2a2a2a;
        border-radius: 6px;
        transition: border-color 0.2s;
    }
    .ca-qr-item:hover {
        border-color: var(--accent);
    }
    .ca-qr-info {
        flex: 1;
        min-width: 150px;
    }
    .ca-qr-title {
        font-weight: 600;
        font-size: 0.95rem;
    }
    .ca-qr-meta {
        font-size: 0.8rem;
        color: #888;
        margin-top: 2px;
    }
    .ca-qr-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .ca-qr-scans {
        font-weight: 700;
        color: var(--accent);
        font-size: 0.85rem;
        white-space: nowrap;
    }
</style>

<div class="ca-header">
    <div>
        <h2>Analytics Dashboard</h2>
        <div class="ca-subtitle">
            Last 30 days: <strong style="color:var(--accent);"><?= $total30d ?> scans</strong>
            <?php if ($scamWarning): ?>
                <span class="ca-scam-badge">&#9888; Anomaly Detected</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?php if ($scamWarning): ?>
            <span class="ca-scam-badge">&#9888; Scan Anomaly</span>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(BASE_URL) ?>/" class="btn btn-sm" style="background:#444;">&larr; Back to Dashboard</a>
    </div>
</div>

<!-- ── CLIENT PILLS ──────────────────────────────────────────────────────── -->
<?php if (!empty($clients)): ?>
<div class="ca-pills">
    <?php foreach ($clients as $c):
        $isActive = ($c === $selectedClient);
    ?>
        <a href="?client=<?= urlencode($c) ?>" class="ca-pill <?= $isActive ? 'active' : '' ?>">
            <?= htmlspecialchars(parse_url($c, PHP_URL_HOST) ?: $c) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($clients)): ?>
<div class="ca-card">
    <div class="ca-placeholder">
        No URL-type QR codes found. Create a URL QR code and share it to start collecting analytics data.
    </div>
</div>
<?php elseif (empty($selectedClient)): ?>
<div class="ca-card">
    <div class="ca-placeholder">
        No client selected. Choose one from the pills above.
    </div>
</div>
<?php else: ?>

<!-- ── 30-DAY BAR CHART ──────────────────────────────────────────────────── -->
<div class="ca-card">
    <h3>30-Day Scan Activity</h3>
    <div class="ca-chart-container">
        <canvas id="scanChart"></canvas>
    </div>
</div>

<!-- ── TOP PLACEMENTS ────────────────────────────────────────────────────── -->
<div class="ca-card">
    <h3>Top Placements</h3>
    <?php if (!empty($topPlacements)): ?>
    <div class="ca-rank-list">
        <?php
        $rankEmojis = ['&#x1F947;', '&#x1F948;', '&#x1F949;']; // 🥇🥈🥉
        $rank = 0;
        foreach ($topPlacements as $tp):
            $rank++;
            $emoji = $rank <= 3 ? $rankEmojis[$rank - 1] : "#{$rank}";
            $lastScan = $tp['last_scan'] ? date('M d, Y', strtotime($tp['last_scan'])) : 'Never';
            $scans = (int)$tp['scan_count'];
        ?>
        <div class="ca-rank-item">
            <div class="ca-rank-emoji"><?= $emoji ?></div>
            <div class="ca-rank-info">
                <div class="ca-rank-title"><?= htmlspecialchars($tp['title'] ?: 'Untitled QR') ?></div>
                <div class="ca-rank-meta">Last scan: <?= $lastScan ?></div>
            </div>
            <div class="ca-rank-count">
                <?= $scans ?>
                <small>scan<?= $scans !== 1 ? 's' : '' ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="ca-placeholder">No scan data available yet.</div>
    <?php endif; ?>
</div>

<!-- ── GEOGRAPHIC SECTION ────────────────────────────────────────────────── -->
<div class="ca-card">
    <h3>Geographic Distribution</h3>
    <?php if ($geoPlaceholder): ?>
    <div class="ca-placeholder">
        <p>IP-based location data is not currently stored in the database.</p>
        <p style="margin-top:8px;">
            <code>Track: Use your existing scan data above to start.</code><br>
            To enable geo-location, the system will automatically add <code>geo_city</code>, <code>geo_region</code>, and <code>geo_country</code> columns to the scans table when configured.<br>
            <small style="color:#666;">(See config.php — geo columns are added automatically on first load)</small>
        </p>
    </div>
    <?php elseif (!empty($geoData)): ?>
    <table class="ca-table">
        <thead>
            <tr>
                <th>City</th>
                <th>State / Region</th>
                <th style="text-align:right;">Scans</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($geoData as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['geo_city'] ?? '—') ?></td>
                <td><?= htmlspecialchars($g['geo_region'] ?? '—') ?></td>
                <td style="text-align:right; font-weight:700; color:var(--accent);"><?= (int)$g['cnt'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="ca-placeholder">No geographic data available.</div>
    <?php endif; ?>
</div>

<!-- ── DEVICE & OS SECTION (collapsible) ─────────────────────────────────── -->
<div class="ca-card">
    <div class="ca-collapsible-header" onclick="toggleDeviceSection()">
        <h3 style="margin:0;">Device &amp; OS</h3>
        <span class="ca-collapsible-toggle" id="deviceToggle">Show</span>
    </div>
    <div class="ca-collapsible-body" id="deviceSection">
        <?php if (!empty($deviceData)): ?>
        <div class="ca-device-list">
            <?php
            $deviceIcons = [
                'iOS'     => '&#x1F4F1;',
                'Android' => '&#x1F4F1;',
                'Windows' => '&#x1F5A5;',
                'Mac'     => '&#x1F5A5;',
                'Linux'   => '&#x1F427;',
                'Other'   => '&#x2753;',
            ];
            $totalDevices = array_sum($deviceData);
            foreach ($deviceData as $device => $count):
                $icon = $deviceIcons[$device] ?? '&#x2753;';
                $pct = $totalDevices > 0 ? round(($count / $totalDevices) * 100) : 0;
            ?>
            <div class="ca-device-item">
                <div class="ca-device-icon"><?= $icon ?></div>
                <div class="ca-device-name"><?= htmlspecialchars($device) ?></div>
                <div class="ca-device-count"><?= (int)$count ?></div>
                <div style="font-size:0.75rem;color:#888;"><?= $pct ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ca-placeholder">No device data available.</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- ── QR MANAGEMENT SECTION ────────────────────────────────────────────────── -->
<?php if (!empty($selectedClient) && !empty($clients)): ?>
<div class="ca-card ca-qr-mgmt" style="margin-top:20px;">
    <div class="ca-collapsible-header" onclick="toggleQrSection()">
        <h3 style="margin:0;">QR Code Management</h3>
        <span style="display:flex; gap:8px; align-items:center;">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/generator.php?client=<?= urlencode($selectedClient) ?>" class="btn btn-sm">+ New QR Code</a>
            <span class="ca-collapsible-toggle" id="qrToggle">Show</span>
        </span>
    </div>
    <div class="ca-collapsible-body" id="qrSection">
        <?php if (empty($qrCodes)): ?>
        <div class="ca-placeholder" style="margin-top:15px;">
            No QR codes for this client yet.
            <a href="<?= htmlspecialchars(BASE_URL) ?>/generator.php?client=<?= urlencode($selectedClient) ?>" class="btn btn-sm" style="margin-top:10px;">+ Add the First QR Code</a>
        </div>
        <?php else: ?>
        <div style="margin-top:15px; display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($qrCodes as $qr):
                // Get scan count for this QR
                $stmtS = $db->prepare("SELECT COUNT(*) AS cnt FROM scans WHERE product_uuid = ?");
                $stmtS->execute([$qr['uuid']]);
                $scanCnt = (int)$stmtS->fetch()['cnt'];
            ?>
            <div class="ca-qr-item" data-uuid="<?= htmlspecialchars($qr['uuid']) ?>">
                <div class="ca-qr-info">
                    <div class="ca-qr-title"><?= htmlspecialchars($qr['title'] ?: 'Untitled QR') ?></div>
                    <div class="ca-qr-meta">UUID: <?= htmlspecialchars(mb_substr($qr['uuid'], 0, 8)) ?>&hellip; &middot; Created: <?= date('M d, Y', strtotime($qr['created_at'])) ?></div>
                </div>
                <div class="ca-qr-actions">
                    <span class="ca-qr-scans"><?= $scanCnt ?> scan<?= $scanCnt !== 1 ? 's' : '' ?></span>
                    <label class="switch">
                        <input type="checkbox" onchange="caToggleQR(<?= (int)$qr['id'] ?>, '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>')" <?= $qr['is_active'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <button class="btn btn-sm" onclick="caShowQR('<?= htmlspecialchars($qr['uuid']) ?>', <?= htmlspecialchars(json_encode($qr['title'] ?: 'Untitled QR'), ENT_QUOTES) ?>)">Get Code</button>
                    <button class="btn btn-sm btn-info" onclick='caOpenEditModal(<?= htmlspecialchars(json_encode([
                        'id'          => $qr['id'],
                        'title'       => $qr['title'],
                        'type'        => $qr['type'],
                        'target_data' => $qr['target_data'],
                    ]), ENT_QUOTES) ?>)' title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293l6.5-6.5zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z"/>
                        </svg>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="caConfirmDelete(<?= (int)$qr['id'] ?>)" title="Delete" style="display:flex; align-items:center; padding:8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                            <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Add Modal ──────────────────────────────────────────────────────────────── -->
<div id="caAddModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('caAddModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Add QR Code</h2>
        <p style="color:#888;">Client: <strong id="caAddClientLabel" style="color:var(--accent);"></strong></p>
        <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars(BASE_URL) ?>/client_analytics.php?client=<?= urlencode($selectedClient) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="client_target" id="caAddClientTarget" value="<?= htmlspecialchars($selectedClient) ?>">
            <label>Title</label>
            <input type="text" name="title" required placeholder="Product Name" maxlength="255">
            <label>Type</label>
            <select name="type" id="caTypeSelect" onchange="caToggleFields()">
                <option value="url">Website URL</option>
                <option value="phone">Phone Number</option>
                <option value="map">Map Location</option>
                <option value="vcard">vCard Contact</option>
                <option value="wifi">Wi-Fi Network</option>
                <option value="sms">SMS Message</option>
                <option value="email">Email Message</option>
                <option value="social">Social Media</option>
            </select>

            <div id="ca-field-general" class="type-fields" style="display:block;">
                <label>Target URL</label>
                <input type="text" name="target" id="caTargetField" placeholder="https://example.com" maxlength="2048">
            </div>
            <div id="ca-field-vcard" class="type-fields">
                <input type="text" name="v_fname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" placeholder="Company" maxlength="255">
            </div>
            <div id="ca-field-wifi" class="type-fields">
                <input type="text" name="wifi_ssid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" placeholder="Password" maxlength="63">
                <select name="wifi_enc"><option value="WPA">WPA/WPA2</option><option value="WEP">WEP</option><option value="nopass">No Encryption</option></select>
            </div>
            <div id="ca-field-sms" class="type-fields">
                <input type="tel" name="sms_phone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <div id="ca-field-email" class="type-fields">
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
<div id="caEditModal" class="modal">
    <div class="modal-content">
        <svg class="close-icon" onclick="closeModal('caEditModal')" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <h2>Edit QR Code <span id="caEditTypeLabel" style="font-size:0.65em; opacity:0.5; font-weight:normal;"></span></h2>
        <form method="POST" id="caEditForm" action="<?= htmlspecialchars(BASE_URL) ?>/client_analytics.php?client=<?= urlencode($selectedClient) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="caEditId">
            <input type="hidden" name="client_target" value="<?= htmlspecialchars($selectedClient) ?>">
            <label>Title</label>
            <input type="text" name="title" id="caEditTitle" required maxlength="255">

            <div id="ca-edit-field-general" class="type-fields" style="display:none;">
                <label id="caEditGeneralLabel">Target</label>
                <input type="text" name="target" id="caEditTarget" maxlength="2048">
            </div>
            <div id="ca-edit-field-vcard" class="type-fields" style="display:none;">
                <input type="text" name="v_fname" id="caEditFname" placeholder="First Name" maxlength="100">
                <input type="text" name="v_lname" id="caEditLname" placeholder="Last Name" maxlength="100">
                <input type="text" name="v_phone" id="caEditVphone" placeholder="Phone" maxlength="30">
                <input type="email" name="v_email" id="caEditVemail" placeholder="Email" maxlength="255">
                <input type="text" name="v_company" id="caEditVcompany" placeholder="Company" maxlength="255">
            </div>
            <div id="ca-edit-field-wifi" class="type-fields" style="display:none;">
                <input type="text" name="wifi_ssid" id="caEditSsid" placeholder="Network Name (SSID)" maxlength="32">
                <input type="text" name="wifi_pass" id="caEditPass" placeholder="Password" maxlength="63">
                <select name="wifi_enc" id="caEditEnc">
                    <option value="WPA">WPA/WPA2</option>
                    <option value="WEP">WEP</option>
                    <option value="nopass">No Encryption</option>
                </select>
            </div>
            <div id="ca-edit-field-sms" class="type-fields" style="display:none;">
                <input type="tel" name="sms_phone" id="caEditSmsPhone" placeholder="Phone Number" maxlength="30">
                <textarea name="sms_body" id="caEditSmsBody" placeholder="Message Body" maxlength="1000"></textarea>
            </div>
            <div id="ca-edit-field-email" class="type-fields" style="display:none;">
                <input type="email" name="email_addr" id="caEditEmailAddr" placeholder="Recipient" maxlength="255">
                <input type="text" name="email_sub" id="caEditEmailSub" placeholder="Subject" maxlength="255">
                <textarea name="email_body" id="caEditEmailBody" placeholder="Body" maxlength="2000"></textarea>
            </div>

            <button type="submit" class="btn" style="width:100%; margin-top:20px;">Save Changes</button>
        </form>
    </div>
</div>

<!-- ── QR Code Modal ──────────────────────────────────────────────────────────── -->
<div id="caQrModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <svg class="close-icon" onclick="closeModal('caQrModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2 id="caQrTitle">QR Code</h2>
        <img id="caQrImage" src="" style="width: 250px; height: 250px; border: 5px solid white; margin: 20px 0;" alt="QR Code">
        <div style="margin: 10px auto; max-width: 400px; text-align: left;">
            <label style="font-size: 0.85em; color: #aaa;">Tracking URL (paste into QRCodeMonkey):</label>
            <input type="text" id="caQrUrl" readonly onclick="this.select(); navigator.clipboard?.writeText(this.value)"
                   style="width: 100%; padding: 8px; background: #2a2a2a; border: 1px solid #444; color: var(--accent); border-radius: 4px; font-size: 0.85em; cursor: pointer; box-sizing: border-box;">
        </div>
        <div style="display:flex; gap:10px; justify-content:center;">
            <a id="caDlPng" href="#" download class="btn btn-sm">Download PNG</a>
            <a id="caDlJpg" href="#" download class="btn btn-sm">Download JPG</a>
            <button onclick="caPrintQR()" class="btn btn-sm" style="background: #444;">Print</button>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────────── -->
<div id="caDeleteModal" class="modal">
    <div class="modal-content" style="text-align: center;">
        <svg class="close-icon" onclick="closeModal('caDeleteModal')" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        <h2>Are you sure?</h2>
        <p>This will move the QR code to Trash. You can restore it later.</p>
        <div style="margin-top: 20px; display:flex; gap:10px; justify-content:center;">
            <button id="caConfirmDeleteBtn" class="btn btn-danger">Yes, Delete</button>
            <button onclick="closeModal('caDeleteModal')" class="btn" style="background: #444;">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── Collapsible Device Section ──────────────────────────────────────────────
function toggleDeviceSection() {
    const body = document.getElementById('deviceSection');
    const toggle = document.getElementById('deviceToggle');
    body.classList.toggle('open');
    toggle.textContent = body.classList.contains('open') ? 'Hide' : 'Show';
}

// ── Chart ───────────────────────────────────────────────────────────────────
<?php if (!empty($chartDatasets)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('scanChart').getContext('2d');

    // Format labels for display (shorter)
    const labels = <?= json_encode(array_map(fn($d) => date('M d', strtotime($d)), $chartLabels)) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: <?= json_encode($chartDatasets) ?>,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#e0e0e0',
                        font: { family: 'Recursive, sans-serif', size: 11 },
                        boxWidth: 12,
                        padding: 15,
                    },
                },
                tooltip: {
                    backgroundColor: '#1e1e1e',
                    titleColor: '#e0e0e0',
                    bodyColor: '#e0e0e0',
                    borderColor: '#333',
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 6,
                },
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: {
                        color: '#888',
                        font: { family: 'Recursive, sans-serif', size: 10 },
                        maxRotation: 45,
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.04)',
                    },
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        color: '#888',
                        font: { family: 'Recursive, sans-serif', size: 10 },
                        precision: 0,
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.04)',
                    },
                },
            },
        },
    });
});
<?php endif; ?>

// ── QR Management Functions ─────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
});

function toggleQrSection() {
    const body = document.getElementById('qrSection');
    const toggle = document.getElementById('qrToggle');
    body.classList.toggle('open');
    toggle.textContent = body.classList.contains('open') ? 'Hide' : 'Show';
}

function openAddModalForClient(clientUrl) {
    document.getElementById('caAddClientLabel').textContent = clientUrl;
    document.getElementById('caAddClientTarget').value = clientUrl;
    document.getElementById('caTargetField').value = clientUrl;
    document.getElementById('caTypeSelect').value = 'url';
    caToggleFields();
    document.getElementById('caAddModal').style.display = 'flex';
}

function caToggleFields() {
    document.querySelectorAll('#caAddModal .type-fields').forEach(e => e.style.display = 'none');
    const type = document.getElementById('caTypeSelect').value;
    const generalInput = document.querySelector('#ca-field-general input');
    if (['vcard','wifi','sms','email'].includes(type)) {
        document.getElementById('ca-field-' + type).style.display = 'block';
    } else {
        document.getElementById('ca-field-general').style.display = 'block';
        generalInput.type = (type === 'url' || type === 'social') ? 'url' : 'text';
        if (type === 'phone')      generalInput.placeholder = '+155****0000';
        else if (type === 'map')   generalInput.placeholder = '123 Main St, City, ST';
        else                       generalInput.placeholder = 'https://...';
    }
}

function caPrintQR() {
    const win = window.open('');
    win.document.write('<html><body style="text-align:center;"><h2 style="font-family:sans-serif">' +
        document.getElementById('caQrTitle').innerText +
        '</h2><img src="' + document.getElementById('caQrImage').src +
        '" onload="window.print();window.close()" /></body></html>');
    win.document.close();
}

function caShowQR(uuid, title) {
    const urlBase = 'generate_image.php?id=' + uuid;
    document.getElementById('caQrImage').src        = urlBase + '&format=jpg';
    document.getElementById('caQrTitle').innerText  = title;
    document.getElementById('caDlPng').href         = urlBase + '&format=png';
    document.getElementById('caDlJpg').href         = urlBase + '&format=jpg';
    document.getElementById('caDlPng').setAttribute('download', title + '-QR.png');
    document.getElementById('caDlJpg').setAttribute('download', title + '-QR.jpg');
    document.getElementById('caQrUrl').value = window.location.origin + '/p/' + uuid;
    document.getElementById('caQrModal').style.display = 'flex';
}

function caToggleQR(id, csrf) {
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    fetch(window.location.href, { method: 'POST', body: fd });
}

function caOpenEditModal(data) {
    document.querySelectorAll('#caEditModal .type-fields').forEach(e => e.style.display = 'none');
    document.getElementById('caEditId').value    = data.id;
    document.getElementById('caEditTitle').value = data.title;
    document.getElementById('caEditTypeLabel').textContent = '[' + data.type.toUpperCase() + ']';

    const type = data.type;
    let target = data.target_data;
    let parsed = null;
    try { parsed = JSON.parse(target); } catch(e) {}

    if (type === 'vcard' && parsed) {
        document.getElementById('ca-edit-field-vcard').style.display = 'block';
        document.getElementById('caEditFname').value    = parsed.fname    || '';
        document.getElementById('caEditLname').value    = parsed.lname    || '';
        document.getElementById('caEditVphone').value   = parsed.phone    || '';
        document.getElementById('caEditVemail').value   = parsed.email    || '';
        document.getElementById('caEditVcompany').value = parsed.company  || '';
    } else if (type === 'wifi' && parsed) {
        document.getElementById('ca-edit-field-wifi').style.display = 'block';
        document.getElementById('caEditSsid').value = parsed.ssid || '';
        document.getElementById('caEditPass').value = parsed.pass || '';
        const encSel = document.getElementById('caEditEnc');
        for (let opt of encSel.options) { if (opt.value === parsed.enc) { opt.selected = true; break; } }
    } else if (type === 'sms' && parsed) {
        document.getElementById('ca-edit-field-sms').style.display = 'block';
        document.getElementById('caEditSmsPhone').value = parsed.phone || '';
        document.getElementById('caEditSmsBody').value  = parsed.body  || '';
    } else if (type === 'email' && parsed) {
        document.getElementById('ca-edit-field-email').style.display = 'block';
        document.getElementById('caEditEmailAddr').value = parsed.email   || '';
        document.getElementById('caEditEmailSub').value  = parsed.subject || '';
        document.getElementById('caEditEmailBody').value = parsed.body    || '';
    } else {
        document.getElementById('ca-edit-field-general').style.display = 'block';
        const lbl = { url:'Target URL', phone:'Phone Number', map:'Map Address', social:'Profile URL' };
        document.getElementById('caEditGeneralLabel').textContent = lbl[type] || 'Target';
        document.getElementById('caEditTarget').value = target;
    }

    document.getElementById('caEditModal').style.display = 'flex';
}

let caDeleteId = null;
function caConfirmDelete(id) { caDeleteId = id; document.getElementById('caDeleteModal').style.display = 'flex'; }
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('caConfirmDeleteBtn');
    if (btn) {
        btn.addEventListener('click', function() {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', caDeleteId);
            fd.append('csrf_token', '<?= htmlspecialchars(csrf_token()) ?>');
            fetch(window.location.href, { method: 'POST', body: fd }).then(() => location.reload());
        });
    }
});
</script>

<?php include THEME_PATH . '/footer.php'; ?>