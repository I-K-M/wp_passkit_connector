<?php

if (!defined('ABSPATH')) {
	exit;
}

final class Client_Membership_Plugin {

	use Client_Membership_Admin_Settings_Trait;
	use Client_Membership_Membership_Engine_Trait;
	use Client_Membership_Validation_Endpoint_Trait;
	use Client_Membership_Passkit_Sync_Trait;

	const VERSION     = '1.0.0';
	const TEXT_DOMAIN = 'client-membership';

	const OPTION_GROUP = 'client_membership_options';
	const OPTION_NAME  = 'client_membership_settings';

	const QUERY_VAR_VALIDATE = 'client_membership_validate';

	// User meta keys
	const UM_STATUS     = 'client_membership_status';
	const UM_EXPIRY     = 'client_membership_expiry';
	const UM_PASS_ID    = 'client_membership_wallet_pass_id';
	const UM_QR_TOKEN   = 'client_membership_qr_token';
	const UM_QR_CREATED = 'client_membership_qr_token_created';

	// Status values
	const STATUS_ACTIVE     = 'active';
	const STATUS_SUSPENDED  = 'suspended';
	const STATUS_CANCELLED  = 'cancelled';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_REFUNDED   = 'refunded';
	const STATUS_INACTIVE   = 'inactive';
	const STATUS_INVALID    = 'invalid';
	const STATUS_RATE_LIMIT = 'rate_limited';

	public static function init(): void {
		register_activation_hook(CLIENT_MEMBERSHIP_PLUGIN_FILE, [__CLASS__, 'on_activate']);
		register_deactivation_hook(CLIENT_MEMBERSHIP_PLUGIN_FILE, [__CLASS__, 'on_deactivate']);

		add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);
		add_action('init', [__CLASS__, 'register_rewrite']);
		add_filter('query_vars', [__CLASS__, 'register_query_vars']);
		add_action('template_redirect', [__CLASS__, 'maybe_handle_validation']);

		add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);

		add_action('woocommerce_subscription_status_active', [__CLASS__, 'on_sub_status_active'], 10, 1);
		add_action('woocommerce_subscription_status_on-hold', [__CLASS__, 'on_sub_status_on_hold'], 10, 1);
		add_action('woocommerce_subscription_status_expired', [__CLASS__, 'on_sub_status_expired'], 10, 1);
		add_action('woocommerce_subscription_status_cancelled', [__CLASS__, 'on_sub_status_cancelled'], 10, 1);
		add_action('woocommerce_subscription_status_pending-cancel', [__CLASS__, 'on_sub_status_pending_cancel'], 10, 1);

		add_action('woocommerce_subscription_renewal_payment_complete', [__CLASS__, 'on_renewal_payment_complete'], 10, 2);

		add_action('woocommerce_order_fully_refunded', [__CLASS__, 'on_order_refunded'], 10, 2);
		add_action('woocommerce_order_refunded', [__CLASS__, 'on_order_refunded'], 10, 2);
		add_action('woocommerce_order_status_refunded', [__CLASS__, 'on_order_refunded_status'], 10, 1);
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname(plugin_basename(CLIENT_MEMBERSHIP_PLUGIN_FILE)) . '/languages'
		);
	}

	public static function on_activate(): void {
		self::register_rewrite();
		flush_rewrite_rules();
	}

	public static function on_deactivate(): void {
		flush_rewrite_rules();
	}

	public static function register_rewrite(): void {
		add_rewrite_rule(
			'^membership/validate/?$',
			'index.php?' . self::QUERY_VAR_VALIDATE . '=1',
			'top'
		);
	}

	public static function register_query_vars(array $vars): array {
		$vars[] = self::QUERY_VAR_VALIDATE;
		return $vars;
	}
}
