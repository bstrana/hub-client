<?php
defined('ABSPATH') || exit;

/**
 * The hub's placements, content types and languages (/hub/v1/meta), kept on this site.
 * Used by the Bricks dynamic data picker and the shortcode cheat sheet.
 *
 * Never fetched for visitors. Refreshed when an editor needs it and the copy is over an
 * hour old (only if "load new sections for editors" is on), on "Refresh sections now",
 * and when the cheat sheet is opened. If the hub can't be reached, the last good copy is
 * kept and the next try waits 5 minutes, so the builder never hangs on every request.
 */
class HC_Meta {

	const OPTION = 'hub_client_meta_store';
	const TTL    = HOUR_IN_SECONDS;
	const RETRY  = 5 * MINUTE_IN_SECONDS;

	/** @param bool $may_fetch false = only what's stored */
	public static function get(bool $may_fetch = false): array {
		$s = self::stored();
		if ($may_fetch && self::due($s)) {
			$s = self::refresh(false);
		}
		$data = (array) ($s['data'] ?? []);
		if (!empty($s['error'])) $data['error'] = $s['error'];
		return $data;
	}

	/** May an editor-side request fetch now? */
	public static function editor_may_fetch(): bool {
		return hub_client_opt('editor_fetch') && is_user_logged_in() && current_user_can('edit_posts');
	}

	public static function refresh(bool $bust = true): array {
		$s = self::stored();
		$r = HC_API::get('/meta', $bust ? ['_v' => (string) time()] : [], 8);
		if (is_wp_error($r)) {
			$s['error'] = $r->get_error_message();
			$s['tried'] = time();
		} else {
			$s = ['data' => (array) $r['data'], 'fetched' => time(), 'tried' => time(), 'error' => ''];
		}
		update_option(self::OPTION, $s, false);
		return $s;
	}

	private static function stored(): array {
		$s = get_option(self::OPTION, []);
		return is_array($s) ? $s : [];
	}

	private static function due(array $s): bool {
		if ((string) hub_client_opt('hub_url') === '') return false;
		$age_ok   = (time() - (int) ($s['fetched'] ?? 0)) < self::TTL;
		$retry_ok = (time() - (int) ($s['tried'] ?? 0)) < self::RETRY;
		return !$age_ok && !$retry_ok;
	}

	/** Slug => name lists, for convenience. */
	public static function placements(array $m): array {
		return array_column((array) ($m['placements'] ?? []), 'name', 'slug');
	}

	public static function types(array $m): array {
		return array_column((array) ($m['content_types'] ?? []), 'name', 'slug');
	}
}
