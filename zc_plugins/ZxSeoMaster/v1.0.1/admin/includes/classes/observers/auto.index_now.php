<?php
declare(strict_types=1);

/**
 * Auto observer for IndexNow submission on product or category update
 * @package admin
 * Copyright 2025 ZenExpert - https://zenexpert.com
 * @version 09 Aug 2026 ZenExpert
 */
class zcObserverIndexNow extends base
{
    /**
     * Defense-in-depth: we only ever contact a known IndexNow-participating service,
     * even if ZX_INDEXNOW_ENDPOINT somehow holds something else. 
     * Keep in sync with the $zxIndexNowEndpoints list in admin/zx_seo_master.php.
     */
    private const ALLOWED_ENDPOINTS = [
        'https://www.bing.com/indexnow',
        'https://api.indexnow.org/indexnow',
        'https://yandex.com/indexnow',
        'https://search.seznam.cz/indexnow',
    ];

    private string $noSubmitMessage = '';
    private array $previous_product_status = [];

    public function __construct()
    {
        $this->attach(
            $this,
            [
                'NOTIFY_MODULES_UPDATE_PRODUCT_END',
                'NOTIFY_ADMIN_CATEGORIES_UPDATE_OR_INSERT_FINISH',
            ]
        );

        $this->detectLocalDevelopmentEnvironment();
        $this->interceptStatusToggleRequest();
    }

    /**
     * Suppresses IndexNow submissions when running on a local/dev copy of the store, so
     * developing against a copy of live data never pings a real search engine. 
     * Checks once up front so every submission path (constructor-intercepted or update()-triggered)
     * shares the same message.
     */
    private function detectLocalDevelopmentEnvironment(): void
    {
        if (file_exists('../includes/local/configure.php')) {
            $this->noSubmitMessage = ' (local dev)';
        }
    }

    /**
     * Reacts to the product/category status-toggle requests that ZX SEO Master's own admin JS
     * (category_product_listing.php) appends `&indexnow=1` to once the admin confirms
     * submission via the IndexNow dialog.
     *
     * This has to inspect the raw request directly (rather than attach()-ing to a notifier
     * like the rest of this class does) because Zen Cart core fires no event around either the
     * product "setflag" action or the categories status-toggle confirmation screen - there is
     * nothing to subscribe to for those two flows. 
     * It's a no-op on every admin page load except the two specific action/param combinations below.
     */
    private function interceptStatusToggleRequest(): void
    {
        // capture product status before a standard product edit is saved, so update() can
        // later tell whether NOTIFY_MODULES_UPDATE_PRODUCT_END represents an actual change
        if (isset($_GET['action']) && $_GET['action'] === 'update_product' && isset($_GET['pID'])) {
            global $db;
            $pID = (int)$_GET['pID'];
            $check = $db->Execute("SELECT products_status FROM " . TABLE_PRODUCTS . " WHERE products_id = " . $pID);
            if (!$check->EOF) {
                $this->previous_product_status[$pID] = (int)$check->fields['products_status'];
            }
        }

        // intercept product status toggles
        if (isset($_GET['action']) && $_GET['action'] === 'setflag' && isset($_GET['indexnow']) && $_GET['indexnow'] === '1') {
            $this->handleProductStatusIntercept();
        }

        // intercept category status toggles (executes after the confirmation form POST)
        if (isset($_GET['action']) && $_GET['action'] === 'update_category_status' && isset($_GET['indexnow']) && $_GET['indexnow'] === '1') {
            $this->handleCategoryStatusBatch();
        }
    }

    /**
     * Handles single product toggles which do not require a confirmation screen
     */
    private function handleProductStatusIntercept(): void
    {
        global $db;

        if (isset($_GET['pID']) && (int)$_GET['pID'] > 0) {
            $products_id = (int)$_GET['pID'];

            $product = $db->Execute("SELECT products_type, master_categories_id FROM " . TABLE_PRODUCTS . " WHERE products_id = " . $products_id);
            if (!$product->EOF) {
                $type_handler = zen_get_handler_from_type($product->fields['products_type']);
                $cPath = zen_get_generated_category_path_rev($product->fields['master_categories_id']);
                $url = zen_catalog_href_link($type_handler . '_info', 'cPath=' . $cPath . '&products_id=' . $products_id);

                $this->submitToIndexNow($url);
            }
        }
    }

