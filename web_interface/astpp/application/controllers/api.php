<?php
// ##############################################################################
// ASTPP - Open Source VoIP Billing Solution
//
// Copyright (C) 2026 infrahost, based on ASTPP (C) iNextrix Technologies Pvt. Ltd.
// License https://www.gnu.org/licenses/agpl-3.0.html
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program. If not, see <http://www.gnu.org/licenses/>.
// ##############################################################################

/*
 * ASTPP fork: provisioning API, JSON over HTTPS, /api/v1/...
 * Replaces the vendor's "api" add-on (removed from the fork: unauthenticated SQL injection,
 * token bypass, public default keys, passwords in logs).
 *
 * - Authentication: Authorization: Bearer <API key> (libraries/astpp/api_keys.php). Keys are
 *   created by admins, resellers and customers in the portal (API Access) and only work from
 *   the source addresses allowed for the key.
 * - Scope: admin = all customers, reseller = own customers, customer = own account.
 * - Never uses session data for authorization. Accounts are created with the same library as
 *   the portal (signup_lib), packages are assigned with the same order library.
 * - Documentation: docs/api/ in the repository, in the portal under API Access.
 */
if (! defined('BASEPATH'))
	exit('No direct script access allowed');

class Api_error extends Exception {
	public $status;
	public $error_code;
	public $fields;
	function __construct($status, $error_code, $message, $fields = array()) {
		parent::__construct($message);
		$this->status = $status;
		$this->error_code = $error_code;
		$this->fields = $fields;
	}
}

#[\AllowDynamicProperties]
class Api extends CI_Controller {
	const MAX_BODY = 65536;
	const ROLE_NAMES = array(-1 => 'admin', 1 => 'reseller', 0 => 'customer');
	const SIP_PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

	private $key = NULL;
	private $role = '';
	private $account_id = 0;
	private $started = 0.0;
	private $log_target = '';
	private $request_method = '';
	private $path = '';
	private $query = array();
	private $new_session = true;

	function __construct() {
		parent::__construct();
		$this->new_session = ! isset($_COOKIE[(string) $this->config->item('sess_cookie_name')]);
		// database errors must not end in CodeIgniter's HTML error page: failed queries return
		// FALSE, the handlers turn that into a JSON 500 (see _remap)
		$this->db->db_debug = FALSE;
		$this->load->library('astpp/api_keys');
	}

	public function _remap($method, $params = array()) {
		$this->started = microtime(true);
		$this->request_method = strtoupper((string) $this->input->server('REQUEST_METHOD'));
		$this->path = implode('/', $params);
		parse_str((string) $this->input->server('QUERY_STRING'), $this->query);
		try {
			if ($method !== 'v1') {
				throw new Api_error(404, 'not_found', 'Unknown API version. Use /api/v1/.');
			}
			$this->authenticate();
			$this->dispatch();
		} catch (Api_error $e) {
			$error = array('code' => $e->error_code, 'message' => $e->getMessage());
			if (! empty($e->fields)) {
				$error['fields'] = $e->fields;
			}
			$this->send($e->status, array('error' => $error));
		} catch (Throwable $e) {
			$db_error = $this->db->_error_message();
			error_log('ASTPP API: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . ($db_error !== '' ? ' (last DB error: ' . $db_error . ')' : ''));
			$this->send(500, array('error' => array('code' => 'internal_error', 'message' => 'An internal error occurred.')));
		}
	}

	// ------------------------------------------------------------------ routing

	private function routes() {
		$all = array('admin', 'reseller', 'customer');
		$ar = array('admin', 'reseller');
		return array(
			array('GET', '#^me$#', 'me', $all),
			array('GET', '#^rate-groups$#', 'rate_groups', $ar),
			array('GET', '#^products$#', 'products', $ar),
			array('GET', '#^customers$#', 'customers_list', $ar),
			array('POST', '#^customers$#', 'customer_create', $ar),
			array('GET', '#^customers/(\d+)$#', 'customer_get', $all),
			array('PATCH', '#^customers/(\d+)$#', 'customer_update', $ar),
			array('GET', '#^customers/(\d+)/balance$#', 'customer_balance', $all),
			array('GET', '#^customers/(\d+)/usage$#', 'customer_usage', $all),
			array('GET', '#^customers/(\d+)/cdrs$#', 'customer_cdrs', $all),
			array('GET', '#^customers/(\d+)/products$#', 'customer_products', $all),
			array('POST', '#^customers/(\d+)/products$#', 'customer_product_assign', $ar),
			array('DELETE', '#^customers/(\d+)/products/(\d+)$#', 'customer_product_terminate', $ar),
			array('GET', '#^customers/(\d+)/sip-devices$#', 'sip_devices_list', $all),
			array('POST', '#^customers/(\d+)/sip-devices$#', 'sip_device_create', $ar),
			array('GET', '#^sip-devices/(\d+)$#', 'sip_device_get', $all),
			array('PATCH', '#^sip-devices/(\d+)$#', 'sip_device_update', $all),
			array('POST', '#^sip-devices/(\d+)/reset-password$#', 'sip_device_reset_password', $all),
			array('DELETE', '#^sip-devices/(\d+)$#', 'sip_device_delete', $ar)
		);
	}

	private function dispatch() {
		$path_matched = false;
		foreach ($this->routes() as $route) {
			list($verb, $pattern, $handler, $roles) = $route;
			if (! preg_match($pattern, $this->path, $m)) {
				continue;
			}
			$path_matched = true;
			if ($verb !== $this->request_method) {
				continue;
			}
			if (! in_array($this->role, $roles, true)) {
				throw new Api_error(403, 'forbidden', 'This API key may not use this endpoint.');
			}
			array_shift($m);
			$this->$handler(...array_map('intval', $m));
			return;
		}
		if ($path_matched) {
			throw new Api_error(405, 'method_not_allowed', 'Method ' . $this->request_method . ' is not allowed for this endpoint.');
		}
		throw new Api_error(404, 'not_found', 'Unknown endpoint.');
	}

	// ------------------------------------------------------------------ authentication

	private function authenticate() {
		$header = (string) $this->input->server('HTTP_AUTHORIZATION');
		$presented = '';
		if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
			$presented = $m[1];
		} elseif ($this->input->server('HTTP_X_API_KEY')) {
			$presented = trim((string) $this->input->server('HTTP_X_API_KEY'));
		}
		if ($presented === '') {
			header('WWW-Authenticate: Bearer');
			throw new Api_error(401, 'unauthorized', 'Missing API key. Send it as "Authorization: Bearer <key>".');
		}
		$key = $this->api_keys->authenticate($presented);
		if ($key === NULL) {
			header('WWW-Authenticate: Bearer error="invalid_token"');
			throw new Api_error(401, 'unauthorized', 'Invalid, disabled or revoked API key.');
		}
		$ip = (string) $this->input->server('REMOTE_ADDR');
		$this->key = $key;
		if (! $this->api_keys->ip_allowed($ip, $key['allowed_ips'])) {
			throw new Api_error(403, 'ip_not_allowed', 'This API key may not be used from ' . $ip . '. Allow the address for the key in the portal (API Access).');
		}
		// audit O5: administrator keys also only from admin_allowed_ips (astpp-config.conf, empty = no restriction)
		if ((int) $key['acc_type'] === -1) {
			$this->load->library('astpp/common');
			if (! $this->common->admin_address_allowed($ip)) {
				throw new Api_error(403, 'ip_not_allowed', 'Administrator access is not allowed from ' . $ip . ' (admin_allowed_ips in astpp-config.conf).');
			}
		}
		$this->role = self::ROLE_NAMES[(int) $key['acc_type']];
		$this->account_id = (int) $key['accountid'];
		$this->api_keys->touch($key, $ip);
	}

