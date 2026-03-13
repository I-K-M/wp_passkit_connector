<?php

if (!defined('ABSPATH')) {
	exit;
}

trait Client_Membership_Passkit_Sync_Trait {

	private static function passkit_is_configured(): bool {
		return (
			(string) self::setting('passkit_bearer_token', '') !== '' &&
			(string) self::setting('passkit_base_url', '') !== '' &&
			(string) self::setting('passkit_template_id', '') !== ''
		);
	}

	private static function format_expiry_for_passkit(string $expiry_iso): string {
		if ($expiry_iso === '') {
			return '';
		}

		$timestamp = strtotime($expiry_iso);
		if (!$timestamp) {
			return '';
		}

		return gmdate('Y-m-d\T00:00:00\Z', $timestamp);
	}

	private static function map_status_to_passkit(string $status): string {
		switch ($status) {
			case self::STATUS_ACTIVE:
				return 'ACTIVE';
			case self::STATUS_EXPIRED:
				return 'EXPIRED';
			case self::STATUS_REFUNDED:
				return 'DELETED';
			case self::STATUS_CANCELLED:
			case self::STATUS_SUSPENDED:
			default:
				return 'ENROLLED';
		}
	}

	private static function maybe_update_pass_url(int $user_id, string $pass_id, array $response): void {
		$pass_url = '';

		if (!empty($response['url']) && is_string($response['url'])) {
			$pass_url = esc_url_raw($response['url']);
		} else {
			$public_base = (string) self::setting('passkit_public_base_url', '');
			if ($public_base !== '' && $pass_id !== '') {
				$pass_url = esc_url_raw(rtrim($public_base, '/') . '/' . rawurlencode($pass_id));
			}
		}

		if ($pass_url !== '') {
			update_user_meta($user_id, self::UM_PASS_URL, $pass_url);
			self::send_wallet_email($user_id);
		}
	}

	private static function send_wallet_email(int $user_id): void {
		$user = get_userdata($user_id);
		if (!$user) {
			return;
		}

		$state  = self::get_membership_state($user_id);
		$status = (string) ($state['status'] ?? self::STATUS_INACTIVE);
		if ($status !== self::STATUS_ACTIVE) {
			return;
		}

		$to = (string) $user->user_email;
		if ($to === '' || !is_email($to)) {
			return;
		}

		$pass_url = self::get_wallet_pass_url($user_id);
		if ($pass_url === '') {
			return;
		}

		$already_sent_for = (string) get_user_meta($user_id, self::UM_PASS_EMAIL, true);
		if ($already_sent_for !== '' && hash_equals($already_sent_for, $pass_url)) {
			return;
		}

		$subject = 'Your Hound & Stag Membership';

		$message = sprintf(
			'<h2>Your membership is now active</h2>' .
			'<p>Your digital membership card is ready.</p>' .
			'<p><a href="%1$s" style="display:inline-block;padding:12px 20px;background:#000;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Add your Membership to Wallet</a></p>' .
			'<p>You can present this card when visiting Hound &amp; Stag.</p>',
			esc_url($pass_url)
		);

		$sent = wp_mail(
			$to,
			$subject,
			$message,
			['Content-Type: text/html; charset=UTF-8']
		);

		if ($sent) {
			update_user_meta($user_id, self::UM_PASS_EMAIL, $pass_url);
		}
	}

	private static function get_wallet_pass_url(int $user_id): string {
		$pass_url = (string) get_user_meta($user_id, self::UM_PASS_URL, true);
		if ($pass_url !== '') {
			return esc_url_raw($pass_url);
		}

		$legacy_pass_url = (string) get_user_meta($user_id, 'hs_wallet_pass_url', true);
		if ($legacy_pass_url !== '') {
			return esc_url_raw($legacy_pass_url);
		}

		return '';
	}

	public static function on_woocommerce_thankyou($order_id): void {
		$order_id = absint($order_id);
		if ($order_id <= 0 || !function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order || !method_exists($order, 'get_user_id')) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		$pass_url = self::get_wallet_pass_url($user_id);
		if ($pass_url === '') {
			return;
		}

		wp_safe_redirect(home_url('/membership-card/?u=' . $user_id));
		exit;
	}

	public static function shortcode_hs_membership_card(): string {
		if (!is_user_logged_in()) {
			return '';
		}

		$user_id  = get_current_user_id();
		$pass_url = self::get_wallet_pass_url($user_id);

		if ($pass_url === '') {
			return '<p>Your membership card is not ready yet.</p>';
		}

		return sprintf(
			'<h2>Your Membership Card</h2>' .
			'<p>Add your card to Apple Wallet or Google Wallet.</p>' .
			'<a href="%1$s" style="display:inline-block;padding:14px 22px;background:#000;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Add to Wallet</a>',
			esc_url($pass_url)
		);
	}

	private static function passkit_sync_for_user(int $user_id): void {
		if (!self::passkit_is_configured()) {
			return;
		}

		$state      = self::get_membership_state($user_id);
		$status     = (string) ($state['status'] ?? self::STATUS_INACTIVE);
		$expiry_iso = (string) ($state['expiry'] ?? '');

		$token = (string) get_user_meta($user_id, self::UM_QR_TOKEN, true);
		if ($token === '' && $status === self::STATUS_ACTIVE) {
			$token = self::ensure_qr_token($user_id);
		}

		$user = get_userdata($user_id);
		if (!$user) {
			return;
		}

		$name = trim($user->first_name . ' ' . $user->last_name);
		if ($name === '') {
			$name = (string) $user->display_name;
		}

		$program_id = (string) self::setting('passkit_template_id', '');
		$tier_id    = (string) self::setting('passkit_tier_id', 'base');
		if ($tier_id === '') {
			$tier_id = 'base';
		}

		$expiry_date    = self::format_expiry_for_passkit($expiry_iso);
		$passkit_status = self::map_status_to_passkit($status);
		$validation_url = self::validation_url($token);

		$payload = [
			'externalId' => (string) $user_id,
			'programId'  => $program_id,
			'tierId'     => $tier_id,
			'person'     => [
				'displayName'  => $name,
				'emailAddress' => (string) $user->user_email,
			],
			'metaData'   => [
				'metaQrDataValidation' => $validation_url,
			],
			'expiryDate' => $expiry_date,
			'status'     => $passkit_status,
		];

		$client = new Client_Membership_PassKit_Client(
			(string) self::setting('passkit_base_url', ''),
			(string) self::setting('passkit_bearer_token', '')
		);

		$pass_id = (string) get_user_meta($user_id, self::UM_PASS_ID, true);
		if ($pass_id !== '') {
			$payload['id'] = $pass_id;
			$response      = $client->update_member($payload);
		} else {
			$response = $client->create_member($payload);
		}

		$resolved_pass_id = '';
		if (!empty($response['id']) && is_string($response['id'])) {
			$resolved_pass_id = $response['id'];
		} elseif (!empty($response['passId']) && is_string($response['passId'])) {
			$resolved_pass_id = $response['passId'];
		}

		if ($resolved_pass_id !== '') {
			update_user_meta($user_id, self::UM_PASS_ID, sanitize_text_field($resolved_pass_id));
			self::maybe_update_pass_url($user_id, $resolved_pass_id, $response);
		} elseif ($pass_id !== '') {
			self::maybe_update_pass_url($user_id, $pass_id, $response);
		}
	}
}
