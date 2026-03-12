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
		}
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