	// ------------------------------------------------------------------ endpoints: general

	private function me() {
		$this->send(200, array('data' => array(
			'key' => array(
				'id' => $this->key['key_id'],
				'name' => $this->key['name'],
				'allowed_ips' => explode(',', $this->key['allowed_ips']),
				'created_at' => $this->iso($this->key['created_date'])
			),
			'account' => array(
				'id' => $this->account_id,
				'number' => $this->key['acc_number'],
				'role' => $this->role,
				'company_name' => $this->key['acc_company_name'],
				'currency' => $this->lookup_code('currency', 'currency', $this->key['acc_currency_id']),
				'timezone' => $this->lookup_code('timezone', 'timezone_name', $this->key['acc_timezone_id'])
			)
		)));
	}

	private function rate_groups() {
		$this->only_query(array('reseller_id'));
		$this->db->select('id, name, reseller_id, status');
		$this->db->where('status <>', 2);
		if ($this->role === 'reseller') {
			$this->db->where('reseller_id', $this->account_id);
		} elseif (isset($this->query['reseller_id'])) {
			$this->db->where('reseller_id', $this->query_int('reseller_id', 0));
		}
		$this->db->order_by('id', 'ASC');
		$rows = $this->db->get('pricelists')->result_array();
		$data = array();
		foreach ($rows as $r) {
			$data[] = array('id' => (int) $r['id'], 'name' => $r['name'], 'reseller_id' => $this->nullable_id($r['reseller_id']), 'status' => $this->status_name($r['status']));
		}
		$this->send(200, array('data' => $data));
	}

	private function products() {
		$this->only_query(array());
		if ($this->role === 'reseller') {
			$this->db->select('P.id, P.name, P.description, RP.price, RP.setup_fee, RP.free_minutes, RP.billing_type, RP.billing_days');
			$this->db->from('reseller_products AS RP');
			$this->db->join('products AS P', 'P.id = RP.product_id', 'inner');
			$this->db->where(array('RP.account_id' => $this->account_id, 'RP.status' => 0));
		} else {
			$this->db->select('P.id, P.name, P.description, P.price, P.setup_fee, P.free_minutes, P.billing_type, P.billing_days');
			$this->db->from('products AS P');
			$this->db->where('P.reseller_id', 0);
		}
		$this->db->where(array('P.status' => 0, 'P.is_deleted' => 0, 'P.product_category' => 1));
		$this->db->order_by('P.id', 'ASC');
		$data = array();
		foreach ($this->db->get()->result_array() as $r) {
			$data[] = array(
				'id' => (int) $r['id'],
				'name' => $r['name'],
				'description' => $r['description'],
				'type' => 'package',
				'price' => $this->money($r['price']),
				'setup_fee' => $this->money($r['setup_fee']),
				'free_minutes' => (int) $r['free_minutes'],
				'recurring' => (string) $r['billing_type'] === '1',
				'billing_days' => (int) $r['billing_days']
			);
		}
		$this->send(200, array('data' => $data));
	}

	// ------------------------------------------------------------------ endpoints: customers

	private function customers_list() {
		$this->only_query(array('limit', 'offset', 'q', 'reseller_id', 'status'));
		list($limit, $offset) = $this->paging();
		$apply = function () {
			$this->db->from('accounts');
			$this->db->where(array('type' => 0, 'deleted' => 0));
			if ($this->role === 'reseller') {
				$this->db->where('reseller_id', $this->account_id);
			} elseif (isset($this->query['reseller_id'])) {
				$this->db->where('reseller_id', $this->query_int('reseller_id', 0));
			}
			if (isset($this->query['status'])) {
				$status = $this->query['status'];
				if ($status !== 'active' && $status !== 'inactive') {
					throw new Api_error(400, 'invalid_parameter', 'status must be active or inactive.');
				}
				$this->db->where('status', $status === 'active' ? 0 : 1);
			}
			if (isset($this->query['q']) && trim((string) $this->query['q']) !== '') {
				// CodeIgniter 2 has no group_start(): OR group as literal condition. escape_like_str()
				// (mysqli driver) escapes quotes and backslash-escapes the LIKE wildcards % and _.
				$like = "'%" . $this->db->escape_like_str(substr(trim((string) $this->query['q']), 0, 80)) . "%'";
				$conditions = array();
				foreach (array('number', 'company_name', 'first_name', 'last_name', 'email') as $col) {
					$conditions[] = $col . ' LIKE ' . $like;
				}
				$this->db->where('(' . implode(' OR ', $conditions) . ')', NULL, FALSE);
			}
		};
		$apply();
		$total = (int) $this->db->count_all_results();
		$apply();
		$this->db->order_by('id', 'ASC');
		$this->db->limit($limit, $offset);
		$data = array();
		foreach ($this->db->get()->result_array() as $row) {
			$data[] = $this->customer_out($row);
		}
		$this->send(200, array('data' => $data, 'meta' => array('total' => $total, 'limit' => $limit, 'offset' => $offset)));
	}

	private function customer_get($id) {
		$this->only_query(array());
		$this->send(200, array('data' => $this->customer_out($this->load_customer($id))));
	}

