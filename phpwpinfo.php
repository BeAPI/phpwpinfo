<?php
function phpwpinfo( ) {
	$info = new PHP_WP_Info( );
	$info->init_all_tests( );
}

/**
 * TODO: Use or not session for save configuration
 */
class PHP_WP_Info {
	private $php_version = '5.2.4';
	private $mysql_version = '5.0';

	private $db_infos = array( );
	private $db_link = null;

	public function __construct( ) {
		@session_start( );

		if ( isset( $_GET ) && isset( $_GET['logout'] ) && $_GET['logout'] == 'true' ) {
			// Flush old session if POST submit
			unset( $_SESSION['credentials'] );

			header( "Location: http://" . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME'], true );
			exit( );
		}

		if ( isset( $_POST ) && isset( $_POST['mysql-connection'] ) ) {// Check POST data
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
		if ( !empty( $this->db_infos ) && is_array( $this->db_infos ) ) {
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
		$php_version = phpversion( );
		if ( version_compare( $php_version, $this->php_version, '>=' ) ) {
			$this->html_table_row( 'PHP Version', $this->php_version, $php_version, 'success' );
		} else {
			$this->html_table_row( 'PHP Version', $this->php_version, $php_version, 'error' );
		}

		// Test MYSQL Client extensions/version
		if ( !extension_loaded( 'mysql' ) ) {
			$this->html_table_row( 'PHP MySQL Extension', 'Required', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'PHP MySQL Extension', 'Required', 'Installed', 'success' );
			$this->html_table_row( 'PHP MySQL Client Version', '-', mysql_get_client_info( ), 'info' );
		}

		// Test MySQL Server Version
		if ( $this->db_link != false ) {
			$mysql_version = mysql_get_server_info( $this->db_link );
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

		if ( !extension_loaded( 'gd' ) ) {
			$this->html_table_row( 'GD Extension', 'Required', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'GD Extension', 'Required', 'Installed', 'success' );
		}

		if ( !extension_loaded( 'curl' ) ) {
			$this->html_table_row( 'CURL Extension', 'Recommended', 'Not installed', 'error' );
		} else {
			$this->html_table_row( 'CURL Extension', 'Recommended', 'Installed', 'success' );
		}

		if ( extension_loaded( 'eaccelerator' ) ) {
			$this->html_table_row( 'Opcode Extension (APC or Xcache or eAccelerator)', 'Recommended', 'eAccelerator Installed', 'success' );
		} elseif ( extension_loaded( 'xcache' ) ) {
			$this->html_table_row( 'Opcode Extension (APC or Xcache or eAccelerator)', 'Recommended', 'XCache Installed', 'success' );
		} elseif ( extension_loaded( 'apc' ) ) {
			$this->html_table_row( 'Opcode Extension (APC or Xcache or eAccelerator)', 'Recommended', 'APC Installed', 'success' );
		} else {
			$this->html_table_row( 'Opcode Extension (APC or Xcache or eAccelerator)', 'Recommended', 'Not installed', 'error' );
		}

		$this->html_table_close( );
		// GD
		// CURL
		// APC or Xcache or Memcache
	}

	public function test_apache_modules( ) {

	}

	public function test_php_config( ) {

	}

	public function test_mysql_config( ) {
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
		$output .= '<body style="padding-top:10px;">' . "\n";
		$output .= '<div class="container">' . "\n";
		$output .= '<div class="navbar">' . "\n";
		$output .= '<div class="navbar-inner">' . "\n";
		$output .= '<a class="brand" href="#">PHP WordPress Info</a>' . "\n";
		$output .= '<ul class="nav pull-right">' . "\n";
		$output .= '<li><a href="https://github.com/herewithme/phpwpinfo">Project on Github</a></li>' . "\n";
		$output .= '<li><a href="?logout=true">Logout MySQL</a></li>' . "\n";
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
		$output .= '<tr>' . "\n";
		$output .= '<th>' . $col1 . '</th>' . "\n";
		$output .= '<th>' . $col2 . '</th>' . "\n";
		$output .= '<th>' . $col3 . '</th>' . "\n";
		$output .= '</tr>' . "\n";
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

	public function stripslashes_deep( $value ) {
		return is_array( $value ) ? array_map( array( &$this, 'stripslashes_deep' ), $value ) : stripslashes( $value );
	}

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

}

// Init render
phpwpinfo( );
