<?php
defined('ABSPATH') || exit;

/**
 * Revision limits.
 *
 * - Hub article copies: their history lives on the hub, and every hub edit rewrites
 *   the copy, so by default they keep no revisions here (setting "rev_copies").
 * - Optionally all other posts and pages (setting "rev_other"; empty = WordPress default
 *   / WP_POST_REVISIONS).
 *
 * Lowering a limit only affects new saves in WordPress, so existing extra revisions are
 * removed by prune(): after the settings change (background, in batches), with the
 * "Clean up revisions now" button, or `wp hub-client revisions`.
 */
class HC_Revisions {

	const BATCH = 200;
	const HOOK  = 'hub_client_prune_revisions';

	public static function init(): void {
		add_filter('wp_revisions_to_keep', [__CLASS__, 'keep'], 20, 2);
		add_action(self::HOOK, [__CLASS__, 'prune_batch']);
		add_action('update_option_hub_client', [__CLASS__, 'on_settings_change'], 10, 2);
		add_action('admin_post_hub_client_prune', [__CLASS__, 'action_prune']);
	}

	/** -1 = unlimited, 0 = none, N = keep N. Null = leave WordPress's value. */
	public static function limit_for(WP_Post $post): ?int {
		if (get_post_meta($post->ID, '_hub_id', true)) {
			return max(-1, (int) hub_client_opt('rev_copies'));
		}
		$other = hub_client_opt('rev_other');
		return ($other === '' || $other === null) ? null : max(-1, (int) $other);
	}

	public static function keep($num, $post) {
		if (!$post instanceof WP_Post) return $num;
		$limit = self::limit_for($post);
		return $limit === null ? $num : $limit;
	}

	public static function on_settings_change($old, $new): void {
		$o = (array) $old; $n = (array) $new;
		if (($o['rev_copies'] ?? null) === ($n['rev_copies'] ?? null) && ($o['rev_other'] ?? null) === ($n['rev_other'] ?? null)) return;
		if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 30, self::HOOK);
	}

	/** Background batch; reschedules itself until nothing is left. */
	public static function prune_batch(): void {
		$r = self::prune(self::BATCH);
		if ($r['more']) wp_schedule_single_event(time() + 60, self::HOOK);
	}

	/**
	 * Delete the oldest revisions beyond each post's limit (autosaves are never deleted).
	 * @return array{posts:int,deleted:int,more:bool}
	 */
	public static function prune(int $max_posts = 0, bool $dry_run = false): array {
		global $wpdb;
		$parents = $wpdb->get_col(
			"SELECT post_parent FROM {$wpdb->posts}
			 WHERE post_type = 'revision' AND post_name NOT LIKE '%autosave%'
			 GROUP BY post_parent ORDER BY post_parent"
		);
		$posts = 0; $deleted = 0; $checked = 0; $more = false;
		foreach ($parents as $pid) {
			$post = get_post((int) $pid);
			if (!$post) continue;
			$keep = (int) wp_revisions_to_keep($post);
			if ($keep < 0) continue;
			// Same rule as WordPress on save: the autosave counts toward the limit but is never deleted.
			$all   = wp_get_post_revisions($post->ID, ['order' => 'ASC', 'orderby' => 'date ID', 'check_enabled' => false]);
			$over  = count($all) - $keep;
			$extra = [];
			foreach ($all as $r) {
				if ($over <= count($extra)) break;
				if (!str_contains($r->post_name, 'autosave')) $extra[] = $r;
			}
			if (!$extra) continue;
			if ($max_posts && $checked >= $max_posts) { $more = true; break; }
			$checked++;
			$posts++;
			foreach ($extra as $rev) {
				if (!$dry_run) wp_delete_post_revision($rev->ID);
				$deleted++;
			}
		}
		return ['posts' => $posts, 'deleted' => $deleted, 'more' => $more];
	}

	public static function action_prune(): void {
		check_admin_referer('hub_client_prune');
		if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
		$r = self::prune();
		$msg = 'ok:' . sprintf(__('Removed %1$d old revisions from %2$d posts.', 'hub-client'), $r['deleted'], $r['posts']);
		wp_safe_redirect(add_query_arg('hc_msg', rawurlencode($msg), admin_url('options-general.php?page=hub-client')));
		exit;
	}
}
