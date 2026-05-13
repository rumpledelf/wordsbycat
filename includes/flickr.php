<?php
declare(strict_types=1);

const WBC_HTTP_USER_AGENT = 'Mozilla/5.0';

function wbc_is_flickr_url(string $url): bool {
    $url = strtolower(trim($url));
    if ($url === '') {
        return false;
    }

    return wbc_contains($url, 'flickr.com')
        || wbc_contains($url, 'staticflickr.com')
        || wbc_contains($url, 'live.staticflickr.com');
}

function wbc_normalize_https_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (wbc_starts_with($url, 'http://')) {
        return 'https://' . substr($url, 7);
    }
    if (wbc_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    return $url;
}

function wbc_probe_flickr_by_url(string $url): array {
    $url = wbc_normalize_https_url($url);

    if (wbc_is_flickr_static_asset_url($url)) {
        return wbc_probe_static_flickr_asset($url);
    }

    if (wbc_contains(strtolower($url), 'flickr.com/photos/')) {
        return wbc_probe_flickr_photo_page($url);
    }

    return [
        'ok' => false,
        'message' => 'That Flickr URL could not be parsed.',
    ];
}

function wbc_probe_flickr_photo_page(string $url): array {
    $page = wbc_fetch_url($url);
    if (!$page['ok']) {
        return ['ok' => false, 'message' => $page['message']];
    }

    $meta = wbc_extract_flickr_photo_page_meta((string)$page['body'], $url);
    if (empty($meta['sizes'])) {
        return ['ok' => false, 'message' => 'No downloadable image sizes were found on that Flickr page.'];
    }

    foreach ($meta['sizes'] as $size) {
        $download = wbc_download_image_to_staging((string)$size['url']);
        if (!$download['ok']) {
            continue;
        }

        return [
            'ok' => true,
            'downloaded_tmp' => $download['tmp'],
            'mime' => $download['mime'],
            'source_page_url' => (string)$meta['page_url'],
            'source_image_url' => (string)$size['url'],
            'source_author' => (string)$meta['author_name'],
            'source_license' => (string)$meta['license_name'],
            'source_license_url' => (string)$meta['license_url'],
        ];
    }

    return ['ok' => false, 'message' => 'The Flickr image could not be downloaded.'];
}

function wbc_probe_static_flickr_asset(string $url): array {
    foreach (wbc_build_flickr_size_variant_urls($url) as $candidateUrl) {
        $download = wbc_download_image_to_staging($candidateUrl);
        if (!$download['ok']) {
            continue;
        }

        return [
            'ok' => true,
            'downloaded_tmp' => $download['tmp'],
            'mime' => $download['mime'],
            'source_page_url' => $url,
            'source_image_url' => $candidateUrl,
            'source_author' => '',
            'source_license' => '',
            'source_license_url' => '',
        ];
    }

    return ['ok' => false, 'message' => 'No larger Flickr image could be fetched from that asset URL.'];
}

function wbc_fetch_url(string $url): array {
    $curl = wbc_detect_curl_binary();
    $result = wbc_run_process([
        $curl,
        '-L',
        '-sS',
        '--max-time',
        '20',
        '--connect-timeout',
        '10',
        '--max-redirs',
        '3',
        '-A',
        WBC_HTTP_USER_AGENT,
        '-w',
        "\nWBC_HTTP_STATUS:%{http_code}",
        $url,
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => 'Could not fetch the Flickr page.', 'body' => ''];
    }

    $output = (string)$result['stdout'];
    $marker = "\nWBC_HTTP_STATUS:";
    $pos = strrpos($output, $marker);
    $body = $pos === false ? $output : substr($output, 0, $pos);
    $status = $pos === false ? 0 : (int)substr($output, $pos + strlen($marker), 3);

    if ($status >= 400 || trim($body) === '') {
        return ['ok' => false, 'message' => 'Flickr returned HTTP ' . $status . '.', 'body' => ''];
    }

    return ['ok' => true, 'message' => '', 'body' => $body];
}

