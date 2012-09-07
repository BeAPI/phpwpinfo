<?php
function phpwpinfo( ) {
	$info = new PHP_WP_Info( );
	$info->init_all_tests( );
}

/**
 * TODO: Use or not session for save DB configuration
 */
class PHP_WP_Info {
	private $php_version = '5.2.4';
	private $mysql_version = '5.0';

	private $db_infos = array( );
	private $db_link = null;

	public function __construct( ) {
		@session_start( );

		// Check GET for phpinfo
		if ( isset( $_GET ) && isset( $_GET['phpinfo'] ) && $_GET['phpinfo'] == 'true' ) {
			phpinfo( );
			die( );
		}

		// Check GET for logout MySQL
		if ( isset( $_GET ) && isset( $_GET['logout'] ) && $_GET['logout'] == 'true' ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials'] );

			header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
			exit( );
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
			if ( (isset( $_SESSION ) && isset( $_SESSION['credentials'] )) ) {
				$this->db_infos = $_SESSION['credentials'];
			}
		}

		// Check credentials
		if ( !empty( $this->db_infos ) && is_array( $this->db_infos ) && is_callable( 'mysql_connect' ) ) {
			$this->db_link = mysql_connect( $this->db_infos['host'], $this->db_infos['user'], $this->db_infos['password'] );
			if ( $this->db_link == false ) {
				unset( $_SESSION['credentials'] );
				$this->db_infos = false;
			}
		}
	}

	public function init_all_tests( ) {
		$this->get_header( );

		$this->test_versions( );
		$this->test_php_config( );
		$this->test_php_extensions( );
		$this->test_mysql_config( );
		$this->test_apache_modules( );
		$this->test_mail( );

		$this->get_footer( );
	}

	/**
	 * Main test, check if php/mysql are installed and right version for WP
	 */
	public function test_versions( ) {
		$this->html_table_open( 'General informations & tests PHP/MySQL Version', '', 'Required', 'Current' );

		// Webserver used
		$this->html_table_row( 'Web server', '-', $this->_get_current_webserver( ), 'info' );

		// Test PHP Version
		$sapi_type = php_sapi_name( );
		if ( substr( $sapi_type, 0, 3 ) == 'cgi' ) {
			$this->html_table_row( 'PHP Type', '-', 'CGI with Apache Worker or another webserver', 'success' );
		} else {
			$this->html_table_row( 'PHP Type', '-', 'Apache Module (low performance)', 'warning' );
		}

		// Test PHP Version
		$php_version = phpversion( );
		if ( version_compare( $php_version, $this->php_version, '>=' ) ) {
			$this->html_table_row( 'PHP Version', $this->php_version, $php_version, 'success' );
		} else {
			$this->html_table_row( 'PHP Version', $this->php_version, $php_version, 'error' );
		}

		// Test MYSQL Client extensions/version
		if ( !extension_loaded( 'mysql' ) || !is_callable( 'mysql_connect' ) ) {
			$this->html_table_row( 'PHP MySQL Extension', 'Required', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'PHP MySQL Extension', 'Required', 'Installed', 'success' );
			$this->html_table_row( 'PHP MySQL Client Version', $this->mysql_version, mysql_get_client_info( ), 'info' );
		}

		// Test MySQL Server Version
		if ( $this->db_link != false && is_callable( 'mysql_get_server_info' ) ) {
			$mysql_version = preg_replace( '/[^0-9.].*/', '', mysql_get_server_info( $this->db_link ) );
			if ( version_compare( $mysql_version, $this->mysql_version, '>=' ) ) {
				$this->html_table_row( 'MySQL Version', $this->mysql_version, $mysql_version, 'success' );
			} else {
				$this->html_table_row( 'MySQL Version', $this->mysql_version, $mysql_version, 'error' );
			}
		} else {
			// Show MySQL Form
			$this->html_form_mysql( ($this->db_infos === false) ? true : false );

			$this->html_table_row( 'MySQL Version', $this->mysql_version, 'Not available, needs credentials.', 'warning' );
		}

		$this->html_table_close( );
	}

