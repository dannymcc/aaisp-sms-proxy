<?php
$db     = new PDO('sqlite:/var/www/data/messages.db');
$cutoff = date('c', strtotime('-14 days'));

$stmt = $db->prepare('DELETE FROM messages WHERE received_at < ?');
$stmt->execute([$cutoff]);
$pruned = $db->query('SELECT changes()')->fetchColumn();

echo date('c') . " — pruned $pruned message(s) older than 14 days\n";
