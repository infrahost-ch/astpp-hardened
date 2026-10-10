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
 * ASTPP fork: API keys of the provisioning API (/api/v1, controllers/api.php), managed in the
 * portal (modules/apikeys).
 *
 * Key format:  astpp_<key id: 16 hex>_<secret: 43 chars base64url = 32 random bytes>
 * Stored:      key_id in clear text (identifies the key in the portal and in logs) and
 *              SHA-256 of the secret. The full key is shown once, when it is created.
 * Access:      every key has a list of allowed source addresses (IPv4/IPv6, optionally with
 *              prefix length). A key without allowed addresses cannot be used.
 */
if (! defined('BASEPATH'))
	exit('No direct script access allowed');

#[\AllowDynamicProperties]
class Api_keys {
	const TABLE = 'api_keys';
	const MAX_KEYS_PER_ACCOUNT = 20;
	const MAX_ALLOWED_ENTRIES = 50;
	const MIN_PREFIX_V4 = 8;
	const MIN_PREFIX_V6 = 32;
	// account types that may own API keys: -1 admin, 1 reseller, 0 customer
	const ACCOUNT_TYPES = array(-1, 1, 0);

	function __construct() {
		$this->CI = & get_instance();
	}

	/**
	 * Creates a key for an account. Returns array(full key, row id).
	 * $allowed_ips must already be normalized (normalize_allowed_ips()).
	 */
	function create($accountid, $name, $allowed_ips, $created_by) {
		$key_id = bin2hex(random_bytes(8));
		$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$this->CI->db->insert(self::TABLE, array(
			'accountid' => (int) $accountid,
			'name' => $name,
			'key_id' => $key_id,
			'secret_hash' => hash('sha256', $secret),
			'allowed_ips' => $allowed_ips,
			'status' => 0,
			'created_by' => (int) $created_by,
			'created_date' => gmdate('Y-m-d H:i:s'),
			'last_used_ip' => ''
		));
		return array('astpp_' . $key_id . '_' . $secret, (int) $this->CI->db->insert_id());
	}

	/**
	 * Looks up the key presented by a client. Returns the api_keys row joined with the owner
	 * account (prefixed acc_) or NULL. Does not check the source address.
	 */
	function authenticate($presented) {
		if (! is_string($presented) || ! preg_match('/^astpp_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', $presented, $m)) {
			return NULL;
		}
		$this->CI->db->select('K.*, A.type AS acc_type, A.number AS acc_number, A.reseller_id AS acc_reseller_id, A.company_name AS acc_company_name, A.currency_id AS acc_currency_id, A.timezone_id AS acc_timezone_id, A.country_id AS acc_country_id');
		$this->CI->db->from(self::TABLE . ' AS K');
		$this->CI->db->join('accounts AS A', 'A.id = K.accountid', 'inner');
		$this->CI->db->where(array('K.key_id' => $m[1], 'K.status' => 0, 'A.status' => 0, 'A.deleted' => 0));
		$row = $this->CI->db->get()->row_array();
		if (empty($row) || ! hash_equals($row['secret_hash'], hash('sha256', $m[2]))) {
			return NULL;
		}
		if (! in_array((int) $row['acc_type'], self::ACCOUNT_TYPES, true)) {
			return NULL;
		}
		return $row;
	}

	/** Records the last use, at most once per minute to keep writes low. */
	function touch($row, $ip) {
		if (! empty($row['last_used_date']) && $row['last_used_ip'] === $ip && strtotime($row['last_used_date'] . ' UTC') > time() - 60) {
			return;
		}
		$this->CI->db->where('id', (int) $row['id']);
		$this->CI->db->update(self::TABLE, array('last_used_date' => gmdate('Y-m-d H:i:s'), 'last_used_ip' => substr($ip, 0, 45)));
	}

	/**
	 * Validates a free-text list of addresses (separated by commas, spaces or new lines).
	 * Returns array(normalized comma separated list, error message or '').
	 */
	function normalize_allowed_ips($text) {
		$entries = preg_split('/[\s,;]+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
		if (empty($entries)) {
			return array('', 'At least one allowed IP address or network is required.');
		}
		if (count($entries) > self::MAX_ALLOWED_ENTRIES) {
			return array('', 'At most ' . self::MAX_ALLOWED_ENTRIES . ' entries are allowed.');
		}
		$normalized = array();
		foreach ($entries as $entry) {
			$cidr = $this->parse_cidr($entry);
			if ($cidr === NULL) {
				return array('', 'Invalid IP address or network: ' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8'));
			}
			list($bin, $prefix) = $cidr;
			$v6 = strlen($bin) === 16;
			if ($prefix < ($v6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4)) {
				return array('', 'Network too large: ' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8') . ' (minimum prefix length /' . ($v6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4) . ').');
			}
			$text_ip = inet_ntop($this->network_address($bin, $prefix));
			$normalized[] = ($prefix === ($v6 ? 128 : 32)) ? $text_ip : $text_ip . '/' . $prefix;
		}
		return array(implode(',', array_values(array_unique($normalized))), '');
	}

	/** TRUE if $ip lies in one of the entries of a normalized list. */
	function ip_allowed($ip, $allowed_ips) {
		$addr = @inet_pton((string) $ip);
		if ($addr === false) {
			return false;
		}
		// IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are compared as IPv4
		if (strlen($addr) === 16 && substr($addr, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			$addr = substr($addr, 12);
		}
		foreach (explode(',', (string) $allowed_ips) as $entry) {
			$cidr = $this->parse_cidr(trim($entry));
			if ($cidr === NULL || strlen($cidr[0]) !== strlen($addr)) {
				continue;
			}
			if ($this->network_address($addr, $cidr[1]) === $this->network_address($cidr[0], $cidr[1])) {
				return true;
			}
		}
		return false;
	}

	/** "a.b.c.d", "a.b.c.d/nn", "ipv6", "ipv6/nnn" -> array(binary address, prefix) or NULL */
	private function parse_cidr($entry) {
		if (! preg_match('#^([0-9A-Fa-f:.]+)(?:/(\d{1,3}))?$#', $entry, $m)) {
			return NULL;
		}
		$bin = @inet_pton($m[1]);
		if ($bin === false) {
			return NULL;
		}
		$max = strlen($bin) * 8;
		$prefix = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $max;
		if ($prefix > $max) {
			return NULL;
		}
		return array($bin, $prefix);
	}

	private function network_address($bin, $prefix) {
		$out = '';
		for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
			$bits = max(0, min(8, $prefix - $i * 8));
			$mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
			$out .= chr(ord($bin[$i]) & $mask);
		}
		return $out;
	}
}
