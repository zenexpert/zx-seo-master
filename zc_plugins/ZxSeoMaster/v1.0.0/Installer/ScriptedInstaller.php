<?php

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    /**
     * Executes when the plugin is installed or updated.
     */
    protected function executeInstall()
    {
        define('TABLE_ZX_SEO_METADATA', DB_PREFIX . 'zx_seo_metadata');
        define('TABLE_ZX_SEO_REDIRECTS', DB_PREFIX . 'zx_seo_redirects');
        define('TABLE_ZX_SEO_MASTER_GLOBALS', DB_PREFIX . 'zx_seo_master_globals');

        // Create the centralized SEO metadata table
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_ZX_SEO_METADATA . " (
                  id int(11) NOT NULL AUTO_INCREMENT,
                  entity_type varchar(50) NOT NULL,
                  entity_id int(11) NOT NULL,
                  language_id int(11) NOT NULL DEFAULT 1,
                  meta_title varchar(255) NOT NULL,
                  meta_description text NOT NULL,
                  focus_keyword varchar(255) NOT NULL,
                  custom_canonical varchar(255) DEFAULT NULL,
                  is_noindex tinyint(1) NOT NULL DEFAULT '0',
                  is_nofollow tinyint(1) NOT NULL DEFAULT '0',
                  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY idx_entity_lang (entity_type, entity_id, language_id)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
        $this->executeInstallerSql($sql);

        // Create the SEO redirects table
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_ZX_SEO_REDIRECTS . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            source_url varchar(191) NOT NULL,
            target_url varchar(255) NOT NULL,
            date_added datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_source_url (source_url)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
        $this->executeInstallerSql($sql);

        // Create the global SEO table
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_ZX_SEO_MASTER_GLOBALS . " (
                  config_key varchar(100) NOT NULL,
                  language_id int(11) NOT NULL DEFAULT 1,
                  config_value text NOT NULL,
                  PRIMARY KEY (config_key, language_id)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;";
        $this->executeInstallerSql($sql);

        // Insert the Default Keys
        $languages = zen_get_languages();
        foreach ($languages as $lang) {
            $langId = (int)$lang['id'];
            $sql = "INSERT IGNORE INTO " . TABLE_ZX_SEO_MASTER_GLOBALS . " (config_key, language_id, config_value) VALUES
                    ('TITLE', $langId, ''),
                    ('SITE_TAGLINE', $langId, ''),
                    ('CUSTOM_KEYWORDS', $langId, ''),
                    ('HOME_PAGE_TITLE', $langId, ''),
                    ('HOME_PAGE_META_DESCRIPTION', $langId, ''),
                    ('HOME_PAGE_META_KEYWORDS', $langId, ''),
                    ('META_TAGS_REVIEW', $langId, 'Reviews: '),
                    ('PRIMARY_SECTION', $langId, ' : '),
                    ('SECONDARY_SECTION', $langId, ' - '),
                    ('TERTIARY_SECTION', $langId, ', '),
                    ('METATAGS_DIVIDER', $langId, ' ');";
            $this->executeInstallerSql($sql);
        }

        // Add configuration key for custom meta tags
        $sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('ZX SEO Custom Meta Tags', 'ZX_SEO_MASTER_CUSTOM_META_TAGS', '', 'Global custom meta tags injected into the HTML head.', 6, 1, now()) ON DUPLICATE KEY UPDATE configuration_value=configuration_value";
        $this->executeInstallerSql($sql);

        // Add configuration key for custom footer tags
        $sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('ZX SEO Custom Footer Scripts', 'ZX_SEO_MASTER_CUSTOM_FOOTER_SCRIPTS', '', 'Global custom JavaScript injected before the closing body tag.', 6, 2, now()) ON DUPLICATE KEY UPDATE configuration_value=configuration_value";
        $this->executeInstallerSql($sql);

        // Auto-generate and install IndexNow Settings
        $key = substr(bin2hex(random_bytes(20)), 0, 30);
        $filePath = DIR_FS_CATALOG . $key . '.txt';
        if (!file_exists($filePath)) {
            @file_put_contents($filePath, $key);
        }

        $this->addConfigurationKey('ZX_INDEXNOW_STATUS', [
            'configuration_title' => 'Enable IndexNow Auto-Submission',
            'configuration_value' => 'true',
            'configuration_description' => 'Automatically submit product and category updates to IndexNow.',
            'configuration_group_id' => 6,
            'sort_order' => 10,
            'set_function' => 'zen_cfg_select_option(array(\'true\', \'false\'), ',
        ]);

        $this->addConfigurationKey('ZX_INDEXNOW_ENDPOINT', [
            'configuration_title' => 'IndexNow Endpoint',
            'configuration_value' => 'https://www.bing.com/indexnow',
            'configuration_description' => 'Select the IndexNow endpoint (submitting to one shares with all).',
            'configuration_group_id' => 6,
            'sort_order' => 11,
            'set_function' => 'zen_cfg_select_option(array(\'https://api.indexnow.org/indexnow\', \'https://www.bing.com/indexnow\', \'https://yandex.com/indexnow\', \'https://search.seznam.cz/indexnow\'), ',
        ]);

        $this->addConfigurationKey('ZX_INDEXNOW_KEY', [
            'configuration_title' => 'IndexNow Key',
            'configuration_value' => $key,
            'configuration_description' => 'Auto-generated IndexNow Key. Matching txt file must exist in store root.',
            'configuration_group_id' => 6,
            'sort_order' => 12,
        ]);

        // Deregister pages in case of an update/re-install to prevent duplicates
        zen_deregister_admin_pages([
            'zxSeoMaster',
        ]);

        // Register the admin pages under the "Tools" menu
        zen_register_admin_page('zxSeoMaster', 'BOX_TOOLS_ZX_SEO_MASTER', 'FILENAME_ZX_SEO_MASTER', '', 'tools', 'Y');

        // Complete the installation process
        parent::executeInstall();
    }

    /**
     * Executes when the plugin is removed via the Admin Plugin Manager.
     */
    protected function executeUninstall()
    {
        zen_deregister_admin_pages([
            'zxSeoMaster',
        ]);
        parent::executeUninstall();
    }
}
