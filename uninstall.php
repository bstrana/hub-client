<?php
/**
 * Hub Client uninstall: removes the plugin's settings, stored sections, logs and timers.
 * Article copies and downloaded images stay — they are this site's posts and media now.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$like = $wpdb->esc_like('hub_client') . '%';
foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)) as $name) {
	delete_option($name);
}
foreach (['_transient_hc_', '_transient_timeout_hc_'] as $p) {
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($p) . '%'));
}
foreach (['hub_client_reconcile', 'hub_client_window', 'hub_client_event_ended', 'hub_client_prune_revisions'] as $hook) {
	wp_clear_scheduled_hook($hook);
}
