<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WP_Movie_Showcase\Cache_Lock;

/** Run explicitly with WMS_MYSQL_HOST, WMS_MYSQL_PORT, WMS_MYSQL_USER and WMS_MYSQL_PASSWORD. */
final class CacheMutexTest extends TestCase {
	public function test_independent_database_sessions_cannot_publish_together(): void {
		if ( ! getenv( 'WMS_MYSQL_HOST' ) ) {
			$this->markTestSkipped( 'A MySQL test connection is required.' );
		}
		$connect = static function (): mysqli {
			return new mysqli( getenv( 'WMS_MYSQL_HOST' ), getenv( 'WMS_MYSQL_USER' ), (string) getenv( 'WMS_MYSQL_PASSWORD' ), '', (int) getenv( 'WMS_MYSQL_PORT' ) );
		};
		$owner = $connect();
		$competitor = $connect();
		$original = $GLOBALS['wpdb'];
		$adapter = new class( $owner ) {
			public string $options;
			public string $lock_name = '';
			private mysqli $connection;
			public function __construct( mysqli $connection ) {
				$this->connection = $connection;
				$this->options = 'wms_mutex_test_' . bin2hex( random_bytes( 8 ) );
			}
			public function prepare( string $query, string $name ): string {
				$this->lock_name = $name;
				return sprintf( $query, "'" . $this->connection->real_escape_string( $name ) . "'" );
			}
			public function get_var( string $query ) {
				return $this->connection->query( $query )->fetch_row()[0];
			}
		};
		$GLOBALS['wpdb'] = $adapter;
		try {
			( new Cache_Lock() )->synchronize( function () use ( $competitor, $adapter ): void {
				$name = $competitor->real_escape_string( $adapter->lock_name );
				$this->assertSame( '0', (string) $competitor->query( "SELECT GET_LOCK('$name', 0)" )->fetch_row()[0] );
				$this->assertSame( '0', (string) $competitor->query( "SELECT RELEASE_LOCK('$name')" )->fetch_row()[0] );
			} );
			$name = $competitor->real_escape_string( $adapter->lock_name );
			$this->assertSame( '1', (string) $competitor->query( "SELECT GET_LOCK('$name', 0)" )->fetch_row()[0] );
			$this->assertSame( '1', (string) $competitor->query( "SELECT RELEASE_LOCK('$name')" )->fetch_row()[0] );
		} finally {
			$GLOBALS['wpdb'] = $original;
			$owner->close();
			$competitor->close();
		}
	}
}
