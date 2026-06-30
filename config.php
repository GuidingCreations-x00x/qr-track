<?php
declare(strict_types=1);

// --- AUTHENTICATION SETTINGS ---
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'Connect@4045');

// --- API SETTINGS ---
// Generated: 2026-06-28 for GuidingCreations-x00x/qr-track
define('API_KEY', '109851ad435a40fa47ce82271ffa0997de4e147fdc9ccbfc6e9c8afe7d5de79b');

// --- SITE SETTINGS ---
define('BASE_URL',   'https://sienna-tiger-551178.hostingersite.com');

// Absolute paths — data dir is in public_html/qr-data/ (protected by .htaccess)
define('DB_PATH',    '/home/u716917981/domains/sienna-tiger-551178.hostingersite.com/public_html/qr-data/tuxxin_qr.sqlite');
define('LOGO_DIR',   '/home/u716917981/domains/sienna-tiger-551178.hostingersite.com/public_html/qr-data/logos');

define('TIMEZONE',   'America/Chicago');
define('THEME_PATH', __DIR__ . '/themes');

// --- NETWORK SETTINGS ---
define('USE_CLOUDFLARE_TUNNEL', false);

// --- DISABLED QR CODE PAGE ---
define('DISABLED_REDIRECT_URL', '');

// --- API RATE THROTTLING ---
define('API_THROTTLE_ENABLED', true);
define('API_THROTTLE_LIMIT',  60);
define('API_THROTTLE_WINDOW', 60);

// --- SESSION SETTINGS ---
define('SESSION_LIFETIME', 7200);

// =============================================================================
// END OF CONFIGURATION
// =============================================================================

function require_auth() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
    $_SESSION['last_active'] = time();

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid request (CSRF check failed).');
    }
}

function purge_old_tokens($db) {
    $db->exec("DELETE FROM api_tokens WHERE expires_at < datetime('now')");
}

// --- DATABASE CONNECTION ---
$dbDir  = dirname(DB_PATH);
$dbFile = DB_PATH;

if ((!is_dir($dbDir) || !is_writable($dbDir)) || (file_exists($dbFile) && !is_writable($dbFile))) {
    exit("Database Permission Error: PHP cannot write to $dbDir. Check that the directory exists and is writable by the web server.");
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT UNIQUE,
        title TEXT,
        type TEXT,
        target_data TEXT,
        logo_path TEXT DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_uuid TEXT,
        ip_address TEXT,
        user_agent TEXT,
        scan_status TEXT DEFAULT 'success',
        scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_uuid) REFERENCES products(uuid)
    )");

    // Migration: add geo columns if missing
    $columns = $db->query("PRAGMA table_info(scans)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('geo_city', $columns)) {
        $db->exec("ALTER TABLE scans ADD COLUMN geo_city TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_region TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_country TEXT");
        $db->exec("ALTER TABLE scans ADD COLUMN geo_isp TEXT");
    }

    // Migration: add design option columns if missing
    $columns = $db->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('dot_modules', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN dot_modules INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE products ADD COLUMN logo_frame INTEGER DEFAULT 0");
        $db->exec("ALTER TABLE products ADD COLUMN color_body TEXT DEFAULT '#000000'");
        $db->exec("ALTER TABLE products ADD COLUMN color_finders TEXT DEFAULT '#000000'");
        $db->exec("ALTER TABLE products ADD COLUMN color_bg TEXT DEFAULT '#ffffff'");
    }
    if (!in_array('dot_style', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN dot_style TEXT DEFAULT 'square'");
        $db->exec("ALTER TABLE products ADD COLUMN corner_style TEXT DEFAULT 'square'");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE,
        product_uuid TEXT,
        expires_at DATETIME
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_token ON api_tokens(token)");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limit (
        ip TEXT PRIMARY KEY,
        window_start INTEGER NOT NULL,
        request_count INTEGER NOT NULL DEFAULT 1
    )");

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}