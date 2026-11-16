<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * PHP::create(): what the create derives from its arguments before the site root, containers and files.
 */
class CreateOptionsTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\PHP';
	const SITE_TYPE  = 'php';

	/** @return array site_data once the create reaches nginx_proxy_check(). */
	private function site_data_at_proxy_check( $site_name, array $assoc_args = [] ) {
		$site = $this->make_site();
		$this->assertSame( 'proxy-check', $this->run_create( $site, $site_name, $assoc_args ) );

		return $site->site_data;
	}

	/** @return array site_data once the database step is done (create_site_root() is next). */
	private function site_data_at_webroot( $site_name, array $assoc_args = [] ) {
		if ( ! $this->global_db ) {
			$this->services_running();
		}
		$site = $this->make_site();
		$this->assertSame( 'webroot', $this->run_create( $site, $site_name, $assoc_args ) );

		return $site->site_data;
	}

	public function test_without_with_db_the_site_has_no_database() {
		$data = $this->site_data_at_webroot( 'example.test', [ 'local-db' => true ] );
		$this->assertSame( 'php', $data['app_sub_type'] );
		$this->assertSame( '', $data['db_host'] );
		$this->assertArrayNotHasKey( 'db_name', $data );
		$this->assertSame( [], $this->global_db->statements );
	}

	public function default_db_names() {
		return [
			'dots'            => [ 'example.com', 'example_com' ],
			'dashes and dots' => [ 'my-site.example.test', 'my_site_example_test' ],
			'upper case'      => [ 'Example.COM', 'example_com' ],
		];
	}

	/**
	 * @dataProvider default_db_names
	 */
	public function test_with_db_default_name_replaces_dots_and_dashes( $site_name, $db_name ) {
		$data = $this->site_data_at_webroot( $site_name, [ 'with-db' => true ] );
		$this->assertSame( 'mysql', $data['app_sub_type'] );
		$this->assertSame( [ $db_name, 'global-db', '3306', '' ], [ $data['db_name'], $data['db_host'], $data['db_port'], $data['db_root_password'] ] );
		$this->assertArrayHasKey( $db_name, $this->global_db->databases );
	}

	/**
	 * PHPT-2 (fixed in v1.12.0): a site name longer than 64 characters gives a default database name MySQL rejects.
	 *
	 * @group PHPT-2
	 */
	public function test_default_db_name_fits_the_mysql_identifier_limit() {
		$data = $this->site_data_at_webroot( str_repeat( 'a', 60 ) . '.example.test', [ 'with-db' => true ] );
		$this->assertLessThanOrEqual( 64, strlen( $data['db_name'] ), $data['db_name'] );
	}

	public function test_default_db_user_is_the_site_name_with_a_random_suffix_cut_to_53_characters() {
		$data = $this->site_data_at_webroot( 'a-b.test', [ 'with-db' => true ] );
		$this->assertMatchesRegularExpression( '/^a-b\.test-[A-Za-z0-9]{6}$/', $data['db_user'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $data['db_password'] );

		$data = $this->site_data_at_webroot( str_repeat( 'b', 40 ) . '.' . str_repeat( 'c', 30 ) . '.test', [ 'with-db' => true ] );
		$this->assertMatchesRegularExpression( '/^b{40}\.c{12}-[A-Za-z0-9]{6}$/', $data['db_user'] );
	}

	public function test_explicit_db_credentials_are_kept() {
		$data = $this->site_data_at_webroot( 'example.test', [ 'with-db' => true, 'dbname' => 'shop', 'dbuser' => 'shopper', 'dbpass' => 'secret' ] );
		$this->assertSame( [ 'shop', 'shopper', 'secret' ], [ $data['db_name'], $data['db_user'], $data['db_password'] ] );
		$this->assertCount( 1, $this->global_db->statements_matching( '/^CREATE USER ["\']shopper["\']@["\']%["\'] IDENTIFIED BY ["\']secret["\']$/' ) );
	}

	public function test_local_db_gets_its_own_root_password_and_skips_the_global_db() {
		$data = $this->site_data_at_webroot( 'example.test', [ 'with-db' => true, 'local-db' => true ] );
		$this->assertSame( 'db', $data['db_host'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{18}$/', $data['db_root_password'] );
		$this->assertSame( [], $this->global_db->statements );
	}

	/**
	 * LOG-4: generated database credentials reached the logged commands and their output.
	 *
	 * @group known-bug
	 * @group LOG-4
	 */
	public function test_create_masks_its_database_credentials_in_logged_output() {
		$data = $this->site_data_at_webroot( 'example.test', [ 'with-db' => true, 'local-db' => true ] );
		$this->set_config( [ 'debug' => true ] );
		EE::debug( $data['db_password'] . ' ' . $data['db_root_password'] );

		$this->assertStringNotContainsString( $data['db_password'], $this->stderr() );
		$this->assertStringNotContainsString( $data['db_root_password'], $this->stderr() );
		if ( property_exists( 'EE', 'obfuscated' ) ) {
			self::set_static( 'EE', 'obfuscated', [] );
		}
	}

	public function test_remote_db_host_and_port_are_split() {
		$data = $this->site_data_at_webroot( 'example.test', [ 'with-db' => true, 'dbhost' => '10.0.0.5:3307', 'dbuser' => 'u', 'dbpass' => 'p' ] );
		$this->assertSame( [ '10.0.0.5', '3307' ], [ $data['db_host'], $data['db_port'] ] );
		$this->assertSame( [], $this->global_db->statements );
	}

	public function test_remote_db_host_requires_user_and_password() {
		$this->services_running();
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'with-db' => true, 'dbhost' => 'db.example.com', 'dbpass' => 'p' ] );
			},
			'`--dbuser` and `--dbpass` are required for remote db host.'
		);
	}

	/**
	 * PHPT-5: the remote database check only runs when db_host is `db`, where it returns at once, so remote credentials are never checked.
	 *
	 * @group known-bug
	 * @group PHPT-5
	 */
	public function test_remote_db_is_checked_before_the_site_is_configured() {
		$this->services_running();
		$this->stop_at_compose();
		$this->assertSame( 'compose', $this->run_create( $this->make_site(), 'example.test', [ 'with-db' => true, 'dbhost' => '10.0.0.5', 'dbuser' => 'u', 'dbpass' => 'p' ] ) );
		$this->assertCount( 1, $this->runner->matching( '/^docker run --name \S+ .*easyengine\/mariadb:/' ) );
	}

	public function supported_php_versions() {
		return [
			'default' => [ [], '8.4' ],
			'7.0'     => [ [ 'php' => '7.0' ], '7.0' ],
			'5.6'     => [ [ 'php' => '5.6' ], '5.6' ],
			'8.5'     => [ [ 'php' => '8.5' ], '8.5' ],
			'latest'  => [ [ 'php' => 'latest' ], 'latest' ],
		];
	}

	/**
	 * @dataProvider supported_php_versions
	 */
	public function test_supported_php_versions_are_kept( $assoc_args, $expected ) {
		$this->assertSame( $expected, (string) $this->site_data_at_proxy_check( 'example.test', $assoc_args )['php_version'] );
	}

	public function test_php_versions_outside_the_list_fail_validation() {
		$this->assert_ee_error(
			function () {
				$this->create_args( [ 'example.test' ], [ 'php' => '7.1' ] );
			},
			"Invalid value specified for 'php'"
		);
	}

	public function loosely_matching_php_versions() {
		return [
			'8'    => [ '8' ],
			'8.40' => [ '8.40' ],
			'7'    => [ '7' ],
			'8e0'  => [ '8e0' ],
			'08.4' => [ '08.4' ],
		];
	}

	/**
	 * PHPT-11: values that equal a listed version only as numbers pass the loose checks and reach the compose file as-is.
	 *
	 * @dataProvider loosely_matching_php_versions
	 * @group known-bug
	 * @group PHPT-11
	 */
	public function test_php_version_is_one_of_the_supported_versions( $php ) {
		$data = $this->site_data_at_proxy_check( 'example.test', [ 'php' => $php ] );
		$this->assertContains( (string) $data['php_version'], [ '5.6', '7.0', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', 'latest' ] );
	}

	public function cache_flags() {
		return [
			'no cache'     => [ [], '' ],
			'global redis' => [ [ 'cache' => true ], 'global-redis' ],
			'local redis'  => [ [ 'cache' => true, 'with-local-redis' => true ], 'redis' ],
			'redis alone'  => [ [ 'with-local-redis' => true ], '' ],
		];
	}

	/**
	 * @dataProvider cache_flags
	 */
	public function test_cache_host_follows_the_cache_flags( $assoc_args, $cache_host ) {
		$this->assertSame( $cache_host, $this->site_data_at_proxy_check( 'example.test', $assoc_args )['cache_host'] );
	}

	public function public_dirs() {
		return [
			'none'      => [ [], '/var/www/htdocs' ],
			'relative'  => [ [ 'public-dir' => 'public' ], '/var/www/htdocs/public' ],
			'full path' => [ [ 'public-dir' => '/var/www/htdocs/web/' ], '/var/www/htdocs/web' ],
		];
	}

	/**
	 * @dataProvider public_dirs
	 */
	public function test_public_dir_becomes_the_container_document_root( $assoc_args, $path ) {
		$this->assertSame( $path, $this->site_data_at_proxy_check( 'example.test', $assoc_args )['site_container_fs_path'] );
	}

	/**
	 * PHPT-12: `[--public-dir]` is declared as a flag, so a bare `--public-dir` (true) becomes the document root `htdocs/1`.
	 *
	 * @group known-bug
	 * @group PHPT-12
	 */
	public function test_bare_public_dir_does_not_become_a_directory_named_1() {
		$this->assertNotSame( '/var/www/htdocs/1', $this->site_data_at_proxy_check( 'example.test', [ 'public-dir' => true ] )['site_container_fs_path'] );
	}

	public function ssl_flags() {
		return [
			'none'      => [ [], '', null ],
			'bare flag' => [ [ 'ssl' => true ], 'le', null ],
			'wildcard'  => [ [ 'ssl' => 'le', 'wildcard' => true ], 'le', true ],
			'self'      => [ [ 'ssl' => 'self' ], 'self', null ],
		];
	}

	/**
	 * @dataProvider ssl_flags
	 */
	public function test_ssl_flag_value( $assoc_args, $ssl, $wildcard ) {
		$data = $this->site_data_at_proxy_check( 'example.test', $assoc_args );
		$this->assertSame( [ $ssl, $wildcard ], [ $data['site_ssl'], $data['site_ssl_wildcard'] ] );
	}

	/**
	 * PHPT-17: `[--ssl]` is declared as a flag, so its `options:` list is never checked and `--ssl=foo` starts a full create.
	 *
	 * @group known-bug
	 * @group PHPT-17
	 */
	public function test_unknown_ssl_value_fails_validation() {
		$this->assert_ee_error(
			function () {
				$this->create_args( [ 'example.test' ], [ 'ssl' => 'foo' ] );
			},
			"Invalid value specified for 'ssl'"
		);
	}

	public function test_aliases_follow_the_site_name_and_admin_email_defaults_to_the_site() {
		$data = $this->site_data_at_webroot( 'Example.test', [ 'alias-domains' => ' www.example.test,,shop.test ' ] );
		$this->assertSame( 'example.test,www.example.test,shop.test', $data['alias_domains'] );
		$this->assertSame( 'admin@example.test', $data['app_admin_email'] );
	}

	public function test_existing_site_is_refused() {
		$this->insert_site( [ 'site_url' => 'example.test', 'site_type' => 'php' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test' );
			},
			'Site example.test already exists.'
		);
	}

	public function test_alias_of_another_site_is_refused() {
		$this->insert_site( [ 'site_url' => 'other.test', 'site_type' => 'php', 'alias_domains' => 'other.test,shop.test' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'alias-domains' => 'shop.test' ] );
			},
			'Site shop.test already exists as an alias domain for site: other.test.'
		);
	}

	public function test_invalid_alias_domain_is_refused() {
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'alias-domains' => 'ok.test,-bad.test' ] );
			},
			'Invalid alias domain(s): -bad.test.'
		);
	}

	/**
	 * PHPT-7: another site's name is accepted as an alias domain, so two sites claim the same VIRTUAL_HOST.
	 *
	 * @group known-bug
	 * @group PHPT-7
	 */
	public function test_existing_site_name_is_refused_as_an_alias() {
		$this->insert_site( [ 'site_url' => 'other.test', 'site_type' => 'php', 'alias_domains' => 'other.test' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'example.test', [ 'alias-domains' => 'other.test' ] );
			},
			'other.test'
		);
	}

	/**
	 * PHPT-4 (fixed in v1.12.0): `ab.test` gets the volumes `abtest_*` of the existing site `a-b.test`.
	 *
	 * @group PHPT-4
	 */
	public function test_site_whose_volume_prefix_is_taken_is_refused() {
		$this->insert_site( [ 'site_url' => 'a-b.test', 'site_type' => 'php' ] );
		$this->assert_ee_error(
			function () {
				$this->run_create( $this->make_site(), 'ab.test' );
			},
			'a-b.test'
		);
	}

	public function test_custom_ssl_needs_existing_key_and_cert() {
		$this->assertSame( 'cleanup', $this->run_create( $this->make_site(), 'example.test', [ 'ssl' => 'custom', 'ssl-crt' => '/nonexistent.crt' ] ) );
		$this->assertStringContainsString( 'Pass --ssl-key and --ssl-crt for custom SSL', $this->stderr() );
	}
}
