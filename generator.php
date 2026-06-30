<?php
require __DIR__ . '/config.php';

// ── Auth check ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// ── Handle POST: Save to DB (authenticated users only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    require_auth();
    verify_csrf();

    $uuid  = bin2hex(random_bytes(6));
    $title = trim($_POST['title'] ?? '');
    $target = trim($_POST['target'] ?? '');
    $dotStyle = $_POST['dot_style'] ?? 'square';
    $cornerStyle = $_POST['corner_style'] ?? 'square';
    $colorBody = $_POST['color_body'] ?? '#000000';
    $colorFinders = $_POST['color_finders'] ?? '#000000';
    $colorBg = $_POST['color_bg'] ?? '#ffffff';

    if ($title === '' || $target === '') {
        http_response_code(400);
        die('Title and Target URL are required.');
    }

    // Determine dot_modules bool from dot_style
    $dotModules = ($dotStyle !== 'square') ? 1 : 0;

    // Handle logo upload
    $logoPath = null;
    $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
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
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data, logo_path, dot_modules, dot_style, color_body, color_finders, color_bg, corner_style) VALUES (?, ?, 'url', ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$uuid, $title, $target, $logoPath, $dotModules, $dotStyle, $colorBody, $colorFinders, $colorBg, $cornerStyle]);

    header("Location: " . BASE_URL . "/?saved=1");
    exit;
}

define('PAGE_TITLE', 'QR Code Generator | Tracking LaB');

// ── PRE-FILL VALUES ─────────────────────────────────────────────────────────
$prefillTarget = htmlspecialchars($_GET['client'] ?? '', ENT_QUOTES);
$prefillTitle  = htmlspecialchars($_GET['title'] ?? '', ENT_QUOTES);
$csrfToken     = csrf_token();

