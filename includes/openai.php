<?php
declare(strict_types=1);

function wbc_openai_api_key(): string {
    return trim((string)wbc_setting('openai_api_key', ''));
}

function wbc_openai_project(): string {
    return trim((string)wbc_setting('openai_project', ''));
}

function wbc_openai_model(): string {
    return trim((string)wbc_setting('openai_text_model', 'gpt-5.4-nano'));
}

function wbc_openai_is_configured(): bool {
    return wbc_openai_api_key() !== '';
}

function wbc_caption_prompt(string $word): string {
    $template = (string)wbc_setting('caption_prompt_template', '');
    if (trim($template) === '') {
        throw new RuntimeException('Caption prompt is not configured in includes/settings.local.php.');
    }
    return str_replace('{word}', trim($word), $template);
}

function wbc_openai_response_text(array $json): string {
    $text = trim((string)($json['output_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    foreach ((array)($json['output'] ?? []) as $item) {
        foreach ((array)($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $text = trim((string)($content['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }
    }

    return '';
}

function wbc_openai_generate_caption_from_prompt(string $prompt): array {
    if (!wbc_openai_is_configured()) {
        return [false, '', 'OpenAI API key is not configured.'];
    }

    $prompt = trim($prompt);
    if ($prompt === '') {
        return [false, '', 'Prompt is empty.'];
    }

    $payload = [
        'model' => wbc_openai_model(),
        'input' => [[
            'role' => 'user',
            'content' => [[
                'type' => 'input_text',
                'text' => $prompt,
            ]],
        ]],
        'text' => [
            'format' => ['type' => 'text'],
        ],
        'max_output_tokens' => 120,
    ];

    $tmpDir = WBC_DATA . '/tmp';
    wbc_ensure_dir($tmpDir);
    $payloadPath = $tmpDir . '/openai_' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $curl = wbc_detect_curl_binary();

    $cmd = [
        $curl,
        '-sS',
        '--fail-with-body',
        '--max-time',
        '60',
        '-X',
        'POST',
        'https://api.openai.com/v1/responses',
        '-H',
        'Authorization: Bearer ' . wbc_openai_api_key(),
        '-H',
        'Content-Type: application/json',
    ];

    $project = wbc_openai_project();
    if ($project !== '') {
        $cmd[] = '-H';
        $cmd[] = 'OpenAI-Project: ' . $project;
    }

    $cmd[] = '--data-binary';
    $cmd[] = '@' . $payloadPath;

    $result = wbc_run_process($cmd);
    @unlink($payloadPath);

    if (!$result['ok']) {
        $stderr = trim((string)$result['stderr']);
        return [false, '', $stderr !== '' ? $stderr : 'OpenAI request failed.'];
    }

    $json = json_decode((string)$result['stdout'], true);
    if (!is_array($json)) {
        return [false, '', 'OpenAI returned unreadable JSON.'];
    }

    $text = wbc_openai_response_text($json);
    if ($text === '') {
        $message = (string)($json['error']['message'] ?? 'OpenAI did not return any caption text.');
        return [false, '', $message];
    }

    return [true, $text, ''];
}

function wbc_openai_generate_caption(string $word): array {
    try {
        $prompt = wbc_caption_prompt($word);
    } catch (Throwable $e) {
        return [false, '', $e->getMessage()];
    }

    return wbc_openai_generate_caption_from_prompt($prompt);
}
