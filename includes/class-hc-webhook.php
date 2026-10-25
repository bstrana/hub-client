<?php
defined('ABSPATH') || exit;

/**
 * POST /wp-json/hub-client/v1/webhook
 *
 * Accepts the hub's signed change events:
 *   X-Hub-Timestamp: unix seconds
 *   X-Hub-Signature: sha256=HMAC_SHA256(timestamp + "." + body, secret)
 * On "upsert" the item is fetched from the hub (never trusted from the
 * payload); on "delete" the local copy goes to the trash.
 */
class HC_Webhook {

	const MAX_SKEW = 300;

	public static function init(): void {
		add_action('rest_api_init', function () {
			register_rest_route('hub-client/v1', '/webhook', [
				'methods'             => 'POST',
				'callback'            => [__CLASS__, 'handle'],
				'permission_callback' => '__return_true', // authenticated by signature below
			]);
		});
	}

	public static function url(): string {
		return rest_url('hub-client/v1/webhook');
	}

	public static function verify(string $body, string $ts, string $sig, string $secret): bool {
		if ($secret === '' || $ts === '' || !ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW) return false;
		return hash_equals('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), $sig);
	}

	public static function handle(WP_REST_Request $r) {
		$secret = (string) hub_client_opt('secret');
		if ($secret === '') {
			return new WP_Error('hc_not_configured', 'Webhook secret not set.', ['status' => 503]);
		}

		$body = (string) $r->get_body();
		$ts   = (string) $r->get_header('x_hub_timestamp');
		$sig  = (string) $r->get_header('x_hub_signature');
		if (!self::verify($body, $ts, $sig, $secret)) {
			// Log at most once a minute, so a flood of junk requests can't turn into DB writes.
			if (!get_transient('hc_wh_rejected')) {
				set_transient('hc_wh_rejected', 1, MINUTE_IN_SECONDS);
				HC_Sync::log('webhook', 0, 'rejected: bad or expired signature');
			}
			return new WP_Error('hc_bad_signature', 'Invalid signature.', ['status' => 401]);
		}

		// Each signature is accepted once (replay protection inside the time window).
		$seen = 'hc_wh_' . md5($sig);
		if (get_transient($seen)) {
			return new WP_REST_Response(['result' => 'duplicate'], 200);
		}
		set_transient($seen, 1, self::MAX_SKEW * 2);

		$data = json_decode($body, true);
		$site = (string) hub_client_opt('site');
		if (!is_array($data) || empty($data['id']) || ($data['site'] ?? '') !== $site) {
			return new WP_Error('hc_bad_payload', 'Payload not for this site.', ['status' => 400]);
		}

		ignore_user_abort(true); // the hub doesn't wait for us
		$hub_id = (int) $data['id'];
		$bust   = $ts;               // unique per change: bypasses any edge cache in front of the hub
		$item   = null;

		if (($data['event'] ?? '') === 'delete') {
			$result = HC_Sync::remove_by_hub_id($hub_id);
		} else {
			$res = HC_API::get('/content/' . $hub_id, ['site' => $site, 'exact' => 'true', 'lang' => (string) ($data['lang'] ?? ''), '_v' => $bust]);
			if (is_wp_error($res)) {
				// Gone or no longer for this site on the hub → remove the copy.
				$code   = (int) ($res->get_error_data()['status'] ?? 0);
				$result = $code === 404 ? HC_Sync::remove_by_hub_id($hub_id) : $res;
			} else {
				$item   = (array) $res['data'];
				$result = HC_Sync::upsert($item);
			}
		}

		// Page sections showing this item, or whose placement/type it belongs to.
		$sections = HC_Blocks::refresh_for_item($hub_id, $item, $bust);
		$changed  = $sections > 0 || in_array($result, ['created', 'updated', 'removed'], true);
		$purged   = $changed ? HC_Sync::purge_page_cache() : [];
		if (is_string($result)) {
			$result .= $sections ? ", $sections section(s) refreshed" : '';
			$result .= $purged ? ', page cache purged (' . implode(', ', $purged) . ')' : '';
		}

		if (is_wp_error($result)) {
			HC_Sync::log('webhook', $hub_id, $result->get_error_message());
			return new WP_Error('hc_sync_failed', $result->get_error_message(), ['status' => 502]);
		}
		HC_Sync::log('webhook', $hub_id, ($data['event'] ?? '') . ': ' . $result);
		return new WP_REST_Response(['result' => $result], 200);
	}
}
