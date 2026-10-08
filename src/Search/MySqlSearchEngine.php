<?php
/**
 * MySQL / MariaDB search engine implementation for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Search;

use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Database\SchemaManager;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentTaxonomies;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;

/**
 * Class MySqlSearchEngine
 */
class MySqlSearchEngine implements SearchEngineInterface {

	/**
	 * Document access service.
	 *
	 * @var DocumentAccessService
	 */
	private DocumentAccessService $access_service;

	/**
	 * Database schema manager.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Constructor.
	 *
	 * @param DocumentAccessService $access_service Access service.
	 * @param SchemaManager         $schema_manager Schema manager.
	 */
	public function __construct( DocumentAccessService $access_service, SchemaManager $schema_manager ) {
		$this->access_service = $access_service;
		$this->schema_manager = $schema_manager;
	}

	/**
	 * Execute search query.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @param int|null             $user_id User ID.
	 * @return SearchResult
	 */
	public function search( array $params, ?int $user_id = null ): SearchResult {
		global $wpdb;

		$table_name   = $this->schema_manager->get_index_table_name();
		$raw_query    = trim( (string) ( $params['query'] ?? '' ) );
		$category     = sanitize_text_field( (string) ( $params['category'] ?? '' ) );
		$tag          = sanitize_text_field( (string) ( $params['tag'] ?? '' ) );
		$sort         = sanitize_key( (string) ( $params['sort'] ?? 'relevance' ) );
		$page         = max( 1, (int) ( $params['page'] ?? 1 ) );
		$per_page     = max( 1, min( 100, (int) ( $params['per_page'] ?? 20 ) ) );
		$offset       = ( $page - 1 ) * $per_page;
		$custom_fltrs = is_array( $params['custom_fields'] ?? null ) ? $params['custom_fields'] : [];

		$ft_capable   = $this->schema_manager->is_fulltext_capable();
		$is_fallback  = ! $ft_capable;

		// 1. Base conditions
		$where_clauses = [
			"p.post_type = '" . DocumentPostType::POST_TYPE . "'",
			"p.post_status = 'publish'",
		];

		// Access control filter
		$where_clauses[] = $this->access_service->get_access_sql_clause( 'p.ID', $user_id );

		// Category filter
		if ( ! empty( $category ) ) {
			$where_clauses[] = $wpdb->prepare(
				"p.ID IN (
					SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s)
					INNER JOIN {$wpdb->terms} t ON (tt.term_id = t.term_id)
					WHERE (t.slug = %s OR t.term_id = %s)
				)",
				DocumentTaxonomies::TAXONOMY_CATEGORY,
				$category,
				$category
			);
		}

		// Tag filter
		if ( ! empty( $tag ) ) {
			$where_clauses[] = $wpdb->prepare(
				"p.ID IN (
					SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s)
					INNER JOIN {$wpdb->terms} t ON (tt.term_id = t.term_id)
					WHERE (t.slug = %s OR t.term_id = %s)
				)",
				DocumentTaxonomies::TAXONOMY_TAG,
				$tag,
				$tag
			);
		}

		// Custom field filters
		foreach ( $custom_fltrs as $field_key => $field_val ) {
			if ( '' !== $field_val && null !== $field_val ) {
				$sanitized_k = sanitize_key( (string) $field_key );
				$meta_k      = '_wpcal_cf_' . $sanitized_k;
				$where_clauses[] = $wpdb->prepare(
					"p.ID IN (
						SELECT post_id FROM {$wpdb->postmeta}
						WHERE meta_key = %s AND meta_value = %s
					)",
					$meta_k,
					sanitize_text_field( (string) $field_val )
				);
			}
		}

		// 2. Search query scoring and WHERE conditions
		$select_score = '0.0 AS score';
		if ( ! empty( $raw_query ) ) {
			if ( $ft_capable ) {
				// Prepare boolean mode terms
				$boolean_query = self::build_boolean_query( $raw_query );
				$like_exact    = '%' . $wpdb->esc_like( $raw_query ) . '%';

				$where_clauses[] = $wpdb->prepare(
					'((i.body_text IS NOT NULL AND MATCH(i.body_text) AGAINST(%s IN BOOLEAN MODE)) OR (i.title_text IS NOT NULL AND MATCH(i.title_text, i.metadata_text) AGAINST(%s IN BOOLEAN MODE)) OR p.post_title LIKE %s OR i.title_text LIKE %s)',
					$boolean_query,
					$boolean_query,
					$like_exact,
					$like_exact
				);

				$select_score = $wpdb->prepare(
					'( (CASE WHEN p.post_title LIKE %s THEN 50.0 ELSE 0.0 END)
					 + (CASE WHEN i.title_text IS NOT NULL THEN (MATCH(i.title_text, i.metadata_text) AGAINST(%s IN BOOLEAN MODE) * 15.0) ELSE 0.0 END)
					 + (CASE WHEN i.body_text IS NOT NULL THEN (MATCH(i.body_text) AGAINST(%s IN BOOLEAN MODE) * 2.0) ELSE 0.0 END)
					) AS score',
					$like_exact,
					$boolean_query,
					$boolean_query
				);
			} else {
				// Degraded fallback mode using LIKE
				$like_term = '%' . $wpdb->esc_like( $raw_query ) . '%';
				$where_clauses[] = $wpdb->prepare(
					'(p.post_title LIKE %s OR i.title_text LIKE %s OR i.metadata_text LIKE %s OR i.body_text LIKE %s)',
					$like_term,
					$like_term,
					$like_term,
					$like_term
				);

				$select_score = $wpdb->prepare(
					'(CASE WHEN p.post_title LIKE %s THEN 20.0 ELSE 1.0 END) AS score',
					$like_term
				);
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// 3. Sorting allowlist
		$order_by_sql = match ( $sort ) {
			'title_asc'  => 'p.post_title ASC',
			'title_desc' => 'p.post_title DESC',
			'newest_pub', 'date_desc' => 'pub_date.meta_value DESC, p.ID DESC',
			'oldest_pub', 'date_asc'  => 'pub_date.meta_value ASC, p.ID ASC',
			'recent', 'added_desc'    => 'p.ID DESC',
			default                   => ! empty( $raw_query ) ? 'max_score DESC, p.ID DESC' : 'p.ID DESC',
		};

		// 4. Grouping by p.ID to pick highest scoring chunk and page
		$main_sql = "SELECT 
				p.ID as document_id,
				p.post_title,
				MAX({$select_score}) as max_score,
				SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(i.body_text, '') ORDER BY {$select_score} DESC SEPARATOR ' '), ' ', 500) as matched_snippet_source,
				(
					SELECT i2.page_number 
					FROM {$table_name} i2 
					WHERE i2.document_id = p.ID 
					ORDER BY {$select_score} DESC 
					LIMIT 1
				) as best_page
			FROM {$wpdb->posts} p
			LEFT JOIN {$table_name} i ON (p.ID = i.document_id)
			LEFT JOIN {$wpdb->postmeta} pub_date ON (p.ID = pub_date.post_id AND pub_date.meta_key = '" . DocumentPostType::META_PUB_DATE . "')
			WHERE {$where_sql}
			GROUP BY p.ID, p.post_title
			ORDER BY {$order_by_sql}
			LIMIT %d OFFSET %d";

		$final_sql = $wpdb->prepare( $main_sql, $per_page, $offset );
		$rows      = $wpdb->get_results( $final_sql, ARRAY_A );

		// Count total matching documents
		$count_sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$table_name} i ON (p.ID = i.document_id)
			LEFT JOIN {$wpdb->postmeta} pub_date ON (p.ID = pub_date.post_id AND pub_date.meta_key = '" . DocumentPostType::META_PUB_DATE . "')
			WHERE {$where_sql}";

		$total = (int) $wpdb->get_var( $count_sql );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		// 5. Build SearchItem DTOs
		$items = [];
		if ( ! empty( $rows ) ) {
			$custom_field_manager = \WPCalibrate\SearchablePdfLibrary\Plugin::get_instance()
				->get_container()
				->get( CustomFieldManager::class );
			$display_fields = $custom_field_manager->get_display_fields();

			foreach ( $rows as $row ) {
				$doc_id = (int) $row['document_id'];
				$model  = new DocumentModel( $doc_id );

				$raw_snippet = ! empty( $row['matched_snippet_source'] )
					? SnippetGenerator::extract_snippet( (string) $row['matched_snippet_source'], $raw_query )
					: SnippetGenerator::extract_snippet( $model->get_description(), $raw_query );

				$highlighted = SnippetGenerator::highlight( $raw_snippet, $raw_query );

				// Compile categories and tags
				$categories_data = array_map(
					static fn( \WP_Term $t ): array => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ],
					$model->get_categories()
				);
				$tags_data = array_map(
					static fn( \WP_Term $t ): array => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ],
					$model->get_tags()
				);

				// Compile display custom fields
				$cf_values = [];
				foreach ( $display_fields as $fk => $fdef ) {
					$val = $model->get_custom_field( (string) $fk );
					if ( '' !== $val && null !== $val ) {
						$cf_values[ $fk ] = [
							'label' => $fdef['label'] ?? $fk,
							'value' => $val,
						];
					}
				}

				$matched_page = (int) ( $row['best_page'] ?? 0 );

				// Deep linking with #page=N where supported
				$view_url     = $model->get_access_url( true );
				if ( ! empty( $view_url ) && $matched_page > 0 ) {
					$view_url .= '#page=' . $matched_page;
				}

				$download_url = $model->get_access_url( false );

				$items[] = new SearchItem(
					document_id:         $doc_id,
					title:               $model->get_title(),
					description:         $model->get_description(),
					author:              $model->get_author(),
					publication_date:    $model->get_publication_date(),
					publication_year:    $model->get_publication_year(),
					document_number:     $model->get_document_number(),
					categories:          $categories_data,
					tags:                $tags_data,
					custom_fields:       $cf_values,
					snippet:             $raw_snippet,
					highlighted_snippet: $highlighted,
					matched_page:        $matched_page,
					access_mode:         $model->get_access_mode(),
					view_url:            $view_url,
					download_url:        $download_url,
					score:               (float) ( $row['max_score'] ?? 0.0 )
				);
			}
		}

		return new SearchResult(
			items:            $items,
			total:            $total,
			page:             $page,
			per_page:         $per_page,
			total_pages:      $total_pages,
			query:            $raw_query,
			is_fallback_mode: $is_fallback
		);
	}

	/**
	 * Build boolean fulltext query string from user input.
	 *
	 * Appends '*' for prefix wildcard and escapes boolean operators.
	 *
	 * @param string $query Raw query.
	 * @return string Boolean mode query.
	 */
	public static function build_boolean_query( string $query ): string {
		$words = SnippetGenerator::extract_search_terms( $query );
		if ( empty( $words ) ) {
			return '';
		}

		$parts = [];
		foreach ( $words as $word ) {
			// Clean any operators
			$clean = preg_replace( '/[+\-><()~*\"@]+/', '', $word );
			if ( ! empty( $clean ) ) {
				$parts[] = '+' . $clean . '*';
			}
		}

		return implode( ' ', $parts );
	}
}
