<?php
/**
 * PDF text extraction contract for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Extraction;

/**
 * Interface PdfExtractorInterface
 *
 * Contract for extracting structured searchable text from PDF files.
 */
interface PdfExtractorInterface {

	/**
	 * Extract text from a local PDF file.
	 *
	 * @param string $file_path Absolute path to the PDF file.
	 * @return ExtractionResult
	 */
	public function extract( string $file_path ): ExtractionResult;

	/**
	 * Check if required parser extensions/environment are operational.
	 *
	 * @return array{is_available: bool, missing_extensions: array<string>}
	 */
	public function check_availability(): array;
}
