<?php
declare(strict_types=1);

class zcObserverZxSeoMasterObserver extends \base
{
    private array $seoData = [];
    private bool $isDataLoaded = false;

    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_MODULE_START_META_TAGS',
            'NOTIFY_HTML_HEAD_END'
        ]);
    }

    public function update(&$class, $eventID, $paramsArray = array(), &$p1 = null, &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null, &$p6 = null, &$p7 = null, &$p8 = null, &$p9 = null)
    {
        if (!$this->isDataLoaded) {
            $this->loadSeoData();
        }

        if (empty($this->seoData)) {
            return;
        }

        switch ($eventID) {
            case 'NOTIFY_MODULE_START_META_TAGS':
                $this->defineCustomMetaTags();
                break;
            case 'NOTIFY_HTML_HEAD_END':
                $this->injectHeadTags();
                break;
        }
    }

    private function loadSeoData()
    {
        global $db;
        $this->isDataLoaded = true;

        $entityType = '';
        $entityId = 0;
        $mainPage = $_GET['main_page'] ?? '';
        $langId = (int)($_SESSION['languages_id'] ?? 1);

        if (isset($_GET['products_id'])) {
            $entityType = 'product';
            $entityId = (int)$_GET['products_id'];
        } elseif ($mainPage === 'index' && isset($_GET['cPath'])) {
            $entityType = 'category';
            $cPathArr = explode('_', $_GET['cPath']);
            $entityId = (int)end($cPathArr);
        } elseif ($mainPage === 'index' && isset($_GET['manufacturers_id'])) {
            $entityType = 'manufacturer';
            $entityId = (int)$_GET['manufacturers_id'];
        } elseif ($mainPage === 'page' && isset($_GET['id'])) {
            $entityType = 'ezpage';
            $entityId = (int)$_GET['id'];
        }

        if ($entityType !== '' && $entityId > 0) {
            $sql = "SELECT meta_title, meta_description, custom_canonical, is_noindex, is_nofollow
                    FROM " . TABLE_ZX_SEO_METADATA . "
                    WHERE entity_type = :entityType
                    AND entity_id = :entityId
                    AND language_id = :langId
                    LIMIT 1";

            $sql = $db->bindVars($sql, ':entityType', $entityType, 'string');
            $sql = $db->bindVars($sql, ':entityId', $entityId, 'integer');
            $sql = $db->bindVars($sql, ':langId', $langId, 'integer');
            $result = $db->Execute($sql);

            if (!$result->EOF) {
                $rawTitle = (string)$result->fields['meta_title'];
                $rawDesc = (string)$result->fields['meta_description'];

                // Build the parsing dictionary
                $parseMap = [
                    '%SITE_NAME%' => defined('STORE_NAME') ? STORE_NAME : '',
                    '%SITE_TAGLINE%' => defined('HEADER_SALES_TEXT') ? HEADER_SALES_TEXT : ''
                ];

                if ($entityType === 'product') {
                    $productObj = new Product($entityId);

                    $parseMap['%PRODUCT_NAME%'] = zen_get_products_name($entityId, $langId);
                    $parseMap['%PRODUCT_MODEL%'] = (string)$productObj->get('products_model');
                    // strip_tags is required here because Zen Cart's price display function often wraps text in HTML spans
                    $parseMap['%PRODUCT_PRICE%'] = strip_tags(zen_get_products_display_price($entityId));
                } elseif ($entityType === 'category') {
                    $parseMap['%CATEGORY_NAME%'] = zen_get_category_name($entityId, $langId);
                }

                // Apply the tags to both Title and Description
                $parsedTitle = str_replace(array_keys($parseMap), array_values($parseMap), $rawTitle);
                $parsedDesc = str_replace(array_keys($parseMap), array_values($parseMap), $rawDesc);

                $this->seoData = [
                    'meta_title' => $parsedTitle,
                    'meta_description' => $parsedDesc,
                    'custom_canonical' => $result->fields['custom_canonical'],
                    'is_noindex' => (int)$result->fields['is_noindex'],
                    'is_nofollow' => (int)$result->fields['is_nofollow'],
                ];
            }
        }
    }

    private function defineCustomMetaTags()
    {
        global $canonicalLink;

        if (!empty($this->seoData['meta_title']) && !defined('META_TAG_TITLE')) {
            define('META_TAG_TITLE', zen_output_string_protected($this->seoData['meta_title']));
        }

        if (!empty($this->seoData['meta_description']) && !defined('META_TAG_DESCRIPTION')) {
            define('META_TAG_DESCRIPTION', zen_output_string_protected($this->seoData['meta_description']));
        }

        if (!empty($this->seoData['custom_canonical'])) {
            $canonicalLink = zen_output_string_protected($this->seoData['custom_canonical']);
        }
    }

    private function injectHeadTags()
    {
        $indexDirective = ($this->seoData['is_noindex'] === 1) ? 'noindex' : 'index';
        $followDirective = ($this->seoData['is_nofollow'] === 1) ? 'nofollow' : 'follow';

        echo '<meta name="robots" content="' . $indexDirective . ', ' . $followDirective . '" />' . "\n";
    }
}