	public function test_php_extensions( ) {
		$this->html_table_open( 'PHP Extensions', '', 'Required', 'Current' );

		if ( !is_callable( 'gd_info' ) ) {
			$this->html_table_row( 'GD', 'Required', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'GD', 'Required', 'Installed', 'success' );
		}

		if ( !is_callable( 'ftp_connect' ) ) {
			$this->html_table_row( 'FTP', 'Recommended', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'FTP', 'Recommended', 'Installed', 'success' );
		}

		if ( !is_callable( 'exif_read_data' ) ) {
			$this->html_table_row( 'Exif', 'Recommended', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'Exif', 'Recommended', 'Installed', 'success' );
		}

		if ( !is_callable( 'curl_init' ) ) {
			$this->html_table_row( 'CURL', 'Recommended', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'CURL', 'Recommended', 'Installed', 'success' );
		}

		if ( is_callable( 'eaccelerator_put' ) ) {
			$this->html_table_row( 'Opcode (APC or Xcache or eAccelerator)', 'Recommended', 'eAccelerator Installed', 'success' );
		} elseif ( is_callable( 'xcache_set' ) ) {
			$this->html_table_row( 'Opcode (APC or Xcache or eAccelerator)', 'Recommended', 'XCache Installed', 'success' );
		} elseif ( is_callable( 'apc_store' ) ) {
			$this->html_table_row( 'Opcode (APC or Xcache or eAccelerator)', 'Recommended', 'APC Installed', 'success' );
		} else {
			$this->html_table_row( 'Opcode (APC or Xcache or eAccelerator)', 'Recommended', 'Not installed', 'error' );
		}

		if ( !is_callable( 'mb_substr' ) ) {
			$this->html_table_row( 'Multibyte String', 'Recommended', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'Multibyte String', 'Recommended', 'Installed', 'success' );
		}

		if ( !class_exists( 'tidy' ) ) {
			$this->html_table_row( 'Tidy', 'Optional', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Tidy', 'Optional', 'Installed', 'success' );
		}

		if ( !is_callable( 'mb_substr' ) ) {
			$this->html_table_row( 'Memcache', 'Optional', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Memcache', 'Optional', 'Installed', 'success' );
		}

		if ( !is_callable( 'finfo_open' ) && !is_callable( 'mime_content_type' ) ) {
			$this->html_table_row( 'Mime type', 'Optional', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Mime type', 'Optional', 'Installed', 'success' );
		}

		if ( !is_callable( 'hash' ) && !is_callable( 'mhash' ) ) {
			$this->html_table_row( 'Hash', 'Optional', 'Not installed', 'info' );
		} else {
			$this->html_table_row( 'Hash', 'Optional', 'Installed', 'success' );
		}

		if ( !is_callable( 'set_time_limit' ) ) {
			$this->html_table_row( 'set_time_limit', 'Optional', 'Not Available', 'info' );
		} else {
			$this->html_table_row( 'set_time_limit', 'Optional', 'Available', 'success' );
		}

		$this->html_table_close( );
	}

	public function test_apache_modules( ) {
		if ( $this->_get_current_webserver( ) != 'Apache' ) {
			return false;
		}

		$current_modules = (array) $this->_get_apache_modules( );
		$modules = array( 'mod_deflate', 'mod_env', 'mod_expires', 'mod_headers', 'mod_filter', 'mod_mime', 'mod_rewrite', 'mod_setenvif' );

		$this->html_table_open( 'Apache Modules', '', 'Required', 'Current' );

		foreach ( $modules as $module ) {
			$name = ucfirst( str_replace( 'mod_', '', $module ) );
			if ( !in_array( $module, $current_modules ) ) {
				$this->html_table_row( $name, 'Recommended', 'Not installed', 'error' );
			} else {
				$this->html_table_row( $name, 'Recommended', 'Installed', 'success' );
			}
		}

		$this->html_table_close( );
	}

	public function test_php_config( ) {
		$this->html_table_open( 'PHP Configuration', '', 'Recommended', 'Current' );

		$value = ini_get( 'register_globals' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'register_globals', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_globals', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'magic_quotes_runtime' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'magic_quotes_runtime', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'magic_quotes_runtime', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'magic_quotes_sybase' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'magic_quotes_sybase', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'magic_quotes_sybase', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'register_long_arrays' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'register_long_arrays', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_long_arrays', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'register_argc_argv ' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'register_argc_argv ', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'register_argc_argv ', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'memory_limit' );
		if ( intval($value) < 64 ) {
			$this->html_table_row( 'memory_limit', '64M', $value, 'error' );
		} else {
			$this->html_table_row( 'memory_limit', '64M', $value, 'success' );
		}

		$value = ini_get( 'file_uploads' );
		if ( strtolower($value) == 'on' || $value == '1' ) {
			$this->html_table_row( 'file_uploads', 'On', 'On', 'success' );
		} else {
			$this->html_table_row( 'file_uploads', 'On', 'Off', 'error' );
		}

		$value = ini_get( 'upload_max_filesize' );
		if ( intval($value) < 32 ) {
			$this->html_table_row( 'upload_max_filesize', '32M', $value, 'warning' );
		} else {
			$this->html_table_row( 'upload_max_filesize', '32M', $value, 'success' );
		}

		$value = ini_get( 'post_max_size' );
		if ( intval($value)  < 32 ) {
			$this->html_table_row( 'post_max_size', '32M', $value, 'warning' );
		} else {
			$this->html_table_row( 'post_max_size', '32M', $value, 'success' );
		}

		$value = ini_get( 'short_open_tag' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'short_open_tag', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'short_open_tag', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'safe_mode' );
		if ( strtolower($value) == 'on' ) {
			$this->html_table_row( 'safe_mode', 'Off', 'On', 'warning' );
		} else {
			$this->html_table_row( 'safe_mode', 'Off', 'Off', 'success' );
		}

		$value = ini_get( 'open_basedir' );
		$this->html_table_row( 'open_basedir', '', $value, 'info' );

		$value = ini_get( 'zlib.output_compression' );
		$this->html_table_row( 'zlib.output_compression', '', $value, 'info' );

		$value = ini_get( 'output_handler' );
		$this->html_table_row( 'output_handler', '', $value, 'info' );

		$value = ini_get( 'expose_php' );
		$this->html_table_row( 'expose_php', '0 or Off', $value, 'info' );

		$value = ini_get( 'upload_tmp_dir' );
		$this->html_table_row( 'upload_tmp_dir', '', $value, 'info' );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'upload_tmp_dir writable ?', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'upload_tmp_dir writable ?', 'Yes', 'No', 'error' );
		}

		$value = dirname(__FILE__);
		$this->html_table_row( 'Current dir', '', $value, 'info' );
		if ( is_dir( $value ) && @is_writable( $value ) ) {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'Yes', 'success' );
		} else {
			$this->html_table_row( 'Current dir writable ?', 'Yes', 'No', 'error' );
		}
			
		if ( is_callable('apc_store') ) {
			$value = init_get('apc.shm_size');
			if ( intval($value) < 32 ) {
				$this->html_table_row( 'apc.shm_size', '32M', $value, 'warning' );
			} else {
				$this->html_table_row( 'apc.shm_size', '32M', $value, 'success' );
			}
		}

		$this->html_table_close( );
	}

