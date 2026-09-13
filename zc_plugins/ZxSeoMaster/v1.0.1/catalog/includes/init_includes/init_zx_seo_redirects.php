<?php
declare(strict_types=1);

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

global $db;

if (!defined('TABLE_ZX_SEO_REDIRECTS')) {
    define('TABLE_ZX_SEO_REDIRECTS', DB_PREFIX . 'zx_seo_redirects');
}

// decode the URI
$requestUri = urldecode($_SERVER['REQUEST_URI'] ?? '');
$basePath = parse_url(DIR_WS_CATALOG, PHP_URL_PATH) ?? '';

// strip the catalog base path
$currentPath = $requestUri;
if ($basePath !== '' && $basePath !== '/' && strpos($requestUri, $basePath) === 0) {
    $currentPath = substr($requestUri, strlen($basePath));
}
$currentPath = ltrim($currentPath, '/');

// create a version without the query string
$pathWithoutQuery = parse_url($currentPath, PHP_URL_PATH) ?? '';

// quick exit if root domain
if ($currentPath === '') {
    return;
}

// create an HTML-entity version to match legacy/unsanitized database records
$currentPathEncoded = str_replace('&', '&amp;', $currentPath);

// query checks Exact Match, HTML-Encoded Match and Base Path (No Parameters) Match
$sql = "SELECT target_url
        FROM " . TABLE_ZX_SEO_REDIRECTS . "
        WHERE source_url = :pathExact
           OR source_url = :pathEncoded
           OR source_url = :pathBase
        LIMIT 1";

$sql = $db->bindVars($sql, ':pathExact', $currentPath, 'string');
$sql = $db->bindVars($sql, ':pathEncoded', $currentPathEncoded, 'string');
$sql = $db->bindVars($sql, ':pathBase', $pathWithoutQuery, 'string');

$redirectCheck = $db->Execute($sql);

if (!$redirectCheck->EOF) {
    $target = trim($redirectCheck->fields['target_url']);

    // resolve relative URLs to absolute storefront URLs
    if (!preg_match('~^(?:f|ht)tps?://~i', $target)) {
        $target = HTTP_SERVER . DIR_WS_CATALOG . ltrim($target, '/');
    }

    // execute 301 and halt
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $target);
    exit();
}
