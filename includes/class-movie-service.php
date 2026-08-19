<?php

declare(strict_types=1);

namespace WP_Movie_Showcase;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Movie_Service {
	private const ENDPOINT = 'https://www.omdbapi.com/';

	private const CACHE_GROUP = 'wp_movie_showcase';

	private const CACHE_PREFIX = 'wms_';

	private const CACHE_NAMESPACE = 'v3';

	private const POSITIVE_TTL = 12 * HOUR_IN_SECONDS;

	private const HOT_TTL = 7 * DAY_IN_SECONDS;

	private const SUGGESTION_TTL = 6 * HOUR_IN_SECONDS;

	private const NEGATIVE_TTL = 15 * MINUTE_IN_SECONDS;

	private const TIMEOUT = 5;

	private const HOT_THRESHOLD = 5;

	private const HOT_MAX_HITS = 100;

	private const TYPE_MOVIE = 'movie';

	private const TYPE_NOT_FOUND = 'not_found';

	private const TYPE_SUGGESTIONS = 'suggestions';

	private const MOVIE_FIELDS = array(
		'title',
		'year',
		'rated',
		'runtime',
		'genre',
		'director',
		'plot',
		'poster',
		'imdb_id',
		'imdb_rating',
	);

	private const SUGGESTION_FIELDS = array(
		'imdb_id',
		'title',
		'year',
		'type',
		'poster',
	);

	private string $api_key;

	private string $cache_namespace;

	private array $request_cache = array();

	private array $hot_cache_updates = array();

	public function __construct( string $api_key ) {
		$this->api_key        = trim( $api_key );
		$this->cache_namespace = $this->build_cache_namespace();
	}

