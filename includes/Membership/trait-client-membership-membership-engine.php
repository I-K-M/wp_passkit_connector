<?php

if (!defined('ABSPATH')) {
	exit;
}

trait Client_Membership_Membership_Engine_Trait {

	private static function is_subscriptions_available(): bool {
		return function_exists('wcs_get_subscription');
	}

	private static function is_membership_product_in_subscription($subscription): bool {
		$product_id = absint(self::setting('membership_product_id', 0));
		if (!$product_id || !$subscription) {
			return false;
		}

		foreach ($subscription->get_items() as $item) {
			if ((int) $item->get_product_id() === $product_id) {
				return true;
			}
		}

		return false;
	}

	private static function subscription_expiry_iso($subscription): string {
		$end = $subscription ? $subscription->get_date('end') : '';
		if (!empty($end)) {
			return gmdate('c', strtotime($end));
		}

		$next = $subscription ? $subscription->get_date('next_payment') : '';
		if (!empty($next)) {
			return gmdate('c', strtotime($next));
		}

		return gmdate('c', time() + (365 * DAY_IN_SECONDS));
	}

	private static function set_state(int $user_id, string $state, string $expiry_iso = ''): void {
		update_user_meta($user_id, self::UM_STATUS, $state);

		if ($expiry_iso !== '') {
			update_user_meta($user_id, self::UM_EXPIRY, $expiry_iso);
		}

		if ($state === self::STATUS_ACTIVE) {
			self::ensure_qr_token($user_id);
		}

		self::passkit_sync_for_user($user_id);
	}

	private static function ensure_qr_token(int $user_id): string {
		$existing = (string) get_user_meta($user_id, self::UM_QR_TOKEN, true);
		if ($existing !== '') {
			return $existing;
		}

		try {
			$token = bin2hex(random_bytes(32));
		} catch (\Throwable $e) {
			$token = wp_generate_password(64, false, false);
		}

		update_user_meta($user_id, self::UM_QR_TOKEN, $token);
		update_user_meta($user_id, self::UM_QR_CREATED, time());

		return $token;
	}

	public static function get_membership_state(int $user_id): array {
		$status = (string) get_user_meta($user_id, self::UM_STATUS, true);
		$expiry = (string) get_user_meta($user_id, self::UM_EXPIRY, true);

		if ($status === '') {
			return [
				'status' => self::STATUS_INACTIVE,
				'expiry' => $expiry,
			];
		}

		if ($status === self::STATUS_ACTIVE && $expiry !== '') {
			$expiry_ts = strtotime($expiry);
			if ($expiry_ts && $expiry_ts < time()) {
				$status = self::STATUS_EXPIRED;
			}
		}

		return [
			'status' => $status,
			'expiry' => $expiry,
		];
	}

	public static function on_sub_status_active($subscription_id): void {
		$subscription = self::get_subscription_if_valid($subscription_id);
		if (!$subscription) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_ACTIVE, self::subscription_expiry_iso($subscription));
	}

	public static function on_sub_status_on_hold($subscription_id): void {
		$subscription = self::get_subscription_if_valid($subscription_id);
		if (!$subscription) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_SUSPENDED, self::subscription_expiry_iso($subscription));
	}

	public static function on_sub_status_expired($subscription_id): void {
		$subscription = self::get_subscription_if_valid($subscription_id);
		if (!$subscription) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_EXPIRED, self::subscription_expiry_iso($subscription));
	}

	public static function on_sub_status_cancelled($subscription_id): void {
		$subscription = self::get_subscription_if_valid($subscription_id);
		if (!$subscription) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_CANCELLED, self::subscription_expiry_iso($subscription));
	}

	public static function on_sub_status_pending_cancel($subscription_id): void {
		$subscription = self::get_subscription_if_valid($subscription_id);
		if (!$subscription) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_ACTIVE, self::subscription_expiry_iso($subscription));
	}

	public static function on_renewal_payment_complete($subscription, $renewal_order): void {
		if (!$subscription || !method_exists($subscription, 'get_user_id')) {
			return;
		}

		if (!self::is_membership_product_in_subscription($subscription)) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state($user_id, self::STATUS_ACTIVE, self::subscription_expiry_iso($subscription));
	}

	private static function get_subscription_if_valid($subscription_id) {
		if (!self::is_subscriptions_available()) {
			return null;
		}

		$subscription = wcs_get_subscription($subscription_id);
		if (!$subscription) {
			return null;
		}

		if (!self::is_membership_product_in_subscription($subscription)) {
			return null;
		}

		return $subscription;
	}

	private static function order_contains_membership_product($order): bool {
		$product_id = absint(self::setting('membership_product_id', 0));
		if (!$product_id || !$order) {
			return false;
		}

		foreach ($order->get_items() as $item) {
			if ((int) $item->get_product_id() === $product_id) {
				return true;
			}
		}

		return false;
	}

	public static function on_order_refunded($order_id, $refund_id = 0): void {
		if (!function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order || !self::order_contains_membership_product($order)) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ($user_id <= 0) {
			return;
		}

		self::set_state(
			$user_id,
			self::STATUS_REFUNDED,
			(string) get_user_meta($user_id, self::UM_EXPIRY, true)
		);
	}

	public static function on_order_refunded_status($order_id): void {
		self::on_order_refunded($order_id, 0);
	}
}
