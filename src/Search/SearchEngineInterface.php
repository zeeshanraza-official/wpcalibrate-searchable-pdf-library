<?php
/**
 * Search engine contract for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Search;

/**
 * Interface SearchEngineInterface
 *
 * Allows interchangeable search backends (MySQL FULLTEXT, fallback LIKE, etc.).
 */
interface SearchEngineInterface {

	/**
	 * Execute search query with filters and sorting.
	 *
	 * @param array<string, mixed> $params Search parameters (query, category, tag, custom_fields, sort, order, page, per_page).
	 * @param int|null             $user_id User ID performing the query (null for current visitor).
	 * @return SearchResult
	 */
	public function search( array $params, ?int $user_id = null ): SearchResult;
}