	public function test_mysql_config( ) {
		if ( $this->db_link == false ) {
			return false;
		}

		$this->html_table_open( 'MySQL Configuration', '', 'Recommended', 'Current' );

		$result = mysql_query("SHOW VARIABLES LIKE 'have_query_cache'", $this->db_link );
		if ( $result) {
		   while ($row = mysql_fetch_assoc($result)) {
		   		if ( strtolower($row['Value']) == 'yes' ) {
		   			$this->html_table_row( "Query cache", 'Yes', 'Yes', 'success' );
		   		} else {
		   			$this->html_table_row( "Query cache", 'Yes', 'False', 'error' );
		   		}
		   }
		}

		$result = mysql_query("SHOW VARIABLES LIKE 'query_cache_size'", $this->db_link );
		if ( $result) {
		   while ($row = mysql_fetch_assoc($result)) {
		   		if ( intval($row['Value']) >= 8388608 ) {
		   			$this->html_table_row( "Query cache size", '8M', $this->_format_bytes((int)$row['Value']), 'success' );
		   		} else {
		   			$this->html_table_row( "Query cache size", '8M', $this->_format_bytes((int)$row['Value']), 'error' );
		   		}
		   }
		}

		$result = mysql_query("SHOW VARIABLES LIKE 'query_cache_type'", $this->db_link );
		if ( $result) {
		   while ($row = mysql_fetch_assoc($result)) {
		   		if ( strtolower($row['Value']) == 'on' || strtolower($row['Value']) == '1' ) {
		   			$this->html_table_row( "Query cache type", '1 or on', strtolower($row['Value']), 'success' );
		   		} else {
		   			$this->html_table_row( "Query cache type", '1', strtolower($row['Value']), 'error' );
		   		}
		   }
		}

		$result = mysql_query("SHOW VARIABLES LIKE 'log_slow_queries'", $this->db_link );
		if ( $result) {
		   while ($row = mysql_fetch_assoc($result)) {
		   		if ( strtolower($row['Value']) == 'yes' ) {
		   			$this->html_table_row( "Log slow queries", 'Yes', 'Yes', 'success' );
		   		} else {
		   			$this->html_table_row( "Log slow queries", 'Yes', 'False', 'error' );
		   		}
		   }
		}

		$result = mysql_query("SHOW VARIABLES LIKE 'long_query_time'", $this->db_link );
		if ( $result) {
		   while ($row = mysql_fetch_assoc($result)) {
		   		if ( intval($row['Value']) < 2 ) {
		   			$this->html_table_row( "Long query time", '2', ((int)$row['Value']), 'success' );
		   		} else {
		   			$this->html_table_row( "Long query time", '2', ((int)$row['Value']), 'error' );
		   		}
		   }
		}

		$this->html_table_close( );
	}
	
