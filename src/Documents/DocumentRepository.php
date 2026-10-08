<?php
/**
 * Document repository for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Documents;

/**
 * Class DocumentRepository
 *
 * Provides query methods and statistics for managed PDF documents.
 */
class DocumentRepository {

	/**
	 * Find a document by post ID.
	 *
	 * @param int $id Post ID.
	 * @return DocumentModel|null
	 */
	public function find_by_id( int $id ): ?DocumentModel {
		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );
		if ( ! $post || DocumentPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new DocumentModel( $id );
	}

	/**
	 * Find a document by attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return DocumentModel|null
	 */
	public function find_by_attachment_id( int $attachment_id ): ?DocumentModel {
		global $wpdb;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 1",
				DocumentPostType::META_ATTACHMENT_ID,
				$attachment_id
			)
		);

		return $post_id > 0 ? $this->find_by_id( $post_id ) : null;
	}

	/**
	 * Get count of managed documents grouped by indexing and warning status.
	 *
	 * @return array<string, int>
	 */
	public function get_status_counts(): array {
		global $wpdb;

		$counts = [
			'total'           => 0,
			'not_indexed'     => 0,
			'queued'          => 0,
			'processing'      => 0,
			'indexed'         => 0,
			'needs_attention' => 0,
			'failed'          => 0,
			'scanned_warning' => 0,
		];

		// Total documents
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				DocumentPostType::POST_TYPE
			)
		);
		$counts['total'] = $total;

		if ( $total === 0 ) {
			return $counts;
		}

		// Count by indexing status
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value as status, COUNT(p.ID) as count
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 GROUP BY pm.meta_value",
				DocumentPostType::META_INDEXING_STATUS,
				DocumentPostType::POST_TYPE
			),
			ARRAY_A
		);

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$status = $row['status'] ?: 'not_indexed';
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = (int) $row['count'];
				}
			}
		}

		// Scanned warnings count
		$warn_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s AND pm.meta_value = '1')
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				DocumentPostType::META_SCANNED_WARNING,
				DocumentPostType::POST_TYPE
			)
		);
		$counts['scanned_warning'] = $warn_count;

		return $counts;
	}

	/**
	 * Retrieve a list of documents with pagination and filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{documents: array<DocumentModel>, total: int, pages: int}
	 */
	public function query( array $args = [] ): array {
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$status   = sanitize_key( $args['status'] ?? '' );
		$category = sanitize_text_field( $args['category'] ?? '' );
		$tag      = sanitize_text_field( $args['tag'] ?? '' );
		$search   = sanitize_text_field( $args['search'] ?? '' );

		$query_args = [
			'post_type'      => DocumentPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		if ( ! empty( $status ) ) {
			if ( 'scanned_warning' === $status ) {
				$query_args['meta_query'] = [
					[
						'key'     => DocumentPostType::META_SCANNED_WARNING,
						'value'   => '1',
						'compare' => '=',
					],
				];
			} else {
				$query_args['meta_query'] = [
					[
						'key'     => DocumentPostType::META_INDEXING_STATUS,
						'value'   => $status,
						'compare' => '=',
					],
				];
			}
		}

		$tax_query = [];
		if ( ! empty( $category ) ) {
			$tax_query[] = [
				'taxonomy' => DocumentTaxonomies::TAXONOMY_CATEGORY,
				'field'    => is_numeric( $category ) ? 'term_id' : 'slug',
				'terms'    => $category,
			];
		}
		if ( ! empty( $tag ) ) {
			$tax_query[] = [
				'taxonomy' => DocumentTaxonomies::TAXONOMY_TAG,
				'field'    => is_numeric( $tag ) ? 'term_id' : 'slug',
				'terms'    => $tag,
			];
		}
		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		$wp_query  = new \WP_Query( $query_args );
		$documents = [];

		if ( $wp_query->have_posts() ) {
			foreach ( $wp_query->posts as $post ) {
				$documents[] = new DocumentModel( (int) $post->ID );
			}
		}

		return [
			'documents' => $documents,
			'total'     => (int) $wp_query->found_posts,
			'pages'     => (int) $wp_query->max_num_pages,
		];
	}
}
