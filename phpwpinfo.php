<?php
/*
Copyright 2012-2026 - Amaury Balmer (amaury@beapi.fr)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Suppress DateTime warnings
date_default_timezone_set( @date_default_timezone_get() );

// Auth only for PHP/Apache
if ( strpos( PHP_SAPI, 'cgi' ) === false ) {
	define( 'LOGIN', 'wordpress' );
	define( 'PASSWORD', 'wordpress' );

	if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || ( $_SERVER['PHP_AUTH_PW'] !== PASSWORD || $_SERVER['PHP_AUTH_USER'] !== LOGIN ) ) {
		header( 'WWW-Authenticate: Basic realm="Authentification"' );
		header( 'HTTP/1.0 401 Unauthorized' );
		echo 'Authentification failed';
		exit();
	}
}

function phpwpinfo() {
	$info = new PHP_WP_Info();
	$info->init_all_tests();
}

class PHP_WP_Info {

	public const VERSION = '1.6.2';

	private bool $debug_mode = true;

	/** Minimum PHP version (compare with version_compare; display uses ≥ prefix in UI). */
	private const MIN_PHP_VERSION = '7.4';

	/** Minimum cURL extension version (libcurl) for reporting in PHP extensions table. */
	private const MIN_CURL_VERSION = '7.38';

	/** Minimum database server versions supported by current WordPress requirements. */
	private const MIN_MYSQL_VERSION   = '8.0';
	private const MIN_MARIADB_VERSION = '10.6';

	/** Minimum Redis server version checked against INFO (see https://endoflife.date/redis). */
	private const MIN_REDIS_VERSION = '6.2';

	private $db_infos = array();
	private $db_link  = false;

	private $redis_infos = array();
	private $redis_link  = false;

	public function __construct() {
		// Use file sessions: php.ini may use Redis without AUTH in save_path, which throws RedisException NOAUTH.
		$handler = ini_get( 'session.save_handler' );
		if ( in_array( $handler, array( 'redis', 'rediscluster' ), true ) ) {
			$session_dir = sys_get_temp_dir() . '/phpwpinfo_sessions';
			if ( ! is_dir( $session_dir ) ) {
				@mkdir( $session_dir, 0700, true );
			}
			ini_set( 'session.save_handler', 'files' );
			ini_set( 'session.save_path', $session_dir );
		}
		@session_start();

		if ( $this->debug_mode === true ) {
			ini_set( 'display_errors', 1 );
			ini_set( 'log_errors', 1 );
			ini_set( 'error_log', __DIR__ . '/error_log.txt' );
			error_reporting( E_ALL );
		}

		// Check GET for phpinfo
		if ( isset( $_GET['phpinfo'] ) && $_GET['phpinfo'] === 'true' ) {
			phpinfo();
			exit();
		}

		// Check GET for self-destruction
		if ( isset( $_GET['self-destruction'] ) && $_GET['self-destruction'] === 'true' ) {
			@unlink( __FILE__ );
			clearstatcache();
			if ( is_file( __FILE__ ) ) {
				die( 'Self-destruction KO ! Sorry, but you must remove me manually !' );
			}
			die( 'Self-destruction OK !' );
		}

		$this->_check_request_database();
		$this->_check_request_redis();
		$this->_check_request_adminer();
		$this->_check_request_wordpress();
	}

	public function init_all_tests() {
		$this->get_header();

		$this->test_versions();
		$this->test_php_config();
		$this->test_php_extensions();
		$this->test_database_config();
		$this->test_apache_modules();
		$this->test_apache_directory_indexes();
		$this->test_form_mail();
		$this->test_form_redis();
		$this->test_form_connectivity();

		$this->get_footer();
	}

	/**
	 * Parses mysqli_get_server_info() output into engine flavor and comparable version.
	 *
	 * MariaDB often reports "5.5.5-10.11.6-MariaDB"; the real release is the second x.y.z group.
	 *
	 * @return array{flavor:string,version:string,raw:string} flavor is mysql or mariadb.
	 */
	private function parse_database_server_info( string $server_info ): array {
		$raw = $server_info;
		if ( stripos( $server_info, 'mariadb' ) !== false ) {
			$version = '';
			if ( preg_match( '/-(\d+\.\d+\.\d+(?:\.\d+)?)-MariaDB/i', $server_info, $matches ) ) {
				$version = $matches[1];
			} elseif ( preg_match( '/(\d+\.\d+\.\d+(?:\.\d+)?)-MariaDB/i', $server_info, $matches ) ) {
				$version = $matches[1];
			} elseif ( preg_match( '/(\d+\.\d+\.\d+)/', $server_info, $matches ) ) {
				$version = $matches[1];
			}

			return array(
				'flavor'  => 'mariadb',
				'version' => $version,
				'raw'     => $raw,
			);
		}

		$version = preg_replace( '/[^0-9.].*/', '', $server_info );
		$version = rtrim( $version, '.' );

		return array(
			'flavor'  => 'mysql',
			'version' => $version,
			'raw'     => $raw,
		);
	}

	/**
	 * Main test, check if php/databse/git are installed and right version for WP
	 */
	public function test_versions() {
		$this->html_table_open(
			'General informations & tests PHP/Database Version',
			'',
			'Required',
			'Recommended',
			'Current'
		);

		// Webserver used
		$this->html_table_row( 'Web server', $this->_get_current_webserver(), '', '', 'info', 3 );

		// Test PHP Version
		if ( strpos( PHP_SAPI, 'cgi' ) !== false ) {
			$this->html_table_row( 'PHP Type', 'CGI with Apache Worker or another webserver', '', '', 'success', 3 );
		} else {
			$this->html_table_row( 'PHP Type', 'Apache Module (low performance)', '', '', 'warning', 3 );
		}

		// Test PHP Version
		$php_version        = PHP_VERSION;
		$php_required_label = '≥' . self::MIN_PHP_VERSION;
		if ( version_compare( $php_version, self::MIN_PHP_VERSION, '>=' ) ) {
			$this->html_table_row( 'PHP Version', $php_required_label, '> 7.3', $php_version, 'success' );
		} else {
			$this->html_table_row( 'PHP Version', $php_required_label, '> 7.3', $php_version, 'error' );
		}

		// Test Database Client extensions/version.
		if ( ! extension_loaded( 'mysqli' ) || ! is_callable( 'mysqli_connect' ) ) {
			$this->html_table_row( 'PHP MySQLi Extension', 'Yes', 'Yes', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'PHP MySQLi Extension', 'Yes', 'Yes', 'Installed', 'success' );
			$this->html_table_row(
				'PHP MySQLi Client Version',
				'-',
				'-',
				mysqli_get_client_info(),
				'info'
			);
		}

		// Test Database Server Version (MySQL 8.0+ or MariaDB 10.6+ per WordPress).
		if ( $this->db_link !== false && is_callable( 'mysqli_get_server_info' ) ) {
			$server_info    = mysqli_get_server_info( $this->db_link );
			$parsed         = $this->parse_database_server_info( $server_info );
			$min_required   = $parsed['flavor'] === 'mariadb' ? self::MIN_MARIADB_VERSION : self::MIN_MYSQL_VERSION;
			$required_label = $parsed['flavor'] === 'mariadb'
				? 'MariaDB ' . self::MIN_MARIADB_VERSION . '+'
				: 'MySQL ' . self::MIN_MYSQL_VERSION . '+';
			$ok             = $parsed['version'] !== '' && version_compare( $parsed['version'], $min_required, '>=' );
			if ( $ok ) {
				$this->html_table_row( 'Database Version', $required_label, 'WP-supported', $server_info, 'success' );
			} else {
				$this->html_table_row( 'Database Version', $required_label, 'WP-supported', $server_info, 'error' );
			}
		} else {
			// Show Database Form
			$this->html_form_database( $this->db_infos === false );

			$this->html_table_row(
				'Database Version',
				'MySQL ' . self::MIN_MYSQL_VERSION . '+ or MariaDB ' . self::MIN_MARIADB_VERSION . '+',
				'-',
				'Not available, needs database credentials.',
				'warning'
			);
		}

		// Test if the server is connected to the server by attempt to find the IP(v4) of www.google.fr
		if ( gethostbyname( 'www.google.fr' ) !== 'www.google.fr' ) {
			$this->html_table_row( 'Internet connectivity (Google)', 'No', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'Internet connectivity (Google)', 'No', 'Yes', 'No', 'error' );
		}

		// Test if the command 'git' exists, so it tests if Git is installed
		if ( $this->_command_exists( 'git' ) === true ) {
			$this->html_table_row( 'GIT is installed?', 'No', 'Yes', 'Yes' );
		} else {
			$this->html_table_row( 'GIT is installed?', 'No', 'Yes', 'No', 'error' );
		}

		$this->html_table_row( 'Remote IP via $_SERVER["REMOTE_ADDR"]', '', '', $_SERVER['REMOTE_ADDR'], 'info' );

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$this->html_table_row(
				'Remote IP via $_SERVER["HTTP_X_FORWARDED_FOR"]',
				'',
				'',
				$_SERVER['HTTP_X_FORWARDED_FOR'],
				'info'
			);
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED'] ) ) {
			$this->html_table_row(
				'Remote IP via $_SERVER["HTTP_X_FORWARDED"]',
				'',
				'',
				$_SERVER['HTTP_X_FORWARDED'],
				'info'
			);
		}

		if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$this->html_table_row(
				'Remote IP via $_SERVER["HTTP_CLIENT_IP"]',
				'',
				'',
				$_SERVER['HTTP_CLIENT_IP'],
				'info'
			);
		}

		$this->html_table_row(
			'Public IP via browser fetch (api.ipify.org). May not work if Content-Security-Policy blocks connect-src to that host.',
			'',
			'',
			'Loading…',
			'warning realip'
		);
	}

	public function test_php_extensions() {
		$this->html_table_open( 'PHP Extensions', '', 'Required', 'Recommended', 'Current' );

		$extensions = array(
			// Higly recommanded
			'json'      => 'error',
			'curl'      => 'error',
			'dom'       => 'error',
			'exif'      => 'info',
			'fileinfo'  => 'info',
			'igbinary'  => 'info',
			'intl'      => 'error',
			'mbstring'  => 'error',
			'openssl'   => 'error',
			'pcre'      => 'error',
			'zlib'      => 'error',
			'iconv'     => 'error',
			'xml'       => 'error',
			'zip'       => 'info',
			// Optional
			'xmlreader' => 'error',     // TO DELETE? (Not in handbook)
			// Cache
			'apcu'      => 'info',
			'memcache'  => 'info',      // TO DELETE? (Not in handbook)
			'memcached' => 'info',
			'redis'     => 'info',
			// Others
			'ftp'       => 'info',
			'ssh2'      => 'info',
			'sockets'   => 'info',
			// Debug
			'blackfire' => 'info',
			'newrelic'  => 'info',
			'xdebug'    => 'info',
			// Deprecated ?
			'suhosin'   => 'info',      // TO DELETE? (Not in handbook + Deprecated)
			'tidy'      => 'info',      // TO DELETE? (Not in handbook)
		);

		foreach ( $extensions as $extension => $status ) {
			if ( ! extension_loaded( $extension ) ) {
				$is_wp_requirements = ( 'error' === $status ) ? 'Yes' : 'No';
				$this->html_table_row( $extension, $is_wp_requirements, 'Yes', 'Not installed', $status );
			} else {
				$this->html_table_row( $extension, 'Yes', 'Yes', 'Installed', 'success' );
			}
		}

		/**
		 * Check GD and Imagick like WordPress does.
		 */
		$gd      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick', false ) && class_exists(
			'ImagickPixel',
			false
		) && version_compare(
			phpversion( 'imagick' ),
			'2.2.0',
			'>='
		);

		// GD/Imagick lib.
		if ( $gd ) {
			$this->html_table_row( 'Image manipulation (GD)', 'Yes', 'Yes', 'Installed', 'success' );
		}

		if ( $imagick ) {
			$this->html_table_row( 'Image manipulation (Imagick)', 'Yes', 'Yes', 'Installed', 'success' );
		}

		if ( ! $gd && ! $imagick ) {
			$this->html_table_row( 'Image manipulation (GD, Imagick)', 'Yes', 'Yes', 'Not installed', 'error' );
		}

		if ( is_callable( 'opcache_reset' ) ) {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)',
				'No',
				'Yes',
				'Zend OPcache Installed',
				'success'
			);
		} elseif ( is_callable( 'eaccelerator_put' ) ) {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)',
				'No',
				'Yes',
				'eAccelerator Installed',
				'success'
			);
		} elseif ( is_callable( 'xcache_set' ) ) {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)',
				'No',
				'Yes',
				'XCache Installed',
				'success'
			);
		} elseif ( is_callable( 'apc_store' ) ) {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)',
				'No',
				'Yes',
				'APC Installed',
				'success'
			);
		} elseif ( is_callable( 'zend_optimizer_version' ) ) {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizerr)',
				'No',
				'Yes',
				'Zend Optimizer Installed',
				'success'
			);
		} else {
			$this->html_table_row(
				'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)',
				'No',
				'Yes',
				'Not installed',
				'warning'
			);
		}

		if ( ! is_callable( 'finfo_open' ) && ! is_callable( 'mime_content_type' ) ) {
			$this->html_table_row( 'Mime type', 'Yes*', 'Yes', 'Not installed', 'warning' );
		} else {
			$this->html_table_row( 'Mime type', 'Yes*', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'hash' ) && ! is_callable( 'mhash' ) ) {
			$this->html_table_row( 'Hash', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Hash', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'set_time_limit' ) ) {
			$this->html_table_row( 'set_time_limit', 'No', 'Yes', 'Not Available', 'info' );
		} else {
			$this->html_table_row( 'set_time_limit', 'No', 'Yes', 'Available', 'success' );
		}

		if ( extension_loaded( 'curl' ) ) {
			$curl = curl_version();
			$this->html_table_row(
				'Curl version',
				'-',
				self::MIN_CURL_VERSION,
				sprintf( '%s %s', $curl['version'], $curl['ssl_version'] ),
				'info'
			);
		}

		$this->html_table_close( '(*) Entries marked with an asterisk are outside the official WordPress requirements but are strongly recommended for production environments.' );
	}

	public function test_apache_modules() {
		if ( $this->_get_current_webserver() !== 'Apache' ) {
			return false;
		}

		$current_modules = (array) $this->_get_apache_modules();
		$modules         = array(
			'mod_deflate'  => false,
			'mod_env'      => false,
			'mod_expires'  => false,
			'mod_headers'  => false,
			'mod_filter'   => false,
			'mod_mime'     => false,
			'mod_rewrite'  => true,
			'mod_setenvif' => false,
		);

		$this->html_table_open( 'Apache Modules', '', 'Required', 'Recommended', 'Current' );

		foreach ( $modules as $module => $is_required ) {
			$is_required = ( $is_required === true ) ? 'Yes' : 'No'; // Boolean to Yes/NO

			$name = ucfirst( str_replace( 'mod_', '', $module ) );
			if ( ! in_array( $module, $current_modules, true ) ) {
				$this->html_table_row( $name, $is_required, 'Recommended', 'Not installed', 'error' );
			} else {
				$this->html_table_row( $name, $is_required, 'Recommended', 'Installed', 'success' );
			}
		}

		$this->html_table_close();

		return true;
	}

	/**
	 * Probe whether Apache directory listing (Options +Indexes / mod_autoindex) is effective for a folder without an index file.
	 */
	public function test_apache_directory_indexes() {
		if ( $this->_get_current_webserver() !== 'Apache' ) {
			return false;
		}

		$this->html_table_open( 'Apache directory listing (Options Indexes)', '', 'Required', 'Recommended', 'Current' );

		$probe_dir = __DIR__ . '/indexes_probe';
		if ( ! is_dir( $probe_dir ) ) {
			$this->html_table_row(
				'indexes_probe/',
				'-',
				'-',
				'Missing (ship indexes_probe/ with phpwpinfo.php)',
				'info'
			);
			$this->html_table_close(
				'This check requests indexes_probe/ (no index file). If directory listing is enabled, Apache returns an "Index of" page — a security risk on production sites.'
			);

			return false;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			$this->html_table_row( 'Directory listing (autoindex)', 'Off', 'Off', 'cURL extension required', 'info' );
			$this->html_table_close();

			return false;
		}

		$url    = $this->_get_indexes_probe_url();
		$result = $this->_http_get_local_url( $url );

		if ( $result['error'] !== null ) {
			$this->html_table_row(
				'Directory listing (autoindex)',
				'Off',
				'Off',
				htmlspecialchars( $result['error'], ENT_QUOTES, 'UTF-8' ),
				'info'
			);
			$this->html_table_close();

			return false;
		}

		$body    = is_string( $result['body'] ) ? $result['body'] : '';
		$listing = $this->_response_looks_like_apache_directory_listing( $body );

		if ( $listing ) {
			$this->html_table_row(
				'Directory listing (autoindex)',
				'Off',
				'Off',
				'Enabled (Options +Indexes or equivalent)',
				'error'
			);
		} else {
			$this->html_table_row(
				'Directory listing (autoindex)',
				'Off',
				'Off',
				'Not shown (HTTP ' . (int) $result['code'] . ')',
				'success'
			);
		}

		$this->html_table_close(
			'Uses an HTTP GET to ' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . ' and detects typical mod_autoindex HTML ("Index of" in title or h1).'
		);

		return true;
	}

	public function test_php_config() {
		$this->html_table_open( 'PHP Configuration', '', 'Required', 'Recommended', 'Current' );

		$value = date_default_timezone_get();
		if ( empty( $value ) ) {
			$this->html_table_row( 'date_default_timezone ', '-', '-', 'Not empty value', 'warning' );
		} else {
			$this->html_table_row( 'date_default_timezone ', '-', '-', $value, 'info' );
		}

		$value = ini_get( 'register_argc_argv ' );
		if ( strtolower( $value ) === 'on' ) {
			$this->html_table_row( 'register_argc_argv ', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_argc_argv ', '-', 'Off', 'Off', 'success' );
		}

		$value = $this->return_bytes( ini_get( 'memory_limit' ) );
		if ( $value !== '-1' && (int) $value < $this->return_bytes( '64M' ) ) {
			$this->html_table_row( 'memory_limit', '64 MB', '256 MB', $this->_format_bytes( $value ), 'error' );
		} else {
			$status = ( (int) $value >= $this->return_bytes( '256M' ) || $value === '-1' ) ? 'success' : 'warning';
			$this->html_table_row( 'memory_limit', '64 MB', '256 MB', $this->_format_bytes( $value ), $status );
		}

		$value = ini_get( 'max_input_vars' );
		if ( (int) $value < 5000 ) {
			$this->html_table_row( 'max_input_vars', '5000', '10000', $value, 'error' );
		} else {
			$status = ( (int) $value >= 10000 ) ? 'success' : 'warning';
			$this->html_table_row( 'max_input_vars', '5000', '10000', $value, $status );
		}

		$value = ini_get( 'max_execution_time' );
		if ( $value !== '-1' && (int) $value < 60 ) {
			$this->html_table_row( 'max_execution_time', '-', '300', $value, 'error' );
		} else {
			$status = ( (int) $value >= 300 || $value === '-1' ) ? 'success' : 'warning';
			$this->html_table_row( 'max_execution_time', '-', '300', $value, $status );
		}

		$value = ini_get( 'max_input_time' );
		if ( $value !== '-1' && (int) $value < 60 ) {
			$this->html_table_row( 'max_input_time', '-', '300', $value, 'error' );
		} else {
			$status = ( (int) $value >= 300 || $value === '-1' ) ? 'success' : 'warning';
			$this->html_table_row( 'max_input_time', '-', '300', $value, $status );
		}

		$value = ini_get( 'file_uploads' );
		if ( strtolower( $value ) === 'on' || $value === '1' ) {
			$this->html_table_row( 'file_uploads', 'On', 'On', 'On', 'success' );
		} else {
			$this->html_table_row( 'file_uploads', 'On', 'On', 'Off', 'error' );
		}

		$value = $this->return_bytes( ini_get( 'upload_max_filesize' ) );
		if ( (int) $value < $this->return_bytes( '32M' ) ) {
			$this->html_table_row( 'upload_max_filesize', '32 MB', '128 MB', $this->_format_bytes( $value ), 'error' );
		} else {
			$status = ( (int) $value >= $this->return_bytes( '128M' ) ) ? 'success' : 'warning';
			$this->html_table_row( 'upload_max_filesize', '32 MB', '128 MB', $this->_format_bytes( $value ), $status );
		}

		$value = $this->return_bytes( ini_get( 'post_max_size' ) );
		if ( (int) $value < $this->return_bytes( '32M' ) ) {
			$this->html_table_row( 'post_max_size', '32 MB', '128 MB', $this->_format_bytes( $value ), 'warning' );
		} else {
			$status = ( (int) $value >= $this->return_bytes( '128M' ) ) ? 'success' : 'warning';
			$this->html_table_row( 'post_max_size', '32 MB', '128 MB', $this->_format_bytes( $value ), $status );
		}

		$value = ini_get( 'short_open_tag' );
		if ( strtolower( $value ) === 'on' ) {
			$this->html_table_row( 'short_open_tag', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'short_open_tag', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'open_basedir' );
		$this->html_table_row( 'open_basedir', $value, '', '', 'info', 3 );

		$value = ini_get( 'zlib.output_compression' );
		$this->html_table_row( 'zlib.output_compression', $value, '', '', 'info', 3 );

		$value = ini_get( 'output_handler' );
		$this->html_table_row( 'output_handler', $value, '', '', 'info', 3 );

		$value = ini_get( 'disable_functions' );
		$this->html_table_row( 'disable_functions', $value, '', '', 'info', 3 );

		$value = ini_get( 'expose_php' );
		if ( $value === '0' || strtolower( $value ) === 'off' || empty( $value ) ) {
			$this->html_table_row( 'expose_php', '-', '0 or Off', $value, 'success' );
		} else {
			$this->html_table_row( 'expose_php', '-', '0 or Off', $value, 'error' );
		}

		$value              = ini_get( 'allow_url_fopen' );
		$allow_url_fopen_on = ( strtolower( (string) $value ) === 'on' || $value === '1' );
		if ( $allow_url_fopen_on ) {
			$this->html_table_row( 'allow_url_fopen', '-', 'Off', $value, 'warning' );
		} else {
			$this->html_table_row( 'allow_url_fopen', '-', 'Off', $value, 'success' );
		}

		$value = ini_get( 'upload_tmp_dir' );
		$this->html_table_row( 'upload_tmp_dir', $value, '', '', 'info', 3 );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'upload_tmp_dir writable ?', '-', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'upload_tmp_dir writable ?', '-', 'Yes', 'No', 'error' );
		}

		$value = '/tmp/';
		$this->html_table_row( 'System temp dir', $value, '', '', 'info', 3 );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'System temp dir writable ?', '-', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'System temp dir writable ?', '-', 'Yes', 'No', 'error' );
		}

		$value = __DIR__;
		$this->html_table_row( 'Current dir', $value, '', '', 'info', 3 );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'Yes', 'No', 'error' );
		}

		if ( is_callable( 'apc_store' ) ) {
			$value = $this->return_bytes( ini_get( 'apc.shm_size' ) );
			if ( (int) $value < $this->return_bytes( '32M' ) ) {
				$this->html_table_row( 'apc.shm_size', '32 MB', '128 MB', $this->_format_bytes( $value ), 'error' );
			} else {
				$status = ( (int) $value >= $this->return_bytes( '128M' ) ) ? 'success' : 'warning';
				$this->html_table_row( 'apc.shm_size', '32 MB', '128 MB', $this->_format_bytes( $value ), $status );
			}
		}

		$this->html_table_close();
	}

	/**
	 * Convert PHP variable (G/M/K) to bytes
	 * Source: https://php.net/manual/fr/function.ini-get.php
	 * @return int|string
	 *
	 * @param $val
	 */
	public function return_bytes( $val ) {
		$val  = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$val  = (int) $val;
		switch ( $last ) {
			// Le modifieur 'G' est disponible depuis PHP 5.1.0
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}

		return $val;
	}

	/**
	 * SQL query cache was removed in MySQL 8.0+ and MariaDB 10.5.2+.
	 *
	 * @return bool
	 */
	private function _db_supports_sql_query_cache() {
		if ( $this->db_link === false || ! is_callable( 'mysqli_get_server_version' ) ) {
			return false;
		}
		$version_int = mysqli_get_server_version( $this->db_link );
		if ( ! is_int( $version_int ) || $version_int <= 0 ) {
			return false;
		}
		$major = intdiv( $version_int, 10000 );
		$minor = intdiv( $version_int % 10000, 100 );
		$patch = $version_int % 100;

		$server_info = is_callable( 'mysqli_get_server_info' ) ? mysqli_get_server_info( $this->db_link ) : '';
		$is_mariadb  = is_string( $server_info ) && stripos( $server_info, 'mariadb' ) !== false;
		// MariaDB 10.x+ uses the same numeric encoding; MySQL never shipped 10.x.
		if ( ! $is_mariadb && $major >= 10 ) {
			$is_mariadb = true;
		}

		if ( $is_mariadb ) {
			if ( $major > 10 ) {
				return false;
			}
			if ( $major === 10 && ( $minor > 5 || ( $minor === 5 && $patch >= 2 ) ) ) {
				return false;
			}

			return true;
		}

		return $major < 8;
	}

	/**
	 * @return void
	 */
	public function test_database_config() {
		if ( $this->db_link === false ) {
			return;
		}

		$this->html_table_open( 'Database Configuration', '', 'Required', 'Recommended', 'Current' );

		if ( $this->_db_supports_sql_query_cache() ) {
			$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'have_query_cache'" );
			if ( $result !== false ) {
				while ( $row = mysqli_fetch_assoc( $result ) ) {
					if ( strtolower( $row['Value'] ) === 'yes' ) {
						$this->html_table_row( 'Query cache', '-', 'False', $row['Value'], 'error' );
					} else {
						$this->html_table_row( 'Query cache', '-', 'False', $row['Value'], 'success' );
					}
				}
			}

			$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'query_cache_size'" );
			if ( $result !== false ) {
				while ( $row = mysqli_fetch_assoc( $result ) ) {
					if ( (int) $row['Value'] >= $this->return_bytes( '8M' ) ) {
						$status = ( (int) $row['Value'] >= $this->return_bytes( '64M' ) ) ? 'success' : 'warning';
						$this->html_table_row(
							'Query cache size',
							'8M',
							'64MB',
							$this->_format_bytes( (int) $row['Value'] ),
							$status
						);
					} else {
						$this->html_table_row(
							'Query cache size',
							'8M',
							'64MB',
							$this->_format_bytes( (int) $row['Value'] ),
							'error'
						);
					}
				}
			}

			$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'query_cache_type'" );
			if ( $result !== false ) {
				while ( $row = mysqli_fetch_assoc( $result ) ) {
					if ( strtolower( $row['Value'] ) === 'on' || strtolower( $row['Value'] ) === '1' ) {
						$this->html_table_row(
							'Query cache type',
							'0 or off',
							'1 or on',
							strtolower( $row['Value'] ),
							'error'
						);
					} else {
						$this->html_table_row(
							'Query cache type',
							'0',
							$row['Value'],
							strtolower( $row['Value'] ),
							'success'
						);
					}
				}
			}
		}

		$is_log_slow_queries = false;
		$result              = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'log_slow_queries'" );
		if ( $result !== false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( strtolower( $row['Value'] ) === 'yes' || strtolower( $row['Value'] ) === 'on' ) {
					$is_log_slow_queries = true;
					$this->html_table_row( 'Log slow queries', 'No', 'Yes', 'Yes', 'success' );
				} else {
					$is_log_slow_queries = false;
					$this->html_table_row( 'Log slow queries', 'No', 'Yes', 'False', 'error' );
				}
			}
		}

		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'long_query_time'" );
		if ( $is_log_slow_queries === true && $result !== false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( (int) $row['Value'] <= 2 ) {
					$this->html_table_row( 'Long query time (sec)', '2', '1', ( (int) $row['Value'] ), 'success' );
				} else {
					$this->html_table_row( 'Long query time (sec)', '2', '1', ( (int) $row['Value'] ), 'error' );
				}
			}
		}
	}

	public function test_form_mail() {
		$this->html_table_open( 'Email Configuration', '', '', '' );
		$this->html_form_email();
		$this->html_table_close();
	}

	/**
	 * Start HTML with inline CSS (no external stylesheets).
	 */
	public function get_header() {
		$output  = '';
		$output .= '<!DOCTYPE html>' . "\n";
		$output .= '<html lang="en">' . "\n";
		$output .= '<head>' . "\n";
		$output .= '<meta charset="utf-8">' . "\n";
		$output .= '<meta name="robots" content="noindex,nofollow">' . "\n";
		$output .= '<title>PHPWPInfo ' . self::VERSION . '</title>' . "\n";
		$output .= '<link rel="icon" href="favicon.ico" sizes="32x32">' . "\n";
		$output .= '<style>' . "\n";
		$output .= <<<'CSS'
body{margin:0;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#333;background:#fff;}
a{color:#08c;text-decoration:none;}
a:hover{color:#005580;text-decoration:underline;}
.container{width:940px;margin:0 auto;max-width:100%;padding:0 20px;box-sizing:border-box;}
.navbar{margin-bottom:20px;overflow:visible;}
.navbar-inner{min-height:40px;padding:10px 20px;background:#f8f8f8;border:1px solid #d4d4d4;border-radius:4px;overflow:visible;}
.navbar-inner:before,.navbar-inner:after{display:table;content:"";line-height:0;}
.navbar-inner:after{clear:both;}
.navbar .brand{float:left;padding:8px 20px 8px 0;font-size:20px;font-weight:200;color:#777;}
.navbar .brand .version-label{font-size:11px;font-weight:600;line-height:1.2;padding:2px 7px;margin-left:10px;vertical-align:middle;color:#666;background:#eee;border:1px solid #ddd;border-radius:3px;}
.navbar .nav{float:right;list-style:none;margin:0;padding:0;}
.navbar .nav>li{float:left;position:relative;line-height:20px;}
.navbar .nav>li>a{padding:10px 15px;display:block;color:#777;}
.navbar .nav>li>a:hover{color:#333;background:#eee;text-decoration:none;}
.pull-right{float:right;}
.dropdown-menu{display:none;position:absolute;top:100%;left:0;z-index:1000;min-width:180px;padding:5px 0;margin:2px 0 0;list-style:none;background:#fff;border:1px solid #ccc;border-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,.15);}
.dropdown.open>.dropdown-menu{display:block;}
.dropdown-menu>li>a{display:block;padding:3px 20px;clear:both;color:#333;white-space:nowrap;}
.dropdown-menu>li>a:hover{background:#08c;color:#fff;text-decoration:none;}
.caret{display:inline-block;width:0;height:0;margin-left:4px;vertical-align:middle;border-top:4px solid #333;border-right:4px solid transparent;border-left:4px solid transparent;opacity:.6;}
caption{font-weight:700;font-size:18px;margin-bottom:20px;text-align:left;}
.table{width:100%;margin-bottom:20px;border-collapse:collapse;}
.table-bordered,.table.table-bordered{border:1px solid #ddd;border-radius:4px;border-collapse:separate;}
.table th,.table td{padding:8px;line-height:20px;border-top:1px solid #ddd;vertical-align:top;}
.table-bordered th,.table-bordered td{border-left:1px solid #ddd;}
.table-bordered tr th:first-child,.table-bordered tr td:first-child{border-left:0;}
.description{margin:-10px 0 20px 0;}
.table tbody tr td{word-break:break-word;}
.table tbody tr.success td{background-color:#dff0d8;}
.table tbody tr.error td{background-color:#f2dede;}
.table tbody tr.warning td{background-color:#fcf8e3;}
.table tbody tr.info td{background-color:#d9edf7;}
.form-inline .input-small,.form-inline .input-large,.form-inline .btn,.form-inline label{margin-right:8px;margin-bottom:4px;display:inline-block;vertical-align:middle;}
.form-inline .checkbox input{width:auto;margin-right:4px;}
.input-small{width:130px;padding:4px 6px;border:1px solid #ccc;border-radius:3px;box-sizing:border-box;}
.input-large{width:220px;padding:4px 6px;border:1px solid #ccc;border-radius:3px;box-sizing:border-box;}
.btn{display:inline-block;padding:4px 12px;font-size:14px;text-align:center;cursor:pointer;border:1px solid #ccc;border-radius:4px;background:#f5f5f5;color:#333;}
.btn:hover{background:#e6e6e6;text-decoration:none;}
.checkbox{padding:4px 0;display:inline-block;}
.help-inline{display:inline-block;margin-left:4px;color:#999;vertical-align:middle;max-width:480px;font-size:13px;line-height:1.35;}
.alert{padding:8px 14px;margin-bottom:18px;border:1px solid transparent;border-radius:4px;}
.alert-error{background-color:#f2dede;border-color:#eed3d7;color:#b94a48;}
.alert-success{background-color:#dff0d8;border-color:#d6e9c6;color:#468847;}
footer{margin-top:30px;padding-top:15px;border-top:1px solid #e5e5e5;color:#999;font-size:13px;}
.field-glyph{margin-right:4px;opacity:.85;}
.status-legend{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;margin:0 0 18px;padding:10px 14px;font-size:13px;line-height:1.4;color:#555;background:#fafafa;border:1px solid #e5e5e5;border-radius:4px;}
.status-legend-title{font-weight:600;color:#333;margin-right:4px;}
.legend-item{display:inline-flex;align-items:center;gap:6px;}
.legend-swatch{display:inline-block;width:18px;height:18px;border-radius:3px;border:1px solid rgba(0,0,0,.08);flex-shrink:0;}
.legend-swatch--success{background-color:#dff0d8;}
.legend-swatch--error{background-color:#f2dede;}
.legend-swatch--warning{background-color:#fcf8e3;}
.legend-swatch--info{background-color:#d9edf7;}
CSS;
		$output .= "\n" . '</style>' . "\n";
		$output .= '</head>' . "\n";
		$output .= '<body style="padding:10px 0;">' . "\n";
		$output .= '<div class="container">' . "\n";
		$output .= '<div class="navbar">' . "\n";
		$output .= '<div class="navbar-inner">' . "\n";
		$output .= '<a class="brand" href="#">PHP WordPress Info <span class="version-label">' . self::VERSION . '</span></a>' . "\n";
		$output .= '<ul class="nav pull-right">' . "\n";
		$output .= '<li><a href="https://make.wordpress.org/hosting/handbook/server-environment/" target="_blank">WordPress Handbook</a></li>' . "\n";
		$output .= '<li><a href="https://github.com/BeAPI/phpwpinfo" target="_blank">Project on Github</a></li>' . "\n";

		if ( $this->db_link !== false ) {
			$output .= '<li class="dropdown">' . "\n";
			$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">Database <b class="caret"></b></a>' . "\n";
			$output .= '<ul class="dropdown-menu">' . "\n";
			$output .= '<li><a href="?database-variables=true">Database Variables</a></li>' . "\n";
			$output .= '<li><a href="?logout-db=true">Logout database</a></li>' . "\n";
			$output .= '</ul>' . "\n";
			$output .= '</li>' . "\n";
		}

		if ( $this->redis_link !== false ) {
			$output .= '<li class="dropdown">' . "\n";
			$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">Redis <b class="caret"></b></a>' . "\n";
			$output .= '<ul class="dropdown-menu">' . "\n";
			$output .= '<li><a href="?redis-variables=true">Redis Variables</a></li>' . "\n";
			$output .= '<li><a href="?logout-redis=true">Logout redis</a></li>' . "\n";
			$output .= '</ul>' . "\n";
			$output .= '</li>' . "\n";
		}

		$output .= '<li class="dropdown">' . "\n";
		$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">Tools <b class="caret"></b></a>' . "\n";
		$output .= '<ul class="dropdown-menu">' . "\n";
		$output .= '<li><a href="?phpinfo=true">PHPinfo()</a></li>' . "\n";

		// Adminer
		if ( ! is_file( __DIR__ . '/adminer.php' ) && is_writable( __DIR__ ) ) {
			$output .= '<li><a href="?adminer=install">Install Adminer</a></li>' . "\n";
		} elseif ( is_file( __DIR__ . '/adminer.php' ) ) {
			$output .= '<li><a href="adminer.php">Adminer</a></li>' . "\n";
			$output .= '<li><a href="?adminer=uninstall">Uninstall Adminer</a></li>' . "\n";
		}

		// WordPress
		if ( ! is_dir( __DIR__ . '/wordpress' ) && is_writable( __DIR__ ) && class_exists( 'ZipArchive' ) ) {
			$output .= '<li><a href="?WordPress=install">Download & Extract WordPress</a></li>' . "\n";
		} elseif ( is_dir( __DIR__ . '/wordpress' ) ) {
			$output .= '<li><a href="wordpress/">WordPress</a></li>' . "\n";
			$output .= '<li><a href="?WordPress=uninstall">Uninstall WordPress</a></li>' . "\n";
		}

		$output .= '<li><a href="?self-destruction=true">Self-destruction</a></li>' . "\n";
		$output .= '</ul>' . "\n";
		$output .= '</li>' . "\n";

		$output .= '</ul>' . "\n";
		$output .= '</div>' . "\n";
		$output .= '</div>' . "\n";

		$output .= '<div class="status-legend" role="note" aria-label="Meaning of row highlight colors in tables below">' . "\n";
		$output .= '<span class="status-legend-title">Row colors:</span>' . "\n";
		$output .= '<span class="legend-item"><span class="legend-swatch legend-swatch--success" aria-hidden="true"></span> OK / meets requirement</span>' . "\n";
		$output .= '<span class="legend-item"><span class="legend-swatch legend-swatch--error" aria-hidden="true"></span> Issue / does not meet requirement</span>' . "\n";
		$output .= '<span class="legend-item"><span class="legend-swatch legend-swatch--warning" aria-hidden="true"></span> Warning</span>' . "\n";
		$output .= '<span class="legend-item"><span class="legend-swatch legend-swatch--info" aria-hidden="true"></span> Optional / informational</span>' . "\n";
		$output .= '</div>' . "\n";

		echo $output;
	}

	/**
	 * Close HTML with inline scripts only (no external script tags).
	 */
	public function get_footer() {
		$output = '';

		$output .= '<footer>&copy; <a href="https://beapi.fr">BE API</a> ' . date( 'Y' ) . '</footer>' . "\n";
		$output .= '</div>' . "\n";

		$output .= <<<'JS'
<script>
(function () {
	document.querySelectorAll('.dropdown-toggle').forEach(function (toggle) {
		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			var li = toggle.closest('.dropdown');
			if (!li) {
				return;
			}
			var wasOpen = li.classList.contains('open');
			document.querySelectorAll('.dropdown.open').forEach(function (d) {
				d.classList.remove('open');
			});
			if (!wasOpen) {
				li.classList.add('open');
			}
		});
	});
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.dropdown')) {
			document.querySelectorAll('.dropdown.open').forEach(function (d) {
				d.classList.remove('open');
			});
		}
	});
})();
(function () {
	var row = document.querySelector('tr.realip');
	if (!row) {
		return;
	}
	var cell = row.querySelector('td:last-child');
	if (!cell) {
		return;
	}
	fetch('https://api.ipify.org?format=json')
		.then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		})
		.then(function (data) {
			if (data && data.ip) {
				cell.textContent = data.ip;
			} else {
				throw new Error('No ip field');
			}
		})
		.catch(function () {
			cell.textContent = 'Unavailable (network, CORS, or CSP connect-src blocking api.ipify.org)';
		});
})();
</script>

JS;

		$output .= '</body>' . "\n";
		$output .= '</html>' . "\n";

		echo $output;
	}

	/**
	 * Open a HTML table
	 *
	 * @param string $title
	 * @param string $col1
	 * @param string $col2
	 * @param string $col3
	 * @param string $col4
	 */
	public function html_table_open( $title = '', $col1 = '', $col2 = '', $col3 = '', $col4 = '' ) {
		$output  = '';
		$output .= '<table class="table table-bordered">' . "\n";
		$output .= '<caption>' . $title . '</caption>' . "\n";
		$output .= '<thead>' . "\n";

		if ( ! empty( $col1 ) || ! empty( $col2 ) || ! empty( $col3 ) || ! empty( $col4 ) ) {
			$output .= '<tr>' . "\n";
			$output .= '<th style="width:40%">' . $col1 . '</th>' . "\n";
			if ( ! empty( $col4 ) ) {
				$output .= '<th style="width:20%">' . $col2 . '</th>' . "\n";
				$output .= '<th style="width:20%">' . $col3 . '</th>' . "\n";
				$output .= '<th style="width:20%">' . $col4 . '</th>' . "\n";
			} else {
				$output .= '<th style="width:30%">' . $col2 . '</th>' . "\n";
				$output .= '<th style="width:30%">' . $col3 . '</th>' . "\n";
			}
			$output .= '</tr>' . "\n";
		}

		$output .= '</thead>' . "\n";
		$output .= '<tbody>' . "\n";

		echo $output;
	}

	/**
	 * Close HTML table
	 *
	 * @param string $description
	 */
	public function html_table_close( $description = '' ) {
		$output  = '';
		$output .= '</tbody>' . "\n";
		$output .= '</table>' . "\n";

		if ( ! empty( $description ) ) {
			$output .= '<p class="description">' . $description . '</p>' . "\n";
		}

		echo $output;
	}

	/**
	 * Add table row
	 * Status available : success, error, warning, info
	 *
	 * @param string $col1
	 * @param string $col2
	 * @param string $col3
	 * @param string $col4
	 * @param string $status
	 * @param bool $colspan
	 */
	public function html_table_row( $col1, $col2, $col3, $col4, $status = 'success', $colspan = false ) {
		$output  = '';
		$output .= '<tr class="' . $status . '">' . "\n";

		if ( $colspan !== false ) {
			$output .= '<td>' . $col1 . '</td>' . "\n";
			$output .= '<td colspan="' . $colspan . '" style="text-align:center;">' . $col2 . '</td>' . "\n";
		} else {
			$output .= '<td>' . $col1 . '</td>' . "\n";
			$output .= '<td>' . $col2 . '</td>' . "\n";
			$output .= '<td>' . $col3 . '</td>' . "\n";
			$output .= '<td>' . $col4 . '</td>' . "\n";
		}

		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Form HTML for Database Login
	 *
	 * @return void                          [description]
	 *
	 * @param boolean $show_error [description]
	 *
	 */
	public function html_form_database(
		$show_error = false
	) {
		$output  = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="4">' . "\n";

		if ( $show_error === true ) {
			$output .= '<div class="alert alert-error">Database credentials invalid.</div>' . "\n";
		}

		$output .= '<form class="form-inline" method="post" action="">' . "\n";
		$output .= '<input type="text" class="input-small" name="credentials-db[host]" placeholder="localhost" value="localhost">' . "\n";
		$output .= '<input type="text" class="input-small" name="credentials-db[user]" placeholder="user">' . "\n";
		$output .= '<input type="password" class="input-small" name="credentials-db[password]" placeholder="password">' . "\n";
		$output .= '<label class="checkbox">' . "\n";
		$output .= '<input type="checkbox" name="remember"> Remember' . "\n";
		$output .= '</label>' . "\n";
		$output .= '<button name="database-connection" type="submit" class="btn">Login</button>' . "\n";
		$output .= '<span class="help-inline">We must connect to the database server (MySQL or MariaDB) to check the configuration</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	public function test_form_connectivity() {
		$this->html_table_open( 'Connectivity tests', '', '', '' );

		if ( isset( $_POST['test-connectivity'] ) ) {
			$result = $this->check_connectivity_http( 'api.wordpress.org', 80, '/core/version-check/1.7/' );
			$this->html_table_row(
				'Test HTTP on api.wordpress.org',
				( $result === true ? 'OK' : 'KO' ),
				'',
				'',
				( $result === true ? 'success' : 'error' ),
				3
			);

			$result = $this->check_connectivity_http( 'api.wordpress.org', 443, '/core/version-check/1.7/' );
			$this->html_table_row(
				'Test HTTPS on api.wordpress.org',
				( $result === true ? 'OK' : 'KO' ),
				'',
				'',
				( $result === true ? 'success' : 'error' ),
				3
			);

			$result = $this->check_connectivity_ssh( 'github.com' );
			$this->html_table_row(
				'Test SSH on github.com',
				( $result === true ? 'OK' : 'KO' ),
				'',
				'',
				( $result === true ? 'success' : 'error' ),
				3
			);

			$result = $this->check_connectivity_ssh( 'bitbucket.org' );
			$this->html_table_row(
				'Test SSH on bitbucket.org',
				( $result === true ? 'OK' : 'KO' ),
				'',
				'',
				( $result === true ? 'success' : 'error' ),
				3
			);

			$result = $this->check_connectivity_ssh( 'gitlab.com' );
			$this->html_table_row(
				'Test SSH on gitlab.com',
				( $result === true ? 'OK' : 'KO' ),
				'',
				'',
				( $result === true ? 'success' : 'error' ),
				3
			);
		}

		$output  = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="3">' . "\n";
		$output .= '<form id="form-connectivity" class="form-inline" method="post" action="#form-connectivity">' . "\n";
		$output .= '<button name="test-connectivity" type="submit" class="btn">Launch tests</button>' . "\n";
		$output .= '<span class="help-inline">Check if server can access to internet via web and SSH</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;

		$this->html_table_close();
	}

	public function test_form_redis() {
		$this->html_table_open( 'Redis Configuration', '', '', '' );

		// Test redis Server Version
		if ( $this->redis_link !== false && class_exists( 'Redis' ) ) {
			$redis_info = $this->redis_link->info();

			if ( version_compare( $redis_info['redis_version'], self::MIN_REDIS_VERSION, '>=' ) ) {
				$this->html_table_row(
					'Database Version',
					self::MIN_REDIS_VERSION,
					'> 5',
					$redis_info['redis_version'],
					'success'
				);
			} else {
				$this->html_table_row(
					'Database Version',
					self::MIN_REDIS_VERSION,
					'> 5',
					$redis_info['redis_version'],
					'error'
				);
			}

			try {
				$this->redis_link->set( 'phpwpinfo', 'yes' );
				$glueStatus = $this->redis_link->get( 'phpwpinfo' );
				if ( $glueStatus ) {
					$testKey = 'phpwpinfo';
					$output  = "It's OK ! Glued with the Redis key value store:" . PHP_EOL;
					$output .= "1. Got value '{$glueStatus}' for key '{$testKey}'." . PHP_EOL;
					if ( $this->redis_link->del( 'phpwpinfo' ) ) {
						$output .= '2. And already removed the key/value pair again.' . PHP_EOL;
					}

					$this->html_table_row(
						'Redis self-test',
						$output,
						'',
						'',
						'success',
						3
					);
				} else {
					$output = 'Not glued with the Redis key value store.' . PHP_EOL;

					$this->html_table_row(
						'Redis self-test',
						$output,
						'',
						'',
						'error',
						3
					);
				}
			} catch ( RedisException $e ) {
				$exceptionMessage = $e->getMessage();
				$output           = "Exception : {$exceptionMessage}. Not glued with the Redis key value store.";

				$this->html_table_row(
					'Redis self-test',
					$output,
					'',
					'',
					'error',
					3
				);
			}
		} else {
			// Show redis Form
			$this->html_form_redis( $this->redis_infos === false );

			$this->html_table_row(
				'Redis version',
				self::MIN_REDIS_VERSION,
				'-',
				'Not available, needs redis auth.',
				'warning'
			);
		}

		$this->html_table_close();
	}

	/**
	 * Form HTML for Redis Auth
	 *
	 * @return void                          [description]
	 *
	 * @param boolean $show_error [description]
	 *
	 */
	public function html_form_redis( $show_error = false ) {
		$output  = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="4">' . "\n";

		if ( $show_error === true ) {
			$output .= '<div class="alert alert-error">Redis credentials invalid.';
			if ( isset( $_SESSION['redis-error-message'] ) ) {
				$output .= ' (' . $_SESSION['redis-error-message'] . ')';
			}

			$output .= '</div>' . "\n";
		}

		$output .= '<form id="form-redis" class="form-inline" method="post" action="#form-redis" >' . "\n";
		$output .= '<input type="text" class="input-small" name="credentials-redis[host]" placeholder="localhost:6379" value="localhost:6379">' . "\n";
		$output .= '<input type="password" class="input-small" name="credentials-redis[password]" placeholder="(optional)">' . "\n";
		$output .= '<label class="checkbox">' . "\n";
		$output .= '<input type="checkbox" name="remember"> Remember' . "\n";
		$output .= '</label>' . "\n";
		$output .= '<button name="redis-connection" type="submit" class="btn">Login</button>' . "\n";
		$output .= '<span class="help-inline">We must connect to the Redis server to check the configuration</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Form for test email
	 *
	 * @return void                          [description]
	 */
	public function html_form_email() {
		$output  = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="3">' . "\n";

		if ( isset( $_POST['test-email'], $_POST['mail'] ) ) {
			if ( ! filter_var( $_POST['mail'], FILTER_VALIDATE_EMAIL ) ) { // Invalid
				$output .= '<div class="alert alert-error">Email invalid.</div>' . "\n";
			} else { // Valid mail
				$subject            = 'Email test with PHP WP Info';
				$message            = "Line 1\nLine 2\nLine 3\nGreat !";
				$additional_headers = '';
				$headers            = 'X-Mailer: PHP/' . phpversion();

				if ( isset( $_POST['mail_from'] ) && filter_var( $_POST['mail_from'], FILTER_VALIDATE_EMAIL ) ) {
					$headers .= "\r\n" . 'From: ' . $_POST['mail_from'] . "\r\n" .
								'Reply-To: ' . $_POST['mail_from'];

					if ( isset( $_POST['mail_returnpath'] ) && '1' === $_POST['mail_returnpath'] ) {
						$headers           .= "\r\n" . 'Return-Path: ' . $_POST['mail_from'];
						$additional_headers = '-f ' . $_POST['mail_from'];
					}
				}

				$mresult = mail( $_POST['mail'], $subject, $message, $headers, $additional_headers );
				if ( $mresult ) {// Valid send
					$output .= '<div class="alert alert-success">Mail sent with success.</div>' . "\n";
				} else { // Error send
					$output .= '<div class="alert alert-error">An error occured during mail sending.</div>' . "\n";
				}
			}
		}

		$output .= '<form id="form-email" class="form-inline" method="post" action="#form-email">' . "\n";
		$output .= ' <span class="field-glyph" aria-hidden="true">&#9993;</span> <input type="email" class="input-large" name="mail" placeholder="recipient@sample.com" value="">' . "\n";
		$output .= ' <span class="field-glyph" aria-hidden="true">&#128100;</span> <input type="email" class="input-large" name="mail_from" placeholder="mailfrom@sample.com" value="">' . "\n";
		$output .= ' <label class="checkbox"><input type="checkbox" class="input-large" name="mail_returnpath" value="1"> Force Return-Path (only if mail from is set)</label>' . "\n";
		$output .= ' <button name="test-email" type="submit" class="btn">Send mail</button>' . "\n";
		$output .= ' <span class="help-inline">Send a test e-mail to check that the server is doing its job. You can leave the “mailfrom” field blank to let the server configuration “do its thing”.</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Stripslashes array
	 *
	 * @param $value
	 *
	 * @return array|string
	 */
	public function stripslashes_deep( $value ) {
		return is_array( $value ) ? array_map( array( &$this, 'stripslashes_deep' ), $value ) : stripslashes( $value );
	}

	/**
	 * Detect current webserver
	 *
	 * @return string        [description]
	 */
	private function _get_current_webserver() {
		if ( stripos( $_SERVER['SERVER_SOFTWARE'], 'apache' ) !== false ) :
			return 'Apache';
		elseif ( stripos( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false ) :
			return 'Lite Speed';
		elseif ( stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) !== false ) :
			return 'nginx';
		elseif ( stripos( $_SERVER['SERVER_SOFTWARE'], 'lighttpd' ) !== false ) :
			return 'lighttpd';
		elseif ( stripos( $_SERVER['SERVER_SOFTWARE'], 'iis' ) !== false ) :
			return 'Microsoft IIS';
		else :
			return 'Not detected';
		endif;
	}

	/**
	 * Method for get apaches modules with Apache modules or CGI with .HTACCESS
	 *
	 * @return array        [description]
	 */
	private function _get_apache_modules() {
		$apache_modules = ( is_callable( 'apache_get_modules' ) ? apache_get_modules() : false );

		if ( $apache_modules === false && ( isset( $_SERVER['http_mod_env'] ) || isset( $_SERVER['REDIRECT_http_mod_env'] ) ) ) {
			// Test with htaccess to get ENV values
			$apache_modules = array( 'mod_env' );

			if ( isset( $_SERVER['http_mod_rewrite'] ) || isset( $_SERVER['REDIRECT_http_mod_rewrite'] ) ) {
				$apache_modules[] = 'mod_rewrite';
			}
			if ( isset( $_SERVER['http_mod_deflate'] ) || isset( $_SERVER['REDIRECT_http_mod_deflate'] ) ) {
				$apache_modules[] = 'mod_deflate';
			}
			if ( isset( $_SERVER['http_mod_expires'] ) || isset( $_SERVER['REDIRECT_http_mod_expires'] ) ) {
				$apache_modules[] = 'mod_expires';
			}
			if ( isset( $_SERVER['http_mod_filter'] ) || isset( $_SERVER['REDIRECT_http_mod_filter'] ) ) {
				$apache_modules[] = 'mod_filter';
			}
			if ( isset( $_SERVER['http_mod_headers'] ) || isset( $_SERVER['REDIRECT_http_mod_headers'] ) ) {
				$apache_modules[] = 'mod_headers';
			}
			if ( isset( $_SERVER['http_mod_mime'] ) || isset( $_SERVER['REDIRECT_http_mod_mime'] ) ) {
				$apache_modules[] = 'mod_mime';
			}
			if ( isset( $_SERVER['http_mod_setenvif'] ) || isset( $_SERVER['REDIRECT_http_mod_setenvif'] ) ) {
				$apache_modules[] = 'mod_setenvif';
			}
		}

		return $apache_modules;
	}

	/**
	 * Get humans values, take from https://php.net/manual/de/function.filesize.php
	 *
	 * @return string [description]
	 *
	 * @param $size
	 *
	 * @internal param int $bytes [description]
	 */
	private function _format_bytes( $size ) {
		$units = array( ' B', ' KB', ' MB', ' GB', ' TB' );
		for ( $i = 0; $size >= 1024 && $i < 4; $i++ ) {
			$size /= 1024;
		}

		return round( $size, 2 ) . $units[ $i ];
	}

	private function _variable_to_html( $variable ) {
		if ( $variable === true ) {
			return 'true';
		}

		if ( $variable === false ) {
			return 'false';
		}

		if ( $variable === null ) {
			return 'null';
		}

		if ( is_array( $variable ) ) {
			$html  = "<table class='table table-bordered'>\n";
			$html .= "<thead><tr><th>Key</th><th>Value</th></tr></thead>\n";
			$html .= "<tbody>\n";
			foreach ( $variable as $key => $value ) {
				$value = $this->_variable_to_html( $value );
				$html .= "<tr><td>$key</td><td>$value</td></tr>\n";
			}
			$html .= "</tbody>\n";
			$html .= '</table>';

			return $html;
		}

		return (string) $variable;
	}

	public function file_get_contents_url( $url ) {
		if ( function_exists( 'curl_init' ) ) {
			$curl = curl_init();

			curl_setopt( $curl, CURLOPT_URL, $url );
			//The URL to fetch. This can also be set when initializing a session with curl_init().
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			//TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
			curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, 15 );
			//The number of seconds to wait while trying to connect.

			curl_setopt(
				$curl,
				CURLOPT_USERAGENT,
				'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
			);
			//The contents of the "User-Agent: " header to be used in a HTTP request.
			curl_setopt( $curl, CURLOPT_FAILONERROR, true );
			//To fail silently if the HTTP code returned is greater than or equal to 400.
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			//To follow any "Location: " header that the server sends as part of the HTTP header.
			curl_setopt( $curl, CURLOPT_AUTOREFERER, true );
			//To automatically set the Referer: field in requests where it follows a Location: redirect.
			curl_setopt( $curl, CURLOPT_TIMEOUT, 300 );
			//The maximum number of seconds to allow cURL functions to execute.

			//curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
			//curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false );

			$contents = curl_exec( $curl );
			curl_close( $curl );

			return $contents;
		}

		return file_get_contents( $url );
	}

	public function rrmdir( $dir ) {
		if ( is_dir( $dir ) ) {
			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( $object !== '.' && $object !== '..' ) {
					if ( filetype( $dir . '/' . $object ) === 'dir' ) {
						$this->rrmdir( $dir . '/' . $object );
					} else {
						unlink( $dir . '/' . $object );
					}
				}
			}
			reset( $objects );
			rmdir( $dir );
		}

		return true;
	}

	private function _check_request_redis() {
		// Check GET for logout-db redis
		if ( isset( $_GET['logout-redis'] ) && $_GET['logout-redis'] === 'true' ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials-redis'] );

			header( 'Location: ' . $this->get_scheme() . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
			exit();
		}

		// Check POST for redis login
		if ( isset( $_POST['redis-connection'] ) ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials-redis'], $_SESSION['redis-error-message'] );

			// Cleanup form data
			$this->redis_infos = $this->stripslashes_deep( $_POST['credentials-redis'] );

			// Check remember checkbox
			if ( isset( $_POST['remember'] ) ) {
				$_SESSION['credentials-redis'] = $this->redis_infos;
			}
		} elseif ( isset( $_SESSION['credentials-redis'] ) ) {
			$this->redis_infos = $_SESSION['credentials-redis'];
		}

		// Check credentials-redis
		if ( ! empty( $this->redis_infos ) && is_array( $this->redis_infos ) && class_exists( 'Redis' ) ) {
			$host_parts         = parse_url( $this->redis_infos['host'] );
			$host_parts['port'] = ( isset( $host_parts['port'] ) ) ? (int) $host_parts['port'] : 6379;

			$redis_args = array(
				'host'           => $host_parts['host'],
				'port'           => $host_parts['port'],
				'connectTimeout' => 5,
			);

			if ( ! empty( $this->redis_infos['password'] ) ) {
				$redis_args['auth'] = $this->redis_infos['password'];
			}

			try {
				$this->redis_link = new Redis( $redis_args );
				$this->redis_link->ping();
			} catch ( RedisException $e ) {
				$_SESSION['redis-error-message'] = $e->getMessage();
				$this->redis_link                = false;
			}

			if ( $this->redis_link === false ) {
				unset( $_SESSION['credentials-redis'] );
				$this->redis_infos = false;
			}
		}

		// Check GET for redis variables
		if ( $this->redis_link !== false && isset( $_GET['redis-variables'] ) && $_GET['redis-variables'] === 'true' ) {
			$redis_info = $this->redis_link->info();
			if ( empty( $redis_info ) ) {
				echo 'No result found, nothing to print so am exiting';
				exit();
			}

			$this->get_header();
			echo $this->_variable_to_html( $redis_info );
			$this->get_footer();
			exit();
		}
	}

	private function _check_request_database() {
		// Check GET for logout-db database
		if ( isset( $_GET['logout-db'] ) && $_GET['logout-db'] === 'true' ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials-db'] );

			header( 'Location: ' . $this->get_scheme() . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
			exit();
		}

		// Check POST for database login
		if ( isset( $_POST['database-connection'] ) ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials-db'] );

			// Cleanup form data
			$this->db_infos = $this->stripslashes_deep( $_POST['credentials-db'] );

			// Check remember checkbox
			if ( isset( $_POST['remember'] ) ) {
				$_SESSION['credentials-db'] = $this->db_infos;
			}
		} elseif ( isset( $_SESSION['credentials-db'] ) ) {
			$this->db_infos = $_SESSION['credentials-db'];
		}

		// Check credentials-db
		if ( ! empty( $this->db_infos ) && is_array( $this->db_infos ) && is_callable( 'mysqli_connect' ) ) {
			try {
				$this->db_link = mysqli_connect(
					$this->db_infos['host'],
					$this->db_infos['user'],
					$this->db_infos['password']
				);
			} catch ( Exception $e ) {
				$error = $e->getMessage();
				unset( $_SESSION['credentials-db'] );
				$this->db_infos = false;
			}
		}

		// Check GET for databse variables
		if ( $this->db_link !== false && isset( $_GET['database-variables'] ) && $_GET['database-variables'] === 'true' ) {
			$result = mysqli_query( $this->db_link, 'SHOW VARIABLES' );
			if ( ! $result ) {
				echo "Could not successfully run query ( 'SHOW VARIABLES' ) from DB: " . mysqli_error( $this->db_link );
				exit();
			}

			if ( mysqli_num_rows( $result ) === 0 ) {
				echo 'No rows found, nothing to print so am exiting';
				exit();
			}

			$output = array();
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				$output[ $row['Variable_name'] ] = $row['Value'];
			}
			$this->get_header();
			echo $this->_variable_to_html( $output );
			$this->get_footer();
			exit();
		}
	}

	private function _check_request_adminer() {
		// Check GET for Install Adminer
		if ( isset( $_GET['adminer'] ) && $_GET['adminer'] === 'install' ) {
			$code = $this->file_get_contents_url( 'https://www.adminer.org/latest-mysql-en.php' );
			if ( ! empty( $code ) ) {
				$result = file_put_contents( __DIR__ . '/adminer.php', $code );
				if ( $result !== false ) {
					header( 'Location: ' . $this->get_scheme() . $_SERVER['SERVER_NAME'] . '/adminer.php', true );
					exit();
				}
			}

			die( 'Impossible to download and install Adminer with this script.' );
		}

		// Check GET for Uninstall Adminer
		if ( isset( $_GET['adminer'] ) && $_GET['adminer'] === 'uninstall' ) {
			if ( is_file( __DIR__ . '/adminer.php' ) ) {
				$result = unlink( __DIR__ . '/adminer.php' );
				if ( $result !== false ) {
					header(
						'Location: ' . $this->get_scheme() . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'],
						true
					);
					exit();
				}
			}

			die( 'Impossible remove file and uninstall Adminer with this script.' );
		}
	}

	public function _check_request_wordpress() {
		// Check GET for Install WordPress
		if ( isset( $_GET['wordpress'] ) && $_GET['wordpress'] === 'install' ) {
			if ( ! is_file( __DIR__ . '/latest.zip' ) ) {
				$code = $this->file_get_contents_url( 'https://wordpress.org/latest.zip' );
				if ( ! empty( $code ) ) {
					$result = file_put_contents( __DIR__ . '/latest.zip', $code );
					if ( $result === false ) {
						die( 'Impossible to write WordPress archive with this script.' );
					}
				} else {
					die( 'Impossible to download WordPress with this script. You can also send WordPress Zip archive via FTP and renme it latest.zip, the script will only try to decompress it.' );
				}
			}

			if ( is_file( __DIR__ . '/latest.zip' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( __DIR__ . '/latest.zip' ) === true ) {
					$zip->extractTo( __DIR__ . '/' );
					$zip->close();

					unlink( __DIR__ . '/latest.zip' );
				} else {
					unlink( __DIR__ . '/latest.zip' );
					die( 'Impossible to unzip WordPress with this script.' );
				}
			}
		}

		// Check GET for Uninstall WordPress
		if ( isset( $_GET['wordpress'] ) && $_GET['wordpress'] === 'uninstall' ) {
			if ( is_dir( __DIR__ . '/wordpress' ) ) {
				$result = $this->rrmdir( __DIR__ . '/wordpress' );
				if ( $result !== false ) {
					header(
						'Location: ' . $this->get_scheme() . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'],
						true
					);
					exit();
				}
			}

			die( 'Impossible remove WordPress folder with this script.' );
		}
	}

	/**
	 * Determines if a command exists on the current environment
	 * Source: https://stackoverflow.com/questions/12424787/how-to-check-if-a-shell-command-exists-from-php
	 *
	 * @return bool True if the command has been found ; otherwise, false.
	 *
	 * @param string $command The command to check
	 */
	private function _command_exists( $command ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$whereIsCommand = ( PHP_OS === 'WINNT' ) ? 'where' : 'which';

		$process = proc_open(
			"$whereIsCommand $command",
			array(
				0 => array( 'pipe', 'r' ), //STDIN
				1 => array( 'pipe', 'w' ), //STDOUT
				2 => array( 'pipe', 'w' ), //STDERR
			),
			$pipes
		);

		if ( $process !== false ) {
			$stdout = stream_get_contents( $pipes[1] );
			//$stderr = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );

			return $stdout !== '';
		}

		return false;
	}

	private function get_scheme() {
		if ( ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) || (int) $_SERVER['SERVER_PORT'] === 443 ) {
			return 'https://';
		}

		return 'http://';
	}

	/**
	 * Public URL of the directory used to detect Apache autoindex (no index document inside).
	 */
	private function _get_indexes_probe_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
		$dir  = dirname( $_SERVER['SCRIPT_NAME'] );
		$dir  = str_replace( '\\', '/', (string) $dir );
		if ( $dir === '/' || $dir === '.' || $dir === '' ) {
			$path = '/indexes_probe/';
		} else {
			$path = rtrim( $dir, '/' ) . '/indexes_probe/';
		}

		return $this->get_scheme() . $host . $path;
	}

	/**
	 * GET a URL on the same site (follow redirects, optional Basic auth when phpwpinfo defines LOGIN).
	 *
	 * @return array{code:int, body:?string, error:?string}
	 */
	private function _http_get_local_url( $url ) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'phpwpinfo-directory-index-probe/1.0' );
		if ( defined( 'LOGIN' ) && defined( 'PASSWORD' ) ) {
			curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
			curl_setopt( $ch, CURLOPT_USERPWD, LOGIN . ':' . PASSWORD );
		}

		$body  = curl_exec( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );

		if ( $errno !== 0 ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => $error !== '' ? $error : ( 'cURL error ' . $errno ),
			);
		}

		return array(
			'code'  => $code,
			'body'  => is_string( $body ) ? $body : '',
			'error' => null,
		);
	}

	/**
	 * Typical Apache mod_autoindex HTML markers.
	 */
	private function _response_looks_like_apache_directory_listing( $body ) {
		if ( $body === '' ) {
			return false;
		}

		if ( preg_match( '/<title>\s*Index of\b/i', $body ) ) {
			return true;
		}

		return (bool) preg_match( '/<h1[^>]*>\s*Index of\b/i', $body );
	}

	/**
	 * @return mixed
	 *
	 * @param int $port
	 * @param string $path
	 * @param string $host
	 *
	 * @see : https://incarnate.github.io/curl-to-php/
	 */
	private function check_connectivity_http( $host = 'api.wordpress.org', $port = 80, $path = '/' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}

		$scheme = ( $port === 80 ) ? 'http://' : 'https://';

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $scheme . $host . $path );
		curl_setopt( $ch, CURLOPT_FILETIME, true );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );

		$result = curl_exec( $ch );
		//$info   = curl_getinfo( $ch );

		if ( curl_errno( $ch ) ) {
			//$result = 'Error:' . curl_error( $ch );
			return false;
		}

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		return ! ( $http_code > 204 );
	}

	/**
	 * @return bool
	 *
	 * @param int $port
	 * @param string $host
	 *
	 * @see : https://www.linuxquestions.org/questions/linux-server-73/ssh-connections-with-php-926003/
	 */
	private function check_connectivity_ssh( $host = 'github.com', $port = 22 ) {
		try {
			$fp = fsockopen( $host, $port, $errno, $errstr, 5 );
			if ( ! $fp ) {
				return false;
			}

			fclose( $fp );

			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}
}

// Init render
phpwpinfo();
