# WPCalibrate Searchable PDF Library

[![WordPress Tested](https://img.shields.io/badge/WordPress-6.5%20to%207.1+-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20|%208.3-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![Latest Release](https://img.shields.io/github/v/release/zeeshanraza-official/wpcalibrate-searchable-pdf-library?color=success&logo=github)](https://github.com/zeeshanraza-official/wpcalibrate-searchable-pdf-library/releases)

**WPCalibrate Searchable PDF Library** is an enterprise-grade WordPress plugin that transforms standard PDF uploads into fully searchable, categorized, and access-controlled document libraries. By parsing PDF binary text streams natively in pure PHP, the plugin indexes document content into a dedicated MySQL `FULLTEXT` engine, allowing visitors to search, filter, and view documents instantly with highlighted keyword snippets and deep-linked page viewers.

---

## Table of Contents
- [About](#about)
- [Key Features](#key-features)
- [Architecture & Extraction Flow](#architecture--extraction-flow)
- [System Requirements](#system-requirements)
- [Installation](#installation)
  - [Method 1: WordPress Admin Upload (Recommended)](#method-1-wordpress-admin-upload-recommended)
  - [Method 2: Git Clone](#method-2-git-clone)
- [Automatic In-Dashboard Updates](#automatic-in-dashboard-updates)
- [Documentation & Usage](#documentation--usage)
  - [Shortcode Guide](#shortcode-guide)
  - [Gutenberg Block](#gutenberg-block)
  - [Document Metadata & Attributes](#document-metadata--attributes)
  - [Access Control Modes](#access-control-modes)
- [Admin Management & Diagnostic Tools](#admin-management--diagnostic-tools)
- [Security Hardening](#security-hardening)
- [Changelog](#changelog)
- [License & Credits](#license--credits)

---

## About

By default, WordPress treats PDF files as opaque binary attachments. Standard WordPress search queries only index post titles and descriptions, leaving the actual knowledge and text trapped inside PDF documents completely unsearchable.

**WPCalibrate Searchable PDF Library** solves this limitation:
1. **Local Text Extraction**: Automatically extracts readable text and metadata from PDF files using pure PHP stream decoding—requiring zero external server binaries (no `pdftotext`, `tesseract`, or `Ghostscript` needed).
2. **Dedicated Search Index**: Stores chunked text and metadata inside an optimized custom table (`wp_wpcal_spl_index`) with MySQL `FULLTEXT` indexing.
3. **Instant Frontend Discovery**: Delivers a lightning-fast responsive document library interface with live debounced search, facet filters, and deep-linked PDF modal preview.
4. **Access Governance**: Enforces granular document access levels (Public, Logged-in Users, or Specific WordPress Roles) with protected binary delivery.

---

## Key Features

- **Pure PHP Text Extraction**:
  - Decodes FlateDecode, LZW, ASCII85, ASCIIHex, and raw text operators (`BT`/`ET`, `Tj`, `TJ`).
  - Fallback stream parsing and XMP/Info metadata recovery for scanned and rasterized PDF documents.
- **High-Performance MySQL FULLTEXT Indexing**:
  - Custom database table (`wp_wpcal_spl_index`) isolated from `wp_posts` and `wp_postmeta` to maintain ultra-fast query performance.
  - Multi-column weighted search matching across Title, Text Content, Summary, Author, Code/Number, Categories, and Custom Fields.
  - Intelligent keyword snippet generation with search term highlighting and word boundaries.
- **Responsive Frontend Library Interface**:
  - Embeddable via `[wpcal_pdf_library]` shortcode or the native `wpcalibrate/pdf-library` Gutenberg block.
  - Interactive Grid and List view layouts with toggleable controls.
  - Live AJAX search with debounced typing (350ms) and accessible no-JS fallback.
  - Facet filters for Categories, Tags, and Publication Year.
- **Embedded Modal PDF Viewer**:
  - Responsive in-browser PDF viewing modal.
  - Deep-linked page navigation (`#page=N`) jumping directly to relevant content.
  - Direct download button honoring permissions.
- **Robust Access Control**:
  - **Public**: Accessible to all site visitors.
  - **Logged-in Users**: Restricted to registered members.
  - **Role-based**: Restricted to specific WordPress user roles (e.g. Administrator, Editor, Subscriber, Customer).
  - Protected binary streaming handler prevents unauthorized direct file URL access.
- **Bulk Media Scanner & Background Processing**:
  - Scan existing WordPress Media Library for PDF files and convert them into searchable documents with one click.
  - Background asynchronous indexing via Action Scheduler or WP-Cron with configurable batch sizes.
- **One-Click In-Dashboard Updates**:
  - Fully integrated with GitHub Releases. Receive update notifications and update directly from the WordPress plugins screen.

---

## Architecture & Extraction Flow

```
+-----------------------------------------------------------------------------------+
|                            PDF DOCUMENT INGESTION                                 |
+-----------------------------------------------------------------------------------+
                                         |
                                         v
   +---------------------------------------------------------------------------+
   |                     LocalPdfParser (Pure PHP Stream)                      |
   |   - Uncompresses FlateDecode / LZW Streams                                 |
   |   - Traverses Content Streams & Text Operators (BT, ET, Tj, TJ)          |
   |   - Fallback Raw Stream & XMP / Info Dictionary Parser                    |
   +---------------------------------------------------------------------------+
                                         |
                                         v
   +---------------------------------------------------------------------------+
   |                        IndexWriter & SchemaManager                        |
   |   - Normalizes UTF-8 encoding                                             |
   |   - Partitions text into indexed chunks (2,500 chars)                     |
   |   - Writes to custom MySQL table: wp_wpcal_spl_index                      |
   |   - Builds MySQL FULLTEXT Index across Title, Text, Summary, Metadata     |
   +---------------------------------------------------------------------------+
                                         |
                                         v
   +---------------------------------------------------------------------------+
   |                        MySqlSearchEngine & Facets                         |
   |   - Left Join query execution (immediate visibility for drafts & queued)  |
   |   - Role & Access mode permissions filtering                              |
   |   - SnippetGenerator: extract 180-char context window with <mark> tags    |
   +---------------------------------------------------------------------------+
                                         |
                                         v
   +---------------------------------------------------------------------------+
   |                     FrontendRenderer & Interactive UI                     |
   |   - Grid / List Layouts                                                   |
   |   - Live debounced AJAX search (350ms)                                    |
   |   - Embedded modal PDF viewer with #page=N deep-linking                   |
   +---------------------------------------------------------------------------+
```

---

## System Requirements

| Component | Minimum Version | Recommended Version |
| :--- | :--- | :--- |
| **WordPress** | 6.5+ | 6.7+ / 7.1+ |
| **PHP** | 8.2 | 8.2 or 8.3 |
| **MySQL / MariaDB** | MySQL 5.7+ / MariaDB 10.3+ | MySQL 8.0+ / MariaDB 10.6+ |
| **PHP Extensions** | `mbstring`, `json`, `zip`, `zlib` | Standard on all modern hosting |

---

## Installation

### Method 1: WordPress Admin Upload (Recommended)
1. Download the latest release package: [`wpcalibrate-searchable-pdf-library-1.0.0.zip`](https://github.com/zeeshanraza-official/wpcalibrate-searchable-pdf-library/releases/latest).
2. Log into your WordPress admin dashboard.
3. Navigate to **Plugins** &rarr; **Add New Plugin** &rarr; **Upload Plugin**.
4. Choose the downloaded ZIP file and click **Install Now**.
5. Click **Activate Plugin**.

### Method 2: Git Clone
```bash
cd wp-content/plugins
git clone https://github.com/zeeshanraza-official/wpcalibrate-searchable-pdf-library.git wpcalibrate-searchable-pdf-library
cd wpcalibrate-searchable-pdf-library
composer install --no-dev --optimize-autoloader
```

---

## Automatic In-Dashboard Updates

This plugin is pre-configured with native GitHub Release Updater capabilities.

1. When a new version is released on GitHub, WordPress will automatically detect the new release.
2. An **"Update Available"** banner will appear on your **Plugins** page (`wp-admin/plugins.php`).
3. Click **Update Now** to download and update the plugin directly in your WordPress dashboard—no manual FTP or ZIP uploading needed!

You can also use community tools such as [Git Updater](https://git-updater.com/) if you prefer repository-level branch tracking.

---

## Documentation & Usage

### Shortcode Guide

Insert the PDF document library onto any page, post, or widget area using the shortcode:

```text
[wpcal_pdf_library]
```

#### Shortcode Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `layout` | `string` | `'grid'` | Presentation layout: `'grid'` or `'list'`. |
| `per_page` | `int` | `12` | Number of documents displayed per page (1 to 100). |
| `category` | `string` | `''` | Filter documents by category slug (comma-separated). |
| `tag` | `string` | `''` | Filter documents by tag slug (comma-separated). |
| `show_filters` | `bool` | `true` | Toggle category, tag, and year dropdown filters (`true`/`false`). |
| `show_search` | `bool` | `true` | Toggle the real-time search input bar (`true`/`false`). |
| `orderby` | `string` | `'date'` | Sorting criteria: `'date'`, `'title'`, `'publication_date'`. |
| `order` | `string` | `'DESC'` | Sort direction: `'DESC'` or `'ASC'`. |

#### Usage Examples

**List view with 20 documents per page:**
```text
[wpcal_pdf_library layout="list" per_page="20"]
```

**Filter by specific category without search bar:**
```text
[wpcal_pdf_library category="research-papers" show_search="false"]
```

---

### Gutenberg Block

The plugin includes a native Block Editor block: **Searchable PDF Library** (`wpcalibrate/pdf-library`).
1. In the Block Editor, click the **+** (Add block) button.
2. Search for **Searchable PDF Library**.
3. Use the block inspector sidebar to configure layout (Grid/List), items per page, category filters, and search bar visibility with live editor preview.

---

### Document Metadata & Attributes

Each PDF document post type (`wpcal_pdf_document`) includes dedicated attributes:
- **PDF Source Attachment**: Select from existing Media Library or upload a new PDF.
- **Document Number / Code**: Reference code, report number, or internal filing ID.
- **Author / Organization**: Individual author or publishing organization.
- **Publication Date & Year**: Official publishing date and year.
- **Document Summary / Abstract**: Text summary indexed with high search weighting.
- **Custom Metadata Fields**: Configure unlimited custom fields in **Settings** &rarr; **Custom Fields**.

---

### Access Control Modes

In the document editor, configure **Access Mode**:
- **Public**: Visible and downloadable by all visitors.
- **Logged-in Users Only**: Visitors must be authenticated; guests are prompted to log in.
- **Specific Roles**: Select specific user roles (e.g. `subscriber`, `editor`, `customer`). Unauthorized users cannot view or download the PDF.

---

## Admin Management & Diagnostic Tools

Navigate to **WPCalibrate** &rarr; **Searchable PDF Library** in your WordPress admin menu:
- **Overview**: Complete quick-start workflow guide, architecture reference, and shortcode cheat sheet.
- **Dashboard**: Library statistics (Total Documents, Indexed Pages, Storage Used, Access Modes).
- **Documents**: Document management table with instant status badges, page counts, and 1-click re-indexing.
- **Categories & Tags**: Dedicated document taxonomies.
- **Custom Fields**: Define dynamic custom metadata fields.
- **Indexing**: Configure batch limits, chunk sizes, and Action Scheduler queues.
- **Tools**: Bulk Media Library Scanner to convert PDFs in bulk; Index Rebuilding and Cache Purging.

---

## Security Hardening

- **Access Verification**: Direct file downloads for restricted PDFs are routed through `ProtectedFileDelivery.php`, preventing unauthorized access to protected documents.
- **Strict Capabilities**: All administrative actions require `manage_options` capability.
- **CSRF Defense**: All form submissions and AJAX requests verify cryptographically secure WordPress nonces.
- **SQL Injection Prevention**: All queries to `wp_wpcal_spl_index` use `$wpdb->prepare()`.
- **Path Traversal Protection**: File paths are strictly validated using `realpath()` within permitted WordPress upload directories.
- **Sanitization & Escaping**: All user inputs sanitized on storage; all outputs escaped via `esc_html()`, `esc_attr()`, and `esc_url()`.

---

## Changelog

### [1.0.3] - 2026-10-08
- **Fixed**: Removed false-positive OCR alerts for raster/scanned PDFs (`Testing.pdf`). Documents with metadata or raw streams are cleanly marked as `Indexed`.
- **Fixed**: Registered standard shortcode tag `[wpcal_pdf_library]` alongside legacy alias `[wpcalibrate_pdf_library]`.
- **Fixed**: Switched search engine queries to `LEFT JOIN` on `wp_wpcal_spl_index` so published documents are immediately browseable before background indexing runs.
- **Fixed**: Added automatic database table schema verification on plugin boot.
- **Added**: Native GitHub Release Updater service for 1-click updates inside the WordPress admin dashboard.

### [1.0.2] - 2026-10-08
- **Fixed**: Resolved infinite recursion loop on `save_post` by decoupling `DocumentModel::save_meta()` from `wp_update_post()`.
- **Fixed**: Added static recursion guard in `DocumentAdmin::save_document_meta()`.
- **Fixed**: Resolved Action Scheduler payload type mismatch in `IndexingQueue::process_single_document()`.

### [1.0.1] - 2026-10-08
- **Added**: Comprehensive "Overview" tab in plugin settings detailing purpose, methods, shortcodes, and quick links.
- **Fixed**: Admin sidebar menu icon updated to crisp 20x20 vector SVG matching native WordPress Dashicons pixel-for-pixel.
- **Added**: Automated multi-worker FTP deployment tooling.

### [1.0.0] - 2026-10-08
- Initial production release.
- Pure PHP PDF text stream extraction.
- MySQL FULLTEXT search engine with snippet highlighting.
- Gutenberg block and shortcode support.
- Role-based access control and protected file streaming.
- Media Library scanner and Action Scheduler batch indexing.

---

## License & Credits

- **Author**: [WPCalibrate](https://wpcalibrate.com)
- **Website**: [https://wpcalibrate.com](https://wpcalibrate.com)
- **Support**: [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
- **License**: [GNU General Public License v2.0 or later](LICENSE)
- **Copyright**: &copy; 2026 WPCalibrate. All rights reserved.
