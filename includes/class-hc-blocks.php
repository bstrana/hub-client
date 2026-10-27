<?php
defined('ABSPATH') || exit;

/**
 * Hub content inside any page element, rendered on the server.
 *
 * Shortcodes (any builder, Gutenberg, widgets):
 *   [hub placement="home-hero" field="title"]
 *   [hub placement="home-blocks" index="2" field="excerpt"]
 *   [hub_image placement="home-hero" size="large" class="hero-img"]
 *   [hub_loop placement="home-blocks" limit="6"] <h3>[hub field="title"]</h3> [/hub_loop]
 *   [hub_loop type="news" limit="3"] <a href="[hub field="link"]">[hub field="title"]</a> [/hub_loop]
 *
 * Bricks dynamic tags (text, image and link fields):
 *   {hub:home-hero:title}   {hub:home-blocks:title:2}   {hub:home-hero:image}   {hub:home-hero:cta_url}
 *   {hub:type.news:title:1}  — newest news item
 *   {hub:post:data.weight}   — the current post (in a query loop: each post); post.<slug|ID> for another
 *
 * Without a source, shortcodes use the current post too: [hub_data], [hub field="subtitle"];
 * id="…" or slug="…" picks another post.
 *
 * Data comes from a local store that only the hub's webhooks refresh, so
 * visitors never cause a request to the hub. See the Data section below.
 */
class HC_Blocks {

	const FIELDS = [
		'title', 'subtitle', 'badge', 'excerpt', 'html', 'slug', 'id', 'lang', 'content_type',
		'cta_url', 'cta_label', 'cta2_url', 'cta2_label',
		'image', 'image_url', 'image_full', 'image_alt', 'image_focus',
		'date', 'link', 'tags',
		'event_start', 'event_end', 'event_date', 'event_time', 'location', 'map_lat', 'map_lng', 'map_url', 'stream_url',
		'seo_title', 'seo_description',
	];

	private static array $memo  = [];
	private static array $stack = []; // current [hub_loop] items

	public static function init(): void {
		add_shortcode('hub', [__CLASS__, 'sc_field']);
		add_shortcode('hub_image', [__CLASS__, 'sc_image']);
		add_shortcode('hub_loop', [__CLASS__, 'sc_loop']);
		add_shortcode('hub_link', [__CLASS__, 'sc_link']);
		add_shortcode('hub_map', [__CLASS__, 'sc_map']);
		add_shortcode('hub_lang_switch', [__CLASS__, 'sc_lang_switch']);
		add_shortcode('hub_data', [__CLASS__, 'sc_data']);
		add_action('init', [__CLASS__, 'visitor_lang_request']);
		add_action('template_redirect', [__CLASS__, 'maybe_nocache']);
		// WP Rocket: separate cache files per selected language.
		add_filter('rocket_cache_dynamic_cookies', fn($c) => hub_client_opt('lang_mode') === 'site' ? $c : array_merge((array) $c, ['hub_lang']));
		// Polylang's switcher on an untranslated page: stay on this page, switch the hub content.
		add_filter('pll_the_language_link', [__CLASS__, 'pll_switch_link'], 20, 2);
		add_action(self::EVENT_HOOK, [__CLASS__, 'on_event_ended']);

		// Bricks dynamic data. Harmless when Bricks isn't installed.
		add_filter('bricks/dynamic_tags_list', [__CLASS__, 'bricks_list']);
		add_filter('bricks/dynamic_data/render_tag', [__CLASS__, 'bricks_tag'], 20, 3);
		add_filter('bricks/dynamic_data/render_content', [__CLASS__, 'bricks_content'], 20, 3);
		add_filter('bricks/frontend/render_data', [__CLASS__, 'bricks_content'], 20, 2);
	}

	/* ---------------- Data: a local store, refreshed only when the hub says so ---------------- */
	//
	// Every distinct section query (site + placement/type + language + limit + order) is
	// listed in a small index option (`hub_client_blocks`: query, last seen, last fetched,
	// error, the hub IDs it shows). Its items live in their own option
	// (`hub_client_block_<id>`, not autoloaded), so a page view only loads the sections it
	// shows, and webhooks work from the index without loading any section data.
	// Page views only READ — visitors never cause a call to the hub.
	// The hub's webhook refreshes the queries a change can affect (refresh_for_item()).
	// A query nobody has fetched yet is fetched once when an editor views it (can be
	// switched off), or by "Refresh sections now" / `wp hub-client refresh`.

	const STORE = 'hub_client_blocks';
	const DATA  = 'hub_client_block_';

	/**
	 * The index. $fresh re-reads it from the database (before writing), so two webhooks
	 * at the same moment don't overwrite each other's changes.
	 * @return array<string, array{q:array,seen:int,fetched?:int,error?:string,ids?:int[],next_end?:int,count?:int}>
	 */
	private static function store(bool $fresh = false): array {
		if ($fresh) wp_cache_delete(self::STORE, 'options');
		$store = (array) get_option(self::STORE, []);
		// Older versions kept every section's items inside the index: move them out once.
		$moved = false;
		foreach ($store as $id => $e) {
			if (is_array($e) && array_key_exists('data', $e)) {
				self::put_data((string) $id, (array) $e['data']);
				$store[$id] = self::summarize($e, (array) $e['data']);
				$moved = true;
			}
		}
		if ($moved) update_option(self::STORE, $store, false);
		return $store;
	}

	/** Change one index entry (fresh read-modify-write; null removes it). */
	private static function update_entry(string $id, ?callable $change): void {
		$store = self::store(true);
		if ($change === null) {
			unset($store[$id]);
		} else {
			$store[$id] = $change($store[$id] ?? []);
		}
		update_option(self::STORE, $store, false);
	}

	private static function data(string $id): array {
		return (array) get_option(self::DATA . $id, []);
	}

	private static function put_data(string $id, array $items): void {
		update_option(self::DATA . $id, $items, false);
	}

	/** Index facts about a section's items, so nothing else needs to load them. */
	private static function summarize(array $entry, array $items): array {
		unset($entry['data']);
		$entry['ids']   = array_values(array_map('intval', array_column($items, 'id')));
		$entry['count'] = count($items);
		$next = 0;
		foreach ($items as $it) {
			$t = self::event_end_ts((array) $it);
			if ($t > time() && (!$next || $t < $next)) $next = $t;
		}
		$entry['next_end'] = $next;
		return $entry;
	}