function wbc_download_image_to_staging(string $url): array {
    $curl = wbc_detect_curl_binary();
    $tmpDir = WBC_DATA . '/tmp';
    wbc_ensure_dir($tmpDir);
    $tmp = $tmpDir . '/flickr_' . bin2hex(random_bytes(8)) . '.bin';

    $result = wbc_run_process([
        $curl,
        '-L',
        '-sS',
        '--fail',
        '--max-time',
        '30',
        '--connect-timeout',
        '10',
        '--max-redirs',
        '3',
        '-A',
        WBC_HTTP_USER_AGENT,
        '-o',
        $tmp,
        $url,
    ]);

    if (!$result['ok'] || !is_file($tmp) || filesize($tmp) <= 0) {
        @unlink($tmp);
        return ['ok' => false, 'tmp' => '', 'mime' => ''];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!wbc_starts_with($mime, 'image/')) {
        @unlink($tmp);
        return ['ok' => false, 'tmp' => '', 'mime' => ''];
    }

    return ['ok' => true, 'tmp' => $tmp, 'mime' => $mime];
}

function wbc_is_flickr_static_asset_url(string $url): bool {
    $url = strtolower($url);
    return wbc_contains($url, 'staticflickr.com') || wbc_contains($url, 'live.staticflickr.com');
}

function wbc_extract_flickr_photo_page_meta(string $html, string $fallbackUrl): array {
    $pageUrl = wbc_extract_html_attr($html, 'meta', 'property', 'og:url', 'content');
    if ($pageUrl === '') {
        $pageUrl = wbc_extract_html_attr($html, 'link', 'rel', 'canonical', 'href');
    }
    $pageUrl = wbc_normalize_https_url($pageUrl !== '' ? $pageUrl : $fallbackUrl);

    $authorName = wbc_extract_flickr_author_name($html);
    $licenseUrl = wbc_extract_flickr_license_url($html);
    $licenseName = wbc_extract_flickr_license_name($html, $licenseUrl);
    $sizes = wbc_extract_flickr_size_urls($html);
    $ogImage = wbc_extract_html_attr($html, 'meta', 'property', 'og:image', 'content');

    $sizesByUrl = [];
    foreach ($sizes as $size) {
        if (!empty($size['url'])) {
            $sizesByUrl[(string)$size['url']] = $size;
        }
    }

    if (!$sizesByUrl && $ogImage !== '') {
        $sizesByUrl[wbc_normalize_flickr_asset_url($ogImage)] = [
            'url' => wbc_normalize_flickr_asset_url($ogImage),
            'width' => (int)wbc_extract_html_attr($html, 'meta', 'property', 'og:image:width', 'content'),
            'height' => (int)wbc_extract_html_attr($html, 'meta', 'property', 'og:image:height', 'content'),
            'fallback_rank' => 0,
        ];
    }

    if ($ogImage !== '') {
        $rank = 1;
        foreach (wbc_build_flickr_size_variant_urls($ogImage) as $variantUrl) {
            $variantUrl = wbc_normalize_flickr_asset_url($variantUrl);
            if ($variantUrl === '') {
                continue;
            }
            if (!isset($sizesByUrl[$variantUrl])) {
                $sizesByUrl[$variantUrl] = [
                    'url' => $variantUrl,
                    'width' => 0,
                    'height' => 0,
                    'fallback_rank' => $rank,
                ];
            }
            $rank++;
        }
    }

    $sizes = array_values($sizesByUrl);
    usort($sizes, static function (array $a, array $b): int {
        return wbc_compare_flickr_candidate_size($a, $b);
    });

    return [
        'page_url' => $pageUrl,
        'author_name' => $authorName,
        'license_url' => $licenseUrl,
        'license_name' => $licenseName,
        'sizes' => $sizes,
    ];
}

function wbc_compare_flickr_candidate_size(array $a, array $b): int {
    $aKnown = !empty($a['width']) && !empty($a['height']);
    $bKnown = !empty($b['width']) && !empty($b['height']);

    if ($aKnown && $bKnown) {
        $aArea = (int)$a['width'] * (int)$a['height'];
        $bArea = (int)$b['width'] * (int)$b['height'];
        return $bArea <=> $aArea;
    }

    if ($aKnown !== $bKnown) {
        return $aKnown ? -1 : 1;
    }

    return ((int)($a['fallback_rank'] ?? 9999)) <=> ((int)($b['fallback_rank'] ?? 9999));
}

