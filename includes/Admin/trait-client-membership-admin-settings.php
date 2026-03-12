<?php

if (!defined('ABSPATH')) {
	exit;
}

trait Client_Membership_Admin_Settings_Trait {

	private static function defaults(): array {
		return [
			'membership_product_id' => 0,
			'passkit_base_url'      => 'https://api.passkit.com',
			'passkit_bearer_token'  => '',
			'passkit_template_id'   => '',
			'passkit_tier_id'       => 'base',
			'passkit_public_base_url' => '',
			'rate_ip_per_5m'        => 30,
			'rate_token_per_1m'     => 10,
			'enable_json'           => 1,
			'brand_name'            => 'Client Membership',
			'brand_logo_url'        => '',
			'brand_logo_text'       => 'Client Logo',
			'brand_support_note'    => 'This result was validated live by the website.',
			'active_message'        => 'Member is valid. Apply member pricing.',
			'inactive_message'      => 'Do not apply member pricing.',
			'endpoint_slug'         => 'membership/validate',
		];
	}

	private static function get_settings(): array {
		$saved = get_option(self::OPTION_NAME, []);
		if (!is_array($saved)) {
			$saved = [];
		}

		return wp_parse_args($saved, self::defaults());
	}

	private static function setting(string $key, $default = null) {
		$settings = self::get_settings();
		return array_key_exists($key, $settings) ? $settings[$key] : $default;
	}

	private static function brand_name(): string {
		return (string) self::setting('brand_name', 'Client Membership');
	}

	private static function validation_url(string $token = ''): string {
		$base = home_url('/membership/validate');
		if ($token === '') {
			return $base;
		}

		return add_query_arg(self::QUERY_ARG_TOKEN, rawurlencode($token), $base);
	}

	public static function register_admin_menu(): void {
		add_options_page(
			__('Client Membership', self::TEXT_DOMAIN),
			__('Client Membership', self::TEXT_DOMAIN),
			'manage_options',
			'client-membership',
			[__CLASS__, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
				'default'           => self::defaults(),
			]
		);

		add_settings_section(
			'client_membership_core',
			__('Core Settings', self::TEXT_DOMAIN),
			function () {
				echo '<p>' . esc_html__('Woo Subscriptions is the source of truth.', self::TEXT_DOMAIN) . '</p>';
			},
			'client-membership'
		);

		add_settings_field(
			'membership_product_id',
			__('Membership Product ID', self::TEXT_DOMAIN),
			[__CLASS__, 'field_membership_product_id'],
			'client-membership',
			'client_membership_core'
		);

		add_settings_section(
			'client_membership_branding',
			__('Branding', self::TEXT_DOMAIN),
			function () {
				echo '<p>' . esc_html__('These values let you quickly rebrand the validation screen for each client.', self::TEXT_DOMAIN) . '</p>';
			},
			'client-membership'
		);

		add_settings_field('brand_name', __('Brand Name', self::TEXT_DOMAIN), [__CLASS__, 'field_brand_name'], 'client-membership', 'client_membership_branding');
		add_settings_field('brand_logo_url', __('Logo URL', self::TEXT_DOMAIN), [__CLASS__, 'field_brand_logo_url'], 'client-membership', 'client_membership_branding');
		add_settings_field('brand_logo_text', __('Logo Placeholder Text', self::TEXT_DOMAIN), [__CLASS__, 'field_brand_logo_text'], 'client-membership', 'client_membership_branding');
		add_settings_field('active_message', __('Active Message', self::TEXT_DOMAIN), [__CLASS__, 'field_active_message'], 'client-membership', 'client_membership_branding');
		add_settings_field('inactive_message', __('Inactive Message', self::TEXT_DOMAIN), [__CLASS__, 'field_inactive_message'], 'client-membership', 'client_membership_branding');
		add_settings_field('brand_support_note', __('Footer Note', self::TEXT_DOMAIN), [__CLASS__, 'field_brand_support_note'], 'client-membership', 'client_membership_branding');

		add_settings_section(
			'client_membership_passkit',
			__('PassKit', self::TEXT_DOMAIN),
			function () {
				echo '<p>' . esc_html__('If bearer token or identifiers are missing, wallet synchronization is skipped.', self::TEXT_DOMAIN) . '</p>';
			},
			'client-membership'
		);

		add_settings_field('passkit_base_url', __('PassKit Base URL', self::TEXT_DOMAIN), [__CLASS__, 'field_passkit_base_url'], 'client-membership', 'client_membership_passkit');
		add_settings_field('passkit_bearer_token', __('PassKit Bearer Token', self::TEXT_DOMAIN), [__CLASS__, 'field_passkit_bearer_token'], 'client-membership', 'client_membership_passkit');
		add_settings_field('passkit_template_id', __('PassKit Program ID', self::TEXT_DOMAIN), [__CLASS__, 'field_passkit_template_id'], 'client-membership', 'client_membership_passkit');
		add_settings_field('passkit_tier_id', __('PassKit Tier ID', self::TEXT_DOMAIN), [__CLASS__, 'field_passkit_tier_id'], 'client-membership', 'client_membership_passkit');
		add_settings_field('passkit_public_base_url', __('Pass URL Base (optional)', self::TEXT_DOMAIN), [__CLASS__, 'field_passkit_public_base_url'], 'client-membership', 'client_membership_passkit');

		add_settings_section(
			'client_membership_security',
			__('Security', self::TEXT_DOMAIN),
			function () {
				echo '<p>' . esc_html__('Basic rate limiting and neutral responses.', self::TEXT_DOMAIN) . '</p>';
			},
			'client-membership'
		);

		add_settings_field('rate_ip_per_5m', __('Rate limit per IP (5 minutes)', self::TEXT_DOMAIN), [__CLASS__, 'field_rate_ip_per_5m'], 'client-membership', 'client_membership_security');
		add_settings_field('rate_token_per_1m', __('Rate limit per token (1 minute)', self::TEXT_DOMAIN), [__CLASS__, 'field_rate_token_per_1m'], 'client-membership', 'client_membership_security');
		add_settings_field('enable_json', __('Enable JSON output', self::TEXT_DOMAIN), [__CLASS__, 'field_enable_json'], 'client-membership', 'client_membership_security');
	}

