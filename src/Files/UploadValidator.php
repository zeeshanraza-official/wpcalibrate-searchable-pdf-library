<?php
/**
 * PDF file upload and path security validator for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Files;

/**
 * Class UploadValidator
 *
 * Validates PDF uploads, prevents path traversal, and verifies PDF magic bytes.
 */
class UploadValidator {

	/**
	 * Verify if a file path is a safe, readable, real PDF file.
	 *
	 * @param string $file_path Absolute file path.
	 * @return true|\WP_Error
	 */
	public function validate_pdf_file( string $file_path ): true|\WP_Error {
		if ( empty( $file_path ) ) {
			return new \WP_Error( 'invalid_path', __( 'File path is empty.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		$real_path = realpath( $file_path );
		if ( false === $real_path || ! is_file( $real_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'File does not exist or cannot be accessed.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Security: Prevent path traversal by ensuring file is inside wp-content or system uploads.
		$upload_dir = wp_upload_dir();
		$basedir    = realpath( $upload_dir['basedir'] );
		$content_dir= realpath( WP_CONTENT_DIR );

		$is_within_allowed = false;
		if ( $basedir && str_starts_with( $real_path, $basedir ) ) {
			$is_within_allowed = true;
		} elseif ( $content_dir && str_starts_with( $real_path, $content_dir ) ) {
			$is_within_allowed = true;
		}

		if ( ! $is_within_allowed ) {
			return new \WP_Error( 'unsafe_path', __( 'File is located outside the permitted WordPress directories.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Validate extension
		$ext = strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );
		if ( 'pdf' !== $ext ) {
			return new \WP_Error( 'invalid_extension', __( 'File is not a PDF.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Verify PDF magic bytes (%PDF-)
		$handle = @fopen( $real_path, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'unreadable_file', __( 'Could not open file for reading.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		$header = fread( $handle, 1024 );
		fclose( $handle );

		if ( false === $header || ! str_contains( (string) $header, '%PDF-' ) ) {
			return new \WP_Error( 'invalid_pdf_header', __( 'File does not contain a valid PDF signature.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		return true;
	}

	/**
	 * Validate and process an uploaded PDF file from $_FILES.
	 *
	 * @param array<string, mixed> $file_post Raw entry from $_FILES.
	 * @return int|\WP_Error Attachment ID on success or WP_Error.
	 */
	public function handle_direct_upload( array $file_post ): int|\WP_Error {
		if ( empty( $file_post['name'] ) || empty( $file_post['tmp_name'] ) ) {
			return new \WP_Error( 'no_file', __( 'No upload file provided.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Require core upload helpers
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Verify mime
		$file_info = wp_check_filetype_and_ext( $file_post['tmp_name'], $file_post['name'] );
		if ( 'pdf' !== $file_info['ext'] || 'application/pdf' !== $file_info['type'] ) {
			return new \WP_Error( 'invalid_mime', __( 'Uploaded file must be a valid PDF document.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Upload into Media Library
		$attachment_id = media_handle_upload( 'pdf_file', 0, [
			'post_mime_type' => 'application/pdf',
		] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return (int) $attachment_id;
	}
}
