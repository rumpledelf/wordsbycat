<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$errors = [];
$notice = '';
$compileMessage = '';

function wbc_local_timezone(): DateTimeZone {
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $candidates = [
        trim((string)getenv('TZ')),
        trim((string)ini_get('date.timezone')),
        'Australia/Melbourne',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        try {
            $timezone = new DateTimeZone($candidate);
            return $timezone;
        } catch (Throwable $e) {
            continue;
        }
    }

    $timezone = new DateTimeZone('UTC');
    return $timezone;
}

function wbc_display_timestamp(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value);
        $dt = $dt->setTimezone(wbc_local_timezone());
        return $dt->format('M j Y g:ia');
    } catch (Throwable $e) {
        return $value;
    }
}

$form = [
    'word' => '',
    'flickr_url' => '',
    'prompt' => '',
    'caption' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = wbc_post_string('action', 'save');

    if ($action === 'compile') {
        try {
            $result = wbc_run_process([WBC_ROOT . '/bin/compile']);
            if (!$result['ok']) {
                $message = trim((string)$result['stderr']);
                if ($message === '') {
                    $message = trim((string)$result['stdout']);
                }
                throw new RuntimeException($message !== '' ? $message : 'Compile failed.');
            }

            $compileMessage = 'Compiled static site into public/.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } else {
        $form['word'] = wbc_post_string('word');
        $form['flickr_url'] = wbc_post_string('flickr_url');
        $form['prompt'] = wbc_post_string('prompt');
        $form['caption'] = wbc_post_string('caption');

        if ($form['word'] === '') {
            $errors[] = 'Word is required.';
        }
        if ($form['flickr_url'] === '') {
            $errors[] = 'Flickr URL is required.';
        } elseif (!wbc_is_flickr_url($form['flickr_url'])) {
            $errors[] = 'That does not look like a Flickr URL.';
        }
        if ($form['caption'] === '') {
            $errors[] = 'Caption is required.';
        }

        if (!$errors) {
            try {
                $letter = wbc_derive_letter($form['word']);
                $slug = wbc_slugify($form['word']);

                $flickr = wbc_probe_flickr_by_url($form['flickr_url']);
                if (!$flickr['ok']) {
                    throw new RuntimeException((string)($flickr['message'] ?? 'Could not fetch Flickr metadata.'));
                }

                $images = wbc_store_entry_assets($letter, $slug, $flickr);

                $entries = wbc_load_letter_entries($letter);
                $existingIndex = null;
                $createdAt = gmdate('c');
                foreach ($entries as $index => $existingEntry) {
                    if ((string)($existingEntry['slug'] ?? '') === $slug) {
                        $existingIndex = $index;
                        $createdAt = (string)($existingEntry['created_at'] ?? $createdAt);
                        break;
                    }
                }

                $entry = [
                    'id' => $letter . '/' . $slug,
                    'word' => $form['word'],
                    'slug' => $slug,
                    'letter' => $letter,
                    'caption' => $form['caption'],
                    'created_at' => $createdAt,
                    'updated_at' => gmdate('c'),
                    'source_type' => 'flickr',
                    'source' => [
                        'page_url' => (string)($flickr['source_page_url'] ?? ''),
                        'image_url' => (string)($flickr['source_image_url'] ?? ''),
                        'author' => (string)($flickr['source_author'] ?? ''),
                        'license' => (string)($flickr['source_license'] ?? ''),
                        'license_url' => (string)($flickr['source_license_url'] ?? ''),
                        'submitted_url' => wbc_normalize_https_url($form['flickr_url']),
                    ],
                    'images' => $images,
                ];

                if ($existingIndex === null) {
                    $entries[] = $entry;
                    $notice = 'Saved ' . $form['word'] . ' to ' . strtoupper($letter) . '.';
                } else {
                    $entries[$existingIndex] = $entry;
                    $notice = 'Updated ' . $form['word'] . ' in ' . strtoupper($letter) . '.';
                }
                wbc_write_letter_entries($letter, $entries);
                $form = ['word' => '', 'flickr_url' => '', 'prompt' => '', 'caption' => ''];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

if ($form['prompt'] === '') {
    try {
        $form['prompt'] = wbc_caption_prompt($form['word']);
    } catch (Throwable $e) {
        $form['prompt'] = '';
    }
}

$recentEntries = wbc_recent_entries(10);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Words by Cat Intake</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
      --bg: #dd9341;
      --panel: #ffffff;
      --ink: #111111;
      --line: #000000;
      --soft-line: #cfcfcf;
      --green: #6ea931;
      --green-dark: #588b24;
      --green-light: #88be4a;
      --orange: #dd9341;
      --orange-dark: #ad6c1c;
      --orange-light: #ebb066;
      --shadow: rgba(0, 0, 0, 0.14);
    }
    body {
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font: 16px/1.45 "Comfortaa", sans-serif;
    }
    main {
      max-width: 960px;
      margin: 0 auto;
      padding: 32px 20px 56px;
    }
    h1, h2 {
      margin: 0 0 12px;
      line-height: 1.1;
    }
    p {
      margin: 0 0 12px;
    }
    .panel {
      background: var(--panel);
      border: 2px solid var(--line);
      border-radius: 18px;
      padding: 20px 22px;
      box-shadow: 6px 6px 0 var(--shadow);
      margin-bottom: 20px;
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    label {
      display: block;
      font-weight: 700;
      margin-bottom: 6px;
    }
    input[type="text"], textarea {
      width: 100%;
      box-sizing: border-box;
      border: 1px solid var(--soft-line);
      border-radius: 14px;
      padding: 10px 12px;
      font: inherit;
      background: white;
      color: var(--ink);
    }
    textarea {
      min-height: 140px;
      resize: vertical;
    }
    .actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 16px;
    }
    button {
      border: 0;
      border-radius: 999px;
      padding: 11px 18px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      background: linear-gradient(180deg, var(--green-light) 0%, var(--green) 55%, var(--green-dark) 100%);
      color: white;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 2px 6px rgba(0, 0, 0, 0.18);
      text-shadow: 0 1px 0 rgba(0, 0, 0, 0.18);
    }
    button.secondary {
      background: linear-gradient(180deg, var(--orange-light) 0%, var(--orange) 55%, var(--orange-dark) 100%);
      color: white;
    }
    button.compile {
      padding: 16px 28px;
      font-size: 20px;
      background: linear-gradient(180deg, #9bd85b 0%, #6ea931 55%, #4e7d1e 100%);
    }
    button:hover {
      filter: brightness(1.03);
    }
    button:active {
      transform: translateY(1px);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.32), 0 1px 3px rgba(0, 0, 0, 0.16);
    }
    button[disabled] {
      opacity: 0.55;
      cursor: default;
    }
    .errors, .notice {
      padding: 12px 14px;
      border-radius: 10px;
      margin-bottom: 16px;
    }
    .errors {
      background: #f9d8d2;
      border: 2px solid var(--line);
    }
    .notice {
      background: #ddeed9;
      border: 2px solid var(--line);
    }
    .meta {
      color: #3d3d3d;
      font-size: 14px;
    }
    a {
      color: var(--orange);
    }
    a:visited,
    a:hover {
      color: var(--orange-dark);
    }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      text-align: left;
      border-top: 1px solid var(--soft-line);
      padding: 10px 8px;
      vertical-align: top;
    }
    th {
      font-size: 14px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--green-dark);
    }
    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 13px;
    }
    .compile-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }
    @media (max-width: 760px) {
      .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<main>
  <div class="panel">
    <h1>Words by Cat Intake</h1>
    <p>Bucket o'cats: paste a Flickr URL, seed a caption, toss it into the per-letter JSON bucket.</p>
    <div class="compile-bar">
      <p class="meta">Toss in as many cats as you want, then compile the static site.</p>
      <form method="post">
        <input type="hidden" name="action" value="compile">
        <button type="submit" class="compile">Compile Site</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <?php if ($errors): ?>
      <div class="errors">
        <?php foreach ($errors as $error): ?>
          <div><?= h($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>

    <?php if ($compileMessage !== ''): ?>
      <div class="notice"><?= h($compileMessage) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="save">
      <div class="grid">
        <div>
          <label for="word">Word</label>
          <input type="text" id="word" name="word" value="<?= h($form['word']) ?>" required>
        </div>
        <div>
          <label for="flickr_url">Flickr URL</label>
          <input type="text" id="flickr_url" name="flickr_url" value="<?= h($form['flickr_url']) ?>" required>
        </div>
      </div>

      <div style="margin-top: 16px;">
        <label for="prompt">AI prompt</label>
        <textarea id="prompt" name="prompt"><?= h($form['prompt']) ?></textarea>
      </div>

      <div style="margin-top: 16px;">
        <label for="caption">Caption</label>
        <textarea id="caption" name="caption" required><?= h($form['caption']) ?></textarea>
      </div>

      <div class="actions">
        <button type="button" class="secondary" id="generate-caption">Generate caption</button>
        <button type="submit">Save to letter JSON</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2>Recent Entries</h2>
    <?php if (!$recentEntries): ?>
      <p class="meta">Nothing saved yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Word</th>
            <th>Letter</th>
            <th>Source</th>
            <th>Saved</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentEntries as $entry): ?>
            <tr>
              <td>
                <strong><?= h((string)($entry['word'] ?? '')) ?></strong><br>
                <span class="mono"><?= h((string)($entry['id'] ?? '')) ?></span>
              </td>
              <td><?= h(strtoupper((string)($entry['letter'] ?? ''))) ?></td>
              <td>
                <?php $author = (string)($entry['source']['author'] ?? ''); ?>
                <?php $pageUrl = (string)($entry['source']['page_url'] ?? ''); ?>
                <?php if ($pageUrl !== ''): ?>
                  <a href="<?= h($pageUrl) ?>" target="_blank" rel="noreferrer">Flickr</a>
                <?php else: ?>
                  Flickr
                <?php endif; ?>
                <?php if ($author !== ''): ?>
                  <br><span class="meta"><?= h($author) ?></span>
                <?php endif; ?>
              </td>
              <td class="meta"><?= h(wbc_display_timestamp((string)($entry['created_at'] ?? ''))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>
<script>
const button = document.getElementById('generate-caption');
const wordInput = document.getElementById('word');
const promptInput = document.getElementById('prompt');
const captionInput = document.getElementById('caption');
const promptTemplate = <?= json_encode((string)wbc_setting('caption_prompt_template', ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const blankPromptTemplate = promptTemplate ? promptTemplate.replaceAll('{word}', '') : '';

function fillPromptFromWord(force = false) {
  const word = wordInput.value.trim();
  if (!word || !promptTemplate) return;
  const nextPrompt = promptTemplate.replaceAll('{word}', word);
  if (force || !promptInput.value.trim()) {
    promptInput.value = nextPrompt;
  }
}

wordInput?.addEventListener('blur', () => fillPromptFromWord(false));
promptInput?.addEventListener('focus', () => {
  const word = wordInput.value.trim();
  if (!word) return;
  const currentPrompt = promptInput.value.trim();
  if (!currentPrompt || currentPrompt === blankPromptTemplate.trim()) {
    promptInput.value = promptTemplate.replaceAll('{word}', word);
  }
});

button?.addEventListener('click', async () => {
  const word = wordInput.value.trim();
  fillPromptFromWord(false);
  const prompt = promptInput.value.trim();

  if (!word && !prompt) {
    window.alert('Add a word or prompt first.');
    (wordInput.value.trim() ? promptInput : wordInput).focus();
    return;
  }

  button.disabled = true;
  const original = button.textContent;
  button.textContent = 'Generating...';

  try {
    const response = await fetch('generate_caption.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({word, prompt}),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'Caption generation failed.');
    }
    captionInput.value = data.caption || '';
  } catch (error) {
    window.alert(error.message || 'Caption generation failed.');
  } finally {
    button.disabled = false;
    button.textContent = original;
  }
});
</script>
</body>
</html>
