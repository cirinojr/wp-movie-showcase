<?php

declare(strict_types=1);

namespace WP_Movie_Showcase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small cross-request lock backed by the persistent object cache or options.
 */
final class Cache_Lock {
	private const GROUP = 'wp_movie_showcase_locks';

	private const PREFIX = 'wms_lock_';
	private $clock;

	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock;
	}

	public function acquire( string $key, int $ttl ): ?string {
		$ttl   = max( 1, $ttl );
		$token = function_exists( 'wp_generate_uuid4' ) ? \wp_generate_uuid4() : uniqid( 'wms_', true );
		$value = array(
			'token'      => $token,
			'expires_at' => $this->now() + $ttl,
		);
		$name  = $this->name( $key );

		if ( \wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- A refresh mutex must expire quickly after an abandoned worker.
			return \wp_cache_add( $name, $value, self::GROUP, $ttl ) ? $token : null;
		}

		if ( \add_option( $name, $value, '', 'no' ) ) {
			return $token;
		}

		$current = \get_option( $name, null );

		if ( ! is_array( $current ) || ! isset( $current['expires_at'] ) || (int) $current['expires_at'] <= $this->now() ) {
			\delete_option( $name );

			return \add_option( $name, $value, '', 'no' ) ? $token : null;
		}

		return null;
	}

	public function release( string $key, string $token ): void {
		$name = $this->name( $key );

		if ( \wp_using_ext_object_cache() ) {
			$current = \wp_cache_get( $name, self::GROUP );

			if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
				\wp_cache_delete( $name, self::GROUP );
			}

			return;
		}

		$current = \get_option( $name, null );

		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
			\delete_option( $name );
		}
	}

	private function name( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 32 );
	}

	private function now(): int {
		return is_callable( $this->clock ) ? (int) ( $this->clock )() : time();
	}
}
