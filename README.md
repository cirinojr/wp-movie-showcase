<div align="center">

![WP Movie Showcase hero](docs/images/wp-movie-showcase-hero.png)

# WP Movie Showcase

A dynamic Gutenberg block for fast, accessible movie and TV search powered by the OMDb API.

[Features](#features) · [Installation](#installation) · [Configuration](#configuration) · [Development](#development)

</div>

## Overview

WP Movie Showcase adds a **Movie Search** block to WordPress. Visitors can search by title, navigate autocomplete suggestions with a keyboard, and load normalized movie information without exposing the OMDb API key to the browser.

The compiled production assets are committed to the repository, so the plugin works immediately after installation. Node.js and Composer are only needed when contributing or rebuilding the project.

## Features

- Dynamic Gutenberg block with server-side rendering.
- Movie and TV title search through the OMDb API.
- Autocomplete with up to five suggestions.
- Exact title selection by IMDb ID.
- Responsive loading skeleton and result card.
- Support for multiple independent blocks on one page.
- Keyboard-friendly combobox and live status announcements.
- Server-side API key handling and response normalization.
- Layered caching with request memory, the WordPress object cache, and Transients.

Results can include the poster, title, year, age rating, runtime, genre, director, plot, and IMDb rating. Missing values are omitted gracefully.

## Requirements

- WordPress 6.4 or newer.
- PHP 7.4 or newer.
- JavaScript enabled in the visitor's browser.
- A valid [OMDb API key](https://www.omdbapi.com/apikey.aspx).

## Installation

### From GitHub

1. Download the repository as a ZIP from GitHub.
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**.
3. Select the downloaded ZIP and choose **Install Now**.
4. Activate **WP Movie Showcase**.

### With Git

Clone the repository inside `wp-content/plugins`:

```bash
git clone https://github.com/cirinojr/wp-movie-showcase.git
```

Then activate **WP Movie Showcase** from the WordPress Plugins screen.

## Configuration

Open **Settings > Movie Showcase**, enter your OMDb API key, and save the settings.

The key can alternatively be supplied through `wp-config.php`:

```php
define( 'WP_MOVIE_SHOWCASE_OMDB_API_KEY', 'your-api-key' );
```

The plugin also accepts the `WP_MOVIE_SHOWCASE_OMDB_API_KEY` and `OMDB_API_KEY` environment variables. A constant or environment variable takes precedence over the value saved in WordPress.

> Never commit a real API key to the repository.

## Usage

1. Edit a post or page in the block editor.
2. Insert the **Movie Search** block.
3. Publish or preview the page.
4. Type at least three characters to display suggestions.
5. Select a suggestion or press Enter to search for the typed title.

Keyboard controls include Arrow Up, Arrow Down, Enter, Escape, and Tab.

## How it works

The block frontend sends a request to WordPress. WordPress validates the request, calls OMDb from the server, normalizes the response, and returns only the data needed by the interface. The API key never needs to be included in client-side JavaScript.

Cache lookup order:

1. Request-local memory.
2. Persistent WordPress object cache, when available.
3. WordPress Transients as the fallback.

Complete movie results are cached for 12 hours and may be promoted to a seven-day hot cache. Suggestions are cached for six hours, while empty suggestions and not-found responses use a short 15-minute cache. Errors are not cached.

## Security and accessibility

- The OMDb API key remains server-side.
- The settings screen requires the `manage_options` capability.
- The stored key is not exposed through the REST API.
- Remote responses are validated and normalized.
- The search field has a visible label.
- Autocomplete uses combobox and listbox semantics.
- Status updates are announced through an `aria-live` region.

## Project structure

```text
wp-movie-showcase/
├── build/                  # Production assets used by WordPress
├── docs/images/            # README artwork and documentation images
├── includes/               # Settings, plugin bootstrap, and movie service
├── src/                    # Block source files and styles
├── readme.txt              # WordPress.org-compatible documentation
├── wp-movie-showcase.php   # Plugin entry point
├── package.json            # JavaScript tooling
└── composer.json           # PHP quality tooling
```

## Development

Install the development dependencies:

```bash
npm ci
composer install
```

Available commands:

```bash
npm run start       # Watch and rebuild assets
npm run build       # Create production assets in build/
npm run lint:js     # Lint JavaScript
npm run lint:css    # Lint SCSS
npm run zip         # Generate an installable plugin ZIP
composer lint       # Run PHPCS with the configured VIP ruleset
```

Before publishing a release, rebuild the assets, run the linters, and generate a fresh ZIP.

## Troubleshooting

**The movie service is not configured**  
Save a valid key under **Settings > Movie Showcase**, or provide it through a supported constant or environment variable.

**No suggestions or results appear**  
Check the spelling and confirm that the WordPress server can make outbound HTTPS requests. Empty and not-found results remain cached for 15 minutes.

**The frontend form does not respond**  
Confirm that JavaScript is enabled and that the files from `build/` are loading.

**Results appear stale after changing the API key**  
The cache namespace changes with the active API key, so subsequent requests use a fresh namespace.

## License

Licensed under [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).

WP Movie Showcase is not affiliated with IMDb or OMDb. Movie data is supplied by the OMDb API.

