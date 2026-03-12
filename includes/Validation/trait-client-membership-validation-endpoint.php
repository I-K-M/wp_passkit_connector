<?php

if (!defined('ABSPATH')) {
	exit;
}

trait Client_Membership_Validation_Endpoint_Trait {

	public static function disable_canonical_redirect_for_validation($redirect_url, $requested_url) {
		if ((int) get_query_var(self::QUERY_VAR_VALIDATE) === 1) {
			return false;
		}

		return $redirect_url;
	}

	public static function maybe_handle_validation(): void {
		if ((int) get_query_var(self::QUERY_VAR_VALIDATE) !== 1) {
			return;
		}

		nocache_headers();

		$token = self::get_request_token();
		if ($token === '' || strlen($token) < 20) {
			self::render_invalid();
		}

		self::enforce_rate_limits($token);

		$user_id = self::get_user_id_by_token($token);
		if (!$user_id) {
			self::render_invalid();
		}

		$state  = self::get_membership_state((int) $user_id);
		$status = (string) ($state['status'] ?? self::STATUS_INACTIVE);
		$expiry = (string) ($state['expiry'] ?? '');

		$user = get_userdata((int) $user_id);
		$name = '';
		if ($user) {
			$name = trim($user->first_name . ' ' . $user->last_name);
			if ($name === '') {
				$name = (string) $user->display_name;
			}
		}
		if ($name === '') {
			$name = __('Member', self::TEXT_DOMAIN);
		}

		$payload = [
			'active'      => ($status === self::STATUS_ACTIVE),
			'status'      => $status,
			'member_name' => $name,
			'expiry'      => $expiry ? gmdate('Y-m-d', strtotime($expiry)) : null,
		];

		if (self::wants_json()) {
			wp_send_json($payload);
		}

		self::render_html($payload);
	}

	private static function get_request_token(): string {
		$token = '';
		if (isset($_GET[self::QUERY_ARG_TOKEN])) {
			$token = trim((string) wp_unslash($_GET[self::QUERY_ARG_TOKEN]));
		} elseif (isset($_GET[self::LEGACY_QUERY_TOKEN])) {
			// Backward compatibility for links generated before query key hardening.
			$token = trim((string) wp_unslash($_GET[self::LEGACY_QUERY_TOKEN]));
		}

		return $token;
	}

	private static function wants_json(): bool {
		if (empty(self::setting('enable_json', 0))) {
			return false;
		}

		if (isset($_GET['format']) && wp_unslash($_GET['format']) === 'json') {
			return true;
		}

		$accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
		return stripos($accept, 'application/json') !== false;
	}

	private static function get_client_ip(): string {
		return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}

	private static function enforce_rate_limits(string $token): void {
		$ip_limit    = max(1, absint(self::setting('rate_ip_per_5m', 30)));
		$token_limit = max(1, absint(self::setting('rate_token_per_1m', 10)));

		$ip = self::get_client_ip();

		$ip_key = 'client_membership_rl_ip_' . md5($ip);
		$tk_key = 'client_membership_rl_tk_' . md5($token);

		$ip_count = (int) get_transient($ip_key);
		$tk_count = (int) get_transient($tk_key);

		$ip_count++;
		$tk_count++;

		set_transient($ip_key, $ip_count, 5 * MINUTE_IN_SECONDS);
		set_transient($tk_key, $tk_count, 1 * MINUTE_IN_SECONDS);

		if ($ip_count > $ip_limit || $tk_count > $token_limit) {
			self::render_rate_limited();
		}
	}

	private static function get_user_id_by_token(string $token): int {
		$users = get_users([
			'meta_key'   => self::UM_QR_TOKEN,
			'meta_value' => $token,
			'number'     => 1,
			'fields'     => 'ID',
		]);

		if (empty($users)) {
			return 0;
		}

		return (int) $users[0];
	}

	private static function render_invalid(): void {
		if (self::wants_json()) {
			wp_send_json([
				'active' => false,
				'status' => self::STATUS_INVALID,
			], 404);
		}

		status_header(404);
		self::render_html([
			'active'      => false,
			'status'      => self::STATUS_INVALID,
			'member_name' => null,
			'expiry'      => null,
		]);
	}

	private static function render_rate_limited(): void {
		if (self::wants_json()) {
			wp_send_json([
				'active' => false,
				'status' => self::STATUS_RATE_LIMIT,
			], 429);
		}

		status_header(429);
		self::render_html([
			'active'      => false,
			'status'      => self::STATUS_RATE_LIMIT,
			'member_name' => null,
			'expiry'      => null,
		]);
	}
}
