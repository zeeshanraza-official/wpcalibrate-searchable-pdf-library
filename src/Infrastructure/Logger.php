<?php
/**
 * Safe diagnostic logger for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Infrastructure;

/**
 * Class Logger
 *
 * Provides structured diagnostic logging without recording sensitive credentials,
 * license keys, authentication tokens, or private document text.
 */
class Logger {

	/**
	 * Log a message to debug log if WP_DEBUG_LOG is active.
	 *
	 * @param string $level   Log level (debug, info, warning, error).
	 * @param string $message Log message.
	 * @param array<string, mixed> $context Contextual data (will be sanitized of secrets).
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = [] ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$sanitized_context = self::sanitize_context( $context );
		$context_str       = ! empty( $sanitized_context ) ? ' ' . wp_json_encode( $sanitized_context ) : '';
		$formatted_message = sprintf(
			'[WPCalibrate PDF Library][%s] %s%s',
			strtoupper( $level ),
			$message,
			$context_str
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $formatted_message );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public static function debug( string $message, array $context = [] ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Sanitize context array to remove sensitive keys or huge content bodies.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed> Sanitized context.
	 */
	public static function sanitize_context( array $context ): array {
		$sanitized = [];
		$blocked_keys = [ 'license_key', 'api_key', 'token', 'password', 'secret', 'body_text', 'extracted_text' ];

		foreach ( $context as $key => $value ) {
			$lower_key = strtolower( (string) $key );
			if ( in_array( $lower_key, $blocked_keys, true ) ) {
				$sanitized[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = self::sanitize_context( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 500 ) {
				$sanitized[ $key ] = substr( $value, 0, 500 ) . '... [TRUNCATED]';
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}
}
