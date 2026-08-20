=== WP Movie Showcase ===
Contributors: claudio-cirino
Tags: movies, omdb, gutenberg, movie search
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search movies and TV series from a Gutenberg block using the OMDb API.

== Description ==

WP Movie Showcase registers a dynamic block that renders a frontend search form. Visitors can search by title or choose an autocomplete suggestion, which loads the exact result by IMDb ID.

The editor view stays simple and shows a WordPress Placeholder.

The plugin is not affiliated with IMDb or OMDb.

== Requirements ==

* WordPress 6.4 or newer.
* PHP 7.4 or newer.
* A valid OMDb API key.
* JavaScript enabled on the frontend.

== Installation ==

1. Install the plugin or upload the generated ZIP.
2. Activate WP Movie Showcase.
3. Go to Settings > Movie Showcase.
4. Save an OMDb API key.
5. Add the Movie Search block to a post or page.
6. View the page on the frontend.

The API key can also be supplied through the `WP_MOVIE_SHOWCASE_OMDB_API_KEY` constant or the `WP_MOVIE_SHOWCASE_OMDB_API_KEY` or `OMDB_API_KEY` environment variables. A configured constant or environment variable takes precedence over the saved option.

== Usage ==

* Type at least 3 characters to request suggestions.
* Up to 5 suggestions are shown.
* Press Enter to search the typed title.
* Select a suggestion to load the exact result by IMDb ID.
* Movie details may include the poster, title, year, rated value, runtime, genre, director, plot, and IMDb rating.
* Missing fields are omitted.

== Block behavior ==

* The block is registered through `block.json`.
* The frontend uses a search form, autocomplete list, loading skeleton, and result area.
* Multiple block instances on the same page work independently.
* The editor shows a simple Placeholder instead of a live preview.

== Security ==

* The OMDb API key stays server-side.
* The settings page uses the WordPress Settings API and requires the `manage_options` capability.
* The stored option is not exposed through REST.
* OMDb requests run on the server through the WordPress HTTP API.
* Responses are validated and normalized before they are returned or cached.

== Cache behavior ==

Cache order:

1. Request-local memory cache.
2. WordPress object cache when available.
3. WordPress Transients when no persistent object cache is available.

Cache durations:

* Complete movie result: 12 hours.
* Non-empty autocomplete suggestions: 6 hours.
* Empty autocomplete suggestions: 15 minutes.
* Movie not found: 15 minutes.
* Hot movie result: 7 days after promotion.

The durations above remain the fresh windows. Complete and hot movie results have an additional 24-hour stale grace period, and non-empty suggestions have a 1-hour stale grace period. Stale data is returned immediately while one refresh is scheduled through WP-Cron. A cross-request lock prevents concurrent refreshes for the same key.

Negative entries do not use stale-while-revalidate. Only successful complete movie results are promoted to the hot cache. Errors are not cached and failed refreshes do not delete usable stale data.

== Accessibility ==

* The search field has a visible label.
* The autocomplete uses combobox and listbox semantics.
* Keyboard support includes Arrow Up, Arrow Down, Enter, Escape, and Tab.
* Status changes are announced through an `aria-live` region.

== Development ==

* `npm ci`
* `composer install`

Available commands:

* `npm run start` - watch and rebuild assets.
* `npm run build` - build production assets in `build/`.
* `npm run lint:js` - lint JavaScript files.
* `npm run lint:css` - lint SCSS files.
* `npm run zip` - generate the installable ZIP.
* `composer lint` - run PHPCS with the configured VIP ruleset.
* `composer test` - run the PHPUnit cache and concurrency suite.
* `composer test:all` - run PHPUnit and WordPress VIP PHPCS.
* `npm run benchmark` - compare cold, fresh, stale, and failed-refresh paths.

Detailed cache design documentation is available in `docs/cache-architecture.md`.

Release builds load runtime assets from `build/`. Development files such as `src/`, `node_modules/`, `vendor/`, and local tooling files are excluded from the ZIP.

== Release ==

1. Run `npm run build`.
2. Run `npm run lint:js` and `npm run lint:css`.
3. Run `composer lint` when PHPCS is installed locally.
4. Run `npm run zip`.

== Troubleshooting ==

= The movie service is not configured =

Save a valid OMDb API key in Settings > Movie Showcase, or provide it through the supported constant or environment variable.

= No suggestions or results are returned =

Check the title spelling. Empty suggestion results and not-found movie responses are cached for 15 minutes.

= The frontend form does not respond =

Confirm JavaScript is enabled and that the block scripts are loading on the page.

= The OMDb request fails =

Check that the API key is valid and that the server can make outbound HTTPS requests.

= Results look stale after changing the API key =

The cache namespace changes with the active API key, so new requests use a new cache namespace.

== Changelog ==

= 1.0.0 =

* Initial release.
* Added stale-while-revalidate, cross-request refresh locks, background refresh, cache observability, tests, and CI while preserving the adaptive cache policy.
