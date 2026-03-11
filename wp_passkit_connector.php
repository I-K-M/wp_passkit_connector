<?php
/**
 * Plugin Name: Client Membership
 * Plugin URI:  https://example.com
 * Description: Generic subscription-based membership plugin for professional teams. Woo Subscriptions is the source of truth. Generates QR tokens, exposes a validation endpoint, and synchronizes wallet passes.
 * Version:     1.0.0
 * Author:      Your Team
 * Author URI:  https://example.com
 * Text Domain: client-membership
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CLIENT_MEMBERSHIP_PLUGIN_FILE', __FILE__);
define('CLIENT_MEMBERSHIP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Admin/trait-client-membership-admin-settings.php';
require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Membership/trait-client-membership-membership-engine.php';
require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Validation/trait-client-membership-validation-endpoint.php';
require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Integrations/trait-client-membership-passkit-sync.php';
require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Integrations/class-client-membership-passkit-client.php';
require_once CLIENT_MEMBERSHIP_PLUGIN_DIR . 'includes/Core/class-client-membership-plugin.php';

Client_Membership_Plugin::init();
