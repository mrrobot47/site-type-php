<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * What a failed `--with-db` PHP create would drop on the global database (catch_clean() → delete_site() with
 * get_rollback_db_data()), against a fake global MariaDB that holds another site's database and user.
 */
class RollbackTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\PHP';
	const SITE_TYPE  = 'php';

	protected function setUp(): void {
		parent::setUp();
		// other.test was created with --dbname=a_b_test, the default name of a-b.test.
		$this->insert_site(
			[
				'site_url'  => 'other.test',
				'site_type' => 'php',
				'db_host'   => 'global-db',
				'db_name'   => 'a_b_test',
				'db_user'   => 'other-user',
			]
		);
		$this->services_running( [ 'a_b_test' ], [ 'other-user' ] );
	}

	/** @return array [ stop point or 'refused', rollback db data ] */
	private function create( array $assoc_args = [], $site_name = 'a-b.test' ) {
		$site = $this->make_site();
		try {
			$point = $this->run_create( $site, $site_name, $assoc_args + [ 'with-db' => true ] );
		} catch ( EE\ExitException $e ) {
			$point = 'refused';
		}

		return [ $point, self::call_private( $site, 'get_rollback_db_data' ) ];
	}

	public function test_a_fresh_database_and_user_are_created_and_would_be_rolled_back() {
		list( $point, $db_data ) = $this->create( [ 'dbuser' => 'cd-user', 'dbpass' => 'pw1' ], 'c-d.test' );

		$this->assertSame( 'webroot', $point );
		$this->assertSame( [ 'a_b_test', 'c_d_test' ], array_keys( $this->global_db->databases ) );
		$this->assertSame( [ 'other-user', 'cd-user' ], array_keys( $this->global_db->users ) );
		// `_` is a wildcard in a GRANT's database name, so site-command v3.8.1 escapes it.
		$this->assertCount( 1, $this->global_db->statements_matching( '/^' . preg_quote( "GRANT ALL PRIVILEGES ON `c\\_d\\_test`.* TO 'cd-user'@'%'", '/' ) . '$/' ) );
		$this->assertSame( [ 'db_host' => 'global-db', 'db_user' => 'cd-user', 'db_name' => 'c_d_test' ], $db_data );
	}

	public function test_a_site_without_a_global_db_never_rolls_it_back() {
		foreach ( [ [ 'local-db' => true ], [ 'with-db' => false ] ] as $assoc_args ) {
			list( $point, $db_data ) = $this->create( $assoc_args, 'c-d.test' );
			$this->assertSame( 'webroot', $point );
			$this->assertSame( [], $db_data );
		}
		$this->assertSame( [], $this->global_db->statements_matching( '/^(CREATE|DROP) /' ) );
	}

	public function test_rollback_before_the_database_step_drops_nothing() {
		list( $point, $db_data ) = $this->create( [ 'ssl' => 'custom', 'ssl-key' => '/nonexistent.key', 'ssl-crt' => '/nonexistent.crt' ] );
		$this->assertSame( 'cleanup', $point );
		$this->assertSame( [], $db_data );
	}

	/**
	 * PHPT-1 (fixed in v1.12.0): `--dbname=<existing db>` makes CREATE DATABASE fail, and the rollback then drops that database as root.
	 *
	 * @group PHPT-1
	 */
	public function test_taken_explicit_db_name_is_not_rolled_back() {
		list( , $db_data ) = $this->create( [ 'dbname' => 'a_b_test' ], 'c-d.test' );
		$this->assertArrayHasKey( 'a_b_test', $this->global_db->databases );
		$this->assertNotSame( 'a_b_test', isset( $db_data['db_name'] ) ? $db_data['db_name'] : null, 'The rollback would drop other.test\'s database.' );
	}

	/**
	 * PHPT-2 (fixed in v1.12.0): the default name of a-b.test is other.test's database, so the failed create's rollback drops it.
	 *
	 * @group PHPT-2
	 */
	public function test_taken_default_db_name_is_not_rolled_back() {
		list( , $db_data ) = $this->create();
		$this->assertNotSame( 'a_b_test', isset( $db_data['db_name'] ) ? $db_data['db_name'] : null, 'The rollback would drop other.test\'s database.' );
	}

	/**
	 * PHPT-3 (fixed in v1.12.0): `--dbuser=<existing user>` makes CREATE USER fail, and the rollback then drops that user.
	 *
	 * @group PHPT-3
	 */
	public function test_taken_db_user_is_not_rolled_back() {
		list( , $db_data ) = $this->create( [ 'dbuser' => 'other-user', 'dbname' => 'fresh_db' ] );
		$this->assertArrayHasKey( 'other-user', $this->global_db->users );
		$this->assertNotSame( 'other-user', isset( $db_data['db_user'] ) ? $db_data['db_user'] : null, 'The rollback would drop other.test\'s user.' );
	}
}
