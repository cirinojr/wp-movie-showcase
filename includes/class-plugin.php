<?php

declare(strict_types=1);

namespace WP_Movie_Showcase;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private const REFRESH_HOOK = 'wp_movie_showcase_refresh_cache';
	private string $plugin_file;

	private Settings $settings;

	private Movie_Service $movies;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->settings    = new Settings();
		$this->movies      = new Movie_Service( $this->settings->get_api_key(), array( $this, 'schedule_refresh' ) );
	}

	public static function activate(): void {
		if ( false === \get_option( Settings::OPTION_NAME, false ) ) {
			\add_option( Settings::OPTION_NAME, '', '', 'no' );
		}
	}

	public function register(): void {
		\register_activation_hook( $this->plugin_file, array( self::class, 'activate' ) );

		$this->settings->register();

		\add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		\add_action( 'init', array( $this, 'register_block' ) );
		\add_action( self::REFRESH_HOOK, array( $this, 'run_refresh' ), 10, 4 );
	}

	public function register_rest_routes(): void {
		\register_rest_route(
			'wp-movie-showcase/v1',
			'/movies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_movie' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'title' => array(
						'type'              => 'string',
						'minLength'         => 1,
						'maxLength'         => 200,
						'description'       => \__( 'Movie or TV series title.', 'wp-movie-showcase' ),
						'sanitize_callback' => array( $this, 'sanitize_title' ),
					),
					'imdb_id' => array(
						'type'              => 'string',
						'minLength'         => 7,
						'maxLength'         => 14,
						'description'       => \__( 'IMDb identifier for the selected title.', 'wp-movie-showcase' ),
						'validate_callback' => array( $this, 'validate_imdb_id' ),
						'sanitize_callback' => array( $this, 'sanitize_imdb_id' ),
					),
				),
			)
		);

		\register_rest_route(
			'wp-movie-showcase/v1',
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_suggestions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'query' => array(
						'type'              => 'string',
						'minLength'         => 3,
						'maxLength'         => 200,
						'description'       => \__( 'Autocomplete title query.', 'wp-movie-showcase' ),
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_suggestion_query' ),
					),
				),
			)
		);
	}

	public function register_block(): void {
		\register_block_type( WP_MOVIE_SHOWCASE_DIR . 'build' );
	}

	public function sanitize_title( $value ) {
		if ( ! is_scalar( $value ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'A valid movie title is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		$title = \sanitize_text_field( \wp_unslash( (string) $value ) );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) ?? $title );

		if ( '' === $title ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'A valid movie title is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );

		if ( $length > 200 ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'The movie title is too long.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		return $title;
	}

	public function get_movie( WP_REST_Request $request ) {
		$title = $request->get_param( 'title' );
		$imdb_id = $request->get_param( 'imdb_id' );

		if ( $title instanceof WP_Error ) {
			return $title;
		}

		if ( $imdb_id instanceof WP_Error ) {
			return $imdb_id;
		}

		if ( is_string( $imdb_id ) && '' !== $imdb_id ) {
			$movie = $this->movies->search_movie_by_id( $imdb_id );

			if ( \is_wp_error( $movie ) ) {
				return $this->rest_error( $movie );
			}

			return $this->rest_response( $movie );
		}

		if ( ! is_string( $title ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'A valid movie title is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		$movie = $this->movies->search_movie( $title );

		if ( \is_wp_error( $movie ) ) {
			return $this->rest_error( $movie );
		}

		return $this->rest_response( $movie );
	}

	public function search_suggestions( WP_REST_Request $request ) {
		$query = $request->get_param( 'query' );

		if ( $query instanceof WP_Error ) {
			return $query;
		}

		if ( ! is_string( $query ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'A valid movie title is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		$suggestions = $this->movies->search_titles( $query );

		if ( \is_wp_error( $suggestions ) ) {
			return $this->rest_error( $suggestions );
		}

		return $this->rest_response( $suggestions );
	}

	public function schedule_refresh( string $operation, string $argument, string $lock_key, string $token ): bool {
		$result = \wp_schedule_single_event(
			time(),
			self::REFRESH_HOOK,
			array( $operation, $argument, $lock_key, $token ),
			true
		);

		return true === $result;
	}

	public function run_refresh( string $operation, string $argument, string $lock_key, string $token ): void {
		$result = $this->movies->refresh( $operation, $argument );

		if ( ! \is_wp_error( $result ) ) {
			$this->movies->release_refresh_lock( $lock_key, $token );
		}
	}

	public function validate_imdb_id( $value ): bool {
		if ( null === $value || '' === $value ) {
			return true;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return 1 === preg_match( '/^tt\d{5,12}$/', strtolower( (string) $value ) );
	}

	public function sanitize_suggestion_query( $value ) {
		$title = $this->sanitize_title( $value );

		if ( $title instanceof WP_Error ) {
			return $title;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );

		if ( $length < 3 ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_title',
				\__( 'At least 3 characters are required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		return $title;
	}

	public function sanitize_imdb_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_imdb_id',
				\__( 'A valid IMDb ID is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		$imdb_id = strtolower( \sanitize_text_field( \wp_unslash( (string) $value ) ) );

		if ( 1 !== preg_match( '/^tt\d{5,12}$/', $imdb_id ) ) {
			return new WP_Error(
				'wp_movie_showcase_invalid_imdb_id',
				\__( 'A valid IMDb ID is required.', 'wp-movie-showcase' ),
				array( 'status' => 400 )
			);
		}

		return $imdb_id;
	}

	private function rest_error( WP_Error $error ): WP_Error {
		$status = 502;

		switch ( $error->get_error_code() ) {
			case 'wp_movie_showcase_invalid_title':
			case 'wp_movie_showcase_invalid_imdb_id':
				$status = 400;
				break;
			case 'wp_movie_showcase_not_found':
				$status = 404;
				break;
			case 'wp_movie_showcase_missing_api_key':
			case 'wp_movie_showcase_invalid_api_key':
			case 'wp_movie_showcase_remote_error':
			case 'wp_movie_showcase_service_error':
				$status = 503;
				break;
		}

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}

	private function rest_response( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data, 200 );
		$debug    = ( defined( 'WP_MOVIE_SHOWCASE_CACHE_DEBUG' ) && WP_MOVIE_SHOWCASE_CACHE_DEBUG ) ||
			( defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( $debug ) {
			$response->header( 'X-WP-Movie-Cache', $this->movies->get_last_cache_status() );
		}

		return $response;
	}
}
