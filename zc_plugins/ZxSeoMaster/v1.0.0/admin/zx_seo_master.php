<?php
declare(strict_types=1);

require 'includes/application_top.php';
require(DIR_WS_CLASSES . 'class.llms_txt_generator.php');

$action = $_GET['action'] ?? '';

// dynamic plugin integration checks
// Ultimate SEO URLs
$usu_installed = false;
$usu_group_id = 0;

$usu_group_query = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
                    WHERE configuration_group_title = 'Ultimate URLs' LIMIT 1";
$usu_group_result = $db->Execute($usu_group_query);

if (!$usu_group_result->EOF) {
    $usu_installed = true;
    $usu_group_id = (int)$usu_group_result->fields['configuration_group_id'];
}

// Ceon URI Mapping
$ceon_installed = false;
$ceon_group_query = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
                     WHERE configuration_group_title = 'Ceon URI Mapping (SEO)' LIMIT 1";
$ceon_group_result = $db->Execute($ceon_group_query);

if (!$ceon_group_result->EOF) {
    $ceon_installed = true;
}

// Structured Data
$sd_installed = false;
$sd_group_id = 0;

$sd_group_query = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
                   WHERE configuration_group_title = 'Structured Data' LIMIT 1";
$sd_group_result = $db->Execute($sd_group_query);

if (!$sd_group_result->EOF) {
    $sd_installed = true;
    $sd_group_id = (int)$sd_group_result->fields['configuration_group_id'];
}

// GA4
$ga4_installed = false;
$ga4_group_id = 0;

$ga4_group_query = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
                    WHERE configuration_group_title = 'GA4 Analytics' LIMIT 1";
$ga4_group_result = $db->Execute($ga4_group_query);

if (!$ga4_group_result->EOF) {
    $ga4_installed = true;
    $ga4_group_id = (int)$ga4_group_result->fields['configuration_group_id'];
}

// capture selected entity for lookup if passed via GET/POST
$entityType = zen_db_prepare_input($_POST['entity_type'] ?? ($_GET['entity_type'] ?? 'product'));
$entityId = (int)($_POST['entity_id'] ?? ($_GET['entity_id'] ?? 0));

// initialize variables for the form fields
$metaTitle = '';
$metaDescription = '';
$focusKeyword = '';
$customCanonical = '';
$isNoindex = 0;
$isNofollow = 0;

