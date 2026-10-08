<?php
/**
 * Safe snippet generator and search term highlighter for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Search;

/**
 * Class SnippetGenerator
 *
 * Extracts contextual snippets around search keywords and highlights matches safely.
 */
class SnippetGenerator {

	/**
	 * Default maximum length of snippet in characters.
	 */
	public const DEFAULT_SNIPPET_LENGTH = 220;

	/**
	 * Generate contextual snippet around matched terms from text.
	 *
	 * @param string $text Full source text.
	 * @param string $query Search query terms.
	 * @param int    $max_length Max snippet character length.
	 * @return string Contextual snippet with ellipsis.
	 */
	public static function extract_snippet( string $text, string $query, int $max_length = self::DEFAULT_SNIPPET_LENGTH ): string {
		$clean_text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( empty( $clean_text ) ) {
			return '';
		}

		$terms = self::extract_search_terms( $query );
		if ( empty( $terms ) ) {
			if ( mb_strlen( $clean_text ) <= $max_length ) {
				return $clean_text;
			}
			return mb_substr( $clean_text, 0, $max_length ) . '...';
		}

		// Find the position of the first matching term
		$best_pos = false;
		$text_lower = mb_strtolower( $clean_text );

		foreach ( $terms as $term ) {
			$pos = mb_strpos( $text_lower, mb_strtolower( $term ) );
			if ( false !== $pos ) {
				if ( false === $best_pos || $pos < $best_pos ) {
					$best_pos = $pos;
				}
			}
		}

		if ( false === $best_pos ) {
			if ( mb_strlen( $clean_text ) <= $max_length ) {
				return $clean_text;
			}
			return mb_substr( $clean_text, 0, $max_length ) . '...';
		}

		// Calculate start and end offsets to center the match
		$half_length = (int) floor( $max_length / 2 );
		$start_pos   = max( 0, $best_pos - $half_length );

		// Adjust to word boundary if not at the beginning
		if ( $start_pos > 0 ) {
			$space_pos = mb_strpos( $clean_text, ' ', $start_pos );
			if ( false !== $space_pos && $space_pos < $best_pos ) {
				$start_pos = $space_pos + 1;
			}
		}

		$snippet = mb_substr( $clean_text, $start_pos, $max_length );

		// Adjust snippet end to word boundary
		if ( mb_strlen( $clean_text ) > ( $start_pos + $max_length ) ) {
			$last_space = mb_strrpos( $snippet, ' ' );
			if ( false !== $last_space && $last_space > ( $max_length / 2 ) ) {
				$snippet = mb_substr( $snippet, 0, $last_space );
			}
			$snippet .= '...';
		}

		if ( $start_pos > 0 ) {
			$snippet = '...' . $snippet;
		}

		return $snippet;
	}

	/**
	 * Highlight matched terms in snippet safely without allowing XSS.
	 *
	 * Escapes text first, then marks matched keywords.
	 *
	 * @param string $snippet Raw snippet text.
	 * @param string $query Search query terms.
	 * @return string Safe HTML with <mark> tags.
	 */
	public static function highlight( string $snippet, string $query ): string {
		$safe_snippet = esc_html( $snippet );
		$terms        = self::extract_search_terms( $query );

		if ( empty( $terms ) ) {
			return $safe_snippet;
		}

		// Sort terms by length descending to match longest terms first
		usort( $terms, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		foreach ( $terms as $term ) {
			$escaped_term = esc_html( $term );
			if ( '' === $escaped_term ) {
				continue;
			}

			$pattern = '/' . preg_quote( $escaped_term, '/' ) . '/iu';
			$safe_snippet = preg_replace(
				$pattern,
				'<mark class="wpcal-highlight">$0</mark>',
				$safe_snippet
			) ?? $safe_snippet;
		}

		return $safe_snippet;
	}

	/**
	 * Extract individual search tokens/terms.
	 *
	 * @param string $query Query string.
	 * @return array<string>
	 */
	public static function extract_search_terms( string $query ): array {
		$clean_query = trim( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $query ) ?? '' );
		if ( empty( $clean_query ) ) {
			return [];
		}

		$words = preg_split( '/\s+/u', $clean_query, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return [];
		}

		// Filter out very short words (e.g. 1 char) unless Asian characters
		$terms = [];
		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) > 1 ) {
				$terms[] = $word;
			}
		}

		return array_unique( $terms );
	}
}
