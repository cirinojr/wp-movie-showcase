<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['wms_now']        = 1700000000;
$GLOBALS['wms_transients'] = array();
$GLOBALS['wms_options']    = array();
$GLOBALS['wms_remote']     = array();
$GLOBALS['wms_calls']      = 0;
$GLOBALS['wms_delay_us']   = 0;
$GLOBALS['wms_ext_cache']  = false;
$GLOBALS['wms_object_cache'] = array();
$GLOBALS['wms_get_option_hook'] = null;

final class WMS_Test_WPDB {
	public string $options = 'wp_options';

	public function update( string $table, array $data, array $where ): int {
		$name = $where['option_name'];

		if ( ! array_key_exists( $name, $GLOBALS['wms_options'] ) ) {
			return 0;
		}

		$current = maybe_serialize( $GLOBALS['wms_options'][ $name ] );

		if ( $current !== $where['option_value'] ) {
			return 0;
		}

		$GLOBALS['wms_options'][ $name ] = unserialize( $data['option_value'] );
		return 1;
	}

	public function delete( string $table, array $where ): int {
		$name = $where['option_name'];

		if ( ! array_key_exists( $name, $GLOBALS['wms_options'] ) ) {
			return 0;
		}

		if ( maybe_serialize( $GLOBALS['wms_options'][ $name ] ) !== $where['option_value'] ) {
			return 0;
		}

		unset( $GLOBALS['wms_options'][ $name ] );
		return 1;
	}
}

$GLOBALS['wpdb'] = new WMS_Test_WPDB();

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function __( string $message ): string {
	return $message;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function wp_strip_all_tags( $value ): string {
	return strip_tags( (string) $value );
}

function esc_url_raw( $url, $protocols = null ): string {
	$url = filter_var( (string) $url, FILTER_VALIDATE_URL );

	return false === $url || 0 !== strpos( $url, 'https://' ) ? '' : $url;
}

function wp_using_ext_object_cache(): bool {
	return $GLOBALS['wms_ext_cache'];
}

function wp_cache_set( string $key, $value, string $group, int $ttl ): bool {
	$GLOBALS['wms_object_cache'][ $group ][ $key ] = array( 'value' => $value, 'expires' => $GLOBALS['wms_now'] + $ttl );
	return true;
}

function wp_cache_add( string $key, $value, string $group, int $ttl ): bool {
	$existing = wp_cache_get( $key, $group, false, $found );

	if ( $found ) {
		return false;
	}

	return wp_cache_set( $key, $value, $group, $ttl );
}

function wp_cache_get( string $key, string $group, bool $force = false, ?bool &$found = null ) {
	$entry = $GLOBALS['wms_object_cache'][ $group ][ $key ] ?? null;

	if ( null === $entry || $entry['expires'] <= $GLOBALS['wms_now'] ) {
		unset( $GLOBALS['wms_object_cache'][ $group ][ $key ] );
		$found = false;
		return false;
	}

	$found = true;
	return $entry['value'];
}

function wp_cache_delete( string $key, string $group ): bool {
	unset( $GLOBALS['wms_object_cache'][ $group ][ $key ] );
	return true;
}

function set_transient( string $key, $value, int $ttl ): bool {
	$GLOBALS['wms_transients'][ $key ] = array( 'value' => $value, 'expires' => $GLOBALS['wms_now'] + $ttl );
	return true;
}

function get_transient( string $key ) {
	if ( ! isset( $GLOBALS['wms_transients'][ $key ] ) ) {
		return false;
	}

	$entry = $GLOBALS['wms_transients'][ $key ];

	if ( $entry['expires'] <= $GLOBALS['wms_now'] ) {
		unset( $GLOBALS['wms_transients'][ $key ] );
		return false;
	}

	return $entry['value'];
}

function delete_transient( string $key ): bool {
	unset( $GLOBALS['wms_transients'][ $key ] );
	return true;
}

function get_option( string $key, $default = false ) {
	$value = $GLOBALS['wms_options'][ $key ] ?? $default;
	$hook  = $GLOBALS['wms_get_option_hook'];

	if ( is_callable( $hook ) ) {
		$GLOBALS['wms_get_option_hook'] = null;
		$hook( $key, $value );
	}

	return $value;
}

function add_option( string $key, $value ): bool {
	if ( array_key_exists( $key, $GLOBALS['wms_options'] ) ) {
		return false;
	}

	$GLOBALS['wms_options'][ $key ] = $value;
	return true;
}

function update_option( string $key, $value ): bool {
	$GLOBALS['wms_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['wms_options'][ $key ] );
	return true;
}

function wp_generate_uuid4(): string {
	return uniqid( 'test-', true );
}

function maybe_serialize( $value ): string {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}

function add_query_arg( array $params, string $url ): string {
	return $url . '?' . http_build_query( $params );
}

function wp_safe_remote_get() {
	++$GLOBALS['wms_calls'];

	if ( $GLOBALS['wms_delay_us'] > 0 ) {
		usleep( $GLOBALS['wms_delay_us'] );
	}

	return array_shift( $GLOBALS['wms_remote'] );
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['status'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

require_once dirname( __DIR__ ) . '/includes/class-cache-lock.php';
require_once dirname( __DIR__ ) . '/includes/class-movie-service.php';

function wms_reset_state(): void {
	$GLOBALS['wms_now']             = 1700000000;
	$GLOBALS['wms_transients']      = array();
	$GLOBALS['wms_options']         = array();
	$GLOBALS['wms_remote']          = array();
	$GLOBALS['wms_calls']           = 0;
	$GLOBALS['wms_delay_us']        = 0;
	$GLOBALS['wms_ext_cache']       = false;
	$GLOBALS['wms_object_cache']    = array();
	$GLOBALS['wms_get_option_hook'] = null;
}

function wms_clock(): int {
	return $GLOBALS['wms_now'];
}

function wms_movie( string $title = 'The Matrix', string $rating = '8.7', string $imdb_id = 'tt0133093' ): array {
	return array(
		'Response'   => 'True',
		'Title'      => $title,
		'Year'       => '1999',
		'Rated'      => 'R',
		'Runtime'    => '136 min',
		'Genre'      => 'Action, Sci-Fi',
		'Director'   => 'The Wachowskis',
		'Plot'       => 'A computer hacker discovers the truth.',
		'Poster'     => 'https://example.com/poster.jpg',
		'imdbID'     => $imdb_id,
		'imdbRating' => $rating,
	);
}

function wms_queue_response( array $data ): void {
	$GLOBALS['wms_remote'][] = array( 'status' => 200, 'body' => json_encode( $data ) );
}

function wms_service( ?callable $scheduler = null, string $api_key = 'api-key', ?WP_Movie_Showcase\Cache_Lock $lock = null ): WP_Movie_Showcase\Movie_Service {
	return new WP_Movie_Showcase\Movie_Service( $api_key, $scheduler, $lock, 'wms_clock' );
}
