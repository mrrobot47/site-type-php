<?php

use EE\Tests\SiteType\SiteTypeTestCase;
use EE\Tests\TestDB;

/**
 * PHP::create_site_db_entry(): the `sites` row a finished create stores.
 */
class SiteEntryTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\PHP';
	const SITE_TYPE  = 'php';

	/** @return array The stored `sites` row. */
	private function store( array $data = [], $cache = false ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'              => 'php',
			'site_url'               => 'example.test',
			'site_fs_path'           => WEBROOT . 'example.test',
			'app_sub_type'           => 'php',
			'app_admin_email'        => 'admin@example.test',
			'alias_domains'          => 'example.test,www.example.test',
			'cache_host'             => $cache ? 'redis' : '',
			'site_ssl'               => 'self',
			'site_ssl_wildcard'      => null,
			'php_version'            => '7.4',
			'site_container_fs_path' => '/var/www/htdocs/public/',
		];
		self::set_property( $site, 'cache_type', $cache );
		self::call_private( $site, 'create_site_db_entry' );
		$rows = TestDB::rows( 'sites' );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Site entry created.', $this->stdout() );

		return $rows[0];
	}

	/** @return string[] $columns of $row as strings. */
	private static function pick( array $row, array $columns ) {
		$picked = [];
		foreach ( $columns as $column ) {
			$picked[ $column ] = null === $row[ $column ] ? null : (string) $row[ $column ];
		}

		return $picked;
	}

	public function test_row_of_a_site_without_a_database() {
		$row = $this->store();
		$this->assertSame(
			[
				'site_type'              => 'php',
				'app_sub_type'           => 'php',
				'alias_domains'          => 'example.test,www.example.test',
				'site_ssl'               => 'self',
				'site_ssl_wildcard'      => '0',
				'php_version'            => '7.4',
				'site_container_fs_path' => '/var/www/htdocs/public',
				'cache_nginx_fullpage'   => '0',
				'db_name'                => null,
				'db_host'                => null,
			],
			self::pick( $row, [ 'site_type', 'app_sub_type', 'alias_domains', 'site_ssl', 'site_ssl_wildcard', 'php_version', 'site_container_fs_path', 'cache_nginx_fullpage', 'db_name', 'db_host' ] )
		);
	}

	public function test_row_of_a_site_with_a_database_and_cache() {
		$row = $this->store(
			[
				'app_sub_type'      => 'mysql',
				'db_name'           => 'example_test',
				'db_user'           => 'example.test-AbC123',
				'db_host'           => 'db',
				'db_port'           => '3306',
				'db_password'       => 'dbpass',
				'db_root_password'  => 'rootpw',
				'site_ssl_wildcard' => true,
			],
			true
		);
		$this->assertSame(
			[
				'app_sub_type'         => 'mysql',
				'db_name'              => 'example_test',
				'db_user'              => 'example.test-AbC123',
				'db_host'              => 'db',
				'db_port'              => '3306',
				'db_password'          => 'dbpass',
				'db_root_password'     => 'rootpw',
				'site_ssl_wildcard'    => '1',
				'cache_host'           => 'redis',
				'cache_nginx_fullpage' => '1',
				'cache_mysql_query'    => '1',
			],
			self::pick( $row, [ 'app_sub_type', 'db_name', 'db_user', 'db_host', 'db_port', 'db_password', 'db_root_password', 'site_ssl_wildcard', 'cache_host', 'cache_nginx_fullpage', 'cache_mysql_query' ] )
		);
	}
}
