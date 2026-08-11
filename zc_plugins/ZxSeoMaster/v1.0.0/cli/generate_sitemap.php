<?php
declare(strict_types=1);

/**
 * CLI execution script for ZxSeoMaster Sitemap Builder
 * Usage: php /path/to/store/zc_plugins/ZxSeoMaster/v1.0.0/cli/generate_sitemap.php
 */

// Ensure this script is only run from the command line
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Bootstrap the Zen Cart admin environment
// Adjust the path to application_top.php based on your server's root directory structure
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require realpath(__DIR__ . '/../../../../admin/includes/application_top.php');

// Include the builder class
require_once DIR_FS_CATALOG . 'zc_plugins/ZxSeoMaster/v1.0.0/admin/includes/classes/class.zx_sitemap_builder.php';

echo "Starting ZxSeoMaster Sitemap Generation...\n";

$builder = new ZxSitemapBuilder();
$builder->build();

echo "Sitemap generation completed successfully.\n";
