<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WP_Movie_Showcase\Cache_Lock;
use WP_Movie_Showcase\Movie_Service;

final class CachePublicationTest extends TestCase {
	protected function setUp(): void {
		wms_reset_state();
	}

	public function backends(): array {
		return array( 'transients' => array( false ), 'object cache' => array( true ) );
	}

	public function invalidations(): array {
		$cases = array();
		foreach ( array( false, true ) as $backend ) {
			foreach ( array( 'title', 'id', 'namespace' ) as $kind ) {
				$cases[] = array( $backend, $kind );
			}
		}
		return $cases;
	}

	/** @dataProvider invalidations */
	public function test_invalidation_during_fetch_cannot_restore_either_alias( bool $backend, string $kind ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$job = $this->stale_movie();
		$worker = wms_service();
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );
		$GLOBALS['wms_remote_hook'] = static function () use ( $kind ): void {
			if ( 'namespace' === $kind ) {
				Movie_Service::invalidate_namespace();
			} elseif ( 'id' === $kind ) {
				wms_service()->invalidate_movie( '', 'tt0133093' );
			} else {
				wms_service()->invalidate_movie( 'The Matrix' );
			}
		};
		$worker->refresh_if_needed( $job[0], $job[1], $job[2] );

		$this->assertNull( $this->cached( wms_service(), 'mt:the matrix' ) );
		$this->assertNull( $this->cached( wms_service(), 'mi:tt0133093' ) );
		wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
		$this->assertSame( '9.5', $worker->search_movie( 'The Matrix' )['imdb_rating'] );
		$this->assertSame( 3, $GLOBALS['wms_calls'] );
	}

	/** @dataProvider backends */
	public function test_expired_worker_cannot_overwrite_new_owner( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$job = $this->stale_movie();
		wms_queue_response( wms_movie( 'The Matrix', '8.0' ) );
		$GLOBALS['wms_remote_hook'] = function () use ( $job ): void {
			$this->assertSame( 0, $GLOBALS['wpdb']->publication_locks, 'The network must not hold the publication mutex.' );
			$GLOBALS['wms_now'] += 121;
			wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
			wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] );
		};
		wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] );

		$this->assertSame( '9.5', wms_service()->search_movie( 'The Matrix' )['imdb_rating'] );
		$this->assertSame( '9.5', wms_service()->search_movie_by_id( 'tt0133093' )['imdb_rating'] );
		$this->assertSame( 3, $GLOBALS['wms_calls'] );
	}

	/** @dataProvider backends */
	public function test_newer_publication_wins_even_before_lease_expiry( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$this->stale_movie();
		wms_queue_response( wms_movie( 'The Matrix', '8.0' ) );
		$GLOBALS['wms_remote_hook'] = static function (): void {
			wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
			wms_service()->refresh( Movie_Service::OPERATION_TITLE, 'The Matrix' );
		};
		wms_service()->refresh( Movie_Service::OPERATION_TITLE, 'The Matrix' );
		$this->assertSame( '9.5', wms_service()->search_movie( 'The Matrix' )['imdb_rating'] );
	}

	/** @dataProvider backends */
	public function test_invalidated_suggestions_are_not_restored_by_positive_or_negative_response( bool $backend ): void {
		foreach ( array( false, true ) as $negative ) {
			wms_reset_state();
			$GLOBALS['wms_ext_cache'] = $backend;
			$data = array( 'Response' => 'True', 'Search' => array( array(
				'Title' => 'The Matrix', 'Year' => '1999', 'imdbID' => 'tt0133093',
				'Type' => 'movie', 'Poster' => 'https://example.com/poster.jpg',
			) ) );
			wms_queue_response( $data );
			wms_service()->search_titles( 'Matrix' );
			$GLOBALS['wms_now'] += 6 * HOUR_IN_SECONDS + 1;
			wms_queue_response( $negative ? array( 'Response' => 'False', 'Error' => 'Movie not found!' ) : $data );
			$GLOBALS['wms_remote_hook'] = static function (): void {
				wms_service()->invalidate_search( 'Matrix' );
			};
			wms_service()->refresh( Movie_Service::OPERATION_SUGGESTIONS, 'Matrix' );
			$this->assertNull( $this->cached( wms_service(), 'sg:matrix' ) );
		}
	}

	/** @dataProvider backends */
	public function test_cold_fetch_is_also_fenced_and_existing_memory_observes_invalidation( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$worker = wms_service();
		wms_queue_response( wms_movie() );
		$GLOBALS['wms_remote_hook'] = static function (): void {
			wms_service()->invalidate_movie( 'The Matrix' );
		};
		$worker->search_movie( 'The Matrix' );
		$this->assertNull( $this->cached( wms_service(), 'mt:the matrix' ) );
		wms_queue_response( wms_movie( 'The Matrix', '9.0' ) );
		$worker->search_movie( 'The Matrix' );
		$worker->search_movie( 'The Matrix' );
		wms_service()->invalidate_movie( '', 'tt0133093' );
		wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
		$this->assertSame( '9.5', $worker->search_movie( 'The Matrix' )['imdb_rating'] );
	}

	public function test_publication_mutex_is_released_after_exception(): void {
		try {
			( new Cache_Lock() )->synchronize( static function (): void {
				throw new RuntimeException( 'test' );
			} );
			$this->fail( 'Exception expected.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'test', $error->getMessage() );
		}
		$this->assertSame( 0, $GLOBALS['wpdb']->publication_locks );
	}

	/** @dataProvider backends */
	public function test_invalidation_between_worker_check_and_snapshot_stops_fetch( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$job = $this->stale_movie();
		wms_queue_response( wms_movie() );
		$GLOBALS['wms_publication_hook'] = static function (): void {
			wms_service()->invalidate_movie( 'The Matrix' );
		};
		$this->assertFalse( wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] ) );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
		$this->assertNull( $this->cached( wms_service(), 'mt:the matrix' ) );
		$this->assertNull( $this->cached( wms_service(), 'mi:tt0133093' ) );
	}

	/** @dataProvider backends */
	public function test_replacement_between_worker_check_and_snapshot_stops_fetch( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$job = $this->stale_movie();
		$GLOBALS['wms_publication_hook'] = static function (): void {
			wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
			wms_service()->refresh( Movie_Service::OPERATION_TITLE, 'The Matrix' );
		};
		$this->assertFalse( wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] ) );
		$this->assertSame( 2, $GLOBALS['wms_calls'] );
		$this->assertSame( '9.5', wms_service()->search_movie_by_id( 'tt0133093' )['imdb_rating'] );
	}

	public function test_failed_mutex_returns_service_error_without_fetching(): void {
		$GLOBALS['wms_mutex_failure'] = true;
		$service = wms_service();
		foreach ( array( $service->search_movie( 'The Matrix' ), $service->search_movie_by_id( 'tt0133093' ), $service->search_titles( 'Matrix' ) ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wp_movie_showcase_service_error', $result->get_error_code() );
		}
		$this->assertSame( 0, $GLOBALS['wms_calls'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->publication_locks );
	}

	/** @dataProvider backends */
	public function test_revision_read_failure_cannot_publish_as_default_revision( bool $backend ): void {
		$GLOBALS['wms_ext_cache'] = $backend;
		$job = $this->stale_movie();
		wms_queue_response( wms_movie( 'The Matrix', '9.5' ) );
		$GLOBALS['wms_remote_hook'] = static function (): void {
			$GLOBALS['wms_revision_failure'] = true;
		};
		$result = wms_service()->refresh_if_needed( $job[0], $job[1], $job[2] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_movie_showcase_service_error', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['wpdb']->publication_locks );
		$GLOBALS['wms_revision_failure'] = false;
		$this->assertSame( '8.7', $this->cached( wms_service(), 'mt:the matrix' )['value']['imdb_rating'] );
		$this->assertSame( '8.7', $this->cached( wms_service(), 'mi:tt0133093' )['value']['imdb_rating'] );
	}

	public function test_revision_read_failure_does_not_serve_local_memory(): void {
		$service = wms_service();
		wms_queue_response( wms_movie() );
		$service->search_movie( 'The Matrix' );
		$service->search_movie( 'The Matrix' );
		$GLOBALS['wms_revision_failure'] = true;
		$this->assertInstanceOf( WP_Error::class, $service->search_movie( 'The Matrix' ) );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_successful_publication_preserves_request_memory_without_counting_an_extra_hit(): void {
		$service = wms_service();
		wms_queue_response( wms_movie() );
		$service->search_movie( 'The Matrix' );
		$service->search_movie( 'The Matrix' );
		$this->assertSame( 'MEMORY_HIT', $service->get_last_cache_status() );
		$this->assertSame( 0, $this->cached( wms_service(), 'mt:the matrix' )['hits'] );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	private function stale_movie(): array {
		wms_queue_response( wms_movie() );
		wms_service()->search_movie( 'The Matrix' );
		$GLOBALS['wms_now'] += 12 * HOUR_IN_SECONDS + 1;
		$job = array();
		wms_service( static function ( ...$args ) use ( &$job ): bool {
			$job = $args;
			return true;
		} )->search_movie( 'The Matrix' );
		return $job;
	}

	private function cached( Movie_Service $service, string $key ) {
		$method = new ReflectionMethod( Movie_Service::class, 'get_cached_value' );
		$method->setAccessible( true );
		return $method->invoke( $service, $key );
	}
}
