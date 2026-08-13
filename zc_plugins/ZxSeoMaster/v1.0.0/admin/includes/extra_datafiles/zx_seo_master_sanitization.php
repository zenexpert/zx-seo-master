<?php
declare(strict_types=1);

$zx_seo_sanitizer = AdminRequestSanitizer::getInstance();

// apply the relaxed product description regex to SEO fields so JSON and punctuation pass safely
$zx_seo_sanitizer->addSimpleSanitization('PRODUCT_DESC_REGEX', [
    'meta_title',
    'meta_description',
    'focus_keyword',
    'custom_canonical',
    'source_url',
    'target_url',
]);

// NULL_ACTION completely bypasses strict sanitization, allowing raw HTML to pass through
$zx_seo_sanitizer->addSimpleSanitization('NULL_ACTION', ['custom_meta_tags', 'custom_footer_scripts']);

// robots_content isn't HTML - it's a plain-text robots.txt file.
// Here we register a bypass, scoped to this plugin's own page only.
$zx_seo_sanitizer->addComplexSanitization([
    'robots_content' => ['sanitizerType' => 'NULL_ACTION', 'method' => 'post', 'pages' => ['zx_seo_master']],
]);
