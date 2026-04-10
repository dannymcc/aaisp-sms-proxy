<?php
// Allow CLI execution (cron) or authenticated HTTP requests
$is_cli = php_sapi_name() === 'cli';
if (!$is_cli && ($_GET['token'] ?? '') !== getenv('SMS_TOKEN')) {
    http_response_code(403);
    exit('Forbidden');
}

// Check if heartbeat is enabled (default: true)
$enabled = strtolower(getenv('HEARTBEAT_ENABLED') ?: 'true');
if ($enabled === 'false' || $enabled === '0') {
    echo date('c') . " — heartbeat disabled, skipping\n";
    exit(0);
}

// Daytime window check (default: 08:00–21:00 local server time)
$start_hour   = (int)(getenv('HEARTBEAT_START_HOUR') ?: 8);
$end_hour     = (int)(getenv('HEARTBEAT_END_HOUR') ?: 21);
$current_hour = (int)date('G');

if ($current_hour < $start_hour || $current_hour >= $end_hour) {
    echo date('c') . " — outside daytime window ({$start_hour}:00–{$end_hour}:00), skipping\n";
    exit(0);
}

// Resolve the target account: explicit override, or first AAISP_*_USERNAME in env
$account = getenv('HEARTBEAT_ACCOUNT') ?: null;
if (!$account) {
    foreach (getenv() as $key => $value) {
        if (preg_match('/^AAISP_[A-Z0-9_]+_USERNAME$/', $key) && $value) {
            $account = $value;
            break;
        }
    }
}

if (!$account) {
    echo date('c') . " — no AAISP account found, skipping\n";
    exit(1);
}

$db = new PDO('sqlite:/var/www/data/messages.db');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS push_tokens (
    account    TEXT PRIMARY KEY,
    selector   TEXT,
    push_token TEXT,
    push_appid TEXT,
    updated_at TEXT
)');

$da  = $account;
$ud  = 'SMS proxy heartbeat: server is up and running. ' . date('D j M Y, H:i');

// Send push notification only — do not insert to DB.
// Groundwire wakes, polls fetch.php, finds nothing, and generates no second notification.
// This ensures the user sees exactly one notification.
$stmt = $db->prepare('SELECT * FROM push_tokens WHERE account = ?');
$stmt->execute([$da]);
$push = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$push || !$push['push_token'] || !$push['push_appid']) {
    echo date('c') . " — no push token for {$account}, cannot send heartbeat\n";
    exit(1);
}

$payload = [
    'verb'        => 'NotifyGenericTextMessage',
    'AppId'       => $push['push_appid'],
    'DeviceToken' => $push['push_token'],
    'Message'     => $ud,
];

$ctx    = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => json_encode($payload),
    'timeout' => 5,
]]);
$result = @file_get_contents('https://pnm.cloudsoftphone.com/pnm2/send', false, $ctx);
echo date('c') . " — heartbeat push sent to {$account} (result=" . ($result !== false ? 'ok' : 'failed') . ")\n";
