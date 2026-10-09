<?php
/*
 * ---------------------------------------------------------------
 * APPLICATION ENVIRONMENT
 * ---------------------------------------------------------------
 *
 * You can load different configurations depending on your
 * current environment. Setting the environment also influences
 * things like logging and error reporting.
 *
 * This can be set to anything, but default usage is:
 *
 * development
 * testing
 * production
 *
 * NOTE: If you change these, also change the error_reporting() code below
 *
 */
ini_set('display_errors','off'); // HARDENING
// PHP8: PHP 7 comparison semantics for rewritten comparisons (see application/php7compat.php)
require_once __DIR__ . '/application/php7compat.php';
ini_set ( "date.timezone", "UTC" );
// PHP8: since PHP 8.1 mysqli throws exceptions by default. CodeIgniter 2 expects
// FALSE return values and handles errors itself.
if (function_exists ( 'mysqli_report' )) {
	mysqli_report ( MYSQLI_REPORT_OFF );
}
define ( 'ENVIRONMENT', 'production' );
/*
 * ---------------------------------------------------------------
 * ERROR REPORTING
 * ---------------------------------------------------------------
 *
 * Different environments will require different levels of error reporting.
 * By default development will show errors but testing and live will hide them.
 */

if (defined ( 'ENVIRONMENT' )) {
	switch (ENVIRONMENT) {
		case 'development' :
			error_reporting ( E_ALL );
			break;
		
		case 'testing' :
		case 'production' :
			// PHP8: errors are logged (never displayed, see _exception_handler()).
			// Set env[ASTPP_DEBUG_LOG] = 1 in the php-fpm pool to also log warnings,
			// notices and deprecations.
			if (getenv ( 'ASTPP_DEBUG_LOG' ) == '1') {
				error_reporting ( E_ALL );
			} else {
				error_reporting ( E_ALL & ~ E_DEPRECATED & ~ E_USER_DEPRECATED & ~ E_NOTICE & ~ E_USER_NOTICE & ~ E_WARNING );
			}
			break;
		
		default :
			exit ( 'The application environment is not set correctly.' );
	}
}

/*
 * ---------------------------------------------------------------
 * SYSTEM FOLDER NAME
 * ---------------------------------------------------------------
 *
 * This variable must contain the name of your "system" folder.
 * Include the path if the folder is not in the same directory
 * as this file.
 *
 */
$system_path = 'system';
/*
 * ---------------------------------------------------------------
 * ASSETS FOLDER NAME
 * ---------------------------------------------------------------
 *
 * This variable must contain the name of your "assets" folder.
 * Include the path if the folder is not in the same directory
 * as this file.
 *
 */
$assets_path = 'assets';
/*
 * ---------------------------------------------------------------
 * APPLICATION FOLDER NAME
 * ---------------------------------------------------------------
 *
 * If you want this front controller to use a different "application"
 * folder then the default one you can set its name here. The folder
 * can also be renamed or relocated anywhere on your server. If
 * you do, use a full server path. For more info please see the user guide:
 * http://codeigniter.com/user_guide/general/managing_apps.html
 *
 * NO TRAILING SLASH!
 *
 */
$application_folder = 'application';

/*
 * --------------------------------------------------------------------
 * DEFAULT CONTROLLER
 * --------------------------------------------------------------------
 *
 * Normally you will set your default controller in the routes.php file.
 * You can, however, force a custom routing by hard-coding a
 * specific controller class/function here. For most applications, you
 * WILL NOT set your routing here, but it's an option for those
 * special instances where you might want to override the standard
 * routing in a specific front controller that shares a common CI installation.
 *
 * IMPORTANT: If you set the routing here, NO OTHER controller will be
 * callable. In essence, this preference limits your application to ONE
 * specific controller. Leave the function name blank if you need
 * to call functions dynamically via the URI.
 *
 * Un-comment the $routing array below to use this feature
 *
 */
// The directory name, relative to the "controllers" folder. Leave blank
// if your controller is not in a sub-folder within the "controllers" folder
// $routing['directory'] = '';

// The controller class file name. Example: Mycontroller
// $routing['controller'] = '';

// The controller function you wish to be called.
// $routing['function'] = '';

/*
 * -------------------------------------------------------------------
 * CUSTOM CONFIG VALUES
 * -------------------------------------------------------------------
 *
 * The $assign_to_config array below will be passed dynamically to the
 * config class when initialized. This allows you to set custom config
 * items or override any default config values found in the config.php file.
 * This can be handy as it permits you to share one application between
 * multiple front controller files, with each file containing different
 * config values.
 *
 * Un-comment the $assign_to_config array below to use this feature
 *
 */
// $assign_to_config['name_of_config_item'] = 'value of config item';

// --------------------------------------------------------------------
// END OF USER CONFIGURABLE SETTINGS. DO NOT EDIT BELOW THIS LINE
// --------------------------------------------------------------------