	public function test_mail() {
		$this->html_table_open( 'Email Configuration', '', '', '' );
		$this->html_form_email();
		$this->html_table_close( );
	}

	/**
	 * Start HTML, call CSS/JS from CDN
	 * Link to Github
	 * TODO: Add links to Codex/WP.org
	 * TODO: Add colors legend
	 */
	public function get_header( ) {
		$output = '';
		$output .= '<!DOCTYPE html>' . "\n";
		$output .= '<html lang="en">' . "\n";
		$output .= '<head>' . "\n";
		$output .= '<meta charset="utf-8">' . "\n";
		$output .= '<title>PHP WordPress Info</title>' . "\n";
		$output .= '<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.1.0/css/bootstrap-combined.min.css" rel="stylesheet">' . "\n";
		$output .= '<style>.table tbody tr.warning td{background-color:#FCF8E3;}</style>' . "\n";
		$output .= '<!--[if lt IE 9]> <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script> <![endif]-->' . "\n";
		$output .= '</head>' . "\n";
		$output .= '<body style="padding:10px 0;">' . "\n";
		$output .= '<div class="container">' . "\n";
		$output .= '<div class="navbar">' . "\n";
		$output .= '<div class="navbar-inner">' . "\n";
		$output .= '<a class="brand" href="#">PHP WordPress Info</a>' . "\n";
		$output .= '<ul class="nav pull-right">' . "\n";
		$output .= '<li><a href="https://github.com/herewithme/phpwpinfo">Project on Github</a></li>' . "\n";
		
		if ( $this->db_link != false ) {
			$output .= '<li class="dropdown">' . "\n";
				$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">MySQL <b class="caret"></b></a>' . "\n";
				$output .= '<ul class="dropdown-menu">' . "\n";
					$output .= '<li><a href="?logout=true">Logout</a></li>' . "\n";
				$output .= '</ul>' . "\n";
			$output .= '</li>' . "\n";
		}

		$output .= '<li class="dropdown">' . "\n";
			$output .= '<a href="#" class="dropdown-toggle" data-toggle="dropdown">Tools <b class="caret"></b></a>' . "\n";
			$output .= '<ul class="dropdown-menu">' . "\n";
				$output .= '<li><a href="?phpinfo=true">PHPinfo()</a></li>' . "\n";
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
	public function get_footer( ) {
		$output = '';

		$output .= '<footer>&copy; BeAPI 2012</footer>' . "\n";
		$output .= '</div>' . "\n";
		$output .= '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8/jquery.min.js"></script>' . "\n";
		$output .= '<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.1.0/js/bootstrap.min.js"></script>' . "\n";
		$output .= '</body>' . "\n";
		$output .= '</html>' . "\n";

		echo $output;
	}

	/**
	 * Open a HTML table
	 */
	public function html_table_open( $title = '', $col1 = '', $col2 = '', $col3 = '' ) {
		$output = '';
		$output .= '<table class="table table-bordered">' . "\n";
		$output .= '<caption>' . $title . '</caption>' . "\n";
		$output .= '<thead>' . "\n";

		if ( !empty($col1) || !empty($col2) || !empty($col3) ) {
			$output .= '<tr>' . "\n";
			$output .= '<th width="40%">' . $col1 . '</th>' . "\n";
			$output .= '<th width="30%">' . $col2 . '</th>' . "\n";
			$output .= '<th width="30%">' . $col3 . '</th>' . "\n";
			$output .= '</tr>' . "\n";
		}

		$output .= '</thead>' . "\n";
		$output .= '<tbody>' . "\n";

		echo $output;
	}

	/**
	 * Close HTML table
	 */
	public function html_table_close( ) {
		$output = '';
		$output .= '</tbody>' . "\n";
		$output .= '</table>' . "\n";

		echo $output;
	}

	/**
	 * Add table row
	 * Status available : success, error, warning, info
	 */
	public function html_table_row( $col1 = '', $col2 = '', $col3 = '', $status = 'success' ) {
		$output = '';
		$output .= '<tr class="' . $status . '">' . "\n";
		$output .= '<td>' . $col1 . '</td>' . "\n";
		$output .= '<td>' . $col2 . '</td>' . "\n";
		$output .= '<td>' . $col3 . '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Form HTML for MySQL Login
	 * @param  boolean $show_error_credentials [description]
	 * @return void                          [description]
	 */
	public function html_form_mysql( $show_error_credentials = false ) {
		$output = '';
		$output .= '<tr>' . "\n";
		$output .= '<td colspan="3">' . "\n";

		if ( $show_error_credentials == true )
			$output .= '<div class="alert alert-error">Credentials invalid.</div>' . "\n";

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

		if ( isset($_POST['test-email']) && isset($_POST['mail']) ) {
			if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)) { // Invalid
				$output .= '<div class="alert alert-error">Email invalid.</div>' . "\n";
			} else { // Valid mail
				if ( mail($_POST['mail'], 'Email test with PHP WP Info', "Line 1\nLine 2\nLine 3\nGreat !") ) { // Valid send
					$output .= '<div class="alert alert-success">Mail sent with success.</div>' . "\n";
				} else { // Error send
					$output .= '<div class="alert alert-error">An error occured during mail sending.</div>' . "\n";
				}
			}
		}

		$output .= '<form id="form-email" class="form-inline" method="post" action="#form-email">' . "\n";
		$output .= '<i class="icon-envelope"></i> <input type="mail" class="input-large" name="mail" placeholder="test@sample.com" value="">' . "\n";
		$output .= '<button name="test-email" type="submit" class="btn">Send mail</button>' . "\n";
		$output .= '<span class="help-inline">Send a test email to check that server is doing its job</span>' . "\n";
		$output .= '</form>' . "\n";
		$output .= '</td>' . "\n";
		$output .= '</tr>' . "\n";

		echo $output;
	}

