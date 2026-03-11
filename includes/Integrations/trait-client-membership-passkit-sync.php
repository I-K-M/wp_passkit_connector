<?php

if (!defined('ABSPATH')) {
	exit;
}

trait Client_Membership_Passkit_Sync_Trait {

	private static function passkit_is_configured(): bool {
		return (
			(string) self::setting('passkit_api_key', '') !== '' &&
			(string) self::setting('passkit_api_secret', '') !== '' &&
			(string) self::setting('passkit_base_url', '') !== '' &&
			(string) self::setting('passkit_template_id', '') !== ''
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

		$pass_id = (string) get_user_meta($user_id, self::UM_PASS_ID, true);

		$payload = [
			'template_id' => (string) self::setting('passkit_template_id', ''),
			'member'      => [
				'external_id' => (string) $user_id,
				'name'        => $name,
			],
			'membership'  => [
				'status'  => $status,
				'expiry'  => $expiry_iso,
				'qr_data' => self::validation_url($token),
			],
		];

		$client = new Client_Membership_PassKit_Client(
			(string) self::setting('passkit_base_url', ''),
			(string) self::setting('passkit_api_key', ''),
			(string) self::setting('passkit_api_secret', '')
		);

		if ($pass_id === '') {
			$response = $client->create_pass($payload);
			if (!empty($response['pass_id'])) {
				update_user_meta($user_id, self::UM_PASS_ID, sanitize_text_field((string) $response['pass_id']));
			}
		} else {
			$client->update_pass($pass_id, $payload);
		}
	}
}
