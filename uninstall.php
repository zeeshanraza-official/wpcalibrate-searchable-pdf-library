<?php
/**
 * Plugin uninstaller for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

// Prevent direct execution.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check admin setting: "Delete plugin data when uninstalled". Default is false (retain data).
$delete_data = (bool) get_option( 'wpcal_spl_delete_on_uninstall', false );

if ( ! $delete_data ) {
	// Administrator opted to preserve data. Exit without deleting.
	return;
}

global $wpdb;

// 1. Drop custom search index table.
$table_name = $wpdb->prefix . 'wpcal_spl_index';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// 2. Remove all managed document CPT posts and their postmeta.
// Original Media Library attachments are NOT deleted.
$document_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		'wpcal_pdf_document'
	)
);

if ( ! empty( $document_ids ) ) {
	foreach ( $document_ids as $doc_id ) {
		wp_delete_post( (int) $doc_id, true );
	}
}

// 3. Delete taxonomy terms and relationships for plugin taxonomies.
$taxonomies = [ 'wpcal_pdf_category', 'wpcal_pdf_tag' ];
foreach ( $taxonomies as $taxonomy ) {
	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		]
	);

	if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, $taxonomy );
		}
	}
}

// 4. Delete plugin options.
$options_to_delete = [
	'wpcal_spl_version',
	'wpcal_spl_db_version',
	'wpcal_spl_settings',
	'wpcal_spl_custom_fields',
	'wpcal_spl_license_data',
	'wpcal_spl_delete_on_uninstall',
	'wpcal_spl_queue_status',
];

foreach ( $options_to_delete as $option_name ) {
	delete_option( $option_name );
}

// 5. Clean transients and locks.
delete_transient( 'wpcal_spl_indexing_lock' );
delete_transient( 'wpcal_spl_flush_rewrites' );
delete_transient( 'wpcal_spl_db_health' );

// 6. Clear scheduled cron hook.
wp_clear_scheduled_hook( 'wpcal_spl_process_indexing_queue' );

// 7. Remove protected storage files created by plugin (excluding uploads).
$upload_dir = wp_upload_dir();
$protected_dir = trailingslashit( $upload_dir['basedir'] ) . 'wpcal_spl_protected';
if ( is_dir( $protected_dir ) ) {
	$files = glob( $protected_dir . '/*' );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $protected_dir );
}
