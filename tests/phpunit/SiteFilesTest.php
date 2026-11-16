<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * Files a PHP create writes into the site root (PHP::configure_site_files(), generate_default_conf()):
 * .env, nginx main.conf, php custom.ini, the copied nginx custom confs and the placeholder index.php.
 */
class SiteFilesTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\PHP';
	const SITE_TYPE  = 'php';

	/** @return string Site root after configure_site_files() ran for $data merged over a PHP site without a database. */
	private function configure( array $data = [], $cache = false ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'              => 'php',
			'site_url'               => 'example.test',
			'site_fs_path'           => WEBROOT . 'example.test',
			'app_sub_type'           => 'php',
			'cache_host'             => $cache ? 'global-redis' : '',
			'db_host'                => '',
			'site_ssl'               => '',
			'php_version'            => 8.4,
			'alias_domains'          => 'example.test',
			'site_container_fs_path' => '/var/www/htdocs',
		];
		self::set_property( $site, 'cache_type', $cache );
		mkdir( $site->site_data['site_fs_path'] );
		self::call_private( $site, 'configure_site_files' );

		return $site->site_data['site_fs_path'];
	}

	/** @return array Site data of a `--with-db --local-db` site. */
	private static function local_db() {
		return [
			'app_sub_type'     => 'mysql',
			'db_host'          => 'db',
			'db_port'          => '3306',
			'db_name'          => 'example_test',
			'db_user'          => 'example.test-AbC123',
			'db_password'      => 'dbpass',
			'db_root_password' => 'rootpw',
		];
	}

	/** @return string[] Non-empty lines of the site's .env. */
	private static function env( $root ) {
		return array_values( array_filter( explode( "\n", file_get_contents( "$root/.env" ) ), 'strlen' ) );
	}

	public function test_env_names_the_site_and_the_process_user() {
		$user = posix_getpwuid( posix_geteuid() );
		$env  = self::env( $this->configure() );
		foreach ( [ 'VIRTUAL_HOST=example.test', 'VIRTUAL_HOST_EMAIL=example@example.test', 'USER_ID=' . $user['uid'], 'GROUP_ID=' . $user['gid'], 'NEWRELIC_LICENSE_KEY=', 'RELAY_HOST=', 'REPLY_EMAIL=' ] as $line ) {
			$this->assertContains( $line, $env );
		}
		$this->assertSame( [], preg_grep( '/^MYSQL_/', $env ) );
	}

	public function test_env_of_a_local_db_site_configures_mariadb() {
		$env = self::env( $this->configure( self::local_db() ) );
		$this->assertSame( [ 'MYSQL_ROOT_PASSWORD=rootpw', 'MYSQL_DATABASE=example_test', 'MYSQL_USER=example.test-AbC123', 'MYSQL_PASSWORD=dbpass' ], array_slice( $env, 0, 4 ) );
	}

	/**
	 * PHPT-14: .env and php.ini come from site-type-wp's templates, so a php site gets empty WORDPRESS_DB_* lines and needs site-type-wp installed.
	 *
	 * @group known-bug
	 * @group PHPT-14
	 */
	public function test_env_is_rendered_from_the_php_template() {
		$this->assertSame( [], preg_grep( '/^WORDPRESS_/', self::env( $this->configure() ) ) );
	}

	public function php_inis() {
		return [
			'5.6'     => [ '5.6', 'sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com' ],
			'default' => [ 8.4, 'sendmail_path = /usr/bin/msmtp -t' ],
		];
	}

	/**
	 * @dataProvider php_inis
	 */
	public function test_php_ini_sends_mail_the_way_the_php_image_supports( $php_version, $sendmail ) {
		$ini = file_get_contents( $this->configure( [ 'php_version' => $php_version ] ) . '/config/php/php/conf.d/custom.ini' );
		$this->assertStringContainsString( $sendmail, $ini );
	}

	public function test_nginx_conf_serves_all_domains_from_the_public_dir() {
		$conf = file_get_contents( $this->configure( [ 'alias_domains' => 'example.test,www.example.test', 'site_container_fs_path' => '/var/www/htdocs/public/' ] ) . '/config/nginx/conf.d/main.conf' );
		$this->assertStringContainsString( "\tserver_name example.test www.example.test;\n", $conf );
		$this->assertStringContainsString( "\troot /var/www/htdocs/public;\n", $conf );
		$this->assertStringContainsString( "upstream php {\n\tserver example.test_php:9000;\n}", $conf );
		$this->assertStringContainsString( '# PHP NGINX CONFIGURATION', $conf );
		$this->assertStringNotContainsString( 'upstream redis', $conf );
		$this->assertStringNotContainsString( 'wp-login.php', $conf );
	}

	public function test_nginx_conf_with_cache_reads_pages_from_redis() {
		$conf = file_get_contents( $this->configure( [], true ) . '/config/nginx/conf.d/main.conf' );
		$this->assertStringContainsString( "upstream redis {\n\tserver global-redis:6379;", $conf );
		$this->assertStringContainsString( 'srcache_fetch GET /redis-fetch $key;', $conf );
		$this->assertStringNotContainsString( '# PHP NGINX CONFIGURATION', $conf );
	}

	/**
	 * PHPT-8: pages are cached as `<site>:http…`, but `ee site clean` purges `<site>_page*`, so the page cache is never cleared.
	 *
	 * @group known-bug
	 * @group PHPT-8
	 */
	public function test_nginx_page_cache_key_has_the_prefix_site_clean_purges() {
		$conf = file_get_contents( $this->configure( [], true ) . '/config/nginx/conf.d/main.conf' );
		$this->assertStringContainsString( 'set $key "example.test_page:http$request_method$host$request_uri";', $conf );
	}

	public function test_index_php_points_at_the_webroot_and_files_are_handed_to_www_data() {
		$root = $this->configure( [ 'site_container_fs_path' => '/var/www/htdocs/public' ] );
		$this->assertStringContainsString( "You can copy your site files to $root/app/htdocs/public.", file_get_contents( "$root/app/htdocs/public/index.php" ) );
		$this->assertSame( "docker-compose -f docker-compose.yml  exec -T --user='root' php chown -R www-data: /var/www/", end( $this->runner->calls )['command'] );
		$this->assertSame( $root, getcwd() );
		$this->assertStringContainsString( 'Configuration files copied.', $this->stdout() );
	}

	public function test_custom_nginx_confs_are_copied_from_the_php_templates() {
		$root = $this->configure();
		$this->assertFileEquals( SITE_PHP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache', "$root/config/nginx/custom/user.conf" );
		$this->assertFileEquals( SITE_PHP_TEMPLATE_ROOT . '/config/nginx/admin-tools.conf.mustache', "$root/config/nginx/custom/admin-tools.conf" );
	}

	public function test_containers_are_started_and_restarted_around_the_config() {
		$this->configure( self::local_db() );
		$compose = 'docker-compose -f docker-compose.yml ';
		$this->assertCount( 1, $this->runner->matching( '/^' . preg_quote( "$compose up -d nginx postfix", '/' ) . '$/' ) );
		$this->assertCount( 1, $this->runner->matching( '/^' . preg_quote( "$compose restart nginx php", '/' ) . '$/' ) );
		$this->assertCount( 1, $this->runner->matching( '#^openssl req .*CN=smtp\.example\.test#' ) );
	}

	public function test_failed_container_start_rolls_the_create_back() {
		$this->runner->on( '/ up -d nginx postfix$/', '', 1 );
		try {
			$this->configure();
			$this->fail( 'Expected the create to roll back.' );
		} catch ( EE\Tests\SiteType\StopCreate $e ) {
			$this->assertSame( 'cleanup', $e->point );
		}
		$this->assertStringContainsString( 'There was some error in docker-compose up.', $this->stderr() );
	}
}