// ── HEADER ──────────────────────────────────────────────────────────────────
if ($isLoggedIn) {
    define('SHOW_ADD_BTN', false);
    include THEME_PATH . '/header.php';
    // header.php opens <div class="container">, so our content goes inside it
} else {
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(PAGE_TITLE) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(BASE_URL) ?>/assets/logo.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Recursive:wght@300..900&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #121212; --card: #1e1e1e; --text: #e0e0e0; --accent: #ff6600; --border: #333; }
        * { box-sizing: border-box; }
        body { font-family: 'Recursive', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid var(--border); padding-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .header-brand { display: flex; align-items: center; gap: 15px; }
        .logo-img { height: 50px; width: auto; }
        h1 { margin: 0; font-weight: 800; background: linear-gradient(45deg, #ff6600, #ff9e42); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .btn { background: var(--accent); color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn:hover { opacity: 0.9; }
        .btn-sm { padding: 5px 10px; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <div class="header-brand">
            <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/logo.svg" alt="Tracking LaB" class="logo-img" style="height:55px;">
            <div>
                <h1>Tracking LaB</h1>
                <small style="color:#666;">QR Code Generator</small>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn btn-sm" style="background:#444;">Sign In</a>
        </div>
    </header>
<?php } ?>

<style>
    /* ── Generator Layout ──────────────────────────────────────────────────── */
    .gen-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        align-items: start;
    }
    @media (max-width: 768px) {
        .gen-grid { grid-template-columns: 1fr; }
    }

    .gen-preview {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
        position: sticky;
        top: 20px;
    }
    @media (max-width: 768px) { .gen-preview { position: static; } }
    .gen-preview h3 {
        margin: 0 0 5px 0;
        font-weight: 700;
        font-size: 1.1rem;
        color: #aaa;
        align-self: flex-start;
    }

    #qrPreviewContainer {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        min-height: 320px;
        background: #0a0a0a;
        border-radius: 8px;
        border: 1px solid #2a2a2a;
        padding: 20px;
    }
    #qrPreviewContainer canvas,
    #qrPreviewContainer img {
        max-width: 100%;
        height: auto;
    }

    .gen-controls {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 25px;
    }
    .gen-controls h2 {
        margin: 0 0 20px 0;
        font-weight: 800;
        font-size: 1.1rem;
        background: linear-gradient(45deg, #ff6600, #ff9e42);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .gen-form-group { margin-bottom: 18px; }
    .gen-form-group label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #aaa;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .gen-form-group input,
    .gen-form-group select {
        width: 100%;
        padding: 10px 12px;
        background: #2a2a2a;
        border: 1px solid #444;
        color: var(--text);
        border-radius: 4px;
        font-family: inherit;
        font-size: 0.9rem;
    }
    .gen-form-group input:focus,
    .gen-form-group select:focus {
        outline: none;
        border-color: var(--accent);
    }
    .gen-form-group input[type="color"] {
        height: 42px;
        padding: 3px;
        cursor: pointer;
        min-width: 60px;
    }

    .gen-color-row { display: flex; gap: 12px; flex-wrap: wrap; }
    .gen-color-row .gen-form-group { flex: 1; min-width: 120px; }

    .gen-file-label {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: #2a2a2a;
        border: 1px dashed #555;
        border-radius: 4px;
        cursor: pointer;
        transition: border-color 0.2s;
        font-size: 0.9rem;
        color: #aaa;
    }
    .gen-file-label:hover { border-color: var(--accent); }
    .gen-file-label input[type="file"] { display: none; }

    .gen-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
    .gen-actions .btn { flex: 1; text-align: center; min-width: 120px; }

    .gen-public-notice {
        text-align: center;
        font-size: 0.8rem;
        color: #666;
        margin-top: 15px;
        padding: 12px;
        border-top: 1px solid var(--border);
    }
    .gen-public-notice a { color: var(--accent); text-decoration: none; }
    .gen-public-notice a:hover { text-decoration: underline; }

    .gen-success {
        background: #1a3a1a;
        border: 1px solid #2a6a2a;
        color: #8bc34a;
        padding: 12px 16px;
        border-radius: 6px;
        font-size: 0.9rem;
        margin-bottom: 16px;
        text-align: center;
    }

    .corner-info { font-size: 0.75rem; color: #555; margin-top: 4px; font-style: italic; }

    /* ── Logged-in nav bar adjustments ────────────────────────────────────── */
    .gen-nav-spacer { display: flex; gap: 10px; align-items: center; }
    .gen-nav-spacer a { background: #444; }
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="gen-success">&#10003; QR code saved successfully! <a href="<?= htmlspecialchars(BASE_URL) ?>" style="color:var(--accent);">Back to Dashboard</a></div>
<?php endif; ?>

<div class="gen-grid">
    <!-- ── PREVIEW ─────────────────────────────────────────────────────── -->
    <div class="gen-preview">
        <h3>&#128065; Live Preview</h3>
        <div id="qrPreviewContainer"></div>
        <div class="gen-actions" style="width:100%;">
            <button class="btn" onclick="downloadQR()" style="flex:1;">&#11015; Download PNG</button>
        </div>
    </div>

    <!-- ── CONTROLS ────────────────────────────────────────────────────── -->
    <div class="gen-controls">
        <h2>&#9881; Design Your QR Code</h2>

        <form id="saveForm" method="POST" enctype="multipart/form-data" action="generator.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save">

            <!-- Title -->
            <div class="gen-form-group">
                <label for="genTitle">Title</label>
                <input type="text" id="genTitle" name="title"
                       value="<?= $prefillTitle ?>"
                       placeholder="e.g., Table Tent"
                       maxlength="255"
                       <?= $isLoggedIn ? '' : 'readonly style="opacity:0.7;" title="Sign in to set a title"' ?>>
            </div>

            <!-- Target URL -->
            <div class="gen-form-group">
                <label for="genTarget">Target URL</label>
                <input type="url" id="genTarget" name="target"
                       value="<?= $prefillTarget ?>"
                       placeholder="https://example.com"
                       maxlength="2048" required>
            </div>

            <!-- Dot Style -->
            <div class="gen-form-group">
                <label for="genDotStyle">Dot Style</label>
                <select id="genDotStyle" name="dot_style">
                    <option value="square">Square</option>
                    <option value="rounded">Rounded</option>
                    <option value="dots">Dots</option>
                    <option value="classy">Classy</option>
                    <option value="classy-rounded">Classy-rounded</option>
                    <option value="extra-rounded">Extra-rounded</option>
                </select>
            </div>

            <!-- Colors -->
            <div class="gen-color-row">
                <div class="gen-form-group">
                    <label for="genColorBody">Body Color</label>
                    <input type="color" id="genColorBody" name="color_body" value="#000000">
                </div>
                <div class="gen-form-group">
                    <label for="genColorBg">Background</label>
                    <input type="color" id="genColorBg" name="color_bg" value="#ffffff">
                </div>
            </div>

            <!-- Corner Style -->
            <div class="gen-form-group">
                <label for="genCornerStyle">Corner Style</label>
                <select id="genCornerStyle" name="corner_style">
                    <option value="square">Square</option>
                    <option value="dot">Dot</option>
                    <option value="rounded">Rounded</option>
                    <option value="extra-rounded">Extra-rounded</option>
                    <option value="classy">Classy</option>
                    <option value="classy-rounded">Classy-rounded</option>
                </select>
                <div class="corner-info">Corner squares and corner dots use this style</div>
            </div>

            <div class="gen-form-group">
                <label for="genColorFinders">Corner Color</label>
                <input type="color" id="genColorFinders" name="color_finders" value="#000000">
            </div>

            <!-- Logo Upload -->
            <div class="gen-form-group">
                <label>Logo (Optional)</label>
                <label class="gen-file-label" for="genLogo">
                    <span style="font-size:1.2rem;">&#128247;</span>
                    <span id="genLogoLabel">Choose an image (PNG/JPG)</span>
                </label>
                <input type="file" id="genLogo" name="logo" accept="image/png, image/jpeg">
            </div>

            <!-- Actions -->
            <div class="gen-actions">
                <?php if ($isLoggedIn): ?>
                    <button type="button" class="btn" onclick="saveQR()" id="saveBtn">&#128190; Save to Dashboard</button>
                <?php else: ?>
                    <p style="font-size:0.85rem; color:#888; width:100%; text-align:center; margin:10px 0 0 0;">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php">Sign in</a> to save QR codes to your dashboard.
                    </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (!$isLoggedIn): ?>
<div class="gen-public-notice">
    <strong>Tracking LaB</strong> &mdash; Free QR Code Generator. <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php">Sign in</a> to save designs and track scans.
</div>
<?php endif; ?>

<!-- ── SCRIPTS ──────────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/qr-code-styling@latest/lib/qr-code-styling.js"></script>
<script>
(function() {
    'use strict';

    const container  = document.getElementById('qrPreviewContainer');
    const inputTitle = document.getElementById('genTitle');
    const inputTarget = document.getElementById('genTarget');
    const selectDotStyle = document.getElementById('genDotStyle');
    const colorBody      = document.getElementById('genColorBody');
    const colorFinders   = document.getElementById('genColorFinders');
    const colorBg        = document.getElementById('genColorBg');
    const selectCornerStyle = document.getElementById('genCornerStyle');
    const fileLogo      = document.getElementById('genLogo');
    const logoLabel     = document.getElementById('genLogoLabel');

    let currentLogoDataUrl = null;

    function getQRData() {
        return inputTarget.value.trim() || 'https://example.com';
    }

    function getTitle() {
        return inputTitle.value.trim() || 'qr';
    }

    function buildOptions() {
        // Map corner style for cornersDotOptions — it has a slightly different type set
        var cornerDotType = selectCornerStyle.value;
        if (cornerDotType === 'dots') cornerDotType = 'dot';

        return {
            width: 300,
            height: 300,
            type: 'canvas',
            data: getQRData(),
            image: currentLogoDataUrl,
            margin: 0,
            qrOptions: {
                typeNumber: 0,
                mode: 'Byte',
                errorCorrectionLevel: 'H'
            },
            imageOptions: {
                hideBackgroundDots: true,
                imageSize: 0.4,
                margin: 0,
                crossOrigin: 'anonymous'
            },
            dotsOptions: {
                color: colorBody.value,
                type: selectDotStyle.value,
                roundSize: true
            },
            backgroundOptions: {
                color: colorBg.value
            },
            cornersSquareOptions: {
                color: colorFinders.value,
                type: selectCornerStyle.value
            },
            cornersDotOptions: {
                color: colorFinders.value,
                type: cornerDotType
            }
        };
    }

    let qr = null;
    let qrInitialized = false;

    function initQR() {
        if (!container) return;
        var opts = buildOptions();
        qr = new QRCodeStyling(opts);
        qr.append(container);
        qrInitialized = true;
    }

    function updateQR() {
        if (!qrInitialized) {
            initQR();
            return;
        }
        qr.update(buildOptions());
    }

    var updateTimer = null;
    function scheduleUpdate() {
        if (updateTimer) clearTimeout(updateTimer);
        updateTimer = setTimeout(updateQR, 120);
    }

    // ── Event listeners ───────────────────────────────────────────────────
    inputTarget.addEventListener('input', scheduleUpdate);
    selectDotStyle.addEventListener('change', scheduleUpdate);
    colorBody.addEventListener('input', scheduleUpdate);
    colorFinders.addEventListener('input', scheduleUpdate);
    colorBg.addEventListener('input', scheduleUpdate);
    selectCornerStyle.addEventListener('change', scheduleUpdate);

    // ── Logo file handling ─────────────────────────────────────────────────
    fileLogo.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) {
            currentLogoDataUrl = null;
            logoLabel.textContent = 'Choose an image (PNG/JPG)';
            scheduleUpdate();
            return;
        }
        logoLabel.textContent = file.name;
        var reader = new FileReader();
        reader.onload = function(ev) {
            currentLogoDataUrl = ev.target.result;
            scheduleUpdate();
        };
        reader.readAsDataURL(file);
    });

    // ── Initial render ────────────────────────────────────────────────────
    function ready() {
        setTimeout(initQR, 200);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }

    // ── Global functions ──────────────────────────────────────────────────
    window.downloadQR = function() {
        if (!qr || !qrInitialized) {
            alert('QR code is still loading. Please wait...');
            return;
        }
        qr.download({
            name: getTitle() || 'qr',
            extension: 'png'
        });
    };

    window.saveQR = function() {
        if (!inputTarget.value.trim()) {
            alert('Please enter a Target URL.');
            inputTarget.focus();
            return;
        }
        if (!inputTitle.value.trim()) {
            alert('Please enter a Title.');
            inputTitle.focus();
            return;
        }
        document.getElementById('saveForm').submit();
    };
})();
</script>

<!-- ── FOOTER ──────────────────────────────────────────────────────────────── -->
<?php if ($isLoggedIn): ?>
    <?php include THEME_PATH . '/footer.php'; ?>
    <!-- footer.php closes container div, body, and html -->
<?php else: ?>
</div><!-- /.container -->
</body>
</html>
<?php endif; ?>
