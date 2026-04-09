<?php
try {
    $db = new PDO('sqlite:/var/www/data/messages.db');
    $db->query('SELECT 1');
    $status = 'ok';
} catch (Exception $e) {
    http_response_code(500);
    $status = 'error';
}
header('Content-Type: application/json');
echo json_encode(['status' => $status]);
