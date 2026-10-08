=== WPCalibrate Searchable PDF Library ===
Contributors: wpcalibrate
Donate link: https://wpcalibrate.com/
Tags: pdf, searchable pdf, document library, pdf search, document management
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast full-text searchable PDF document library for WordPress. Extract and search PDF text locally with access controls, filters, and embedded viewer.

== Description ==

WPCalibrate Searchable PDF Library transforms your PDF files into a fast, searchable document library. Unlike basic media tools that only search WordPress post titles or file names, this plugin extracts and indexes full-text body content from text-based PDFs directly on your WordPress server.

When a visitor searches for a term or phrase found only deep within a PDF page, the document is discovered and displayed with an extracted text snippet, safe keyword highlighting, and deep-linking to the specific page number.

### Core Features

* **Full-Text PDF Content Search:** Discovers documents even when the search phrase is absent from title, filename, or categories.
* **Local Server Text Extraction:** Uses a high-performance local parser (`prinsfrank/pdfparser`) without transmitting your documents to external third-party services.
* **Background Queue Processing:** Extracts and indexes PDFs in asynchronous batches via Action Scheduler (if available) or WordPress WP-Cron, protecting server resources.
* **Safe Match Highlighting & Snippets:** Presents contextual snippets with safely escaped search term highlights.
* **Page-Level Deep Linking:** Tracks detected page numbers and appends `#page=N` for supported PDF viewers.
* **Granular Access Control:** Restrict individual documents to Public, Logged-in Users, or Selected User Roles.
* **Protected File Delivery:** Restricts direct file access via Apache `.htaccess` and IIS `web.config` rules, streaming protected files through an authorized WordPress endpoint.
* **Responsive Library Display:** Built-in Grid and List layouts with live debounced search, category and tag filters, sort controls, and pagination.
* **Embedded PDF Viewer:** Responsive native browser PDF preview modal with fallback links.
* **Shortcode & Gutenberg Block:** Publish libraries anywhere using `[wpcalibrate_pdf_library]` or the `wpcalibrate/searchable-pdf-library` block.
* **Media Library Scanner:** Discover and bulk import existing PDF attachments from your Media Library.
* **Custom Metadata Fields:** Define searchable and filterable custom fields (text, number, date, select, checkbox).
* **Scanned PDF Detection:** Identifies image-only or low-text PDFs and alerts administrators that OCR is required.
* **Zero WooCommerce Dependency:** Operates independently as a standalone document library.

== Installation ==

1. Upload the `wpcalibrate-searchable-pdf-library` folder to the `/wp-content/plugins/` directory, or install the ZIP file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Access **WPCalibrate > Searchable PDF Library** in your WordPress admin menu.
4. Add new documents or navigate to **Tools** to scan and import existing PDFs from your Media Library.
5. Embed your document library on any page using the Gutenberg Block **Searchable PDF Library** or the shortcode `[wpcalibrate_pdf_library]`.

== Server Requirements ==

* **PHP:** Version 8.2 or 8.3 minimum.
* **PHP Extensions:** `gd`, `iconv`, and `zlib` (standard in almost all hosting environments).
* **MySQL / MariaDB:** MySQL 5.6+ or MariaDB 10.0+ for FULLTEXT search indexing (a degraded fallback mode is provided if FULLTEXT is unavailable).
* **WordPress:** Version 6.5 or higher (tested up to 7.1.3).

== Shortcode Usage ==

Use the `[wpcalibrate_pdf_library]` shortcode to output your document library.

Supported attributes:
* `category`: Filter by category slug (e.g. `category="annual-reports"`).
* `tag`: Filter by tag slug (e.g. `tag="finance"`).
* `layout`: Default view layout, either `grid` or `list` (e.g. `layout="list"`).
* `per_page`: Number of documents per page (e.g. `per_page="12"`).
* `show_search`: Show or hide search box (`yes` or `no`).
* `show_filters`: Show or hide category, tag, and sort filters (`yes` or `no`).

Example:
`[wpcalibrate_pdf_library layout="grid" per_page="12" show_filters="yes"]`

== Gutenberg Block ==

Search for **Searchable PDF Library** in the block inserter. The block offers live inspector controls in the sidebar to configure layout, pagination, search visibility, and filter controls.

== Scanned / Image-Only PDFs Limitation ==

Version 1.0.0 extracts text from standard text-based PDF documents. Image-only or scanned PDFs (such as flat scanner outputs without OCR text layers) contain little or no machine-readable text. When detected, the plugin marks the document with:
*"Little or no searchable text detected. This PDF may require OCR."*
Optical Character Recognition (OCR) is outside the scope of Version 1.0.0 and will be introduced in a future release.

== Protected Files & Web Server Notes ==

WordPress Media Library attachments are placed in `wp-content/uploads/` with public URLs. To prevent unauthorized direct downloads of role-restricted PDFs, the plugin uses a dedicated protected directory with Apache `.htaccess` and IIS `web.config` deny rules.

*On Nginx servers*, `.htaccess` files are not evaluated automatically. Administrators using Nginx must ensure direct static requests to `/wp-content/uploads/wpcal_spl_protected/` are blocked in their server block configuration.

== Privacy Considerations ==

All PDF text parsing and search indexing occurs locally on your WordPress server. No document content, search queries, or visitor information is transmitted to external cloud providers or AI services.

== Data Retention on Uninstall ==

By default, deactivating or deleting the plugin preserves all managed documents, metadata, and search indexes.
If you wish to completely remove all plugin-created database tables and options upon deletion, navigate to **Settings > Access Control** and enable *"Delete plugin data when uninstalled"*. Original Media Library attachments are never deleted.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
No. WPCalibrate Searchable PDF Library is entirely standalone and does not require WooCommerce.

= What happens if my server does not support MySQL FULLTEXT indexes? =
The plugin automatically detects your database engine. If FULLTEXT indexes cannot be created, it activates a degraded fallback search mode using bounded SQL queries so search remains functional.

= Can I customize metadata fields? =
Yes. Go to **WPCalibrate > Searchable PDF Library > Custom Fields** to add custom fields such as Department, Project Code, or Document Number. You can designate each field as searchable, filterable, or displayed in search results.

= Does live search slow down the server? =
No. Live search requests are debounced by 350ms to prevent sending queries on every keystroke, and queries are paginated and bounded.

== Changelog ==

= 1.0.0 =
* Initial production release.
* Local PDF text extraction via prinsfrank/pdfparser.
* Dedicated custom database table with page chunking and MySQL FULLTEXT indexing.
* Centralized role-based and logged-in document access control.
* Protected streaming file delivery endpoint with server deny rules.
* Responsive frontend library with debounced live search, Grid/List layouts, and accessible viewer modal.
* Shortcode `[wpcalibrate_pdf_library]` and server-rendered Gutenberg block.
* Media Library PDF batch scanner and bulk importer.
* Custom metadata fields builder (text, number, date, select, checkbox).
* Diagnostics dashboard, maintenance tools, and WPCalibrate admin integration.
