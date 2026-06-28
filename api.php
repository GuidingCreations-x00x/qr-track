<?php
require 'config.php';

header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');

// --- DEFAULT KEY GUARD ---
if (API_KEY === 'change_me_to_a_random_string') {
    http_response_code(503);
    echo json_encode([
        'status'      => 'error',
        'message'     => 'API Disabled: Default insecure API Key detected.',
        'instruction' => 'Edit config.php and set API_KEY to a secure random string.',
    ]);
    exit;
}

// --- RATE LIMITING ---
if (API_THROTTLE_ENABLED) {
    $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now       = time();
    $windowStart = $now - API_THROTTLE_WINDOW;

    // Fetch or create rate limit record for this IP
    $rl = $db->prepare("SELECT window_start, request_count FROM rate_limit WHERE ip = ?");
    $rl->execute([$clientIp]);
    $rlRow = $rl->fetch();

    if (!$rlRow || $rlRow['window_start'] < $windowStart) {
        // New window — reset
        $db->prepare("INSERT OR REPLACE INTO rate_limit (ip, window_start, request_count) VALUES (?, ?, 1)")
           ->execute([$clientIp, $now]);
    } else {
        if ($rlRow['request_count'] >= API_THROTTLE_LIMIT) {
            http_response_code(429);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Too Many Requests. Limit: ' . API_THROTTLE_LIMIT . ' per ' . API_THROTTLE_WINDOW . 's.',
            ]);
            exit;
        }
        $db->prepare("UPDATE rate_limit SET request_count = request_count + 1 WHERE ip = ?")
           ->execute([$clientIp]);
    }
}

// --- AUTHENTICATION (header only — no GET fallback) ---
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$authKey = $headers['x-api-key'] ?? null;

if ($authKey === null || !hash_equals(API_KEY, $authKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid API Key']);
    exit;
}

// --- CLEANUP ---
purge_old_tokens($db);

// --- ROUTER ---
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    handleCreate($db);
} elseif ($method === 'GET') {
    handleGet($db);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}

// --- HELPERS ---

function getOrCreateImageToken($db, $uuid) {
    // Reuse an existing valid token if one exists — avoids table bloat
    $stmt = $db->prepare("SELECT token FROM api_tokens WHERE product_uuid = ? AND expires_at > datetime('now') LIMIT 1");
    $stmt->execute([$uuid]);
    $row = $stmt->fetch();
    if ($row) {
        return $row['token'];
    }
    // None found — create a new one
    $token = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO api_tokens (token, product_uuid, expires_at) VALUES (?, ?, datetime('now', '+24 hours'))")
       ->execute([$token, $uuid]);
    return $token;
}

function handleGet($db) {
    $uuid = isset($_GET['uuid']) ? trim($_GET['uuid']) : '';

    // SINGLE GET — uuid must be a non-empty string
    if ($uuid !== '') {
        $stmt = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count FROM products p WHERE uuid = ? AND is_deleted = 0");
        $stmt->execute([$uuid]);
        $data = $stmt->fetch();

        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'QR Code not found']);
            return;
        }
        echo json_encode(['status' => 'success', 'data' => formatQrData($db, $data)]);
        return;
    }

    // LIST ALL — no uuid provided
    $stmt = $db->query("SELECT p.*, (SELECT COUNT(*) FROM scans WHERE product_uuid = p.uuid) as scan_count FROM products p WHERE is_deleted = 0 ORDER BY created_at DESC");
    $rows = $stmt->fetchAll();

    $output = [];
    foreach ($rows as $row) {
        $output[] = formatQrData($db, $row);
    }

    echo json_encode(['status' => 'success', 'count' => count($output), 'data' => $output]);
}

function handleCreate($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['title']) || !isset($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON. Required fields: title, type']);
        return;
    }

    $allowedTypes = ['url', 'phone', 'map', 'vcard', 'wifi', 'sms', 'email', 'social'];
    if (!in_array($input['type'], $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type. Allowed: ' . implode(', ', $allowedTypes)]);
        return;
    }

    $uuid   = bin2hex(random_bytes(6));
    $title  = trim($input['title']);
    $type   = $input['type'];
    $target = '';

    switch ($type) {
        case 'url':
        case 'map':
        case 'social':
            $target = $input['target'] ?? '';
            break;
        case 'phone':
            $target = $input['phone'] ?? '';
            break;
        case 'wifi':
            $target = json_encode([
                'ssid' => $input['ssid'] ?? '',
                'pass' => $input['pass'] ?? '',
                'enc'  => $input['enc']  ?? 'WPA',
            ]);
            break;
        case 'vcard':
            $target = json_encode([
                'fname'   => $input['fname']   ?? '',
                'lname'   => $input['lname']   ?? '',
                'phone'   => $input['phone']   ?? '',
                'email'   => $input['email']   ?? '',
                'company' => $input['company'] ?? '',
            ]);
            break;
        case 'sms':
            $target = json_encode([
                'phone' => $input['phone'] ?? '',
                'body'  => $input['body']  ?? '',
            ]);
            break;
        case 'email':
            $target = json_encode([
                'email'   => $input['email']   ?? '',
                'subject' => $input['subject'] ?? '',
                'body'    => $input['body']    ?? '',
            ]);
            break;
    }

    try {
        $stmt = $db->prepare("INSERT INTO products (uuid, title, type, target_data) VALUES (?, ?, ?, ?)");
        $stmt->execute([$uuid, $title, $type, $target]);

        $token = getOrCreateImageToken($db, $uuid);

        echo json_encode([
            'status'        => 'created',
            'uuid'          => $uuid,
            'tracking_url'  => BASE_URL . '/p/' . $uuid,
            'image_url_png' => BASE_URL . '/generate_image.php?id=' . $uuid . '&format=png&token=' . $token,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}

function formatQrData($db, $row) {
    $targetDecoded = json_decode($row['target_data']);
    $finalTarget   = (json_last_error() === JSON_ERROR_NONE) ? $targetDecoded : $row['target_data'];
    $token         = getOrCreateImageToken($db, $row['uuid']);

    return [
        'uuid'       => $row['uuid'],
        'title'      => $row['title'],
        'type'       => $row['type'],
        'target'     => $finalTarget,
        'scans'      => (int)$row['scan_count'],
        'is_active'  => (bool)$row['is_active'],
        'created_at' => $row['created_at'],
        'links'      => [
            'tracking_url' => BASE_URL . '/p/' . $row['uuid'],
            'image_png'    => BASE_URL . '/generate_image.php?id=' . $row['uuid'] . '&format=png&token=' . $token,
            'image_jpg'    => BASE_URL . '/generate_image.php?id=' . $row['uuid'] . '&format=jpg&token=' . $token,
        ],
    ];
}
