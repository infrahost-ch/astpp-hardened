<?php
if (! defined('BASEPATH')) {
	exit('No direct script access allowed');
}

/*
 * ASTPP fork (audit O4): Content-Security-Policy (hook post_controller_constructor, config/hooks.php).
 *
 * Stage 2 (11.10.2026): the policy is enforced (Content-Security-Policy). Every <script> tag of the
 * views carries the nonce (<script ...<?php echo csp_nonce_attr(); ?>>, also <script src>: jQuery
 * runs the scripts of AJAX popups only with the nonce of the tag); event handlers are
 * data-astpp-<event>="..." attributes, run without eval by assets/js/astpp_csp.js; no javascript:
 * URLs, no eval. Violations are still reported to /csp_report/ (index.php ->
 * application/csp_report.php, stored deduplicated in the table csp_reports).
 * Stage 1 (report only) is still available as csp = report.
 *
 *  - script-src 'self' 'nonce-<n>': own script files and script tags that carry the nonce. The
 *    nonce belongs to the session and is renewed when the login state changes (like the CSRF
 *    token), not per response: popups are loaded by AJAX into the page, their scripts must match
 *    the policy of that page. No 'unsafe-inline', no 'unsafe-eval'.
 *  - style-src keeps 'unsafe-inline' (style attributes in nearly every view; lower risk, later stage).
 *  - form-action allows PayPal (payment forms post there), everything else is this host only.
 *  - reports via report-uri only: browsers send them at once. With report-to (Reporting API) Chrome
 *    ignores report-uri and batches the reports (the browser of the test sent none at all).
 *    'report-sample' adds the first 40 characters of a blocked inline script to the report.
 *
 * Mode from astpp-config.conf: csp = enforce (default, also when the line is missing) | report
 * (Report-Only, fallback if something breaks) | off. Not sent for the API, FreeSWITCH, cron and
 * internal root controllers.
 */
#[\AllowDynamicProperties]
class Csp
{
	private static $nonce = '';

	/** Root controllers that serve pages of the logged-in browser (as in Csrf_guard). */
	private static $root_pages = array('getstatus', 'currencyupdate', 'translation_script');

	public function header()
	{
		if (php_sapi_name() === 'cli' || defined('CRON') || headers_sent()) {
			return;
		}
		$mode = self::mode();
		if ($mode === 'off') {
			return;
		}
		$CI = & get_instance();
		$RTR = $CI->router;
		$module = method_exists($RTR, 'fetch_module') ? (string) $RTR->fetch_module() : '';
		if ($module === '' && ! in_array(strtolower($RTR->fetch_class()), self::$root_pages, true)) {
			return;
		}
		self::$nonce = self::session_nonce($CI);
		header(($mode === 'enforce' ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . self::policy(self::$nonce));
	}

	/** ' nonce="..."' for inline <script> blocks, '' without policy (see csp_nonce_attr() in index.php). */
	public static function nonce_attr()
	{
		return self::$nonce === '' ? '' : ' nonce="' . self::$nonce . '"';
	}

	public static function mode()
	{
		$mode = strtolower(trim((string) config_item('csp')));
		return in_array($mode, array('off', 'report', 'enforce'), true) ? $mode : 'enforce';
	}

	public static function policy($nonce)
	{
		return implode('; ', array(
			"default-src 'self'",
			"script-src 'self' 'nonce-" . $nonce . "' 'report-sample'",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data: blob:",
			"font-src 'self' data:",
			"connect-src 'self'",
			"frame-src 'self'",
			"frame-ancestors 'self'",
			"form-action 'self' https://www.paypal.com https://www.sandbox.paypal.com",
			"base-uri 'self'",
			"object-src 'none'",
			'report-uri ' . base_url() . 'csp_report/',
		));
	}

	/** Nonce of the session; a new one when there is none or the login state changed. */
	private static function session_nonce($CI)
	{
		$account = $CI->session->userdata('accountinfo');
		$owner = $CI->session->userdata('user_login')
			? (is_array($account) && isset($account['id']) ? $account['id'] : '?') . '/' . $CI->session->userdata('logintype')
			: '';
		$nonce = (string) $CI->session->userdata('csp_nonce');
		if (! preg_match('/^[0-9a-f]{32}$/', $nonce) || (string) $CI->session->userdata('csp_nonce_for') !== $owner) {
			$nonce = bin2hex(random_bytes(16));
			$CI->session->set_userdata(array('csp_nonce' => $nonce, 'csp_nonce_for' => $owner));
		}
		return $nonce;
	}
}
