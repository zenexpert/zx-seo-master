<?php
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$current_lang = (int)($_SESSION['languages_id'] ?? 1);

$sql = "SELECT config_key, config_value
        FROM " . TABLE_ZX_SEO_MASTER_GLOBALS . "
        WHERE language_id = " . $current_lang;
$global_seo_settings = $db->Execute($sql);

$seo_data = [];
while (!$global_seo_settings->EOF) {
    $seo_data[$global_seo_settings->fields['config_key']] = $global_seo_settings->fields['config_value'];
    $global_seo_settings->MoveNext();
}

// map the dynamic values to the constants expected by the core module
$constants_to_define = [
    'TITLE', 'SITE_TAGLINE', 'CUSTOM_KEYWORDS',
    'HOME_PAGE_META_DESCRIPTION', 'HOME_PAGE_META_KEYWORDS', 'HOME_PAGE_TITLE',
    'PRIMARY_SECTION', 'SECONDARY_SECTION', 'TERTIARY_SECTION', 'METATAGS_DIVIDER'
];

foreach ($constants_to_define as $key) {
    if (!empty($seo_data[$key])) {
        define($key, $seo_data[$key]);
    }
}
