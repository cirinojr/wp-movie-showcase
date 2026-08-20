<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WP_Movie_Showcase\Movie_Service;

final class CacheInvalidationTest extends TestCase {
	protected function setUp(): void {
		wms_reset_state();
	}

	public function test_invalidation_by_title_removes_imdb_alias(): void {
		wms_queue_response( wms_movie() );
		$service = wms_service();
		$service->search_movie( 'The Matrix' );
		$service->invalidate_movie( 'The Matrix' );
		wms_queue_response( wms_movie() );

		wms_service()->search_movie_by_id( 'tt0133093' );

		$this->assertSame( 2, $GLOBALS['wms_calls'] );
	}

	public function test_invalidation_by_imdb_id_removes_canonical_title_alias(): void {
		wms_queue_response( wms_movie() );
		$service = wms_service();
		$service->search_movie_by_id( 'tt0133093' );
		$service->invalidate_movie( '', 'tt0133093' );
		wms_queue_response( wms_movie() );

		wms_service()->search_movie( 'The Matrix' );

		$this->assertSame( 2, $GLOBALS['wms_calls'] );
	}

	public function test_unknown_movie_invalidation_does_not_delete_other_entries(): void {
		wms_queue_response( wms_movie() );
		$service = wms_service();
		$service->search_movie( 'The Matrix' );
		$service->invalidate_movie( 'Unknown Movie' );

		wms_service()->search_movie_by_id( 'tt0133093' );

		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}

	public function test_incompatible_schema_envelope_is_not_trusted(): void {
		$service    = wms_service();
		$reflection = new ReflectionMethod( Movie_Service::class, 'cache_key' );
		$reflection->setAccessible( true );
		$cache_key = $reflection->invoke( $service, 'mt:the matrix' );
		$now       = $GLOBALS['wms_now'];
		set_transient(
			$cache_key,
			array(
				'schema' => 3, 'type' => 'movie', 'value' => array(),
				'cached_at' => $now, 'fresh_until' => $now + 100, 'stale_until' => $now + 200,
			),
			200
		);
		wms_queue_response( wms_movie() );

		$this->assertSame( 'The Matrix', $service->search_movie( 'The Matrix' )['title'] );
		$this->assertSame( 1, $GLOBALS['wms_calls'] );
	}
}
