<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use WP_Movie_Showcase\Cache_Lock;
use WP_Movie_Showcase\Movie_Service;

function reset_state(): void {
	$GLOBALS['wms_now']        = 1700000000;
	$GLOBALS['wms_transients'] = array();
	$GLOBALS['wms_options']    = array();
	$GLOBALS['wms_remote']     = array();
	$GLOBALS['wms_calls']      = 0;
	$GLOBALS['wms_ext_cache']  = false;
	$GLOBALS['wms_object_cache'] = array();
}

function clock(): int {
	return $GLOBALS['wms_now'];
}

function movie( string $title = 'The Matrix', string $rating = '8.7' ): array {
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
		'imdbID'     => 'tt0133093',
		'imdbRating' => $rating,
	);
}

function queue_response( array $data ): void {
	$GLOBALS['wms_remote'][] = array( 'status' => 200, 'body' => json_encode( $data ) );
}

function service( ?callable $scheduler = null ): Movie_Service {
	return new Movie_Service( 'api-key', $scheduler, null, 'clock' );
}

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$tests = array();

$tests['fresh cache avoids upstream'] = static function (): void {
	reset_state();
	queue_response( movie() );
	expect( 'The Matrix' === service()->search_movie( 'The Matrix' )['title'], 'Cold result failed.' );
	expect( 'The Matrix' === service()->search_movie( 'The Matrix' )['title'], 'Fresh result failed.' );
	expect( 1 === $GLOBALS['wms_calls'], 'Fresh hit called OMDb.' );
};

$tests['stale returns immediately and schedules once'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
	$jobs = array();
	$scheduler = static function ( ...$args ) use ( &$jobs ): bool {
		$jobs[] = $args;
		return true;
	};
	service( $scheduler )->search_movie( 'The Matrix' );
	service( $scheduler )->search_movie( 'The Matrix' );
	expect( 1 === count( $jobs ), 'Stampede lock allowed duplicate jobs.' );
	expect( 1 === $GLOBALS['wms_calls'], 'Stale response waited for OMDb.' );
};

$tests['refresh success replaces stale'] = static function (): void {
	reset_state();
	queue_response( movie( 'The Matrix', '8.7' ) );
	service()->search_movie( 'The Matrix' );
	$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
	$job = array();
	$worker = service( static function ( ...$args ) use ( &$job ): bool {
		$job = $args;
		return true;
	} );
	$worker->search_movie( 'The Matrix' );
	queue_response( movie( 'The Matrix', '9.0' ) );
	$worker->refresh( $job[0], $job[1] );
	$worker->release_refresh_lock( $job[2], $job[3] );
	expect( '9.0' === service()->search_movie( 'The Matrix' )['imdb_rating'], 'Refresh did not replace stale.' );
};

$tests['refresh failure preserves stale'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
	$job = array();
	$worker = service( static function ( ...$args ) use ( &$job ): bool {
		$job = $args;
		return true;
	} );
	$worker->search_movie( 'The Matrix' );
	$GLOBALS['wms_remote'][] = new WP_Error( 'timeout', 'Timeout' );
	expect( is_wp_error( $worker->refresh( $job[0], $job[1] ) ), 'Refresh failure was not reported.' );
	$retry_jobs = array();
	$retry = service( static function ( ...$args ) use ( &$retry_jobs ): bool {
		$retry_jobs[] = $args;
		return true;
	} );
	expect( 'The Matrix' === $retry->search_movie( 'The Matrix' )['title'], 'Stale was removed after failure.' );
	expect( array() === $retry_jobs, 'Failed refresh did not retain the short retry backoff.' );
};

$tests['expired data requires upstream'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	$GLOBALS['wms_now'] += 36 * HOUR_IN_SECONDS + 1;
	queue_response( movie( 'The Matrix Reloaded' ) );
	expect( 'The Matrix Reloaded' === service()->search_movie( 'The Matrix' )['title'], 'Expired data was served.' );
	expect( 2 === $GLOBALS['wms_calls'], 'Expired data did not call OMDb.' );
};

