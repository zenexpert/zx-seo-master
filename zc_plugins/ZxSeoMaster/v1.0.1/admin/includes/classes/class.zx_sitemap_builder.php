<?php
declare(strict_types=1);

class ZxSitemapBuilder
{
    private string $savePath;
    private string $baseUrl;
    private int $maxEntries = 50000;
    private int $currentEntries = 0;
    private int $fileCounter = 1;
    private $filePointer;
    private string $currentPrefix = 'sitemap';
    private array $generatedFiles = []; // Keeps track of all created files for the index

    public function __construct()
    {
        $this->savePath = DIR_FS_CATALOG;
        $this->baseUrl = HTTP_SERVER . DIR_WS_CATALOG;
    }

    public function build(): void
    {
        $this->setupTempDuplicateTable();

        // Generate each type in its own file
        $this->startNewSection('sitemap_static');
        $this->generateStaticPages();
        $this->closeCurrentFile();

        $this->startNewSection('sitemap_categories');
        $this->generateCategories();
        $this->closeCurrentFile();

        $this->startNewSection('sitemap_products');
        $this->generateProducts();
        $this->closeCurrentFile();

        $this->startNewSection('sitemap_ezpages');
        $this->generateEzPages();
        $this->closeCurrentFile();

        $this->buildSitemapIndex();
    }

    private function startNewSection(string $prefix): void
    {
        $this->currentPrefix = $prefix;
        $this->fileCounter = 1;
        $this->currentEntries = 0;
        $this->openNewFile();
    }

