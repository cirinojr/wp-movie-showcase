<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WP_Movie_Showcase\Cache_Lock;

final class CacheLockTest extends TestCase {
	protected function setUp(): void {
		wms_reset_state();
	}

	public function test_only_one_owner_acquires_a_new_lock(): void {
		$lock = new Cache_Lock( 'wms_clock' );

		$this->assertNotNull( $lock->acquire( 'movie', 10 ) );
		$this->assertNull( $lock->acquire( 'movie', 10 ) );
	}

	public function test_expired_takeover_is_compare_and_swap_safe(): void {
		$lock = new Cache_Lock( 'wms_clock' );
		$this->assertNotNull( $lock->acquire( 'movie', 10 ) );
		$GLOBALS['wms_now'] += 11;
		$competing_token = null;
		$GLOBALS['wms_get_option_hook'] = static function () use ( $lock, &$competing_token ): void {
			$competing_token = $lock->acquire( 'movie', 10 );
		};

		$late_token = $lock->acquire( 'movie', 10 );

		$this->assertNotNull( $competing_token );
		$this->assertNull( $late_token );
	}

	public function test_old_owner_cannot_release_a_new_database_lock(): void {
		$lock      = new Cache_Lock( 'wms_clock' );
		$old_token = $lock->acquire( 'movie', 10 );
		$GLOBALS['wms_now'] += 11;
		$new_token = null;
		$GLOBALS['wms_get_option_hook'] = static function () use ( $lock, &$new_token ): void {
			$new_token = $lock->acquire( 'movie', 10 );
		};

		$lock->release( 'movie', (string) $old_token );

		$this->assertNotNull( $new_token );
		$this->assertNull( $lock->acquire( 'movie', 10 ) );
	}

	public function test_abandoned_lock_eventually_becomes_acquirable(): void {
		$lock = new Cache_Lock( 'wms_clock' );
		$this->assertNotNull( $lock->acquire( 'movie', 10 ) );
		$GLOBALS['wms_now'] += 11;

		$this->assertNotNull( $lock->acquire( 'movie', 10 ) );
	}

	public function test_object_cache_release_never_deletes_a_newer_lease(): void {
		$GLOBALS['wms_ext_cache'] = true;
		$lock                     = new Cache_Lock( 'wms_clock' );
		$old_token                = $lock->acquire( 'movie', 10 );
		$GLOBALS['wms_now']       += 11;
		$new_token                = $lock->acquire( 'movie', 10 );

		$lock->release( 'movie', (string) $old_token );

		$this->assertNotNull( $new_token );
		$this->assertNull( $lock->acquire( 'movie', 10 ) );
	}
}