	/**
	 * Language of hub content on this page:
	 *   1. lang="…" on the shortcode / tag
	 *   2. a page translated with Polylang/WPML (it has language versions): that page's language
	 *   3. any other page — setting "Section language":
	 *        visitor (default): the language the visitor selected — ?hub_lang=en, remembered in
	 *                 the hub_lang cookie (set by the Polylang switcher on such pages, or
	 *                 [hub_lang_switch]); else the site's language. Polylang's own pll_language
	 *                 cookie is NOT used: Polylang resets it to the page's language on every view.
	 *        site:    always the site's language
	 *   4. archives on a multilingual site: the language in the URL
	 *   5. the site's language: first of "Languages to keep", else the Polylang/WPML default
	 */
	public static function current_lang(string $attr = ''): string {
		if ($attr !== '') return sanitize_key($attr);
		static $memo = null;
		if ($memo !== null && did_action('wp')) return $memo;

		$id = 0;
		if (is_singular()) {
			$id = (int) get_queried_object_id();
		} elseif (!did_action('wp') || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
			$id = (int) get_the_ID(); // Bricks builder / REST render: the post being edited
		}
		$lang = '';
		if ($id) {
			[$own, $versions] = self::post_lang($id);
			if ($own !== '' && $versions > 1) {
				$lang = $own; // a translated page shows its own language
			} elseif (hub_client_opt('lang_mode') !== 'site') {
				$lang = self::visitor_lang();
			}
		} elseif (did_action('wp') && !is_admin()) {
			if (function_exists('pll_current_language') && ($l = pll_current_language('slug'))) $lang = (string) $l;
			$wpml = apply_filters('wpml_current_language', null);
			if ($lang === '' && is_string($wpml)) $lang = $wpml;
			if (hub_client_opt('lang_mode') !== 'site' && ($v = self::visitor_lang(false)) !== '') $lang = $v;
		}
		$lang = $lang !== '' ? $lang : self::site_lang();
		if (did_action('wp')) $memo = $lang;
		return $lang;
	}

	/** [language of the post, number of language versions] from Polylang / WPML. */
	private static function post_lang(int $id): array {
		if (function_exists('pll_get_post_language')) {
			$own = (string) (pll_get_post_language($id, 'slug') ?: '');
			$n   = function_exists('pll_get_post_translations') ? count(array_filter((array) pll_get_post_translations($id))) : 1;
			return [$own, $n];
		}
		$d = apply_filters('wpml_post_language_details', null, $id);
		if (is_array($d) && !empty($d['language_code'])) {
			$type = apply_filters('wpml_element_type', get_post_type($id));
			$trid = apply_filters('wpml_element_trid', null, $id, $type);
			$tr   = $trid ? (array) apply_filters('wpml_get_element_translations', null, $trid, $type) : [];
			return [(string) $d['language_code'], max(1, count($tr))];
		}
		return ['', 0];
	}

	/** Languages a visitor may pick: Languages to keep, else Polylang's / WPML's list. */
	public static function site_langs(): array {
		$langs = hub_client_csv('langs');
		if (!$langs && function_exists('pll_languages_list')) $langs = (array) pll_languages_list(['fields' => 'slug']);
		if (!$langs) {
			$w = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
			if (is_array($w)) $langs = array_keys($w);
		}
		return array_values(array_map('strval', $langs));
	}

	/**
	 * The visitor's selected language, or '' if none.
	 * ?hub_lang=xx (also stored in the hub_lang cookie) > hub_lang cookie.
	 */
	public static function visitor_lang(bool $use_cookies = true): string {
		$ok   = self::site_langs();
		$pick = fn($v) => ($v = sanitize_key((string) $v)) !== '' && (!$ok || in_array($v, $ok, true)) ? $v : '';
		if (isset($_GET['hub_lang']) && ($l = $pick(wp_unslash($_GET['hub_lang']))) !== '') return $l;
		if (!$use_cookies) return '';
		return isset($_COOKIE['hub_lang']) ? $pick(wp_unslash($_COOKIE['hub_lang'])) : '';
	}

	/** ?hub_lang=xx: remember the choice; pages in another language than the site's aren't page-cached. */
	public static function visitor_lang_request(): void {
		if (is_admin() || hub_client_opt('lang_mode') === 'site') return;
		$l = self::visitor_lang(false);
		if ($l !== '' && !headers_sent() && ($_COOKIE['hub_lang'] ?? '') !== $l) {
			setcookie('hub_lang', $l, ['expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax']);
			$_COOKIE['hub_lang'] = $l;
		}
	}

