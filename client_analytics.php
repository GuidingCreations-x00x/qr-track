<?php
require __DIR__ . '/config.php';
require_auth();

header('X-Robots-Tag: noindex, nofollow');

define('PAGE_TITLE', 'Analytics Dashboard | Tracking LaB');

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
        SELECT p.uuid, p.title, p.created_at
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
</script>

<?php include THEME_PATH . '/footer.php'; ?>