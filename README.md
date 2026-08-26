# ZX SEO Master

**Platform:** Zen Cart 2.1.0 or newer

**Architecture:** Encapsulated Plugin, Observer-Based

> **Disclaimer:** Please note that this plugin is installed and used at your own risk. If you are unsure of how to complete the installation procedure documented below, please seek support in the official Forum section (keep in mind Forums are public and work on voluntary basis) or contact me for paid assistance. ZenExpert takes no responsibility for problems related to the installation or use of this plugin.

ZX SEO Master is a comprehensive, enterprise-grade technical on-site SEO auditing and configuration suite built exclusively for modern Zen Cart environments. Engineered with strict encapsulation, it centralizes all metadata, content analysis, redirects, and automated indexing into a single, seamless dashboard.

## Table of Contents
* [Installation](#installation)
* [Features & Usage](#features--usage)
  * [Global Settings & IndexNow](#global-settings--indexnow)
  * [Metadata Editor & Dynamic Tags](#metadata-editor--dynamic-tags)
  * [Dynamic Plugin Integrations](#dynamic-plugin-integrations)
  * [Meta Audit](#meta-audit)
  * [Content Analysis](#content-analysis)
  * [Real-Time Product SEO Analyzer](#real-time-product-seo-analyzer-sidebox)
  * [301 Redirects](#301-redirects)
  * [Site Tools & Migrations](#site-tools--migrations)
  * [LLMS.txt Generator](#llmstxt-generator)
* [Common Issues](#common-issues)
* [Sources and Credits](#sources-and-credits)
* [Support](#support)
* [License](#license)


## Installation

ZX SEO Master is built as a strict **Encapsulated Plugin** for Zen Cart 2.1.0 or newer. It utilizes reference-based observers and a self-contained directory structure, guaranteeing absolutely zero core file modifications.

1. Backup your database.
2. Upload the `zx_seo_master` folder to your store's `zc_plugins` directory.
3. Navigate to your Zen Cart Admin and go to **Plugins > Plugin Manager**.
4. Locate ZX SEO Master and click **Install**. The necessary database tables (`zx_seo_metadata`, `zx_seo_redirects`, `zx_seo_master_globals`) and configuration keys will automatically generate.
5. Access the dashboard via **Tools > ZX SEO Master**.


## Features & Usage

### Global Settings & IndexNow
Manage your storefront's overarching SEO parameters:
* **Store Formatting:** Define global title tags, taglines, and separator formatting strings (e.g., `Title - Tagline`) with full multi-language support.
* **Homepage Overrides:** Set strict meta titles and descriptions explicitly for your catalog index.
* **Global Code Injection:** Safely inject verification tags (like Google Site Verification) into the document `<head>` and tracking scripts into the global footer.
* **IndexNow API:** Automatically ping major search engines (Bing, Yandex, Seznam) when content changes. The system automatically generates and manages your verification `.txt` key file at the catalog root.

### Metadata Editor & Dynamic Tags
A centralized hub to override native data. Data saved here acts as the supreme authority on your storefront, overriding default database tables.
* **Dynamic Tag Injection:** Use quick-insert buttons to inject variables like `%PRODUCT_NAME%`, `%PRODUCT_PRICE%`, and `%SITE_TAGLINE%` directly into your titles and descriptions.
* **Live Snippet Preview:** As you type or insert tags, a Google search snippet preview instantly renders the *parsed* data (e.g., swapping out `%PRODUCT_NAME%` for the actual product name) so you can see exactly how it will appear in search results.
* **Smart Character Counting:** The real-time character counter dynamically evaluates the length of the parsed output, not the raw tag text, giving you perfectly accurate length warnings.
* **AJAX Entity Search:** Rapidly search for Products, Categories, EZ-Pages, and Manufacturers directly from the dashboard.
* **Multi-Lingual Editing:** Manage Titles, Meta Descriptions, and Focus Keywords seamlessly across all installed languages.
* **Advanced Directives:** Set Custom Canonical URLs, and apply explicit `noindex` and `nofollow` robots directives on a per-page basis.
* **Automatic Routing:** Clicking the native Zen Cart "Edit Meta Tags" button on any product or category listing will automatically intercept the request and route you directly to this centralized editor.

### Dynamic Plugin Integrations
ZX SEO Master actively detects popular third-party modules and pulls their configuration settings directly into the master dashboard, preventing you from hunting through native menus. Supported integrations include:
* **Ultimate SEO URLs (USU)**
* **Ceon URI Mapping** (Provides a direct dashboard link)
* **Torvista's Structured Data (JSON-LD)**
* **lat9's GA4 Analytics**

### Meta Audit
Run a real-time database scan to identify active products and categories missing crucial Meta Descriptions. Click **Fix Now** on any flagged entity to instantly load it into the Metadata Editor for correction.

### Content Analysis
Test your content against modern SEO parameters using a specific Focus Keyword directly from the dashboard. The analyzer cross-references your custom `zx_seo_metadata` tables and native tables to score:
* Title and Description optimal character lengths.
* Exact keyword matches within metadata.
* Content keyword density.

### Real-Time Product SEO Analyzer (Sidebox)
In addition to the central dashboard, ZX SEO Master injects a dynamic, slide-out SEO analyzer directly into your standard Zen Cart product editing pages.
* **Live Google Snippet Preview:** See exactly how your product will appear in search results as you type.
* **Real-Time Content Scoring:** Instantly evaluates your product description for word count, readability, and sentence length. Fully supports and hooks into CKEditor if installed.
* **Keyword Density Tracking:** Enter a focus keyword to immediately see its density within your content and meta tags. Automatically pulls from your saved `zx_seo_metadata` authority tables if a focus keyword was previously assigned.
* **Link Auditing:** Automatically detects and tallies internal links versus external outbound links within your description HTML.

### 301 Redirects
A robust URL redirection manager that intercepts broken or outdated links and passes link equity to new destinations.
* Safely strips absolute paths down to relative paths to prevent routing errors.
* Includes **Collision Detection**: Warns the administrator and requires explicit overwrite confirmation if a source URL is already mapped in the database.
* Includes pagination and a search filter for managing large redirect tables.

### Site Tools & Migrations
* **XML Sitemap Generator:** 1-click generation of `sitemapindex.xml`. Includes a quick-copy button to append the sitemap declaration to your robots file.
* **Robots.txt Editor:** Read, edit, and save your `robots.txt` file directly from the admin panel.
* **Data Migration Tool:** A one-way, non-destructive import routine. Automatically pulls legacy native Zen Cart product and category meta tags into the master `zx_seo_metadata` tables so all SEO data exists in one place.

### LLMS.txt Generator
Ensure Large Language Models (like ChatGPT, Claude, and Gemini) properly index and cite your store's information. ZX SEO Master includes a dedicated interface to construct an `llms.txt` file.
* Define positive guidance rules for AI crawlers.
* Highlight priority pages, specific category paths, and brand endpoints.
* Instruct AI to de-emphasize low-value pages (like search queries and thin content).


## Common Issues
None reported so far.


## Sources and Credits
* External libraries used in this plugin: none


## Contributing
Found a bug? Feel free to submit an issue or pull request.


## License
GNU Public License V2.0
