<?php
/**
 * Local PDF text extractor using prinsfrank/pdfparser for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Extraction;

use PrinsFrank\PdfParser\PdfParser;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class LocalPdfParser
 *
 * Concrete implementation of PdfExtractorInterface.
 */
class LocalPdfParser implements PdfExtractorInterface {

	/**
	 * Minimum characters threshold per page to consider meaningful text.
	 */
	public const SCANNED_CHAR_THRESHOLD_PER_PAGE = 25;

	/**
	 * Check if required parser extensions are operational.
	 *
	 * @return array{is_available: bool, missing_extensions: array<string>}
	 */
	public function check_availability(): array {
		$required_extensions = [ 'gd', 'iconv', 'zlib' ];
		$missing             = [];

		foreach ( $required_extensions as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}

		return [
			'is_available'       => empty( $missing ),
			'missing_extensions' => $missing,
		];
	}

	/**
	 * Extract text from a local PDF file.
	 *
	 * @param string $file_path Absolute path to the PDF file.
	 * @return ExtractionResult
	 */
	public function extract( string $file_path ): ExtractionResult {
		$availability = $this->check_availability();
		if ( ! $availability['is_available'] ) {
			return ExtractionResult::failure(
				sprintf(
					/* translators: %s: Comma-separated list of missing extensions */
					__( 'Required PHP extensions for PDF extraction are missing: %s', 'wpcalibrate-searchable-pdf-library' ),
					implode( ', ', $availability['missing_extensions'] )
				)
			);
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return ExtractionResult::failure( __( 'PDF file does not exist or is not readable.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		$file_hash = hash_file( 'sha256', $file_path );
		if ( false === $file_hash ) {
			$file_hash = '';
		}

		// Safeguards: Increase memory limit temporarily if needed and set time limit if permitted.
		$current_limit = ini_get( 'memory_limit' );
		if ( $current_limit && -1 !== (int) $current_limit && function_exists( 'wp_convert_hr_to_bytes' ) ) {
			$limit_bytes = wp_convert_hr_to_bytes( $current_limit );
			if ( $limit_bytes < 268435456 ) { // 256MB
				@ini_set( 'memory_limit', '256M' );
			}
		}
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			@set_time_limit( 120 );
		}

		try {
			$parser   = new PdfParser();
			$document = $parser->parseFile( $file_path );

			$page_count      = $document->getNumberOfPages();
			$pages_text      = [];
			$full_text_parts = [];

			$pages = $document->getPages();
			$page_idx = 1;

			foreach ( $pages as $page ) {
				try {
					$page_raw = $page->getText();
					$cleaned  = self::normalize_whitespace( $page_raw );
				} catch ( \Throwable $page_e ) {
					Logger::warning( 'Failed extracting single page text: ' . $page_e->getMessage() );
					$cleaned = '';
				}

				$pages_text[ $page_idx ] = $cleaned;
				if ( '' !== $cleaned ) {
					$full_text_parts[] = $cleaned;
				}
				$page_idx++;
			}

			// In case getPages() returned empty but getNumberOfPages() is > 0, fallback to document getText()
			if ( empty( $full_text_parts ) && $page_count > 0 ) {
				try {
					$doc_text = self::normalize_whitespace( $document->getText() );
					if ( '' !== $doc_text ) {
						$pages_text[1]     = $doc_text;
						$full_text_parts[] = $doc_text;
					}
				} catch ( \Throwable $t ) {
					Logger::debug( 'Fallback document->getText() exception: ' . $t->getMessage() );
				}
			}

			// Secondary fallback: raw stream parsing and XMP / info metadata extraction
			if ( empty( $full_text_parts ) ) {
				try {
					$raw_fallback = self::extract_raw_pdf_fallback( $file_path );
					if ( '' !== $raw_fallback ) {
						$pages_text[1]     = $raw_fallback;
						$full_text_parts[] = $raw_fallback;
					}
				} catch ( \Throwable $raw_e ) {
					Logger::debug( 'Raw stream fallback extractor exception: ' . $raw_e->getMessage() );
				}
			}

			$full_text = trim( implode( "\n\n", $full_text_parts ) );
			$text_hash = hash( 'sha256', $full_text );

			// Scanned PDF detection
			$total_chars           = mb_strlen( preg_replace( '/\s+/', '', $full_text ) );
			$is_scanned_or_no_text = ( 0 === $total_chars );

			return new ExtractionResult(
				success: true,
				error_message: '',
				page_count: max( 1, $page_count ),
				pages_text: $pages_text,
				full_text: $full_text,
				is_scanned_or_no_text: $is_scanned_or_no_text,
				file_hash: $file_hash,
				text_hash: $text_hash
			);

		} catch ( \Throwable $e ) {
			Logger::error( 'PDF extraction error on ' . basename( $file_path ), [ 'error' => $e->getMessage() ] );

			$msg = $e->getMessage();
			if ( str_contains( strtolower( $msg ), 'encrypt' ) ) {
				$msg = __( 'PDF document is password protected or encrypted and cannot be parsed.', 'wpcalibrate-searchable-pdf-library' );
			} else {
				$msg = sprintf(
					/* translators: %s: Technical parser error message */
					__( 'PDF parser failed to process file: %s', 'wpcalibrate-searchable-pdf-library' ),
					$e->getMessage()
				);
			}

			return ExtractionResult::failure( $msg, $file_hash );
		}
	}

	/**
	 * Secondary fallback text and metadata extractor for PDFs with non-standard streams or CMaps.
	 *
	 * Scans uncompressed content, FlateDecode streams, and XMP metadata packets.
	 *
	 * @param string $file_path Path to PDF file.
	 * @return string Extracted text.
	 */
	public static function extract_raw_pdf_fallback( string $file_path ): string {
		$raw = @file_get_contents( $file_path );
		if ( false === $raw || '' === $raw ) {
			return '';
		}

		$extracted_parts = [];

		// 1. Extract XMP metadata packet (Title, Description, Subject, Keywords)
		if ( preg_match( '/<x:xmpmeta[^>]*>(.*?)<\/x:xmpmeta>/s', $raw, $xmp ) ) {
			if ( preg_match_all( '/<dc:(?:title|description|subject)[^>]*>(.*?)<\/dc:(?:title|description|subject)>/s', $xmp[1], $dc_matches ) ) {
				foreach ( $dc_matches[1] as $dc_text ) {
					$clean = strip_tags( $dc_text );
					if ( '' !== trim( $clean ) ) {
						$extracted_parts[] = trim( $clean );
					}
				}
			}
		}

		// 2. Extract standard PDF Document Info Dictionary strings (/Title, /Author, /Subject, /Keywords)
		if ( preg_match_all( '/\/(Title|Author|Subject|Keywords)\s*\((.*?)\)/s', $raw, $info_matches ) ) {
			foreach ( $info_matches[2] as $info_str ) {
				$clean = trim( stripcslashes( $info_str ) );
				if ( '' !== $clean && mb_strlen( $clean ) > 1 ) {
					$extracted_parts[] = $clean;
				}
			}
		}

		// 3. Scan content streams for text operators
		$offset = 0;
		while ( ( $pos = strpos( $raw, 'stream', $offset ) ) !== false ) {
			if ( substr( $raw, max( 0, $pos - 3 ), 3 ) !== 'end' ) {
				$end_pos = strpos( $raw, 'endstream', $pos );
				if ( false !== $end_pos ) {
					$dict_start = strrpos( substr( $raw, 0, $pos ), '<<' );
					$dict       = false !== $dict_start ? substr( $raw, $dict_start, $pos - $dict_start ) : '';
					$stream_len = $end_pos - ( $pos + 6 );

					// Only parse reasonably sized text content streams (skip giant multi-megabyte image streams)
					if ( $stream_len > 0 && $stream_len < 1048576 && ! str_contains( $dict, '/Subtype /Image' ) ) {
						$stream_bytes = trim( substr( $raw, $pos + 6, $stream_len ) );
						$content      = $stream_bytes;

						if ( str_contains( $dict, '/FlateDecode' ) ) {
							$uncompressed = @gzuncompress( $stream_bytes );
							if ( false === $uncompressed ) {
								$uncompressed = @gzinflate( $stream_bytes );
							}
							if ( false !== $uncompressed ) {
								$content = $uncompressed;
							}
						}

						// Look for PDF text operators
						if ( str_contains( $content, 'BT' ) || str_contains( $content, 'Tj' ) || str_contains( $content, 'TJ' ) ) {
							// Literal strings (...) Tj
							if ( preg_match_all( '/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $content, $tj ) ) {
								foreach ( $tj[1] as $str ) {
									$extracted_parts[] = stripcslashes( $str );
								}
							}
							// Array of glyphs/strings [(...)] TJ
							if ( preg_match_all( '/\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ/s', $content, $tJ ) ) {
								foreach ( $tJ[1] as $arr ) {
									if ( preg_match_all( '/\(((?:[^()\\\\]|\\\\.)*)\)/s', $arr, $sub_strs ) ) {
										$extracted_parts[] = implode( '', array_map( 'stripcslashes', $sub_strs[1] ) );
									}
								}
							}
						}
					}
				}
			}
			$offset = $pos + 6;
		}

		return self::normalize_whitespace( implode( "\n", $extracted_parts ) );
	}

	/**
	 * Normalize whitespace while preserving essential word boundaries and paragraphs.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize_whitespace( string $text ): string {
		// Replace CRLF / CR with LF
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		// Replace non-breaking spaces with standard space
		$text = str_replace( "\xc2\xa0", ' ', $text );

		// Replace tabs and multiple horizontal spaces with a single space
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );

		// Trim whitespace on each line
		$lines = explode( "\n", (string) $text );
		$lines = array_map( 'trim', $lines );

		// Rebuild text, collapsing 3+ consecutive newlines to 2
		$clean = implode( "\n", $lines );
		$clean = preg_replace( "/\n{3,}/", "\n\n", $clean );

		return trim( (string) $clean );
	}
}
