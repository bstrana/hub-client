<?php
defined('ABSPATH') || exit;

/**
 * Hub client commands.
 */
class HC_CLI {

	/**
	 * Pull changes from the hub and trash removed articles.
	 *
	 * [--full]
	 * : Re-check every article, not only changes since the last run.
	 */
	public function sync($args, $assoc) {
		$r = HC_Sync::reconcile(!empty($assoc['full']));
		if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
		WP_CLI::success(sprintf('%d new, %d updated, %d unchanged, %d removed, %d skipped, %d errors.', $r['created'], $r['updated'], $r['unchanged'], $r['removed'], $r['skipped'], $r['errors']));
	}

	/**
	 * Refresh every page section (shortcodes / Bricks tags) in use.
	 */
	public function refresh() {
		$r = HC_Blocks::refresh_all();
		$r['failed'] ? WP_CLI::warning("{$r['refreshed']} refreshed, {$r['failed']} failed.") : WP_CLI::success("{$r['refreshed']} sections refreshed.");
	}

	/**
	 * Check the connection to the hub.
	 */
	public function test() {
		$r = HC_API::get('/meta');
		if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
		$sites = array_column((array) ($r['data']['sites'] ?? []), 'slug');
		if (!in_array(hub_client_opt('site'), $sites, true)) WP_CLI::error('Connected, but "' . hub_client_opt('site') . '" is not a target site on the hub.');
		WP_CLI::success('Connected. Languages: ' . implode(', ', (array) $r['data']['languages']));
	}

	/**
	 * Publish/unpublish copies whose visibility window has passed.
	 */
	public function windows() {
		WP_CLI::success(HC_Sync::apply_windows() . ' changed.');
	}
}
