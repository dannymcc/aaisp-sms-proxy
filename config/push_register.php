<?php
if (($_GET['token'] ?? '') !== getenv('SMS_TOKEN')) {
    http_response_code(403);
    exit('Forbidden');
}

$account    = $_GET['account']    ?? '';
$selector   = $_GET['selector']   ?? '';
$push_token = $_GET['push_token'] ?? '';
$push_appid = $_GET['push_appid'] ?? '';

if (!$account || !$push_token || !$push_appid) {
    http_response_code(400);
    exit('Missing parameters');
}

$db = new PDO('sqlite:/var/www/data/messages.db');
$db->exec('CREATE TABLE IF NOT EXISTS push_tokens (
    account    TEXT PRIMARY KEY,
    selector   TEXT,
    push_token TEXT,
    push_appid TEXT,
    updated_at TEXT
)');

$stmt = $db->prepare('INSERT INTO push_tokens (account, selector, push_token, push_appid, updated_at)
    VALUES (?, ?, ?, ?, ?)
    ON CONFLICT(account) DO UPDATE SET
        selector   = excluded.selector,
        push_token = excluded.push_token,
        push_appid = excluded.push_appid,
        updated_at = excluded.updated_at');
$stmt->execute([$account, $selector, $push_token, $push_appid, date('c')]);

http_response_code(200);