    private function setupTempDuplicateTable(): void
    {
        global $db;
        $db->Execute("DROP TABLE IF EXISTS " . DB_PREFIX . "zx_sitemap_temp");

        $sql = "CREATE TABLE " . DB_PREFIX . "zx_sitemap_temp (
                    url_hash CHAR(32) NOT NULL,
                    PRIMARY KEY (url_hash)
                ) ENGINE = MEMORY;";
        $db->Execute($sql);
    }

    private function isDuplicate(string $url): bool
    {
        global $db;
        $hash = md5($url);

        $sql = "SELECT SQL_NO_CACHE 1 FROM " . DB_PREFIX . "zx_sitemap_temp WHERE url_hash = :hash LIMIT 1";
        $sql = $db->bindVars($sql, ':hash', $hash, 'string');
        $check = $db->Execute($sql);

        if ($check->RecordCount() > 0) {
            return true;
        }

        $insertSql = "INSERT INTO " . DB_PREFIX . "zx_sitemap_temp (url_hash) VALUES (:hash)";
        $insertSql = $db->bindVars($insertSql, ':hash', $hash, 'string');
        $db->Execute($insertSql);

        return false;
    }

    private function openNewFile(): void
    {
        // Add logic to omit the counter if it's the first file, for cleaner names like sitemap_categories.xml
        $suffix = ($this->fileCounter === 1) ? '' : '_' . $this->fileCounter;
        $filename = $this->currentPrefix . $suffix . '.xml';

        $fullPath = $this->savePath . $filename;
        $this->filePointer = fopen($fullPath, 'w');

        // Store the filename for the index builder
        $this->generatedFiles[] = $filename;

        $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $header .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        fwrite($this->filePointer, $header);
    }

    private function writeItem(string $loc, string $lastMod, array $images = []): void
    {
        if ($this->isDuplicate($loc)) {
            return;
        }

        if ($this->currentEntries >= $this->maxEntries) {
            $this->closeCurrentFile();
            $this->fileCounter++;
            $this->currentEntries = 0;
            $this->openNewFile();
        }

        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>" . $lastMod . "</lastmod>\n";

        if (!empty($images)) {
            foreach ($images as $img) {
                $xml .= "    <image:image>\n";
                $xml .= "      <image:loc>" . htmlspecialchars($img['url'], ENT_XML1, 'UTF-8') . "</image:loc>\n";
                if (!empty($img['title'])) {
                    $xml .= "      <image:title>" . htmlspecialchars($img['title'], ENT_XML1, 'UTF-8') . "</image:title>\n";
                }
                $xml .= "    </image:image>\n";
            }
        }

        $xml .= "  </url>\n";

        fwrite($this->filePointer, $xml);
        $this->currentEntries++;
    }

    private function closeCurrentFile(): void
    {
        if (is_resource($this->filePointer)) {
            // Only write the closing tag if we actually added URLs to this specific file,
            // otherwise, we can delete the empty file so it doesn't clutter the index.
            if ($this->currentEntries > 0) {
                fwrite($this->filePointer, "</urlset>\n");
                fclose($this->filePointer);
            } else {
                // Remove the empty file and take it out of the index array
                fclose($this->filePointer);
                $emptyFile = array_pop($this->generatedFiles);
                @unlink($this->savePath . $emptyFile);
            }
        }
    }

    /**
     * Builds the master sitemapindex.xml file and creates a sitemap.xml fallback.
     */
    private function buildSitemapIndex(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($this->generatedFiles as $filename) {
            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . $this->baseUrl . $filename . "</loc>\n";
            $xml .= "    <lastmod>" . gmdate('Y-m-d\TH:i:sP') . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>' . "\n";

        // Write both files using explicit file handles
        $targetFiles = ['sitemapindex.xml', 'sitemap.xml'];

        foreach ($targetFiles as $target) {
            $filePath = $this->savePath . $target;
            $handle = @fopen($filePath, 'w');

            if (is_resource($handle)) {
                fwrite($handle, $xml);
                fclose($handle);
            }
        }
    }

    private function formatDate(?string $dateString): string
    {
        if (empty($dateString)) {
            return gmdate('Y-m-d\TH:i:sP');
        }
        $date = new DateTime($dateString);
        return $date->format('Y-m-d\TH:i:sP');
    }

    private function getActiveLanguages(): array
    {
        return zen_get_languages();
    }

    private function generateCategories(): void
    {
        global $db;
        $languages = $this->getActiveLanguages();
        $isMultiLingual = count($languages) > 1;

        foreach ($languages as $lang) {
            $langId = (int)$lang['id'];
            $langParam = $isMultiLingual ? '&language=' . $lang['code'] : '';

            $sql = "SELECT c.categories_id, c.categories_image, c.last_modified, c.date_added,
                           cd.categories_name, seo.is_noindex
                    FROM " . TABLE_CATEGORIES . " c
                    LEFT JOIN " . TABLE_CATEGORIES_DESCRIPTION . " cd
                        ON (c.categories_id = cd.categories_id AND cd.language_id = :langId)
                    LEFT JOIN " . TABLE_ZX_SEO_METADATA . " seo
                        ON (seo.entity_id = c.categories_id AND seo.entity_type = 'category')
                    WHERE c.categories_status = 1";

            $sql = $db->bindVars($sql, ':langId', $langId, 'integer');
            $categories = $db->Execute($sql);

            foreach ($categories as $category) {
                if ((int)$category['is_noindex'] === 1) {
                    continue;
                }

                $cPath = zen_get_generated_category_path_rev($category['categories_id']);
                $lastMod = $this->formatDate($category['last_modified'] ?? $category['date_added']);
                $url = zen_href_link(FILENAME_DEFAULT, 'cPath=' . $cPath . $langParam, 'NONSSL', false);

                $images = [];
                if (!empty($category['categories_image'])) {
                    $images[] = [
                        'url' => $this->baseUrl . DIR_WS_IMAGES . $category['categories_image'],
                        'title' => $category['categories_name'] ?? ''
                    ];
                }

                $this->writeItem($url, $lastMod, $images);
            }
        }
    }

    private function generateProducts(): void
    {
        global $db;
        $languages = $this->getActiveLanguages();
        $isMultiLingual = count($languages) > 1;

        foreach ($languages as $lang) {
            $langId = (int)$lang['id'];
            $langParam = $isMultiLingual ? '&language=' . $lang['code'] : '';

            $sql = "SELECT p.products_id, p.products_image, p.products_last_modified, p.products_date_added,
                           pd.products_name, seo.is_noindex
                    FROM " . TABLE_PRODUCTS . " p
                    LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
                        ON (p.products_id = pd.products_id AND pd.language_id = :langId)
                    LEFT JOIN " . TABLE_ZX_SEO_METADATA . " seo
                        ON (seo.entity_id = p.products_id AND seo.entity_type = 'product')
                    WHERE p.products_status = 1";

            $sql = $db->bindVars($sql, ':langId', $langId, 'integer');
            $products = $db->Execute($sql);

            foreach ($products as $product) {
                if ((int)$product['is_noindex'] === 1) {
                    continue;
                }

                $lastMod = $this->formatDate($product['products_last_modified'] ?? $product['products_date_added']);
                $infoPage = zen_get_info_page($product['products_id']);
                $url = zen_href_link($infoPage, 'products_id=' . $product['products_id'] . $langParam, 'NONSSL', false);

                $images = [];
                if (!empty($product['products_image'])) {
                    $images[] = [
                        'url' => $this->baseUrl . DIR_WS_IMAGES . $product['products_image'],
                        'title' => $product['products_name'] ?? ''
                    ];
                }

                $this->writeItem($url, $lastMod, $images);
            }
        }
    }

    private function generateEzPages(): void
    {
        global $db;
        $languages = $this->getActiveLanguages();
        $isMultiLingual = count($languages) > 1;

        $sql = "SELECT e.pages_id, e.alt_url, e.alt_url_external, seo.is_noindex
                FROM " . TABLE_EZPAGES . " e
                LEFT JOIN " . TABLE_ZX_SEO_METADATA . " seo
                    ON (seo.entity_id = e.pages_id AND seo.entity_type = 'ezpage')
                WHERE (e.status_header = 1 OR e.status_sidebox = 1 OR e.status_footer = 1 OR e.status_toc = 1)";

        $ezpages = $db->Execute($sql);

        foreach ($ezpages as $ezpage) {
            if ((int)$ezpage['is_noindex'] === 1) {
                continue;
            }

            if (!empty($ezpage['alt_url_external'])) {
                continue;
            }

            foreach ($languages as $lang) {
                $langParam = $isMultiLingual ? '&language=' . $lang['code'] : '';

                if (!empty($ezpage['alt_url'])) {
                    if (strpos($ezpage['alt_url'], 'http') === 0) {
                        $url = $ezpage['alt_url'];
                    } else {
                        $url = zen_href_link($ezpage['alt_url'], ltrim($langParam, '&'), 'NONSSL', false);
                    }
                } else {
                    $url = zen_href_link(FILENAME_EZPAGES, 'id=' . $ezpage['pages_id'] . $langParam, 'NONSSL', false);
                }

                $this->writeItem($url, $this->formatDate(null));
            }
        }
    }

    private function generateStaticPages(): void
    {
        $languages = $this->getActiveLanguages();
        $isMultiLingual = count($languages) > 1;

        $staticPages = [
            FILENAME_DEFAULT,
            FILENAME_CONTACT_US,
            FILENAME_CONDITIONS,
            FILENAME_PRIVACY,
            FILENAME_SHIPPING,
            FILENAME_SITE_MAP
        ];

        foreach ($languages as $lang) {
            $langParam = $isMultiLingual ? 'language=' . $lang['code'] : '';

            foreach ($staticPages as $page) {
                if (defined($page)) {
                    $url = zen_href_link(constant($page), $langParam, 'NONSSL', false);
                    $this->writeItem($url, $this->formatDate(null));
                }
            }
        }
    }
}
