<?php
/**
 * Document access control service for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Access;

use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;

/**
 * Class DocumentAccessService
 *
 * Enforces granular access restrictions (Public, Logged-in Users, Selected Roles).
 */
class DocumentAccessService {

	/**
	 * Access mode constants.
	 */
	public const MODE_PUBLIC    = 'public';
	public const MODE_LOGGED_IN = 'logged_in';
	public const MODE_ROLES     = 'roles';

	/**
	 * Check if a given user can access a specific document.
	 *
	 * @param int|DocumentModel $document Document model or post ID.
	 * @param int|null          $user_id User ID (null for current user).
	 * @return bool True if accessible.
	 */
	public function can_user_access_document( int|DocumentModel $document, ?int $user_id = null ): bool {
		$model = is_int( $document ) ? new DocumentModel( $document ) : $document;
		if ( $model->get_id() <= 0 ) {
			return false;
		}

		$user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );
		$is_logged_in = ( $user instanceof \WP_User && $user->exists() );

		// Administrators can always access all managed documents
		if ( $is_logged_in && user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$mode = $model->get_access_mode();

		switch ( $mode ) {
			case self::MODE_PUBLIC:
				return true;

			case self::MODE_LOGGED_IN:
				return $is_logged_in;

			case self::MODE_ROLES:
				if ( ! $is_logged_in ) {
					return false;
				}
				$allowed_roles = $model->get_allowed_roles();
				if ( empty( $allowed_roles ) ) {
					// Fallback to logged-in if no specific roles were checked
					return true;
				}
				$user_roles = (array) $user->roles;
				return ! empty( array_intersect( $allowed_roles, $user_roles ) );

			default:
				return false;
		}
	}

	/**
	 * Generate SQL WHERE condition to restrict documents based on user access.
	 *
	 * Used by MySQL Search Engine and WP_Query filters to ensure restricted documents
	 * are filtered out at database level.
	 *
	 * @param string   $document_id_column SQL column name for document ID.
	 * @param int|null $user_id User ID (null for current user).
	 * @return string Safe SQL snippet.
	 */
	public function get_access_sql_clause( string $document_id_column = 'document_id', ?int $user_id = null ): string {
		global $wpdb;

		$user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );
		$is_logged_in = ( $user instanceof \WP_User && $user->exists() );

		// Administrator can see everything
		if ( $is_logged_in && user_can( $user, 'manage_options' ) ) {
			return '1=1';
		}

		if ( ! $is_logged_in ) {
			// Anonymous visitors can see any document not explicitly restricted to logged-in or specific roles
			return "{$document_id_column} NOT IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '" . DocumentPostType::META_ACCESS_MODE . "'
				AND meta_value IN ('logged_in', 'roles')
			)";
		}

		// Logged in user: public, logged_in, or matching role
		$user_roles = (array) $user->roles;
		$role_conditions = [];

		foreach ( $user_roles as $role ) {
			// Role is stored in serialized array inside postmeta
			$escaped_role = esc_sql( $role );
			$role_conditions[] = "meta_value LIKE '%\"{$escaped_role}\"%'";
		}

		$role_check_sql = '';
		if ( ! empty( $role_conditions ) ) {
			$role_check_sql = " OR (meta_key = '" . DocumentPostType::META_ALLOWED_ROLES . "' AND (" . implode( ' OR ', $role_conditions ) . '))';
		}

		return "{$document_id_column} IN (
			SELECT post_id FROM {$wpdb->postmeta}
			WHERE (meta_key = '" . DocumentPostType::META_ACCESS_MODE . "' AND meta_value IN ('public', 'logged_in'))
			{$role_check_sql}
		)";
	}

	/**
	 * Get list of all available WordPress roles for administration select.
	 *
	 * @return array<string, string> Keyed by role slug with display name.
	 */
	public function get_available_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return [];
		}

		$wp_roles = wp_roles();
		$roles    = [];

		foreach ( $wp_roles->roles as $slug => $data ) {
			$roles[ $slug ] = translate_user_role( $data['name'] );
		}

		return $roles;
	}
}
