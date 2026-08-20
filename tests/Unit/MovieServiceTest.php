<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WP_Movie_Showcase\Cache_Lock;
use WP_Movie_Showcase\Movie_Service;

final class MovieServiceTest extends TestCase {
	protected function setUp(): void {
		wms_reset_state();
	}

	public function test_cache_miss_fetches_and_fresh_hit_avoids_upstream(): void {
		wms_queue_response( wms_movie() );

		$this->assertSame( 'The Matrix', wms_service()->search_movie( 'The Matrix' )['title'] );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
		$this->assertSame( 'The Matrix', wms_service()->search_movie( 'The Matrix' )['title'] );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_stale_returns_immediately_and_one_of_one_hundred_requests_schedules_refresh(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$jobs = array();
		$scheduler = static function ( ...$args ) use ( &$jobs ): bool {
			$jobs[] = $args;
			return true;
		};

		for ( $request = 0; $request < 100; ++$request ) {
			$this->assertSame( 'The Matrix', wms_service( $scheduler )->search_movie( 'The Matrix' )['title'] );
		}

		$this->assertCount( 1, $jobs );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_successful_refresh_replaces_stale_payload(): void {
		wms_queue_response( wms_movie( 'The Matrix', '8.7' ) );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		$worker = wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} );
		$worker->search_movie( 'The Matrix' );
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );

		$worker->refresh( $job[0], $job[1] );
		$worker->release_refresh_lock( $job[2], $job[3] );

