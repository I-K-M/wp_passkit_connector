<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal PassKit client.
 */
final class Client_Membership_PassKit_Client {

	private string $base_url;
	private string $bearer_token;

	public function __construct(string $base_url, string $bearer_token) {
		$this->base_url     = rtrim($base_url, '/');
		$this->bearer_token = $bearer_token;
	}

	private function request(string $method, string $path, ?array $body = null): array {
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->bearer_token,
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

	public function create_member(array $payload): array {
		return $this->request('POST', '/members/member', $payload);
	}

	public function update_member(array $payload): array {
		return $this->request('PUT', '/members/member', $payload);
	}
}
