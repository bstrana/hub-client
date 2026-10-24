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
 *
 * Data comes from a local store that only the hub's webhooks refresh, so
 * visitors never cause a request to the hub. See the Data section below.
 */
class HC_Blocks {

	const FIELDS = ['title', 'excerpt', 'html', 'slug', 'id', 'lang', 'cta_url', 'cta_label', 'image', 'image_url', 'image_full', 'image_alt', 'date', 'link', 'content_type'];

	private static array $memo  = [];
	private static array $stack = []; // current [hub_loop] items

	public static function init(): void {
		add_shortcode('hub', [__CLASS__, 'sc_field']);
		add_shortcode('hub_image', [__CLASS__, 'sc_image']);
		add_shortcode('hub_loop', [__CLASS__, 'sc_loop']);
		add_shortcode('hub_link', [__CLASS__, 'sc_link']);

		// Bricks dynamic data. Harmless when Bricks isn't installed.
		add_filter('bricks/dynamic_tags_list', [__CLASS__, 'bricks_list']);
		add_filter('bricks/dynamic_data/render_tag', [__CLASS__, 'bricks_tag'], 20, 3);
		add_filter('bricks/dynamic_data/render_content', [__CLASS__, 'bricks_content'], 20, 3);
		add_filter('bricks/frontend/render_data', [__CLASS__, 'bricks_content'], 20, 2);
	}

	/* ---------------- Data: a local store, refreshed only when the hub says so ---------------- */
	//
	// Every distinct section query (site + placement/type + language + limit + order)
	// is kept, with its data, in ONE option (`hub_client_blocks`), so a page view costs a
	// single database read however many sections it shows. Page views only READ the
	// store — visitors never cause a call to the hub.
	// The hub's webhook refreshes the queries a change can affect (refresh_for_item()).
	// A query nobody has fetched yet is fetched once when an editor views it (can be
	// switched off), or by "Refresh sections now" / `wp hub-client refresh`.

	const STORE = 'hub_client_blocks';

	/** @return array<string, array{q:array,seen:int,data?:array,fetched?:int,error?:string}> */
	private static function store(): array {
		return (array) get_option(self::STORE, []);
	}

	private static function save(array $store): void {
		update_option(self::STORE, $store, false);
	}

	public static function current_lang(string $attr = ''): string {
		if ($attr !== '') return sanitize_key($attr);
		if (function_exists('pll_current_language') && ($l = pll_current_language('slug'))) return (string) $l;
		$wpml = apply_filters('wpml_current_language', null);
		if (is_string($wpml) && $wpml !== '') return $wpml;
		$langs = hub_client_csv('langs');
		return $langs[0] ?? '';
	}

	/** Normalised API query for a section, or null if it names no source. */
	public static function normalize(array $q): ?array {
		$query = array_filter([
			'site'      => (string) hub_client_opt('site'),
			'placement' => sanitize_title((string) ($q['placement'] ?? '')),
			'type'      => sanitize_title((string) ($q['type'] ?? '')),
			'lang'      => self::current_lang((string) ($q['lang'] ?? '')),
			'per_page'  => max(1, min(100, (int) ($q['limit'] ?? 10))),
			'orderby'   => in_array($q['orderby'] ?? '', ['order', 'date', 'modified', 'title'], true) ? $q['orderby'] : '',
			'fallback'  => isset($q['fallback']) && !$q['fallback'] ? 'false' : '',
		], fn($v) => $v !== '' && $v !== null);
		if (empty($query['placement']) && empty($query['type'])) return null;
		if (!empty($query['type']) && empty($query['placement']) && empty($query['orderby'])) $query['orderby'] = 'date';
		ksort($query);
		return $query;
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

		self::register($id, $query);
		$stored = self::store()[$id] ?? [];
		if (!isset($stored['fetched'])) {
			// Never fetched. Only an editor's page view may fetch it now; visitors wait for the hub.
			if (hub_client_opt('editor_fetch') && is_user_logged_in() && current_user_can('edit_posts')) {
				$r = self::fetch($id, $query);
				$stored = self::store()[$id] ?? [];
				if (is_wp_error($r)) self::$last['error'] = $r->get_error_message();
			} else {
				self::$last['error'] = 'not loaded yet — it arrives with the next change on the hub, or use Settings → Hub client → "Refresh sections now".';
			}
		} elseif (!empty($stored['error'])) {
			self::$last['error'] = 'last refresh failed (' . $stored['error'] . '); showing the previous content';
		}
		$data = (array) ($stored['data'] ?? []);
		self::$info[$id] = self::$last;
		return self::$memo[$id] = $data;
	}

