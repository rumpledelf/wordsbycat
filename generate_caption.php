<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST only.']);
    exit;
}

$word = wbc_post_string('word');
$prompt = wbc_post_string('prompt');

if ($word === '' && $prompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Word or prompt is required.']);
    exit;
}

[$ok, $caption, $message] = $prompt !== ''
    ? wbc_openai_generate_caption_from_prompt($prompt)
    : wbc_openai_generate_caption($word);

if (!$ok) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

echo json_encode([
    'ok' => true,
    'caption' => $caption,
]);
