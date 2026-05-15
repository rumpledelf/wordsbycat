<?php
declare(strict_types=1);

$wbc_settings = [
    'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
    'openai_project' => getenv('OPENAI_PROJECT') ?: '',
    'openai_text_model' => getenv('OPENAI_TEXT_MODEL') ?: 'gpt-5.4',
    'google_tag_id' => getenv('GOOGLE_TAG_ID') ?: '',
    'caption_prompt_template' => '',
    'main_image_width' => 1200,
    'main_image_height' => 896,
    'thumb_size' => 240,
];

$settingsLocal = __DIR__ . '/settings.local.php';
if (is_file($settingsLocal)) {
    require $settingsLocal;
}
