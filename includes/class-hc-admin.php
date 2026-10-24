<?php
defined('ABSPATH') || exit;

/** Settings → Hub client. */
class HC_Admin {

	public static function init(): void {
		add_action('admin_menu', fn() => add_options_page(__('Hub client', 'hub-client'), __('Hub client', 'hub-client'), 'manage_options', 'hub-client', [__CLASS__, 'render']));
		add_action('admin_init', [__CLASS__, 'register']);
		add_action('admin_post_hub_client_test', [__CLASS__, 'action_test']);
		add_action('admin_post_hub_client_sync', [__CLASS__, 'action_sync']);
		add_action('admin_post_hub_client_refresh', [__CLASS__, 'action_refresh']);
		add_action('update_option_hub_client', fn() => update_option('hub_client_flush', 1), 10, 0);
		add_action('add_option_hub_client', fn() => update_option('hub_client_flush', 1), 10, 0);
		add_action('init', function () {
			if (get_option('hub_client_flush')) { delete_option('hub_client_flush'); flush_rewrite_rules(); }
		}, 99);
	}

	public static function register(): void {
		register_setting('hub_client', 'hub_client', ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize']]);
	}

	public static function sanitize($in): array {
		$in  = (array) $in;
		$old = (array) get_option('hub_client', []);
		$csv = fn($v) => implode(',', array_filter(array_map('sanitize_key', explode(',', (string) $v))));
		return [
			'hub_url'  => untrailingslashit(esc_url_raw((string) ($in['hub_url'] ?? ''), ['https', 'http'])),
			'site'     => sanitize_title((string) ($in['site'] ?? '')),
			// Empty password fields keep the stored value.
			'api_key'  => ($in['api_key'] ?? '') !== '' ? sanitize_text_field($in['api_key']) : (string) ($old['api_key'] ?? ''),
			'secret'   => ($in['secret'] ?? '') !== '' ? sanitize_text_field($in['secret']) : (string) ($old['secret'] ?? ''),
			'types'    => $csv($in['types'] ?? ''),
			'langs'    => $csv($in['langs'] ?? ''),
			'store_as' => ($in['store_as'] ?? '') === 'hub_article' ? 'hub_article' : 'post',
			'cpt_slug' => sanitize_title((string) ($in['cpt_slug'] ?? 'novosti')) ?: 'novosti',
			'author'   => absint($in['author'] ?? 0),
			'editor_fetch' => empty($in['editor_fetch']) ? 0 : 1,
			'nightly'      => empty($in['nightly']) ? 0 : 1,
			'purge_cache'  => empty($in['purge_cache']) ? 0 : 1,
			'copy_images'  => empty($in['copy_images']) ? 0 : 1,
		];
	}

	public static function action_test(): void {
		check_admin_referer('hub_client_test');
		if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
		$res = HC_API::get('/meta');
		$msg = is_wp_error($res)
			? 'error:' . $res->get_error_message()
			: (in_array(hub_client_opt('site'), array_column((array) ($res['data']['sites'] ?? []), 'slug'), true)
				? 'ok:' . sprintf(__('Connected. Hub languages: %s.', 'hub-client'), implode(', ', (array) $res['data']['languages']))
				: 'error:' . __('Connected, but this site\'s slug is not a target site on the hub.', 'hub-client'));
		wp_safe_redirect(add_query_arg('hc_msg', rawurlencode($msg), admin_url('options-general.php?page=hub-client')));
		exit;
	}

	public static function action_sync(): void {
		check_admin_referer('hub_client_sync');
		if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
		if (function_exists('set_time_limit')) @set_time_limit(300);
		$res = HC_Sync::reconcile(true);
		$msg = is_wp_error($res) ? 'error:' . $res->get_error_message() : 'ok:' . sprintf(
			__('Synced: %1$d new, %2$d updated, %3$d unchanged, %4$d removed, %5$d errors.', 'hub-client'),
			$res['created'], $res['updated'], $res['unchanged'], $res['removed'], $res['errors']
		);
		wp_safe_redirect(add_query_arg('hc_msg', rawurlencode($msg), admin_url('options-general.php?page=hub-client')));
		exit;
	}

	public static function action_refresh(): void {
		check_admin_referer('hub_client_refresh');
		if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
		$r = HC_Blocks::refresh_all();
		$msg = ($r['failed'] ? 'error:' : 'ok:') . sprintf(__('Sections refreshed: %1$d, failed: %2$d.', 'hub-client'), $r['refreshed'], $r['failed']);
		wp_safe_redirect(add_query_arg('hc_msg', rawurlencode($msg), admin_url('options-general.php?page=hub-client')));
		exit;
	}

	public static function render(): void {
		$o = wp_parse_args((array) get_option('hub_client', []), [
			'hub_url' => '', 'site' => '', 'api_key' => '', 'secret' => '', 'types' => 'news', 'langs' => '',
			'store_as' => 'post', 'cpt_slug' => 'novosti', 'author' => 0, 'editor_fetch' => 1, 'nightly' => 0, 'purge_cache' => 1, 'copy_images' => 1,
		]);
		$n = fn($k) => esc_attr("hub_client[$k]");
		if (!empty($_GET['hc_msg'])) {
			[$kind, $text] = array_pad(explode(':', sanitize_text_field(wp_unslash($_GET['hc_msg'])), 2), 2, '');
			printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $kind === 'ok' ? 'success' : 'error', esc_html($text));
		}
		$count = (int) (new WP_Query(['post_type' => HC_Sync::post_type(), 'post_status' => 'any', 'meta_key' => '_hub_id', 'fields' => 'ids', 'posts_per_page' => 1, 'suppress_filters' => true]))->found_posts;
		$last  = (int) get_option('hub_client_last_sync', 0);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Hub client', 'hub-client'); ?></h1>
			<p><?php esc_html_e('Articles from the content hub are kept here as local posts, with their own URLs, templates and SEO. Edit them on the hub; local changes are overwritten.', 'hub-client'); ?></p>
			<p><a href="<?php echo esc_url(admin_url('options-general.php?page=hub-shortcodes')); ?>"><?php esc_html_e('Shortcode and Bricks tag cheat sheet →', 'hub-client'); ?></a></p>

			<h2><?php esc_html_e('Connect this site on the hub', 'hub-client'); ?></h2>
			<p><?php esc_html_e('On the hub: Content blocks → Target sites → this site → Webhook URL:', 'hub-client'); ?></p>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr(HC_Webhook::url()); ?>" onclick="this.select()"></p>
			<p class="description"><?php esc_html_e('Then copy that target site\'s webhook secret (and API key, if the hub requires one) into the fields below — or better, into wp-config.php as HUB_CLIENT_SECRET and HUB_CLIENT_KEY.', 'hub-client'); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields('hub_client'); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="hc_url"><?php esc_html_e('Hub URL', 'hub-client'); ?></label></th>
						<td><input id="hc_url" type="url" class="regular-text" name="<?php echo $n('hub_url'); ?>" value="<?php echo esc_attr($o['hub_url']); ?>" placeholder="https://hub.yourdomain.hr"></td></tr>
					<tr><th><label for="hc_site"><?php esc_html_e('This site on the hub', 'hub-client'); ?></label></th>
						<td><input id="hc_site" type="text" class="regular-text" name="<?php echo $n('site'); ?>" value="<?php echo esc_attr($o['site']); ?>" placeholder="ybc">
						<p class="description"><?php esc_html_e('The target site slug.', 'hub-client'); ?></p></td></tr>
					<tr><th><label for="hc_secret"><?php esc_html_e('Webhook secret', 'hub-client'); ?></label></th>
						<td><?php if (defined('HUB_CLIENT_SECRET')) : ?><em><?php esc_html_e('Set in wp-config.php', 'hub-client'); ?></em>
						<?php else : ?><input id="hc_secret" type="password" class="regular-text" name="<?php echo $n('secret'); ?>" value="" placeholder="<?php echo $o['secret'] ? esc_attr__('saved — leave empty to keep', 'hub-client') : ''; ?>" autocomplete="off"><?php endif; ?></td></tr>
					<tr><th><label for="hc_key"><?php esc_html_e('API key', 'hub-client'); ?></label></th>
						<td><?php if (defined('HUB_CLIENT_KEY')) : ?><em><?php esc_html_e('Set in wp-config.php', 'hub-client'); ?></em>
						<?php else : ?><input id="hc_key" type="password" class="regular-text" name="<?php echo $n('api_key'); ?>" value="" placeholder="<?php echo $o['api_key'] ? esc_attr__('saved — leave empty to keep', 'hub-client') : esc_attr__('only if the hub requires keys', 'hub-client'); ?>" autocomplete="off"><?php endif; ?></td></tr>
					<tr><th><label for="hc_types"><?php esc_html_e('Content types to keep', 'hub-client'); ?></label></th>
						<td><input id="hc_types" type="text" class="regular-text" name="<?php echo $n('types'); ?>" value="<?php echo esc_attr($o['types']); ?>" placeholder="news,event">
						<p class="description"><?php esc_html_e('Hub content types that become articles here, comma-separated. Empty = everything for this site. Blocks like heroes and sidebars stay API-only.', 'hub-client'); ?></p></td></tr>
					<tr><th><label for="hc_langs"><?php esc_html_e('Languages to keep', 'hub-client'); ?></label></th>
						<td><input id="hc_langs" type="text" class="regular-text" name="<?php echo $n('langs'); ?>" value="<?php echo esc_attr($o['langs']); ?>" placeholder="hr,en">
						<p class="description"><?php esc_html_e('Empty = all hub languages. With Polylang on this site, versions are linked as translations.', 'hub-client'); ?></p></td></tr>
					<tr><th><?php esc_html_e('Store as', 'hub-client'); ?></th>
						<td>
							<label><input type="radio" name="<?php echo $n('store_as'); ?>" value="post" <?php checked($o['store_as'], 'post'); ?>> <?php esc_html_e('Posts (content types become categories)', 'hub-client'); ?></label><br>
							<label><input type="radio" name="<?php echo $n('store_as'); ?>" value="hub_article" <?php checked($o['store_as'], 'hub_article'); ?>> <?php esc_html_e('Own post type "Hub articles", URL base:', 'hub-client'); ?></label>
							<input type="text" class="small-text" style="width:10em" name="<?php echo $n('cpt_slug'); ?>" value="<?php echo esc_attr($o['cpt_slug']); ?>">
						</td></tr>
					<tr><th><label for="hc_author"><?php esc_html_e('Author', 'hub-client'); ?></label></th>
						<td><?php wp_dropdown_users(['name' => 'hub_client[author]', 'id' => 'hc_author', 'selected' => (int) $o['author'], 'capability' => ['edit_posts'], 'show_option_none' => __('First administrator', 'hub-client'), 'option_none_value' => 0]); ?></td></tr>
					<tr><th><?php esc_html_e('Calls to the hub', 'hub-client'); ?></th>
						<td>
							<p class="description" style="margin-top:0"><?php esc_html_e('Content is stored on this site and refreshed only when the hub reports a change (webhook). Visitors never cause a call to the hub.', 'hub-client'); ?></p>
							<label><input type="checkbox" name="hub_client[editor_fetch]" value="1" <?php checked(!empty($o['editor_fetch'])); ?>> <?php esc_html_e('Load a new section the first time an editor views it (e.g. right after adding it in Bricks)', 'hub-client'); ?></label><br>
							<label><input type="checkbox" name="hub_client[nightly]" value="1" <?php checked(!empty($o['nightly'])); ?>> <?php esc_html_e('Nightly safety check of articles (repairs missed webhooks; one set of calls per night)', 'hub-client'); ?></label><br>
							<label><input type="checkbox" name="hub_client[copy_images]" value="1" <?php checked(!empty($o['copy_images'])); ?>> <?php esc_html_e('Copy section images to this site\'s media library (own domain, own image sizes and optimiser; done when content is refreshed, never on page views)', 'hub-client'); ?></label><br>
							<label><input type="checkbox" name="hub_client[purge_cache]" value="1" <?php checked(!empty($o['purge_cache'])); ?>> <?php esc_html_e('Purge the page cache after a hub change (WP Rocket, LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Cache Enabler, Nginx Helper)', 'hub-client'); ?></label>
						</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e('Status', 'hub-client'); ?></h2>
			<p><?php printf(esc_html__('%1$d synced articles. Last full check: %2$s.', 'hub-client'), $count, $last ? esc_html(wp_date('Y-m-d H:i', $last + 60)) : esc_html__('never', 'hub-client')); ?></p>
			<?php $bs = HC_Blocks::stats(); ?>
			<p><?php printf(esc_html__('%1$d page sections in use, last refreshed %2$s%3$s.', 'hub-client'), $bs['sections'], $bs['last'] ? esc_html(wp_date('Y-m-d H:i', $bs['last'])) : esc_html__('never', 'hub-client'), $bs['errors'] ? ' — ' . sprintf(esc_html__('%d with a failed last refresh (previous content still shown)', 'hub-client'), $bs['errors']) : ''); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hub_client_test'), 'hub_client_test')); ?>"><?php esc_html_e('Test connection', 'hub-client'); ?></a>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hub_client_refresh'), 'hub_client_refresh')); ?>"><?php esc_html_e('Refresh sections now', 'hub-client'); ?></a>
				<a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hub_client_sync'), 'hub_client_sync')); ?>"><?php esc_html_e('Sync articles now', 'hub-client'); ?></a>
			</p>

			<?php $log = array_reverse((array) get_option(HC_Sync::LOG, [])); if ($log) : ?>
				<h2><?php esc_html_e('Recent activity', 'hub-client'); ?></h2>
				<table class="widefat striped" style="max-width:900px"><tbody>
				<?php foreach (array_slice($log, 0, 20) as $e) : ?>
					<tr><td style="width:140px"><?php echo esc_html(wp_date('Y-m-d H:i', (int) $e['t'])); ?></td><td style="width:90px"><?php echo esc_html($e['what']); ?></td><td style="width:70px"><?php echo $e['id'] ? '#' . (int) $e['id'] : ''; ?></td><td><?php echo esc_html($e['detail']); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
