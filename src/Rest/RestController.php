<?php
/**
 * REST API controller for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Rest;

use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexStatus;
use WPCalibrate\SearchablePdfLibrary\Search\SearchEngineInterface;
use WPCalibrate\SearchablePdfLibrary\Tools\MediaLibraryScanner;

/**
 * Class RestController
 *
 * Implements REST routes under the wpcalibrate-pdf-library/v1 namespace.
 */
class RestController {

	public const NAMESPACE = 'wpcalibrate-pdf-library/v1';

	private SearchEngineInterface $search_engine;
	private DocumentAccessService $access_service;
	private IndexingQueue $queue;
	private MediaLibraryScanner $scanner;

	/**
	 * Constructor.
	 *
	 * @param SearchEngineInterface $search_engine Search engine.
	 * @param DocumentAccessService $access_service Access service.
	 * @param IndexingQueue         $queue Queue.
	 * @param MediaLibraryScanner   $scanner Scanner.
	 */
	public function __construct(
		SearchEngineInterface $search_engine,
		DocumentAccessService $access_service,
		IndexingQueue $queue,
		MediaLibraryScanner $scanner
	) {
		$this->search_engine  = $search_engine;
		$this->access_service = $access_service;
		$this->queue          = $queue;
		$this->scanner        = $scanner;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// 1. Search / Public documents endpoint
		register_rest_route(
			self::NAMESPACE,
			'/search',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_search_results' ],
					'permission_callback' => '__return_true', // Publicly callable, but filtered by access service inside
					'args'                => [
						'query'    => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'category' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'tag'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'sort'     => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => 'relevance',
						],
						'page'     => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 1,
						],
						'per_page' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 20,
						],
					],
				],
			]
		);

		// 2. Single document details endpoint
		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_single_document' ],
					'permission_callback' => [ $this, 'check_document_read_permission' ],
					'args'                => [
						'id' => [
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $param ): bool => is_numeric( $param ) && $param > 0,
						],
					],
				],
			]
		);

		// 3. Status and diagnostics endpoint (Admin only)
		register_rest_route(
			self::NAMESPACE,
			'/status',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_index_status' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
			]
		);

		// 4. Admin reindex actions endpoint
		register_rest_route(
			self::NAMESPACE,
			'/reindex',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'trigger_reindex' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'target' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
							'enum'              => [ 'single', 'all', 'failed' ],
						],
						'id'     => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						],
					],
				],
			]
		);

		// 5. Admin bulk import from media library endpoint
		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'trigger_import' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'batch_size' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 20,
						],
					],
				],
			]
		);
	}

	/**
	 * Verify admin capability for protected routes.
	 *
	 * @return bool
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verify read permissions for single document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_document_read_permission( \WP_REST_Request $request ): bool {
		$id  = (int) $request->get_param( 'id' );
		$doc = new DocumentModel( $id );
		if ( $doc->get_id() <= 0 ) {
			return false;
		}

		return $this->access_service->can_user_access_document( $doc );
	}

	/**
	 * Handle search request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_search_results( \WP_REST_Request $request ): \WP_REST_Response {
		$params = [
			'query'         => $request->get_param( 'query' ) ?: '',
			'category'      => $request->get_param( 'category' ) ?: '',
			'tag'           => $request->get_param( 'tag' ) ?: '',
			'sort'          => $request->get_param( 'sort' ) ?: 'relevance',
			'page'          => (int) ( $request->get_param( 'page' ) ?: 1 ),
			'per_page'      => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			'custom_fields' => $request->get_param( 'custom_fields' ) ?: [],
		];

		$result = $this->search_engine->search( $params );
		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * Handle single document retrieval.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_single_document( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$doc = new DocumentModel( $id );

		if ( $doc->get_id() <= 0 ) {
			return new \WP_Error( 'document_not_found', __( 'Document not found.', 'wpcalibrate-searchable-pdf-library' ), [ 'status' => 404 ] );
		}

		$data = [
			'id'               => $doc->get_id(),
			'title'            => $doc->get_title(),
			'description'      => $doc->get_description(),
			'author'           => $doc->get_author(),
			'publication_date' => $doc->get_publication_date(),
			'publication_year' => $doc->get_publication_year(),
			'document_number'  => $doc->get_document_number(),
			'access_mode'      => $doc->get_access_mode(),
			'view_url'         => $doc->get_access_url( true ),
			'download_url'     => $doc->get_access_url( false ),
		];

		return rest_ensure_response( $data );
	}

	/**
	 * Handle status request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_index_status(): \WP_REST_Response {
		$repo   = new \WPCalibrate\SearchablePdfLibrary\Documents\DocumentRepository();
		$counts = $repo->get_status_counts();
		$unimported = $this->scanner->count_unimported_pdfs();

		return rest_ensure_response(
			[
				'counts'          => $counts,
				'unimported_pdfs' => $unimported,
			]
		);
	}

	/**
	 * Handle re-indexing action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function trigger_reindex( \WP_REST_Request $request ): \WP_REST_Response {
		$target = $request->get_param( 'target' );
		$id     = (int) $request->get_param( 'id' );

		$affected = 0;
		if ( 'single' === $target && $id > 0 ) {
			$this->queue->queue_document( $id );
			$affected = 1;
		} elseif ( 'failed' === $target ) {
			$affected = $this->queue->retry_failed();
		} elseif ( 'all' === $target ) {
			$affected = $this->queue->queue_all( false );
		}

		return rest_ensure_response(
			[
				'success'  => true,
				'target'   => $target,
				'affected' => $affected,
			]
		);
	}

	/**
	 * Handle bulk import action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function trigger_import( \WP_REST_Request $request ): \WP_REST_Response {
		$batch_size = max( 1, min( 100, (int) ( $request->get_param( 'batch_size' ) ?: 20 ) ) );
		$result     = $this->scanner->import_batch( $batch_size, true );

		return rest_ensure_response(
			[
				'success'   => true,
				'imported'  => $result['imported_count'],
				'remaining' => $result['remaining_count'],
			]
		);
	}
}