function wbc_extract_html_attr(string $html, string $tag, string $matchAttr, string $matchValue, string $wantedAttr): string {
    $tag = preg_quote($tag, '#');
    $matchAttr = preg_quote($matchAttr, '#');
    $matchValue = preg_quote($matchValue, '#');
    $wantedAttr = preg_quote($wantedAttr, '#');

    if (!preg_match_all('#<' . $tag . '\b[^>]*>#i', $html, $tags)) {
        return '';
    }

    foreach ($tags[0] as $rawTag) {
        if (!preg_match('#\b' . $matchAttr . '\s*=\s*["\']' . $matchValue . '["\']#i', $rawTag)) {
            continue;
        }
        if (preg_match('#\b' . $wantedAttr . '\s*=\s*(["\'])(.*?)\1#i', $rawTag, $m)) {
            return html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return '';
}

function wbc_extract_flickr_author_name(string $html): string {
    if (preg_match('#"owner":\{"data":\{.*?"username":"([^"]*)".*?"realname":"([^"]*)"#s', $html, $m)) {
        $realname = wbc_decode_js_string($m[2]);
        return $realname !== '' ? $realname : wbc_decode_js_string($m[1]);
    }

    if (preg_match('#<title>.*?\|\s*(.*?)\s*\|\s*Flickr</title>#is', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return '';
}

function wbc_extract_flickr_license_url(string $html): string {
    foreach (wbc_extract_json_ld_blocks($html) as $json) {
        $found = wbc_find_json_key_recursive($json, 'license');
        if (is_string($found) && $found !== '') {
            return wbc_normalize_https_url($found);
        }
    }

    if (preg_match('#"license_url"\s*:\s*"([^"]+)"#', $html, $m)) {
        return wbc_normalize_https_url(wbc_decode_js_string($m[1]));
    }

    return '';
}

function wbc_extract_flickr_license_name(string $html, string $licenseUrl): string {
    if (preg_match('#"license"\s*:\s*"([^"]+)"#', $html, $m)) {
        return wbc_decode_js_string($m[1]);
    }

    $urlNeedle = str_replace('/', '\\/', $licenseUrl);
    if ($urlNeedle !== '' && preg_match('#"name":"([^"]+)","url":"' . preg_quote($urlNeedle, '#') . '"#', $html, $m)) {
        return wbc_decode_js_string($m[1]);
    }

    return '';
}

function wbc_extract_json_ld_blocks(string $html): array {
    if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
        return [];
    }

    $blocks = [];
    foreach ($matches[1] as $raw) {
        $decoded = json_decode(html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (is_array($decoded)) {
            $blocks[] = $decoded;
        }
    }
    return $blocks;
}

function wbc_find_json_key_recursive(array $data, string $key) {
    foreach ($data as $k => $v) {
        if ($k === $key) {
            return $v;
        }
        if (is_array($v)) {
            $found = wbc_find_json_key_recursive($v, $key);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

function wbc_extract_flickr_size_urls(string $html): array {
    $sizes = [];

    if (preg_match_all('#"displayUrl":"([^"]+)","width":(\d+),"height":(\d+),"url":"([^"]+)"#', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $url = wbc_normalize_flickr_asset_url(wbc_decode_js_string($m[4]));
            if ($url === '') {
                continue;
            }
            $sizes[$url] = [
                'url' => $url,
                'width' => (int)$m[2],
                'height' => (int)$m[3],
            ];
        }
    }

    return array_values($sizes);
}

function wbc_decode_js_string(string $value): string {
    $decoded = json_decode('"' . str_replace('"', '\"', $value) . '"');
    if (is_string($decoded)) {
        return html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return html_entity_decode(str_replace('\/', '/', $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function wbc_normalize_flickr_asset_url(string $url): string {
    return wbc_normalize_https_url(trim(wbc_decode_js_string($url)));
}

function wbc_build_flickr_size_variant_urls(string $url): array {
    $url = wbc_normalize_https_url($url);
    $suffixes = ['o', '6k', '5k', '4k', '3k', 'k', 'h', 'b', 'c', 'z', 'n', 'm'];

    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
        return [$url];
    }

    $baseUrl = $parts['scheme'] . '://' . $parts['host'];
    $path = (string)$parts['path'];

    if (preg_match('#^(.*)_([0-9]?k|[sqtmnzwlcbho])(\.[a-zA-Z0-9]+)$#', $path, $m)) {
        $base = $m[1];
        $ext = $m[3];
        $urls = [];
        foreach ($suffixes as $suffix) {
            $urls[] = $baseUrl . $base . '_' . $suffix . $ext;
        }
        $urls[] = $baseUrl . $base . $ext;
        return array_values(array_unique($urls));
    }

    if (preg_match('#^(.*?)(\.[a-zA-Z0-9]+)$#', $path, $m)) {
        $base = $m[1];
        $ext = $m[2];
        $urls = [$url];
        foreach ($suffixes as $suffix) {
            $urls[] = $baseUrl . $base . '_' . $suffix . $ext;
        }
        return array_values(array_unique($urls));
    }

    return [$url];
}
