<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wp-movie-showcase',
	)
);
$instance_id = wp_unique_id( 'wp-movie-showcase-' );
$input_id    = $instance_id . '-title';
$listbox_id  = $instance_id . '-suggestions';
$helper_id   = $instance_id . '-helper';
$status_id   = $instance_id . '-status';
$results_id  = $instance_id . '-results';
?>

<div <?php echo wp_kses_data( $wrapper_attributes ); ?>>
	<form class="wp-movie-showcase__form" action="" method="get" novalidate>
		<label class="wp-movie-showcase__label" for="<?php echo esc_attr( $input_id ); ?>">
			<?php esc_html_e( 'Movie title', 'wp-movie-showcase' ); ?>
		</label>
		<p id="<?php echo esc_attr( $helper_id ); ?>" class="wp-movie-showcase__helper">
			<?php esc_html_e( 'Search for a movie or TV series.', 'wp-movie-showcase' ); ?>
		</p>
		<div class="wp-movie-showcase__controls">
			<div class="wp-movie-showcase__combobox-wrap">
				<svg
					class="wp-movie-showcase__search-icon"
					viewBox="0 0 18 18"
					width="18"
					height="18"
					aria-hidden="true"
					focusable="false"
				>
					<circle cx="7.5" cy="7.5" r="4.75" fill="none" stroke="currentColor" stroke-width="1.5"></circle>
					<path d="M11 11l4.25 4.25" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5"></path>
				</svg>
				<input
					id="<?php echo esc_attr( $input_id ); ?>"
					class="wp-movie-showcase__input"
					type="text"
					name="title"
					maxlength="200"
					placeholder="<?php esc_attr_e( 'Search movies or TV series', 'wp-movie-showcase' ); ?>"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
					aria-activedescendant=""
					autocapitalize="off"
					autocomplete="off"
					spellcheck="false"
					aria-describedby="<?php echo esc_attr( $helper_id ); ?> <?php echo esc_attr( $status_id ); ?>"
				/>
				<button class="wp-movie-showcase__clear" type="button" hidden>
					<span class="wp-movie-showcase__sr-only"><?php esc_html_e( 'Clear search', 'wp-movie-showcase' ); ?></span>
					<span aria-hidden="true">&times;</span>
				</button>
				<div id="<?php echo esc_attr( $listbox_id ); ?>" class="wp-movie-showcase__suggestions" role="listbox" hidden></div>
			</div>
			<button class="wp-movie-showcase__button" type="submit" disabled>
				<?php esc_html_e( 'Search Movie', 'wp-movie-showcase' ); ?>
			</button>
		</div>
		<p
			id="<?php echo esc_attr( $status_id ); ?>"
			class="wp-movie-showcase__status"
			aria-live="polite"
			aria-atomic="true"
		></p>
	</form>
	<div id="<?php echo esc_attr( $results_id ); ?>" class="wp-movie-showcase__results"></div>
</div>