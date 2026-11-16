<?php

use EE\Tests\SiteType\SiteTypeTestCase;

/**
 * docker-compose.yml of PHP sites: PHP::dump_docker_compose_yml() → Site_PHP_Docker::generate_docker_compose_yml()
 * → templates/docker-compose.mustache, parsed back as YAML.
 */
class DockerComposeTest extends SiteTypeTestCase {

	const SITE_CLASS = 'EE\Site\Type\PHP';
	const SITE_TYPE  = 'php';

	/** @var string[] Volumes `docker volume create` made during the test. */
	private $created_volumes = [];

	/** @return array Parsed docker-compose.yml of a site with $data merged over a plain PHP site without a database. */
	private function compose( array $data = [], array $filters = [ 'nohttps' => true ] ) {
		$site            = $this->make_site();
		$site->site_data = $data + [
			'site_type'     => 'php',
			'site_url'      => 'example.test',
			'site_fs_path'  => WEBROOT . 'example.test',
			'app_sub_type'  => 'php',
			'cache_host'    => '',
			'db_host'       => '',
			'site_ssl'      => '',
			'php_version'   => 8.4,
			'alias_domains' => 'example.test',
		];
		$site->dump_docker_compose_yml( $filters );

		return self::yaml( file_get_contents( $site->site_data['site_fs_path'] . '/docker-compose.yml' ) );
	}

	public function test_default_site_runs_php_nginx_mailhog_and_postfix_from_img_versions() {
		$compose = $this->compose();
		$this->assertSame( [ 'php', 'nginx', 'mailhog', 'postfix' ], array_keys( $compose['services'] ) );
		$this->assertSame( self::image( 'easyengine/php8.4' ), $compose['services']['php']['image'] );
		$this->assertSame( self::image( 'easyengine/nginx' ), $compose['services']['nginx']['image'] );
		$this->assertSame( self::image( 'easyengine/mailhog' ), $compose['services']['mailhog']['image'] );
		$this->assertSame( self::image( 'easyengine/postfix' ), $compose['services']['postfix']['image'] );
		foreach ( $compose['services'] as $name => $service ) {
			$this->assertSame( [ 'io.easyengine.site=${VIRTUAL_HOST}' ], $service['labels'], $name );
			$this->assertSame( [ 'services_global-nginx-proxy_1:${VIRTUAL_HOST}' ], $service['external_links'], $name );
		}
		$this->assertSame( [ 'USER_ID', 'GROUP_ID', 'VIRTUAL_HOST', 'NEWRELIC_APPNAME=${VIRTUAL_HOST}', 'NEWRELIC_LICENSE_KEY' ], $compose['services']['php']['environment'] );
		$this->assertSame( [ 'VIRTUAL_HOST=${VIRTUAL_HOST}', 'VIRTUAL_PATH=/', 'HSTS=off', 'HTTPS_METHOD=nohttps' ], $compose['services']['nginx']['environment'] );
		$this->assertSame( [ 'example.test' => [ 'aliases' => [ '${VIRTUAL_HOST}_php' ] ] ], $compose['services']['php']['networks'] );
	}

	public function php_images() {
		return [
			'float default' => [ 8.4, 'easyengine/php8.4' ],
			'5.6'           => [ '5.6', 'easyengine/php5.6' ],
			'8.0'           => [ '8.0', 'easyengine/php8.0' ],
			'latest'        => [ 'latest', 'easyengine/php8.4' ],
		];
	}

	/**
	 * @dataProvider php_images
	 */
	public function test_php_image_follows_the_php_version( $php_version, $image ) {
		$this->assertSame( self::image( $image ), $this->compose( [ 'php_version' => $php_version ] )['services']['php']['image'] );
	}

	public function test_aliases_are_added_to_the_virtual_host_with_the_site_cert() {
		$env = $this->compose( [ 'alias_domains' => 'example.test,www.example.test' ], [] )['services']['nginx']['environment'];
		$this->assertSame( [ 'VIRTUAL_HOST=${VIRTUAL_HOST},www.example.test', 'VIRTUAL_PATH=/', 'HSTS=off', 'CERT_NAME=${VIRTUAL_HOST}' ], $env );
	}

	public function test_volumes_are_external_and_prefixed_with_the_docker_style_site_name() {
		$compose = $this->compose( [ 'site_url' => 'my-site.test', 'site_fs_path' => WEBROOT . 'my-site.test', 'alias_domains' => 'my-site.test' ] );
		$this->assertSame( [ 'htdocs:/var/www', 'config_php:/usr/local/etc', 'log_php:/var/log/php', 'newrelic_sock:/run/newrelic' ], $compose['services']['php']['volumes'] );
		$this->assertSame( [ 'htdocs:/var/www', 'config_nginx:/usr/local/openresty/nginx/conf', 'log_nginx:/var/log/nginx' ], $compose['services']['nginx']['volumes'] );
		$this->assertSame( 'mysitetest_htdocs', $compose['volumes']['htdocs']['name'] );
		$this->assertSame( 'global-newrelic-daemon_newrelic_sock', $compose['volumes']['newrelic_sock']['name'] );
		$this->assertCount( 9, $compose['volumes'] );
		foreach ( $compose['volumes'] as $volume ) {
			$this->assertTrue( $volume['external'] );
		}
	}