	public static function sanitize_settings($input): array {
		$input = is_array($input) ? $input : [];

		$out = [];
		$out['membership_product_id'] = isset($input['membership_product_id']) ? absint($input['membership_product_id']) : 0;

		$out['passkit_base_url']        = isset($input['passkit_base_url']) ? esc_url_raw(trim((string) $input['passkit_base_url'])) : 'https://api.passkit.com';
		$out['passkit_bearer_token']    = isset($input['passkit_bearer_token']) ? trim((string) $input['passkit_bearer_token']) : '';
		$out['passkit_template_id']     = isset($input['passkit_template_id']) ? sanitize_text_field((string) $input['passkit_template_id']) : '';
		$out['passkit_tier_id']         = isset($input['passkit_tier_id']) ? sanitize_text_field((string) $input['passkit_tier_id']) : 'base';
		$out['passkit_public_base_url'] = isset($input['passkit_public_base_url']) ? esc_url_raw(trim((string) $input['passkit_public_base_url'])) : '';

		$out['rate_ip_per_5m']    = isset($input['rate_ip_per_5m']) ? max(1, absint($input['rate_ip_per_5m'])) : 30;
		$out['rate_token_per_1m'] = isset($input['rate_token_per_1m']) ? max(1, absint($input['rate_token_per_1m'])) : 10;
		$out['enable_json']       = !empty($input['enable_json']) ? 1 : 0;

		$out['brand_name']         = isset($input['brand_name']) ? sanitize_text_field((string) $input['brand_name']) : 'Client Membership';
		$out['brand_logo_url']     = isset($input['brand_logo_url']) ? esc_url_raw(trim((string) $input['brand_logo_url'])) : '';
		$out['brand_logo_text']    = isset($input['brand_logo_text']) ? sanitize_text_field((string) $input['brand_logo_text']) : 'Client Logo';
		$out['brand_support_note'] = isset($input['brand_support_note']) ? sanitize_textarea_field((string) $input['brand_support_note']) : 'This result was validated live by the website.';
		$out['active_message']     = isset($input['active_message']) ? sanitize_text_field((string) $input['active_message']) : 'Member is valid. Apply member pricing.';
		$out['inactive_message']   = isset($input['inactive_message']) ? sanitize_text_field((string) $input['inactive_message']) : 'Do not apply member pricing.';
		$out['endpoint_slug']      = 'membership/validate';

		return wp_parse_args($out, self::defaults());
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Client Membership', self::TEXT_DOMAIN) . '</h1>';
		echo '<p><strong>' . esc_html__('Validation endpoint:', self::TEXT_DOMAIN) . '</strong> <code>' . esc_html(self::validation_url()) . '</code></p>';
		echo '<p><strong>' . esc_html__('Important:', self::TEXT_DOMAIN) . '</strong> ' . esc_html__('exclude /membership/validate* from caching.', self::TEXT_DOMAIN) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields(self::OPTION_GROUP);
		do_settings_sections('client-membership');
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	private static function render_text_input(string $key, string $type = 'text', string $class = 'regular-text', string $description = ''): void {
		$value = self::setting($key, '');
		echo '<input type="' . esc_attr($type) . '" class="' . esc_attr($class) . '" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" />';
		if ($description !== '') {
			echo '<p class="description">' . esc_html($description) . '</p>';
		}
	}

	private static function render_number_input(string $key, int $min = 1): void {
		$value = (int) self::setting($key, 0);
		echo '<input type="number" min="' . esc_attr((string) $min) . '" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" />';
	}

	private static function render_checkbox(string $key, string $label): void {
		$checked = !empty(self::setting($key, 0)) ? 'checked' : '';
		echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr($key) . ']" value="1" ' . $checked . ' /> ' . esc_html($label) . '</label>';
	}

	public static function field_membership_product_id(): void {
		self::render_number_input('membership_product_id', 1);
		echo '<p class="description">' . esc_html__('The WooCommerce subscription product tied to the membership.', self::TEXT_DOMAIN) . '</p>';
	}

	public static function field_brand_name(): void { self::render_text_input('brand_name'); }
	public static function field_brand_logo_url(): void { self::render_text_input('brand_logo_url'); }
	public static function field_brand_logo_text(): void { self::render_text_input('brand_logo_text'); }
	public static function field_active_message(): void { self::render_text_input('active_message'); }
	public static function field_inactive_message(): void { self::render_text_input('inactive_message'); }

	public static function field_brand_support_note(): void {
		$value = (string) self::setting('brand_support_note', '');
		echo '<textarea class="large-text" rows="3" name="' . esc_attr(self::OPTION_NAME) . '[brand_support_note]">' . esc_textarea($value) . '</textarea>';
	}

	public static function field_passkit_base_url(): void { self::render_text_input('passkit_base_url'); }

	public static function field_passkit_bearer_token(): void {
		self::render_text_input('passkit_bearer_token', 'password');
		echo '<p class="description">' . esc_html__('Token generated in your PassKit program settings.', self::TEXT_DOMAIN) . '</p>';
	}

	public static function field_passkit_template_id(): void {
		self::render_text_input('passkit_template_id');
	}

	public static function field_passkit_tier_id(): void {
		self::render_text_input('passkit_tier_id');
		echo '<p class="description">' . esc_html__('Common default is "base".', self::TEXT_DOMAIN) . '</p>';
	}

	public static function field_passkit_public_base_url(): void {
		self::render_text_input('passkit_public_base_url');
		echo '<p class="description">' . esc_html__('Optional public URL prefix to construct saved pass links from pass IDs.', self::TEXT_DOMAIN) . '</p>';
	}

	public static function field_rate_ip_per_5m(): void { self::render_number_input('rate_ip_per_5m', 1); }
	public static function field_rate_token_per_1m(): void { self::render_number_input('rate_token_per_1m', 1); }

	public static function field_enable_json(): void {
		self::render_checkbox('enable_json', __('Allow ?format=json and Accept: application/json', self::TEXT_DOMAIN));
	}

	private static function render_html(array $payload): void {
		$status = (string) ($payload['status'] ?? self::STATUS_INVALID);
		$active = !empty($payload['active']);
		$name   = isset($payload['member_name']) ? (string) $payload['member_name'] : '';
		$exp    = isset($payload['expiry']) ? (string) $payload['expiry'] : '';

		$headline = self::status_headline($status, $active);
		$icon     = $active ? '✅' : '❌';
		$accent   = $active ? '#39ff14' : '#ff1744';

		$brand_name       = self::brand_name();
		$brand_logo_url   = (string) self::setting('brand_logo_url', '');
		$brand_logo_text  = (string) self::setting('brand_logo_text', 'Client Logo');
		$active_message   = (string) self::setting('active_message', 'Member is valid. Apply member pricing.');
		$inactive_message = (string) self::setting('inactive_message', 'Do not apply member pricing.');
		$footer_note      = (string) self::setting('brand_support_note', 'This result was validated live by the website.');

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html__('Membership Validation', self::TEXT_DOMAIN) . '</title>';
		echo '<style>
			*{box-sizing:border-box}
			body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#000;color:#fff;}
			.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;padding:24px;}
			.logo{margin-bottom:20px;}
			.logo img{max-width:220px;max-height:90px;height:auto;display:block;}
			.logo-placeholder{width:220px;height:72px;display:flex;align-items:center;justify-content:center;border:1px dashed rgba(255,255,255,.35);color:rgba(255,255,255,.75);font-weight:700;letter-spacing:.08em;text-transform:uppercase;text-align:center;padding:10px;}
			.card{width:100%;max-width:680px;overflow:hidden;background:linear-gradient(180deg,#111 0%,#222 100%);border:1px solid rgba(255,255,255,.08);box-shadow:0 20px 60px rgba(0,0,0,.45);}
			.hero{padding:32px 24px 22px;text-align:center;background:' . ($active ? 'linear-gradient(180deg, rgba(57,255,20,.16), rgba(57,255,20,.05))' : 'linear-gradient(180deg, rgba(255,23,68,.18), rgba(255,23,68,.05))') . ';border-bottom:1px solid rgba(255,255,255,.08);}
			.brand{font-size:13px;letter-spacing:.12em;text-transform:uppercase;opacity:.9;margin-bottom:18px;}
			.icon{width:136px;height:136px;margin:0 auto 18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:82px;font-weight:900;color:#000;background:' . esc_attr($accent) . ';}
			.status{font-size:64px;line-height:.95;font-weight:900;letter-spacing:-.04em;color:' . esc_attr($accent) . ';margin:0;}
			.substatus{margin-top:12px;font-size:18px;font-weight:700;opacity:.95;}
			.content{padding:20px;display:grid;gap:14px;}
			.box{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:16px 18px;}
			.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;opacity:.7;margin-bottom:6px;}
			.value{font-size:30px;font-weight:800;line-height:1.15;}
			.expiry{font-size:24px;font-weight:800;}
			.note{font-size:14px;line-height:1.45;opacity:.82;padding:4px 2px 2px;}
			@media (max-width: 640px){.icon{width:108px;height:108px;font-size:62px}.status{font-size:48px}.value{font-size:24px}.expiry{font-size:20px}}
		</style>';
		echo '</head><body>';
		echo '<div class="wrap">';

		echo '<div class="logo">';
		if ($brand_logo_url !== '') {
			echo '<img src="' . esc_url($brand_logo_url) . '" alt="' . esc_attr($brand_name) . '">';
		} else {
			echo '<div class="logo-placeholder">' . esc_html($brand_logo_text) . '</div>';
		}
		echo '</div>';

		echo '<div class="card">';
		echo '<div class="hero">';
		echo '<div class="brand">' . esc_html($brand_name) . '</div>';
		echo '<div class="icon">' . esc_html($icon) . '</div>';
		echo '<h1 class="status">' . esc_html($headline) . '</h1>';
		echo '<div class="substatus">' . esc_html($active ? $active_message : $inactive_message) . '</div>';
		echo '</div>';

		echo '<div class="content">';

		if ($name !== '') {
			echo '<div class="box">';
			echo '<div class="label">' . esc_html__('Member', self::TEXT_DOMAIN) . '</div>';
			echo '<div class="value">' . esc_html($name) . '</div>';
			echo '</div>';
		}

		if ($exp !== '') {
			echo '<div class="box">';
			echo '<div class="label">' . esc_html__('Expiry', self::TEXT_DOMAIN) . '</div>';
			echo '<div class="expiry">' . esc_html($exp) . '</div>';
			echo '</div>';
		}

		echo '<div class="note">';
		if ($active) {
			echo esc_html__('This membership is active.', self::TEXT_DOMAIN) . ' ';
		} else {
			echo esc_html__('This membership is not active.', self::TEXT_DOMAIN) . ' ';
		}
		echo esc_html($footer_note);
		echo '</div>';

		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</body></html>';
		exit;
	}

	private static function status_headline(string $status, bool $active): string {
		if ($active) {
			return 'ACTIVE';
		}

		switch ($status) {
			case self::STATUS_EXPIRED:
				return 'EXPIRED';
			case self::STATUS_SUSPENDED:
				return 'SUSPENDED';
			case self::STATUS_CANCELLED:
				return 'CANCELLED';
			case self::STATUS_REFUNDED:
				return 'REFUNDED';
			case self::STATUS_RATE_LIMIT:
				return 'SLOW DOWN';
			case self::STATUS_INVALID:
				return 'INVALID';
			default:
				return 'NOT ACTIVE';
		}
	}
}
