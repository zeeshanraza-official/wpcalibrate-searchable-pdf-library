<?php
/**
 * Dependency container for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary;

/**
 * Class Container
 *
 * Simple, robust PSR-11-like service locator / container for the plugin.
 */
class Container {

	/**
	 * Registered services and factories.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Resolved singletons.
	 *
	 * @var array<string, object>
	 */
	private array $instances = [];

	/**
	 * Register a service factory or instance.
	 *
	 * @param string $id Service identifier.
	 * @param mixed  $service Service instance or callable factory.
	 * @return self
	 */
	public function set( string $id, mixed $service ): self {
		$this->services[ $id ] = $service;
		unset( $this->instances[ $id ] );
		return $this;
	}

	/**
	 * Get a service instance.
	 *
	 * @param string $id Service identifier.
	 * @return object
	 * @throws \RuntimeException When service is not registered.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Service "%s" is not registered in container.', $id ) );
		}

		$service = $this->services[ $id ];
		if ( is_callable( $service ) ) {
			$this->instances[ $id ] = $service( $this );
		} elseif ( is_object( $service ) ) {
			$this->instances[ $id ] = $service;
		} else {
			throw new \RuntimeException( sprintf( 'Service "%s" must be an object or callable factory.', $id ) );
		}

		return $this->instances[ $id ];
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] ) || isset( $this->instances[ $id ] );
	}
}