// if an entity ID is provided, attempt to load existing data for viewing/editing
$contextData = [];
if ($entityId > 0) {
    // load SEO metadata
    $entity_metadata = [];
    $languages = zen_get_languages();

    if (!empty($entityId) && !empty($entityType)) {
        $meta_query = $db->Execute("SELECT language_id, meta_title, meta_description, focus_keyword, custom_canonical, is_noindex, is_nofollow
                                FROM " . TABLE_ZX_SEO_METADATA . "
                                WHERE entity_type = '" . zen_db_input($entityType) . "'
                                AND entity_id = " . (int)$entityId);
        foreach ($meta_query as $meta) {
            $lID = (int)$meta['language_id'];
            $entity_metadata[$lID] = [
                'meta_title' => $meta['meta_title'],
                'meta_description' => $meta['meta_description'],
                'focus_keyword' => $meta['focus_keyword'],
                'custom_canonical' => $meta['custom_canonical'],
                'is_noindex' => $meta['is_noindex'],
                'is_nofollow' => $meta['is_nofollow']
            ];
        }
    }

    // load entity context data
    $langId = (int)($_SESSION['languages_id'] ?? 1);

    if ($entityType === 'product') {
        $ctxSql = "SELECT p.products_image, p.products_model, pd.products_name, p.products_price, p.products_tax_class_id
               FROM " . TABLE_PRODUCTS . " p
               LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd ON (p.products_id = pd.products_id AND pd.language_id = :langId)
               WHERE p.products_id = :id LIMIT 1";
        $ctxSql = $db->bindVars($ctxSql, ':langId', $langId, 'integer');
        $ctxSql = $db->bindVars($ctxSql, ':id', $entityId, 'integer');
        $ctxRes = $db->Execute($ctxSql);

        if (!$ctxRes->EOF) {
            require_once DIR_WS_CLASSES . 'currencies.php';
            $currencies = new currencies();
            $displayPrice = $currencies->display_price($ctxRes->fields['products_price'], zen_get_tax_rate($ctxRes->fields['products_tax_class_id']));

            $contextData = [
                'title' => $ctxRes->fields['products_name'] . ' [' . $ctxRes->fields['products_model'] . ']',
                'subtitle' => 'Price: ' . $displayPrice,
                'image' => $ctxRes->fields['products_image'],
                'admin_link' => zen_href_link('product', 'action=new_product&pID=' . $entityId),
                'catalog_link' => zen_catalog_href_link(zen_get_info_page($entityId), 'products_id=' . $entityId, 'NONSSL'),
                // Raw data for the JavaScript Live Preview Dictionary
                'raw_name' => $ctxRes->fields['products_name'],
                'raw_model' => $ctxRes->fields['products_model'],
                'raw_price' => strip_tags($displayPrice)
            ];
        }
    } elseif ($entityType === 'category') {
        $ctxSql = "SELECT c.categories_image, cd.categories_name
               FROM " . TABLE_CATEGORIES . " c
               LEFT JOIN " . TABLE_CATEGORIES_DESCRIPTION . " cd ON (c.categories_id = cd.categories_id AND cd.language_id = :langId)
               WHERE c.categories_id = :id LIMIT 1";
        $ctxSql = $db->bindVars($ctxSql, ':langId', $langId, 'integer');
        $ctxSql = $db->bindVars($ctxSql, ':id', $entityId, 'integer');
        $ctxRes = $db->Execute($ctxSql);

        if (!$ctxRes->EOF) {
            $cPath = zen_get_generated_category_path_rev($entityId);
            $contextData = [
                'title' => $ctxRes->fields['categories_name'],
                'subtitle' => 'Category ID: ' . $entityId,
                'image' => $ctxRes->fields['categories_image'],
                'admin_link' => zen_href_link(FILENAME_CATEGORIES, 'cPath=' . $cPath),
                'catalog_link' => zen_catalog_href_link(FILENAME_DEFAULT, 'cPath=' . $cPath, 'NONSSL'),
                // Raw data for the JavaScript Live Preview Dictionary
                'raw_name' => $ctxRes->fields['categories_name'],
                'raw_model' => '',
                'raw_price' => ''
            ];
        }
    } elseif ($entityType === 'manufacturer') {
        $ctxSql = "SELECT manufacturers_image, manufacturers_name
               FROM " . TABLE_MANUFACTURERS . "
               WHERE manufacturers_id = :id LIMIT 1";
        $ctxSql = $db->bindVars($ctxSql, ':id', $entityId, 'integer');
        $ctxRes = $db->Execute($ctxSql);

        if (!$ctxRes->EOF) {
            $contextData = [
                'title' => $ctxRes->fields['manufacturers_name'],
                'subtitle' => 'Manufacturer ID: ' . $entityId,
                'image' => $ctxRes->fields['manufacturers_image'],
                'admin_link' => zen_href_link(FILENAME_MANUFACTURERS, 'mID=' . $entityId . '&action=edit'),
                'catalog_link' => zen_catalog_href_link(FILENAME_DEFAULT, 'manufacturers_id=' . $entityId, 'NONSSL'),
                // Raw data for the JavaScript Live Preview Dictionary
                'raw_name' => $ctxRes->fields['manufacturers_name'],
                'raw_model' => '',
                'raw_price' => ''
            ];
        }
    } elseif ($entityType === 'ezpage') {
        $ctxSql = "SELECT e.alt_url, ec.pages_title
               FROM " . TABLE_EZPAGES . " e
               LEFT JOIN " . TABLE_EZPAGES_CONTENT . " ec ON (e.pages_id = ec.pages_id AND ec.languages_id = :langId)
               WHERE e.pages_id = :id LIMIT 1";
        $ctxSql = $db->bindVars($ctxSql, ':langId', $langId, 'integer');
        $ctxSql = $db->bindVars($ctxSql, ':id', $entityId, 'integer');
        $ctxRes = $db->Execute($ctxSql);

        if (!$ctxRes->EOF) {
            $contextData = [
                'title' => $ctxRes->fields['pages_title'],
                'subtitle' => 'EZ-Page ID: ' . $entityId,
                'image' => '',
                'admin_link' => zen_href_link(FILENAME_EZPAGES_ADMIN, 'action=new&eID=' . $entityId),
                'catalog_link' => zen_catalog_href_link(FILENAME_EZPAGES, 'id=' . $entityId, 'NONSSL'),
                // Raw data for the JavaScript Live Preview Dictionary
                'raw_name' => $ctxRes->fields['pages_title'],
                'raw_model' => '',
                'raw_price' => ''
            ];
        }
    }
}

$robotsPath = DIR_FS_CATALOG . 'robots.txt';

// actions
switch ($action) {
    case 'save':
        $entityType = preg_replace('/[^a-z_]/', '', $_POST['entity_type'] ?? '');
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $seo_meta = $_POST['seo_meta'] ?? [];

        if ($entityType !== '' && $entityId > 0 && !empty($seo_meta)) {
            foreach ($seo_meta as $langId => $data) {
                $safeLangId = (int)$langId;

                $metaTitle = zen_db_prepare_input($data['meta_title'] ?? '');
                $metaDescription = zen_db_prepare_input($data['meta_description'] ?? '');
                $focusKeyword = zen_db_prepare_input($data['focus_keyword'] ?? '');
                $customCanonical = zen_db_prepare_input($data['custom_canonical'] ?? '');
                $isNoindex = isset($data['is_noindex']) ? 1 : 0;
                $isNofollow = isset($data['is_nofollow']) ? 1 : 0;

                $sqlDataArray = [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'language_id' => $safeLangId,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'focus_keyword' => $focusKeyword,
                    'custom_canonical' => $customCanonical,
                    'is_noindex' => $isNoindex,
                    'is_nofollow' => $isNofollow,
                ];

                $checkSql = "SELECT id FROM " . TABLE_ZX_SEO_METADATA . "
                             WHERE entity_type = :entityType AND entity_id = :entityId AND language_id = :langId";
                $checkSql = $db->bindVars($checkSql, ':entityType', $entityType, 'string');
                $checkSql = $db->bindVars($checkSql, ':entityId', $entityId, 'integer');
                $checkSql = $db->bindVars($checkSql, ':langId', $safeLangId, 'integer');
                $check = $db->Execute($checkSql);

                if ($check->RecordCount() > 0) {
                    zen_db_perform(TABLE_ZX_SEO_METADATA, $sqlDataArray, 'update', "id = " . (int)$check->fields['id']);
                } else {
                    zen_db_perform(TABLE_ZX_SEO_METADATA, $sqlDataArray);
                }
            }

            $messageStack->add_session(SUCCESS_SETTINGS_SAVED, 'success');
            zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER, 'entity_type=' . $entityType . '&entity_id=' . $entityId) . '#editor');
        } else {
            $messageStack->add_session(ERROR_MISSING_DATA, 'error');
            zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#editor');
        }
        break;

    case 'generate_sitemap':
        require_once 'includes/classes/class.zx_sitemap_builder.php';

        $builder = new ZxSitemapBuilder();
        $builder->build();

        $messageStack->add_session(SUCCESS_SITEMAP_GENERATED, 'success');

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#tools');
        break;

    case 'save_robots':
        $robotsContent = $_POST['robots_content'] ?? '';

        // ensure we have write permissions before attempting to save
        if (is_writable($robotsPath) || (!file_exists($robotsPath) && is_writable(DIR_FS_CATALOG))) {
            file_put_contents($robotsPath, trim($robotsContent));
            $messageStack->add_session(SUCCESS_ROBOTS_SAVED, 'success');
        } else {
            $messageStack->add_session(ERROR_ROBOTS_NOT_WRITABLE, 'error');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#tools');
        break;

    case 'search_entity':
        $search = trim($_GET['q'] ?? '');
        $type = preg_replace('/[^a-z]/', '', $_GET['type'] ?? 'product');
        $langId = (int)($_SESSION['languages_id'] ?? 1);
        $results = [];

        if (strlen($search) > 0) {
            $searchString = '%' . $search . '%';

            if ($type === 'product') {
                $sql = "SELECT p.products_id as id, pd.products_name as name, p.products_model as model
                        FROM " . TABLE_PRODUCTS . " p
                        LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd ON (p.products_id = pd.products_id AND pd.language_id = :langId)
                        WHERE (p.products_id LIKE :search OR p.products_model LIKE :search OR pd.products_name LIKE :search)
                        LIMIT 15";
            } elseif ($type === 'category') {
                $sql = "SELECT c.categories_id as id, cd.categories_name as name
                        FROM " . TABLE_CATEGORIES . " c
                        LEFT JOIN " . TABLE_CATEGORIES_DESCRIPTION . " cd ON (c.categories_id = cd.categories_id AND cd.language_id = :langId)
                        WHERE (c.categories_id LIKE :search OR cd.categories_name LIKE :search)
                        LIMIT 15";
            } elseif ($type === 'manufacturer') {
                $sql = "SELECT manufacturers_id as id, manufacturers_name as name
                        FROM " . TABLE_MANUFACTURERS . "
                        WHERE (manufacturers_id LIKE :search OR manufacturers_name LIKE :search)
                        LIMIT 15";
            } elseif ($type === 'ezpage') {
                $sql = "SELECT e.pages_id as id, ec.pages_title as name
                        FROM " . TABLE_EZPAGES . " e
                        LEFT JOIN " . TABLE_EZPAGES_CONTENT . " ec ON (e.pages_id = ec.pages_id AND ec.languages_id = :langId)
                        WHERE (e.pages_id LIKE :search OR ec.pages_title LIKE :search)
                        LIMIT 15";
            }

            if (isset($sql)) {
                $sql = $db->bindVars($sql, ':langId', $langId, 'integer');
                $sql = $db->bindVars($sql, ':search', $searchString, 'string');

                $res = $db->Execute($sql);

                if ($res && $res->RecordCount() > 0) {
                    foreach ($res as $r) {
                        $displayText = '[' . $r['id'] . '] ' . $r['name'];
                        if (isset($r['model']) && $r['model'] !== '') {
                            $displayText .= ' (' . $r['model'] . ')';
                        }
                        $results[] = [
                            'id' => $r['id'],
                            'text' => $displayText
                        ];
                    }
                }
            }
        }

        // wipe out any stray HTML, whitespace or PHP warnings generated before this point
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        echo json_encode($results);
        exit;

    case 'save_redirect':
        $rID = (int)($_POST['rID'] ?? 0);

        $sourceUrl = trim($_POST['source_url'] ?? '');
        $targetUrl = trim($_POST['target_url'] ?? '');

        // auto-clean the source URL if the admin pasted a full absolute URL
        $parsedSource = parse_url($sourceUrl);
        if (isset($parsedSource['host'])) {
            $path = ltrim($parsedSource['path'] ?? '', '/');
            $catalogPath = ltrim(parse_url(DIR_WS_CATALOG, PHP_URL_PATH) ?? '', '/');

            // strip the Zen Cart subfolder if it exists
            if ($catalogPath !== '' && strpos($path, $catalogPath) === 0) {
                $path = substr($path, strlen($catalogPath));
            }

            // rebuild the clean relative string
            $query = isset($parsedSource['query']) ? '?' . $parsedSource['query'] : '';
            $sourceUrl = $path . $query;
        }

        $sourceUrl = ltrim($sourceUrl, '/');
        // target URL is left alone so it can support external domains if needed
        $targetUrl = ltrim($targetUrl, '/');

        $overwrite = isset($_POST['overwrite_confirmed']) ? 1 : 0;

        if (empty($sourceUrl) || empty($targetUrl)) {
            $messageStack->add_session(ERROR_REDIRECT_SOURCES, 'error');
            zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#redirects');
        }

        // check if the source URL already exists
        $checkSql = "SELECT id FROM " . TABLE_ZX_SEO_REDIRECTS . " WHERE source_url = :sourceUrl";
        if ($rID > 0) {
            $checkSql .= " AND id != " . $rID; // ignore itself if editing an existing record
        }
        $checkSql = $db->bindVars($checkSql, ':sourceUrl', $sourceUrl, 'string');
        $check = $db->Execute($checkSql);

        if ($check->RecordCount() > 0 && !$overwrite) {
            // collision detected - save inputs to session and ask for overwrite confirmation.
            $_SESSION['zx_redirect_draft'] = [
                'rID' => $rID,
                'source_url' => $sourceUrl,
                'target_url' => $targetUrl,
                'collision' => true
            ];
            $messageStack->add_session(WARNING_REDIRECT_COLLISION, 'warning');
            zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#redirects');
        }

        // proceed with Save or Overwrite
        $sqlDataArray = [
            'source_url' => $sourceUrl,
            'target_url' => $targetUrl,
            'date_added' => 'now()'
        ];

        if ($check->RecordCount() > 0 && $overwrite) {
            $existingId = (int)$check->fields['id'];
            zen_db_perform(TABLE_ZX_SEO_REDIRECTS, ['target_url' => $targetUrl], 'update', "id = " . $existingId);
            $messageStack->add_session(SUCCESS_REDIRECT_OVERWRITTEN, 'success');
        } elseif ($rID > 0) {
            zen_db_perform(TABLE_ZX_SEO_REDIRECTS, $sqlDataArray, 'update', "id = " . $rID);
            $messageStack->add_session(SUCCESS_REDIRECT_UPDATED, 'success');
        } else {
            zen_db_perform(TABLE_ZX_SEO_REDIRECTS, $sqlDataArray);
            $messageStack->add_session(SUCCESS_REDIRECT_CREATED, 'success');
        }

        unset($_SESSION['zx_redirect_draft']);
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#redirects');
        break;

    case 'delete_redirect':
        $rID = (int)($_GET['rID'] ?? 0);
        if ($rID > 0) {
            $db->Execute("DELETE FROM " . TABLE_ZX_SEO_REDIRECTS . " WHERE id = " . $rID);
            $messageStack->add_session(SUCCESS_REDIRECT_DELETED, 'success');
        }
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#redirects');
        break;

    case 'clear_redirect_draft':
        unset($_SESSION['zx_redirect_draft']);
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#redirects');
        break;

    case 'save_global_head':
        $customMeta = trim($_POST['custom_meta_tags'] ?? '');

        // update the value
        $updateSql = "UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = :meta WHERE configuration_key = 'ZX_SEO_MASTER_CUSTOM_META_TAGS'";
        $updateSql = $db->bindVars($updateSql, ':meta', $customMeta, 'string');
        $db->Execute($updateSql);

        $messageStack->add_session(SUCCESS_GLOBAL_HEAD_SAVED, 'success');
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#settings');
        break;

    case 'save_global_scripts':
        $customScripts = trim($_POST['custom_footer_scripts'] ?? '');

        // update the value
        $updateSql = "UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = :scripts WHERE configuration_key = 'ZX_SEO_MASTER_CUSTOM_FOOTER_SCRIPTS'";
        $updateSql = $db->bindVars($updateSql, ':scripts', $customScripts, 'string');
        $db->Execute($updateSql);

        $messageStack->add_session(SUCCESS_GLOBAL_FOOTER_SCRIPTS_SAVED, 'success');
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#settings');
        break;

    case 'save_globals':
        $global_seo = $_POST['global_seo'] ?? [];

        // define which keys are allowed to contain leading/trailing spaces
        $divider_keys = ['PRIMARY_SECTION', 'SECONDARY_SECTION', 'TERTIARY_SECTION', 'METATAGS_DIVIDER'];

        foreach ($global_seo as $lang_id => $keys) {
            $safe_lang_id = (int)$lang_id;
            foreach ($keys as $key => $value) {
                $safe_key = zen_db_prepare_input($key);

                // if the key is a formatting divider, preserve spaces - otherwise, sanitize and trim natively
                if (in_array($safe_key, $divider_keys)) {
                    $safe_value = $value; // zen_db_input() in the query will still safely escape this
                } else {
                    $safe_value = zen_db_prepare_input($value);
                }

                // insert or update the value for the specific language
                $db->Execute("INSERT INTO " . TABLE_ZX_SEO_MASTER_GLOBALS . "
                              (config_key, language_id, config_value)
                              VALUES ('" . zen_db_input($safe_key) . "', " . $safe_lang_id . ", '" . zen_db_input($safe_value) . "')
                              ON DUPLICATE KEY UPDATE config_value = '" . zen_db_input($safe_value) . "'");
            }
        }

        $messageStack->add_session(SUCCESS_GLOBAL_SEO_SAVED, 'success');
        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#settings');
        break;

    case 'save_indexnow':
        $indexNowStatus = $_POST['indexnow_status'] === 'true' ? 'true' : 'false';
        $indexNowEndpoint = trim($_POST['indexnow_endpoint'] ?? 'https://www.bing.com/indexnow');
        $newKey = preg_replace('/[^a-zA-Z0-9-]/', '', $_POST['indexnow_key'] ?? '');

        // get the current key to compare
        $currentKeyCheck = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'ZX_INDEXNOW_KEY'");
        $currentKey = $currentKeyCheck->EOF ? '' : $currentKeyCheck->fields['configuration_value'];

        // update standard settings
        $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($indexNowStatus) . "' WHERE configuration_key = 'ZX_INDEXNOW_STATUS'");
        $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($indexNowEndpoint) . "' WHERE configuration_key = 'ZX_INDEXNOW_ENDPOINT'");

        // handle key updates and file generation
        if (!empty($newKey) && $newKey !== $currentKey) {

            // delete the old verification file if it exists
            if (!empty($currentKey)) {
                $oldFilePath = DIR_FS_CATALOG . $currentKey . '.txt';
                if (file_exists($oldFilePath) && is_writable($oldFilePath)) {
                    @unlink($oldFilePath);
                }
            }

            // update the database with the new key
            $db->Execute("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '" . zen_db_input($newKey) . "' WHERE configuration_key = 'ZX_INDEXNOW_KEY'");

            // create the new verification file
            $newFilePath = DIR_FS_CATALOG . $newKey . '.txt';
            if (is_writable(DIR_FS_CATALOG)) {
                $handle = @fopen($newFilePath, 'w');
                if (is_resource($handle)) {
                    fwrite($handle, $newKey);
                    fclose($handle);
                    $messageStack->add_session(sprintf(SUCCESS_INDEXNOW_SAVED, $newKey), 'success');
                }
            } else {
                $messageStack->add_session(sprintf(WARNING_INDEXNOW_SAVED, $newKey), 'warning');
            }
        } else {
            $messageStack->add_session(SUCCESS_INDEXNOW_UPDATED, 'success');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#settings');
        break;

    case 'save_llms':
        $file_path = DIR_FS_CATALOG . 'llms.txt';

        $site_name = zen_db_prepare_input($_POST['site_name']);
        $description = htmlspecialchars_decode(zen_db_prepare_input($_POST['description']));

        $generator = new LlmsTxtGenerator($site_name, $description);

        // Positive Guidance
        $custom_guidance = zen_db_prepare_input($_POST['custom_guidance']);
        if (isset($_POST['guidance_options']) && is_array($_POST['guidance_options'])) {
            // Re-map default guidance for the controller scope
            $default_guidance = [
                'prioritize_products' => TEXT_LLMS_MANAGER_GUIDE_PRIORITIZE_PRODUCTS,
                'short_descriptions' => TEXT_LLMS_MANAGER_GUIDE_SHORT_DESCRIPTIONS,
                'shipping_focus' => TEXT_LLMS_MANAGER_GUIDE_SHIPPING_FOCUS
            ];
            foreach ($_POST['guidance_options'] as $g_key) {
                if (isset($default_guidance[$g_key])) $generator->addGuidance($default_guidance[$g_key]);
            }
        }
        if (!empty($custom_guidance)) $generator->addGuidance($custom_guidance);

        // Preferred Sources
        $sources = [];
        if (isset($_POST['src_home'])) {
            $sources[] = ['url' => zen_catalog_href_link(FILENAME_DEFAULT), 'title' => TEXT_LLMS_MANAGER_LABEL_HOMEPAGE, 'desc' => TEXT_LLMS_MANAGER_LABEL_HOMEPAGE_DESCRIPTION];
        }
        if (isset($_POST['src_contact'])) {
            $sources[] = ['url' => zen_catalog_href_link(FILENAME_CONTACT_US), 'title' => TEXT_LLMS_MANAGER_LABEL_CONTACT, 'desc' => TEXT_LLMS_MANAGER_LABEL_CONTACT_DESCRIPTION];
        }
        if (!empty($_POST['src_about_url'])) {
            $sources[] = ['url' => zen_db_prepare_input($_POST['src_about_url']), 'title' => TEXT_LLMS_MANAGER_LABEL_ABOUT_OUTPUT, 'desc' => TEXT_LLMS_MANAGER_LABEL_ABOUT_DESCRIPTION];
        }
        if (!empty($_POST['src_faq_url'])) {
            $sources[] = ['url' => zen_db_prepare_input($_POST['src_faq_url']), 'title' => TEXT_LLMS_MANAGER_LABEL_FAQ_OUTPUT, 'desc' => TEXT_LLMS_MANAGER_LABEL_FAQ_DESCRIPTION];
        }
        if (!empty($_POST['src_blog_url'])) {
            $sources[] = ['url' => zen_db_prepare_input($_POST['src_blog_url']), 'title' => TEXT_LLMS_MANAGER_LABEL_BLOG_OUTPUT, 'desc' => TEXT_LLMS_MANAGER_LABEL_BLOG_DESCRIPTION];
        }
        $generator->addLinkSection('Preferred sources', $sources);

        // Machine-friendly sources
        $machine_links = [];
        if (isset($_POST['mf_sitemap']) && !empty($_POST['mf_sitemap_url'])) {
            $machine_links[] = ['url' => zen_db_prepare_input($_POST['mf_sitemap_url']), 'title' => TEXT_LLMS_MANAGER_LABEL_SITEMAP_OUTPUT, 'desc' => TEXT_LLMS_MANAGER_LABEL_SITEMAP_OUTPUT_DESCRIPTION];
        }
        if (isset($_POST['mf_robots'])) {
            $base_url = (defined('HTTP_SERVER') ? HTTP_SERVER : '') . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
            $machine_links[] = ['url' => $base_url . 'robots.txt', 'title' => TEXT_LLMS_MANAGER_LABEL_ROBOTS_OUTPUT, 'desc' => TEXT_LLMS_MANAGER_LABEL_ROBOTS_OUTPUT_DESCRIPTION];
        }
        $generator->addLinkSection('Machine-friendly sources', $machine_links);

        // Priority Categories
        $cat_links = [];
        if (isset($_POST['categories']) && is_array($_POST['categories'])) {
            foreach ($_POST['categories'] as $cID) {
                $cat_name = zen_get_category_name($cID, $_SESSION['languages_id']);
                $raw_url = htmlspecialchars_decode(zen_catalog_href_link(FILENAME_DEFAULT, 'cPath=' . $cID));
                $cat_links[] = [
                    'url' => $raw_url,
                    'title' => $cat_name,
                    'desc' => TEXT_LLMS_MANAGER_LABEL_PRIORITY_CATEGORIES_DESCRIPTION  . $cat_name
                ];
            }
        }
        $generator->addLinkSection('Priority Categories', $cat_links);

        $brand_links = [];
        $seen_names = [];

        // Priority Brands
        if (isset($_POST['manufacturers']) && is_array($_POST['manufacturers'])) {
            foreach ($_POST['manufacturers'] as $mID) {
                $mID = (int)$mID;
                $man_query = $db->Execute("SELECT manufacturers_name FROM " . TABLE_MANUFACTURERS . " WHERE manufacturers_id = " . $mID);
                if (!$man_query->EOF) {
                    $man_name = $man_query->fields['manufacturers_name'];
                    $clean_name = trim($man_name);
                    if (!empty($clean_name) && !in_array($clean_name, $seen_names)) {
                        $raw_url = htmlspecialchars_decode(zen_catalog_href_link(FILENAME_DEFAULT, 'manufacturers_id=' . $mID));
                        $brand_links[] = [
                            'url' => $raw_url,
                            'title' => $clean_name,
                            'desc' => TEXT_LLMS_MANAGER_LABEL_PRIORITY_BRANDS_DESCRIPTION . $clean_name
                        ];
                        $seen_names[] = $clean_name;
                    }
                }
            }
        }
        $generator->addLinkSection('Priority Brands', $brand_links);

        // Priority Products
        $prod_links = [];
        $prod_ids = zen_db_prepare_input($_POST['product_ids']);
        if (!empty($prod_ids)) {
            $id_array = explode(',', $prod_ids);
            foreach ($id_array as $pID) {
                $pID = (int)trim($pID);
                if ($pID > 0) {
                    $p_name = zen_get_products_name($pID);
                    if ($p_name) {
                        $raw_url = htmlspecialchars_decode(zen_catalog_href_link(FILENAME_PRODUCT_INFO, 'products_id=' . $pID));
                        $prod_links[] = [
                            'url' => $raw_url,
                            'title' => $p_name,
                            'desc' => TEXT_LLMS_MANAGER_LABEL_PRODUCT_IDS_DESCRIPTION
                        ];
                    }
                }
            }
        }
        $generator->addLinkSection('Priority Products', $prod_links);

        // Policies
        $policy_links = [];
        if (isset($_POST['include_shipping'])) {
            $policy_links[] = ['url' => zen_catalog_href_link(FILENAME_SHIPPING), 'title' => TEXT_LLMS_MANAGER_LABEL_SHIPPING, 'desc' => TEXT_LLMS_MANAGER_LABEL_SHIPPING_DESCRIPTION];
        }
        if (isset($_POST['include_privacy'])) {
            $policy_links[] = ['url' => zen_catalog_href_link(FILENAME_PRIVACY), 'title' => TEXT_LLMS_MANAGER_LABEL_PRIVACY, 'desc' => TEXT_LLMS_MANAGER_LABEL_PRIVACY_DESCRIPTION];
        }
        if (isset($_POST['include_conditions'])) {
            $policy_links[] = ['url' => zen_catalog_href_link(FILENAME_CONDITIONS), 'title' => TEXT_LLMS_MANAGER_LABEL_CONDITIONS, 'desc' => TEXT_LLMS_MANAGER_LABEL_CONDITIONS_DESCRIPTION];
        }
        $generator->addLinkSection('Policies and trust pages', $policy_links);

        // De-emphasise
        $de_items = [];
        if (isset($_POST['de_options']) && is_array($_POST['de_options'])) {
            $default_deemphasis = [
                'search_results' => TEXT_LLMS_MANAGER_DE_SEARCH_RESULTS,
                'filtered_urls' => TEXT_LLMS_MANAGER_DE_FILTERED_URLS,
                'thin_content' => TEXT_LLMS_MANAGER_DE_THIN_CONTENT
            ];
            foreach ($_POST['de_options'] as $d_key) {
                if (isset($default_deemphasis[$d_key])) $de_items[] = $default_deemphasis[$d_key];
            }
        }
        $custom_de = zen_db_prepare_input($_POST['custom_deemphasis']);
        if (!empty($custom_de)) {
            $lines = explode("\n", $custom_de);
            foreach($lines as $line) {
                if(trim($line)) $de_items[] = trim(htmlspecialchars_decode($line));
            }
        }
        $generator->addListSection('De-emphasise', $de_items);

        // Generate and save to file
        $content = $generator->generate();
        if (file_put_contents($file_path, $content)) {
            $messageStack->add_session(TEXT_LLMS_MANAGER_SAVE_SUCCESS, 'success');
        } else {
            $messageStack->add_session(TEXT_LLMS_MANAGER_SAVE_ERROR, 'error');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#llms');
        break;

    case 'analyze_content':
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');

        $type = preg_replace('/[^a-z]/', '', $_GET['cType'] ?? '');
        $id = (int)($_GET['cId'] ?? 0);
        $langId = (int)($_SESSION['languages_id'] ?? 1);

        $title = '';
        $content = '';
        $nativeMetaTitle = '';
        $nativeMetaDesc = '';
        $customMetaTitle = '';
        $customMetaDesc = '';

        if ($type === 'product' && $id > 0) {
            // get core content
            $sql = "SELECT pd.products_name, pd.products_description
                    FROM " . TABLE_PRODUCTS_DESCRIPTION . " pd
                    WHERE pd.products_id = " . $id . " AND pd.language_id = " . $langId;
            $result = $db->Execute($sql);
            if (!$result->EOF) {
                $title = (string)$result->fields['products_name'];
                $content = (string)$result->fields['products_description'];
            }

            // get native Zen Cart Meta Tags
            $metaSql = "SELECT metatags_title, metatags_description
                        FROM " . TABLE_META_TAGS_PRODUCTS_DESCRIPTION . "
                        WHERE products_id = " . $id . " AND language_id = " . $langId;
            $metaResult = $db->Execute($metaSql);
            if (!$metaResult->EOF) {
                $nativeMetaTitle = (string)$metaResult->fields['metatags_title'];
                $nativeMetaDesc = (string)$metaResult->fields['metatags_description'];
            }
        } elseif ($type === 'category' && $id > 0) {
            // get core content
            $sql = "SELECT cd.categories_name, cd.categories_description
                    FROM " . TABLE_CATEGORIES_DESCRIPTION . " cd
                    WHERE cd.categories_id = " . $id . " AND cd.language_id = " . $langId;
            $result = $db->Execute($sql);
            if (!$result->EOF) {
                $title = (string)$result->fields['categories_name'];
                $content = (string)$result->fields['categories_description'];
            }
        }

        // get custom Meta Tags
        $customSql = "SELECT meta_title, meta_description
                      FROM " . TABLE_ZX_SEO_METADATA . "
                      WHERE entity_type = '" . zen_db_input($type) . "'
                      AND entity_id = " . $id . " AND language_id = " . $langId;
        $customResult = $db->Execute($customSql);
        if (!$customResult->EOF) {
            $customMetaTitle = (string)$customResult->fields['meta_title'];
            $customMetaDesc = (string)$customResult->fields['meta_description'];
        }

        if (empty($title) && empty($content)) {
            die(json_encode(['error' => ANALYZE_CONTENT_NOT_FOUND]));
        }

        die(json_encode([
            'success' => true,
            'title' => $title,
            'content' => strip_tags($content),
            'nativeMetaTitle' => $nativeMetaTitle,
            'nativeMetaDesc' => $nativeMetaDesc,
            'customMetaTitle' => $customMetaTitle,
            'customMetaDesc' => $customMetaDesc
        ]));

    case 'audit_missing_meta':
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');

        $langId = (int)($_SESSION['languages_id'] ?? 1);
        $missing = [];

        // find products with missing Meta Descriptions
        $productSql = "
            SELECT p.products_id AS id, pd.products_name AS name, 'product' AS type
            FROM " . TABLE_PRODUCTS . " p
            JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd ON (p.products_id = pd.products_id AND pd.language_id = " . $langId . ")
            LEFT JOIN " . TABLE_META_TAGS_PRODUCTS_DESCRIPTION . " mtpd ON (p.products_id = mtpd.products_id AND mtpd.language_id = " . $langId . ")
            LEFT JOIN " . TABLE_ZX_SEO_METADATA . " zsm ON (p.products_id = zsm.entity_id AND zsm.entity_type = 'product' AND zsm.language_id = " . $langId . ")
            WHERE p.products_status = 1
            AND (mtpd.metatags_description IS NULL OR mtpd.metatags_description = '')
            AND (zsm.meta_description IS NULL OR zsm.meta_description = '')
            ORDER BY p.products_id DESC
            LIMIT 50";

        $productResult = $db->Execute($productSql);
        foreach ($productResult as $product) {
            $missing[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'type' => 'product'
            ];
        }

        // find categories with missing Meta Descriptions
        $categorySql = "
            SELECT c.categories_id AS id, cd.categories_name AS name, 'category' AS type
            FROM " . TABLE_CATEGORIES . " c
            JOIN " . TABLE_CATEGORIES_DESCRIPTION . " cd ON (c.categories_id = cd.categories_id AND cd.language_id = " . $langId . ")
            LEFT JOIN " . TABLE_METATAGS_CATEGORIES_DESCRIPTION . " mtcd ON (c.categories_id = mtcd.categories_id AND mtcd.language_id = " . $langId . ")
            LEFT JOIN " . TABLE_ZX_SEO_METADATA . " zsm ON (c.categories_id = zsm.entity_id AND zsm.entity_type = 'category' AND zsm.language_id = " . $langId . ")
            WHERE c.categories_status = 1
            AND (mtcd.metatags_description IS NULL OR mtcd.metatags_description = '')
            AND (zsm.meta_description IS NULL OR zsm.meta_description = '')
            ORDER BY c.categories_id DESC
            LIMIT 50";

        $categoryResult = $db->Execute($categorySql);
        foreach ($categoryResult as $category) {
            $missing[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'type' => 'category'
            ];
        }

        die(json_encode(['success' => true, 'data' => $missing]));

    case 'migrate_native_meta':
        $importedCount = 0;

        // migrate native product Meta Tags
        $prodSql = "SELECT products_id, language_id, metatags_title, metatags_keywords, metatags_description
                    FROM " . TABLE_META_TAGS_PRODUCTS_DESCRIPTION . "
                    WHERE metatags_title != '' OR metatags_description != '' OR metatags_keywords != ''";
        $prodMeta_data = $db->Execute($prodSql);

        foreach ($prodMeta_data as $prodMeta) {
            $pID = (int)$prodMeta['products_id'];
            $lID = (int)$prodMeta['language_id'];

            // check if custom data already exists
            $checkSql = "SELECT id FROM " . TABLE_ZX_SEO_METADATA . " WHERE entity_type = 'product' AND entity_id = " . $pID . " AND language_id = " . $lID;
            $check = $db->Execute($checkSql);

            if ($check->EOF) {
                $sqlDataArray = [
                    'entity_type' => 'product',
                    'entity_id' => $pID,
                    'language_id' => $lID,
                    'meta_title' => $prodMeta['metatags_title'],
                    'meta_description' => $prodMeta['metatags_description'],
                    'focus_keyword' => $prodMeta['metatags_keywords']
                ];
                zen_db_perform(TABLE_ZX_SEO_METADATA, $sqlDataArray);
                $importedCount++;
            }
        }

        // migrate native category Meta Tags
        $catSql = "SELECT categories_id, language_id, metatags_title, metatags_keywords, metatags_description
                   FROM " . TABLE_METATAGS_CATEGORIES_DESCRIPTION . "
                   WHERE metatags_title != '' OR metatags_description != '' OR metatags_keywords != ''";
        $catMeta_data = $db->Execute($catSql);

        foreach ($catMeta_data as $catMeta) {
            $cID = (int)$catMeta['categories_id'];
            $lID = (int)$catMeta['language_id'];

            $checkSql = "SELECT id FROM " . TABLE_ZX_SEO_METADATA . " WHERE entity_type = 'category' AND entity_id = " . $cID . " AND language_id = " . $lID;
            $check = $db->Execute($checkSql);

            if ($check->EOF) {
                $sqlDataArray = [
                    'entity_type' => 'category',
                    'entity_id' => $cID,
                    'language_id' => $lID,
                    'meta_title' => $catMeta['metatags_title'],
                    'meta_description' => $catMeta['metatags_description'],
                    'focus_keyword' => $catMeta['metatags_keywords']
                ];
                zen_db_perform(TABLE_ZX_SEO_METADATA, $sqlDataArray);
                $importedCount++;
            }
        }

        if ($importedCount > 0) {
            $messageStack->add_session(sprintf(SUCCESS_MIGRATE_META, $importedCount), 'success');
        } else {
            $messageStack->add_session(SUCCESS_MIGRATE_META_INFO, 'notice');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#tools');
        break;

    case 'save_usu_config':
        $configuration = $_POST['configuration'] ?? [];

        if (!empty($configuration)) {
            foreach ($configuration as $key => $value) {
                $safe_key = zen_db_prepare_input($key);

                // handle potential multi-select arrays, though USU mostly uses strings/booleans
                if (is_array($value)) {
                    $safe_value = zen_db_prepare_input(implode(', ', $value));
                } else {
                    $safe_value = zen_db_prepare_input($value);
                }

                $db->Execute("UPDATE " . TABLE_CONFIGURATION . "
                              SET configuration_value = '" . zen_db_input($safe_value) . "'
                              WHERE configuration_key = '" . zen_db_input($safe_key) . "'
                              AND configuration_group_id = " . $usu_group_id);
            }
            $messageStack->add_session(SUCCESS_USU_UPDATED, 'success');
        } else {
            $messageStack->add_session(WARNING_USU_NO_UPDATE, 'warning');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#ultimate_urls');
        break;

    case 'save_sd_config':
        $configuration = $_POST['configuration'] ?? [];

        if (!empty($configuration)) {
            foreach ($configuration as $key => $value) {
                $safe_key = zen_db_prepare_input($key);

                if (is_array($value)) {
                    $safe_value = zen_db_prepare_input(implode(', ', $value));
                } else {
                    $safe_value = zen_db_prepare_input($value);
                }

                $db->Execute("UPDATE " . TABLE_CONFIGURATION . "
                              SET configuration_value = '" . zen_db_input($safe_value) . "'
                              WHERE configuration_key = '" . zen_db_input($safe_key) . "'
                              AND configuration_group_id = " . $sd_group_id);
            }
            $messageStack->add_session(SUCCESS_SD_UPDATED, 'success');
        } else {
            $messageStack->add_session(WARNING_CONFIG_NO_UPDATE, 'warning');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#structured_data');
        break;

    case 'save_ga4_config':
        $configuration = $_POST['configuration'] ?? [];

        if (!empty($configuration)) {
            foreach ($configuration as $key => $value) {
                $safe_key = zen_db_prepare_input($key);

                if (is_array($value)) {
                    $safe_value = zen_db_prepare_input(implode(', ', $value));
                } else {
                    $safe_value = zen_db_prepare_input($value);
                }

                $db->Execute("UPDATE " . TABLE_CONFIGURATION . "
                              SET configuration_value = '" . zen_db_input($safe_value) . "'
                              WHERE configuration_key = '" . zen_db_input($safe_key) . "'
                              AND configuration_group_id = " . $ga4_group_id);
            }
            $messageStack->add_session(SUCCESS_GA4_UPDATED, 'success');
        } else {
            $messageStack->add_session(WARNING_CONFIG_NO_UPDATE, 'warning');
        }

        zen_redirect(zen_href_link(FILENAME_ZX_SEO_MASTER) . '#ga4_analytics');
        break;
} // end switch

// Redirects tab
// handle form state (drafts & editing)
$rID = 0;
$rSource = '';
$rTarget = '';
$rCollision = false;

if (isset($_SESSION['zx_redirect_draft'])) {
    $rID = (int)$_SESSION['zx_redirect_draft']['rID'];
    $rSource = $_SESSION['zx_redirect_draft']['source_url'];
    $rTarget = $_SESSION['zx_redirect_draft']['target_url'];
    $rCollision = $_SESSION['zx_redirect_draft']['collision'];
} elseif (isset($_GET['action']) && $_GET['action'] === 'edit_redirect' && isset($_GET['rID'])) {
    $editSql = "SELECT id, source_url, target_url FROM " . TABLE_ZX_SEO_REDIRECTS . " WHERE id = :id LIMIT 1";
    $editSql = $db->bindVars($editSql, ':id', (int)$_GET['rID'], 'integer');
    $editRes = $db->Execute($editSql);
    if (!$editRes->EOF) {
        $rID = (int)$editRes->fields['id'];
        $rSource = $editRes->fields['source_url'];
        $rTarget = $editRes->fields['target_url'];
    }
}

// handle search and pagination for the table
$searchRedirects = trim($_GET['search_redirects'] ?? '');
$redirectsQuery = "SELECT id, source_url, target_url, date_added FROM " . TABLE_ZX_SEO_REDIRECTS;
if (!empty($searchRedirects)) {
    $searchEscaped = zen_db_input($searchRedirects);
    $redirectsQuery .= " WHERE source_url LIKE '%{$searchEscaped}%' OR target_url LIKE '%{$searchEscaped}%'";
}
$redirectsQuery .= " ORDER BY date_added DESC";

if (!isset($_GET['page']) || empty($_GET['page'])) {
    $_GET['page'] = '1';
}
$maxResults = defined('MAX_DISPLAY_SEARCH_RESULTS') ? (int)MAX_DISPLAY_SEARCH_RESULTS : 20;

$redirectsSplitter = new splitPageResults($_GET['page'], $maxResults, $redirectsQuery, $redirectsQueryNumRows);
$redirects = $db->Execute($redirectsQuery);

// load existing robots.txt content if it exists
$currentRobots = '';
if (file_exists($robotsPath)) {
    $currentRobots = file_get_contents($robotsPath);
}

// LLMS.txt tab
$llms_file_path = DIR_FS_CATALOG . 'llms.txt';
$current_llms_content = '';
$llms_exists = file_exists($llms_file_path);
$llms_mtime = '';

if ($llms_exists) {
    $current_llms_content = file_get_contents($llms_file_path);
    $llms_mtime = date("F d, Y H:i:s", filemtime($llms_file_path));
}

$detect_sitemap = (file_exists(DIR_FS_CATALOG . 'sitemap.xml') ? 'sitemap.xml' : (file_exists(DIR_FS_CATALOG . 'sitemapindex.xml') ? 'sitemapindex.xml' : false));
$detect_robots  = (file_exists(DIR_FS_CATALOG . 'robots.txt'));

$default_guidance = [
    'prioritize_products' => TEXT_LLMS_MANAGER_GUIDE_PRIORITIZE_PRODUCTS,
    'short_descriptions' => TEXT_LLMS_MANAGER_GUIDE_SHORT_DESCRIPTIONS,
    'shipping_focus' => TEXT_LLMS_MANAGER_GUIDE_SHIPPING_FOCUS
];

$default_deemphasis = [
    'search_results' => TEXT_LLMS_MANAGER_DE_SEARCH_RESULTS,
    'filtered_urls' => TEXT_LLMS_MANAGER_DE_FILTERED_URLS,
    'thin_content' => TEXT_LLMS_MANAGER_DE_THIN_CONTENT
];

$cat_array = [];
$categories = $db->Execute("SELECT c.categories_id, cd.categories_name FROM " . TABLE_CATEGORIES . " c LEFT JOIN " . TABLE_CATEGORIES_DESCRIPTION . " cd ON c.categories_id = cd.categories_id WHERE cd.language_id = " . (int)$_SESSION['languages_id'] . " ORDER BY cd.categories_name");
foreach ($categories as $category) {
    $cat_array[] = ['id' => $category['categories_id'], 'text' => $category['categories_name']];
}

$man_array = [];
$manufacturers = $db->Execute("SELECT manufacturers_id, manufacturers_name FROM " . TABLE_MANUFACTURERS . " ORDER BY manufacturers_name");
foreach ($manufacturers as $manufacturer) {
    $man_array[] = ['id' => $manufacturer['manufacturers_id'], 'text' => $manufacturer['manufacturers_name']];
}

$base_url_cat = (defined('HTTP_SERVER') ? HTTP_SERVER : '') . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/');
$default_sitemap_url = $base_url_cat . ($detect_sitemap ? $detect_sitemap : 'sitemap.xml');

// get languages and current Global SEO Settings
$languages = zen_get_languages();

$globals_query = $db->Execute("SELECT config_key, language_id, config_value FROM " . TABLE_ZX_SEO_MASTER_GLOBALS);
$seo_globals = [];
foreach ($globals_query as $global) {
    // array format: $seo_globals[LanguageID][ConfigKey]
    $seo_globals[$global['language_id']][$global['config_key']] = $global['config_value'];
}

?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
    <meta charset="<?php echo CHARSET; ?>">
    <title><?php echo TITLE; ?></title>
    <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="container-fluid">
    <h1 class="pageHeading"><?= HEADING_TITLE ?></h1>

    <div class="row">
        <div class="col-md-10 col-md-offset-1">

            <ul class="nav nav-tabs" role="tablist" id="seoTabs">
                <li role="presentation" class="active">
                    <a href="#settings" aria-controls="settings" role="tab" data-toggle="tab"><?= TEXT_TAB_GLOBAL_SETTINGS ?></a>
                </li>
                <li role="presentation">
                    <a href="#editor" aria-controls="editor" role="tab" data-toggle="tab"><?= TEXT_TAB_EDITOR ?></a>
                </li>
                <!-- plugin integrations -->
                <?php if ($usu_installed) { ?>
                    <li role="presentation">
                        <a href="#ultimate_urls" aria-controls="ultimate_urls" role="tab" data-toggle="tab"><i class="fa fa-link"></i> <?= TEXT_TAB_USU ?></a>
                    </li>
                <?php } ?>
                <?php if ($ceon_installed) { ?>
                    <li role="presentation">
                        <a href="#ceon_uri" aria-controls="ceon_uri" role="tab" data-toggle="tab"><i class="fa fa-link"></i> <?= TEXT_TAB_CEON ?></a>
                    </li>
                <?php } ?>
                <?php if ($sd_installed) { ?>
                    <li role="presentation">
                        <a href="#structured_data" aria-controls="structured_data" role="tab" data-toggle="tab"><i class="fa fa-code"></i> <?= TEXT_TAB_STRUCTURED_DATA ?></a>
                    </li>
                <?php } ?>
                <?php if ($ga4_installed) { ?>
                    <li role="presentation">
                        <a href="#ga4_analytics" aria-controls="ga4_analytics" role="tab" data-toggle="tab"><i class="fa fa-line-chart"></i> <?= TEXT_TAB_GA4 ?></a>
                    </li>
                <?php } ?>
                <li role="presentation">
                    <a href="#audit" aria-controls="editor" role="tab" data-toggle="tab"><?= TEXT_TAB_AUDIT ?></a>
                </li>
                <li role="presentation">
                    <a href="#analysis" aria-controls="analysis" role="tab" data-toggle="tab"><?= TEXT_TAB_CONTENT_ANALYSIS ?></a>
                </li>
                <li role="presentation">
                    <a href="#redirects" aria-controls="redirects" role="tab" data-toggle="tab"><?= TEXT_TAB_301 ?></a>
                </li>
                <li role="presentation">
                    <a href="#tools" aria-controls="tools" role="tab" data-toggle="tab"><?= TEXT_TAB_TOOLS ?></a>
                </li>
                <li role="presentation">
                    <a href="#llms" aria-controls="llms" role="tab" data-toggle="tab"><?= TEXT_TAB_LLMS ?></a>
                </li>
                <li role="presentation">
                    <a href="#recommended" aria-controls="recommended" role="tab" data-toggle="tab"><?= TEXT_TAB_RECOMMENDATIONS ?></a>
                </li>
                <li role="presentation">
                    <a href="#services" aria-controls="services" role="tab" data-toggle="tab"><?= TEXT_TAB_SERVICES ?></a>
                </li>
            </ul>

            <div class="tab-content">

                <!-- TAB 1: Global Settings -->
                <div role="tabpanel" class="tab-pane active" id="settings">
                    <div class="row">
                        <div class="col-md-8 col-md-offset-2">
                            <!-- Global Meta Panel -->
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><i class="fa fa-globe"></i> <?= TITLE_GLOBAL_SEO ?></h3>
                                </div>
                                <div class="panel-body">
                                    <?= zen_draw_form('save_globals_form', FILENAME_ZX_SEO_MASTER, 'action=save_globals', 'post') ?>
                                    <?= zen_draw_hidden_field('securityToken', $_SESSION['securityToken']) ?>

                                    <!-- Language Tabs -->
                                    <ul class="nav nav-tabs" role="tablist">
                                        <?php foreach ($languages as $index => $lang) { ?>
                                            <li role="presentation" class="<?php echo ($index === 0) ? 'active' : ''; ?>">
                                                <a href="#seo_lang_<?= $lang['id'] ?>" aria-controls="seo_lang_<?= $lang['id'] ?>" role="tab" data-toggle="tab">
                                                    <?php echo zen_image(DIR_WS_CATALOG_LANGUAGES . $lang['directory'] . '/images/' . $lang['image'], $lang['name']) . ' ' . $lang['name']; ?>
                                                </a>
                                            </li>
                                        <?php } ?>
                                    </ul>

                                    <!-- Tab Panes -->
                                    <div class="tab-content">
                                        <?php foreach ($languages as $index => $lang) {
                                            $lID = (int)$lang['id'];
                                            ?>
                                            <div role="tabpanel" class="tab-pane <?php echo ($index === 0) ? 'active' : ''; ?>" id="seo_lang_<?= $lID ?>">

                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <fieldset>
                                                            <legend><?= TITLE_GENERAL_STORE_TAGS ?></legend>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_STORE_NAME ?></label>
                                                                <?= zen_draw_input_field("global_seo[{$lID}][TITLE]", $seo_globals[$lID]['TITLE'] ?? '', 'class="form-control" placeholder="' . TEXT_LABEL_STORE_NAME_PLACEHOLDER .'"') ?>
                                                            </div>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_STORE_TAGLINE ?></label>
                                                                <?= zen_draw_input_field("global_seo[{$lID}][SITE_TAGLINE]", $seo_globals[$lID]['SITE_TAGLINE'] ?? '', 'class="form-control" placeholder="' . TEXT_LABEL_STORE_PLACEHOLDER . '"') ?>
                                                            </div>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_KEYWORDS ?></label>
                                                                <?= zen_draw_input_field("global_seo[{$lID}][CUSTOM_KEYWORDS]", $seo_globals[$lID]['CUSTOM_KEYWORDS'] ?? '', 'class="form-control"') ?>
                                                            </div>
                                                        </fieldset>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <fieldset>
                                                            <legend><?= TITLE_HOME_PAGE_OVERRIDES ?></legend>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_HOME_PAGE_TITLE ?></label>
                                                                <?= zen_draw_input_field("global_seo[{$lID}][HOME_PAGE_TITLE]", $seo_globals[$lID]['HOME_PAGE_TITLE'] ?? '', 'class="form-control"') ?>
                                                            </div>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_HOME_PAGE_DESCRIPTION ?></label>
                                                                <?= zen_draw_textarea_field("global_seo[{$lID}][HOME_PAGE_META_DESCRIPTION]", 'soft', '100%', '3', $seo_globals[$lID]['HOME_PAGE_META_DESCRIPTION'] ?? '', 'class="form-control"') ?>
                                                            </div>
                                                            <div class="form-group">
                                                                <label><?= TEXT_LABEL_HOME_PAGE_KEYWORDS ?></label>
                                                                <?= zen_draw_textarea_field("global_seo[{$lID}][HOME_PAGE_META_KEYWORDS]", 'soft', '100%', '2', $seo_globals[$lID]['HOME_PAGE_META_KEYWORDS'] ?? '', 'class="form-control"') ?>
                                                            </div>
                                                        </fieldset>
                                                    </div>
                                                </div>

                                                <!-- Formatting Dividers -->
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <fieldset>
                                                            <legend><a data-toggle="collapse" href="#advancedDividers_<?= $lID ?>" aria-expanded="false"><?= TITLE_FORMATTING_DIVIDERS ?></a></legend>
                                                            <div class="collapse" id="advancedDividers_<?= $lID ?>">
                                                                <div class="row">
                                                                    <div class="col-md-3 form-group">
                                                                        <label><?= TEXT_LABEL_PRIMARY_SECTION ?></label>
                                                                        <?= zen_draw_input_field("global_seo[{$lID}][PRIMARY_SECTION]", $seo_globals[$lID]['PRIMARY_SECTION'] ?? ' : ', 'class="form-control"') ?>
                                                                    </div>
                                                                    <div class="col-md-3 form-group">
                                                                        <label><?= TEXT_LABEL_SECONDARY_SECTION ?></label>
                                                                        <?= zen_draw_input_field("global_seo[{$lID}][SECONDARY_SECTION]", $seo_globals[$lID]['SECONDARY_SECTION'] ?? ' - ', 'class="form-control"') ?>
                                                                    </div>
                                                                    <div class="col-md-3 form-group">
                                                                        <label><?= TEXT_LABEL_TERTIARY_SECTION ?></label>
                                                                        <?= zen_draw_input_field("global_seo[{$lID}][TERTIARY_SECTION]", $seo_globals[$lID]['TERTIARY_SECTION'] ?? ', ', 'class="form-control"') ?>
                                                                    </div>
                                                                    <div class="col-md-3 form-group">
                                                                        <label><?php echo TEXT_LABEL_METATAGS_DIVIDER ?></label>
                                                                        <?= zen_draw_input_field("global_seo[{$lID}][METATAGS_DIVIDER]", $seo_globals[$lID]['METATAGS_DIVIDER'] ?? ' ', 'class="form-control"') ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </fieldset>
                                                    </div>
                                                </div>

                                            </div>
                                        <?php } ?>
                                    </div>

                                    <div class="text-right mt-15">
                                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= BUTTON_SAVE_GLOBAL_SETTINGS ?></button>
                                    </div>

                                    </form>
                                </div>
                            </div>

                            <!-- Global Head Panel -->
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?= TITLE_GLOBAL_HEAD_SETTINGS ?></h3>
                                </div>
                                <div class="panel-body">
                                    <p><?= TEXT_GLOBAL_HEAD_SETTINGS ?></p>

                                    <?php
                                    $customMetaTags = '';
                                    $metaCheck = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'ZX_SEO_MASTER_CUSTOM_META_TAGS'");
                                    if (!$metaCheck->EOF) {
                                        $customMetaTags = $metaCheck->fields['configuration_value'];
                                    }
                                    ?>

                                    <?= zen_draw_form('zx_global_settings', FILENAME_ZX_SEO_MASTER, 'action=save_global_head', 'post') ?>
                                    <div class="form-group">
                                        <label for="custom_meta_tags"><?= TEXT_LABEL_CUSTOM_META_TAGS; ?></label>
                                        <?= zen_draw_textarea_field('custom_meta_tags', 'soft', '100%', '8', htmlspecialchars($customMetaTags, ENT_QUOTES, CHARSET), 'class="form-control monospace" id="custom_meta_tags" placeholder="<meta name=&quot;google-site-verification&quot; content=&quot;...&quot; />"') ?>
                                        <span class="help-block"><?= TEXT_CUSTOM_META_TAGS ?></span>
                                    </div>

                                    <div class="text-right">
                                        <button type="submit" class="btn btn-primary"><?= BUTTON_SAVE_GLOBAL_SETTINGS ?></button>
                                    </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Global Footer Scripts Panel -->
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?= TITLE_GLOBAL_FOOTER_SCRIPTS ?></h3>
                                </div>
                                <div class="panel-body">
                                    <p><?= TEXT_GLOBAL_FOOTER_SCRIPTS ?></p>

                                    <?php
                                    $customFooterScripts = '';
                                    $scriptCheck = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'ZX_SEO_MASTER_CUSTOM_FOOTER_SCRIPTS'");
                                    if (!$scriptCheck->EOF) {
                                        $customFooterScripts = $scriptCheck->fields['configuration_value'];
                                    }
                                    ?>

                                    <?= zen_draw_form('zx_global_scripts', FILENAME_ZX_SEO_MASTER, 'action=save_global_scripts', 'post') ?>
                                    <div class="form-group">
                                        <label for="custom_footer_scripts"><?php echo TEXT_LABEL_FOOTER_SCRIPTS; ?></label>
                                        <?= zen_draw_textarea_field('custom_footer_scripts', 'soft', '100%', '8', htmlspecialchars($customFooterScripts, ENT_QUOTES, CHARSET), 'class="form-control monospace" id="custom_footer_scripts" placeholder="' . TEXT_LABEL_FOOTER_SCRIPTS_PLACEHOLDER .'"') ?>
                                    </div>

                                    <div class="text-right">
                                        <button type="submit" class="btn btn-primary"><?= BUTTON_SAVE_FOOTER_SCRIPTS ?></button>
                                    </div>
                                    </form>
                                </div>
                            </div>

                            <!-- IndexNow API Panel -->
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?= TITLE_INDEXNOW_SETTINGS ?></h3>
                                </div>
                                <div class="panel-body">
                                    <p><?= TEXT_INDEXNOW_INTRO ?></p>

                                    <?= zen_draw_form('zx_indexnow', FILENAME_ZX_SEO_MASTER, 'action=save_indexnow', 'post'); ?>

                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label for="indexnow_status"><?= TEXT_LABEL_INDEXNOW_STATUS ?></label>
                                            <?php
                                            $statusOptions = [
                                                ['id' => 'true', 'text' => TEXT_LABEL_INDEXNOW_STATUS_ENABLED],
                                                ['id' => 'false', 'text' => TEXT_LABEL_INDEXNOW_STATUS_DISABLED]
                                            ];
                                            $currentStatus = defined('ZX_INDEXNOW_STATUS') ? ZX_INDEXNOW_STATUS : 'false';
                                            echo zen_draw_pull_down_menu('indexnow_status', $statusOptions, $currentStatus, 'class="form-control" id="indexnow_status"');
                                            ?>
                                        </div>

                                        <div class="col-md-6 form-group">
                                            <label for="indexnow_endpoint"><?= TEXT_LABEL_INDEXNOW_ENDPOINT ?></label>
                                            <?php
                                            $endpointOptions = [
                                                ['id' => 'https://www.bing.com/indexnow', 'text' => 'Bing (bing.com)'],
                                                ['id' => 'https://api.indexnow.org/indexnow', 'text' => 'IndexNow (api.indexnow.org)'],
                                                ['id' => 'https://yandex.com/indexnow', 'text' => 'Yandex (yandex.com)'],
                                                ['id' => 'https://search.seznam.cz/indexnow', 'text' => 'Seznam (seznam.cz)']
                                            ];
                                            $currentEndpoint = defined('ZX_INDEXNOW_ENDPOINT') ? ZX_INDEXNOW_ENDPOINT : 'https://www.bing.com/indexnow';
                                            echo zen_draw_pull_down_menu('indexnow_endpoint', $endpointOptions, $currentEndpoint, 'class="form-control" id="indexnow_endpoint"');
                                            ?>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label><?= TEXT_LABEL_INDEXNOW_KEY ?></label>

                                        <?php
                                        $currentKey = defined('ZX_INDEXNOW_KEY') ? ZX_INDEXNOW_KEY : '';
                                        if (!empty($currentKey)) {
                                            ?>
                                            <div class="well well-sm">
                                                <strong><?= TEXT_INDEXNOW_CURRENT_KEY ?></strong> <code><?= zen_output_string_protected($currentKey) ?></code>
                                            </div>
                                        <?php } ?>

                                        <div class="input-group">
                                            <?php echo zen_draw_input_field('indexnow_key', '', 'class="form-control" id="indexnow_key" placeholder="' . TEXT_INDEXNOW_NEW_KEY_PLACEHOLDER . '"'); ?>
                                            <span class="input-group-btn">
                                                    <a href="https://www.bing.com/indexnow" target="_blank" class="btn btn-default" title="<?= TEXT_INDEXNOW_GENERATE_KEY_PLACEHOLDER ?>"><?= TEXT_INDEXNOW_GENERATE_KEY ?> <i class="fa fa-external-link"></i></a>
                                                </span>
                                        </div>
                                        <span class="help-block"><?= TEXT_INDEXNOW_KEY_HELP ?></span>
                                    </div>

                                    <div class="text-right">
                                        <button type="submit" class="btn btn-primary"><?= BUTTON_SAVE_INDEXNOW_SETTINGS ?></button>
                                    </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: Metadata Editor -->
                <div role="tabpanel" class="tab-pane" id="editor">
                    <?= zen_draw_form('zx_seo_form', FILENAME_ZX_SEO_MASTER, 'action=save', 'post', 'id="zxSeoForm"') ?>
                    <div class="row">
                        <div class="col-md-5 form-group">
                            <?php
                            echo zen_draw_label(TEXT_ENTITY_TYPE, 'entity_type', 'class="control-label"');

                            $typeOptions = [
                                ['id' => 'product', 'text' => TEXT_METADATA_TYPE_PRODUCT],
                                ['id' => 'category', 'text' => TEXT_METADATA_TYPE_CATEGORY],
                                ['id' => 'ezpage', 'text' => TEXT_METADATA_TYPE_EZPAGE],
                                ['id' => 'manufacturer', 'text' => TEXT_METADATA_TYPE_MANUFACTURER]
                            ];
                            echo zen_draw_pull_down_menu('entity_type', $typeOptions, $entityType, 'class="form-control" id="entity_type"');
                            ?>
                        </div>
                        <div class="col-md-5 form-group">
                            <?php
                            echo zen_draw_label(TEXT_METADATA_ENTITY_ID, 'entity_search', 'class="control-label"');
                            echo zen_draw_input_field('entity_search', '', 'class="form-control" id="entity_search" placeholder="' . TEXT_METADATA_SEARCH_PLACEHOLDER .'" autocomplete="off"');

                            // store the actual ID here for form submission
                            echo zen_draw_hidden_field('entity_id', $entityId > 0 ? $entityId : '', 'id="entity_id"');
                            ?>

                            <!-- Dropdown container for AJAX results -->
                            <ul id="search_results" class="dropdown-menu"></ul>
                        </div>
                        <div class="col-md-2 form-group">
                            <button type="button" class="btn btn-default btn-block" id="loadEntityBtn"><?= BUTTON_LOAD ?></button>
                        </div>
                    </div>

                    <hr>

                    <!-- Entity Context Display -->
                    <?php if (!empty($contextData)) { ?>
                        <div class="well well-sm context-data">
                            <?php if (!empty($contextData['image'])) { ?>
                                <div class="context-data-image">
                                    <?= zen_image(DIR_WS_CATALOG_IMAGES . $contextData['image'], $contextData['title'], 80, 80) ?>
                                </div>
                            <?php } else { ?>
                                <div class="context-data-noimage">
                                    <i class="fa fa-picture-o text-muted" style="font-size: 24px;"></i>
                                </div>
                            <?php } ?>

                            <div class="context-data-info">
                                <h4><?= zen_output_string_protected($contextData['title']); ?></h4>
                                <?php if (!empty($contextData['subtitle'])) { ?>
                                    <p class="text-muted"><?php echo $contextData['subtitle']; ?></p>
                                <?php } ?>

                                <div>
                                    <a href="<?= $contextData['admin_link'] ?>" class="btn btn-default btn-xs" target="_blank" title="<?= BUTTON_EDIT_PRODUCT ?>">
                                        <i class="fa fa-pencil"></i> <?= BUTTON_EDIT_ENTITY ?>
                                    </a>
                                    <a href="<?= $contextData['catalog_link'] ?>" class="btn btn-info btn-xs" target="_blank" title="<?= BUTTON_VIEW_STOREFRONT_TITLE ?>">
                                        <i class="fa fa-external-link"></i> <?= BUTTON_VIEW_STOREFRONT ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <!-- Language Tabs for SEO Data -->
                    <ul class="nav nav-tabs" role="tablist">
                        <?php foreach ($languages as $index => $lang) { ?>
                            <li role="presentation" class="<?= ($index === 0) ? 'active' : '' ?>">
                                <a href="#entity_lang_<?= $lang['id'] ?>" aria-controls="entity_lang_<?= $lang['id'] ?>" role="tab" data-toggle="tab">
                                    <?= zen_image(DIR_WS_CATALOG_LANGUAGES . $lang['directory'] . '/images/' . $lang['image'], $lang['name']) . ' ' . $lang['name'] ?>
                                </a>
                            </li>
                        <?php } ?>
                    </ul>

                    <!-- Tab Panes -->
                    <div class="tab-content">
                        <?php foreach ($languages as $index => $lang) {
                            $lID = (int)$lang['id'];
                            ?>
                            <div role="tabpanel" class="tab-pane <?php echo ($index === 0) ? 'active' : ''; ?>" id="entity_lang_<?= $lID ?>">

                                <div class="row">
                                    <div class="col-md-7">
                                        <div class="form-group">
                                            <?= zen_draw_label(TEXT_META_TITLE, 'meta_title_' . $lID, 'class="control-label"') ?>
                                            <div class="pull-right small"><?= TEXT_META_TITLE_CHARS ?><span id="meta_title_count_<?= $lID ?>">0</span> / 60</div>

                                            <?= zen_draw_input_field("seo_meta[{$lID}][meta_title]", $entity_metadata[$lID]['meta_title'] ?? '', 'class="form-control meta-title-input" id="meta_title_' . $lID . '" data-lang="' . $lID . '" data-max="60"') ?>

                                            <!-- Dynamic Variable Injectors -->
                                            <div class="mt-5 mb-10" style="font-size: 11px;">
                                                <span class="text-muted">Insert Tag: </span>
                                                <a href="#" class="label label-default insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%SITE_NAME%">Site Name</a>
                                                <a href="#" class="label label-default insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%SITE_TAGLINE%">Tagline</a>

                                                <?php if ($entityType === 'product') { ?>
                                                    <a href="#" class="label label-primary insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%PRODUCT_NAME%">Product Name</a>
                                                    <a href="#" class="label label-primary insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%PRODUCT_MODEL%">Model</a>
                                                    <a href="#" class="label label-primary insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%PRODUCT_PRICE%">Price</a>
                                                <?php } ?>

                                                <?php if ($entityType === 'category') { ?>
                                                    <a href="#" class="label label-info insert-variable" data-target="meta_title_<?= $lID ?>" data-val="%CATEGORY_NAME%">Category Name</a>
                                                <?php } ?>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <?= zen_draw_label(TEXT_META_DESCRIPTION, 'meta_description_' . $lID, 'class="control-label"') ?>
                                            <div class="pull-right small"><?= TEXT_META_TITLE_CHARS ?><span id="meta_description_count_<?= $lID; ?>">0</span> / 160</div>
                                            <?= zen_draw_textarea_field("seo_meta[{$lID}][meta_description]", 'soft', '100%', '4', $entity_metadata[$lID]['meta_description'] ?? '', 'class="form-control meta-desc-input" id="meta_description_' . $lID . '" data-lang="' . $lID . '" data-max="160"') ?>
                                        </div>

                                        <!-- live Google Snippet preview -->
                                        <div class="well well-sm mt-10 live-preview-snippet">
                                            <strong class="text-muted small text-uppercase"><?= TEXT_LIVE_SNIPPET_PREVIEW ?></strong><br>
                                            <div class="pt-10">
                                                <div class="lps-site-name">yoursite.com &gt; ...</div>
                                                <div id="preview_meta_title_<?= $lID ?>"></div>
                                                <div id="preview_meta_description_<?= $lID ?>"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-5">
                                        <div class="form-group">
                                            <?php
                                            echo zen_draw_label(TEXT_FOCUS_KEYWORD, 'focus_keyword_' . $lID, 'class="control-label"');
                                            echo zen_draw_input_field("seo_meta[{$lID}][focus_keyword]", $entity_metadata[$lID]['focus_keyword'] ?? '', 'class="form-control" id="focus_keyword_' . $lID . '" placeholder="e.g. stainless steel widget"');
                                            ?>
                                        </div>

                                        <div class="form-group">
                                            <?php
                                            echo zen_draw_label(TEXT_CUSTOM_CANONICAL, 'custom_canonical_' . $lID, 'class="control-label"');
                                            echo zen_draw_input_field("seo_meta[{$lID}][custom_canonical]", $entity_metadata[$lID]['custom_canonical'] ?? '', 'class="form-control" id="custom_canonical_' . $lID . '" placeholder="https://..."');
                                            ?>
                                        </div>

                                        <div class="well well-sm">
                                            <div class="checkbox">
                                                <label>
                                                    <?php
                                                    $noindex_checked = isset($entity_metadata[$lID]['is_noindex']) && $entity_metadata[$lID]['is_noindex'] == 1;
                                                    echo zen_draw_checkbox_field("seo_meta[{$lID}][is_noindex]", '1', $noindex_checked, '', 'id="is_noindex_' . $lID . '"');
                                                    ?>
                                                    <?= TEXT_LABEL_NOINDEX ?>
                                                </label>
                                            </div>
                                            <div class="checkbox">
                                                <label>
                                                    <?php
                                                    $nofollow_checked = isset($entity_metadata[$lID]['is_nofollow']) && $entity_metadata[$lID]['is_nofollow'] == 1;
                                                    echo zen_draw_checkbox_field("seo_meta[{$lID}][is_nofollow]", '1', $nofollow_checked, '', 'id="is_nofollow_' . $lID . '"');
                                                    ?>
                                                    <?= TEXT_LABEL_NOFOLLOW ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        <?php } ?>
                    </div>

                    <div class="text-right mt-15">
                        <button type="submit" class="btn btn-primary"><?php echo BUTTON_SAVE_METADATA; ?></button>
                    </div>
                    </form>
                </div>

                <!-- Dynamic Tab: Ultimate URLs -->
                <?php if ($usu_installed) { ?>
                    <div role="tabpanel" class="tab-pane" id="ultimate_urls">
                        <div class="row">
                            <div class="col-12">
                                <div class="panel panel-info mt-15">
                                    <div class="panel-heading">
                                        <h3 class="panel-title"><i class="fa fa-cogs"></i> <?= TITLE_USU_CONFIGURATION ?></h3>
                                    </div>
                                    <div class="panel-body">
                                        <?php echo zen_draw_form('usu_config_form', FILENAME_ZX_SEO_MASTER, 'action=save_usu_config', 'post'); ?>

                                        <div class="container-fluid">
                                            <?php
                                            $usu_configs = $db->Execute("SELECT configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, set_function
                                                                     FROM " . TABLE_CONFIGURATION . "
                                                                     WHERE configuration_group_id = " . $usu_group_id . "
                                                                     ORDER BY sort_order");
                                            foreach ($usu_configs as $usu_config) {
                                                $c_key = $usu_config['configuration_key'];
                                                $c_value = $usu_config['configuration_value'];
                                                $c_title = $usu_config['configuration_title'];
                                                $c_desc = $usu_config['configuration_description'];
                                                $set_function = $usu_config['set_function'];

                                                // replicating the flexbox and spacing utilities for Bootstrap 3 compatibility
                                                echo '<div class="row row-hover align-items-center config-row">';

                                                echo '  <div class="col-md-3">';
                                                echo '      <strong>' . $c_title . '</strong>';
                                                echo '  </div>';

                                                echo '  <div class="col-md-3">';
                                                if (zen_not_null($set_function)) {
                                                    eval('$value_field = ' . $set_function . '"' . zen_output_string_protected($c_value) . '", "configuration[' . $c_key . ']");');
                                                    $value_field = str_replace('<select', '<select class="form-control"', $value_field);
                                                    echo $value_field;
                                                } else {
                                                    // if it's a version number, output as text with a hidden field. Otherwise use a standard text input
                                                    if (stripos($c_title, 'version') !== false) {
                                                        echo zen_output_string_protected($c_value);
                                                        echo zen_draw_hidden_field('configuration[' . $c_key . ']', $c_value);
                                                        echo zen_draw_hidden_field('orig_' . $c_key, $c_value);
                                                    } else {
                                                        echo zen_draw_input_field('configuration[' . $c_key . ']', zen_output_string_protected($c_value), 'class="form-control"');
                                                    }
                                                }
                                                echo '  </div>';

                                                echo '  <div class="col-md-6 bg-info p-3">';
                                                echo        $c_desc;
                                                echo '  </div>';

                                                echo '</div>';
                                            }
                                            ?>
                                        </div>

                                        <div class="text-right mt-15">
                                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> <?= BUTTON_SAVE_SETTINGS ?></button>
                                        </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- Dynamic Tab: Ceon URI Mapping -->
                <?php if ($ceon_installed) { ?>
                    <div role="tabpanel" class="tab-pane" id="ceon_uri">
                        <div class="row">
                            <div class="col-md-8 col-md-offset-2">
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <h3 class="panel-title"><i class="fa fa-external-link"></i> <?= TITLE_CEON_URI_CONFIGURATION ?></h3>
                                    </div>
                                    <div class="panel-body text-center">
                                        <i class="fa fa-map-signs text-muted"></i>
                                        <h4><?= TITLE_CEON_URI_CONFIGURATION_SUB ?></h4>

                                        <p>
                                            <?= TEXT_CEON_URI_MAPPINGS_DASHBOARD ?>
                                        </p>

                                        <a href="<?php echo zen_href_link(FILENAME_CEON_URI_MAPPING_CONFIG); ?>" class="btn btn-primary btn-lg">
                                            <i class="fa fa-cogs"></i> <?= BUTTON_OPEN_CEON_URI ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- Dynamic Tab: Structured Data -->
                <?php if ($sd_installed) { ?>
                    <div role="tabpanel" class="tab-pane" id="structured_data">
                        <div class="row">
                            <div class="col-12">
                                <div class="panel panel-info">
                                    <div class="panel-heading">
                                        <h3 class="panel-title"><i class="fa fa-code"></i> <?= TITLE_STRUCTURED_DATA_CONFIGURATION ?></h3>
                                    </div>
                                    <div class="panel-body">
                                        <?php echo zen_draw_form('sd_config_form', FILENAME_ZX_SEO_MASTER, 'action=save_sd_config', 'post'); ?>

                                        <div class="container-fluid">
                                            <?php
                                            $sd_configs = $db->Execute("SELECT configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, set_function
                                                                    FROM " . TABLE_CONFIGURATION . "
                                                                    WHERE configuration_group_id = " . $sd_group_id . "
                                                                    ORDER BY sort_order");
                                            foreach ($sd_configs as $sd_config) {
                                                $c_key = $sd_config['configuration_key'];
                                                $c_value = $sd_config['configuration_value'];
                                                $c_title = $sd_config['configuration_title'];
                                                $c_desc = $sd_config['configuration_description'];
                                                $set_function = $sd_config['set_function'];

                                                echo '<div class="row row-hover align-items-center py-2 config-row">';

                                                echo '  <div class="col-md-3">';
                                                echo '      <strong>' . $c_title . '</strong>';
                                                echo '  </div>';

                                                echo '  <div class="col-md-3">';
                                                if (zen_not_null($set_function)) {
                                                    eval('$value_field = ' . $set_function . '"' . zen_output_string_protected($c_value) . '", "configuration[' . $c_key . ']");');
                                                    $value_field = str_replace('<select', '<select class="form-control"', $value_field);
                                                    echo $value_field;
                                                } else {
                                                    if (stripos($c_title, 'version') !== false) {
                                                        echo zen_output_string_protected($c_value);
                                                        echo zen_draw_hidden_field('configuration[' . $c_key . ']', $c_value);
                                                        echo zen_draw_hidden_field('orig_' . $c_key, $c_value);
                                                    } else {
                                                        echo zen_draw_input_field('configuration[' . $c_key . ']', zen_output_string_protected($c_value), 'class="form-control"');
                                                    }
                                                }
                                                echo '  </div>';

                                                echo '  <div class="col-md-6 bg-info p-3">';
                                                echo        $c_desc;
                                                echo '  </div>';

                                                echo '</div>';
                                            }
                                            ?>
                                        </div>

                                        <div class="text-right mt-15">
                                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> <?= BUTTON_SAVE_SETTINGS ?></button>
                                        </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- Dynamic Tab: GA4 Analytics -->
                <?php if ($ga4_installed) { ?>
                    <div role="tabpanel" class="tab-pane" id="ga4_analytics">
                        <div class="row">
                            <div class="col-12">
                                <div class="panel panel-info">
                                    <div class="panel-heading">
                                        <h3 class="panel-title"><i class="fa fa-line-chart"></i> <?= TITLE_GA4_CONFIGURATION ?></h3>
                                    </div>
                                    <div class="panel-body">
                                        <?php echo zen_draw_form('ga4_config_form', FILENAME_ZX_SEO_MASTER, 'action=save_ga4_config', 'post'); ?>

                                        <div class="container-fluid">
                                            <?php
                                            $ga4_configs = $db->Execute("SELECT configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, set_function
                                                                     FROM " . TABLE_CONFIGURATION . "
                                                                     WHERE configuration_group_id = " . $ga4_group_id . "
                                                                     ORDER BY sort_order");
                                            foreach ($ga4_configs as $ga4_config) {
                                                $c_key = $ga4_config['configuration_key'];
                                                $c_value = $ga4_config['configuration_value'];
                                                $c_title = $ga4_config['configuration_title'];
                                                $c_desc = $ga4_config['configuration_description'];
                                                $set_function = $ga4_config['set_function'];

                                                echo '<div class="row row-hover align-items-center py-2 config-row">';

                                                echo '  <div class="col-md-3">';
                                                echo '      <strong>' . $c_title . '</strong>';
                                                echo '  </div>';

                                                echo '  <div class="col-md-3">';
                                                if (zen_not_null($set_function)) {
                                                    eval('$value_field = ' . $set_function . '"' . zen_output_string_protected($c_value) . '", "configuration[' . $c_key . ']");');
                                                    $value_field = str_replace('<select', '<select class="form-control"', $value_field);
                                                    echo $value_field;
                                                } else {
                                                    if (stripos($c_title, 'version') !== false) {
                                                        echo zen_output_string_protected($c_value);
                                                        echo zen_draw_hidden_field('configuration[' . $c_key . ']', $c_value);
                                                        echo zen_draw_hidden_field('orig_' . $c_key, $c_value);
                                                    } else {
                                                        echo zen_draw_input_field('configuration[' . $c_key . ']', zen_output_string_protected($c_value), 'class="form-control"');
                                                    }
                                                }
                                                echo '  </div>';

                                                echo '  <div class="col-md-6 bg-info p-3">';
                                                echo        $c_desc;
                                                echo '  </div>';

                                                echo '</div>';
                                            }
                                            ?>
                                        </div>

                                        <div class="text-right mt-15">
                                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> <?= BUTTON_SAVE_SETTINGS ?></button>
                                        </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- TAB 3: Meta Audit -->
                <div role="tabpanel" class="tab-pane" id="audit">
                    <div class="panel panel-warning">
                        <div class="panel-heading">
                            <h3 class="panel-title"><i class="fa fa-stethoscope"></i> <?= TITLE_MISSING_META_AUDIT ?></h3>
                        </div>
                        <div class="panel-body">
                            <p><?= TEXT_META_AUDIT_1 ?></p>
                            <p class="text-muted small"><i class="fa fa-info-circle"></i> <?= TEXT_META_AUDIT_2 ?></p>

                            <button type="button" id="btnRunAudit" class="btn btn-warning"><i class="fa fa-search"></i> <?= BUTTON_RUN_META_AUDIT ?></button>

                            <div id="audit_results_container">
                                <table class="table table-striped table-hover">
                                    <thead>
                                    <tr>
                                        <th style="width: 10%;"><?= TEXT_META_AUDIT_TYPE ?>></th>
                                        <th style="width: 10%;"><?= TEXT_META_AUDIT_ID ?></th>
                                        <th><?= TEXT_META_AUDIT_NAME ?></th>
                                        <th class="text-right" style="width: 15%;"><?= TEXT_META_AUDIT_ACTION ?></th>
                                    </tr>
                                    </thead>
                                    <tbody id="audit_results_body">

                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: Content Analysis -->
                <div role="tabpanel" class="tab-pane" id="analysis">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?= TITLE_ANALYSIS_PARAMETERS ?></h3>
                                </div>
                                <div class="panel-body">
                                    <div class="form-group">
                                        <label for="analyze_type"><?= TEXT_ANALYSIS_LABEL_CONTENT_TYPE ?></label>
                                        <select id="analyze_type" class="form-control">
                                            <option value="product"><?= TEXT_ANALYSIS_CONTENT_TYPE_PRODUCT ?></option>
                                            <option value="category"><?= TEXT_ANALYSIS_CONTENT_TYPE_CATEGORY ?></option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="analyze_id"><?= TEXT_ANALYSIS_LABEL_ID ?></label>
                                        <input type="number" id="analyze_id" class="form-control" placeholder="<?= TEXT_ANALYSIS_LABEL_ID_PLACEHOLDER ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="analyze_focus_keyword"><?= TEXT_ANALYSIS_LABEL_KEYWORD ?></label>
                                        <input type="text" id="analyze_focus_keyword" class="form-control" placeholder="<?= TEXT_ANALYSIS_LABEL_KEYWORD_PLACEHOLDER ?>">
                                    </div>
                                    <button type="button" id="btnAnalyze" class="btn btn-primary btn-block"><?= BUTTON_RUN_ANALYSIS ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?= TITLE_ANALYSIS_SEO_SCOREBOARD ?></h3>
                                </div>
                                <div class="panel-body" id="analysis_results">
                                    <p class="text-muted"><i class="fa fa-info-circle"></i> <?= TEXT_ANALYSIS_RESULTS_START ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: 301 Redirects -->
                <div role="tabpanel" class="tab-pane" id="redirects">
                    <div class="row">

                        <!-- left column: Add/Edit form -->
                        <div class="col-md-4">
                            <div class="panel <?php echo $rCollision ? 'panel-danger' : 'panel-default'; ?>">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><?php echo $rID > 0 && !$rCollision ? TITLE_REDIRECT_EDIT : TITLE_REDIRECT_ADD; ?></h3>
                                </div>
                                <div class="panel-body">
                                    <?php echo zen_draw_form('zx_redirects', FILENAME_ZX_SEO_MASTER, 'action=save_redirect', 'post'); ?>
                                    <?php echo zen_draw_hidden_field('rID', $rID); ?>

                                    <div class="form-group <?php echo $rCollision ? 'has-error' : ''; ?>">
                                        <label for="source_url"><?= TEXT_REDIRECT_LABEL_SOURCE ?></label>
                                        <?php echo zen_draw_input_field('source_url', $rSource, 'class="form-control" id="source_url" placeholder="' . TEXT_REDIRECT_LABEL_SOURCE_PLACEHOLDER .'" required'); ?>
                                        <span class="help-block"><?= TEXT_REDIRECT_LABEL_SOURCE_HELP ?></span>
                                    </div>

                                    <div class="form-group">
                                        <label for="target_url"><?= TEXT_REDIRECT_LABEL_NEW ?></label>
                                        <?= zen_draw_input_field('target_url', $rTarget, 'class="form-control" id="target_url" placeholder="' . TEXT_REDIRECT_LABEL_NEW_PLACEHOLDER .'" required') ?>
                                    </div>

                                    <?php if ($rCollision) { ?>
                                        <div class="alert alert-danger" style="padding: 10px; font-size: 13px;">
                                            <div class="checkbox">
                                                <label>
                                                    <?php echo zen_draw_checkbox_field('overwrite_confirmed', '1', false); ?>
                                                    <strong><?= TEXT_REDIRECT_OVERWRITE ?></strong>
                                                </label>
                                            </div>
                                        </div>
                                    <?php } ?>

                                    <div class="text-right">
                                        <?php if ($rID > 0 || $rCollision) { ?>
                                            <a href="<?= zen_href_link(FILENAME_ZX_SEO_MASTER, 'action=clear_redirect_draft#redirects') ?>" class="btn btn-default"><?= TEXT_CANCEL ?></a>
                                        <?php } ?>
                                        <button type="submit" class="btn <?php echo $rCollision ? 'btn-danger' : 'btn-primary'; ?>">
                                            <?php echo $rID > 0 && !$rCollision ? BUTTON_UPDATE : BUTTON_SAVE_REDIRECT; ?>
                                        </button>
                                    </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- right column: search & data table -->
                        <div class="col-md-8">
                            <div class="row mb-20">
                                <div class="col-md-6">
                                    <h4><?= TEXT_REDIRECT_ACTIVE_REDIRECTS . ' (' . $redirectsQueryNumRows . ')'; ?></h4>
                                </div>
                                <div class="col-md-6 text-right">
                                    <?= zen_draw_form('search_redirects_form', FILENAME_ZX_SEO_MASTER, '', 'get', 'class="form-inline"') ?>
                                    <?= zen_draw_hidden_field('cmd', 'zx_seo_master') ?>
                                    <div class="input-group">
                                        <?= zen_draw_input_field('search_redirects', $searchRedirects, 'class="form-control input-sm" placeholder="' . TEXT_REDIRECTS_SEARCH_PLACEHOLDER .'"'); ?>
                                        <span class="input-group-btn">
                                                    <button class="btn btn-default btn-sm" type="submit"><?= BUTTON_SEARCH ?></button>
                                                    <?php if (!empty($searchRedirects)) { ?>
                                                        <a href="<?= zen_href_link(FILENAME_ZX_SEO_MASTER) ?>#redirects" class="btn btn-default btn-sm" title="<?= BUTTON_CLEAR_SEARCH_TITLE ?>"><i class="fa fa-times"></i></a>
                                                    <?php } ?>
                                                </span>
                                    </div>
                                    </form>
                                </div>
                            </div>

                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                <tr>
                                    <th><?= TEXT_REDIRECT_SOURCE ?></th>
                                    <th><?= TEXT_REDIRECT_TARGET ?></th>
                                    <th style="width: 120px;" class="text-center"><?= TEXT_REDIRECT_ACTION ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($redirectsQueryNumRows > 0) { ?>
                                    <?php foreach ($redirects as $r) { ?>
                                        <tr>
                                            <td style="word-break: break-all;"><?php echo zen_output_string_protected($r['source_url']); ?></td>
                                            <td style="word-break: break-all;"><?php echo zen_output_string_protected($r['target_url']); ?></td>
                                            <td class="text-center">
                                                <a href="<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER, 'action=edit_redirect&rID=' . $r['id'] . (isset($_GET['page']) ? '&page=' . $_GET['page'] : '') . '#redirects'); ?>" class="btn btn-xs btn-default" title="<?= ICON_EDIT ?>"><i class="fa fa-pencil"></i></a>
                                                <a href="<?php echo zen_href_link(FILENAME_ZX_SEO_MASTER, 'action=delete_redirect&rID=' . $r['id'] . (isset($_GET['page']) ? '&page=' . $_GET['page'] : '') . '#redirects'); ?>" class="btn btn-xs btn-danger" title="<?= ICON_DELETE ?>" onclick="return confirm('<?= TEXT_REDIRECT_DELETE_CONFIRM ?>');"><i class="fa fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } else { ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted"><?= TEXT_REDIRECTS_NO_RESULTS ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>

                            <?php if ($redirectsQueryNumRows > $maxResults) { ?>
                                <div class="row">
                                    <div class="col-md-6 pt-10">
                                        <?php echo $redirectsSplitter->display_count($redirectsQueryNumRows, $maxResults, $_GET['page'], TEXT_REDIRECTS_PAGINATION); ?>
                                    </div>
                                    <div class="col-md-6 text-right">
                                        <?php
                                        $maxPageLinks = defined('MAX_DISPLAY_PAGE_LINKS') ? (int)MAX_DISPLAY_PAGE_LINKS : 5;
                                        echo $redirectsSplitter->display_links($redirectsQueryNumRows, $maxResults, $maxPageLinks, $_GET['page'], zen_get_all_get_params(['page', 'info', 'x', 'y', 'main_page']));
                                        ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- TAB 6: Site Tools -->
                <div role="tabpanel" class="tab-pane" id="tools">

                    <!-- XML Sitemap generator -->
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h3 class="panel-title"><?= TITLE_SITEMAP_GENERATOR ?></h3>
                        </div>
                        <div class="panel-body">
                            <p><?= TEXT_SITEMAP_GENERATOR ?></p>
                            <?php echo zen_draw_form('zx_seo_sitemap', FILENAME_ZX_SEO_MASTER, 'action=generate_sitemap', 'post'); ?>
                            <button type="submit" class="btn btn-success"><?= BUTTON_GENERATE_SITEMAP ?></button>
                            </form>

                            <?php
                            $sitemapPath = DIR_FS_CATALOG . 'sitemapindex.xml';
                            if (file_exists($sitemapPath)) {
                                $robotsLine = 'Sitemap: ' . HTTP_SERVER . DIR_WS_CATALOG . 'sitemapindex.xml';
                                ?>
                                <hr>
                                <div class="alert alert-info">
                                    <p class="mb-10"><i class="fa fa-info-circle"></i> <?= TEXT_SITEMAP_ACTIVE ?></p>
                                    <div class="input-group">
                                        <input type="text" id="sitemapRobotsLine" class="form-control" value="<?= zen_output_string_protected($robotsLine) ?>" readonly>
                                        <span class="input-group-btn">
                                            <button class="btn btn-default" type="button" id="copySitemapBtn" title="<?= BUTTON_COPY_TITLE ?>">
                                                <i class="fa fa-clipboard"></i> <?= BUTTON_COPY ?>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Robots.txt Editor -->
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h3 class="panel-title"><?= TITLE_ROBOTSTXT_EDITOR ?></h3>
                        </div>
                        <div class="panel-body">
                            <p><?= TEXT_ROBOTSTXT_INTRO ?></p>

                            <!-- common rules -->
                            <div class="mb-20">
                                <button class="btn btn-default btn-xs" type="button" data-toggle="collapse" data-target="#commonRulesBlock" aria-expanded="false" aria-controls="commonRulesBlock">
                                    <i class="fa fa-lightbulb-o"></i> <?= TEXT_ROBOTSTXT_COMMON_RULES ?>
                                </button>
                                <div class="collapse mt-15" id="commonRulesBlock">
                                    <div class="well well-sm monospace">
                                        User-agent: *<br>
                                        Crawl-delay: 5<br>
                                        <span class="text-muted"># <?= TEXT_ROBOTSTXT_SAMPLE_PREVENT_SESSION ?></span><br>
                                        Disallow: /*?*zenid=<br>
                                        Disallow: /*&zenid=<br>
                                        <span class="text-muted"># <?= TEXT_ROBOTSTXT_SAMPLE_PREVENT_CURRENCY ?></span><br>
                                        Disallow: /*?*currency=<br>
                                        Disallow: /*&currency=<br>
                                        <span class="text-muted"># <?= TEXT_ROBOTSTXT_SAMPLE_PREVENT_SORT ?></span><br>
                                        Disallow: /*?*sort=<br>
                                        Disallow: /*&sort=
                                    </div>
                                </div>
                            </div>

                            <?php
                            $robotsContent = '';
                            $robotsPath = DIR_FS_CATALOG . 'robots.txt';
                            if (file_exists($robotsPath)) {
                                $robotsContent = file_get_contents($robotsPath);
                            }
                            ?>
                            <?php echo zen_draw_form('zx_seo_robots', FILENAME_ZX_SEO_MASTER, 'action=save_robots', 'post'); ?>
                            <div class="form-group">
                                <?php echo zen_draw_textarea_field('robots_content', 'soft', '100%', '15', $robotsContent, 'class="form-control monospace"'); ?>
                            </div>
                            <div class="text-right">
                                <button type="submit" class="btn btn-primary"><?= BUTTON_SAVE_ROBOTSTXT ?></button>
                            </div>
                            </form>
                        </div>
                    </div>

                    <!-- Meta Data migration tool -->
                    <div class="panel panel-info">
                        <div class="panel-heading">
                            <h3 class="panel-title"><i class="fa fa-database"></i> <?= TITLE_META_MIGRATE ?></h3>
                        </div>
                        <div class="panel-body">
                            <p><?= TEXT_META_MIGRATE_INTRO ?></p>
                            <ul class="mb-10">
                                <li><?= TEXT_META_MIGRATE_LINE1 ?></li>
                                <li><?= TEXT_META_MIGRATE_LINE2 ?></li>
                                <li><?= TEXT_META_MIGRATE_LINE3 ?></li>
                            </ul>

                            <?php echo zen_draw_form('zx_seo_migrate', FILENAME_ZX_SEO_MASTER, 'action=migrate_native_meta', 'post'); ?>
                            <button type="submit" class="btn btn-info" onclick="return confirm('<?= TEXT_META_MIGRATE_CONFIRM_IMPORT ?>');">
                                <i class="fa fa-download"></i> <?= BUTTON_IMPORT_NATIVE_METATAGS ?>
                            </button>
                            </form>
                        </div>
                    </div>

                </div>

                <!-- TAB 7: LLMS.txt -->
                <div role="tabpanel" class="tab-pane" id="llms">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="llm-top-row">
                                <h3><?= TEXT_LLMS_MANAGER_TITLE ?></h3>
                                <button type="button" class="btn btn-primary" onclick="toggleLlmPanel()">
                                    <i class="fa fa-eye"></i> <?= TEXT_LLMS_MANAGER_PREVIEW_FILE ?>
                                </button>
                            </div>

                            <?= zen_draw_form('llms_generator', FILENAME_ZX_SEO_MASTER, 'action=save_llms', 'post') ?>

                            <div class="panel panel-default">
                                <div class="panel-body">
                                    <div class="llm-section">
                                        <h4><?= TEXT_LLMS_MANAGER_SECTION_HEADER ?></h4>
                                        <div class="form-group">
                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_SITE_TITLE ?></label>
                                            <?= zen_draw_input_field('site_name', (defined('STORE_NAME') ? STORE_NAME : ''), 'class="form-control"') ?>
                                        </div>

                                        <div class="form-group">
                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_SUMMARY ?></label>
                                            <?= zen_draw_textarea_field('description', 'soft', '100%', '2', TEXT_LLMS_MANAGER_DEFAULT_DESCRIPTION, 'class="form-control"') ?>
                                        </div>

                                        <div class="form-group">
                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_POSITIVE_GUIDANCE ?></label>
                                            <?php foreach($default_guidance as $key => $text) {
                                                echo '<div class="checkbox"><label>' . zen_draw_checkbox_field('guidance_options[]', $key) . ' ' . $text . '</label></div>';
                                            } ?>
                                            <?= zen_draw_textarea_field('custom_guidance', 'soft', '100%', '2', '', 'class="form-control mt-1" placeholder="'.TEXT_LLMS_MANAGER_PLACEHOLDER_CUSTOM_GUIDANCE.'" ') ?>
                                        </div>
                                    </div>
                                    <hr>

                                    <div class="llm-section section-sources">
                                        <h4><?= TEXT_LLMS_MANAGER_SECTION_SOURCES ?></h4>

                                        <h5><?= TEXT_LLMS_MANAGER_SECTION_SOURCES_MACHINE ?></h5>
                                        <div class="form-group section-sources-checkboxes">
                                            <div class="checkbox">
                                                <label>
                                                    <?php
                                                    echo zen_draw_checkbox_field('mf_sitemap', '1', ($detect_sitemap !== false));
                                                    echo TEXT_LLMS_MANAGER_LABEL_INCLUDE_SITEMAP;
                                                    if ($detect_sitemap) {
                                                        echo '<span class="badge badge-info autodetect-badge">' . TEXT_LLMS_MANAGER_DETECTED_BADGE . ': ' . $detect_sitemap . '</span>';
                                                    } ?>
                                                </label>
                                            </div>
                                            <?= zen_draw_input_field('mf_sitemap_url', $default_sitemap_url, 'class="form-control mt-1" placeholder="'.TEXT_LLMS_MANAGER_LABEL_SITEMAP_URL_PLACEHOLDER.'"') ?>

                                            <div class="checkbox">
                                                <label>
                                                    <?php
                                                    echo zen_draw_checkbox_field('mf_robots', '1', $detect_robots);
                                                    echo TEXT_LLMS_MANAGER_LABEL_INCLUDE_ROBOTS;
                                                    if ($detect_robots) {
                                                        echo '<span class="badge badge-info autodetect-badge">'.TEXT_LLMS_MANAGER_DETECTED_BADGE.'</span>';
                                                    }
                                                    ?>
                                                </label>
                                            </div>
                                        </div>

                                        <h5><?= TEXT_LLMS_MANAGER_SECTION_SOURCES_HUMAN ?></h5>
                                        <div class="form-group">
                                            <div class="checkbox">
                                                <label><?= zen_draw_checkbox_field('src_home', '1', true) . TEXT_LLMS_MANAGER_LABEL_HOMEPAGE ?></label>
                                            </div>
                                            <div class="checkbox">
                                                <label><?= zen_draw_checkbox_field('src_contact', '1', true) . TEXT_LLMS_MANAGER_LABEL_CONTACT ?></label>
                                            </div>

                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_ABOUT ?></label>
                                            <?= zen_draw_input_field('src_about_url', (defined('FILENAME_ABOUT_US') ? zen_catalog_href_link(FILENAME_ABOUT_US) : ''), 'class="form-control" placeholder="e.g. index.php?main_page=about_us"') ?>

                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_FAQ ?></label>
                                            <?= zen_draw_input_field('src_faq_url', '', 'class="form-control" placeholder="'.TEXT_LLMS_MANAGER_LABEL_FAQ_PLACEHOLDER.'"') ?>

                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_BLOG ?></label>
                                            <?= zen_draw_input_field('src_blog_url', '', 'class="form-control" placeholder="'.TEXT_LLMS_MANAGER_LABEL_BLOG_PLACEHOLDER.'"') ?>
                                        </div>
                                    </div>
                                    <hr>

                                    <div class="llm-section section-priorities">
                                        <h4><?= TEXT_LLMS_MANAGER_SECTION_PRIORITIES ?></h4>

                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_PRIORITY_CATEGORIES ?></label>
                                                <?= zen_draw_pull_down_menu('categories[]', $cat_array, '', 'multiple class="form-control" size="8"') ?>
                                                <p class="help-block llm-note"><?= TEXT_LLMS_MANAGER_NOTE_CTRL_CLICK ?></p>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_PRIORITY_BRANDS ?></label>
                                                <?= zen_draw_pull_down_menu('manufacturers[]', $man_array, '', 'multiple class="form-control" size="8"') ?>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_PRODUCT_IDS ?></label>
                                            <?= zen_draw_input_field('product_ids', '', 'class="form-control" placeholder="101, 155, 202"') ?>
                                        </div>
                                    </div>
                                    <hr>

                                    <div class="llm-section">
                                        <h4><?= TEXT_LLMS_MANAGER_SECTION_POLICIES ?></h4>
                                        <div class="form-group">
                                            <div class="checkbox">
                                                <label><?= zen_draw_checkbox_field('include_shipping', '1', true) . TEXT_LLMS_MANAGER_LABEL_SHIPPING ?></label>
                                            </div>
                                            <div class="checkbox">
                                                <label><?= zen_draw_checkbox_field('include_privacy', '1', true) . TEXT_LLMS_MANAGER_LABEL_PRIVACY ?></label>
                                            </div>
                                            <div class="checkbox">
                                                <label><?= zen_draw_checkbox_field('include_conditions', '1', true) . TEXT_LLMS_MANAGER_LABEL_CONDITIONS ?></label>
                                            </div>
                                        </div>
                                    </div>
                                    <hr>

                                    <div class="llm-section section-deemphasis">
                                        <h4><?= TEXT_LLMS_MANAGER_SECTION_DEEMPHASIS ?></h4>
                                        <p><?= TEXT_LLMS_MANAGER_NOTE_DEEMPHASISE ?></p>

                                        <div class="form-group">
                                            <?php foreach($default_deemphasis as $key => $text) {
                                                echo '<div class="checkbox"><label>' . zen_draw_checkbox_field('de_options[]', $key) . ' ' . $text . '</label></div>';
                                            } ?>

                                            <label class="llm-label"><?= TEXT_LLMS_MANAGER_LABEL_CUSTOM_DEEMPHASIS ?></label>
                                            <?= zen_draw_textarea_field('custom_deemphasis', 'soft', '100%', '3', '', 'class="form-control" placeholder="'.TEXT_LLMS_MANAGER_LABEL_CUSTOM_DEEMPHASIS_PLACEHOLDER.'"') ?>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-success btn-lg btn-block mt-20"><?= TEXT_LLMS_MANAGER_BUTTON_GENERATE ?></button>
                                </div>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- TAB 8: Recommended Plugins -->
                <div role="tabpanel" class="tab-pane" id="recommended">
                    <div class="row">
                        <div class="col-md-8 col-md-offset-2">
                            <h3 ><?= TITLE_RECOMMENDED_PLUGINS ?></h3>
                            <p class="mb-20"><?= TEXT_RECOMMENDED_INTRO ?></p>

                            <!-- Structured Data -->
                            <div class="panel panel-info">
                                <div class="panel-heading">
                                    <h3 class="panel-title">
                                        <i class="fa fa-code"></i> <?= TITLE_RECOMMENDED_STRUCTURED_DATA ?>
                                        <?php if ($sd_installed) { ?>
                                            <span class="label label-success pull-right"><i class="fa fa-check"></i> <?= TEXT_RECOMMENDED_INSTALLED ?></span>
                                        <?php } ?>
                                    </h3>
                                </div>
                                <div class="panel-body">
                                    <p><strong><?= TEXT_RECOMMENDED_STRUCTURED_DATA ?></strong></p>
                                    <p><?= TEXT_RECOMMENDED_STRUCTURED_DATA_INTRO ?></p>
                                    <a href="https://github.com/torvista/Zen_Cart-Structured_Data" target="_blank" class="btn btn-default btn-sm">
                                        <?= TEXT_RECOMMENDED_VIEW_GITHUB ?> <i class="fa fa-github"></i>
                                    </a>
                                </div>
                            </div>

                            <!-- SEO Friendly URLs -->
                            <div class="panel panel-info">
                                <div class="panel-heading">
                                    <h3 class="panel-title">
                                        <i class="fa fa-link"></i> <?= TITLE_RECOMMENDED_SEF_URLS ?>
                                        <?php if ($usu_installed || $ceon_installed) { ?>
                                            <span class="label label-success pull-right"><i class="fa fa-check"></i> <?= TEXT_RECOMMENDED_INSTALLED ?></span>
                                        <?php } ?>
                                    </h3>
                                </div>
                                <div class="panel-body">
                                    <p><?= TEXT_RECOMMENDED_SEF_URLS_INTRO ?></p>

                                    <hr>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <p>
                                                <strong><?= TITLE_RECOMMENDED_USU ?></strong>
                                                <?php if ($usu_installed) { ?>
                                                    <span class="label label-success"><i class="fa fa-check"></i> <?= TEXT_RECOMMENDED_INSTALLED ?></span>
                                                <?php } ?>
                                            </p>
                                            <p class="mb-10"><?= TEXT_RECOMMENDED_USU ?></p>
                                            <div class="btn-group" role="group">
                                                <a href="https://www.zen-cart.com/downloads.php?do=file&id=2334" target="_blank" class="btn btn-default btn-sm">
                                                    <i class="fa fa-download"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_PLUGINS ?>
                                                </a>
                                                <a href="https://github.com/lat9/usu" target="_blank" class="btn btn-default btn-sm">
                                                    <i class="fa fa-github"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_GITHUB_LAT9 ?>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="col-md-6 ceon-recommended">
                                            <p>
                                                <strong><?= TITLE_RECOMMENDED_CEON_URI ?></strong>
                                                <?php if ($ceon_installed) { ?>
                                                    <span class="label label-success"><i class="fa fa-check"></i> <?= TEXT_RECOMMENDED_INSTALLED ?></span>
                                                <?php } ?>
                                            </p>
                                            <p class="mb-10"><?= TEXT_RECOMMENDED_CEON_URI_INTRO ?></p>
                                            <div class="btn-group" role="group">
                                                <a href="https://www.zen-cart.com/downloads.php?do=file&id=1013" target="_blank" class="btn btn-default btn-sm">
                                                    <i class="fa fa-download"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_PLUGINS ?>
                                                </a>
                                                <a href="https://github.com/torvista/Zen-Cart_CEON-URI-Mapping" target="_blank" class="btn btn-default btn-sm">
                                                    <i class="fa fa-github"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_GITHUB_TORVISTA ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Google Analytics -->
                            <div class="panel panel-info">
                                <div class="panel-heading">
                                    <h3 class="panel-title">
                                        <i class="fa fa-plug"></i> <?= TITLE_RECOMMENDED_GA4 ?>
                                        <?php if ($ga4_installed) { ?>
                                            <span class="label label-success pull-right"><i class="fa fa-check"></i> <?= TEXT_RECOMMENDED_INSTALLED ?></span>
                                        <?php } ?>
                                    </h3>
                                </div>
                                <div class="panel-body">
                                    <p><strong><?= TEXT_RECOMMENDED_GA4 ?></strong></p>
                                    <p><?= TEXT_RECOMMENDED_GA4_INTRO ?></p>
                                    <div class="btn-group" role="group">
                                        <a href="https://www.zen-cart.com/downloads.php?do=file&id=2368" target="_blank" class="btn btn-default btn-sm">
                                            <i class="fa fa-download"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_PLUGINS ?>
                                        </a>
                                        <a href="https://github.com/lat9/ga4-analytics" target="_blank" class="btn btn-default btn-sm">
                                            <i class="fa fa-github"></i> <?= TEXT_RECOMMENDED_DOWNLOAD_GITHUB_LAT9 ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- TAB 9: Services -->
                <div role="tabpanel" class="tab-pane" id="services">
                    <div class="row">
                        <div class="col-md-8 col-md-offset-2">

                            <div class="panel panel-primary">
                                <div class="panel-heading">
                                    <h3 class="panel-title"><i class="fa fa-briefcase"></i> Professional Technical SEO Audit</h3>
                                </div>
                                <div class="panel-body">
                                    <h3>Maximize Your Store's Technical Foundation</h3>

                                    <p>
                                        ZX SEO Master provides you with all the administrative tools you need to optimize your content. With time and research, you can absolutely execute a highly effective content strategy on your own.
                                    </p>

                                    <p>
                                        However, great content cannot rank if search engines struggle to crawl your code. As a Zen Cart developer, I offer a comprehensive, manual technical site audit to identify and resolve the underlying structural bottlenecks unique to the Zen Cart platform.
                                    </p>

                                    <div class="well">
                                        <h4>What the Technical Audit Includes:</h4>
                                        <ul>
                                            <li><strong>Crawlability & Architecture:</strong> Resolving Zen Cart-specific duplicate content loops (e.g., dynamic <code>cPath</code> and <code>zenid</code> parameter indexing).</li>
                                            <li><strong>Performance Profiling:</strong> Core Web Vitals analysis, server-side response times, and theme/template structural reviews.</li>
                                            <li><strong>Code Validation:</strong> Auditing semantic HTML, Open Graph tags, and Schema.org (JSON-LD) structured data implementation.</li>
                                            <li><strong>Actionable Reporting:</strong> A prioritized, developer-friendly PDF report detailing exactly what code needs to be fixed and how to fix it.</li>
                                        </ul>
                                    </div>

                                    <div class="text-center mt-20">
                                        <a href="https://zenexpert.com/shop/seo-audit" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-lg">
                                            Order a Technical SEO Report <i class="fa fa-arrow-right"></i>
                                        </a>
                                        <p class="text-muted small mt-15">Opens securely in a new window.</p>
                                    </div>

                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- LLMs Custom Flyout Output -->
                <div class="llm-backdrop" id="llmBackdrop" onclick="toggleLlmPanel()"></div>

                <div class="llm-flyout" id="llmFlyout">
                    <div class="llm-flyout-header">
                        <h4><?= TEXT_LLMS_MANAGER_CURRENT_FILE ?> llms.txt</h4>
                        <button type="button" class="close" aria-label="Close" onclick="toggleLlmPanel()">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="flyout-buttons">
                        <?php if ($llms_exists) { ?>
                            <button type="button" class="btn btn-sm btn-success" onclick="copyLlmsContent()">
                                <i class="fa fa-copy"></i> <?= TEXT_LLMS_MANAGER_COPY_TO_CLIPBOARD ?>
                            </button>
                            <span id="copy-feedback">
                                <i class="fa fa-check"></i> <?= TEXT_LLMS_MANAGER_COPIED_SUCCESS ?>
                            </span>

                            <a href="<?= HTTP_CATALOG_SERVER . DIR_WS_CATALOG . 'llms.txt' ?>" target="_blank" class="btn btn-sm btn-info text-white pull-right">
                                <i class="fa fa-external-link"></i> <?= TEXT_LLMS_MANAGER_OPEN_FILE ?>
                            </a>
                            <div class="text-muted small"><?= TEXT_LLMS_MANAGER_FILE_UPDATED_DATE . $llms_mtime ?></div>
                        <?php } else { ?>
                            <span class="label label-warning"><?= TEXT_LLMS_MANAGER_FILE_NOT_FOUND ?></span>
                        <?php } ?>
                    </div>

                    <div class="llm-flyout-body">
                        <textarea id="llmsPreview" class="form-control llm-textarea" rows="20" readonly><?= zen_output_string_protected($current_llms_content); ?></textarea>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