    /**
     * Handles category toggles, reading the POST data from the setflag_categories confirmation screen
     */
    private function handleCategoryStatusBatch(): void
    {
        global $db;

        // the form posts categories_id
        $cat_id = isset($_POST['categories_id']) ? (int)$_POST['categories_id'] : (isset($_GET['cID']) ? (int)$_GET['cID'] : 0);
        if ($cat_id === 0) return;

        $urls_to_submit = [];

        // always submit the master category being updated
        $urls_to_submit[] = zen_catalog_href_link('index', zen_get_path($cat_id));

        // read the confirmation form POST variables
        $subcategories_changed = isset($_POST['set_subcategories_status']) && in_array($_POST['set_subcategories_status'], ['set_subcategories_status_off', 'set_subcategories_status_on'], true);
        $products_changed = isset($_POST['set_products_status']) && in_array($_POST['set_products_status'], ['set_products_status_off', 'set_products_status_on'], true);

        $categories_to_check = [$cat_id];

        if ($subcategories_changed) {
            $subcats = [];
            zen_get_subcategories($subcats, $cat_id);
            foreach ($subcats as $subcat_id) {
                $urls_to_submit[] = zen_catalog_href_link('index', zen_get_path($subcat_id));
                $categories_to_check[] = $subcat_id;
            }
        }

        if ($products_changed) {
            foreach ($categories_to_check as $cID) {
                $products = $db->Execute("SELECT p.products_id, p.products_type FROM " . TABLE_PRODUCTS_TO_CATEGORIES . " ptc LEFT JOIN " . TABLE_PRODUCTS . " p ON (p.products_id = ptc.products_id) WHERE ptc.categories_id = " . (int)$cID);
                while (!$products->EOF) {
                    $pID = $products->fields['products_id'];
                    $type_handler = zen_get_handler_from_type($products->fields['products_type']);
                    $cPath = zen_get_generated_category_path_rev($cID);
                    $urls_to_submit[] = zen_catalog_href_link($type_handler . '_info', 'cPath=' . $cPath . '&products_id=' . $pID);
                    $products->MoveNext();
                }
            }
        }

        // clean duplicates in case of linked products
        $urls_to_submit = array_values(array_unique($urls_to_submit));

        if (count($urls_to_submit) > 1) {
            $this->submitToIndexNowBatch($urls_to_submit);
        } elseif (count($urls_to_submit) === 1) {
            $this->submitToIndexNow($urls_to_submit[0]);
        }
    }

    public function update(&$class, $eventID, $p1, $p2, $p3, $p4): void
    {
        if (!defined('ZX_INDEXNOW_KEY') || trim(ZX_INDEXNOW_KEY) === '') {
            return;
        }

        global $db;
        switch ($eventID) {
            case 'NOTIFY_MODULES_UPDATE_PRODUCT_END':
                $products_id = (int)$p1['products_id'];
                $product = $db->Execute("SELECT products_status, products_type, master_categories_id FROM " . TABLE_PRODUCTS . " WHERE products_id = " . $products_id);

                $current_status = (int)$product->fields['products_status'];
                $previous_status = $this->previous_product_status[$products_id] ?? null;

                // skip submission if it was already disabled and remains disabled (or inserted as disabled)
                if ($current_status === 0 && ($previous_status === 0 || $previous_status === null)) {
                    $this->noSubmitMessage = ' (product remains disabled)';
                }

                $type_handler = zen_get_handler_from_type($product->fields['products_type']);
                $cPath = zen_get_generated_category_path_rev($product->fields['master_categories_id']);
                $url = zen_catalog_href_link($type_handler . '_info', 'cPath=' . $cPath . '&products_id=' . $products_id);
                $this->submitToIndexNow($url);
                break;

            case 'NOTIFY_ADMIN_CATEGORIES_UPDATE_OR_INSERT_FINISH':
                $cat_id = (int)$p1['categories_id'];

                // categories cannot change status on the edit screen
                // if it is disabled here, it was already disabled prior to the edit
                if ((int)zen_get_categories_status($cat_id) === 0) {
                    $this->noSubmitMessage = ' (category remains disabled)';
                }

                $url = zen_catalog_href_link('index', zen_get_path($cat_id));
                $this->submitToIndexNow($url);
                break;

            default:
                break;
        }
    }

