<?php
/**
 * Plugin Name:       Hub Client
 * Description:       Hub content on this site: [hub] shortcodes and {hub:…} Bricks tags for page sections, plus local copies of hub articles with their own URLs, templates, SEO and sitemaps. Updated instantly by signed webhooks.
 * Version:           0.5.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Tom
 * License:           GPL-2.0-or-later
 * Text Domain:       hub-client
 * GitHub Plugin URI: https://github.com/bstrana/hub-client
 * Primary Branch:    main
 */

defined('ABSPATH') || exit;

define('HUB_CLIENT_VERSION', '0.5.0');
define('HUB_CLIENT_DIR', plugin_dir_path(__FILE__));

require_once HUB_CLIENT_DIR . 'includes/class-hc-api.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-meta.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-revisions.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-media.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-sync.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-webhook.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-admin.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-blocks.php';
require_once HUB_CLIENT_DIR . 'includes/class-hc-cheatsheet.php';

HC_Sync::init();
HC_Webhook::init();
HC_Admin::init();
HC_Blocks::init();
HC_Cheatsheet::init();
HC_Revisions::init();

if (defined('WP_CLI') && WP_CLI) {
	require_once HUB_CLIENT_DIR . 'includes/class-hc-cli.php';
	WP_CLI::add_command('hub-client', 'HC_CLI');
}

register_activation_hook(__FILE__, function () {
	HC_Sync::register_types();
	HC_Sync::schedule();
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('hub_client_reconcile');
	wp_clear_scheduled_hook('hub_client_window');
	flush_rewrite_rules();
});

/**
 * Settings with defaults. Secret and key can come from wp-config.php
 * (HUB_CLIENT_SECRET, HUB_CLIENT_KEY), which wins over the database.
 */
function hub_client_opt(string $key) {
	if ($key === 'secret' && defined('HUB_CLIENT_SECRET')) return (string) HUB_CLIENT_SECRET;
	if ($key === 'api_key' && defined('HUB_CLIENT_KEY')) return (string) HUB_CLIENT_KEY;
	$defaults = [
		'hub_url'  => '',          // https://hub.yourdomain.hr
		'site'     => '',          // this site's target_site slug on the hub
		'api_key'  => '',
		'secret'   => '',
		'types'    => 'news',      // hub content types to keep as articles; empty = all
		'langs'    => '',          // languages to keep; empty = all the hub has
		'store_as' => 'post',      // 'post' or 'hub_article'
		'cpt_slug' => 'novosti',   // URL base when stored as hub_article
		'author'   => 0,
		'editor_fetch' => 1,       // a section never fetched before may be fetched when an editor views it
		'nightly'      => 0,       // nightly article check (a timed call); off = hub calls only on webhooks
		'purge_cache'  => 1,       // purge the page cache plugin after a hub change
		'copy_images'  => 1,       // copy section images into this site's media library (on refresh, never on page views)
		'rev_copies'   => 0,       // revisions kept for hub article copies (history lives on the hub); -1 = unlimited
		'rev_other'    => '',      // revisions for all other posts/pages; '' = WordPress default
	];
	$o = get_option('hub_client', []);
	return $o[$key] ?? $defaults[$key] ?? null;
}

function hub_client_csv(string $key): array {
	return array_values(array_filter(array_map('sanitize_key', explode(',', (string) hub_client_opt($key)))));
}
