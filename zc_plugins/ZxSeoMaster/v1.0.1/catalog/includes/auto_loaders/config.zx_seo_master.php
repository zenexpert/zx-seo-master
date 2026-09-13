<?php
/**
 * Auto-loader for ZX SEO Master
 */


// 301 Redirects
// point 20: fires immediately after DB connection but before session/language loads.
$autoLoadConfig[20][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_zx_seo_redirects.php'
];

// load our global SEO definitions immediately after init_languages (point 70)
$autoLoadConfig[71][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_zx_seo_master_globals.php'
];