	/**
	 * Stripslashes array
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	public function stripslashes_deep( $value ) {
		return is_array( $value ) ? array_map( array( &$this, 'stripslashes_deep' ), $value ) : stripslashes( $value );
	}

	/**
	 * Detect current webserver
	 *
	 * @return string        [description]
	 */
	private function _get_current_webserver( ) {
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
	private function _get_apache_modules( ) {
		$apache_modules = (is_callable( 'apache_get_modules' ) ? apache_get_modules( ) : false);

		if ( $apache_modules === false && isset( $_SERVER['http_mod_env'] ) ) {
			// Test with htaccess to get ENV values
			$apache_modules = array( 'mod_env' );

			if ( isset( $_SERVER['http_mod_rewrite'] ) ) {
				$apache_modules[] = 'mod_rewrite';
			}
			if ( isset( $_SERVER['http_mod_deflate'] ) ) {
				$apache_modules[] = 'mod_deflate';
			}
			if ( isset( $_SERVER['http_mod_expires'] ) ) {
				$apache_modules[] = 'mod_expires';
			}
			if ( isset( $_SERVER['http_mod_filter'] ) ) {
				$apache_modules[] = 'mod_filter';
			}
			if ( isset( $_SERVER['http_mod_headers'] ) ) {
				$apache_modules[] = 'mod_headers';
			}
			if ( isset( $_SERVER['http_mod_mime'] ) ) {
				$apache_modules[] = 'mod_mime';
			}
			if ( isset( $_SERVER['http_mod_setenvif'] ) ) {
				$apache_modules[] = 'mod_setenvif';
			}
		}

		return $apache_modules;
	}

	/**
	 * Get humans values, take from http://php.net/manual/de/function.filesize.php
	 * @param  integer  $bytes     [description]
	 * @return string          	   [description]
	 */
	function _format_bytes($size) {
		$units = array(' B', ' KB', ' MB', ' GB', ' TB');
		for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
		return round($size, 2).$units[$i];
	} 

}

// Init render
phpwpinfo( );
