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
 * ASTPP fork: portal page "API Access" - API keys for the provisioning API (/api/v1).
 * Admins, resellers and customers manage the keys of their own account; admins also see,
 * disable and delete the keys of all accounts. Every key needs at least one allowed source
 * address. The full key is shown once, in the response to the create request (not stored).
 * State-changing requests are POST only and carry a per-session CSRF token.
 */
#[\AllowDynamicProperties]
class Apikeys extends MX_Controller {
	const CSRF = 'apikeys_csrf';

	function __construct() {
		parent::__construct();
		$this->load->helper('template_inheritance');
		$this->load->library('session');
		$this->load->library('astpp/api_keys');
		if ($this->session->userdata('user_login') == FALSE) {
			redirect(base_url() . 'login/login');
		}
		$this->accountinfo = $this->session->userdata('accountinfo');
		if (! is_array($this->accountinfo) || ! in_array((int) $this->accountinfo['type'], Api_keys::ACCOUNT_TYPES, true)) {
			$this->session->set_flashdata('astpp_errormsg', gettext('Permission Denied!'));
			redirect(base_url() . 'dashboard/');
		}
		$this->is_admin = (int) $this->accountinfo['type'] === -1;
	}

	function index() {
		redirect(base_url() . 'apikeys/apikeys_list/');
	}

	function apikeys_list() {
		$this->render_list();
	}

	function apikeys_add() {
		$this->render_form(array('name' => '', 'allowed_ips' => $this->input->ip_address(), 'status' => 0), '');
	}

	function apikeys_save() {
		$this->require_post();
		$name = trim((string) $this->input->post('name', TRUE));
		list($allowed, $error) = $this->api_keys->normalize_allowed_ips($this->input->post('allowed_ips', TRUE));
		if ($error === '' && ($name === '' || mb_strlen($name, 'UTF-8') > 100)) {
			$error = gettext('Please enter a name with at most 100 characters.');
		}
		if ($error === '' && $this->db->where('accountid', (int) $this->accountinfo['id'])->count_all_results(Api_keys::TABLE) >= Api_keys::MAX_KEYS_PER_ACCOUNT) {
			$error = sprintf(gettext('An account can have at most %d API keys.'), Api_keys::MAX_KEYS_PER_ACCOUNT);
		}
		if ($error !== '') {
			$this->render_form(array('name' => $name, 'allowed_ips' => (string) $this->input->post('allowed_ips', TRUE), 'status' => 0), '', $error);
			return;
		}
		list($key, $id) = $this->api_keys->create((int) $this->accountinfo['id'], $name, $allowed, (int) $this->accountinfo['id']);
		$this->rotate_csrf();
		// the page contains the full key: keep it out of browser and proxy caches
		header('Cache-Control: no-store');
		$this->render_list($key);
	}

	function apikeys_edit($id = '') {
		$row = $this->load_key($id);
		$row['allowed_ips'] = str_replace(',', "\n", $row['allowed_ips']);
		$this->render_form($row, (int) $row['id']);
	}

	function apikeys_update($id = '') {
		$this->require_post();
		$row = $this->load_key($id);
		$name = trim((string) $this->input->post('name', TRUE));
		$status = (string) $this->input->post('status', TRUE) === '1' ? 1 : 0;
		list($allowed, $error) = $this->api_keys->normalize_allowed_ips($this->input->post('allowed_ips', TRUE));
		if ($error === '' && ($name === '' || mb_strlen($name, 'UTF-8') > 100)) {
			$error = gettext('Please enter a name with at most 100 characters.');
		}
		if ($error !== '') {
			$this->render_form(array('id' => $row['id'], 'key_id' => $row['key_id'], 'name' => $name, 'allowed_ips' => (string) $this->input->post('allowed_ips', TRUE), 'status' => $status), (int) $row['id'], $error);
			return;
		}
		$this->db->where('id', (int) $row['id']);
		$this->db->update(Api_keys::TABLE, array('name' => $name, 'allowed_ips' => $allowed, 'status' => $status));
		$this->rotate_csrf();
		$this->session->set_flashdata('astpp_notification', gettext('API key updated.'));
		redirect(base_url() . 'apikeys/apikeys_list/');
	}

