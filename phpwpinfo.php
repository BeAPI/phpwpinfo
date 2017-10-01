<?php
/*
Version 1.3
Copyright 2012-2016 - Amaury Balmer (amaury@beapi.fr)

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
if ( strpos( php_sapi_name(), 'cgi' ) === false ) {
	define( 'LOGIN', 'wordpress' );
	define( 'PASSWORD', 'wordpress' );

	if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) || ( $_SERVER['PHP_AUTH_PW'] != PASSWORD || $_SERVER['PHP_AUTH_USER'] != LOGIN ) ) {
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

/**
 * TODO: Use or not session for save DB configuration
 */
class PHP_WP_Info {
	private $debug_mode = true;
	private $php_version = '5.2.4';
	private $mysqli_version = '5.0';

	private $db_infos = array();
	private $db_link = null;

	public function __construct() {
		@session_start();

		if ( $this->debug_mode == true ) {
			ini_set( 'display_errors', 1 );
			ini_set( 'log_errors', 1 );
			ini_set( 'error_log', dirname( __FILE__ ) . '/error_log.txt' );
			error_reporting( E_ALL );
		}

		// Check GET for phpinfo
		if ( isset( $_GET ) && isset( $_GET['phpinfo'] ) && $_GET['phpinfo'] == 'true' ) {
			phpinfo();
			exit();
		}

		// Check GET for self-destruction
		if ( isset( $_GET ) && isset( $_GET['self-destruction'] ) && $_GET['self-destruction'] == 'true' ) {
			@unlink( __FILE__ );
			clearstatcache();
			if ( is_file( __FILE__ ) ) {
				die( 'Self-destruction KO ! Sorry, but you must remove me manually !' );
			}
			die( 'Self-destruction OK !' );
		}

		$this->_check_request_mysql();
		$this->_check_request_adminer();
		$this->_check_request_phpsecinfo();
		$this->_check_request_wordpress();
	}

	public function init_all_tests() {
		$this->get_header();

		$this->test_versions();
		$this->test_php_config();
		$this->test_php_extensions();
		$this->test_mysqli_config();
		$this->test_apache_modules();
		$this->test_form_mail();

		$this->get_footer();
	}	

	/**
	 * Main test, check if php/mysql/git are installed and right version for WP
	 */
	public function test_versions() {
		$this->html_table_open( 'General informations & tests PHP/MySQL Version', '', 'Required', 'Recommended', 'Current' );

		// Webserver used
		$this->html_table_row( 'Web server', $this->_get_current_webserver(), '', '', 'info', 3 );

		// Test PHP Version
		$sapi_type = php_sapi_name();
		if ( strpos( $sapi_type, 'cgi' ) !== false ) {
			$this->html_table_row( 'PHP Type', 'CGI with Apache Worker or another webserver', '', '','success', 3 );
		} else {
			$this->html_table_row( 'PHP Type', 'Apache Module (low performance)', '', '', 'warning', 3 );
		}

		// Test PHP Version
		$php_version = phpversion();
		if ( version_compare( $php_version, $this->php_version, '>=' ) ) {
			$this->html_table_row( 'PHP Version', $this->php_version, '> 5.4', $php_version, 'success' );
		} else {
			$this->html_table_row( 'PHP Version', $this->php_version, '> 5.4', $php_version, 'error' );
		}

		// Test MYSQL Client extensions/version.
		if ( ! extension_loaded( 'mysqli' ) || ! is_callable( 'mysqli_connect' ) ) {
			$this->html_table_row( 'PHP MySQLi Extension', 'Yes', 'Yes', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'PHP MySQLi Extension', 'Yes', 'Yes', 'Installed', 'success' );
			$this->html_table_row( 'PHP MySQLi Client Version', $this->mysqli_version, '> 5.5', mysqli_get_client_info(), 'info' );
		}

		// Test MySQL Server Version
		if ( $this->db_link != false && is_callable( 'mysqli_get_server_info' ) ) {
			$mysqli_version = preg_replace( '/[^0-9.].*/', '', mysqli_get_server_info( $this->db_link ) );
			if ( version_compare( $mysqli_version, $this->mysqli_version, '>=' ) ) {
				$this->html_table_row( 'MySQL Version', $this->mysqli_version, '> 5.5', $mysqli_version, 'success' );
			} else {
				$this->html_table_row( 'MySQL Version', $this->mysqli_version, '> 5.5', $mysqli_version, 'error' );
			}
		} else {
			// Show MySQL Form
			$this->html_form_mysql( ( $this->db_infos === false ) ? true : false );

			$this->html_table_row( 'MySQL Version', $this->mysqli_version, '-', 'Not available, needs credentials.', 'warning' );
		}

		// Test if the server is connected to the server by attempt to find the IP(v4) of www.google.fr
		if( gethostbyname('www.google.fr') != 'www.google.fr' ) {
			$this->html_table_row('Internet connectivity (Google)', 'No', 'Yes', 'Yes', 'success');
		} else {
			$this->html_table_row('Internet connectivity (Google)', 'No', 'Yes', 'No', 'error');
		}

		// Test if the command 'git' exists, so it tests if Git is installed
		if ($this->_command_exists('git') == 1 ) {
			$this->html_table_row('GIT is installed?', 'No', 'Yes', 'Yes');
		} else {
			$this->html_table_row('GIT is installed?', 'No', 'Yes', 'No', 'error');
		}

		$this->html_table_row('Remote IP via $_SERVER["REMOTE_ADDR"]', '', '', $_SERVER["REMOTE_ADDR"], 'info');

		if ( isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ) {
			$this->html_table_row('Remote IP via $_SERVER["HTTP_X_FORWARDED_FOR"]', '', '', $_SERVER["HTTP_X_FORWARDED_FOR"], 'info');
		}

		if ( isset($_SERVER["HTTP_X_FORWARDED"]) ) {
			$this->html_table_row('Remote IP via $_SERVER["HTTP_X_FORWARDED"]', '', '', $_SERVER["HTTP_X_FORWARDED"], 'info');
		}

		if ( isset($_SERVER["HTTP_CLIENT_IP"]) ) {
			$this->html_table_row('Remote IP via $_SERVER["HTTP_CLIENT_IP"]', '', '', $_SERVER["HTTP_CLIENT_IP"], 'info');
		}

		$this->html_table_row('Real remote IP via AJAX call', '', '', '... js loading ...', 'warning realip');
	}
		
