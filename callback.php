<?php

define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

$raw = file_get_contents('php://input');
$data = json_decode($raw);

$token = $data->token ?? '';
$expected = get_config('local_log_sender', 'lrs_callback_token');

if ($token !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$payload = json_decode($data->data);
error_log("Received callback: " . json_encode($data));
$outputpath = __DIR__  . '/lrs_callback.json';
file_put_contents($outputpath, json_encode($payload, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'success']);
