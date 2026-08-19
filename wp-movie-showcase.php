<?php
/**
 * Plugin Name: WP Movie Showcase
 * Description: Provides a Gutenberg movie search block backed by the OMDb API.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Claudio Cirino
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-movie-showcase
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MOVIE_SHOWCASE_FILE', __FILE__ );
define( 'WP_MOVIE_SHOWCASE_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_MOVIE_SHOWCASE_DIR . 'includes/class-settings.php';
require_once WP_MOVIE_SHOWCASE_DIR . 'includes/class-movie-service.php';
require_once WP_MOVIE_SHOWCASE_DIR . 'includes/class-plugin.php';

( new WP_Movie_Showcase\Plugin( WP_MOVIE_SHOWCASE_FILE ) )->register();