	function apikeys_delete($id = '') {
		$this->require_post();
		$row = $this->load_key($id);
		$this->db->where('id', (int) $row['id']);
		$this->db->delete(Api_keys::TABLE);
		$this->rotate_csrf();
		$this->session->set_flashdata('astpp_notification', gettext('API key deleted. Requests with this key are rejected from now on.'));
		redirect(base_url() . 'apikeys/apikeys_list/');
	}

	/** Documentation (modules/apikeys/docs/api.md, rendered with Parsedown). */
	function apikeys_docs() {
		require_once APPPATH . 'third_party/parsedown/Parsedown.php';
		$markdown = (string) file_get_contents(APPPATH . 'modules/apikeys/docs/api.md');
		$markdown = str_replace(array('{{API_BASE}}', '{{OPENAPI_URL}}'), array(base_url() . 'api/v1/', base_url() . 'apikeys/apikeys_openapi/'), $markdown);
		$parser = new Parsedown();
		$data['page_title'] = gettext('API Documentation');
		$data['docs_html'] = $parser->text($markdown);
		$this->load->view('apikeys_docs', $data);
	}

	/** OpenAPI description (modules/apikeys/docs/openapi.yaml) as download. */
	function apikeys_openapi() {
		$yaml = (string) file_get_contents(APPPATH . 'modules/apikeys/docs/openapi.yaml');
		$yaml = str_replace('{{API_BASE}}', rtrim(base_url(), '/') . '/api/v1', $yaml);
		header('Content-Type: application/yaml; charset=utf-8');
		header('Content-Disposition: attachment; filename="astpp-api-openapi.yaml"');
		header('X-Content-Type-Options: nosniff');
		echo $yaml;
		exit();
	}

	// ------------------------------------------------------------------ helpers

	private function render_list($new_key = '') {
		$this->db->select('K.id, K.accountid, K.name, K.key_id, K.allowed_ips, K.status, K.created_date, K.last_used_date, K.last_used_ip, A.number, A.company_name, A.first_name, A.last_name, A.type');
		$this->db->from(Api_keys::TABLE . ' AS K');
		$this->db->join('accounts AS A', 'A.id = K.accountid', 'left');
		if (! $this->is_admin) {
			$this->db->where('K.accountid', (int) $this->accountinfo['id']);
		}
		$this->db->order_by('K.id', 'ASC');
		$data['keys'] = $this->db->get()->result_array();
		$data['page_title'] = gettext('API Access');
		$data['is_admin'] = $this->is_admin;
		$data['own_id'] = (int) $this->accountinfo['id'];
		$data['new_key'] = $new_key;
		$data['api_base'] = base_url() . 'api/v1/';
		$data['client_ip'] = $this->input->ip_address();
		$data['csrf'] = $this->csrf_token();
		$this->load->view('apikeys_list', $data);
	}

	private function render_form($row, $id, $error = '') {
		$data['page_title'] = $id === '' ? gettext('New API Key') : gettext('Edit API Key');
		$data['row'] = $row;
		$data['id'] = $id;
		$data['error'] = $error;
		$data['client_ip'] = $this->input->ip_address();
		$data['csrf'] = $this->csrf_token();
		$this->load->view('apikeys_form', $data);
	}

	/** Key visible for the logged-in account (admins: all keys), else back to the list. */
	private function load_key($id) {
		$where = array('id' => (int) $id);
		if (! $this->is_admin) {
			$where['accountid'] = (int) $this->accountinfo['id'];
		}
		$row = $this->db->get_where(Api_keys::TABLE, $where)->row_array();
		if (empty($row)) {
			$this->session->set_flashdata('astpp_errormsg', gettext('Permission Denied!'));
			redirect(base_url() . 'apikeys/apikeys_list/');
		}
		return $row;
	}

	private function require_post() {
		$token = (string) $this->input->post('csrf_token');
		$expected = (string) $this->session->userdata(self::CSRF);
		if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST' || $expected === '' || ! hash_equals($expected, $token)) {
			$this->session->set_flashdata('astpp_errormsg', gettext('The form has expired. Please try again.'));
			redirect(base_url() . 'apikeys/apikeys_list/');
		}
	}

	private function csrf_token() {
		$token = (string) $this->session->userdata(self::CSRF);
		if ($token === '') {
			$token = bin2hex(random_bytes(16));
			$this->session->set_userdata(self::CSRF, $token);
		}
		return $token;
	}

	private function rotate_csrf() {
		$this->session->set_userdata(self::CSRF, bin2hex(random_bytes(16)));
	}
}
