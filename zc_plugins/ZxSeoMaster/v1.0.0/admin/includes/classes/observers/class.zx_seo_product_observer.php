<?php
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class zx_seo_product_observer extends base
{

    public function __construct()
    {
        // hook into the admin footer to inject our HTML and scripts
        // hook into product page to redirect meta tags editor
        // hook into categories page to redirect meta tags editor
        $this->attach($this, [
            'NOTIFY_ADMIN_FOOTER_END',
            'NOTIFY_BEGIN_ADMIN_PRODUCTS',
            'NOTIFY_BEGIN_ADMIN_CATEGORIES'
        ]);
    }

    public function update(&$class, $eventID, $paramsArray = [], &$p1 = null, &$p2 = null, &$p3 = null)
    {
        switch ($eventID) {

            case 'NOTIFY_ADMIN_FOOTER_END':
                $cmd = $_GET['cmd'] ?? '';
                $action = $_GET['action'] ?? '';

                // trigger only on the product editing page
                if ($cmd === 'product' && $action === 'new_product') {
                    $this->renderSlideoutAnalyzer();
                }
                break;

            case 'NOTIFY_BEGIN_ADMIN_PRODUCTS':
                if ($p1 === 'new_product_meta_tags' && !empty($_GET['pID'])) {
                    $pID = (int)$_GET['pID'];
                    $redirectUrl = zen_href_link('zx_seo_master', 'entity_type=product&entity_id=' . $pID) . '#editor';
                    zen_redirect($redirectUrl);
                }
                break;

            case 'NOTIFY_BEGIN_ADMIN_CATEGORIES':
                if ($p1 === 'edit_category_meta_tags' && !empty($_GET['cID'])) {
                    $cID = (int)$_GET['cID'];
                    $redirectUrl = zen_href_link('zx_seo_master', 'entity_type=category&entity_id=' . $cID) . '#editor';
                    zen_redirect($redirectUrl);
                }
                break;

        }
    }

    private function renderSlideoutAnalyzer()
    {
        global $db;

        $savedFocusKeyword = '';
        $savedMetaTitle = '';
        $savedMetaDesc = '';

        $pID = (int)($_GET['pID'] ?? 0);
        $langId = (int)($_SESSION['languages_id'] ?? 1);

        if ($pID > 0) {
            // 1. Fallback: Query native Zen Cart meta tags
            $native_query = "SELECT metatags_title, metatags_keywords, metatags_description
                             FROM " . TABLE_META_TAGS_PRODUCTS_DESCRIPTION . "
                             WHERE products_id = " . $pID . "
                             AND language_id = " . $langId . " LIMIT 1";
            $native_result = $db->Execute($native_query);

            if (!$native_result->EOF) {
                if (!empty($native_result->fields['metatags_keywords'])) $savedFocusKeyword = $native_result->fields['metatags_keywords'];
                if (!empty($native_result->fields['metatags_title'])) $savedMetaTitle = $native_result->fields['metatags_title'];
                if (!empty($native_result->fields['metatags_description'])) $savedMetaDesc = $native_result->fields['metatags_description'];
            }

            // 2. Authority Override: Query ZX SEO Master custom tables
            $custom_query = "SELECT meta_title, meta_description, focus_keyword
                             FROM " . TABLE_ZX_SEO_METADATA . "
                             WHERE entity_type = 'product'
                             AND entity_id = " . $pID . "
                             AND language_id = " . $langId . " LIMIT 1";
            $custom_result = $db->Execute($custom_query);

            if (!$custom_result->EOF) {
                if (!empty($custom_result->fields['focus_keyword'])) $savedFocusKeyword = $custom_result->fields['focus_keyword'];
                if (!empty($custom_result->fields['meta_title'])) $savedMetaTitle = $custom_result->fields['meta_title'];
                if (!empty($custom_result->fields['meta_description'])) $savedMetaDesc = $custom_result->fields['meta_description'];
            }
        }

        ?>

        <!-- Toggle Button -->
        <div id="zxSeoToggleBtn"><i class="fa fa-tachometer"></i> SEO</div>

        <!-- Offcanvas Sidebox -->
        <div id="zxSeoSidebox">
            <div class="zx-seo-header">
                <h3>ZX SEO Master Analyzer</h3>
                <i class="fa fa-times zx-seo-close" id="zxSeoCloseBtn"></i>
            </div>

            <div class="zx-seo-body">
                <div class="form-group">
                    <label><?= TEXT_LABEL_FOCUS_KEYWORD ?></label>
                    <input type="text" id="sidebox_focus_keyword" class="form-control"
                           value="<?php echo zen_output_string_protected($savedFocusKeyword); ?>"
                           placeholder="<?= TEXT_LABEL_FOCUS_KEYWORD_PLACEHOLDER ?>">

                    <!-- Hidden fields to pass authoritative meta data to the JS engine -->
                    <input type="hidden" id="sidebox_meta_title"
                           value="<?php echo zen_output_string_protected($savedMetaTitle); ?>">
                    <input type="hidden" id="sidebox_meta_desc"
                           value="<?php echo zen_output_string_protected($savedMetaDesc); ?>">
                </div>

                <div class="snippet-preview">
                    <div
                        style="font-size: 11px; text-transform: uppercase; color: #999; margin-bottom: 8px; font-weight: bold;"><?= TEXT_GOOGLE_SNIPPET_PREVIEW ?></div>
                    <div class="snippet-url">yoursite.com > <span id="snip_path">product</span></div>
                    <h3 class="snippet-title" id="snip_title"><?= TEXT_GOOGLE_SNIPPET_PREVIEW_TITLE ?></h3>
                    <p class="snippet-desc" id="snip_desc"><?= TEXT_GOOGLE_SNIPPET_PREVIEW_DESCRIPTION ?></p>
                </div>

                <div class="analysis-group">
                    <h4><?= TEXT_BASIC_SEO ?></h4>
                    <div id="res_content_length" class="analysis-item"></div>
                    <div id="res_internal_links" class="analysis-item"></div>
                    <div id="res_external_links" class="analysis-item"></div>
                    <div id="res_keyword_density" class="analysis-item"></div>
                </div>

                <div class="analysis-group">
                    <h4><?= TEXT_TITLE_META ?></h4>
                    <div id="res_title_length" class="analysis-item"></div>
                    <div id="res_title_keyword" class="analysis-item"></div>
                    <div id="res_desc_length" class="analysis-item"></div>
                </div>

                <div class="analysis-group">
                    <h4><?= TEXT_READABILITY ?></h4>
                    <div id="res_readability" class="analysis-item"></div>
                    <div id="res_sentence_length" class="analysis-item"></div>
                </div>
            </div>
        </div>

        <?php
    }
}
