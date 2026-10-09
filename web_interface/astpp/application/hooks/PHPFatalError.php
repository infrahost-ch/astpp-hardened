<?php
#[\AllowDynamicProperties]
class PHPFatalError{
	protected $CI;
	public function setHandler() {
		set_exception_handler('exception_handler');
	}
}
// PHP8: TypeError/ValueError/mysqli_sql_exception etc. are Throwables. Log them with
// file, line and trace, but never show internals (paths, SQL) to the browser.
function exception_handler($exception) {
	error_log('PHP Uncaught ' . get_class($exception) . ': ' . $exception->getMessage()
		. ' in ' . $exception->getFile() . ' on line ' . $exception->getLine()
		. (isset($_SERVER['REQUEST_URI']) ? ' [' . $_SERVER['REQUEST_URI'] . ']' : '')
		. "\n" . $exception->getTraceAsString());
	if (defined('ENVIRONMENT') && ENVIRONMENT == 'development') {
		show_error($exception->getMessage(), 500);
	}
	show_error('An internal error occurred. Please contact the administrator.', 500);
}