	public function test_php_extensions() {
		$this->html_table_open( 'PHP Extensions', '', 'Required', 'Recommended','Current' );

		/**
		 * Check GD and Imagick like WordPress does.
		 */
		$gd = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick = extension_loaded( 'imagick' ) &&  class_exists( 'Imagick', false ) &&  class_exists( 'ImagickPixel', false ) && version_compare( phpversion( 'imagick' ), '2.2.0', '>=' );

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

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->html_table_row( 'ZIP', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'ZIP', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'ftp_connect' ) ) {
			$this->html_table_row( 'FTP', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'FTP', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'exif_read_data' ) ) {
			$this->html_table_row( 'Exif', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Exif', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'curl_init' ) ) {
			$this->html_table_row( 'CURL', 'Yes*', 'Yes', 'Not installed', 'warning' );
		} else {
			$this->html_table_row( 'CURL', 'Yes*', 'Yes', 'Installed', 'success' );
		}
		
		if ( is_callable( 'opcache_reset' ) ) {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)', 'No', 'Yes', 'Zend OPcache Installed', 'success' );
		} elseif ( is_callable( 'eaccelerator_put' ) ) {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)', 'No', 'Yes', 'eAccelerator Installed', 'success' );
		} elseif ( is_callable( 'xcache_set' ) ) {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)', 'No', 'Yes', 'XCache Installed', 'success' );
		} elseif ( is_callable( 'apc_store' ) ) {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)', 'No', 'Yes', 'APC Installed', 'success' );
		} elseif ( is_callable( 'zend_optimizer_version' ) ) {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizerr)', 'No', 'Yes', 'Zend Optimizer Installed', 'success' );
		} else {
			$this->html_table_row( 'Opcode (Zend OPcache, APC, Xcache, eAccelerator or Zend Optimizer)', 'No', 'Yes', 'Not installed', 'warning' );
		}

		if ( ! class_exists( 'Memcache' ) ) {
			$this->html_table_row( 'Memcache', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Memcache', 'No', 'Yes', 'Installed', 'success' );
		}
		
		if ( ! class_exists( 'Memcached' ) ) {
			$this->html_table_row( 'Memcached', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Memcached', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! is_callable( 'mb_substr' ) ) {
			$this->html_table_row( 'Multibyte String', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Multibyte String', 'No', 'Yes', 'Installed', 'success' );
		}

		if ( ! class_exists( 'tidy' ) ) {
			$this->html_table_row( 'Tidy', 'No', 'Yes', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Tidy', 'No', 'Yes', 'Installed', 'success' );
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

		$this->html_table_close( '(*) Items with an asterisk are not required by WordPress, but it is highly recommended by me!' );
	}

	public function test_apache_modules() {
		if ( $this->_get_current_webserver() != 'Apache' ) {
			return false;
		}

		$current_modules = (array) $this->_get_apache_modules();
		$modules         = array(
			'mod_deflate' => false,
			'mod_env' => false,
			'mod_expires' => false,
			'mod_headers' => false,
			'mod_filter' => false,
			'mod_mime' => false,
			'mod_rewrite' => true,
			'mod_setenvif' => false,
		);

		$this->html_table_open( 'Apache Modules', '', 'Required', 'Recommended', 'Current' );

		foreach ( $modules as $module => $is_required ) {
			$is_required = ($is_required == true ) ? 'Yes' : 'No'; // Boolean to Yes/NO

			$name = ucfirst( str_replace( 'mod_', '', $module ) );
			if ( ! in_array( $module, $current_modules ) ) {
				$this->html_table_row( $name, $is_required, 'Recommended', 'Not installed', 'error' );
			} else {
				$this->html_table_row( $name, $is_required, 'Recommended', 'Installed', 'success' );
			}
		}

		$this->html_table_close();

		return true;
	}

	public function test_php_config() {
		$this->html_table_open( 'PHP Configuration', '', 'Required', 'Recommended', 'Current' );

		$value = ini_get( 'register_globals' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'register_globals', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_globals', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'magic_quotes_runtime' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'magic_quotes_runtime', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'magic_quotes_runtime', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'magic_quotes_sybase' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'magic_quotes_sybase', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'magic_quotes_sybase', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'register_long_arrays' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'register_long_arrays', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_long_arrays', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'register_argc_argv ' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'register_argc_argv ', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_argc_argv ', '-', 'Off', 'Off', 'success' );
		}

		$value = $this->return_bytes( ini_get( 'memory_limit' ) );
		if ( intval( $value ) < $this->return_bytes('64M') ) {
			$this->html_table_row( 'memory_limit', '64 MB', '256 MB', $this->_format_bytes($value), 'error' );
		} else {
			$status = (intval( $value ) >= $this->return_bytes('256M')) ? 'success' : 'warning';
			$this->html_table_row( 'memory_limit', '64 MB', '256 MB', $this->_format_bytes($value), $status );
		}
		
		$value = ini_get( 'max_input_vars' );
		if ( intval( $value ) < 1000 ) {
			$this->html_table_row( 'max_input_vars', '1000', '5000', $value, 'error' );
		} else {
			$status = (intval( $value ) >= 5000) ? 'success' : 'warning';
			$this->html_table_row( 'max_input_vars', '1000', '5000', $value, $status );
		}

		$value = ini_get( 'file_uploads' );
		if ( strtolower( $value ) == 'on' || $value == '1' ) {
			$this->html_table_row( 'file_uploads', 'On', 'On', 'On', 'success' );
		} else {
			$this->html_table_row( 'file_uploads', 'On', 'On', 'Off', 'error' );
		}

		$value = $this->return_bytes( ini_get( 'upload_max_filesize' ) );
		if ( intval( $value ) < $this->return_bytes('32M') ) {
			$this->html_table_row( 'upload_max_filesize', '32 MB', '128 MB', $this->_format_bytes($value), 'error' );
		} else {
			$status = (intval( $value ) >= $this->return_bytes('128M')) ? 'success' : 'warning';
			$this->html_table_row( 'upload_max_filesize', '32 MB', '128 MB', $this->_format_bytes($value), $status );
		}

		$value = $this->return_bytes( ini_get( 'post_max_size' ) );
		if ( intval( $value ) < $this->return_bytes('32M') ) {
			$this->html_table_row( 'post_max_size', '32 MB', '128 MB', $this->_format_bytes($value), 'warning' );
		} else {
			$status = (intval( $value ) >= $this->return_bytes('128M')) ? 'success' : 'warning';
			$this->html_table_row( 'post_max_size', '32 MB', '128 MB', $this->_format_bytes($value), $status );
		}

		$value = ini_get( 'short_open_tag' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'short_open_tag', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'short_open_tag', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'safe_mode' );
		if ( strtolower( $value ) == 'on' ) {
			$this->html_table_row( 'safe_mode', '-', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'safe_mode', '-', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'open_basedir' );
		$this->html_table_row( 'open_basedir', $value, '', '', 'info', 3 );

		$value = ini_get( 'zlib.output_compression' );
		$this->html_table_row( 'zlib.output_compression', $value, '', '', 'info', 3 );

		$value = ini_get( 'output_handler' );
		$this->html_table_row( 'output_handler', $value, '', '', 'info', 3 );

		$value = ini_get( 'expose_php' );
		if ( $value == '0' || strtolower( $value ) == 'off' || empty( $value ) ) {
			$this->html_table_row( 'expose_php', '-', '0 or Off', $value, 'success' );
		} else {
			$this->html_table_row( 'expose_php', '-', '0 or Off', $value, 'error' );
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

		$value = dirname( __FILE__ );
		$this->html_table_row( 'Current dir', $value, '', '', 'info', 3 );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'Yes', 'No', 'error' );
		}

		if ( is_callable( 'apc_store' ) ) {
			$value = $this->return_bytes( ini_get( 'apc.shm_size' ) );
			if ( intval( $value ) < $this->return_bytes('32M') ) {
				$this->html_table_row( 'apc.shm_size', '32 MB', '128 MB', $this->_format_bytes($value), 'error' );
			} else {
				$status = (intval( $value ) >= $this->return_bytes('128M')) ? 'success' : 'warning';
				$this->html_table_row( 'apc.shm_size', '32 MB', '128 MB', $this->_format_bytes($value), $status );
			}
		}

		$this->html_table_close();
	}

	/**
	 * Convert PHP variable (G/M/K) to bytes
	 * Source: http://php.net/manual/fr/function.ini-get.php
	 * 
	 */
	public function return_bytes($val) {
	    $val = trim($val);
	    $last = strtolower($val[strlen($val)-1]);
	    $val = (int) $val;
	    switch($last) {
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
	 * @return bool
	 */
	public function test_mysqli_config() {
		if ( $this->db_link == false ) {
			return false;
		}

		$this->html_table_open( 'MySQL Configuration', '', 'Required', 'Recommended', 'Current' );

		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'have_query_cache'" );
		if ( $result != false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( strtolower( $row['Value'] ) == 'yes' ) {
					$this->html_table_row( "Query cache", 'Yes*', 'Yes', 'Yes', 'success' );
				} else {
					$this->html_table_row( "Query cache", 'Yes*', 'Yes', 'False', 'error' );
				}
			}
		}

		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'query_cache_size'" );
		if ( $result != false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( intval( $row['Value'] ) >= $this->return_bytes('8M') ) {
					$status = (intval( $row['Value'] ) >= $this->return_bytes('64M')) ? 'success' : 'warning';
					$this->html_table_row( "Query cache size", '8M', '64MB', $this->_format_bytes( (int) $row['Value'] ), $status );
				} else {
					$this->html_table_row( "Query cache size", '8M', '64MB', $this->_format_bytes( (int) $row['Value'] ), 'error' );
				}
			}
		}

		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'query_cache_type'" );
		if ( $result != false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( strtolower( $row['Value'] ) == 'on' || strtolower( $row['Value'] ) == '1' ) {
					$this->html_table_row( "Query cache type", '1 or on', '1 or on', strtolower( $row['Value'] ), 'success' );
				} else {
					$this->html_table_row( "Query cache type", '1', '1', strtolower( $row['Value'] ), 'error' );
				}
			}
		}

		$is_log_slow_queries = false;
		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'log_slow_queries'" );
		if ( $result != false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( strtolower( $row['Value'] ) == 'yes' || strtolower( $row['Value'] ) == 'on' ) {
					$is_log_slow_queries = true;
					$this->html_table_row( "Log slow queries", 'No', 'Yes', 'Yes', 'success' );
				} else {
					$is_log_slow_queries = false;
					$this->html_table_row( "Log slow queries", 'No', 'Yes', 'False', 'error' );
				}
			}
		}

		$result = mysqli_query( $this->db_link, "SHOW VARIABLES LIKE 'long_query_time'" );
		if ( $is_log_slow_queries == true && $result != false ) {
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				if ( intval( $row['Value'] ) <= 2 ) {
					$this->html_table_row( "Long query time (sec)", '2', '1', ( (int) $row['Value'] ), 'success' );
				} else {
					$this->html_table_row( "Long query time (sec)", '2', '1', ( (int) $row['Value'] ), 'error' );
				}
			}
		}

		$this->html_table_close( '(*) Items with an asterisk are not required by WordPress, but it is highly recommended by me!' );

		return true;
	}

	public function test_form_mail() {
		$this->html_table_open( 'Email Configuration', '', '', '' );
		$this->html_form_email();
		$this->html_table_close();
	}

	/**
	 * Start HTML, call CSS/JS from CDN
	 * Link to Github
	 * TODO: Add links to Codex/WP.org
	 * TODO: Add colors legend
	 */
	public function get_header() {
		$output = '';
		$output .= '<!DOCTYPE html>' . "\n";
		$output .= '<html lang="en">' . "\n";
		$output .= '<head>' . "\n";
		$output .= '<meta charset="utf-8">' . "\n";
		$output .= '<meta name="robots" content="noindex,nofollow">' . "\n";
		$output .= '<title>PHP WordPress Info</title>' . "\n";
		$output .= '<link href="https://maxcdn.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet">' . "\n";
		$output .= '<style>.table tbody tr.warning td{background-color:#FCF8E3;} .description{margin:-10px 0 20px 0;} caption{font-weight: 700;font-size: 18px}</style>' . "\n";
		$output .= '<!--[if lt IE 9]> <script src="https://html5shim.googlecode.com/svn/trunk/html5.js"></script> <![endif]-->' . "\n";
		$output .= '</head>' . "\n";
		$output .= '<body style="padding:10px 0;">' . "\n";
		$output .= '<div class="container">' . "\n";
		$output .= '<div class="navbar">' . "\n";
		$output .= '<div class="navbar-inner">' . "\n";
		$output .= '<a class="brand" href="#">PHP WordPress Info</a>' . "\n";
		$output .= '<ul class="nav pull-right">' . "\n";
		$output .= '<li><a href="https://github.com/BeAPI/phpwpinfo">Project on Github</a></li>' . "\n";

		if ( $this->db_link != false ) {
			$output .= '<li class="dropdown">' . "\n";
			$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">MySQL <b class="caret"></b></a>' . "\n";
			$output .= '<ul class="dropdown-menu">' . "\n";
			$output .= '<li><a href="?mysql-variables=true">MySQL Variables</a></li>' . "\n";
			$output .= '<li><a href="?logout=true">Logout</a></li>' . "\n";
			$output .= '</ul>' . "\n";
			$output .= '</li>' . "\n";
		}

		$output .= '<li class="dropdown">' . "\n";
		$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">Tools <b class="caret"></b></a>' . "\n";
		$output .= '<ul class="dropdown-menu">' . "\n";
		$output .= '<li><a href="?phpinfo=true">PHPinfo()</a></li>' . "\n";

		// Adminer
		if ( ! is_file( dirname( __FILE__ ) . '/adminer.php' ) && is_writable( dirname( __FILE__ ) ) ) {
			$output .= '<li><a href="?adminer=install">Install Adminer</a></li>' . "\n";
		} else {
			$output .= '<li><a href="adminer.php">Adminer</a></li>' . "\n";
			$output .= '<li><a href="?adminer=uninstall">Uninstall Adminer</a></li>' . "\n";
		}

		// PHP sec info
		if ( ! is_dir( dirname( __FILE__ ) . '/phpsecinfo' ) && is_writable( dirname( __FILE__ ) ) && class_exists( 'ZipArchive' ) ) {
			$output .= '<li><a href="?phpsecinfo=install">Install PhpSecInfo</a></li>' . "\n";
		} else {
			$output .= '<li><a href="?phpsecinfo=load">PhpSecInfo</a></li>' . "\n";
			$output .= '<li><a href="?phpsecinfo=uninstall">Uninstall PhpSecInfo</a></li>' . "\n";
		}

		// WordPress
		if ( ! is_dir( dirname( __FILE__ ) . '/wordpress' ) && is_writable( dirname( __FILE__ ) ) && class_exists( 'ZipArchive' ) ) {
			$output .= '<li><a href="?wordpress=install">Download & Extract WordPress</a></li>' . "\n";
		} else {
			$output .= '<li><a href="wordpress/">WordPress</a></li>' . "\n";
		}

		$output .= '<li><a href="?self-destruction=true">Self-destruction</a></li>' . "\n";
		$output .= '</ul>' . "\n";
		$output .= '</li>' . "\n";

		$output .= '</ul>' . "\n";
		$output .= '</div>' . "\n";
		$output .= '</div>' . "\n";

		echo $output;
	}

	/**
	 * Close HTML, call JS
	 */
	public function get_footer() {
		$output = '';

		$output .= '<footer>&copy; <a href="http://beapi.fr">BE API</a> ' . date( 'Y' ) . '</footer>' . "\n";
		$output .= '</div>' . "\n";

		$output .= '<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.2/jquery.min.js"></script>' . "\n";
		$output .= '<script src="https://maxcdn.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>' . "\n";

		$output .= '<script type="text/javascript">
			$.getJSON("//freegeoip.net/json/?callback=?", function(data) {
			  $(".realip td:last").html(data.ip);
			});
		</script>' . "\n";

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
		$output = '';
		$output .= '<table class="table table-bordered">' . "\n";
		$output .= '<caption>' . $title . '</caption>' . "\n";
		$output .= '<thead>' . "\n";

		if ( ! empty( $col1 ) || ! empty( $col2 ) || ! empty( $col3 ) || ! empty( $col4 ) ) {
			$output .= '<tr>' . "\n";
			$output .= '<th width="40%">' . $col1 . '</th>' . "\n";
			if ( !empty($col4) ) {
				$output .= '<th width="20%">' . $col2 . '</th>' . "\n";
				$output .= '<th width="20%">' . $col3 . '</th>' . "\n";
				$output .= '<th width="20%">' . $col4 . '</th>' . "\n";
			} else {
				$output .= '<th width="30%">' . $col2 . '</th>' . "\n";
				$output .= '<th width="30%">' . $col3 . '</th>' . "\n";
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
		$output = '';
		$output .= '</tbody>' . "\n";
		$output .= '</table>' . "\n";

		if ( !empty($description) ) {
			$output .= '<p class="description">'.$description.'</p>' . "\n";
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
	 * @param string $status
	 * @param bool $colspan
	 */
	public function html_table_row( $col1, $col2, $col3, $col4, $status = 'success', $colspan = false ) {
		$output = '';
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
	 * Form HTML for MySQL Login
	 *
	 * @param  boolean $show_error_credentials [description]
	 *
	 * @return void                          [description]
	 */
	public function html_form_mysql( $show_error_credentials = false ) {
		$output = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="4">' . "\n";

		if ( $show_error_credentials == true ) {
			$output .= '<div class="alert alert-error">Credentials invalid.</div>' . "\n";
		}

		$output .= '<form class="form-inline" method="post" action="">' . "\n";
		$output .= '<input type="text" class="input-small" name="credentials[host]" placeholder="localhost" value="localhost">' . "\n";
		$output .= '<input type="text" class="input-small" name="credentials[user]" placeholder="user">' . "\n";
		$output .= '<input type="password" class="input-small" name="credentials[password]" placeholder="password">' . "\n";
		$output .= '<label class="checkbox">' . "\n";
		$output .= '<input type="checkbox" name="remember"> Remember' . "\n";
		$output .= '</label>' . "\n";
		$output .= '<button name="mysql-connection" type="submit" class="btn">Login</button>' . "\n";
		$output .= '<span class="help-inline">We must connect to the MySQL server to check the configuration</span>' . "\n";
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
		$output = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="3">' . "\n";

		if ( isset( $_POST['test-email'] ) && isset( $_POST['mail'] ) ) {
			if ( ! filter_var( $_POST['mail'], FILTER_VALIDATE_EMAIL ) ) {// Invalid
				$output .= '<div class="alert alert-error">Email invalid.</div>' . "\n";
			} else {// Valid mail
				if ( mail( $_POST['mail'], 'Email test with PHP WP Info', "Line 1\nLine 2\nLine 3\nGreat !" ) ) {// Valid send
					$output .= '<div class="alert alert-success">Mail sent with success.</div>' . "\n";
				} else {// Error send
					$output .= '<div class="alert alert-error">An error occured during mail sending.</div>' . "\n";
				}
			}
		}

		$output .= '<form id="form-email" class="form-inline" method="post" action="#form-email">' . "\n";
		$output .= '<i class="icon-envelope"></i> <input type="email" class="input-large" name="mail" placeholder="test@sample.com" value="">' . "\n";
		$output .= '<button name="test-email" type="submit" class="btn">Send mail</button>' . "\n";
		$output .= '<span class="help-inline">Send a test email to check that server is doing its job</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Stripslashes array
	 *
	 * @param  [type] $value [description]
	 *
	 * @return array|string [type]        [description]
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
		if ( stristr( $_SERVER['SERVER_SOFTWARE'], 'apache' ) !== false ) :
			return 'Apache';
		elseif ( stristr( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false ) :
			return 'Lite Speed';
		elseif ( stristr( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) !== false ) :
			return 'nginx';
		elseif ( stristr( $_SERVER['SERVER_SOFTWARE'], 'lighttpd' ) !== false ) :
			return 'lighttpd';
		elseif ( stristr( $_SERVER['SERVER_SOFTWARE'], 'iis' ) !== false ) :
			return 'Microsoft IIS';
		else :
			return 'Not detected';
		endif;
	}

	/**
	 * Method for get apaches modules with Apache modules or CGI with .HTACCESS
	 *
	 * @return string        [description]
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
	 * Get humans values, take from http://php.net/manual/de/function.filesize.php
	 *
	 * @param $size
	 *
	 * @return string [description]
	 * @internal param int $bytes [description]
	 */
	private function _format_bytes( $size ) {
		$units = array( ' B', ' KB', ' MB', ' GB', ' TB' );
		for ( $i = 0; $size >= 1024 && $i < 4; $i ++ ) {
			$size /= 1024;
		}

		return round( $size, 2 ) . $units[ $i ];
	}

	private function _variable_to_html( $variable ) {
		if ( $variable === true ) {
			return 'true';
		} else if ( $variable === false ) {
			return 'false';
		} else if ( $variable === null ) {
			return 'null';
		} else if ( is_array( $variable ) ) {
			$html = "<table class='table table-bordered'>\n";
			$html .= "<thead><tr><th>Key</th><th>Value</th></tr></thead>\n";
			$html .= "<tbody>\n";
			foreach ( $variable as $key => $value ) {
				$value = $this->_variable_to_html( $value );
				$html .= "<tr><td>$key</td><td>$value</td></tr>\n";
			}
			$html .= "</tbody>\n";
			$html .= "</table>";

			return $html;
		} else {
			return strval( $variable );
		}
	}

	function file_get_contents_url( $url ) {
		if ( function_exists( 'curl_init' ) ) {
			$curl = curl_init();

			curl_setopt( $curl, CURLOPT_URL, $url );
			//The URL to fetch. This can also be set when initializing a session with curl_init().
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
			//TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
			curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, 15 );
			//The number of seconds to wait while trying to connect.

			curl_setopt( $curl, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)' );
			//The contents of the "User-Agent: " header to be used in a HTTP request.
			curl_setopt( $curl, CURLOPT_FAILONERROR, true );
			//To fail silently if the HTTP code returned is greater than or equal to 400.
			curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
			//To follow any "Location: " header that the server sends as part of the HTTP header.
			curl_setopt( $curl, CURLOPT_AUTOREFERER, true );
			//To automatically set the Referer: field in requests where it follows a Location: redirect.
			curl_setopt( $curl, CURLOPT_TIMEOUT, 300 );
			//The maximum number of seconds to allow cURL functions to execute.

			curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false );

			$contents = curl_exec( $curl );
			curl_close( $curl );

			return $contents;
		} else {
			return file_get_contents( $url );
		}
	}

	function rrmdir( $dir ) {
		if ( is_dir( $dir ) ) {
			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( $object != "." && $object != ".." ) {
					if ( filetype( $dir . "/" . $object ) == "dir" ) {
						$this->rrmdir( $dir . "/" . $object );
					} else {
						unlink( $dir . "/" . $object );
					}
				}
			}
			reset( $objects );
			rmdir( $dir );
		}
	}

	private function _check_request_mysql() {
		// Check GET for logout MySQL
		if ( isset( $_GET ) && isset( $_GET['logout'] ) && $_GET['logout'] == 'true' ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials'] );

			header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
			exit();
		}

		// Check POST for MySQL login
		if ( isset( $_POST ) && isset( $_POST['mysql-connection'] ) ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials'] );

			// Cleanup form data
			$this->db_infos = $this->stripslashes_deep( $_POST['credentials'] );

			// Check remember checkbox
			if ( isset( $_POST['remember'] ) ) {
				$_SESSION['credentials'] = $this->db_infos;
			}
		} else {
			if ( ( isset( $_SESSION ) && isset( $_SESSION['credentials'] ) ) ) {
				$this->db_infos = $_SESSION['credentials'];
			}
		}

		// Check credentials
		if ( ! empty( $this->db_infos ) && is_array( $this->db_infos ) && is_callable( 'mysqli_connect' ) ) {
			$this->db_link = mysqli_connect( $this->db_infos['host'], $this->db_infos['user'], $this->db_infos['password'] );
			if ( $this->db_link == false ) {
				unset( $_SESSION['credentials'] );
				$this->db_infos = false;
			}
		}

		// Check GET for MYSQL variables
		if ( $this->db_link != false && isset( $_GET ) && isset( $_GET['mysql-variables'] ) && $_GET['mysql-variables'] == 'true' ) {
			$result = mysqli_query( 'SHOW VARIABLES' );
			if ( ! $result ) {
				echo "Could not successfully run query ( 'SHOW VARIABLES' ) from DB: " . mysqli_error();
				exit();
			}

			if ( mysqli_num_rows( $result ) == 0 ) {
				echo "No rows found, nothing to print so am exiting";
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
		if ( isset( $_GET ) && isset( $_GET['adminer'] ) && $_GET['adminer'] == 'install' ) {
			$code = $this->file_get_contents_url( 'https://www.adminer.org/latest-mysql-en.php' );
			if ( ! empty( $code ) ) {
				$result = file_put_contents( dirname( __FILE__ ) . '/adminer.php', $code );
				if ( $result != false ) {
					header( "Location: http://" . $_SERVER['SERVER_NAME'] . '/adminer.php', true );
					exit();
				}
			}

			die( 'Impossible to download and install Adminer with this script.' );
		}

		// Check GET for Uninstall Adminer
		if ( isset( $_GET ) && isset( $_GET['adminer'] ) && $_GET['adminer'] == 'uninstall' ) {
			if ( is_file( dirname( __FILE__ ) . '/adminer.php' ) ) {
				$result = unlink( dirname( __FILE__ ) . '/adminer.php' );
				if ( $result != false ) {
					header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
					exit();
				}
			}

			die( 'Impossible remove file and uninstall Adminer with this script.' );
		}
	}

	private function _check_request_phpsecinfo() {
		// Check GET for Install phpsecinfo
		if ( isset( $_GET ) && isset( $_GET['phpsecinfo'] ) && $_GET['phpsecinfo'] == 'install' ) {
			$code = $this->file_get_contents_url( 'http://www.herewithme.fr/static/funkatron-phpsecinfo-b5a6155.zip' );
			if ( ! empty( $code ) ) {
				$result = file_put_contents( dirname( __FILE__ ) . '/phpsecinfo.zip', $code );
				if ( $result != false ) {
					$zip = new ZipArchive;
					if ( $zip->open( dirname( __FILE__ ) . '/phpsecinfo.zip' ) === true ) {
						$zip->extractTo( dirname( __FILE__ ) . '/phpsecinfo/' );
						$zip->close();

						unlink( dirname( __FILE__ ) . '/phpsecinfo.zip' );
					} else {
						unlink( dirname( __FILE__ ) . '/phpsecinfo.zip' );
						die( 'Impossible to uncompress phpsecinfo with this script.' );
					}

					header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
					exit();
				} else {
					die( 'Impossible to write phpsecinfo archive with this script.' );
				}
			} else {
				die( 'Impossible to download phpsecinfo with this script.' );
			}
		}

		// Check GET for Uninstall phpsecinfo
		if ( isset( $_GET ) && isset( $_GET['phpsecinfo'] ) && $_GET['phpsecinfo'] == 'uninstall' ) {
			if ( is_dir( dirname( __FILE__ ) . '/phpsecinfo/' ) ) {
				$this->rrmdir( dirname( __FILE__ ) . '/phpsecinfo/' );
				if ( ! is_dir( dirname( __FILE__ ) . '/phpsecinfo/' ) ) {
					header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
					exit();
				}
			}

			die( 'Impossible remove file and uninstall phpsecinfo with this script.' );
		}

		// Check GET for load
		if ( isset( $_GET ) && isset( $_GET['phpsecinfo'] ) && $_GET['phpsecinfo'] == 'load' ) {
			if ( is_dir( dirname( __FILE__ ) . '/phpsecinfo/' ) ) {
				require( dirname( __FILE__ ) . '/phpsecinfo/funkatron-phpsecinfo-b5a6155/PhpSecInfo/PhpSecInfo.php' );
				phpsecinfo();
				exit();
			}
		}
	}

	function _check_request_wordpress() {
		// Check GET for Install wordpress
		if ( isset( $_GET ) && isset( $_GET['wordpress'] ) && $_GET['wordpress'] == 'install' ) {
			if ( ! is_file( dirname( __FILE__ ) . '/latest.zip' ) ) {
				$code = $this->file_get_contents_url( 'https://wordpress.org/latest.zip' );
				if ( ! empty( $code ) ) {
					$result = file_put_contents( dirname( __FILE__ ) . '/latest.zip', $code );
					if ( $result == false ) {
						die( 'Impossible to write WordPress archive with this script.' );
					}
				} else {
					die( 'Impossible to download WordPress with this script. You can also send WordPress Zip archive via FTP and renme it latest.zip, the script will only try to decompress it.' );
				}
			}

			if ( is_file( dirname( __FILE__ ) . '/latest.zip' ) ) {
				$zip = new ZipArchive;
				if ( $zip->open( dirname( __FILE__ ) . '/latest.zip' ) === true ) {
					$zip->extractTo( dirname( __FILE__ ) . '/' );
					$zip->close();

					unlink( dirname( __FILE__ ) . '/latest.zip' );
				} else {
					unlink( dirname( __FILE__ ) . '/latest.zip' );
					die( 'Impossible to uncompress WordPress with this script.' );
				}
			}
		}
	}

	/**
	 * Determines if a command exists on the current environment
	 * Source: https://stackoverflow.com/questions/12424787/how-to-check-if-a-shell-command-exists-from-php
	 *
	 * @param string $command The command to check
	 * @return bool True if the command has been found ; otherwise, false.
	 */
	private function _command_exists($command) {
		$whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';

		$process = proc_open(
			"$whereIsCommand $command",
			array(
				0 => array("pipe", "r"), //STDIN
				1 => array("pipe", "w"), //STDOUT
				2 => array("pipe", "w"), //STDERR
			),
			$pipes
		);

		if ($process !== false) {
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);

			return $stdout != '';
		}

		return false;
	}
}

// Init render
phpwpinfo();