	/** Remember that a page uses this query, so webhooks keep it current. */
	private static function register(string $id, array $query): void {
		$store = self::store();
		if (isset($store[$id]) && (time() - (int) ($store[$id]['seen'] ?? 0)) < DAY_IN_SECONDS) return; // write at most daily
		$store[$id] = array_merge($store[$id] ?? [], ['q' => $query, 'seen' => time()]);
		self::save($store);
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
		$res   = HC_API::get('/content', $q);
		$store = self::store();
		$entry = $store[$id] ?? ['q' => $query, 'seen' => time()];
		if (is_wp_error($res)) {
			$entry['error'] = $res->get_error_message(); // previous data stays
			$store[$id] = $entry;
			self::save($store);
			HC_Sync::log('blocks', 0, $res->get_error_message());
			return $res;
		}
		// Images are copied here, at refresh time — never during a page view.
		$entry['data']    = HC_Media::localize_items(is_array($res['data']) ? $res['data'] : []);
		$entry['fetched'] = time();
		$entry['error']   = '';
		$store[$id] = $entry;
		self::save($store);
		unset(self::$memo[$id]);
		return true;
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
			$shows  = in_array($hub_id, array_map('intval', array_column((array) ($entry['data'] ?? []), 'id')), true);
			$fits   = $item && (
				(!empty($q['placement']) && in_array($q['placement'], (array) ($item['placements'] ?? []), true)) ||
				(!empty($q['type']) && empty($q['placement']) && in_array($q['type'], (array) ($item['content_types'] ?? []), true))
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
		return ['refreshed' => $ok, 'failed' => $fail];
	}

	/** Registry minus queries no page has used for 60 days (their stored data is deleted). */
	public static function live_registry(): array {
		$store = self::store();
		$live  = array_filter($store, fn($e) => (time() - (int) ($e['seen'] ?? 0)) <= 60 * DAY_IN_SECONDS
			&& ($e['q']['site'] ?? '') === (string) hub_client_opt('site'));
		if (count($live) !== count($store)) self::save($live);
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

	/** One field of one item, ready to print. */
	public static function value(array $item, string $field, array $a = []): string {
		switch ($field) {
			case 'title':
			case 'excerpt':
			case 'cta_label':
			case 'slug':
			case 'lang':
			case 'content_type':
				return esc_html((string) ($item[$field] ?? ''));
			case 'id':
				return (string) (int) ($item['id'] ?? 0);
			case 'html':
				return wp_kses_post((string) ($item['html'] ?? ''));
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
				$local = class_exists('HC_Sync') ? HC_Sync::local_id((int) ($item['id'] ?? 0)) : 0;
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

	private static function pick(array $a): ?array {
		// Inside [hub_loop] without its own source: the current loop item.
		if (self::$stack && empty($a['placement']) && empty($a['type'])) {
			return end(self::$stack);
		}
		// Same limit for every index so all fields of one placement share one cached request.
		$items = self::items(['placement' => $a['placement'], 'type' => $a['type'], 'lang' => $a['lang'], 'limit' => max(10, (int) $a['index'])]);
		return $items[max(1, (int) $a['index']) - 1] ?? null;
	}

	public static function sc_field($atts): string {
		$a = shortcode_atts(['placement' => '', 'type' => '', 'field' => 'title', 'index' => 1, 'lang' => '', 'format' => '', 'default' => ''], $atts, 'hub');
		$item = self::pick($a);
		$out  = $item ? self::value($item, sanitize_key($a['field']), array_filter(['format' => $a['format']])) : '';
		if ($out !== '') return $out;
		if ($a['default'] !== '') return esc_html($a['default']);
		// An item without this field (e.g. no button) is normal; a missing item is worth a hint.
		return $item ? '' : self::hint('[hub field="' . sanitize_key($a['field']) . '"]');
	}

	public static function sc_image($atts): string {
		$a = shortcode_atts(['placement' => '', 'type' => '', 'index' => 1, 'lang' => '', 'size' => 'large', 'class' => '', 'loading' => 'lazy', 'sizes' => '100vw'], $atts, 'hub_image');
		$item = self::pick($a);
		$img  = $item['image'] ?? null;
		if (!$img) return $item ? '' : self::hint('[hub_image]');
		if ($lid = self::local_image($item)) {
			$attr = ['class' => $a['class'], 'loading' => $a['loading'] === 'eager' ? 'eager' : 'lazy', 'decoding' => 'async', 'alt' => (string) ($img['alt'] ?? '')];
			if ($a['loading'] === 'eager') $attr['fetchpriority'] = 'high';
			if ($a['sizes'] !== '100vw') $attr['sizes'] = $a['sizes'];
			return wp_get_attachment_image($lid, sanitize_key($a['size']) ?: 'large', false, $attr);
		}
		$size = sanitize_key($a['size']);
		$src  = $size === 'full' ? ($img['full'] ?? '') : ($img['sizes'][$size] ?? $img['url'] ?? '');
		if (!$src) return '';
		return sprintf(
			'<img src="%s"%s alt="%s"%s class="%s" loading="%s" decoding="async">',
			esc_url($src),
			!empty($img['srcset']) ? ' srcset="' . esc_attr($img['srcset']) . '" sizes="' . esc_attr($a['sizes']) . '"' : '',
			esc_attr($img['alt'] ?? ''),
			!empty($img['width']) && $size === 'full' ? ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"' : '',
			esc_attr($a['class']),
			$a['loading'] === 'eager' ? 'eager' : 'lazy'
		);
	}

	/**
	 * A complete link, so no shortcode has to sit inside an href="…" attribute.
	 *   [hub_link placement="home-hero" class="btn"]                → button: cta_url + cta_label
	 *   [hub_link to="article" text="Pročitaj više"]                 → this site's copy of the article
	 *   [hub_link to="cta" text="title"]                             → link text from another field
	 * Always self-closing (no [/hub_link]), so it can't swallow the content after it.
	 */
	public static function sc_link($atts): string {
		$a = shortcode_atts(['placement' => '', 'type' => '', 'index' => 1, 'lang' => '', 'to' => 'cta', 'text' => '', 'class' => '', 'target' => ''], $atts, 'hub_link');
		$item = self::pick($a);
		if (!$item) return self::hint('[hub_link]');
		$href = self::value($item, $a['to'] === 'article' ? 'link' : 'cta_url');
		if ($href === '') return '';
		if ($a['text'] !== '' && in_array($a['text'], self::FIELDS, true)) {
			$label = self::value($item, $a['text']);
		} elseif ($a['text'] !== '') {
			$label = esc_html($a['text']);
		} else {
			$label = self::value($item, $a['to'] === 'article' ? 'title' : 'cta_label') ?: esc_html($href);
		}
		return sprintf('<a href="%s"%s%s>%s</a>', $href, $a['class'] !== '' ? ' class="' . esc_attr($a['class']) . '"' : '',
			$a['target'] === '_blank' ? ' target="_blank" rel="noopener"' : '', $label);
	}

	public static function sc_loop($atts, $content = ''): string {
		$a = shortcode_atts(['placement' => '', 'type' => '', 'lang' => '', 'limit' => 10, 'orderby' => '', 'fallback' => 'true', 'wrap' => '', 'class' => '', 'empty' => ''], $atts, 'hub_loop');
		$items = self::items([
			'placement' => $a['placement'], 'type' => $a['type'], 'lang' => $a['lang'], 'limit' => (int) $a['limit'],
			'orderby' => $a['orderby'], 'fallback' => $a['fallback'] !== 'false',
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

	public static function bricks_list(array $tags): array {
		foreach ([
			'{hub:home-hero:title}'     => 'Hub: title (placement:field)',
			'{hub:home-hero:html}'      => 'Hub: content',
			'{hub:home-hero:image}'     => 'Hub: image',
			'{hub:home-hero:cta_url}'   => 'Hub: button link',
			'{hub:home-hero:cta_label}' => 'Hub: button text',
			'{hub:home-blocks:title:2}' => 'Hub: 2nd item in a placement',
			'{hub:type.news:title:1}'   => 'Hub: newest news item',
		] as $name => $label) {
			$tags[] = ['name' => $name, 'label' => $label, 'group' => 'Hub'];
		}
		return $tags;
	}

	/** "hub:placement:field[:index[:lang]]" → value (string, or [url] for image fields). */
	public static function resolve(string $tag, string $context = 'text') {
		$parts = explode(':', $tag);
		if (($parts[0] ?? '') !== 'hub' || count($parts) < 3) return null;
		[, $source, $field] = $parts;
		$index = max(1, (int) ($parts[3] ?? 1));
		$lang  = (string) ($parts[4] ?? '');
		$q = str_starts_with($source, 'type.')
			? ['type' => substr($source, 5), 'lang' => $lang, 'limit' => max(10, $index)]
			: ['placement' => $source, 'lang' => $lang, 'limit' => max(10, $index)];
		$item = self::items($q)[$index - 1] ?? null;
		if (!$item) return $context === 'image' ? [] : ($context === 'text' ? self::hint('{' . $tag . '}') : '');

		if ($context === 'image') {
			// An attachment ID lets Bricks output its own sizes and srcset.
			if ($lid = self::local_image($item)) return [$lid];
			$url = (string) ($item['image']['full'] ?? $item['image']['url'] ?? '');
			return $url !== '' ? [$url] : [];
		}
		if ($context === 'link' && $field === 'title') $field = 'link';
		return self::value($item, sanitize_key($field));
	}

	public static function bricks_tag($tag, $post = null, $context = 'text') {
		if (!is_string($tag) || !str_starts_with($tag, 'hub:')) return $tag;
		$v = self::resolve($tag, (string) $context);
		return $v ?? $tag;
	}

	public static function bricks_content($content, $post = null, $context = 'text') {
		if (!is_string($content) || !str_contains($content, '{hub:')) return $content;
		return preg_replace_callback('/\{(hub:[a-z0-9_.\-]+:[a-z_]+(?::\d+)?(?::[a-z]{2,3})?)\}/i', function ($m) {
			$v = self::resolve($m[1], 'text');
			return is_string($v) ? $v : '';
		}, $content);
	}
}
