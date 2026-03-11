<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal PassKit client.
 * Replace endpoints and payload to match the real PassKit API used on the project.
 */
final class Client_Membership_PassKit_Client {

	private string $base_url;
	private string $api_key;
	private string $api_secret;

	public function __construct(string $base_url, string $api_key, string $api_secret) {
		$this->base_url   = rtrim($base_url, '/');
		$this->api_key    = $api_key;
		$this->api_secret = $api_secret;
	}

	private function request(string $method, string $path, ?array $body = null): array {
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];

		if ($body !== null) {
			$args['body'] = wp_json_encode($body);
		}

		$url  = $this->base_url . $path;
		$resp = wp_remote_request($url, $args);

		if (is_wp_error($resp)) {
			error_log('[Client Membership] PassKit request failed: ' . $resp->get_error_message());
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$raw  = (string) wp_remote_retrieve_body($resp);

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$data = [];
		}

		if ($code < 200 || $code >= 300) {
			error_log('[Client Membership] PassKit non-2xx (' . $code . '): ' . $raw);
			return [];
		}

		return $data;
	}

	public function create_pass(array $payload): array {
		return $this->request('POST', '/v1/passes/create', $payload);
	}

	public function update_pass(string $pass_id, array $payload): array {
		return $this->request('PATCH', '/v1/passes/' . rawurlencode($pass_id), $payload);
	}
}
