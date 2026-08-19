<?php

declare(strict_types=1);

namespace WP_Movie_Showcase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_NAME = 'wp_movie_showcase_omdb_api_key';

	private const PAGE_SLUG = 'wp-movie-showcase';

	private const OPTION_GROUP = 'wp_movie_showcase_settings';

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function get_api_key(): string {
		$api_key = $this->configured_api_key();

		return '' !== $api_key ? $api_key : $this->saved_api_key();
	}

	public function register_page(): void {
		\add_options_page(
			\__( 'Movie Showcase', 'wp-movie-showcase' ),
			\__( 'Movie Showcase', 'wp-movie-showcase' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		\register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'show_in_rest'      => false,
			)
		);

		\add_settings_section(
			'wp_movie_showcase_api',
			\__( 'OMDb API Settings', 'wp-movie-showcase' ),
			'__return_false',
			self::PAGE_SLUG
		);

		\add_settings_field(
			self::OPTION_NAME,
			\__( 'OMDb API Key', 'wp-movie-showcase' ),
			array( $this, 'render_field' ),
			self::PAGE_SLUG,
			'wp_movie_showcase_api'
		);
	}

	public function sanitize_api_key( $value ): string {
		if ( '' !== $this->configured_api_key() ) {
			return $this->saved_api_key();
		}

		$current_value = $this->saved_api_key();

		if ( ! is_scalar( $value ) ) {
			return $current_value;
		}

		$api_key = trim( \sanitize_text_field( \wp_unslash( (string) $value ) ) );

		return '' !== $api_key ? $api_key : $current_value;
	}

	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Movie Showcase', 'wp-movie-showcase' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				\settings_fields( self::OPTION_GROUP );
				\do_settings_sections( self::PAGE_SLUG );
				\submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_field(): void {
		$configured_api_key = $this->configured_api_key();
		?>
		<input
			type="password"
			id="<?php echo \esc_attr( self::OPTION_NAME ); ?>"
			name="<?php echo \esc_attr( self::OPTION_NAME ); ?>"
			class="regular-text"
			value=""
			autocomplete="new-password"
			<?php disabled( '' !== $configured_api_key ); ?>
		/>
		<?php if ( '' !== $configured_api_key ) : ?>
			<p class="description">
				<?php \esc_html_e( 'The API key is managed in wp-config.php or the server environment.', 'wp-movie-showcase' ); ?>
			</p>
		<?php elseif ( '' !== $this->saved_api_key() ) : ?>
			<p class="description">
				<?php
				\esc_html_e(
					'An API key is already configured. Leave this field blank to keep the current key.',
					'wp-movie-showcase'
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php \esc_html_e( 'Enter your OMDb API key.', 'wp-movie-showcase' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function configured_api_key(): string {
		if ( \defined( 'WP_MOVIE_SHOWCASE_OMDB_API_KEY' ) ) {
			$api_key = \constant( 'WP_MOVIE_SHOWCASE_OMDB_API_KEY' );

			if ( is_scalar( $api_key ) ) {
				$api_key = trim( \sanitize_text_field( (string) $api_key ) );

				if ( '' !== $api_key ) {
					return $api_key;
				}
			}
		}

		foreach ( array( 'WP_MOVIE_SHOWCASE_OMDB_API_KEY', 'OMDB_API_KEY' ) as $key ) {
			$api_key = \getenv( $key );

			if ( false === $api_key || ! is_string( $api_key ) ) {
				continue;
			}

			$api_key = trim( \sanitize_text_field( $api_key ) );

			if ( '' !== $api_key ) {
				return $api_key;
			}
		}

		return '';
	}

	private function saved_api_key(): string {
		$api_key = \get_option( self::OPTION_NAME, '' );

		return is_string( $api_key ) ? trim( $api_key ) : '';
	}
}