<?php
$token         = $_GET['token'] ?? '';
$valid_tokens  = [getenv('RECEIVE_TOKEN'), getenv('RECEIVE_TOKEN_2')];
if (!in_array($token, array_filter($valid_tokens), true)) {
    http_response_code(403);
    exit('Forbidden');
}

$oa   = $_POST['oa']   ?? $_GET['oa']   ?? '';
$da   = $_POST['da']   ?? $_GET['da']   ?? '';
$ud   = $_POST['ud']   ?? $_GET['ud']   ?? '';
$scts = $_POST['scts'] ?? $_GET['scts'] ?? date('c');

if (!$oa || !$da || !$ud) {
    http_response_code(400);
    exit('Bad Request');
}

$db = new PDO('sqlite:/var/www/data/messages.db');
$db->exec('CREATE TABLE IF NOT EXISTS messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    da          TEXT,
    oa          TEXT,
    ud          TEXT,
    scts        TEXT,
    received_at TEXT
)');
$db->exec('CREATE TABLE IF NOT EXISTS push_tokens (
    account    TEXT PRIMARY KEY,
    selector   TEXT,
    push_token TEXT,
    push_appid TEXT,
    updated_at TEXT
)');

// Deduplicate: same sender, recipient, and content within 2 minutes
$window = date('Y-m-d H:i:s', time() - 120);
$check = $db->prepare(
    "SELECT id FROM messages WHERE oa = ? AND da = ? AND ud = ? AND received_at > ?"
);
$check->execute([$oa, $da, $ud, $window]);
$duplicate = $check->fetchColumn();

if (!$duplicate) {
    $stmt = $db->prepare('INSERT INTO messages (da, oa, ud, scts, received_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$da, $oa, $ud, $scts, date('c')]);
}

// Respond to AAISP immediately
ignore_user_abort(true);
ob_start();
http_response_code(200);
echo 'OK';
$body = ob_get_clean();
header('Connection: close');
header('Content-Length: ' . strlen($body));
echo $body;
flush();

if ($duplicate) exit();

// Count pending messages for badge
$stmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE da = ?');
$stmt->execute([$da]);
$badge = (int)$stmt->fetchColumn() ?: 1;

// Look up push token for this number
$stmt = $db->prepare('SELECT * FROM push_tokens WHERE account = ?');
$stmt->execute([$da]);
$push = $stmt->fetch(PDO::FETCH_ASSOC);

if ($push && $push['push_token'] && $push['push_appid']) {
    $payload = [
        'verb'        => 'NotifyTextMessage',
        'AppId'       => $push['push_appid'],
        'DeviceToken' => $push['push_token'],
        'Selector'    => $push['selector'],
        'Badge'       => (int)$badge,
        'Sound'       => 'default',
        'UserName'    => $oa,
        'Message'     => $ud,
    ];

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 5,
    ]]);
    @file_get_contents('https://pnm.cloudsoftphone.com/pnm2/send', false, $ctx);
}