	public function service_variants() {
		return [
			'no db'               => [ [] ],
			'global db'           => [ [ 'app_sub_type' => 'mysql', 'db_host' => 'global-db' ] ],
			'local db'            => [ [ 'app_sub_type' => 'mysql', 'db_host' => 'db' ] ],
			'local db and redis'  => [ [ 'app_sub_type' => 'mysql', 'db_host' => 'db', 'cache_host' => 'redis' ] ],
			'global redis'        => [ [ 'cache_host' => 'global-redis' ] ],
		];
	}

	/**
	 * Every named volume and network a service uses is declared at the top level, so `docker-compose up` accepts the file.
	 *
	 * @dataProvider service_variants
	 */
	public function test_every_volume_and_network_a_service_uses_is_declared( $data ) {
		$compose = $this->compose( $data );
		foreach ( $compose['services'] as $name => $service ) {
			foreach ( isset( $service['volumes'] ) ? $service['volumes'] : [] as $volume ) {
				$source = explode( ':', $volume )[0];
				if ( '/' !== $source[0] ) {
					$this->assertArrayHasKey( $source, $compose['volumes'], "$name mounts $volume" );
				}
			}
			foreach ( array_keys( $service['networks'] ) as $network ) {
				$this->assertArrayHasKey( $network, $compose['networks'], "$name joins $network" );
			}
			foreach ( isset( $service['depends_on'] ) ? $service['depends_on'] : [] as $dependency ) {
				$this->assertArrayHasKey( $dependency, $compose['services'], "$name depends on $dependency" );
			}
		}
	}

	public function test_global_db_puts_php_on_the_backend_network() {
		$compose = $this->compose( [ 'app_sub_type' => 'mysql', 'db_host' => 'global-db' ] );
		$this->assertArrayHasKey( 'global-backend-network', $compose['services']['php']['networks'] );
		$this->assertArrayNotHasKey( 'db', $compose['services'] );
	}

	public function test_local_db_adds_a_mariadb_service_php_depends_on() {
		$compose = $this->compose( [ 'app_sub_type' => 'mysql', 'db_host' => 'db' ] );
		$db      = $compose['services']['db'];
		$this->assertSame( self::image( 'easyengine/mariadb' ), $db['image'] );
		$this->assertSame( [ 'MYSQL_ROOT_PASSWORD', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD' ], array_slice( $db['environment'], 0, 4 ) );
		$this->assertSame( [ 'db_data:/var/lib/mysql', 'db_conf:/etc/mysql', 'db_logs:/var/log/mysql' ], $db['volumes'] );
		$this->assertSame( [ 'db' ], $compose['services']['php']['depends_on'] );
		$this->assertSame( 'exampletest_db_logs', $compose['volumes']['db_logs']['name'] );
	}

	/**
	 * MIG-8: the local db gets compose's 10 s stop timeout, so a slow MariaDB shutdown is SIGKILLed.
	 *
	 * @group known-bug
	 * @group MIG-8
	 */
	public function test_local_db_gets_time_to_shut_down_cleanly() {
		$db = $this->compose( [ 'app_sub_type' => 'mysql', 'db_host' => 'db' ] )['services']['db'];
		$this->assertArrayHasKey( 'stop_grace_period', $db );
		$this->assertContains( 'MARIADB_AUTO_UPGRADE=1', $db['environment'] );
	}

	public function test_local_redis_adds_a_redis_service() {
		$compose = $this->compose( [ 'cache_host' => 'redis' ] );
		$this->assertSame( self::image( 'easyengine/redis' ), $compose['services']['redis']['image'] );
		$this->assertSame( [ 'redis' ], $compose['services']['php']['depends_on'] );
	}

	public function test_sysctl_config_tunes_php_nginx_db_and_redis() {
		$this->set_config( [ 'sysctl' => 'true' ] );
		$compose = $this->compose( [ 'app_sub_type' => 'mysql', 'db_host' => 'db', 'cache_host' => 'redis' ] );
		foreach ( [ 'php', 'nginx', 'db', 'redis' ] as $name ) {
			$this->assertCount( 9, $compose['services'][ $name ]['sysctls'], $name );
		}
		$this->assertArrayNotHasKey( 'sysctls', $compose['services']['mailhog'] );
	}

	public function test_dump_creates_the_site_volumes_once_with_site_labels() {
		$this->runner->on(
			'/^docker volume inspect (\S+)$/',
			function ( $command ) {
				return [ '', in_array( substr( $command, strlen( 'docker volume inspect ' ) ), $this->created_volumes, true ) ? 0 : 1 ];
			}
		);
		$this->runner->on(
			'/^docker volume create /',
			function ( $command ) {
				preg_match( '/(\S+)$/', trim( $command ), $m );
				$this->created_volumes[] = $m[1];

				return [ '', 0 ];
			}
		);
		$this->compose( [ 'app_sub_type' => 'mysql', 'db_host' => 'db' ] );
		$this->assertSame(
			[ 'exampletest_htdocs', 'exampletest_config_nginx', 'exampletest_log_nginx', 'exampletest_config_php', 'exampletest_log_php', 'exampletest_data_postfix', 'exampletest_ssl_postfix', 'exampletest_config_postfix', 'exampletest_db_data', 'exampletest_db_conf', 'exampletest_db_logs' ],
			$this->created_volumes
		);
		foreach ( $this->runner->matching( '/^docker volume create /' ) as $command ) {
			$this->assertStringContainsString( '--label "io.easyengine.site=example.test"', $command );
		}
	}
}
