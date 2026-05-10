<?php
declare(strict_types=1);

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wbc_setting(string $key, mixed $default = null): mixed {
    global $wbc_settings;
    return $wbc_settings[$key] ?? $default;
}

function wbc_post_string(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function wbc_repo_relpath(string $absPath): string {
    $prefix = rtrim(str_replace('\\', '/', WBC_ROOT), '/') . '/';
    $absPath = str_replace('\\', '/', $absPath);
    if (str_starts_with($absPath, $prefix)) {
        return substr($absPath, strlen($prefix));
    }
    return $absPath;
}

function wbc_ensure_dir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create directory: ' . $path);
    }
}

function wbc_slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'untitled';
}

function wbc_derive_letter(string $word): string {
    $word = strtolower(trim($word));
    return preg_match('/^[a-z]/', $word) === 1 ? $word[0] : 'x';
}

function wbc_letter_json_path(string $letter): string {
    return WBC_DATA . '/letters/' . $letter . '.json';
}

function wbc_load_letter_entries(string $letter): array {
    $path = wbc_letter_json_path($letter);
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function wbc_write_letter_entries(string $letter, array $entries): void {
    $path = wbc_letter_json_path($letter);
    wbc_ensure_dir(dirname($path));

    usort($entries, static function (array $a, array $b): int {
        $wordCmp = strcasecmp((string)($a['word'] ?? ''), (string)($b['word'] ?? ''));
        if ($wordCmp !== 0) {
            return $wordCmp;
        }
        return strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? ''));
    });

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open JSON file for writing.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock JSON file for writing.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException('Could not write JSON file.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function wbc_unique_slug(string $letter, string $baseSlug): string {
    $existing = wbc_load_letter_entries($letter);
    $used = [];
    foreach ($existing as $entry) {
        $slug = (string)($entry['slug'] ?? '');
        if ($slug !== '') {
            $used[$slug] = true;
        }
    }

    if (!isset($used[$baseSlug])) {
        return $baseSlug;
    }

    $i = 2;
    while (isset($used[$baseSlug . '-' . $i])) {
        $i++;
    }
    return $baseSlug . '-' . $i;
}

function wbc_detect_imagemagick(): string {
    static $binary = '';
    if ($binary !== '') {
        return $binary;
    }

    $candidates = [
        '/usr/local/bin/magick',
        '/opt/homebrew/bin/magick',
        '/usr/bin/magick',
        '/usr/local/bin/convert',
        '/opt/homebrew/bin/convert',
        '/usr/bin/convert',
    ];

    foreach ($candidates as $candidate) {
        $result = wbc_run_process([$candidate, '-version']);
        if ($result['ok']) {
            $binary = $candidate;
            return $binary;
        }
    }

    throw new RuntimeException('ImageMagick was not found.');
}

function wbc_detect_curl_binary(): string {
    static $binary = '';
    if ($binary !== '') {
        return $binary;
    }

    $candidates = [
        '/opt/local/bin/curl',
        '/usr/bin/curl',
        '/usr/local/bin/curl',
        '/opt/homebrew/bin/curl',
    ];

    foreach ($candidates as $candidate) {
        $result = wbc_run_process([$candidate, '--version']);
        if ($result['ok']) {
            $binary = $candidate;
            return $binary;
        }
    }

    throw new RuntimeException('curl was not found.');
}

function wbc_run_process(array $cmd): array {
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($proc)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'code' => -1];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [
        'ok' => $code === 0,
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
        'code' => (int)$code,
    ];
}

function wbc_image_extension_from_mime(string $mime): string {
    return match (strtolower($mime)) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'bin',
    };
}

function wbc_square_target(string $sourceAbs, string $destAbs, int $size): void {
    $binary = wbc_detect_imagemagick();
    wbc_ensure_dir(dirname($destAbs));
    $result = wbc_run_process([
        $binary,
        $sourceAbs,
        '-auto-orient',
        '-resize',
        $size . 'x' . $size . '^',
        '-gravity',
        'center',
        '-extent',
        $size . 'x' . $size,
        '-strip',
        '-quality',
        '88',
        $destAbs,
    ]);

    if (!$result['ok'] || !is_file($destAbs)) {
        throw new RuntimeException('Could not generate square thumbnail.');
    }
}

function wbc_ratio_target(string $sourceAbs, string $destAbs, int $width, int $height): void {
    $binary = wbc_detect_imagemagick();
    wbc_ensure_dir(dirname($destAbs));
    $result = wbc_run_process([
        $binary,
        $sourceAbs,
        '-auto-orient',
        '-resize',
        $width . 'x' . $height . '^',
        '-gravity',
        'center',
        '-extent',
        $width . 'x' . $height,
        '-strip',
        '-quality',
        '88',
        $destAbs,
    ]);

    if (!$result['ok'] || !is_file($destAbs)) {
        throw new RuntimeException('Could not generate 4:3 image derivative.');
    }
}

function wbc_get_image_dimensions(string $path): array {
    $info = @getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('Could not read image dimensions.');
    }

    return ['width' => (int)$info[0], 'height' => (int)$info[1]];
}

function wbc_store_entry_assets(string $letter, string $slug, array $flickr): array {
    $tmpPath = (string)($flickr['downloaded_tmp'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new RuntimeException('Downloaded source image was not available.');
    }

    $mime = (string)($flickr['mime'] ?? '');
    $ext = wbc_image_extension_from_mime($mime);

    $originalAbs = WBC_DATA . '/originals/' . $letter . '/' . $slug . '.' . $ext;
    $mainAbs = WBC_DATA . '/derived/' . $letter . '/' . $slug . '.jpg';
    $thumbAbs = WBC_DATA . '/thumbs/' . $letter . '/' . $slug . '.jpg';

    wbc_ensure_dir(dirname($originalAbs));
    if (!rename($tmpPath, $originalAbs)) {
        if (!copy($tmpPath, $originalAbs)) {
            throw new RuntimeException('Could not store original source image.');
        }
        @unlink($tmpPath);
    }

    wbc_ratio_target(
        $originalAbs,
        $mainAbs,
        (int)wbc_setting('main_image_width', 1600),
        (int)wbc_setting('main_image_height', 1200)
    );
    wbc_square_target($originalAbs, $thumbAbs, (int)wbc_setting('thumb_size', 400));

    $originalDims = wbc_get_image_dimensions($originalAbs);
    $mainDims = wbc_get_image_dimensions($mainAbs);
    $thumbDims = wbc_get_image_dimensions($thumbAbs);

    return [
        'original' => [
            'path' => wbc_repo_relpath($originalAbs),
            'width' => $originalDims['width'],
            'height' => $originalDims['height'],
            'mime' => $mime,
        ],
        'main' => [
            'path' => wbc_repo_relpath($mainAbs),
            'width' => $mainDims['width'],
            'height' => $mainDims['height'],
        ],
        'thumb' => [
            'path' => wbc_repo_relpath($thumbAbs),
            'width' => $thumbDims['width'],
            'height' => $thumbDims['height'],
        ],
    ];
}

function wbc_recent_entries(int $limit = 12): array {
    $entries = [];
    foreach (glob(WBC_DATA . '/letters/*.json') ?: [] as $path) {
        $json = file_get_contents($path);
        if ($json === false || trim($json) === '') {
            continue;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return array_slice($entries, 0, $limit);
}