	/** After the page is known: if hub sections may follow the visitor, keep other languages out of the page cache. */
	public static function maybe_nocache(): void {
		if (is_admin() || hub_client_opt('lang_mode') === 'site') return;
		$v = self::visitor_lang();
		if ($v !== '' && $v !== self::site_lang() && !defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);           // WP Rocket, W3TC, WP Super Cache, Cache Enabler, …
			do_action('litespeed_control_set_nocache', 'hub client: visitor language');
			if (!headers_sent()) header('Cache-Control: private, no-cache');
		}
	}

	/** Polylang switcher link for a language this page has no translation in → same page, ?hub_lang=xx. */
	public static function pll_switch_link($url, $slug) {
		if (hub_client_opt('lang_mode') === 'site' || !is_singular()) return $url;
		$id = (int) get_queried_object_id();
		[$own, $versions] = self::post_lang($id);
		if ($versions > 1 && function_exists('pll_get_post') && pll_get_post($id, (string) $slug)) return $url; // a real translation exists
		$here = get_permalink($id);
		return add_query_arg('hub_lang', (string) $slug, $here); // also for the site language, so the cookie switches back
	}

	/**
	 * [hub_lang_switch] — links that switch hub content on this same page.
	 *   [hub_lang_switch]  [hub_lang_switch labels="hr:HR,en:EN" class="lang-switch"]
	 */
	public static function sc_lang_switch($atts): string {
		$a = shortcode_atts(['labels' => '', 'class' => 'hub-lang-switch', 'sep' => ' '], $atts, 'hub_lang_switch');
		$labels = [];
		foreach (array_filter(array_map('trim', explode(',', $a['labels']))) as $pair) {
			[$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
			if ($k !== '') $labels[sanitize_key($k)] = $v !== '' ? $v : strtoupper($k);
		}
		$cur = self::current_lang();
		$out = [];
		foreach (self::site_langs() as $l) {
			$url = add_query_arg('hub_lang', $l, remove_query_arg('hub_lang'));
			$out[] = sprintf('<a href="%s" hreflang="%s"%s>%s</a>', esc_url($url), esc_attr($l), $l === $cur ? ' class="current" aria-current="true"' : '', esc_html($labels[$l] ?? strtoupper($l)));
		}
		return $out ? '<nav class="' . esc_attr($a['class']) . '">' . implode(esc_html($a['sep']), $out) . '</nav>' : '';
	}

	/** This site's default language for hub content. */
	public static function site_lang(): string {
		$langs = hub_client_csv('langs');
		if ($langs) return $langs[0];
		// With Polylang/WPML the site language is their default language.
		if (function_exists('pll_default_language') && ($l = pll_default_language('slug'))) return (string) $l;
		$wpml = apply_filters('wpml_default_language', null);
		return is_string($wpml) ? $wpml : '';
	}

	/** Normalised API query for a section, or null if it names no source. */
	public static function normalize(array $q): ?array {
		$query = array_filter([
			'site'      => (string) hub_client_opt('site'),
			'placement' => sanitize_title((string) ($q['placement'] ?? '')),
			'type'      => sanitize_title((string) ($q['type'] ?? '')),
			'tag'       => self::tag_param((string) ($q['tag'] ?? '')),
			'lang'      => self::current_lang((string) ($q['lang'] ?? '')),
			'per_page'  => max(1, min(100, (int) ($q['limit'] ?? 10))),
			'orderby'   => in_array($q['orderby'] ?? '', ['order', 'date', 'modified', 'title', 'event'], true) ? $q['orderby'] : '',
			'upcoming'  => !empty($q['upcoming']) ? 'true' : '',
			'fallback'  => isset($q['fallback']) && !$q['fallback'] ? 'false' : '',
		], fn($v) => $v !== '' && $v !== null);
		if (empty($query['placement']) && empty($query['type']) && empty($query['tag'])) return null;
		if (!empty($query['upcoming']) && empty($query['orderby'])) $query['orderby'] = 'event';
		if ((!empty($query['type']) || !empty($query['tag'])) && empty($query['placement']) && empty($query['orderby'])) $query['orderby'] = 'date';
		ksort($query);
		return $query;
	}

	/** "a, b" → "a,b" (any of them); "a+b" → "a+b" (all of them). Slugs only. */
	public static function tag_param(string $raw): string {
		$raw = trim($raw);
		if ($raw === '') return '';
		$all   = str_contains($raw, '+');
		$slugs = array_values(array_filter(array_map('sanitize_title', preg_split('/[,+]/', $raw))));
		return implode($all ? '+' : ',', array_slice($slugs, 0, 5));
	}

	public static function key(array $query): string {
		return md5(wp_json_encode($query));
	}

	/**
	 * Items for one section — from the local store only (plus the one-time editor fetch).
	 *
	 * @param array{placement?:string,type?:string,lang?:string,limit?:int,orderby?:string,fallback?:bool} $q
	 */
	public static function items(array $q): array {
		$query = self::normalize($q);
		if (!$query) return [];
		$id = self::key($query);
		if (isset(self::$memo[$id])) { self::$last = self::$info[$id]; return self::$memo[$id]; }

		self::$last = ['query' => $query, 'error' => ''];
		if ((string) hub_client_opt('site') === '' || (string) hub_client_opt('hub_url') === '') {
			self::$last['error'] = 'Hub URL or "This site on the hub" is not set (Settings → Hub client).';
			self::$info[$id] = self::$last;
			return self::$memo[$id] = [];
		}

		$stored = self::register($id, $query);
		if (!isset($stored['fetched'])) {
			// Never fetched. Only an editor's page view may fetch it now; visitors wait for the hub.
			if (hub_client_opt('editor_fetch') && is_user_logged_in() && current_user_can('edit_posts')) {
				$r = self::fetch($id, $query);
				$stored = self::store(true)[$id] ?? [];
				if (is_wp_error($r)) self::$last['error'] = $r->get_error_message();
			} else {
				self::$last['error'] = 'not loaded yet — it arrives with the next change on the hub, or use Settings → Hub client → "Refresh sections now".';
			}
		} elseif (!empty($stored['error'])) {
			self::$last['error'] = 'last refresh failed (' . $stored['error'] . '); showing the previous content';
		}
		$data = isset($stored['fetched']) ? self::data($id) : [];
		if (!empty($query['upcoming'])) {
			// The stored list is from the last hub change; drop events that have ended since.
			$data = array_values(array_filter($data, fn($it) => !self::event_ended($it)));
		}
		self::$info[$id] = self::$last;
		return self::$memo[$id] = $data;
	}

	/** Remember that a page uses this query, so webhooks keep it current. Returns its index entry. */
	private static function register(string $id, array $query): array {
		$entry = self::store()[$id] ?? null;
		if ($entry && (time() - (int) ($entry['seen'] ?? 0)) < DAY_IN_SECONDS) return $entry; // write at most daily
		self::update_entry($id, fn($e) => array_merge($e, ['q' => $query, 'seen' => time()]));
		return self::store()[$id] ?? [];
	}

	/**
	 * One call to the hub for one query; result stored. On failure the previous
	 * content stays and the error is noted.
	 *
	 * @return true|WP_Error
	 */
	public static function fetch(string $id, array $query, string $bust = '') {
		$q = $query;
		if ($bust !== '') $q['_v'] = $bust; // unique URL: never served from Cloudflare's edge cache
		$res = HC_API::get('/content', $q);
		if (is_wp_error($res)) {
			$msg = $res->get_error_message();
			self::update_entry($id, fn($e) => array_merge(['q' => $query, 'seen' => time()], $e, ['error' => $msg])); // previous data stays
			HC_Sync::log('blocks', 0, $msg);
			return $res;
		}
		// Images are copied and HTML is cleaned here, at refresh time — never during a page view.
		$items = HC_Media::localize_items(is_array($res['data']) ? array_values(array_filter($res['data'], 'is_array')) : []);
		foreach ($items as &$it) {
			if (isset($it['html'])) $it['html'] = wp_kses_post((string) $it['html']);
			$it['_safe'] = 1;
		}
		unset($it);
		self::put_data($id, $items);
		self::update_entry($id, fn($e) => self::summarize(array_merge(['q' => $query, 'seen' => time()], $e, ['fetched' => time(), 'error' => '']), $items));
		unset(self::$memo[$id]);
		if (!empty($query['upcoming'])) self::schedule_event_purge();
		return true;
	}

	/* ---------------- Upcoming events: drop ended ones without asking the hub ---------------- */

	const EVENT_HOOK = 'hub_client_event_ended';

	/** When an event stops being "upcoming": its end, or the end of its start day. */
	public static function event_end_ts(array $item): int {
		$end = strtotime((string) ($item['event_end'] ?? ''));
		if ($end) return $end;
		$start = strtotime((string) ($item['event_start'] ?? ''));
		if (!$start) return 0;
		return (int) (new DateTimeImmutable('@' . $start))->setTimezone(wp_timezone())->setTime(23, 59, 59)->getTimestamp();
	}

	public static function event_ended(array $item): bool {
		$t = self::event_end_ts($item);
		return $t > 0 && $t < time();
	}

	/**
	 * One local timer at the next moment an event in an "upcoming" section ends, so the
	 * page cache is purged then and visitors stop seeing it. No call to the hub.
	 */
	public static function schedule_event_purge(): void {
		$next = 0;
		foreach (self::store() as $e) {
			if (empty($e['q']['upcoming'])) continue;
			$t = (int) ($e['next_end'] ?? 0);
			if ($t > time() && (!$next || $t < $next)) $next = $t;
		}
		$scheduled = wp_next_scheduled(self::EVENT_HOOK);
		if ($scheduled && (!$next || $scheduled !== $next + 60)) wp_unschedule_event($scheduled, self::EVENT_HOOK);
		if ($next && wp_next_scheduled(self::EVENT_HOOK) !== $next + 60) wp_schedule_single_event($next + 60, self::EVENT_HOOK);
	}

	public static function on_event_ended(): void {
		if (hub_client_opt('purge_cache')) HC_Sync::purge_page_cache();
		self::schedule_event_purge();
	}

	/**
	 * Called by the webhook: refresh only the sections this change can affect —
	 * those showing the item now, and those whose placement or type it has.
	 *
	 * @param array|null $item The item as the hub serves it now (null if gone).
	 * @return int number of hub calls made
	 */
	public static function refresh_for_item(int $hub_id, ?array $item, string $bust): int {
		$calls = 0;
		foreach (self::live_registry() as $id => $entry) {
			$q      = $entry['q'];
			$shows  = in_array($hub_id, (array) ($entry['ids'] ?? []), true);
			$fits   = $item && (
				(!empty($q['placement']) && in_array($q['placement'], (array) ($item['placements'] ?? []), true)) ||
				(!empty($q['type']) && empty($q['placement']) && in_array($q['type'], (array) ($item['content_types'] ?? []), true)) ||
				(!empty($q['tag']) && empty($q['placement']) && empty($q['type']) && array_intersect(preg_split('/[,+]/', $q['tag']), (array) ($item['tags'] ?? [])))
			);
			if ($shows || $fits) {
				self::fetch($id, $q, $bust);
				$calls++;
			}
		}
		return $calls;
	}

	/** Refresh every section in use (manual button, CLI, after changing settings). */
	public static function refresh_all(): array {
		$ok = 0; $fail = 0;
		$bust = (string) time();
		foreach (self::live_registry() as $id => $entry) {
			is_wp_error(self::fetch($id, $entry['q'], $bust)) ? $fail++ : $ok++;
		}
		HC_Meta::refresh(); // keep the Bricks picker / cheat sheet lists current too
		return ['refreshed' => $ok, 'failed' => $fail];
	}

	/** Registry minus queries no page has used for 60 days (their stored data is deleted). */
	public static function live_registry(): array {
		$store = self::store();
		$live  = array_filter($store, fn($e) => (time() - (int) ($e['seen'] ?? 0)) <= 60 * DAY_IN_SECONDS
			&& ($e['q']['site'] ?? '') === (string) hub_client_opt('site'));
		foreach (array_diff_key($store, $live) as $id => $e) {
			delete_option(self::DATA . $id);
			self::update_entry((string) $id, null);
		}
		return $live;
	}

	/** For the settings screen. */
	public static function stats(): array {
		$store = self::store();
		$last = 0; $errors = 0;
		foreach ($store as $e) { $last = max($last, (int) ($e['fetched'] ?? 0)); if (!empty($e['error'])) $errors++; }
		return ['sections' => count($store), 'last' => $last, 'errors' => $errors];
	}

	/** What the last items() call asked for and what went wrong, for the admin hint. */
	private static array $last = ['query' => [], 'error' => ''];
	private static array $info = [];

	/**
	 * Empty output explained — only to logged-in administrators, never to visitors.
	 * Shown as a small red note in the Bricks builder and on the front end.
	 */
	public static function hint(string $what): string {
		if (!current_user_can('manage_options') || !apply_filters('hub_client_admin_hints', true)) return '';
		$q   = self::$last['query'];
		$why = self::$last['error'] !== ''
			? self::$last['error']
			: sprintf('nothing found for %s (site "%s", language "%s")',
				!empty($q['placement']) ? 'placement "' . $q['placement'] . '"' : 'type "' . ($q['type'] ?? '') . '"',
				$q['site'] ?? '', $q['lang'] ?? 'default');
		return '<span class="hub-admin-hint" style="display:inline-block;font:12px/1.4 system-ui,sans-serif;color:#b32d2e;background:#fcf0f1;border:1px dashed #b32d2e;padding:2px 6px;border-radius:3px">'
			. esc_html("Hub {$what}: {$why}") . ' <small>(only admins see this)</small></span>';
	}

	/** "title" → "title"; "data.Weight" / "html.weight" → "data.weight" (values from "@name:" lines). */
	public static function field_key(string $f): string {
		$f = strtolower(trim($f));
		if (preg_match('/^(?:data|html)\.([a-z][a-z0-9_-]{0,40})$/', $f, $m)) return 'data.' . str_replace('-', '_', $m[1]);
		return sanitize_key($f);
	}

	/** One field of one item, ready to print. */
	public static function value(array $item, string $field, array $a = []): string {
		if (str_starts_with($field, 'data.')) {
			$data = (array) ($item['data'] ?? []);
			return esc_html((string) ($data[substr($field, 5)] ?? ''));
		}
		switch ($field) {
			case 'title':
			case 'subtitle':
			case 'badge':
			case 'excerpt':
			case 'cta_label':
			case 'cta2_label':
			case 'location':
			case 'seo_title':
			case 'seo_description':
			case 'slug':
			case 'lang':
			case 'content_type':
				return esc_html((string) ($item[$field] ?? ''));
			case 'tags':
				return esc_html(implode(', ', (array) ($item['tag_names'] ?? [])));
			case 'cta2_url':
			case 'stream_url':
			case 'map_url':
				return esc_url((string) ($item[$field] ?? ''));
			case 'map_lat':
			case 'map_lng':
				return isset($item[$field]) && is_numeric($item[$field]) ? (string) (float) $item[$field] : '';
			case 'image_focus':
				// CSS object-position; "center" when not set, so it can go straight into a style.
				return esc_attr((string) ($item['image_focus'] ?? '') ?: 'center');
			case 'event_start':
			case 'event_end':
			case 'event_date':
			case 'event_time':
				$src = $field === 'event_end' ? 'event_end' : 'event_start';
				$ts  = strtotime((string) ($item[$src] ?? ''));
				if (!$ts) return '';
				$fmt = (string) ($a['format'] ?? '');
				if ($fmt === '') {
					$fmt = $field === 'event_date' ? get_option('date_format')
						: ($field === 'event_time' ? get_option('time_format') : get_option('date_format') . ' ' . get_option('time_format'));
				}
				return esc_html(wp_date($fmt, $ts));
			case 'id':
				return (string) (int) ($item['id'] ?? 0);
			case 'html':
				// Cleaned when it was fetched; older stored data is cleaned here.
				return !empty($item['_safe']) ? (string) ($item['html'] ?? '') : wp_kses_post((string) ($item['html'] ?? ''));
			case 'cta_url':
				return esc_url((string) ($item['cta_url'] ?? ''));
			case 'image':
			case 'image_url':
				if ($lid = self::local_image($item)) return esc_url((string) wp_get_attachment_image_url($lid, 'large'));
				return esc_url((string) ($item['image']['url'] ?? $item['image_url'] ?? ''));
			case 'image_full':
				if ($lid = self::local_image($item)) return esc_url((string) wp_get_attachment_url($lid));
				return esc_url((string) ($item['image']['full'] ?? ''));
			case 'image_alt':
				return esc_attr((string) ($item['image']['alt'] ?? ''));
			case 'date':
				$ts = strtotime((string) ($item['date'] ?? ''));
				return $ts ? esc_html(wp_date((string) ($a['format'] ?? get_option('date_format')), $ts)) : '';
			case 'link':
				// The local article page, when Hub Client keeps a copy of this item.
				$local = (int) ($item['post_id'] ?? 0) ?: (class_exists('HC_Sync') ? HC_Sync::local_id((int) ($item['id'] ?? 0)) : 0);
				return $local && get_post_status($local) === 'publish' ? esc_url(get_permalink($local)) : '';
		}
		return '';
	}

	/** This site's copy of the item's image, if one was made and still exists. */
	public static function local_image(array $item): int {
		$id = (int) ($item['image']['local_id'] ?? 0);
		return $id && wp_attachment_is_image($id) ? $id : 0;
	}

	/* ---------------- Shortcodes ---------------- */

	/**
	 * Which item a shortcode shows:
	 *   1. id="…" / slug="…"           that post on this site (a copy of a hub item)
	 *   2. placement / type / tag      that hub section (index="2" for the 2nd item)
	 *   3. inside [hub_loop]           the loop's current item
	 *   4. nothing given               the current post — the one being shown, or the current
	 *                                  post of a query loop (e.g. each player in a Bricks loop)
	 */
	private static function pick(array $a): ?array {
		if (($a['id'] ?? '') !== '' || ($a['slug'] ?? '') !== '') {
			return self::post_item(self::find_post((string) ($a['id'] ?? ''), (string) ($a['slug'] ?? '')), (string) ($a['lang'] ?? ''));
		}
		if (empty($a['placement']) && empty($a['type']) && empty($a['tag'])) {
			return self::$stack ? end(self::$stack) : self::post_item(self::current_post(), (string) ($a['lang'] ?? ''));
		}
		// Same limit for every index so all fields of one placement share one cached request.
		$items = self::items(['placement' => $a['placement'], 'type' => $a['type'], 'tag' => (string) ($a['tag'] ?? ''), 'lang' => $a['lang'], 'limit' => max(10, (int) $a['index']),
			'upcoming' => in_array((string) ($a['upcoming'] ?? ''), ['1', 'true', 'yes'], true)]);
		return $items[max(1, (int) $a['index']) - 1] ?? null;
	}

	public static function sc_field($atts): string {
		$a = shortcode_atts(['id' => '', 'slug' => '', 'placement' => '', 'type' => '', 'tag' => '', 'field' => 'title', 'index' => 1, 'lang' => '', 'upcoming' => '', 'format' => '', 'default' => ''], $atts, 'hub');
		$item = self::pick($a);
		$out  = $item ? self::value($item, self::field_key($a['field']), array_filter(['format' => $a['format']])) : '';
		if ($out !== '') return $out;
		if ($a['default'] !== '') return esc_html($a['default']);
		// An item without this field (e.g. no button) is normal; a missing item is worth a hint.
		return $item ? '' : self::hint('[hub field="' . sanitize_key($a['field']) . '"]');
	}

	public static function sc_image($atts): string {
		$a = shortcode_atts(['id' => '', 'slug' => '', 'placement' => '', 'type' => '', 'tag' => '', 'index' => 1, 'lang' => '', 'upcoming' => '', 'size' => 'large', 'class' => '', 'loading' => 'lazy', 'sizes' => '100vw'], $atts, 'hub_image');
		$item  = self::pick($a);
		$img   = $item['image'] ?? null;
		if (!$img) return $item ? '' : self::hint('[hub_image]');
		$focus = (string) ($item['image_focus'] ?? '');
		$focus = preg_match('/^[a-z0-9% .]+$/', $focus) ? $focus : '';
		if ($lid = self::local_image($item)) {
			$attr = ['class' => $a['class'], 'loading' => $a['loading'] === 'eager' ? 'eager' : 'lazy', 'decoding' => 'async', 'alt' => (string) ($img['alt'] ?? '')];
			if ($focus !== '') $attr['style'] = 'object-position:' . $focus;
			if ($a['loading'] === 'eager') $attr['fetchpriority'] = 'high';
			if ($a['sizes'] !== '100vw') $attr['sizes'] = $a['sizes'];
			return wp_get_attachment_image($lid, sanitize_key($a['size']) ?: 'large', false, $attr);
		}
		$size = sanitize_key($a['size']);
		$src  = $size === 'full' ? ($img['full'] ?? '') : ($img['sizes'][$size] ?? $img['url'] ?? '');
		if (!$src) return '';
		return sprintf(
			'<img src="%s"%s alt="%s"%s class="%s" loading="%s" decoding="async"%s>',
			esc_url($src),
			!empty($img['srcset']) ? ' srcset="' . esc_attr($img['srcset']) . '" sizes="' . esc_attr($a['sizes']) . '"' : '',
			esc_attr($img['alt'] ?? ''),
			!empty($img['width']) && $size === 'full' ? ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"' : '',
			esc_attr($a['class']),
			$a['loading'] === 'eager' ? 'eager' : 'lazy',
			$focus !== '' ? ' style="object-position:' . esc_attr($focus) . '"' : ''
		);
	}

	/**
	 * A complete link, so no shortcode has to sit inside an href="…" attribute.
	 *   [hub_link placement="home-hero" class="btn"]                → button: cta_url + cta_label
	 *   [hub_link placement="home-hero" to="cta2" class="btn-2"]     → second button
	 *   [hub_link to="article" text="Pročitaj više"]                 → this site's copy of the article
	 *   [hub_link type="event" to="stream" text="Gledaj uživo"]      → live stream
	 *   [hub_link type="event" to="map" text="Upute"]                → directions (Google Maps)
	 *   [hub_link to="cta" text="title"]                             → link text from another field
	 * Always self-closing (no [/hub_link]), so it can't swallow the content after it.
	 */
	public static function sc_link($atts): string {
		$a = shortcode_atts(['id' => '', 'slug' => '', 'placement' => '', 'type' => '', 'tag' => '', 'index' => 1, 'lang' => '', 'upcoming' => '', 'to' => 'cta', 'text' => '', 'class' => '', 'target' => ''], $atts, 'hub_link');
		$item = self::pick($a);
		if (!$item) return self::hint('[hub_link]');
		// to => [link field, default text field, fallback text]
		$targets = [
			'cta'     => ['cta_url', 'cta_label', ''],
			'cta2'    => ['cta2_url', 'cta2_label', ''],
			'article' => ['link', 'title', ''],
			'stream'  => ['stream_url', '', __('Watch live', 'hub-client')],
			'map'     => ['map_url', 'location', __('Directions', 'hub-client')],
		];
		[$href_field, $text_field, $fallback] = $targets[$a['to']] ?? $targets['cta'];
		$href = self::value($item, $href_field);
		if ($href === '') return '';
		if ($a['text'] !== '' && (in_array($a['text'], self::FIELDS, true) || str_starts_with(self::field_key($a['text']), 'data.'))) {
			$label = self::value($item, self::field_key($a['text']));
		} elseif ($a['text'] !== '') {
			$label = esc_html($a['text']);
		} else {
			$label = ($text_field !== '' ? self::value($item, $text_field) : '') ?: esc_html($fallback !== '' ? $fallback : $href);
		}
		$blank = $a['target'] === '_blank' || ($a['target'] === '' && in_array($a['to'], ['stream', 'map'], true));
		return sprintf('<a href="%s"%s%s>%s</a>', $href, $a['class'] !== '' ? ' class="' . esc_attr($a['class']) . '"' : '',
			$blank ? ' target="_blank" rel="noopener"' : '', $label);
	}

	/**
	 * A map of the item's pin, embedded (lazy-loaded), with a directions link.
	 *   [hub_map type="event"]   [hub_map placement="contact" height="400" provider="google"]
	 * provider="osm" (default, OpenStreetMap: no cookies) or "google". link="" hides the link.
	 */
	public static function sc_map($atts): string {
		$a = shortcode_atts(['id' => '', 'slug' => '', 'placement' => '', 'type' => '', 'tag' => '', 'index' => 1, 'lang' => '', 'upcoming' => '', 'height' => 320, 'provider' => 'osm', 'class' => 'hub-map', 'link' => __('Directions', 'hub-client')], $atts, 'hub_map');
		$item = self::pick($a);
		if (!$item) return self::hint('[hub_map]');
		$lat = isset($item['map_lat']) && is_numeric($item['map_lat']) ? (float) $item['map_lat'] : null;
		$lng = isset($item['map_lng']) && is_numeric($item['map_lng']) ? (float) $item['map_lng'] : null;
		$loc = (string) ($item['location'] ?? '');
		$h   = max(120, min(1200, (int) $a['height']));
		$src = '';
		if ($a['provider'] === 'google') {
			$q   = $lat !== null && $lng !== null ? "$lat,$lng" : $loc;
			$src = $q !== '' ? 'https://maps.google.com/maps?q=' . rawurlencode($q) . '&z=15&output=embed' : '';
		} elseif ($lat !== null && $lng !== null) {
			$d   = 0.006;
			$src = sprintf('https://www.openstreetmap.org/export/embed.html?bbox=%F%%2C%F%%2C%F%%2C%F&layer=mapnik&marker=%F%%2C%F', $lng - $d, $lat - $d / 1.6, $lng + $d, $lat + $d / 1.6, $lat, $lng);
		}
		$out = '';
		if ($src !== '') {
			$out .= sprintf('<iframe src="%s" title="%s" loading="lazy" referrerpolicy="no-referrer-when-downgrade" style="border:0;width:100%%;height:%dpx" allowfullscreen></iframe>',
				esc_url($src), esc_attr($loc !== '' ? $loc : __('Map', 'hub-client')), $h);
		}
		$url = self::value($item, 'map_url');
		if ($a['link'] !== '' && $url !== '') {
			$out .= sprintf('<a class="hub-map-link" href="%s" target="_blank" rel="noopener">%s</a>', $url, esc_html($a['link']));
		}
		if ($out === '') return '';
		return '<div class="' . esc_attr($a['class']) . '">' . $out . '</div>';
	}

	/**
	 * All (or some) "@name: value" data points of one item, as a list.
	 *
	 * Which item: inside [hub_loop] the current one; with placement / type / tag the picked one;
	 * otherwise the current post — e.g. each player in a Bricks query loop over a hub post type.
	 *
	 *   [hub_data]                                     every data point, as <dl>
	 *   [hub_data keys="position,height,weight"]       only these, in this order
	 *   [hub_data exclude="internal_note"]             all but these
	 *   [hub_data labels="height:Visina,weight:Težina" wrap="table"]
	 *   [hub_data key="weight"]                        one value, nothing around it
	 *   [hub_data wrap="div" class="stats"]<span class="s"><b>{label}</b> {value}</span>[/hub_data]
	 *
	 * wrap: dl (default) | ul | table | div | none. Labels default to the name ("price_month" → "Price month").
	 */
	public static function sc_data($atts, $content = ''): string {
		$a = shortcode_atts(['id' => '', 'slug' => '', 'placement' => '', 'type' => '', 'tag' => '', 'index' => 1, 'lang' => '', 'upcoming' => '',
			'key' => '', 'keys' => '', 'exclude' => '', 'labels' => '', 'wrap' => 'dl', 'class' => 'hub-data', 'empty' => '', 'show_empty' => ''], $atts, 'hub_data');
		$data = self::data_for($a);

		$norm = fn($k) => str_replace('-', '_', sanitize_key((string) $k));
		if ($a['key'] !== '') return esc_html((string) ($data[$norm($a['key'])] ?? ''));

		if ($a['keys'] !== '') {
			$pick = [];
			foreach (array_map($norm, explode(',', $a['keys'])) as $k) if ($k !== '' && array_key_exists($k, $data)) $pick[$k] = $data[$k];
			$data = $pick;
		}
		foreach (array_map($norm, array_filter(explode(',', $a['exclude']))) as $k) unset($data[$k]);
		if ($a['show_empty'] === '') $data = array_filter($data, fn($v) => trim((string) $v) !== '');
		if (!$data) return $a['empty'] !== '' ? esc_html($a['empty']) : '';

		$labels = [];
		foreach (array_filter(array_map('trim', explode(',', $a['labels']))) as $pair) {
			[$k, $l] = array_pad(explode(':', $pair, 2), 2, '');
			if ($k !== '' && $l !== '') $labels[$norm($k)] = $l;
		}
		$label = fn($k) => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k));

		$wrap = in_array($a['wrap'], ['dl', 'ul', 'table', 'div', 'none'], true) ? $a['wrap'] : 'dl';
		$rows = '';
		foreach ($data as $k => $v) {
			$L = esc_html($label($k)); $V = esc_html((string) $v); $K = esc_attr($k);
			if (trim((string) $content) !== '') {
				$rows .= str_replace(['{key}', '{label}', '{value}'], [$K, $L, $V], (string) $content);
			} elseif ($wrap === 'table') {
				$rows .= "<tr class=\"hub-data-$K\"><th>$L</th><td>$V</td></tr>";
			} elseif ($wrap === 'ul') {
				$rows .= "<li class=\"hub-data-$K\"><span class=\"hub-data-label\">$L</span> <span class=\"hub-data-value\">$V</span></li>";
			} elseif ($wrap === 'dl') {
				$rows .= "<dt class=\"hub-data-$K\">$L</dt><dd class=\"hub-data-$K\">$V</dd>";
			} else {
				$rows .= "<span class=\"hub-data-item hub-data-$K\"><span class=\"hub-data-label\">$L</span> <span class=\"hub-data-value\">$V</span></span> ";
			}
		}
		if ($wrap === 'none') return $rows;
		$tag = $wrap === 'table' ? 'table' : $wrap;
		$inner = $wrap === 'table' ? "<tbody>$rows</tbody>" : $rows;
		return '<' . $tag . ' class="' . esc_attr($a['class']) . '">' . $inner . '</' . $tag . '>';
	}

	/** The data points for [hub_data] (same item choice as every other shortcode). */
	private static function data_for(array $a): array {
		$item = self::pick($a);
		return array_map('strval', (array) ($item['data'] ?? []));
	}

	/* ---------------- Items from this site's posts ---------------- */

	/** Post that "the current post" means right now (set by Bricks for its query loops). */
	private static int $post_ctx = 0;

	public static function current_post(): int {
		if (self::$post_ctx) return self::$post_ctx;
		$id = (int) get_the_ID();
		if (!$id && is_singular()) $id = (int) get_queried_object_id();
		return $id;
	}

	/** A copy of a hub item on this site, by post ID or slug (any of the hub post types). */
	public static function find_post(string $id, string $slug): int {
		if ($id !== '') {
			$id = (int) $id;
			return $id > 0 && get_post_meta($id, '_hub_id', true) ? $id : 0;
		}
		$slug = sanitize_title($slug);
		if ($slug === '' || !class_exists('HC_Sync')) return 0;
		static $memo = [];
		if (isset($memo[$slug])) return $memo[$slug];
		$q = ['name' => $slug, 'post_type' => HC_Sync::post_types(), 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_hub_id', 'no_found_rows' => true];
		// Same slug in several languages (Polylang): prefer the current one, else any.
		$found = get_posts($q + ['suppress_filters' => false]) ?: get_posts($q + ['suppress_filters' => true, 'lang' => '']);
		return $memo[$slug] = (int) ($found[0] ?? 0);
	}

	/**
	 * A local copy as a hub item, so every field works on it: [hub field="subtitle"],
	 * [hub_image], [hub_link to="cta"], [hub_map], [hub_data] … Only copies of hub items
	 * (posts with _hub_id); other posts give null. lang="xx" switches to that translation.
	 */
	public static function post_item(int $id, string $lang = ''): ?array {
		if (!$id || !($hub_id = (int) get_post_meta($id, '_hub_id', true))) return null;
		if ($lang !== '' && function_exists('pll_get_post') && ($t = (int) pll_get_post($id, sanitize_key($lang)))) $id = $t;
		$p = get_post($id);
		if (!$p) return null;
		$m   = fn(string $k) => (string) get_post_meta($id, $k, true);
		$iso = fn(string $local) => $local !== '' ? get_gmt_from_date($local, 'Y-m-d\TH:i:s\Z') : '';
		$data = [];
		foreach (array_filter(explode(',', $m('_hub_data_keys'))) as $k) $data[$k] = $m('hub_data_' . $k);
		$image = null;
		if ($thumb = (int) get_post_thumbnail_id($id)) {
			$image = ['local_id' => $thumb, 'alt' => (string) get_post_meta($thumb, '_wp_attachment_image_alt', true),
				'url' => (string) wp_get_attachment_image_url($thumb, 'large'), 'full' => (string) wp_get_attachment_url($thumb)];
		}
		$tags = class_exists('HC_Sync') ? get_the_terms($id, HC_Sync::tag_taxonomy($p->post_type)) : [];
		$types = array_filter(explode(',', $m('_hub_types')));
		return [
			'id' => $hub_id, 'post_id' => $id, 'title' => get_the_title($p), 'slug' => $p->post_name,
			'subtitle' => $m('hub_subtitle'), 'badge' => $m('hub_badge'), 'excerpt' => $p->post_excerpt,
			'html' => $p->post_content, 'lang' => $m('_hub_lang'), 'content_type' => (string) ($types[0] ?? ''),
			'date' => $p->post_date_gmt !== '0000-00-00 00:00:00' ? str_replace(' ', 'T', $p->post_date_gmt) . 'Z' : '',
			'image' => $image, 'image_focus' => $m('hub_image_focus'),
			'cta_url' => $m('hub_cta_url'), 'cta_label' => $m('hub_cta_label'), 'cta2_url' => $m('hub_cta2_url'), 'cta2_label' => $m('hub_cta2_label'),
			'event_start' => $iso($m('hub_event_start')), 'event_end' => $iso($m('hub_event_end')),
			'location' => $m('hub_location'), 'map_lat' => $m('hub_map_lat'), 'map_lng' => $m('hub_map_lng'),
			'map_url' => $m('hub_map_url'), 'stream_url' => $m('hub_stream_url'),
			'seo_title' => $m('hub_seo_title'), 'seo_description' => $m('hub_seo_description'),
			'tag_names' => is_array($tags) ? wp_list_pluck($tags, 'name') : [],
			'data' => $data,
		];
	}

	public static function sc_loop($atts, $content = ''): string {
		$a = shortcode_atts(['placement' => '', 'type' => '', 'tag' => '', 'lang' => '', 'limit' => 10, 'orderby' => '', 'upcoming' => '', 'fallback' => 'true', 'wrap' => '', 'class' => '', 'empty' => ''], $atts, 'hub_loop');
		$items = self::items([
			'placement' => $a['placement'], 'type' => $a['type'], 'tag' => $a['tag'], 'lang' => $a['lang'], 'limit' => (int) $a['limit'],
			'orderby' => $a['orderby'], 'upcoming' => in_array($a['upcoming'], ['1', 'true', 'yes'], true), 'fallback' => $a['fallback'] !== 'false',
		]);
		if (!$items) return $a['empty'] !== '' ? esc_html($a['empty']) : self::hint('[hub_loop]');
		$out = '';
		foreach ($items as $i => $item) {
			$item['_index'] = $i + 1;
			self::$stack[] = $item;
			$out .= do_shortcode($content);
			array_pop(self::$stack);
		}
		$tag = in_array($a['wrap'], ['div', 'ul', 'ol', 'section'], true) ? $a['wrap'] : '';
		return $tag ? sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tag, esc_attr($a['class']), $out) : $out;
	}

	/* ---------------- Bricks dynamic tags ---------------- */

	/**
	 * Tags for the Bricks dynamic data picker, built from the hub's real placements and
	 * content types: one group per placement / type ("Hub · home-hero"), one tag per field.
	 * Uses the stored list; in the builder it's refreshed at most hourly (never for visitors).
	 * Falls back to examples if the hub hasn't been reached yet.
	 */
	public static function bricks_list(array $tags): array {
		$meta  = HC_Meta::get(HC_Meta::editor_may_fetch());
		$place = HC_Meta::placements($meta);
		$types = HC_Meta::types($meta);

		$placement_fields = [
			'title'       => __('Title', 'hub-client'),
			'subtitle'    => __('Subtitle', 'hub-client'),
			'badge'       => __('Badge', 'hub-client'),
			'excerpt'     => __('Excerpt', 'hub-client'),
			'html'        => __('Content (HTML)', 'hub-client'),
			'image'       => __('Image', 'hub-client'),
			'image_alt'   => __('Image alt text', 'hub-client'),
			'image_focus' => __('Image focal point (CSS object-position)', 'hub-client'),
			'cta_label'   => __('Button text', 'hub-client'),
			'cta_url'     => __('Button link', 'hub-client'),
			'cta2_label'  => __('Second button text', 'hub-client'),
			'cta2_url'    => __('Second button link', 'hub-client'),
		];
		$type_fields = [
			'title'    => __('Title (newest)', 'hub-client'),
			'subtitle' => __('Subtitle (newest)', 'hub-client'),
			'badge'    => __('Badge (newest)', 'hub-client'),
			'excerpt'  => __('Excerpt (newest)', 'hub-client'),
			'image'    => __('Image (newest)', 'hub-client'),
			'link'     => __('Article page link (newest)', 'hub-client'),
			'date'     => __('Date (newest)', 'hub-client'),
		];
		$event_fields = [
			'title'      => __('Title', 'hub-client'),
			'event_date' => __('Date', 'hub-client'),
			'event_time' => __('Time', 'hub-client'),
			'event_start' => __('Starts (date + time)', 'hub-client'),
			'event_end'  => __('Ends (date + time)', 'hub-client'),
			'location'   => __('Location', 'hub-client'),
			'map_url'    => __('Directions link', 'hub-client'),
			'map_lat'    => __('Latitude (Map element)', 'hub-client'),
			'map_lng'    => __('Longitude (Map element)', 'hub-client'),
			'stream_url' => __('Live stream link', 'hub-client'),
			'image'      => __('Image', 'hub-client'),
			'link'       => __('Article page link', 'hub-client'),
			'cta_url'    => __('Button link', 'hub-client'),
		];

		if (!$place && !$types) {
			$place = ['home-hero' => 'home-hero'];
			$types = ['news' => 'news'];
		}
		foreach ($place as $slug => $name) {
			$group = sprintf('Hub · %s', $name);
			foreach ($placement_fields as $field => $label) {
				$tags[] = ['name' => '{hub:' . $slug . ':' . $field . '}', 'label' => $label, 'group' => $group];
			}
		}
		foreach ($types as $slug => $name) {
			$group = sprintf('Hub · %s (%s)', $name, __('content type', 'hub-client'));
			foreach ($type_fields as $field => $label) {
				$tags[] = ['name' => '{hub:type.' . $slug . ':' . $field . '}', 'label' => $label, 'group' => $group];
			}
		}
		// Event-like types also get a "next" group: upcoming items, soonest first.
		foreach (self::event_types(array_keys($types)) as $slug) {
			$group = sprintf('Hub · %s (%s)', $types[$slug] ?? $slug, __('next upcoming', 'hub-client'));
			foreach ($event_fields as $field => $label) {
				$tags[] = ['name' => '{hub:next.' . $slug . ':' . $field . '}', 'label' => $label, 'group' => $group];
			}
		}
		// The current post (a hub copy: article, player …), also each post of a query loop.
		$group = __('Hub · this post', 'hub-client');
		foreach ($placement_fields + ['link' => __('Its page link', 'hub-client'), 'date' => __('Date', 'hub-client')] + $event_fields as $field => $label) {
			$tags[] = ['name' => '{hub:post:' . $field . '}', 'label' => $label, 'group' => $group];
		}
		$tags[] = ['name' => '{hub:post:data.weight}', 'label' => __('A data point — data.<name>', 'hub-client'), 'group' => $group];
		$tags[] = ['name' => '{hub:post.ana-horvat:title}', 'label' => __('Another post — post.<slug> or post.<ID>', 'hub-client'), 'group' => 'Hub · tips'];
		// How-to entries for the index / language suffixes.
		$first = (string) (array_key_first($place) ?: 'home-blocks');
		$tags[] = ['name' => '{hub:' . $first . ':title:2}', 'label' => __('2nd item — add :2, :3 … to any tag', 'hub-client'), 'group' => 'Hub · tips'];
		$tags[] = ['name' => '{hub:' . $first . ':title:1:en}', 'label' => __('Fixed language — add :1:en to any tag', 'hub-client'), 'group' => 'Hub · tips'];
		$tags[] = ['name' => '{hub:tag.' . ((string) (array_key_first(HC_Meta::tags($meta)) ?: 'my-tag')) . ':title}', 'label' => __('Newest item with a tag — tag.<slug>', 'hub-client'), 'group' => 'Hub · tips'];
		$tags[] = ['name' => '{hub:next.' . ((string) (array_key_first($types) ?: 'event')) . ':title}', 'label' => __('Next upcoming item of any type — next.<type>', 'hub-client'), 'group' => 'Hub · tips'];
		return $tags;
	}

	/**
	 * Content types that are events, for the "next upcoming" picker groups.
	 * Guessed from the slug; adjust with the hub_client_event_types filter.
	 */
	public static function event_types(array $slugs): array {
		$guess = array_values(array_filter($slugs, fn($s) => (bool) preg_match('/event|dogad|termin|turnir|tournament|kamp|camp|utakmic|match|game|trening|workshop|radionic|webinar|course|tecaj|tečaj|concert|koncert|meetup|conference|konferencij|predavanj|lecture|show|festival|sajam|fair/i', $s)));
		return (array) apply_filters('hub_client_event_types', $guess, $slugs);
	}

	/** "hub:placement:field[:index[:lang]]" → value (string, or [url] for image fields). */
	public static function resolve(string $tag, string $context = 'text') {
		$parts = explode(':', $tag);
		if (($parts[0] ?? '') !== 'hub' || count($parts) < 3) return null;
		[, $source, $field] = $parts;
		$index = max(1, (int) ($parts[3] ?? 1));
		$lang  = (string) ($parts[4] ?? '');
		if ($source === 'post' || str_starts_with($source, 'post.')) {
			// post = the current post (each post of a query loop); post.123 / post.ana-horvat = that one
			$ref  = (string) substr($source, 5);
			$pid  = $source === 'post' ? self::current_post() : (ctype_digit($ref) ? self::find_post($ref, '') : self::find_post('', $ref));
			$item = self::post_item($pid, $lang);
			if (!$item) return $context === 'image' ? [] : ($context === 'text' ? self::hint('{' . $tag . '}') : '');
			return self::render_resolved($item, $field, $context);
		}
		if (str_starts_with($source, 'next.')) {          // next.event = upcoming events, soonest first
			$q = ['type' => substr($source, 5), 'upcoming' => true, 'lang' => $lang, 'limit' => max(10, $index)];
		} elseif (str_starts_with($source, 'tag.')) {             // tag.summer-camp = newest items with that tag
			$q = ['tag' => substr($source, 4), 'lang' => $lang, 'limit' => max(10, $index)];
		} elseif (str_starts_with($source, 'type.')) {
			$q = ['type' => substr($source, 5), 'lang' => $lang, 'limit' => max(10, $index)];
		} else {
			$q = ['placement' => $source, 'lang' => $lang, 'limit' => max(10, $index)];
		}
		$item = self::items($q)[$index - 1] ?? null;
		if (!$item) return $context === 'image' ? [] : ($context === 'text' ? self::hint('{' . $tag . '}') : '');
		return self::render_resolved($item, $field, $context);
	}

	/** One field of a resolved item, in the form Bricks wants for the context. */
	private static function render_resolved(array $item, string $field, string $context) {
		if ($context === 'image') {
			// An attachment ID lets Bricks output its own sizes and srcset.
			if ($lid = self::local_image($item)) return [$lid];
			$url = (string) ($item['image']['full'] ?? $item['image']['url'] ?? '');
			return $url !== '' ? [$url] : [];
		}
		if ($context === 'link' && $field === 'title') $field = 'link';
		return self::value($item, self::field_key($field));
	}

	public static function bricks_tag($tag, $post = null, $context = 'text') {
		if (!is_string($tag) || !str_starts_with($tag, 'hub:')) return $tag;
		$v = self::with_post($post, fn() => self::resolve($tag, (string) $context));
		return $v ?? $tag;
	}

	public static function bricks_content($content, $post = null, $context = 'text') {
		if (!is_string($content) || !str_contains($content, '{hub:')) return $content;
		return self::with_post($post, fn() => preg_replace_callback('/\{(hub:[a-z0-9_.\-]+:[a-z][a-z0-9_.\-]*(?::\d+)?(?::[a-z]{2,3})?)\}/i', function ($m) {
			$v = self::resolve($m[1], 'text');
			return is_string($v) ? $v : '';
		}, $content));
	}

	/** Run with Bricks' post (the loop's current post) as "the current post". */
	private static function with_post($post, callable $fn) {
		$id = $post instanceof WP_Post ? (int) $post->ID : (is_numeric($post) ? (int) $post : 0);
		if (!$id) return $fn();
		$prev = self::$post_ctx;
		self::$post_ctx = $id;
		try { return $fn(); } finally { self::$post_ctx = $prev; }
	}
}
