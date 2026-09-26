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
	private const CACHE_SCHEMA_VERSION = 4;
	private const CACHE_GENERATION_OPTION = 'wp_movie_showcase_cache_generation';
	private const INVALIDATION_OPTION = 'wp_movie_showcase_invalidation_revision';

	private const POSITIVE_TTL = 12 * HOUR_IN_SECONDS;

	private const HOT_TTL = 7 * DAY_IN_SECONDS;

	private const SUGGESTION_TTL = 6 * HOUR_IN_SECONDS;

	private const NEGATIVE_TTL = 15 * MINUTE_IN_SECONDS;

	private const TIMEOUT = 5;

	private const HOT_THRESHOLD = 5;

	private const HOT_MAX_HITS = 100;
	private const MOVIE_STALE_TTL = 24 * HOUR_IN_SECONDS;
	private const SUGGESTION_STALE_TTL = HOUR_IN_SECONDS;
	private const REFRESH_LOCK_TTL = 2 * MINUTE_IN_SECONDS;
	private const EXECUTION_LOCK_PREFIX = 'execution:';

	public const OPERATION_TITLE = 'movie_title';
	public const OPERATION_ID = 'movie_id';
	public const OPERATION_SUGGESTIONS = 'suggestions';

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
	private Cache_Lock $lock;
	private $scheduler;
	private $clock;
	private string $last_cache_status = 'MISS';
	private ?array $pending_writes = null;
	private string $memory_revision = '';
	private ?int $execution_deadline = null;
	private ?array $refresh_entry = null;

	private static function read_revision( string $name ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A concurrency fence must bypass request-local and persistent option caches.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( '' !== $wpdb->last_error ) {
			throw new Cache_Coordination_Exception( 'Could not read the movie cache revision.' );
		}
		return (string) $value;
	}

	private function fetch_and_publish( string $key, callable $fetch ) {
		try {
			return $this->fetch_with_snapshot( $key, $fetch );
		} catch ( Cache_Coordination_Exception $error ) {
			return $this->coordination_error();
		}
	}

	private function coordination_error(): WP_Error {
		return new WP_Error( 'wp_movie_showcase_service_error', \__( 'The movie service is currently unavailable.', 'wp-movie-showcase' ) );
	}

	private function fetch_with_snapshot( string $key, callable $fetch ) {
		$snapshot = $this->lock->synchronize( function () use ( $key ): ?array {
			$entry = $this->get_cached_value( $key );
			if ( null !== $this->execution_deadline
				&& ( $entry !== $this->refresh_entry || ! $this->needs_background_refresh( $entry )
					|| $this->now() >= $this->execution_deadline || $this->cache_namespace !== $this->build_cache_namespace() ) ) {
				return null;
			}
			$entries = array( $key => $entry );
			if ( is_array( $entry ) && self::TYPE_MOVIE === $entry['type'] ) {
				foreach ( $this->movie_cache_keys( $entry['value'], $key ) as $alias ) {
					$entries[ $alias ] = $this->get_cached_value( $alias );
				}
			}
			return array( self::read_revision( self::INVALIDATION_OPTION ), $entries );
		} );
		if ( null === $snapshot ) {
			return false;
		}
		$deadline = $this->execution_deadline ?? $this->now() + self::REFRESH_LOCK_TTL;
		$this->pending_writes = array();

		try {
			$result = $fetch();
			$writes = $this->pending_writes;
		} finally {
			foreach ( $this->pending_writes as $write_key => $value ) {
				unset( $this->request_cache[ $write_key ], $this->hot_cache_updates[ $write_key ] );
			}
			$this->pending_writes = null;
		}

		if ( $writes ) {
			$this->lock->synchronize( function () use ( $snapshot, $deadline, $writes ): void {
				if ( $this->now() >= $deadline
					|| $snapshot[0] !== self::read_revision( self::INVALIDATION_OPTION )
					|| $this->cache_namespace !== $this->build_cache_namespace() ) {
					return;
				}
				foreach ( $snapshot[1] as $alias => $entry ) {
					if ( $entry !== $this->get_cached_value( $alias ) ) {
						return;
					}
				}

				// All aliases are published in the same critical section as the fence check.
				foreach ( $writes as $write_key => $value ) {
					if ( ! array_key_exists( $write_key, $snapshot[1] ) && null !== $this->get_cached_value( $write_key ) ) {
						continue;
					}
					$this->cache_value( $write_key, $value );
					$this->request_cache[ $write_key ] = $value;
				}
				$this->memory_revision = $snapshot[0];
			} );
		}

		return $result;
	}

	public function __construct( string $api_key, ?callable $scheduler = null, ?Cache_Lock $lock = null, ?callable $clock = null ) {
		$this->api_key        = trim( $api_key );
		$this->cache_namespace = $this->build_cache_namespace( false );
		$this->scheduler       = $scheduler;
		$this->clock           = $clock;
		$this->lock            = $lock ?? new Cache_Lock( $clock );
	}

	public function get_last_cache_status(): string {
		return $this->last_cache_status;
	}

	public function invalidate_movie( string $title = '', string $imdb_id = '' ): void {
		$this->lock->synchronize( function () use ( $title, $imdb_id ): void {
			self::advance_invalidation_revision();
			$this->invalidate_movie_entries( $title, $imdb_id );
		} );
	}

	private function invalidate_movie_entries( string $title, string $imdb_id ): void {
		$this->cache_namespace = $this->build_cache_namespace();
		$provided_keys = array_filter( array( $this->movie_title_key( $title ), $this->movie_id_key( $imdb_id ) ) );
		$keys          = $provided_keys;

		foreach ( $provided_keys as $key ) {
			$envelope = $this->get_cached_value( $key );

			if ( is_array( $envelope ) && self::TYPE_MOVIE === $envelope['type'] ) {
				$keys = array_merge( $keys, $this->movie_cache_keys( $envelope['value'], $key ) );
				break;
			}
		}

		foreach ( array_unique( $keys ) as $key ) {
			$this->delete( $key );
		}
	}

	public function invalidate_search( string $query ): void {
		$query = $this->normalize_title( $query );

		if ( '' !== $query ) {
			$this->lock->synchronize( function () use ( $query ): void {
				self::advance_invalidation_revision();
				$this->cache_namespace = $this->build_cache_namespace();
				$this->delete( 'sg:' . $query );
			} );
		}
	}

	public static function invalidate_namespace(): void {
		( new Cache_Lock() )->synchronize( static function (): void {
			self::advance_invalidation_revision();
			$generation = max( 1, (int) self::read_revision( self::CACHE_GENERATION_OPTION ) );
			if ( ! \update_option( self::CACHE_GENERATION_OPTION, $generation + 1, false ) ) {
				throw new Cache_Coordination_Exception( 'Could not invalidate the movie cache namespace.' );
			}
		} );
	}

	private static function advance_invalidation_revision(): void {
		if ( ! \update_option( self::INVALIDATION_OPTION, \wp_generate_uuid4(), false ) ) {
			throw new Cache_Coordination_Exception( 'Could not advance the movie cache invalidation revision.' );
		}
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
		$cached = $this->get( $key, self::OPERATION_TITLE, $title );

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
		$cached = $this->get( $key, self::OPERATION_ID, $imdb_id );

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
		$cached = $this->get( $key, self::OPERATION_SUGGESTIONS, $query );

		if ( is_array( $cached ) || \is_wp_error( $cached ) ) {
			return $cached;
		}

		return $this->request_suggestions( $query, $key );
	}

	private function request_movie( array $params, string $key ) {
		return $this->fetch_and_publish( $key, function () use ( $params, $key ) {
			return $this->fetch_movie( $params, $key );
		} );
	}

	private function fetch_movie( array $params, string $key ) {
		$data = $this->request_data( $params );

		if ( \is_wp_error( $data ) ) {
			return $data;
		}

		if ( 'False' === $data['Response'] ) {
			$error = $this->omdb_error( $data );

			if ( 'wp_movie_showcase_not_found' === $error->get_error_code() ) {
				$this->set( $key, false, self::NEGATIVE_TTL, self::TYPE_NOT_FOUND, 0 );
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
		return $this->fetch_and_publish( $key, function () use ( $query, $key ) {
			return $this->fetch_suggestions( $query, $key );
		} );
	}

	private function fetch_suggestions( string $query, string $key ) {
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

			$this->set( $key, array(), self::NEGATIVE_TTL, self::TYPE_SUGGESTIONS, 0 );

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
			self::TYPE_SUGGESTIONS,
			empty( $suggestions ) ? 0 : self::SUGGESTION_STALE_TTL
		);

		return $suggestions;
	}

	/**
	 * Refreshes one known operation without reading the cache first.
	 */
	public function refresh( string $operation, string $argument ) {
		$this->trace( 'SWR_REFRESH' );

		switch ( $operation ) {
			case self::OPERATION_TITLE:
				$title = $this->clean_text( $argument );
				return $this->request_movie( array( 't' => $title, 'plot' => 'short' ), $this->movie_title_key( $title ) );
			case self::OPERATION_ID:
				$imdb_id = $this->normalize_imdb_id( $argument );
				return $this->request_movie( array( 'i' => $imdb_id, 'plot' => 'short' ), $this->movie_id_key( $imdb_id ) );
			case self::OPERATION_SUGGESTIONS:
				$query = $this->clean_text( $argument );
				return $this->request_suggestions( $query, 'sg:' . $this->normalize_title( $query ) );
		}

		return new WP_Error( 'wp_movie_showcase_invalid_refresh', 'Invalid refresh operation.' );
	}

	/**
	 * Refreshes a scheduled stale entry only when its shared state still requires it.
	 */
	public function refresh_if_needed( string $operation, string $argument, string $scheduled_cache_key ) {
		$key = $this->refresh_key( $operation, $argument );

		if ( null === $key || ! hash_equals( $this->cache_key( $key ), $scheduled_cache_key ) ) {
			return false;
		}

		$envelope = $this->get_cached_value( $key );

		if ( ! $this->needs_background_refresh( $envelope ) ) {
			return false;
		}

		$deadline        = $this->now() + self::REFRESH_LOCK_TTL;
		$execution_key   = self::EXECUTION_LOCK_PREFIX . $scheduled_cache_key;
		$execution_token = $this->lock->acquire( $execution_key, self::REFRESH_LOCK_TTL );

		if ( null === $execution_token ) {
			$this->trace( 'SWR_EXECUTION_LOCKED' );
			return false;
		}

		// Another worker may have refreshed after our first read but before lock acquisition.
		$envelope = $this->get_cached_value( $key );

		if ( ! $this->needs_background_refresh( $envelope ) ) {
			$this->lock->release( $execution_key, $execution_token );
			return false;
		}

		$this->execution_deadline = $deadline;
		$this->refresh_entry = $envelope;
		try {
			$result = $this->refresh( $operation, $argument );
		} finally {
			$this->execution_deadline = null;
			$this->refresh_entry = null;
		}

		if ( ! \is_wp_error( $result ) ) {
			$this->lock->release( $execution_key, $execution_token );
		}

		return $result;
	}

	public function release_refresh_lock( string $cache_key, string $token ): void {
		$this->lock->release( $cache_key, $token );
	}

	private function request_data( array $params ) {
		$this->trace( 'UPSTREAM_FETCH' );
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

	private function get( string $key, string $operation, string $argument ) {
		try {
			return $this->lock->synchronize( function () use ( $key, $operation, $argument ) {
				return $this->get_current( $key, $operation, $argument );
			} );
		} catch ( Cache_Coordination_Exception $error ) {
			return $this->coordination_error();
		}
	}

	private function get_current( string $key, string $operation, string $argument ) {
		$revision = self::read_revision( self::INVALIDATION_OPTION );
		$namespace = $this->build_cache_namespace();
		if ( $revision !== $this->memory_revision || $namespace !== $this->cache_namespace ) {
			$this->request_cache = array();
			$this->hot_cache_updates = array();
			$this->memory_revision = $revision;
			$this->cache_namespace = $namespace;
		}
		if ( array_key_exists( $key, $this->request_cache ) ) {
			$this->set_status( 'MEMORY_HIT' );
			return $this->request_cache[ $key ]['value'];
		}

		$envelope = $this->get_cached_value( $key );

		if ( null === $envelope ) {
			$this->set_status( 'CACHE_MISS' );
			return null;
		}

		$now = $this->now();

		if ( $now > $envelope['stale_until'] ) {
			$this->delete( $key );
			$this->set_status( 'CACHE_MISS' );
			return null;
		}

		$this->request_cache[ $key ] = $envelope;

		if ( $now <= $envelope['fresh_until'] ) {
			$this->set_status( false === $envelope['value'] || array() === $envelope['value'] ? 'NEGATIVE_HIT' : 'CACHE_FRESH' );
			$this->maybe_promote_hot_cache( $key, $envelope );
			return $envelope['value'];
		}

		$this->set_status( 'CACHE_STALE' );
		$this->schedule_stale_refresh( $key, $operation, $argument );

		return $envelope['value'];
	}

	private function set( string $key, $value, int $ttl, string $type, int $stale_ttl ): void {
		$now      = $this->now();
		$envelope = array(
			'revision'    => \wp_generate_uuid4(),
			'schema'       => self::CACHE_SCHEMA_VERSION,
			'type'         => $type,
			'value'        => $value,
			'cached_at'    => $now,
			'fresh_until'  => $now + $ttl,
			'stale_until'  => $now + $ttl + max( 0, $stale_ttl ),
		);

		if ( self::TYPE_MOVIE === $type ) {
			$envelope['hits'] = 0;
			$envelope['hot']  = false;
		}

		$this->request_cache[ $key ] = $envelope;
		$this->cache_value( $key, $envelope );
	}

	private function cache_movie( array $movie, string $primary_key, int $ttl ): void {
		foreach ( $this->movie_cache_keys( $movie, $primary_key ) as $key ) {
			$this->set( $key, $movie, $ttl, self::TYPE_MOVIE, self::MOVIE_STALE_TTL );
		}
	}

	private function cache_hot_movie( string $primary_key, array $envelope ): void {
		$movie                    = $envelope['value'];
		$now                      = $this->now();
		$envelope['cached_at']    = $now;
		$envelope['fresh_until']  = $now + self::HOT_TTL;
		$envelope['stale_until']  = $now + self::HOT_TTL + self::MOVIE_STALE_TTL;

		foreach ( $this->movie_cache_keys( $movie, $primary_key ) as $key ) {
			$this->request_cache[ $key ]     = $envelope;
			$this->hot_cache_updates[ $key ] = true;
			$this->cache_value( $key, $envelope );
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
			$data  = \wp_cache_get( $cache_key, self::CACHE_GROUP, true, $found );

			if ( ! $found ) {
				return null;
			}
		} else {
			// Discard request-local option snapshots before checking another worker's publication.
			\wp_cache_delete( '_transient_' . $cache_key, 'options' );
			\wp_cache_delete( '_transient_timeout_' . $cache_key, 'options' );
			\wp_cache_delete( 'notoptions', 'options' );
			$data = \get_transient( $cache_key );

			if ( false === $data ) {
				return null;
			}
		}

		if ( ! $this->is_valid_cache_envelope( $data ) ) {
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

	private function cache_value( string $key, array $value ): void {
		if ( null !== $this->pending_writes ) {
			$this->pending_writes[ $key ] = $value;
			return;
		}
		$cache_key = $this->cache_key( $key );
		$ttl       = max( 300, $value['stale_until'] - $this->now() );

		if ( \wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Fixed TTL.
			\wp_cache_set( $cache_key, $value, self::CACHE_GROUP, $ttl );
			return;
		}

		\set_transient( $cache_key, $value, $ttl );
	}

	private function cache_key( string $key ): string {
		return self::CACHE_PREFIX . substr(
			hash( 'sha256', self::CACHE_NAMESPACE . ':' . self::CACHE_SCHEMA_VERSION . ':' . $this->cache_namespace . ':' . $key ),
			0,
			32
		);
	}

	private function build_cache_namespace( bool $fresh = true ): string {
		$generation = max( 1, (int) ( $fresh ? self::read_revision( self::CACHE_GENERATION_OPTION ) : \get_option( self::CACHE_GENERATION_OPTION, 1 ) ) );

		if ( '' === $this->api_key ) {
			return 'nokey:' . $generation;
		}

		return substr( hash( 'sha256', $this->api_key ), 0, 8 ) . ':' . $generation;
	}

	private function is_valid_cache_envelope( $data ): bool {
		if (
			! is_array( $data ) ||
			! isset( $data['schema'] ) ||
			self::CACHE_SCHEMA_VERSION !== $data['schema'] ||
			! isset( $data['type'] ) ||
			! is_string( $data['type'] ) ||
			! array_key_exists( 'value', $data ) ||
			! isset( $data['cached_at'], $data['fresh_until'], $data['stale_until'] ) ||
			! is_int( $data['cached_at'] ) ||
			! is_int( $data['fresh_until'] ) ||
			! is_int( $data['stale_until'] ) ||
			$data['cached_at'] > $data['fresh_until'] ||
			$data['fresh_until'] > $data['stale_until']
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
		$now                     = $this->now();
		$envelope['cached_at']   = $now;
		$envelope['fresh_until'] = $now + self::POSITIVE_TTL;
		$envelope['stale_until'] = $now + self::POSITIVE_TTL + self::MOVIE_STALE_TTL;
		$this->request_cache[ $key ] = $envelope;
		$this->cache_value( $key, $envelope );
	}

	private function schedule_stale_refresh( string $key, string $operation, string $argument ): void {
		$lock_key = $this->cache_key( $key );
		$token    = $this->lock->acquire( $lock_key, self::REFRESH_LOCK_TTL );

		if ( null === $token ) {
			$this->trace( 'SWR_LOCKED' );
			return;
		}

		if ( ! is_callable( $this->scheduler ) || true !== ( $this->scheduler )( $operation, $argument, $lock_key, $token ) ) {
			$this->lock->release( $lock_key, $token );
			return;
		}

		$this->trace( 'SWR_SCHEDULED' );
	}

	private function refresh_key( string $operation, string $argument ): ?string {
		switch ( $operation ) {
			case self::OPERATION_TITLE:
				return $this->movie_title_key( $this->clean_text( $argument ) );
			case self::OPERATION_ID:
				return $this->movie_id_key( $this->normalize_imdb_id( $argument ) );
			case self::OPERATION_SUGGESTIONS:
				$query = $this->normalize_title( $argument );
				return '' === $query ? null : 'sg:' . $query;
		}

		return null;
	}

	private function needs_background_refresh( $envelope ): bool {
		if ( ! is_array( $envelope ) ) {
			return false;
		}

		$now = $this->now();

		return $now > $envelope['fresh_until'] && $now <= $envelope['stale_until'];
	}

	private function set_status( string $status ): void {
		$this->last_cache_status = $status;
		$this->trace( $status );
	}

	private function trace( string $event ): void {
		$debug = ( defined( 'WP_MOVIE_SHOWCASE_CACHE_DEBUG' ) && WP_MOVIE_SHOWCASE_CACHE_DEBUG ) ||
			( defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( $debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicit opt-in cache diagnostics.
			error_log( 'WP Movie Showcase cache: ' . $event );
		}
	}

	private function now(): int {
		return is_callable( $this->clock ) ? (int) ( $this->clock )() : time();
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