	private function customer_create() {
		$in = $this->body(array('reseller_id', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'address', 'country', 'currency', 'timezone', 'billing_type', 'credit_limit', 'max_channels', 'rate_group_id', 'localization_id', 'send_welcome_email', 'portal_password'));
		$welcome = false;
		if (array_key_exists('send_welcome_email', $in)) {
			if (! is_bool($in['send_welcome_email'])) {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('send_welcome_email' => 'Must be true or false.'));
			}
			$welcome = $in['send_welcome_email'];
			unset($in['send_welcome_email']);
		}
		$owner = 0;
		if ($this->role === 'reseller') {
			if (isset($in['reseller_id']) && (int) $in['reseller_id'] !== $this->account_id) {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('reseller_id' => 'Resellers can only create their own customers.'));
			}
			$owner = $this->account_id;
		} elseif (isset($in['reseller_id']) && $in['reseller_id'] !== NULL && (int) $in['reseller_id'] !== 0) {
			$owner = (int) $in['reseller_id'];
			$reseller = $this->db->get_where('accounts', array('id' => $owner, 'type' => 1, 'deleted' => 0))->row_array();
			if (empty($reseller)) {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('reseller_id' => 'No reseller with this id.'));
			}
		}
		$fields = $this->customer_fields($in, true, $owner);
		if ($welcome && (! isset($fields['email']) || $fields['email'] === '')) {
			throw new Api_error(422, 'validation_failed', 'Invalid input.', array('send_welcome_email' => 'Requires an e-mail address.'));
		}
		$this->load->library('astpp/signup_lib');
		// N13: without portal_password a generated one (passes the rule), returned once in the response
		$password = isset($fields['password']) ? NULL : $this->common->generate_password();
		$number = $this->common->find_uniq_rendno_customer(isset(Common_model::$global_config['system_config']['cardlength']) ? Common_model::$global_config['system_config']['cardlength'] : 10, 'number', 'accounts');
		$account = array_merge(array(
			'company_name' => '',
			'first_name' => '',
			'last_name' => '',
			'email' => '',
			'telephone_1' => '',
			// signup_lib sends the welcome e-mail (account number, link to set the password) when notifications
			// is 0; only on request, the account gets the system default afterwards
			'notifications' => $welcome ? '0' : '1',
			'number' => $number,
			'password' => $password === NULL ? '' : $this->common->password_store($password), // M6: hash; the response carries a generated password once (N13: portal_password in $fields wins)
			'type' => 0,
			'reseller_id' => $owner,
			'sweep_id' => 2,
			'invoice_day' => 1,
			'language_id' => 0,
			'cps' => 0,
			'local_call' => 0,
			'pin' => '',
			'reference' => '',
			'dialed_modify' => '',
			'notify_email' => '',
			'invoice_note' => '',
			'std_cid_translation' => '',
			'did_cid_translation' => '',
			'tax_number' => '',
			'first_used' => '0000-00-00 00:00:00',
			'is_distributor' => 0,
			'permission_id' => 0,
			'sip_device_flag' => '1', // the API creates SIP devices explicitly
			'notification_email' => isset($fields['email']) ? $fields['email'] : ''
		), $fields);
		$this->db->trans_start();
		$id = (int) $this->signup_lib->create_account($account);
		$this->db->trans_complete();
		if ($id <= 0 || $this->db->trans_status() === FALSE) {
			error_log('ASTPP API: customer not created: ' . $this->db->_error_message());
			throw new Api_error(500, 'internal_error', 'The customer could not be created.');
		}
		$this->db->where('id', $id);
		$this->db->update('accounts', array('notifications' => isset(Common_model::$global_config['system_config']['notifications']) ? Common_model::$global_config['system_config']['notifications'] : '0'));
		$this->log_target = 'customer ' . $id;
		$out = $this->customer_out($this->db->get_where('accounts', array('id' => $id))->row_array());
		if ($password !== NULL) {
			$out['portal_password'] = $password;
		}
		$this->send(201, array('data' => $out));
	}

	private function customer_update($id) {
		$customer = $this->load_customer($id);
		$in = $this->body(array('company_name', 'first_name', 'last_name', 'email', 'phone', 'address', 'country', 'timezone', 'billing_type', 'credit_limit', 'max_channels', 'rate_group_id', 'localization_id', 'status', 'portal_password'));
		$fields = $this->customer_fields($in, false, (int) $customer['reseller_id'], $customer);
		if (array_key_exists('status', $in)) {
			if ($in['status'] !== 'active' && $in['status'] !== 'inactive') {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('status' => 'Must be active or inactive.'));
			}
			$fields['status'] = $in['status'] === 'active' ? 0 : 1;
		}
		if (! empty($fields)) {
			$this->db->where('id', $id);
			$this->db->update('accounts', $fields);
		}
		$this->log_target = 'customer ' . $id;
		$this->send(200, array('data' => $this->customer_out($this->db->get_where('accounts', array('id' => $id))->row_array())));
	}

	private function customer_balance($id) {
		$this->only_query(array());
		$c = $this->load_customer($id);
		$postpaid = (string) $c['posttoexternal'] === '1';
		$available = $postpaid ? (float) $c['credit_limit'] - (float) $c['balance'] : (float) $c['balance'];
		$this->send(200, array('data' => array(
			'customer_id' => (int) $c['id'],
			'currency' => $this->lookup_code('currency', 'currency', $c['currency_id']),
			'billing_type' => $postpaid ? 'postpaid' : 'prepaid',
			'balance' => $this->money($c['balance']),
			'credit_limit' => $this->money($c['credit_limit']),
			'available' => $this->money($available)
		)));
	}

	private function customer_usage($id) {
		$this->only_query(array());
		$c = $this->load_customer($id);
		$packages = $this->active_packages((int) $c['id']);
		$free = 0;
		$used = 0;
		foreach ($packages as $p) {
			$free += $p['free_minutes'] * 60;
			$used += $p['used_seconds'];
		}
		foreach ($packages as &$p) {
			unset($p['used_seconds']);
		}
		$this->send(200, array('data' => array(
			'customer_id' => (int) $c['id'],
			'free_minutes' => $free / 60,
			'used_minutes' => round($used / 60, 2),
			'remaining_minutes' => round(max(0, $free - $used) / 60, 2),
			'packages' => $packages
		)));
	}

	private function customer_cdrs($id) {
		$this->only_query(array('from', 'to', 'limit', 'offset'));
		$c = $this->load_customer($id);
		list($limit, $offset) = $this->paging(100, 500);
		$from = $this->query_datetime('from', gmdate('Y-m-d 00:00:00', strtotime('-30 days')));
		$to = $this->query_datetime('to', gmdate('Y-m-d H:i:s'));
		$apply = function () use ($c, $from, $to) {
			$this->db->from('cdrs');
			$this->db->where(array('accountid' => (int) $c['id'], 'callstart >=' => $from, 'callstart <=' => $to));
		};
		$apply();
		$total = (int) $this->db->count_all_results();
		$apply();
		$this->db->select('uniqueid, callstart, end_stamp, callerid, callednum, notes, billseconds, disposition, call_direction, calltype, package_id, debit, sip_user');
		$this->db->order_by('callstart', 'DESC');
		$this->db->limit($limit, $offset);
		$data = array();
		foreach ($this->db->get()->result_array() as $r) {
			$data[] = array(
				'id' => $r['uniqueid'],
				'start' => $this->iso($r['callstart']),
				'end' => $this->iso($r['end_stamp']),
				'caller_id' => $r['callerid'],
				'destination' => $r['callednum'],
				'destination_name' => $r['notes'],
				'duration' => (int) $r['billseconds'],
				'disposition' => $r['disposition'],
				'direction' => $r['call_direction'],
				'package_call' => $r['calltype'] === 'FREE',
				'charge' => $r['calltype'] === 'FREE' ? 0.0 : $this->money($r['debit']),
				'sip_user' => $r['sip_user']
			);
		}
		$this->send(200, array('data' => $data, 'meta' => array('total' => $total, 'limit' => $limit, 'offset' => $offset, 'from' => $this->iso($from), 'to' => $this->iso($to))));
	}

	// ------------------------------------------------------------------ endpoints: packages

	private function customer_products($id) {
		$this->only_query(array());
		$c = $this->load_customer($id);
		$this->send(200, array('data' => $this->active_packages((int) $c['id'], false)));
	}

	private function customer_product_assign($id) {
		$c = $this->load_customer($id);
		$in = $this->body(array('product_id', 'quantity'));
		$errors = array();
		$product_id = $this->int_field($in, 'product_id', $errors, true, 1);
		$quantity = $this->int_field($in, 'quantity', $errors, false, 1, 50);
		$quantity = $quantity === NULL ? 1 : $quantity;
		$this->fail_on($errors);
		if ((string) $c['status'] !== '0') {
			throw new Api_error(409, 'customer_inactive', 'Packages can only be assigned to active customers.');
		}
		$product = $this->db->get_where('products', array('id' => $product_id, 'status' => 0, 'is_deleted' => 0, 'product_category' => 1))->row_array();
		$owner = (int) $c['reseller_id'];
		if (empty($product)) {
			throw new Api_error(422, 'validation_failed', 'Invalid input.', array('product_id' => 'No active package with this id.'));
		}
		if ($owner > 0) {
			$offer = $this->db->get_where('reseller_products', array('product_id' => $product_id, 'account_id' => $owner, 'status' => 0))->row_array();
			if (empty($offer)) {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('product_id' => 'The reseller of this customer does not offer this package.'));
			}
			$unit_price = (float) $offer['price'] + (float) $offer['setup_fee'];
		} else {
			if ((int) $product['reseller_id'] !== 0) {
				throw new Api_error(422, 'validation_failed', 'Invalid input.', array('product_id' => 'Not available for this customer.'));
			}
			$unit_price = (float) $product['price'] + (float) $product['setup_fee'];
		}
		$total = $unit_price * $quantity;
		$available = (string) $c['posttoexternal'] === '1' ? (float) $c['credit_limit'] - (float) $c['balance'] : (float) $c['balance'];
		if ($total > 0 && $available < $total) {
			throw new Api_error(402, 'insufficient_balance', 'The customer\'s available balance does not cover the price of the package.');
		}
		$this->load->library('astpp/order');
		$before = (int) $this->db->select_max('id')->get('order_items')->row()->id;
		for ($i = 0; $i < $quantity; $i++) {
			$this->order->confirm_order(array(
				'product_id' => $product_id,
				'product_category' => 1,
				'payment_by' => 0,
				'quantity' => 1,
				'accountid' => (int) $c['id'],
				'is_parent_billing' => 'true'
			), (int) $c['id'], array('id' => $this->account_id));
		}
		$this->db->select('id');
		$this->db->where(array('accountid' => (int) $c['id'], 'product_id' => $product_id, 'id >' => $before));
		$created = array_map('intval', array_column($this->db->get('order_items')->result_array(), 'id'));
		if (count($created) !== $quantity) {
			log_message('error', 'API: package ' . $product_id . ' for customer ' . $c['id'] . ': ' . count($created) . ' of ' . $quantity . ' orders created');
		}
		$this->log_target = 'customer ' . $c['id'] . ' package ' . $product_id . ' x' . count($created);
		$all = $this->active_packages((int) $c['id'], false);
		$this->send(201, array('data' => array_values(array_filter($all, function ($p) use ($created) {
			return in_array($p['id'], $created, true);
		}))));
	}

	private function customer_product_terminate($id, $item_id) {
		$this->only_query(array());
		$c = $this->load_customer($id);
		$item = $this->db->get_where('order_items', array('id' => $item_id, 'accountid' => (int) $c['id'], 'is_terminated' => 0))->row_array();
		if (empty($item)) {
			throw new Api_error(404, 'not_found', 'No active package with this id for this customer.');
		}
		$now = gmdate('Y-m-d H:i:s');
		$note = 'Terminated via API (key ' . $this->key['key_id'] . ')';
		$terminate = array('is_terminated' => 1, 'termination_date' => $now, 'termination_note' => $note);
		$this->db->trans_start();
		$this->db->where('id', $item_id);
		$this->db->update('order_items', $terminate);
		// the reseller's own order in the chain (created together with the customer's order)
		$order = $this->db->get_where('orders', array('id' => (int) $item['order_id']))->row_array();
		$guard = 0;
		while (! empty($order) && (int) $order['parent_order_id'] > 0 && $guard++ < 5) {
			$this->db->where(array('order_id' => (int) $order['parent_order_id'], 'is_terminated' => 0));
			$this->db->update('order_items', $terminate);
			$order = $this->db->get_where('orders', array('id' => (int) $order['parent_order_id']))->row_array();
		}
		$this->db->trans_complete();
		$this->log_target = 'customer ' . $c['id'] . ' order item ' . $item_id;
		$this->send(204, NULL);
	}

	// ------------------------------------------------------------------ endpoints: SIP devices

	private function sip_devices_list($id) {
		$this->only_query(array());
		$c = $this->load_customer($id);
		$this->db->order_by('id', 'ASC');
		$data = array();
		foreach ($this->db->get_where('sip_devices', array('accountid' => (int) $c['id']))->result_array() as $row) {
			$data[] = $this->sip_out($row);
		}
		$this->send(200, array('data' => $data));
	}

	private function sip_device_get($id) {
		$this->only_query(array());
		$this->send(200, array('data' => $this->sip_out($this->load_sip_device($id))));
	}

	private function sip_device_create($id) {
		$c = $this->load_customer($id);
		$in = $this->body(array('username', 'password', 'caller_id_name', 'caller_id_number', 'codec', 'status'));
		$errors = array();
		$username = isset($in['username']) ? $this->str_field($in, 'username', $errors, false, 40, '/^[A-Za-z0-9._-]{4,40}$/', '4-40 characters: letters, digits, . _ -') : NULL;
		$password = isset($in['password']) ? $this->str_field($in, 'password', $errors, false, 64, '/^[A-Za-z0-9._~!@#%^+=,:-]{12,64}$/', '12-64 characters: letters, digits and ._~!@#%^+=,:-') : NULL;
		$values = $this->sip_fields($in, $errors);
		$this->fail_on($errors);
		if ($username === NULL) {
			$username = (string) $this->common->find_uniq_rendno_customer(10, 'username', 'sip_devices');
		} elseif ($this->db->get_where('sip_devices', array('username' => $username))->num_rows() > 0) {
			throw new Api_error(409, 'conflict', 'A SIP device with this username already exists.');
		}
		$password = $password === NULL ? $this->random_password(24) : $password;
		$now = gmdate('Y-m-d H:i:s');
		$profile = $this->db->get_where('sip_profiles', array('name' => 'default'))->row_array();
		$this->db->insert('sip_devices', array(
			'username' => $username,
			'sip_profile_id' => empty($profile) ? 1 : (int) $profile['id'],
			'reseller_id' => (int) $c['reseller_id'],
			'accountid' => (int) $c['id'],
			'dir_params' => json_encode(array(
				'password' => $password,
				'vm-enabled' => 'false',
				'vm-password' => (string) random_int(100000, 999999),
				'vm-mailto' => '',
				'vm-attach-file' => 'true',
				'vm-keep-local-after-email' => 'true',
				'vm-email-all-messages' => 'true'
			)),
			'dir_vars' => json_encode(array(
				'effective_caller_id_name' => isset($values['caller_id_name']) ? $values['caller_id_name'] : '',
				'effective_caller_id_number' => isset($values['caller_id_number']) ? $values['caller_id_number'] : '',
				'user_context' => 'default'
			)),
			'codec' => isset($values['codec']) ? $values['codec'] : '',
			'status' => isset($values['status']) ? $values['status'] : 0,
			'creation_date' => $now,
			'last_modified_date' => $now,
			'call_waiting' => 0
		));
		$device_id = (int) $this->db->insert_id();
		$this->log_target = 'customer ' . $c['id'] . ' sip device ' . $device_id;
		$out = $this->sip_out($this->db->get_where('sip_devices', array('id' => $device_id))->row_array());
		$out['password'] = $password;
		$this->send(201, array('data' => $out));
	}

	private function sip_device_update($id) {
		$device = $this->load_sip_device($id);
		$in = $this->body(array('caller_id_name', 'caller_id_number', 'codec', 'status'));
		$errors = array();
		$values = $this->sip_fields($in, $errors);
		$this->fail_on($errors);
		$vars = json_decode((string) $device['dir_vars'], true) ?: array();
		$update = array('last_modified_date' => gmdate('Y-m-d H:i:s'));
		foreach (array('caller_id_name' => 'effective_caller_id_name', 'caller_id_number' => 'effective_caller_id_number') as $field => $var) {
			if (array_key_exists($field, $values)) {
				$vars[$var] = $values[$field];
			}
		}
		$update['dir_vars'] = json_encode($vars);
		if (array_key_exists('codec', $values)) {
			$update['codec'] = $values['codec'];
		}
		if (array_key_exists('status', $values)) {
			$update['status'] = $values['status'];
		}
		$this->db->where('id', (int) $device['id']);
		$this->db->update('sip_devices', $update);
		$this->log_target = 'sip device ' . $device['id'];
		$this->send(200, array('data' => $this->sip_out($this->db->get_where('sip_devices', array('id' => (int) $device['id']))->row_array())));
	}

	private function sip_device_reset_password($id) {
		$device = $this->load_sip_device($id);
		$in = $this->body(array('password'), true);
		$errors = array();
		$password = isset($in['password']) ? $this->str_field($in, 'password', $errors, false, 64, '/^[A-Za-z0-9._~!@#%^+=,:-]{12,64}$/', '12-64 characters: letters, digits and ._~!@#%^+=,:-') : NULL;
		$this->fail_on($errors);
		$password = $password === NULL ? $this->random_password(24) : $password;
		$params = json_decode((string) $device['dir_params'], true) ?: array();
		$params['password'] = $password;
		$this->db->where('id', (int) $device['id']);
		$this->db->update('sip_devices', array('dir_params' => json_encode($params), 'last_modified_date' => gmdate('Y-m-d H:i:s')));
		$this->log_target = 'sip device ' . $device['id'];
		$out = $this->sip_out($this->db->get_where('sip_devices', array('id' => (int) $device['id']))->row_array());
		$out['password'] = $password;
		$this->send(200, array('data' => $out));
	}

	private function sip_device_delete($id) {
		$this->only_query(array());
		$device = $this->load_sip_device($id);
		$this->db->where('id', (int) $device['id']);
		$this->db->delete('sip_devices');
		$this->log_target = 'sip device ' . $device['id'] . ' (' . $device['username'] . ')';
		$this->send(204, NULL);
	}

	// ------------------------------------------------------------------ scope helpers

	/** Customer account (type 0) visible for the key, or 404. */
	private function load_customer($id) {
		$where = array('id' => (int) $id, 'type' => 0, 'deleted' => 0);
		if ($this->role === 'reseller') {
			$where['reseller_id'] = $this->account_id;
		} elseif ($this->role === 'customer' && (int) $id !== $this->account_id) {
			throw new Api_error(404, 'not_found', 'Customer not found.');
		}
		$row = $this->db->get_where('accounts', $where)->row_array();
		if (empty($row)) {
			throw new Api_error(404, 'not_found', 'Customer not found.');
		}
		return $row;
	}

	private function load_sip_device($id) {
		$device = $this->db->get_where('sip_devices', array('id' => (int) $id))->row_array();
		if (empty($device)) {
			throw new Api_error(404, 'not_found', 'SIP device not found.');
		}
		try {
			$this->load_customer((int) $device['accountid']);
		} catch (Api_error $e) {
			throw new Api_error(404, 'not_found', 'SIP device not found.');
		}
		return $device;
	}

	/** Active (not terminated) packages of an account with their usage in the current period. */
	private function active_packages($accountid, $with_seconds = true) {
		$this->db->select('OI.id, OI.order_id, OI.product_id, OI.price, OI.setup_fee, OI.free_minutes, OI.billing_days, OI.billing_type, OI.billing_date, OI.next_billing_date, P.name');
		$this->db->from('order_items AS OI');
		$this->db->join('products AS P', 'P.id = OI.product_id', 'inner');
		$this->db->where(array('OI.accountid' => $accountid, 'OI.is_terminated' => 0, 'OI.product_category' => 1));
		$this->db->order_by('OI.id', 'ASC');
		$items = $this->db->get()->result_array();
		$out = array();
		foreach ($items as $it) {
			$counter = $this->db->get_where('counters', array('accountid' => $accountid, 'package_id' => (int) $it['order_id'], 'status' => 1))->row_array();
			$used = empty($counter) ? 0 : (int) $counter['used_seconds'];
			$free = (int) $it['free_minutes'];
			$row = array(
				'id' => (int) $it['id'],
				'product_id' => (int) $it['product_id'],
				'name' => $it['name'],
				'price' => $this->money($it['price']),
				'setup_fee' => $this->money($it['setup_fee']),
				'recurring' => (string) $it['billing_type'] === '1',
				'billing_days' => (int) $it['billing_days'],
				'period_start' => $this->iso($it['billing_date']),
				'next_billing' => $this->iso($it['next_billing_date']),
				'free_minutes' => $free,
				'used_minutes' => round($used / 60, 2),
				'remaining_minutes' => round(max(0, $free * 60 - $used) / 60, 2)
			);
			if ($with_seconds) {
				$row['used_seconds'] = $used;
			}
			$out[] = $row;
		}
		return $out;
	}

	// ------------------------------------------------------------------ output mapping

	private function customer_out($row) {
		return array(
			'id' => (int) $row['id'],
			'number' => $row['number'],
			'reseller_id' => $this->nullable_id($row['reseller_id']),
			'status' => $this->status_name($row['status']),
			'company_name' => $row['company_name'],
			'first_name' => $row['first_name'],
			'last_name' => $row['last_name'],
			'email' => $row['email'],
			'phone' => $row['telephone_1'],
			'address' => array(
				'line1' => $row['address_1'],
				'line2' => $row['address_2'],
				'postal_code' => $row['postal_code'],
				'city' => $row['city'],
				'province' => $row['province'],
				'country' => $this->lookup_code('countrycode', 'iso', $row['country_id'])
			),
			'currency' => $this->lookup_code('currency', 'currency', $row['currency_id']),
			'timezone' => $this->lookup_code('timezone', 'timezone_name', $row['timezone_id']),
			'billing_type' => (string) $row['posttoexternal'] === '1' ? 'postpaid' : 'prepaid',
			'credit_limit' => $this->money($row['credit_limit']),
			'max_channels' => (int) $row['maxchannels'],
			'rate_group_id' => $this->nullable_id($row['pricelist_id']),
			'localization_id' => $this->nullable_id($row['localization_id']),
			'created_at' => $this->iso($row['creation']),
			'expires_at' => $this->iso($row['expiry'])
		);
	}

	private function sip_out($row) {
		$vars = json_decode((string) $row['dir_vars'], true) ?: array();
		return array(
			'id' => (int) $row['id'],
			'customer_id' => (int) $row['accountid'],
			'username' => $row['username'],
			'status' => $this->status_name($row['status']),
			'caller_id_name' => isset($vars['effective_caller_id_name']) ? (string) $vars['effective_caller_id_name'] : '',
			'caller_id_number' => isset($vars['effective_caller_id_number']) ? (string) $vars['effective_caller_id_number'] : '',
			'codec' => (string) $row['codec'],
			'created_at' => $this->iso($row['creation_date']),
			'updated_at' => $this->iso($row['last_modified_date'])
		);
	}

	// ------------------------------------------------------------------ input validation

	/** Customer fields shared by create and update; returns accounts columns. */
	private function customer_fields($in, $create, $owner, $customer = NULL) {
		$errors = array();
		$f = array();
		foreach (array('company_name' => 40, 'first_name' => 40, 'last_name' => 40) as $name => $max) {
			if (array_key_exists($name, $in)) {
				$f[$name] = $this->str_field($in, $name, $errors, false, $max);
			}
		}
		if ($create && trim((isset($f['company_name']) ? $f['company_name'] : '') . (isset($f['first_name']) ? $f['first_name'] : '')) === '') {
			$errors['company_name'] = 'company_name or first_name is required.';
		}
		if (array_key_exists('email', $in)) {
			$email = $this->str_field($in, 'email', $errors, false, 80);
			if ($email !== '' && $email !== NULL && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$errors['email'] = 'Invalid e-mail address.';
			}
			$f['email'] = (string) $email;
		}
		if (array_key_exists('phone', $in)) {
			$f['telephone_1'] = $this->str_field($in, 'phone', $errors, false, 20, '/^\+?[0-9 ()\/-]{0,20}$/', 'digits, spaces and + ( ) / -');
		}
		if (array_key_exists('address', $in)) {
			if (! is_array($in['address']) || array_keys($in['address']) === range(0, count($in['address']) - 1) && count($in['address']) > 0) {
				$errors['address'] = 'Must be an object.';
			} else {
				$unknown = array_diff(array_keys($in['address']), array('line1', 'line2', 'postal_code', 'city', 'province', 'country'));
				if (! empty($unknown)) {
					$errors['address'] = 'Unknown fields: ' . implode(', ', $unknown);
				}
				$map = array('line1' => array('address_1', 80), 'line2' => array('address_2', 80), 'postal_code' => array('postal_code', 12), 'city' => array('city', 20), 'province' => array('province', 20));
				foreach ($map as $key => $def) {
					if (array_key_exists($key, $in['address'])) {
						$f[$def[0]] = $this->str_field($in['address'], $key, $errors, false, $def[1], NULL, NULL, 'address.' . $key);
					}
				}
				if (array_key_exists('country', $in['address'])) {
					$in['country'] = $in['address']['country'];
				}
			}
		}
		$cfg = Common_model::$global_config['system_config'];
		if (array_key_exists('country', $in) || $create) {
			$iso = array_key_exists('country', $in) ? strtoupper((string) $in['country']) : '';
			$id = $iso === '' ? (int) $cfg['country'] : $this->lookup_id('countrycode', 'iso', $iso);
			if ($id === NULL) {
				$errors['country'] = 'Unknown ISO country code.';
			} else {
				$f['country_id'] = $id;
			}
		}
		if ($create) {
			$code = isset($in['currency']) ? strtoupper((string) $in['currency']) : (string) $this->lookup_code('currency', 'currency', $this->key['acc_currency_id']);
			$id = $this->lookup_id('currency', 'currency', $code);
			if ($id === NULL) {
				$errors['currency'] = 'Unknown currency code.';
			} else {
				$f['currency_id'] = $id;
			}
		}
		if (array_key_exists('timezone', $in) || $create) {
			$name = array_key_exists('timezone', $in) ? (string) $in['timezone'] : '';
			$id = $name === '' ? (int) $this->key['acc_timezone_id'] : $this->lookup_id('timezone', 'timezone_name', $name);
			if ($id === NULL || $id <= 0) {
				$errors['timezone'] = 'Unknown time zone (use names like Europe/Zurich).';
			} else {
				$f['timezone_id'] = $id;
			}
		}
		if (array_key_exists('billing_type', $in) || $create) {
			$type = array_key_exists('billing_type', $in) ? $in['billing_type'] : 'prepaid';
			if ($type !== 'prepaid' && $type !== 'postpaid') {
				$errors['billing_type'] = 'Must be prepaid or postpaid.';
			} else {
				$f['posttoexternal'] = $type === 'postpaid' ? 1 : 0;
			}
		}
		if (array_key_exists('credit_limit', $in)) {
			$f['credit_limit'] = $this->decimal_field($in, 'credit_limit', $errors, 0, 1000000);
		}
		if (array_key_exists('max_channels', $in)) {
			$f['maxchannels'] = $this->int_field($in, 'max_channels', $errors, false, 0, 1000);
		}
		if (array_key_exists('rate_group_id', $in) || $create) {
			$rg = $this->int_field($in, 'rate_group_id', $errors, $create, 1);
			if ($rg !== NULL) {
				$group = $this->db->get_where('pricelists', array('id' => $rg, 'reseller_id' => $owner, 'status' => 0))->row_array();
				if (empty($group)) {
					$errors['rate_group_id'] = 'No active rate group with this id for this ' . ($owner > 0 ? 'reseller' : 'account') . '.';
				} else {
					$f['pricelist_id'] = $rg;
				}
			}
		}
		if (array_key_exists('localization_id', $in)) {
			if ($in['localization_id'] === NULL || (string) $in['localization_id'] === '0') {
				$f['localization_id'] = 0;
			} else {
				$loc = $this->int_field($in, 'localization_id', $errors, false, 1);
				if ($loc !== NULL) {
					$row = $this->db->get_where('localization', array('id' => $loc, 'status' => 0))->row_array();
					if (empty($row) || ((int) $row['reseller_id'] !== $owner && (int) $row['reseller_id'] !== 0)) {
						$errors['localization_id'] = 'No active localization with this id for this customer.';
					} else {
						$f['localization_id'] = $loc;
					}
				}
			}
		}
		// audit N13: portal password chosen by the client, same rule as the portal
		// (libraries/astpp/password_policy.php); stored as hash only, never returned
		if (array_key_exists('portal_password', $in)) {
			if (! is_string($in['portal_password'])) {
				$errors['portal_password'] = 'Must be a string.';
			} else {
				$personal = array(isset($f['email']) ? $f['email'] : '');
				if ($customer !== NULL) {
					$personal[] = $customer['number'];
					$personal[] = $customer['email'];
				}
				$this->load->library('astpp/password_policy');
				$rule = $this->password_policy->check($in['portal_password'], $personal);
				if ($rule !== '') {
					$errors['portal_password'] = $this->password_policy->field_message($rule);
				} else {
					$f['password'] = $this->common->password_store($in['portal_password']);
				}
			}
		}
		$this->fail_on($errors);
		if (isset($f['posttoexternal']) && $f['posttoexternal'] === 0 && ! array_key_exists('credit_limit', $in)) {
			$f['credit_limit'] = 0;
		}
		return $f;
	}

	/** SIP device fields shared by create and update. */
	private function sip_fields($in, &$errors) {
		$v = array();
		if (array_key_exists('caller_id_name', $in)) {
			$v['caller_id_name'] = $this->str_field($in, 'caller_id_name', $errors, false, 80, '/^[^\x00-\x1f"<>\\\\]*$/u', 'no control characters, quotes or <>');
		}
		if (array_key_exists('caller_id_number', $in)) {
			$v['caller_id_number'] = $this->str_field($in, 'caller_id_number', $errors, false, 20, '/^\+?[0-9]*$/', 'digits, optionally with a leading +');
		}
		if (array_key_exists('codec', $in)) {
			$codec = strtoupper(str_replace(' ', '', (string) $in['codec']));
			if (! preg_match('/^(?:[A-Z0-9.]{2,16}(?:,[A-Z0-9.]{2,16})*)?$/', $codec) || strlen($codec) > 100) {
				$errors['codec'] = 'Comma separated codec names, e.g. PCMA,PCMU.';
			}
			$v['codec'] = $codec;
		}
		if (array_key_exists('status', $in)) {
			if ($in['status'] !== 'active' && $in['status'] !== 'inactive') {
				$errors['status'] = 'Must be active or inactive.';
			} else {
				$v['status'] = $in['status'] === 'active' ? 0 : 1;
			}
		}
		return $v;
	}

	/** Parses the JSON body; rejects unknown fields. */
	private function body($allowed, $optional = false) {
		$this->only_query(array());
		$raw = (string) file_get_contents('php://input', false, NULL, 0, self::MAX_BODY + 1);
		if (strlen($raw) > self::MAX_BODY) {
			throw new Api_error(413, 'payload_too_large', 'The request body is too large.');
		}
		if (trim($raw) === '') {
			if ($optional) {
				return array();
			}
			throw new Api_error(400, 'invalid_body', 'A JSON object is required as request body.');
		}
		$type = strtolower(trim(explode(';', (string) $this->input->server('CONTENT_TYPE'))[0]));
		if ($type !== 'application/json') {
			throw new Api_error(415, 'unsupported_media_type', 'Send the request body as application/json.');
		}
		$data = json_decode($raw, true, 16);
		if (! is_array($data) || ($data !== array() && array_keys($data) === range(0, count($data) - 1))) {
			throw new Api_error(400, 'invalid_body', 'The request body must be a JSON object.');
		}
		$unknown = array_diff(array_keys($data), $allowed);
		if (! empty($unknown)) {
			throw new Api_error(400, 'unknown_fields', 'Unknown fields: ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed) . '.');
		}
		return $data;
	}

	private function only_query($allowed) {
		$unknown = array_diff(array_keys($this->query), $allowed);
		if (! empty($unknown)) {
			throw new Api_error(400, 'unknown_parameters', 'Unknown query parameters: ' . implode(', ', $unknown) . '.');
		}
	}

	private function str_field($in, $name, &$errors, $required, $max, $pattern = NULL, $hint = NULL, $label = NULL) {
		$label = $label === NULL ? $name : $label;
		if (! array_key_exists($name, $in) || $in[$name] === NULL) {
			if ($required) {
				$errors[$label] = 'Required.';
			}
			return $required ? NULL : '';
		}
		if (! is_string($in[$name]) && ! is_int($in[$name])) {
			$errors[$label] = 'Must be a string.';
			return NULL;
		}
		$value = trim((string) $in[$name]);
		if (mb_strlen($value, 'UTF-8') > $max) {
			$errors[$label] = 'At most ' . $max . ' characters.';
		} elseif ($pattern !== NULL && ! preg_match($pattern, $value)) {
			$errors[$label] = 'Invalid format' . ($hint ? ' (' . $hint . ')' : '') . '.';
		} elseif (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
			$errors[$label] = 'Control characters are not allowed.';
		}
		return $value;
	}

	private function int_field($in, $name, &$errors, $required, $min = NULL, $max = NULL) {
		if (! array_key_exists($name, $in) || $in[$name] === NULL) {
			if ($required) {
				$errors[$name] = 'Required.';
			}
			return NULL;
		}
		$v = $in[$name];
		if (! (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,10}$/', $v)))) {
			$errors[$name] = 'Must be an integer.';
			return NULL;
		}
		$v = (int) $v;
		if (($min !== NULL && $v < $min) || ($max !== NULL && $v > $max)) {
			$errors[$name] = 'Must be between ' . ($min === NULL ? '-' : $min) . ' and ' . ($max === NULL ? '-' : $max) . '.';
			return NULL;
		}
		return $v;
	}

	private function decimal_field($in, $name, &$errors, $min, $max) {
		$v = $in[$name];
		if (! (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{1,9}(\.\d{1,5})?$/', $v)))) {
			$errors[$name] = 'Must be a number.';
			return NULL;
		}
		$v = round((float) $v, 5);
		if ($v < $min || $v > $max) {
			$errors[$name] = 'Must be between ' . $min . ' and ' . $max . '.';
			return NULL;
		}
		return $v;
	}

	private function fail_on($errors) {
		if (! empty($errors)) {
			throw new Api_error(422, 'validation_failed', 'Invalid input.', $errors);
		}
	}

	private function paging($default = 50, $max = 500) {
		$limit = $this->query_int('limit', $default);
		$offset = $this->query_int('offset', 0);
		if ($limit < 1 || $limit > $max) {
			throw new Api_error(400, 'invalid_parameter', 'limit must be between 1 and ' . $max . '.');
		}
		if ($offset < 0) {
			throw new Api_error(400, 'invalid_parameter', 'offset must not be negative.');
		}
		return array($limit, $offset);
	}

	private function query_int($name, $default) {
		if (! isset($this->query[$name]) || $this->query[$name] === '') {
			return $default;
		}
		if (! is_string($this->query[$name]) || ! preg_match('/^-?\d{1,10}$/', $this->query[$name])) {
			throw new Api_error(400, 'invalid_parameter', $name . ' must be an integer.');
		}
		return (int) $this->query[$name];
	}

	/** ISO 8601 date or date-time (UTC) -> 'Y-m-d H:i:s' */
	private function query_datetime($name, $default) {
		if (! isset($this->query[$name]) || $this->query[$name] === '') {
			return $default;
		}
		$v = is_string($this->query[$name]) ? $this->query[$name] : '';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
			$v .= $name === 'to' ? 'T23:59:59Z' : 'T00:00:00Z';
		}
		$ts = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})$/', $v) ? strtotime($v) : false;
		if ($ts === false) {
			throw new Api_error(400, 'invalid_parameter', $name . ' must be a date (2026-10-31) or date-time (2026-10-31T12:00:00Z).');
		}
		return gmdate('Y-m-d H:i:s', $ts);
	}

	// ------------------------------------------------------------------ small helpers

	private function lookup_id($table, $column, $value) {
		$row = $this->db->select('id')->get_where($table, array($column => $value))->row_array();
		return empty($row) ? NULL : (int) $row['id'];
	}

	private function lookup_code($table, $column, $id) {
		if ((int) $id <= 0) {
			return NULL;
		}
		$row = $this->db->select($column)->get_where($table, array('id' => (int) $id))->row_array();
		return empty($row) ? NULL : $row[$column];
	}

	private function random_password($length) {
		$out = '';
		for ($i = 0; $i < $length; $i++) {
			$out .= self::SIP_PASSWORD_CHARS[random_int(0, strlen(self::SIP_PASSWORD_CHARS) - 1)];
		}
		return $out;
	}

	private function money($value) {
		return round((float) $value, 4);
	}

	private function nullable_id($value) {
		return (int) $value > 0 ? (int) $value : NULL;
	}

	private function status_name($status) {
		return (string) $status === '0' ? 'active' : 'inactive';
	}

	private function iso($datetime) {
		if (empty($datetime) || strpos($datetime, '0000-00-00') === 0 || strpos($datetime, '1000-01-01') === 0) {
			return NULL;
		}
		$ts = strtotime($datetime . ' UTC');
		return $ts === false ? NULL : gmdate('Y-m-d\TH:i:s\Z', $ts);
	}

	// ------------------------------------------------------------------ response

	private function send($status, $payload) {
		$this->write_log($status);
		if ($this->new_session && isset($this->session)) {
			// no session for API clients: remove the one CodeIgniter created for this request
			$this->session->sess_destroy();
		}
		http_response_code($status);
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		if ($payload !== NULL) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		}
		exit();
	}

	/** One line per request in <log_path>/api.log. Never contains keys, passwords or bodies. */
	private function write_log($status) {
		$cfg = isset(Common_model::$global_config['system_config']['log_path']) ? Common_model::$global_config['system_config']['log_path'] : '/var/log/astpp/';
		$line = json_encode(array(
			'time' => gmdate('Y-m-d\TH:i:s\Z'),
			'ip' => (string) $this->input->server('REMOTE_ADDR'),
			'key' => $this->key ? $this->key['key_id'] : NULL,
			'account' => $this->account_id ?: NULL,
			'role' => $this->role ?: NULL,
			'method' => $this->request_method,
			'path' => '/api/v1/' . $this->path,
			'status' => $status,
			'target' => $this->log_target ?: NULL,
			'ms' => (int) round((microtime(true) - $this->started) * 1000)
		), JSON_UNESCAPED_SLASHES);
		@file_put_contents(rtrim($cfg, '/') . '/api.log', $line . "\n", FILE_APPEND | LOCK_EX);
	}
}
