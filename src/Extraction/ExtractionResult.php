<?php
/**
 * Extraction result value object for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Extraction;

/**
 * Class ExtractionResult
 *
 * Value object holding text extraction output, page mapping, and diagnostics.
 */
class ExtractionResult {

	public function __construct(
		private bool $success,
		private string $error_message = '',
		private int $page_count = 0,
		private array $pages_text = [],
		private string $full_text = '',
		private bool $is_scanned_or_no_text = false,
		private string $file_hash = '',
		private string $text_hash = ''
	) {}

	public function is_success(): bool {
		return $this->success;
	}

	public function get_error_message(): string {
		return $this->error_message;
	}

	public function get_page_count(): int {
		return $this->page_count;
	}

	/**
	 * Map of page numbers to extracted text.
	 *
	 * @return array<int, string>
	 */
	public function get_pages_text(): array {
		return $this->pages_text;
	}

	public function get_full_text(): string {
		return $this->full_text;
	}

	public function is_scanned_or_no_text(): bool {
		return $this->is_scanned_or_no_text;
	}

	public function get_file_hash(): string {
		return $this->file_hash;
	}

	public function get_text_hash(): string {
		return $this->text_hash;
	}

	/**
	 * Factory method for failure results.
	 *
	 * @param string $error Error message.
	 * @param string $file_hash Optional file hash.
	 * @return self
	 */
	public static function failure( string $error, string $file_hash = '' ): self {
		return new self(
			success: false,
			error_message: $error,
			file_hash: $file_hash
		);
	}
}
