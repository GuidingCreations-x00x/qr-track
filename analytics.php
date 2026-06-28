<?php
require 'config.php';
require_auth();

define('PAGE_TITLE', 'Analytics | Tracking LaB');

// ── QUERY 1: URL groups (aggregate per destination URL) ────────────────────
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
    GROUP BY p.target_data
    ORDER BY total_scans DESC
");

$urlGroups = $stmt->fetchAll();

// ── QUERY 2: Individual QR code scan counts ────────────────────────────────
$stmt2 = $db->query("
    SELECT
        p.uuid,
        p.title,
        p.target_data,
        COUNT(s.id) AS scan_count
    FROM products p
    LEFT JOIN scans s ON p.uuid = s.product_uuid
    WHERE p.type = 'url'
      AND p.is_deleted = 0
    GROUP BY p.uuid
    ORDER BY p.target_data, scan_count DESC
");

$allQrs = $stmt2->fetchAll();

// Organise QR codes by target_data
$qrByUrl = [];
foreach ($allQrs as $qr) {
    $url = $qr['target_data'];
    $qrByUrl[$url][] = $qr;
}

// Find the max scan count so we can normalise bar widths (per group)
$maxScans = $urlGroups ? max(array_column($urlGroups, 'total_scans')) : 0;

// Find max individual QR scan count for nested bar widths
$maxQrScans = $allQrs ? max(array_column($allQrs, 'scan_count')) : 0;

// ── RENDER ──────────────────────────────────────────────────────────────────
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>
<style>
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 20px;
    }
    .back-link:hover {
        text-decoration: underline;
    }
    .url-group {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 8px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .url-group-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        background: rgba(255,255,255,0.03);
        border-bottom: 1px solid var(--border);
        flex-wrap: wrap;
        gap: 10px;
    }
    .url-group-header .url-display {
        word-break: break-all;
        color: var(--text);
        font-size: 0.95rem;
        flex: 1;
        min-width: 0;
    }
    .url-group-header .url-display a {
        color: var(--accent);
        text-decoration: none;
    }
    .url-group-header .url-display a:hover {
        text-decoration: underline;
    }
    .url-group-header .url-stats {
        display: flex;
        gap: 20px;
        flex-shrink: 0;
    }
    .url-group-header .url-stat-item {
        text-align: center;
    }
    .url-group-header .url-stat-item .num {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--accent);
    }
    .url-group-header .url-stat-item .lbl {
        font-size: 0.7rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .url-group-header .url-bar-bg {
        width: 100%;
        height: 4px;
        background: #2a2a2a;
        border-radius: 2px;
        overflow: hidden;
        margin-top: 8px;
        flex-basis: 100%;
    }
    .url-group-header .url-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent), #ff9e42);
        border-radius: 2px;
        transition: width 0.4s ease;
        min-width: 1px;
    }
    .qr-table {
        width: 100%;
        border-collapse: collapse;
    }
    .qr-table th {
        text-align: left;
        padding: 10px 20px;
        border-bottom: 1px solid var(--border);
        color: #aaa;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(0,0,0,0.15);
    }
    .qr-table td {
        padding: 10px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        vertical-align: middle;
    }
    .qr-table tr:last-child td {
        border-bottom: none;
    }
    .qr-table tr:hover td {
        background: rgba(255,255,255,0.02);
    }
    .bar-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bar-bg {
        flex: 1;
        height: 20px;
        background: #2a2a2a;
        border-radius: 4px;
        overflow: hidden;
        min-width: 60px;
    }
    .bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--accent), #ff9e42);
        border-radius: 4px;
        transition: width 0.4s ease;
        min-width: 2px;
    }
    .bar-count {
        font-weight: 700;
        color: var(--accent);
        min-width: 30px;
        text-align: right;
        font-size: 0.95rem;
    }
    .qr-title {
        font-weight: 600;
        color: var(--text);
        font-size: 0.9rem;
    }
    .qr-uuid {
        color: #666;
        font-size: 0.75rem;
        font-family: monospace;
        margin-top: 2px;
    }
    .stat-meta {
        color: #888;
        font-size: 0.85rem;
    }
    .stat-badge {
        display: inline-block;
        background: #2a2a2a;
        color: #aaa;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.8rem;
        margin-left: 4px;
    }
    .no-data {
        text-align: center;
        color: #666;
        padding: 60px 0;
        font-size: 1.1rem;
    }
</style>

<p><a href="<?= htmlspecialchars(BASE_URL) ?>/" class="back-link">← Back to Dashboard</a></p>

<?php if (empty($urlGroups)): ?>
    <p class="no-data">No URL-type QR codes have been scanned yet. Create a URL QR code and share it to start collecting data.</p>
<?php else: ?>
    <?php foreach ($urlGroups as $group):
        $barPct = $maxScans > 0 ? round(($group['total_scans'] / $maxScans) * 100) : 0;
        $qrs = $qrByUrl[$group['target_data']] ?? [];
    ?>
    <div class="url-group">
        <div class="url-group-header">
            <div class="url-display">
                <a href="<?= htmlspecialchars($group['target_data']) ?>" target="_blank" rel="noopener">
                    <?= htmlspecialchars(mb_substr($group['target_data'], 0, 60)) ?>
                </a>
                <?php if (mb_strlen($group['target_data']) > 60): ?>
                    <span class="stat-badge" title="<?= htmlspecialchars($group['target_data']) ?>">…</span>
                <?php endif; ?>
            </div>
            <div class="url-stats">
                <div class="url-stat-item">
                    <div class="num"><?= (int)$group['total_scans'] ?></div>
                    <div class="lbl">Scans</div>
                </div>
                <div class="url-stat-item">
                    <div class="num"><?= (int)$group['qr_count'] ?></div>
                    <div class="lbl">QR Codes</div>
                </div>
                <div class="url-stat-item">
                    <div class="num"><?= $group['last_scan'] ? date('M d', strtotime($group['last_scan'])) : '—' ?></div>
                    <div class="lbl">Last Scan</div>
                </div>
            </div>
            <div class="url-bar-bg">
                <div class="url-bar-fill" style="width:<?= $barPct ?>%;"></div>
            </div>
        </div>

        <?php if (!empty($qrs)): ?>
        <table class="qr-table">
            <thead>
                <tr>
                    <th style="width:35%;">QR Code</th>
                    <th style="width:50%;">Scans</th>
                    <th style="width:15%;">UUID</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($qrs as $qr):
                    $innerBarPct = $maxQrScans > 0 ? round(($qr['scan_count'] / $maxQrScans) * 100) : 0;
                ?>
                <tr>
                    <td>
                        <div class="qr-title"><?= htmlspecialchars($qr['title'] ?: 'Untitled QR') ?></div>
                    </td>
                    <td>
                        <div class="bar-cell">
                            <div class="bar-bg">
                                <div class="bar-fill" style="width:<?= $innerBarPct ?>%;"></div>
                            </div>
                            <span class="bar-count"><?= (int)$qr['scan_count'] ?></span>
                        </div>
                    </td>
                    <td class="stat-meta">
                        <span class="qr-uuid"><?= htmlspecialchars(mb_substr($qr['uuid'], 0, 8)) ?>…</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include THEME_PATH . '/footer.php'; ?>