    private function submitToIndexNowBatch(array $urls): void
    {
        global $messageStack;

        if (!empty($this->noSubmitMessage)) {
            $messageStack->add_session("IndexNow Batch NOT submitted" . $this->noSubmitMessage . ": " . count($urls) . " URLs skipped.", 'info');
            return;
        }

        $indexnow_key = trim(ZX_INDEXNOW_KEY);
        $endpoint = defined('ZX_INDEXNOW_ENDPOINT') ? ZX_INDEXNOW_ENDPOINT : 'https://api.indexnow.org/indexnow';

        if (!in_array($endpoint, self::ALLOWED_ENDPOINTS, true)) {
            $messageStack->add_session("IndexNow Batch NOT submitted: configured endpoint is not on the supported list.", 'error');
            return;
        }

        // decode &amp; back to standard & for all URLs
        $clean_urls = array_map('htmlspecialchars_decode', $urls);

        $parsed_url = parse_url($clean_urls[0]);
        $host = $parsed_url['host'] ?? $_SERVER['HTTP_HOST'];

        $payload = json_encode([
            'host' => $host,
            'key' => $indexnow_key,
            'urlList' => $clean_urls
        ], JSON_UNESCAPED_SLASHES);

        //error_log('IndexNow Batch Submission Payload: ' . print_r($payload, true));

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($payload)
        ]);

        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $message = ($http_code === 200 || $http_code === 202)
            ? "IndexNow Batch Submission Successful. " . count($urls) . " URLs submitted."
            : "IndexNow Batch Submission Failed. HTTP code: $http_code";

        $messageStack->add_session($message, ($http_code === 200 || $http_code === 202) ? 'success' : 'error');
    }

    private function submitToIndexNow($url): void
    {
        global $messageStack;

        $url = htmlspecialchars_decode($url);

        if (!empty($this->noSubmitMessage)) {
            $messageStack->add_session("IndexNow url NOT submitted" . $this->noSubmitMessage . ": " . '"' . $url . '"', 'info');
            return;
        }

        $indexnow_key = trim(ZX_INDEXNOW_KEY);
        $endpoint = defined('ZX_INDEXNOW_ENDPOINT') ? ZX_INDEXNOW_ENDPOINT : 'https://api.indexnow.org/indexnow';

        if (!in_array($endpoint, self::ALLOWED_ENDPOINTS, true)) {
            $messageStack->add_session("IndexNow url NOT submitted: configured endpoint is not on the supported list.", 'error');
            return;
        }

        $query = http_build_query([
            'url' => $url,
            'key' => $indexnow_key
        ]);
        $full_url = rtrim($endpoint, '/') . '?' . $query;

        //error_log('IndexNow Single Submission URL: ' . $full_url);

        $ch = curl_init($full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $indexnow_responses = [
            200 => ['OK', 'URL submitted successfully'],
            202 => ['Accepted', 'URL received. IndexNow key validation pending.'],
            400 => ['Bad request', 'Invalid format'],
            403 => ['Forbidden', 'Key not valid (e.g. key not found, file found but key not in the file)'],
            422 => ['Unprocessable Entity', 'URLs don’t belong to the host or key not matching the schema'],
            429 => ['Too Many Requests', 'Too Many Requests (potential Spam)'],
        ];

        if (isset($indexnow_responses[$http_code])) {
            $message = "IndexNow response $http_code: {$indexnow_responses[$http_code][0]} - {$indexnow_responses[$http_code][1]}";
        } else {
            $message = "IndexNow submission failed. HTTP code: $http_code";
        }

        $messageStack->add_session($message . ' for URL: ' . $url, ($http_code === 200 || $http_code === 202) ? 'success' : 'error');
    }
}
