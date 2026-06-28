<?php
require 'config.php';
require_auth();

define('PAGE_TITLE', 'Analytics | Tracking LaB');

// ── QUERY: Group by destination URL ─────────────────────────────────────────
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

$rows = $stmt->fetchAll();

// Find the max scan count so we can normalise bar widths
$maxScans = $rows ? max(array_column($rows, 'total_scans')) : 0;

// ── RENDER ──────────────────────────────────────────────────────────────────
define('SHOW_ADD_BTN', true);
include THEME_PATH . '/header.php';
?>
<style>
    .analytics-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    .analytics-table th {
        text-align: left;
        padding: 12px 10px;
        border-bottom: 2px solid var(--border);
        color: #aaa;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .analytics-table td {
        padding: 10px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .analytics-table tr:hover td {
        background: rgba(255,255,255,0.03);
    }
    .bar-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bar-bg {
        flex: 1;
        height: 24px;
        background: #2a2a2a;
        border-radius: 4px;
        overflow: hidden;
        min-width: 80px;
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
        min-width: 40px;
        text-align: right;
        font-size: 1rem;
    }
    .url-display {
        word-break: break-all;
        color: var(--text);
        font-size: 0.9rem;
    }
    .url-display a {
        color: var(--accent);
        text-decoration: none;
    }
    .url-display a:hover {
        text-decoration: underline;
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
    .analytics-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .summary-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 18px 20px;
    }
    .summary-card .label {
        font-size: 0.8rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .summary-card .value {
        font-size: 1.6rem;
        font-weight: 800;
        margin-top: 4px;
        color: var(--accent);
    }
    .no-data {
        text-align: center;
        color: #666;
        padding: 60px 0;
        font-size: 1.1rem;
    }
</style>

<?php
// Compute summary stats
$totalUrls     = count($rows);
$totalScansAll = array_sum(array_column($rows, 'total_scans'));
$totalQrs      = array_sum(array_column($rows, 'qr_count'));
?>

<div class="analytics-summary">
    <div class="summary-card">
        <div class="label">Destination URLs</div>
        <div class="value"><?= $totalUrls ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Scans</div>
        <div class="value"><?= number_format($totalScansAll) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">QR Codes Tracked</div>
        <div class="value"><?= number_format($totalQrs) ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Avg Scans / URL</div>
        <div class="value"><?= $totalUrls ? round($totalScansAll / $totalUrls, 1) : 0 ?></div>
    </div>
</div>

<?php if (empty($rows)): ?>
    <p class="no-data">No URL-type QR codes have been scanned yet. Create a URL QR code and share it to start collecting data.</p>
<?php else: ?>
<div style="overflow-x: auto;">
    <table class="analytics-table">
        <thead>
            <tr>
                <th style="width:35%;">Destination URL</th>
                <th style="width:40%;">Scans (bar chart)</th>
                <th style="width:10%;">QR Codes</th>
                <th style="width:15%;">Last Scan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row):
                $barPct = $maxScans > 0 ? round(($row['total_scans'] / $maxScans) * 100) : 0;
            ?>
            <tr>
                <td>
                    <div class="url-display">
                        <a href="<?= htmlspecialchars($row['target_data']) ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars(mb_substr($row['target_data'], 0, 60)) ?>
                        </a>
                        <?php if (mb_strlen($row['target_data']) > 60): ?>
                            <span class="stat-badge" title="<?= htmlspecialchars($row['target_data']) ?>">…</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="bar-cell">
                        <div class="bar-bg">
                            <div class="bar-fill" style="width:<?= $barPct ?>%;"></div>
                        </div>
                        <span class="bar-count"><?= (int)$row['total_scans'] ?></span>
                    </div>
                </td>
                <td class="stat-meta"><?= (int)$row['qr_count'] ?> QR<?= $row['qr_count'] != 1 ? 's' : '' ?></td>
                <td class="stat-meta">
                    <?= $row['last_scan']
                        ? date('M d, Y', strtotime($row['last_scan']))
                        : '<span style="color:#555;">—</span>' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include THEME_PATH . '/footer.php'; ?>