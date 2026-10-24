<?php
defined('ABSPATH') || exit;

/**
 * Local copies of hub articles.
 *
 * Each hub item (one per language) becomes one local post, found again by
 * the _hub_id meta. Local copies are overwritten on every change on the hub,
 * so edit articles on the hub, not here.
 */
class HC_Sync {

	const LOG = 'hub_client_log';

	public static function init(): void {
		add_action('init', [__CLASS__, 'register_types']);
		add_action('init', [__CLASS__, 'schedule']);
		add_action('hub_client_reconcile', [__CLASS__, 'cron_reconcile']);
		add_action('hub_client_window', [__CLASS__, 'apply_windows']);
		add_action('admin_notices', [__CLASS__, 'edit_notice']);

		// SEO for articles shared with other sites (Canonical site on the hub).
		add_filter('get_canonical_url', [__CLASS__, 'seo_canonical'], 20, 2);
		add_filter('wpseo_canonical', [__CLASS__, 'seo_canonical'], 20);
		add_filter('rank_math/frontend/canonical', [__CLASS__, 'seo_canonical'], 20);
		add_filter('aioseo_canonical_url', [__CLASS__, 'seo_canonical'], 20);
		add_filter('seopress_titles_canonical', [__CLASS__, 'seopress_canonical'], 20);
		add_filter('wp_robots', [__CLASS__, 'seo_robots_core'], 20);
		add_filter('wpseo_robots', [__CLASS__, 'seo_robots_string'], 20);
		add_filter('rank_math/frontend/robots', [__CLASS__, 'seo_robots_rankmath'], 20);
		add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'sitemap_core'], 20);
		add_filter('wpseo_exclude_from_sitemap_by_post_ids', [__CLASS__, 'sitemap_ids'], 20);
	}

	/* ---------------- SEO: articles whose canonical home is another site ---------------- */

	/**
	 * For the current singular copy: ['url' => canonical URL or '', 'foreign' => bool].
	 * "Foreign" = the hub says another site is this article's canonical home.
	 */
	public static function canonical_info(?int $post_id = null): array {
		$post_id = $post_id ?? (is_singular() ? (int) get_queried_object_id() : 0);
		if (!$post_id || !get_post_meta($post_id, '_hub_id', true)) return ['url' => '', 'foreign' => false];
		$site = (string) get_post_meta($post_id, 'hub_canonical_site', true);
		$mine = (string) hub_client_opt('site');
		$foreign = $site !== '' && $site !== $mine;
		return ['url' => $foreign ? (string) get_post_meta($post_id, 'hub_canonical_url', true) : '', 'foreign' => $foreign];
	}

	/** Point the canonical link at the original site, when the hub knows its URL. */
	public static function seo_canonical($canonical, $post = null) {
		$id = $post instanceof WP_Post ? $post->ID : null;
		$c  = self::canonical_info($id);
		return $c['url'] !== '' ? $c['url'] : $canonical;
	}

	public static function seopress_canonical($html) {
		$c = self::canonical_info();
		return $c['url'] !== '' ? '<link rel="canonical" href="' . esc_url($c['url']) . '" />' : $html;
	}

	/** No URL known for the original → keep this copy out of search results instead. */
	private static function needs_noindex(): bool {
		$c = self::canonical_info();
		return $c['foreign'] && $c['url'] === '';
	}

	public static function seo_robots_core(array $robots): array {
		if (self::needs_noindex()) { $robots['noindex'] = true; $robots['follow'] = true; unset($robots['index']); }
		return $robots;
	}

	public static function seo_robots_string($robots) {
		return self::needs_noindex() ? 'noindex, follow' : $robots;
	}

	public static function seo_robots_rankmath($robots) {
		if (self::needs_noindex()) { $robots['index'] = 'noindex'; $robots['follow'] = 'follow'; }
		return $robots;
	}

	/** Copies whose canonical home is another site don't belong in this site's sitemap. */
	public static function foreign_ids(): array {
		static $ids = null;
		if ($ids !== null) return $ids;
		$mine = (string) hub_client_opt('site');
		$ids = array_map('intval', get_posts([
			'post_type' => self::post_type(), 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true,
			'meta_query' => [['key' => 'hub_canonical_site', 'compare' => 'EXISTS'], ['key' => 'hub_canonical_site', 'value' => [$mine, ''], 'compare' => 'NOT IN']],
		]));
		return $ids;
	}

	public static function sitemap_core(array $args): array {
		if (($args['post_type'] ?? '') === self::post_type() && ($ids = self::foreign_ids())) {
			$args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $ids);
		}
		return $args;
	}

	public static function sitemap_ids($ids): array {
		return array_merge((array) $ids, self::foreign_ids());
	}

	/* ---------------- Where copies live ---------------- */

	public static function post_type(): string {
		return hub_client_opt('store_as') === 'hub_article' ? 'hub_article' : 'post';
	}

	public static function type_taxonomy(): string {
		return self::post_type() === 'post' ? 'category' : 'hub_type';
	}

	public static function register_types(): void {
		if (hub_client_opt('store_as') !== 'hub_article') return;
		$slug = sanitize_title((string) hub_client_opt('cpt_slug')) ?: 'novosti';
		register_post_type('hub_article', [
			'labels'       => ['name' => __('Hub articles', 'hub-client'), 'singular_name' => __('Hub article', 'hub-client')],
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => true,
			'rewrite'      => ['slug' => $slug, 'with_front' => false],
			'menu_icon'    => 'dashicons-rss',
			'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
		]);
		register_taxonomy('hub_type', ['hub_article'], [
			'labels'            => ['name' => __('Article types', 'hub-client'), 'singular_name' => __('Article type', 'hub-client')],
			'public'            => true,
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => ['slug' => $slug . '-type'],
		]);
	}

	public static function schedule(): void {
		if (hub_client_opt('nightly')) {
			if (!wp_next_scheduled('hub_client_reconcile')) wp_schedule_event(time() + 600, 'daily', 'hub_client_reconcile');
		} elseif (wp_next_scheduled('hub_client_reconcile')) {
			wp_clear_scheduled_hook('hub_client_reconcile');
		}
		if (!wp_next_scheduled('hub_client_window')) wp_schedule_event(time() + 300, 'hourly', 'hub_client_window');
	}

	/* ---------------- Lookup ---------------- */

	public static function local_id(int $hub_id): int {
		$q = [
			'post_type'        => self::post_type(),
			'post_status'      => ['publish', 'draft', 'pending', 'future', 'private', 'trash'],
			'meta_key'         => '_hub_id',
			'meta_value'       => $hub_id,
			'fields'           => 'ids',
			'numberposts'      => 1,
			'suppress_filters' => true,
		];
		$ids = get_posts($q);
		return $ids ? (int) $ids[0] : 0;
	}

	/** Should this item exist here as an article? */
	public static function wanted(array $item): bool {
		$site = (string) hub_client_opt('site');
		if ($site !== '' && !in_array($site, (array) ($item['sites'] ?? []), true)) return false;
		$types = hub_client_csv('types');
		if ($types && !array_intersect($types, (array) ($item['content_types'] ?? []))) return false;
		$langs = hub_client_csv('langs');
		if ($langs && !in_array($item['lang'] ?? '', $langs, true)) return false;
		return true;
	}

	/* ---------------- Write ---------------- */

	/**
	 * Create or update the local copy of one hub item.
	 *
	 * @return string created|updated|unchanged|removed|skipped, or WP_Error
	 */
	public static function upsert(array $item, bool $force = false) {
		$hub_id = (int) ($item['id'] ?? 0);
		if (!$hub_id || empty($item['title'])) return new WP_Error('hc_item', 'Malformed item.');

		$local = self::local_id($hub_id);
		if (!self::wanted($item)) {
			if ($local && get_post_status($local) !== 'trash') { self::trash($local); return 'removed'; }
			return 'skipped';
		}
		// Came back on the hub (re-published, or the site was re-assigned): restore the old copy.
		if ($local && get_post_status($local) === 'trash') {
			wp_untrash_post($local);
			$force = true;
		}

		$status = !empty($item['visible']) || !isset($item['visible']) ? 'publish' : 'draft';
		if ($local && !$force
			&& get_post_meta($local, '_hub_modified', true) === (string) $item['modified']
			&& get_post_status($local) === $status) {
			return 'unchanged';
		}

		$date_gmt = !empty($item['date']) ? gmdate('Y-m-d H:i:s', strtotime($item['date'])) : current_time('mysql', true);
		$postarr  = [
			'post_type'     => self::post_type(),
			'post_status'   => $status,
			'post_title'    => (string) $item['title'],
			'post_content'  => (string) ($item['html'] ?? ''),
			'post_excerpt'  => (string) ($item['excerpt'] ?? ''),
			'post_name'     => sanitize_title((string) ($item['slug'] ?? '')),
			'post_date_gmt' => $date_gmt,
			'post_date'     => get_date_from_gmt($date_gmt),
			'post_author'   => self::author(),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		];
		if ($local) $postarr['ID'] = $local;

		// Content is stored as the hub rendered it. kses still applies for the
		// (anonymous) webhook context, so a compromised hub can't inject scripts.
		$id = $local ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);
		if (is_wp_error($id)) return $id;
		$id = (int) $id;

		$meta = [
			'_hub_id'            => $hub_id,
			'_hub_group'         => (string) ($item['group'] ?? ''),
			'_hub_lang'          => (string) ($item['lang'] ?? ''),
			'_hub_modified'      => (string) ($item['modified'] ?? ''),
			'_hub_show_from'     => (string) ($item['show_from'] ?? ''),
			'_hub_show_until'    => (string) ($item['show_until'] ?? ''),
			'hub_cta_url'        => esc_url_raw((string) ($item['cta_url'] ?? '')),
			'hub_cta_label'      => (string) ($item['cta_label'] ?? ''),
			'hub_canonical_site' => (string) ($item['canonical_site'] ?? ''),
			'hub_canonical_url'  => esc_url_raw((string) ($item['canonical_url'] ?? '')),
		];
		foreach ($meta as $k => $v) {
			($v === '' || $v === 0) ? delete_post_meta($id, $k) : update_post_meta($id, $k, $v);
		}

		// Hub content types → categories (post) or Article types (CPT).
		$terms = [];
		foreach ((array) ($item['content_types'] ?? []) as $slug) {
			$slug = sanitize_title($slug);
			$t = term_exists($slug, self::type_taxonomy());
			if (!$t) $t = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), self::type_taxonomy(), ['slug' => $slug]);
			if (!is_wp_error($t)) $terms[] = (int) $t['term_id'];
		}
		if ($terms) wp_set_object_terms($id, $terms, self::type_taxonomy(), false);

		self::sync_image($id, $item['image'] ?? null);
		self::link_language($id, $item);

		return $local ? 'updated' : 'created';
	}

	public static function remove_by_hub_id(int $hub_id): string {
		$local = self::local_id($hub_id);
		if (!$local || get_post_status($local) === 'trash') return 'skipped';
		self::trash($local);
		return 'removed';
	}

	private static function trash(int $id): void {
		if (get_post_status($id) !== 'trash') wp_trash_post($id);
	}

	private static function author(): int {
		$a = (int) hub_client_opt('author');
		if ($a && get_userdata($a)) return $a;
		$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID']);
		return $admins ? (int) $admins[0] : 0;
	}

	/** Download the image into this site's media library (once per image URL). */
	private static function sync_image(int $post_id, $img): void {
		$url = is_array($img) ? (string) ($img['full'] ?? $img['url'] ?? '') : '';
		if ($url === '') {
			delete_post_thumbnail($post_id);
			delete_post_meta($post_id, '_hub_image_src');
			return;
		}
		if (get_post_meta($post_id, '_hub_image_src', true) === $url && has_post_thumbnail($post_id)) return;

		$att = HC_Media::local_id($url, (string) ($img['alt'] ?? ''), $post_id);
		if (!$att) return;
		set_post_thumbnail($post_id, (int) $att);
		update_post_meta($post_id, '_hub_image_src', $url);
	}

	/** With Polylang on this site, set the language and link the versions. */
	private static function link_language(int $id, array $item): void {
		if (!function_exists('pll_set_post_language') || !function_exists('pll_languages_list')) return;
		$lang = (string) ($item['lang'] ?? '');
		if (!in_array($lang, (array) pll_languages_list(['fields' => 'slug']), true)) return;
		pll_set_post_language($id, $lang);
		$group = (string) ($item['group'] ?? '');
		if ($group === '') return;
		$siblings = get_posts([
			'post_type' => self::post_type(), 'post_status' => 'any', 'meta_key' => '_hub_group', 'meta_value' => $group,
			'fields' => 'ids', 'numberposts' => 20, 'lang' => '', 'suppress_filters' => true,
		]);
		$map = [];
		foreach ($siblings as $sid) {
			$l = (string) get_post_meta($sid, '_hub_lang', true);
			if ($l !== '' && in_array($l, (array) pll_languages_list(['fields' => 'slug']), true)) $map[$l] = (int) $sid;
		}
		if (count($map) > 1) pll_save_post_translations($map);
	}

	/* ---------------- Full / delta reconcile ---------------- */

	public static function cron_reconcile(): void {
		$r = self::reconcile(false);
		if (is_wp_error($r)) self::log('reconcile', 0, $r->get_error_message());
	}

	/**
	 * Pull everything changed since the last run (or everything with $full),
	 * then trash local copies the hub no longer has.
	 *
	 * @return array<string,int>|WP_Error
	 */
	public static function reconcile(bool $full = false) {
		$site = (string) hub_client_opt('site');
		if ($site === '') return new WP_Error('hc_config', __('Set this site\'s hub slug first.', 'hub-client'));

		$started = time();
		$since   = $full ? 0 : (int) get_option('hub_client_last_sync', 0);
		$langs   = hub_client_csv('langs');
		if (!$langs) {
			$meta = HC_API::get('/meta');
			if (is_wp_error($meta)) return $meta;
			$langs = (array) ($meta['data']['languages'] ?? []);
		}

		$stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => 0];
		foreach ($langs as $lang) {
			$page = 1;
			do {
				$q = ['site' => $site, 'lang' => $lang, 'fallback' => 'false', 'sync' => 'true', 'per_page' => 100, 'page' => $page, 'orderby' => 'modified'];
				if ($since) $q['modified_after'] = gmdate('c', $since);
				$res = HC_API::get('/content', $q);
				if (is_wp_error($res)) return $res;
				foreach ((array) $res['data'] as $item) {
					$r = self::upsert((array) $item, $full);
					is_wp_error($r) ? $stats['errors']++ : $stats[$r]++;
				}
				$pages = (int) ($res['headers']['x-wp-totalpages'] ?? 1);
				$page++;
			} while ($page <= $pages);
		}

		// Anything the hub no longer serves for this site goes to the trash.
		$ids = HC_API::get('/ids', ['site' => $site]);
		if (is_wp_error($ids)) return $ids;
		$live  = array_map('intval', (array) $ids['data']);
		$local = get_posts([
			'post_type' => self::post_type(), 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
			'meta_key' => '_hub_id', 'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true,
		]);
		foreach ($local as $lid) {
			if (!in_array((int) get_post_meta($lid, '_hub_id', true), $live, true)) {
				self::trash((int) $lid);
				$stats['removed']++;
			}
		}

		update_option('hub_client_last_sync', $started - 60, false);
		self::log('reconcile', 0, wp_json_encode($stats));
		return $stats;
	}

	/* ---------------- Visibility windows ---------------- */

	/** Hourly: publish or unpublish copies whose show_from / show_until has passed. */
	public static function apply_windows(): int {
		$changed = 0;
		$ids = get_posts([
			'post_type' => self::post_type(), 'post_status' => ['publish', 'draft'], 'fields' => 'ids', 'numberposts' => -1,
			'suppress_filters' => true,
			'meta_query' => ['relation' => 'OR', ['key' => '_hub_show_from', 'compare' => 'EXISTS'], ['key' => '_hub_show_until', 'compare' => 'EXISTS']],
		]);
		$now = time();
		foreach ($ids as $id) {
			$from  = (string) get_post_meta($id, '_hub_show_from', true);
			$until = (string) get_post_meta($id, '_hub_show_until', true);
			$vis   = ($from === '' || strtotime($from) <= $now) && ($until === '' || strtotime($until) >= $now);
			$want  = $vis ? 'publish' : 'draft';
			if (get_post_status($id) !== $want) {
				wp_update_post(['ID' => $id, 'post_status' => $want]);
				$changed++;
			}
		}
		return $changed;
	}

	/* ---------------- Page cache ---------------- */

	/**
	 * Purge this site's page cache after a hub change, so visitors get fully
	 * cached pages that are still current. Covers the common cache plugins;
	 * anything else can hook `hub_client_purge_cache`.
	 *
	 * @return string[] which caches were purged
	 */
	public static function purge_page_cache(): array {
		if (!hub_client_opt('purge_cache')) return [];
		$done = [];
		if (function_exists('rocket_clean_domain'))       { rocket_clean_domain(); $done[] = 'WP Rocket'; }
		if (has_action('litespeed_purge_all'))             { do_action('litespeed_purge_all'); $done[] = 'LiteSpeed Cache'; }
		if (function_exists('w3tc_flush_all'))             { w3tc_flush_all(); $done[] = 'W3 Total Cache'; }
		if (function_exists('wp_cache_clear_cache'))       { wp_cache_clear_cache(); $done[] = 'WP Super Cache'; }
		if (has_action('wpfc_clear_all_cache'))            { do_action('wpfc_clear_all_cache'); $done[] = 'WP Fastest Cache'; }
		if (function_exists('sg_cachepress_purge_cache'))  { sg_cachepress_purge_cache(); $done[] = 'SiteGround'; }
		if (has_action('breeze_clear_all_cache'))          { do_action('breeze_clear_all_cache'); $done[] = 'Breeze'; }
		if (has_action('cache_enabler_clear_complete_cache')) { do_action('cache_enabler_clear_complete_cache'); $done[] = 'Cache Enabler'; }
		if (has_action('rt_nginx_helper_purge_all'))       { do_action('rt_nginx_helper_purge_all'); $done[] = 'Nginx Helper'; }
		do_action('hub_client_purge_cache');
		return $done;
	}

	/* ---------------- Log + editor notice ---------------- */

	public static function log(string $what, int $id, string $detail): void {
		$log   = (array) get_option(self::LOG, []);
		$log[] = ['t' => time(), 'what' => $what, 'id' => $id, 'detail' => $detail];
		update_option(self::LOG, array_slice($log, -50), false);
	}

	public static function edit_notice(): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->base !== 'post') return;
		$id = (int) ($_GET['post'] ?? 0);
		if (!$id || !($hub_id = (int) get_post_meta($id, '_hub_id', true))) return;
		$link = untrailingslashit((string) hub_client_opt('hub_url')) . '/wp-admin/post.php?post=' . $hub_id . '&action=edit';
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
			esc_html__('This article is synced from the hub. Changes made here are overwritten on the next update.', 'hub-client'),
			esc_url($link),
			esc_html__('Edit it on the hub', 'hub-client')
		);
	}
}