$tests['negative cache remains short and fresh-only'] = static function (): void {
	reset_state();
	queue_response( array( 'Response' => 'False', 'Error' => 'Movie not found!' ) );
	expect( is_wp_error( service()->search_movie( 'Missing' ) ), 'Negative response was not an error.' );
	expect( is_wp_error( service()->search_movie( 'Missing' ) ), 'Negative cache was not reused.' );
	expect( 1 === $GLOBALS['wms_calls'], 'Negative cache called OMDb twice.' );
	$GLOBALS['wms_now'] += 15 * MINUTE_IN_SECONDS + 1;
	queue_response( movie( 'Missing' ) );
	expect( ! is_wp_error( service()->search_movie( 'Missing' ) ), 'Expired negative blocked valid data.' );
};

$tests['suggestions preserve fresh ttl and stale grace'] = static function (): void {
	reset_state();
	queue_response(
		array(
			'Response' => 'True',
			'Search'   => array(
				array(
					'Title'  => 'The Matrix',
					'Year'   => '1999',
					'imdbID' => 'tt0133093',
					'Type'   => 'movie',
					'Poster' => 'https://example.com/poster.jpg',
				),
			),
		)
	);
	service()->search_titles( 'Matrix' );
	service()->search_titles( 'Matrix' );
	expect( 1 === $GLOBALS['wms_calls'], 'Fresh suggestions called OMDb.' );
	$GLOBALS['wms_now'] += 6 * HOUR_IN_SECONDS + 1;
	$jobs = array();
	$scheduler = static function ( ...$args ) use ( &$jobs ): bool {
		$jobs[] = $args;
		return true;
	};
	$result = service( $scheduler )->search_titles( 'Matrix' );
	expect( 1 === count( $result ) && 1 === count( $jobs ), 'Stale suggestions were not returned and scheduled.' );
	expect( 1 === $GLOBALS['wms_calls'], 'Stale suggestions called OMDb synchronously.' );
};

$tests['hot promotion preserves threshold and seven-day ttl'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	for ( $index = 0; $index < 5; ++$index ) {
		service()->search_movie( 'The Matrix' );
	}
	$hot = array_filter(
		$GLOBALS['wms_transients'],
		static fn( array $entry ): bool => ! empty( $entry['value']['hot'] )
	);
	expect( ! empty( $hot ), 'Movie was not promoted after five hits.' );
	$entry = reset( $hot )['value'];
	expect( $GLOBALS['wms_now'] + 7 * DAY_IN_SECONDS === $entry['fresh_until'], 'Hot fresh TTL changed.' );
};

$tests['namespace changes invalidate API-key cache'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	queue_response( movie() );
	( new Movie_Service( 'different-key', null, null, 'clock' ) )->search_movie( 'The Matrix' );
	expect( 2 === $GLOBALS['wms_calls'], 'API-key namespace did not invalidate cache.' );
};

$tests['schema generation invalidates namespace'] = static function (): void {
	reset_state();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	Movie_Service::invalidate_namespace();
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	expect( 2 === $GLOBALS['wms_calls'], 'Namespace generation did not invalidate cache.' );
};

$tests['persistent object cache supports fresh and stampede paths'] = static function (): void {
	reset_state();
	$GLOBALS['wms_ext_cache'] = true;
	queue_response( movie() );
	service()->search_movie( 'The Matrix' );
	service()->search_movie( 'The Matrix' );
	expect( 1 === $GLOBALS['wms_calls'], 'Object cache fresh hit called OMDb.' );
	$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
	$jobs = array();
	$scheduler = static function ( ...$args ) use ( &$jobs ): bool {
		$jobs[] = $args;
		return true;
	};
	service( $scheduler )->search_movie( 'The Matrix' );
	service( $scheduler )->search_movie( 'The Matrix' );
	expect( 1 === count( $jobs ), 'Object cache lock allowed a stampede.' );
};

$tests['abandoned lock expires'] = static function (): void {
	reset_state();
	$lock = new Cache_Lock( 'clock' );
	expect( null !== $lock->acquire( 'movie', 10 ), 'Initial lock failed.' );
	expect( null === $lock->acquire( 'movie', 10 ), 'Concurrent lock succeeded.' );
	$GLOBALS['wms_now'] += 11;
	expect( null !== $lock->acquire( 'movie', 10 ), 'Expired lock remained blocked.' );
};

$failures = 0;

foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$name} - {$error->getMessage()}\n" );
	}
}

exit( $failures > 0 ? 1 : 0 );
