<?php
defined('ABSPATH') || exit;

/** Read-only calls to the hub's /wp-json/hub/v1 API. */
class HC_API {

	/**
	 * @return array{data:mixed,headers:array}|WP_Error
	 */
	public static function get(string $path, array $query = [], int $timeout = 20) {
		$base = untrailingslashit((string) hub_client_opt('hub_url'));
		if ($base === '' || !str_starts_with($base, 'https://') && !apply_filters('hub_client_allow_http', false)) {
			return new WP_Error('hc_config', __('Hub URL is missing or not HTTPS.', 'hub-client'));
		}
		$url     = add_query_arg(array_map('rawurlencode', $query), $base . '/wp-json/hub/v1' . $path);
		$headers = ['Accept' => 'application/json'];
		if ($key = (string) hub_client_opt('api_key')) $headers['X-Hub-Key'] = $key;

		$res = wp_safe_remote_get($url, ['timeout' => $timeout, 'headers' => $headers]);
		if (is_wp_error($res)) return $res;

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = json_decode((string) wp_remote_retrieve_body($res), true);
		if ($code !== 200) {
			$msg = is_array($body) && isset($body['message']) ? $body['message'] : "HTTP $code";
			return new WP_Error('hc_http_' . $code, $msg, ['status' => $code]);
		}
		return ['data' => $body, 'headers' => wp_remote_retrieve_headers($res)];
	}
}