		$this->assertSame( '9.0', wms_service()->search_movie( 'The Matrix' )['imdb_rating'] );
	}

	/**
	 * @dataProvider delayedWorkerOrderProvider
	 */
	public function test_delayed_workers_beyond_scheduling_lease_refresh_only_once( array $order ): void {
		wms_queue_response( wms_movie( 'The Matrix', '8.7' ) );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$jobs = array();
		$scheduler = static function ( ...$args ) use ( &$jobs ): bool {
			$jobs[] = $args;
			return true;
		};

		wms_service( $scheduler )->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 2 * MINUTE_IN_SECONDS + 1;
		wms_service( $scheduler )->search_movie( 'The Matrix' );
		$this->assertCount( 2, $jobs );
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );

		foreach ( $order as $job_index ) {
			$job = $jobs[ $job_index ];
			wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] );
		}

		$this->assertSame( 2, $GLOBALS['wms_calls'] );
		$this->assertSame( '9.0', wms_service()->search_movie( 'The Matrix' )['imdb_rating'] );
	}

	public function delayedWorkerOrderProvider(): array {
		return array(
			'oldest job first' => array( array( 0, 1 ) ),
			'newest job first' => array( array( 1, 0 ) ),
		);
	}

	public function test_simultaneous_workers_observing_stale_obtain_one_execution_owner(): void {
		wms_queue_response( wms_movie( 'The Matrix', '8.7' ) );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} )->search_movie( 'The Matrix' );

		$lock = new Cache_Lock( 'wms_clock' );
		$this->assertNotNull( $lock->acquire( 'execution:' . $job[2], 1 ) );
		$GLOBALS['wms_now'] += 2;
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );
		$competing_result = null;
		$GLOBALS['wms_get_option_hook'] = static function () use ( $lock, $job, &$competing_result ): void {
			$competing_result = wms_service( null, 'api-key', $lock )->refresh_if_needed( $job[0], $job[1], $job[2] );
		};

		$late_result = wms_service( null, 'api-key', $lock )->refresh_if_needed( $job[0], $job[1], $job[2] );

		$this->assertIsArray( $competing_result );
		$this->assertFalse( $late_result );
		$this->assertSame( 2, $GLOBALS['wms_calls'] );
		$this->assertSame( '9.0', wms_service()->search_movie( 'The Matrix' )['imdb_rating'] );
	}

	public function test_worker_exits_when_cache_became_fresh_before_execution(): void {
		wms_queue_response( wms_movie( 'The Matrix', '8.7' ) );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} )->search_movie( 'The Matrix' );
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );
		wms_service()->refresh( $job[0], $job[1] );
		$calls_before_worker = $GLOBALS['wms_calls'];

		$this->assertFalse( wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] ) );
		$this->assertSame( $calls_before_worker, $GLOBALS['wms_calls'] );
	}

	public function test_delayed_worker_does_not_resurrect_invalidated_entry(): void {
		wms_queue_response( wms_movie() );
		$service = wms_service();
		$service->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} )->search_movie( 'The Matrix' );
		$service->invalidate_movie( 'The Matrix' );

		$this->assertFalse( wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] ) );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_delayed_worker_cannot_write_into_a_new_namespace(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} )->search_movie( 'The Matrix' );

		Movie_Service::invalidate_namespace();
		$this->assertFalse( wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] ) );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_failed_refresh_preserves_stale_and_retry_backoff(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		$worker = wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} );
		$worker->search_movie( 'The Matrix' );
		$GLOBALS['wms_remote'][] = new WP_Error( 'timeout', 'Timeout' );

		$this->assertTrue( is_wp_error( $worker->refresh( $job[0], $job[1] ) ) );
		$retry_jobs = array();
		$result = wms_service( static function ( ...$args ) use ( &$retry_jobs ): bool {
			$retry_jobs[] = $args;
			return true;
		} )->search_movie( 'The Matrix' );

		$this->assertSame( 'The Matrix', $result['title'] );
		$this->assertSame( array(), $retry_jobs );
	}

	public function test_expired_stale_requires_synchronous_upstream_fetch(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 36 * HOUR_IN_SECONDS + 1;
		wms_queue_response( wms_movie( 'The Matrix Reloaded' ) );

		$this->assertSame( 'The Matrix Reloaded', wms_service()->search_movie( 'The Matrix' )['title'] );
		$this->assertSame( 2, $GLOBALS['wms_calls'] );
	}

	public function test_negative_cache_is_short_and_has_no_stale_window(): void {
		wms_queue_response( array( 'Response' => 'False', 'Error' => 'Movie not found!' ) );

		$this->assertTrue( is_wp_error( wms_service()->search_movie( 'Missing' ) ) );
		$this->assertTrue( is_wp_error( wms_service()->search_movie( 'Missing' ) ) );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
		$GLOBALS['wms_now'] += 15 * MINUTE_IN_SECONDS + 1;
		wms_queue_response( wms_movie( 'Missing' ) );
		$this->assertFalse( is_wp_error( wms_service()->search_movie( 'Missing' ) ) );
	}

	public function test_suggestion_policy_preserves_six_hour_fresh_and_one_hour_stale_windows(): void {
		wms_queue_response(
			array(
				'Response' => 'True',
				'Search'   => array(
					array(
						'Title' => 'The Matrix', 'Year' => '1999', 'imdbID' => 'tt0133093',
						'Type' => 'movie', 'Poster' => 'https://example.com/poster.jpg',
					),
				),
			)
		);
		wms_service()->search_titles( 'Matrix' );
		wms_service()->search_titles( 'Matrix' );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
		$GLOBALS['wms_now'] += 6 * HOUR_IN_SECONDS + 1;
		$jobs = array();
		$result = wms_service( static function ( ...$args ) use ( &$jobs ): bool {
			$jobs[] = $args;
			return true;
		} )->search_titles( 'Matrix' );

		$this->assertCount( 1, $result );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_hot_promotion_keeps_five_hit_threshold_and_seven_day_fresh_window(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );

		for ( $hit = 0; $hit < 5; ++$hit ) {
			wms_service()->search_movie( 'The Matrix' );
		}

		$hot = array_filter( $GLOBALS['wms_transients'], static function ( array $entry ): bool {
			return ! empty( $entry['value']['hot'] );
		} );
		$this->assertNotEmpty( $hot );
		$entry = reset( $hot )['value'];
		$this->assertSame( $GLOBALS['wms_now'] + 7 * DAY_IN_SECONDS, $entry['fresh_until'] );
	}

	public function test_api_key_and_generation_namespaces_invalidate_cached_data(): void {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		wms_queue_response( wms_movie() );
		wms_service( null, 'different-key' )->search_movie( 'The Matrix' );
		$this->assertSame( 2, $GLOBALS['wms_calls'] );

		Movie_Service::invalidate_namespace();
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$this->assertSame( 3, $GLOBALS['wms_calls'] );
	}

	public function test_persistent_object_cache_path_avoids_fresh_fetch(): void {
		$GLOBALS['wms_ext_cache'] = true;
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		wms_service()->search_movie( 'The Matrix' );

		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}
}