	public function search_movie( string $title ) {
		$title = $this->clean_text( $title );

		if ( '' === $title || $this->string_length( $title ) > 200 ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'A valid movie title is required.', 'wp-movie-showcase' )
			);
		}

		if ( '' === $this->api_key ) {
			return new WP_Error(
				'wp_movie_showcase_missing_api_key',
				\__( 'The movie service is not configured.', 'wp-movie-showcase' )
			);
		}

		$key    = $this->movie_title_key( $title );
		$cached = $this->get( $key );

		if ( null !== $cached ) {
			return $this->cached_result( $cached );
		}

		return $this->request_movie(
			array(
				't'    => $title,
				'plot' => 'short',
			),
			$key
		);
	}

	public function search_movie_by_id( string $imdb_id ) {
		$imdb_id = $this->normalize_imdb_id( $imdb_id );

		if ( '' === $imdb_id ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_imdb_id',
				\__( 'A valid IMDb ID is required.', 'wp-movie-showcase' )
			);
		}

		if ( '' === $this->api_key ) {
			return new WP_Error(
				'wp_movie_showcase_missing_api_key',
				\__( 'The movie service is not configured.', 'wp-movie-showcase' )
			);
		}

		$key    = $this->movie_id_key( $imdb_id );
		$cached = $this->get( $key );

		if ( null !== $cached ) {
			return $this->cached_result( $cached );
		}

		return $this->request_movie(
			array(
				'i'    => $imdb_id,
				'plot' => 'short',
			),
			$key
		);
	}

	public function search_titles( string $query ) {
		$query  = $this->clean_text( $query );
		$length = $this->string_length( $query );

		if ( $length < 3 || $length > 200 ) {
			return array();
		}

		if ( '' === $this->api_key ) {
			return new WP_Error(
				'wp_movie_showcase_missing_api_key',
				\__( 'The movie service is not configured.', 'wp-movie-showcase' )
			);
		}

		$key    = 'sg:' . $this->normalize_title( $query );
		$cached = $this->get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		return $this->request_suggestions( $query, $key );
	}

	private function request_movie( array $params, string $key ) {
		$data = $this->request_data( $params );

		if ( \is_wp_error( $data ) ) {
			return $data;
		}

		if ( 'False' === $data['Response'] ) {
			$error = $this->omdb_error( $data );

			if ( 'wp_movie_showcase_not_found' === $error->get_error_code() ) {
				$this->set( $key, false, self::NEGATIVE_TTL, self::TYPE_NOT_FOUND );
			}

			return $error;
		}

		$movie = $this->normalize_movie( $data );

		if ( null === $movie ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_response',
				\__( 'The movie service returned invalid data.', 'wp-movie-showcase' )
			);
		}

		$this->cache_movie( $movie, $key, self::POSITIVE_TTL );

		return $movie;
	}

	private function request_suggestions( string $query, string $key ) {
		$data = $this->request_data(
			array(
				's'    => $query,
				'page' => 1,
			)
		);

		if ( \is_wp_error( $data ) ) {
			return $data;
		}

		if ( 'False' === $data['Response'] ) {
			$error = $this->omdb_error( $data );

			if ( 'wp_movie_showcase_not_found' !== $error->get_error_code() ) {
				return $error;
			}

			$this->set( $key, array(), self::NEGATIVE_TTL, self::TYPE_SUGGESTIONS );

			return array();
		}

		if ( ! isset( $data['Search'] ) || ! is_array( $data['Search'] ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_response',
				\__( 'The movie service returned invalid data.', 'wp-movie-showcase' )
			);
		}

		$suggestions = array();

		foreach ( $data['Search'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$suggestion = $this->normalize_suggestion( $item );

			if ( null === $suggestion ) {
				continue;
			}

			$suggestions[] = $suggestion;

			if ( count( $suggestions ) >= 5 ) {
				break;
			}
		}

		$this->set(
			$key,
			$suggestions,
			empty( $suggestions ) ? self::NEGATIVE_TTL : self::SUGGESTION_TTL,
			self::TYPE_SUGGESTIONS
		);

		return $suggestions;
	}

	private function request_data( array $params ) {
		$response = \wp_safe_remote_get(
			\add_query_arg(
				array_merge(
					array(
						'apikey' => $this->api_key,
						'r'      => 'json',
					),
					$params
				),
				self::ENDPOINT
			),
			array( 'timeout' => self::TIMEOUT )
		);

		if ( \is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_movie_showcase_remote_error',
				\__( 'The movie service is currently unavailable.', 'wp-movie-showcase' )
			);
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_api_key',
				\__( 'The movie service is not configured correctly.', 'wp-movie-showcase' )
			);
		}

		if ( 200 !== $status ) {
			$error_code = 429 === $status
				? 'wp_movie_showcase_service_error'
				: 'wp_movie_showcase_http_error';

			return new WP_Error(
				$error_code,
				\__( 'The movie service is currently unavailable.', 'wp-movie-showcase' )
			);
		}

		$body = \wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_response',
				\__( 'The movie service returned invalid data.', 'wp-movie-showcase' )
			);
		}

		if ( ! isset( $data['Response'] ) || ! is_string( $data['Response'] ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_response',
				\__( 'The movie service returned invalid data.', 'wp-movie-showcase' )
			);
		}

		return $data;
	}

	private function omdb_error( array $data ): WP_Error {
		$error = isset( $data['Error'] ) && is_string( $data['Error'] )
			? strtolower( $data['Error'] )
			: '';

		if ( false !== strpos( $error, 'not found' ) ) {
			return new WP_Error(
				'wp_movie_showcase_not_found',
				\__( 'Movie not found.', 'wp-movie-showcase' )
			);
		}

		if ( false !== strpos( $error, 'api key' ) || false !== strpos( $error, 'authentication' ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_api_key',
				\__( 'The movie service is not configured correctly.', 'wp-movie-showcase' )
			);
		}

		if ( false !== strpos( $error, 'request limit reached' ) ) {
			return new WP_Error(
				'wp_movie_showcase_service_error',
				\__( 'The movie service is currently unavailable.', 'wp-movie-showcase' )
			);
		}

		return new WP_Error(
			'wp_movie_showcase_api_error',
			\__( 'The movie service could not complete the request.', 'wp-movie-showcase' )
		);
	}

	private function normalize_movie( array $data ): ?array {
		$movie = array(
			'title'       => $this->text( $data['Title'] ?? '' ),
			'year'        => $this->text( $data['Year'] ?? '' ),
			'rated'       => $this->text( $data['Rated'] ?? '' ),
			'runtime'     => $this->text( $data['Runtime'] ?? '' ),
			'genre'       => $this->text( $data['Genre'] ?? '' ),
			'director'    => $this->text( $data['Director'] ?? '' ),
			'plot'        => $this->text( $data['Plot'] ?? '' ),
			'poster'      => $this->poster( $data['Poster'] ?? '' ),
			'imdb_id'     => $this->normalize_imdb_id( $data['imdbID'] ?? '' ),
			'imdb_rating' => $this->text( $data['imdbRating'] ?? '' ),
		);

		return $this->is_valid_movie( $movie ) ? $movie : null;
	}

	private function normalize_suggestion( array $data ): ?array {
		$suggestion = array(
			'imdb_id' => $this->normalize_imdb_id( $data['imdbID'] ?? '' ),
			'title'   => $this->text( $data['Title'] ?? '' ),
			'year'    => $this->text( $data['Year'] ?? '' ),
			'type'    => $this->text( $data['Type'] ?? '' ),
			'poster'  => $this->poster( $data['Poster'] ?? '' ),
		);

		return $this->is_valid_suggestion( $suggestion ) ? $suggestion : null;
	}

	private function cached_result( $cached ) {
		if ( false === $cached ) {
			return new WP_Error(
				'wp_movie_showcase_not_found',
				\__( 'Movie not found.', 'wp-movie-showcase' )
			);
		}

		return $cached;
	}

	private function get( string $key ) {
		if ( array_key_exists( $key, $this->request_cache ) ) {
			return $this->request_cache[ $key ];
		}

		$envelope = $this->get_cached_value( $key );

		if ( null === $envelope ) {
			return null;
		}

		$value = $envelope['value'];

		$this->request_cache[ $key ] = $value;
		$this->maybe_promote_hot_cache( $key, $envelope );

		return $value;
	}

	private function set( string $key, $value, int $ttl, string $type ): void {
		$envelope = array(
			'type'  => $type,
			'value' => $value,
		);

		if ( self::TYPE_MOVIE === $type ) {
			$envelope['hits'] = 0;
			$envelope['hot']  = false;
		}

		$this->request_cache[ $key ] = $value;
		$this->cache_value( $key, $envelope, $ttl );
	}

	private function cache_movie( array $movie, string $primary_key, int $ttl ): void {
		foreach ( $this->movie_cache_keys( $movie, $primary_key ) as $key ) {
			$this->set( $key, $movie, $ttl, self::TYPE_MOVIE );
		}
	}

	private function cache_hot_movie( string $primary_key, array $envelope ): void {
		$movie = $envelope['value'];

		foreach ( $this->movie_cache_keys( $movie, $primary_key ) as $key ) {
			$this->request_cache[ $key ]     = $movie;
			$this->hot_cache_updates[ $key ] = true;
			$this->cache_value( $key, $envelope, self::HOT_TTL );
		}
	}

	private function movie_cache_keys( array $movie, string $primary_key ): array {
		$keys = array(
			$primary_key,
			$this->movie_title_key( $movie['title'] ),
			$this->movie_id_key( $movie['imdb_id'] ),
		);

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	private function delete( string $key ): void {
		unset( $this->request_cache[ $key ], $this->hot_cache_updates[ $key ] );

		$cache_key = $this->cache_key( $key );

		if ( \wp_using_ext_object_cache() ) {
			\wp_cache_delete( $cache_key, self::CACHE_GROUP );
			return;
		}

		\delete_transient( $cache_key );
	}

	private function get_cached_value( string $key ) {
		$cache_key = $this->cache_key( $key );
		$data      = null;

		if ( \wp_using_ext_object_cache() ) {
			$found = false;
			$data  = \wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

			if ( ! $found ) {
				return null;
			}
		} else {
			$data = \get_transient( $cache_key );

			if ( false === $data ) {
				return null;
			}
		}

		if ( ! $this->is_valid_cache_envelope( $data ) ) {
			$this->delete( $key );
			return null;
		}

		if ( self::TYPE_MOVIE === $data['type'] ) {
			$data['hits'] = isset( $data['hits'] ) && is_int( $data['hits'] )
				? min( self::HOT_MAX_HITS, max( 0, $data['hits'] ) )
				: 0;
			$data['hot']  = isset( $data['hot'] ) && is_bool( $data['hot'] )
				? $data['hot']
				: false;
		}

		return $data;
	}

	private function cache_value( string $key, $value, int $ttl ): void {
		$cache_key = $this->cache_key( $key );
		$ttl       = max( 300, $ttl );

		if ( \wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Fixed TTL.
			\wp_cache_set( $cache_key, $value, self::CACHE_GROUP, $ttl );
			return;
		}

		\set_transient( $cache_key, $value, $ttl );
	}

	private function cache_key( string $key ): string {
		return self::CACHE_PREFIX . substr(
			hash( 'sha256', self::CACHE_NAMESPACE . ':' . $this->cache_namespace . ':' . $key ),
			0,
			32
		);
	}

	private function build_cache_namespace(): string {
		if ( '' === $this->api_key ) {
			return 'nokey';
		}

		return substr( hash( 'sha256', $this->api_key ), 0, 8 );
	}

	private function is_valid_cache_envelope( $data ): bool {
		if (
			! is_array( $data ) ||
			! isset( $data['type'] ) ||
			! is_string( $data['type'] ) ||
			! array_key_exists( 'value', $data )
		) {
			return false;
		}

		switch ( $data['type'] ) {
			case self::TYPE_MOVIE:
				if ( ! $this->is_valid_movie( $data['value'] ) ) {
					return false;
				}

				if ( isset( $data['hits'] ) && ! is_int( $data['hits'] ) ) {
					return false;
				}

				return ! isset( $data['hot'] ) || is_bool( $data['hot'] );
			case self::TYPE_NOT_FOUND:
				return false === $data['value'];
			case self::TYPE_SUGGESTIONS:
				return $this->is_valid_suggestions( $data['value'] );
		}

		return false;
	}

	private function is_valid_movie( $movie ): bool {
		if ( ! $this->has_exact_string_fields( $movie, self::MOVIE_FIELDS ) ) {
			return false;
		}

		if ( '' === $movie['title'] || '' === $this->normalize_imdb_id( $movie['imdb_id'] ) ) {
			return false;
		}

		return $this->is_valid_poster( $movie['poster'] );
	}

	private function is_valid_suggestions( $suggestions ): bool {
		if ( ! is_array( $suggestions ) || count( $suggestions ) > 5 ) {
			return false;
		}

		foreach ( $suggestions as $suggestion ) {
			if ( ! $this->is_valid_suggestion( $suggestion ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_valid_suggestion( $suggestion ): bool {
		if ( ! $this->has_exact_string_fields( $suggestion, self::SUGGESTION_FIELDS ) ) {
			return false;
		}

		if ( '' === $suggestion['title'] || '' === $this->normalize_imdb_id( $suggestion['imdb_id'] ) ) {
			return false;
		}

		return $this->is_valid_poster( $suggestion['poster'] );
	}

	private function has_exact_string_fields( $value, array $fields ): bool {
		if ( ! is_array( $value ) || count( $value ) !== count( $fields ) ) {
			return false;
		}

		if ( array_diff( $fields, array_keys( $value ) ) || array_diff( array_keys( $value ), $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			if ( ! is_string( $value[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_valid_poster( string $poster ): bool {
		if ( '' === $poster ) {
			return true;
		}

		return $poster === \esc_url_raw( $poster, array( 'https' ) );
	}

	private function maybe_promote_hot_cache( string $key, array $envelope ): void {
		if ( self::TYPE_MOVIE !== $envelope['type'] || array_key_exists( $key, $this->hot_cache_updates ) ) {
			return;
		}

		if ( ! empty( $envelope['hot'] ) ) {
			$this->hot_cache_updates[ $key ] = true;
			return;
		}

		$hits      = isset( $envelope['hits'] ) && is_int( $envelope['hits'] )
			? min( self::HOT_MAX_HITS, max( 0, $envelope['hits'] ) )
			: 0;
		$next_hits = min( self::HOT_MAX_HITS, $hits + 1 );

		$this->hot_cache_updates[ $key ] = true;

		if ( $next_hits >= self::HOT_THRESHOLD ) {
			$envelope['hits'] = $next_hits;
			$envelope['hot']  = true;
			$this->cache_hot_movie( $key, $envelope );
			return;
		}

		$envelope['hits'] = $next_hits;
		$this->cache_value( $key, $envelope, self::POSITIVE_TTL );
	}

	private function movie_title_key( string $title ): string {
		$title = $this->normalize_title( $title );

		return '' === $title ? '' : 'mt:' . $title;
	}

	private function movie_id_key( string $imdb_id ): string {
		$imdb_id = $this->normalize_imdb_id( $imdb_id );

		return '' === $imdb_id ? '' : 'mi:' . $imdb_id;
	}

	private function clean_text( string $value ): string {
		$value = \sanitize_text_field( \wp_strip_all_tags( $value ) );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	private function normalize_title( string $title ): string {
		$title = $this->clean_text( $title );

		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $title, 'UTF-8' )
			: strtolower( $title );
	}

	private function normalize_imdb_id( $imdb_id ): string {
		if ( ! is_scalar( $imdb_id ) ) {
			return '';
		}

		$imdb_id = strtolower( $this->clean_text( (string) $imdb_id ) );

		return 1 === preg_match( '/^tt\d{5,12}$/', $imdb_id ) ? $imdb_id : '';
	}

	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' )
			? mb_strlen( $value, 'UTF-8' )
			: strlen( $value );
	}

	private function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = \sanitize_text_field( \wp_strip_all_tags( (string) $value ) );

		return 'N/A' === $value ? '' : $value;
	}

	private function poster( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = \esc_url_raw( (string) $value, array( 'https' ) );

		return '' === $value || 'N/A' === $value ? '' : $value;
	}
}