/*
 * ---------------------------------------------------------------
 * Resolve the system path for increased reliability
 * ---------------------------------------------------------------
 */

// Set the current directory correctly for CLI requests
if (defined ( 'STDIN' )) {
	chdir ( dirname ( __FILE__ ) );
}

if (realpath ( $system_path ) !== FALSE) {
	$system_path = realpath ( $system_path ) . '/';
}

// ensure there's a trailing slash
$system_path = rtrim ( $system_path, '/' ) . '/';

// Is the system path correct?
if (! is_dir ( $system_path )) {
	exit ( "Your system folder path does not appear to be set correctly. Please open the following file and correct this: " . pathinfo ( __FILE__, PATHINFO_BASENAME ) );
}

// Kinjal ASTPPCOM-975 Start
if((substr($_SERVER['REQUEST_URI'], 0, strlen('//')) === '//')){
    $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'],1);
}
$folder_name = explode('/',$_SERVER['REQUEST_URI']) ;
if($folder_name['1'] == 'api' || $folder_name['1'] == 'admin'){
        if (! is_dir ($application_folder . '/controllers/'.$folder_name['1'])) {
        $array = array (
            'status'  => false,
            'error'   => 'API Addon is not installed, Please contact administrator',
            'response_code' => 400
        );
        echo json_encode($array); die;
    }
}
// Kinjal ASTPPCOM-975 END

/*
 * -------------------------------------------------------------------
 * Now that we know the path, set the main path constants
 * -------------------------------------------------------------------
 */
// The name of THIS file
define ( 'SELF', pathinfo ( __FILE__, PATHINFO_BASENAME ) );

// The PHP file extension
// this global constant is deprecated.
define ( 'EXT', '.php' );

// Path to the system folder
define ( 'BASEPATH', str_replace ( "\\", "/", $system_path ) );

// Path to the front controller (this file)
define ( 'FCPATH', str_replace ( SELF, '', __FILE__ ) );

// Name of the "system folder"
define ( 'SYSDIR', trim ( strrchr ( trim ( BASEPATH, '/' ), '/' ), '/' ) );

// Name of the "assets folder'
define ( 'ASSETSDIR', realpath ( $assets_path ) . '/' );

// The path to the "application" folder
if (is_dir ( $application_folder )) {
	define ( 'APPPATH', $application_folder . '/' );
} else {
	if (! is_dir ( BASEPATH . $application_folder . '/' )) {
		exit ( "Your application folder path does not appear to be set correctly. Please open the following file and correct this: " . SELF );
	}
	
	define ( 'APPPATH', BASEPATH . $application_folder . '/' );
}

/*
 * --------------------------------------------------------------------
 * LOAD THE BOOTSTRAP FILE
 * --------------------------------------------------------------------
 *
 * And away we go...
 *
 */
/*
 * HARDENING: TRUE for CLI/cron runs and for HTTP requests the server makes to itself.
 * The jobs in `cron_settings` call the cron controllers via "wget {BASE_URL}...", so
 * those controllers must stay reachable over HTTP - but only from this host.
 */
function astpp_internal_request() {
	if (defined ( 'CRON' ) || php_sapi_name () === 'cli') {
		return TRUE;
	}
	$remote = isset ( $_SERVER ['REMOTE_ADDR'] ) ? ( string ) $_SERVER ['REMOTE_ADDR'] : '';
	if (strpos ( $remote, '127.' ) === 0 || $remote === '::1') {
		return TRUE;
	}
	return ($remote !== '' && isset ( $_SERVER ['SERVER_ADDR'] ) && $remote === ( string ) $_SERVER ['SERVER_ADDR']);
}

/*
 * HARDENING: global normalization of request parameters that ASTPP concatenates
 * into SQL in many places (bulk actions and the left panel quick search).
 *  - selected_ids:       "'1','2','3'" -> only quoted integers survive
 *  - left_panel_search:  quotes/backslashes removed
 */
if (isset ( $_POST ['selected_ids'] )) {
	$astpp_ids = array ();
	foreach ( explode ( ',', ( string ) $_POST ['selected_ids'] ) as $astpp_id ) {
		$astpp_id = trim ( $astpp_id, " '\"" );
		if ($astpp_id !== '' && ctype_digit ( $astpp_id )) {
			$astpp_ids [] = "'" . $astpp_id . "'";
		}
	}
	$_POST ['selected_ids'] = empty ( $astpp_ids ) ? "'0'" : implode ( ',', $astpp_ids );
	unset ( $astpp_ids, $astpp_id );
}
if (isset ( $_POST ['left_panel_search'] )) {
	$_POST ['left_panel_search'] = str_replace ( array (
			"'",
			'"',
			astpp_chr ( 92 ), // backslash
			'`',
			';' 
	), '', ( string ) $_POST ['left_panel_search'] );
}

require_once BASEPATH . 'core/CodeIgniter.php';

/* End of file index.php */
/* Location: ./index.php */
