<?php
/**
 * Search results DTOs for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Search;

/**
 * Class SearchItem
 */
class SearchItem {

	/**
	 * Constructor.
	 *
	 * @param int                  $document_id Document ID.
	 * @param string               $title Document title.
	 * @param string               $description Description.
	 * @param string               $author Author.
	 * @param string               $publication_date Publication date.
	 * @param int                  $publication_year Publication year.
	 * @param string               $document_number Document number.
	 * @param array<int, array{id: int, name: string, slug: string}> $categories Categories.
	 * @param array<int, array{id: int, name: string, slug: string}> $tags Tags.
	 * @param array<string, mixed> $custom_fields Custom fields.
	 * @param string               $snippet Raw snippet.
	 * @param string               $highlighted_snippet Highlighted HTML snippet.
	 * @param int                  $matched_page Matched page number (0 if unknown).
	 * @param string               $access_mode Access mode ('public', 'logged_in', 'roles').
	 * @param string               $view_url View action URL.
	 * @param string               $download_url Download action URL.
	 * @param float                $score Search relevance score.
	 */
	public function __construct(
		public int $document_id,
		public string $title,
		public string $description = '',
		public string $author = '',
		public string $publication_date = '',
		public int $publication_year = 0,
		public string $document_number = '',
		public array $categories = [],
		public array $tags = [],
		public array $custom_fields = [],
		public string $snippet = '',
		public string $highlighted_snippet = '',
		public int $matched_page = 0,
		public string $access_mode = 'public',
		public string $view_url = '',
		public string $download_url = '',
		public float $score = 0.0
	) {}

	/**
	 * Convert to array for REST API responses or templates.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'document_id'         => $this->document_id,
			'title'               => $this->title,
			'description'         => $this->description,
			'author'              => $this->author,
			'publication_date'    => $this->publication_date,
			'publication_year'    => $this->publication_year,
			'document_number'     => $this->document_number,
			'categories'          => $this->categories,
			'tags'                => $this->tags,
			'custom_fields'       => $this->custom_fields,
			'snippet'             => $this->snippet,
			'highlighted_snippet' => $this->highlighted_snippet,
			'matched_page'        => $this->matched_page,
			'access_mode'         => $this->access_mode,
			'view_url'            => $this->view_url,
			'download_url'        => $this->download_url,
			'score'               => $this->score,
		];
	}
}

/**
 * Class SearchResult
 */
class SearchResult {

	/**
	 * Constructor.
	 *
	 * @param array<SearchItem> $items Search items.
	 * @param int               $total Total matched documents.
	 * @param int               $page Current page.
	 * @param int               $per_page Results per page.
	 * @param int               $total_pages Total pages.
	 * @param string            $query Search query.
	 * @param bool              $is_fallback_mode Whether fallback search was used.
	 */
	public function __construct(
		public array $items = [],
		public int $total = 0,
		public int $page = 1,
		public int $per_page = 20,
		public int $total_pages = 1,
		public string $query = '',
		public bool $is_fallback_mode = false
	) {}

	/**
	 * Convert to array for REST response.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'items'            => array_map( static fn( SearchItem $item ): array => $item->to_array(), $this->items ),
			'total'            => $this->total,
			'page'             => $this->page,
			'per_page'         => $this->per_page,
			'total_pages'      => $this->total_pages,
			'query'            => $this->query,
			'is_fallback_mode' => $this->is_fallback_mode,
		];
	}
}
