<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use WP_Movie_Showcase\Movie_Service;

$GLOBALS['wms_delay_us'] = 50000;

function benchmark_clock(): int {
	return $GLOBALS['wms_now'];
}

function benchmark_movie(): array {
	return array(
		'Response'   => 'True',
		'Title'      => 'The Matrix',
		'Year'       => '1999',
		'Rated'      => 'R',
		'Runtime'    => '136 min',
		'Genre'      => 'Action, Sci-Fi',
		'Director'   => 'The Wachowskis',
		'Plot'       => 'A computer hacker discovers the truth.',
		'Poster'     => 'https://example.com/poster.jpg',
		'imdbID'     => 'tt0133093',
		'imdbRating' => '8.7',
	);
}

function measured( string $label, callable $callback ): void {
	$before_calls = $GLOBALS['wms_calls'];
	$started      = hrtime( true );
	$cache_state  = (string) $callback();
	$milliseconds = ( hrtime( true ) - $started ) / 1000000;
	$calls        = $GLOBALS['wms_calls'] - $before_calls;

	printf( "%-36s %8.3f ms | OMDb calls: %d | Cache: %s\n", $label, $milliseconds, $calls, $cache_state );
}

$job = array();
$scheduler = static function ( ...$args ) use ( &$job ): bool {
	$job = $args;
	return true;
};

$GLOBALS['wms_remote'][] = array( 'status' => 200, 'body' => json_encode( benchmark_movie() ) );

measured(
	'Cold request',
	static function () use ( $scheduler ): string {
		$service = new Movie_Service( 'benchmark-key', $scheduler, null, 'benchmark_clock' );
		$service->search_movie( 'The Matrix' );
		return $service->get_last_cache_status();
	}
);

measured(
	'Fresh cache hit',
	static function () use ( $scheduler ): string {
		$service = new Movie_Service( 'benchmark-key', $scheduler, null, 'benchmark_clock' );
		$service->search_movie( 'The Matrix' );
		return $service->get_last_cache_status();
	}
);

$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;

measured(
	'Stale cache hit',
	static function () use ( $scheduler ): string {
		$service = new Movie_Service( 'benchmark-key', $scheduler, null, 'benchmark_clock' );
		$service->search_movie( 'The Matrix' );
		return $service->get_last_cache_status();
	}
);

$GLOBALS['wms_remote'][] = new WP_Error( 'timeout', 'Simulated timeout' );
$worker                  = new Movie_Service( 'benchmark-key', $scheduler, null, 'benchmark_clock' );
$worker->refresh( $job[0], $job[1] );

measured(
	'Stale after failed refresh',
	static function () use ( $scheduler ): string {
		$service = new Movie_Service( 'benchmark-key', $scheduler, null, 'benchmark_clock' );
		$service->search_movie( 'The Matrix' );
		return $service->get_last_cache_status();
	}
);

echo "\nThe benchmark uses a deterministic 50 ms simulated upstream delay.\n";
