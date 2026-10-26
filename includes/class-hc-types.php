<?php
defined('ABSPATH') || exit;

/**
 * Post types for this site, fed from the hub.
 *
 * Each one gets its own name, URL base, archive and menu icon here, and one or more hub
 * content types: hub items of those types are kept as posts of that type (e.g. hub
 * "event" → "Tournaments" at /turniri/). Items of other kept types go where "Store as"
 * says (Posts or Hub articles). Content is still written and translated on the hub.
 *
 * Stored in the hub_client_cpts option: key => {plural, singular, slug, types[], archive, icon}.
 * Changing a mapping moves existing copies to the right post type (HC_Sync::rehome()).
 */
class HC_Types {

	const OPTION = 'hub_client_cpts';
	const ICONS  = ['admin-post', 'calendar-alt', 'megaphone', 'portfolio', 'products', 'groups', 'businessperson', 'awards', 'location', 'format-gallery', 'video-alt3', 'book', 'welcome-learn-more', 'heart', 'star-filled', 'clipboard', 'tickets-alt', 'store', 'flag', 'admin-site-alt3'];
	const RESERVED = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'hub_article', 'action', 'author', 'order', 'theme', 'type', 'name', 'year', 'page_id', 'p', 'term', 'taxonomy', 'tag', 'category', 'search', 'feed', 'embed', 'comments', 'bricks_template'];

	public static function init(): void {
		add_action('init', [__CLASS__, 'register'], 9);              // before HC_Sync attaches its taxonomies
		add_action('init', [__CLASS__, 'maybe_flush'], 99);
		add_action('admin_init', fn() => register_setting('hub_client_cpts', self::OPTION, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize'], 'default' => []]));
		add_action('update_option_' . self::OPTION, [__CLASS__, 'changed'], 10, 2);
		add_action('add_option_' . self::OPTION, fn($o, $v) => self::changed([], $v), 10, 2);
		add_action('hub_client_rehome', [HC_Sync::class, 'rehome']);
		// Polylang: these post types (and the hub taxonomies) are translatable, without a trip to its settings.
		add_filter('pll_get_post_types', [__CLASS__, 'pll_post_types'], 10, 2);
		add_filter('pll_get_taxonomies', [__CLASS__, 'pll_taxonomies'], 10, 2);
	}

	/** @return array<string, array{plural:string,singular:string,slug:string,types:string[],archive:bool,icon:string}> */
	public static function all(): array {
		$v = get_option(self::OPTION, []);
		return is_array($v) ? $v : [];
	}

	/** Hub content type → this site's post type key, for the types mapped here. */
	public static function map(): array {
		$m = [];
		foreach (self::all() as $key => $d) {
			foreach ((array) ($d['types'] ?? []) as $t) $m[(string) $t] ??= (string) $key;
		}
		return $m;
	}

	public static function register(): void {
		foreach (self::all() as $key => $d) {
			if (post_type_exists($key)) continue;
			$plural   = (string) ($d['plural'] ?? $key);
			$singular = (string) ($d['singular'] ?? $plural);
			register_post_type($key, [
				'labels'       => [
					'name'          => $plural,
					'singular_name' => $singular,
					'menu_name'     => $plural,
					'all_items'     => $plural,
					'edit_item'     => sprintf(__('Edit %s', 'hub-client'), $singular),
					'view_item'     => sprintf(__('View %s', 'hub-client'), $singular),
					'search_items'  => sprintf(__('Search %s', 'hub-client'), $plural),
					'not_found'     => sprintf(__('No %s found', 'hub-client'), $plural),
				],
				'description'  => sprintf(__('From the hub: %s', 'hub-client'), implode(', ', (array) ($d['types'] ?? []))),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => !empty($d['archive']) ? (string) $d['slug'] : false,
				'rewrite'      => ['slug' => (string) $d['slug'], 'with_front' => false],
				'menu_icon'    => 'dashicons-' . (in_array($d['icon'] ?? '', self::ICONS, true) ? $d['icon'] : 'admin-post'),
				'menu_position' => 21,
				'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
				// Edited on the hub: no "Add new" here.
				'capabilities' => ['create_posts' => 'do_not_allow'],
				'map_meta_cap' => true,
			]);
		}
	}

	/** Settings form → clean definitions. Errors are shown on the settings screen. */
	public static function sanitize($in): array {
		$in = (array) $in;
		if (isset($in['rows'])) {
			$rows = (array) $in['rows'];
		} else {
			// Already-clean definitions (saved from code, e.g. WP-CLI): key => definition.
			$rows = [];
			foreach ($in as $k => $d) if (is_array($d)) $rows[] = ['key' => (string) $k] + $d;
		}
		$out  = [];
		$taken_types = [];
		$slugs = [];
		foreach ($rows as $r) {
			$r = (array) $r;
			if (!empty($r['delete'])) continue;
			$plural   = sanitize_text_field((string) ($r['plural'] ?? ''));
			$singular = sanitize_text_field((string) ($r['singular'] ?? '')) ?: $plural;
			if ($plural === '') continue; // empty "add" card
			$slug = sanitize_title((string) ($r['slug'] ?? '')) ?: sanitize_title($plural);
			$key  = substr(sanitize_key(str_replace('-', '_', (string) ($r['key'] ?? '') ?: $slug)), 0, 20);
			if ($key === '' || in_array($key, self::RESERVED, true) || isset($out[$key]) || (post_type_exists($key) && !isset(self::all()[$key]))) {
				self::error(sprintf(__('“%1$s”: the key “%2$s” is reserved or already used — choose another URL base.', 'hub-client'), $plural, $key));
				continue;
			}
			if (in_array($slug, $slugs, true)) {
				self::error(sprintf(__('“%s”: another post type already uses that URL base.', 'hub-client'), $plural));
				continue;
			}
			$types = [];
			foreach (array_merge((array) ($r['types'] ?? []), explode(',', (string) ($r['types_extra'] ?? ''))) as $t) {
				$t = sanitize_title((string) $t);
				if ($t === '') continue;
				if (isset($taken_types[$t])) {
					self::error(sprintf(__('Hub type “%1$s” can feed only one post type; it stays with “%2$s”.', 'hub-client'), $t, $taken_types[$t]));
					continue;
				}
				$taken_types[$t] = $plural;
				$types[] = $t;
			}
			$slugs[] = $slug;
			$out[$key] = [
				'plural'   => $plural,
				'singular' => $singular,
				'slug'     => $slug,
				'types'    => array_values(array_unique($types)),
				'archive'  => !empty($r['archive']),
				'icon'     => in_array($r['icon'] ?? '', self::ICONS, true) ? (string) $r['icon'] : 'admin-post',
			];
		}
		return $out;
	}

	private static function error(string $msg): void {
		add_settings_error(self::OPTION, 'hub_cpt_' . md5($msg), $msg, 'warning');
	}

	/** Definitions changed: new URLs need a rewrite flush; copies may need to move. */
	public static function changed($old, $new): void {
		update_option('hub_client_cpts_flush', 1, false);
		if (!wp_next_scheduled('hub_client_rehome')) wp_schedule_single_event(time() + 5, 'hub_client_rehome');
	}

	public static function maybe_flush(): void {
		if (get_option('hub_client_cpts_flush')) {
			delete_option('hub_client_cpts_flush');
			flush_rewrite_rules(false);
		}
	}

	public static function pll_post_types($types, $is_settings) {
		foreach (array_merge(array_keys(self::all()), post_type_exists('hub_article') ? ['hub_article'] : []) as $k) $types[$k] = $k;
		return $types;
	}

	public static function pll_taxonomies($taxes, $is_settings) {
		foreach (['hub_type', 'hub_article_tag'] as $t) if (taxonomy_exists($t)) $taxes[$t] = $t;
		return $taxes;
	}

	/* ---------------- Settings tab ---------------- */

	public static function render_tab(): void {
		$all   = self::all();
		$meta  = HC_Meta::get(HC_Meta::editor_may_fetch());
		$hub   = HC_Meta::types($meta);
		$kinds = [];
		foreach ((array) ($meta['content_types'] ?? []) as $t) $kinds[$t['slug']] = $t['name'];
		$default = HC_Sync::post_type() === 'post' ? __('Posts', 'hub-client') : __('Hub articles', 'hub-client');
		$map   = self::map();
		$kept  = hub_client_csv('types');
		?>
		<div class="hubui-card accent" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
			<span class="dashicons dashicons-info-outline" style="color:#3858e9"></span>
			<span><?php printf(esc_html__('Give hub content its own post type here — own name, URL, archive and Bricks templates. Hub types not listed in any post type go to %s (Articles tab). Content is still edited and translated on the hub.', 'hub-client'), '<strong>' . esc_html($default) . '</strong>'); ?></span>
		</div>
		<form method="post" action="options.php" class="hubc-types">
			<?php settings_fields('hub_client_cpts'); ?>
			<div class="hubui-grid">
			<?php
			$i = 0;
			foreach ($all as $key => $d) { self::card($i++, $key, $d, $hub, $map); }
			self::card($i, '', [], $hub, $map); // empty card to add one
			?>
			</div>
			<?php submit_button(__('Save post types', 'hub-client')); ?>
		</form>
		<?php if ($all) : ?>
			<?php HC_UI::card_open(__('In Bricks', 'hub-client'), __('Each post type works like any other: make a Single template and an Archive template with the condition “Post type → …”, and use it in query loops. All hub fields are custom fields: {cf_hub_subtitle}, {cf_hub_event_start}, {cf_hub_location} …', 'hub-client'), 'layout'); ?>
				<table class="hubui-table"><thead><tr><th><?php esc_html_e('Post type', 'hub-client'); ?></th><th><?php esc_html_e('From the hub', 'hub-client'); ?></th><th><?php esc_html_e('Posts', 'hub-client'); ?></th><th><?php esc_html_e('Archive', 'hub-client'); ?></th></tr></thead><tbody>
				<?php foreach ($all as $key => $d) :
					$n = post_type_exists($key) ? (int) array_sum((array) wp_count_posts($key)) : 0; ?>
					<tr>
						<td><span class="dashicons dashicons-<?php echo esc_attr($d['icon']); ?>"></span> <strong><?php echo esc_html($d['plural']); ?></strong> <code><?php echo esc_html($key); ?></code></td>
						<td><?php foreach ((array) $d['types'] as $t) echo HC_UI::chip($t, 'info') . ' '; ?></td>
						<td><a href="<?php echo esc_url(admin_url('edit.php?post_type=' . $key)); ?>"><?php echo (int) $n; ?></a></td>
						<td><?php echo !empty($d['archive']) && post_type_exists($key) ? '<a href="' . esc_url((string) get_post_type_archive_link($key)) . '" target="_blank">/' . esc_html($d['slug']) . '/</a>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="hubui-muted"><?php esc_html_e('Hub types in these post types are kept as articles automatically, even if they are not in “Content types to keep”.', 'hub-client'); ?></p>
			<?php HC_UI::card_close(); ?>
		<?php endif; ?>
		<style>
			.hubc-types .hubc-typecard .form-table th{width:130px}
			.hubc-types .hubc-hubtypes{display:flex;flex-wrap:wrap;gap:6px}
			.hubc-types .hubc-hubtypes label{border:1px solid #dcdcde;border-radius:999px;padding:2px 10px;background:#fff;cursor:pointer}
			.hubc-types .hubc-hubtypes label:has(input:checked){border-color:#3858e9;background:#eef1fd;color:#1d2327}
			.hubc-types .hubc-hubtypes label.taken{opacity:.55}
			.hubc-types .hubc-hubtypes input{margin:0 4px 0 0}
			.hubc-types .hubc-icons{display:flex;flex-wrap:wrap;gap:4px}
			.hubc-types .hubc-icons label{border:1px solid #dcdcde;border-radius:6px;padding:4px;cursor:pointer;line-height:0}
			.hubc-types .hubc-icons input{position:absolute;opacity:0}
			.hubc-types .hubc-icons label:has(input:checked){border-color:#3858e9;background:#eef1fd;color:#3858e9}
			.hubc-types .hubc-new{border-style:dashed}
		</style>
		<script>
		document.addEventListener('input', function (e) {
			var card = e.target.closest('.hubc-typecard');
			if (!card || !card.classList.contains('hubc-new') || !e.target.matches('[data-role="plural"]')) return;
			var slug = card.querySelector('[data-role="slug"]');
			if (slug && !slug.dataset.touched) slug.value = e.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
		});
		document.addEventListener('change', function (e) { if (e.target.matches('[data-role="slug"]')) e.target.dataset.touched = 1; });
		</script>
		<?php
	}

	private static function card(int $i, string $key, array $d, array $hub, array $map): void {
		$new  = $key === '';
		$name = fn($f) => esc_attr("hub_client_cpts[rows][$i][$f]");
		$icon = (string) ($d['icon'] ?? 'admin-post');
		$mine = (array) ($d['types'] ?? []);
		$extra = array_diff($mine, array_keys($hub));
		echo '<div class="hubui-card hubc-typecard' . ($new ? ' hubc-new' : ' accent') . '">';
		echo '<h2><span class="dashicons dashicons-' . esc_attr($new ? 'plus-alt' : $icon) . '"></span>' . esc_html($new ? __('Add a post type', 'hub-client') : $d['plural']) . ($new ? '' : ' <code style="font-weight:400">' . esc_html($key) . '</code>') . '</h2>';
		if (!$new) echo '<input type="hidden" name="' . $name('key') . '" value="' . esc_attr($key) . '">';
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e('Name (plural)', 'hub-client'); ?></th><td><input type="text" data-role="plural" name="<?php echo $name('plural'); ?>" value="<?php echo esc_attr((string) ($d['plural'] ?? '')); ?>" placeholder="<?php esc_attr_e('e.g. Tournaments', 'hub-client'); ?>" class="regular-text"></td></tr>
			<tr><th><?php esc_html_e('Singular', 'hub-client'); ?></th><td><input type="text" name="<?php echo $name('singular'); ?>" value="<?php echo esc_attr((string) ($d['singular'] ?? '')); ?>" placeholder="<?php esc_attr_e('e.g. Tournament', 'hub-client'); ?>" class="regular-text"></td></tr>
			<tr><th><?php esc_html_e('URL base', 'hub-client'); ?></th><td><code><?php echo esc_html(trailingslashit(home_url())); ?></code><input type="text" data-role="slug" name="<?php echo $name('slug'); ?>" value="<?php echo esc_attr((string) ($d['slug'] ?? '')); ?>" placeholder="turniri" style="width:10em"><code>/…</code>
				<?php if (!$new) : ?><p class="description"><?php esc_html_e('Changing it changes every URL of this type.', 'hub-client'); ?></p><?php endif; ?></td></tr>
			<tr><th><?php esc_html_e('From the hub', 'hub-client'); ?></th><td>
				<div class="hubc-hubtypes">
				<?php foreach ($hub as $slug => $label) :
					$taken = isset($map[$slug]) && $map[$slug] !== $key; ?>
					<label class="<?php echo $taken ? 'taken' : ''; ?>" title="<?php echo $taken ? esc_attr(sprintf(__('Used by %s', 'hub-client'), self::all()[$map[$slug]]['plural'] ?? $map[$slug])) : ''; ?>"><input type="checkbox" name="<?php echo $name('types'); ?>[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $mine, true)); ?> <?php disabled($taken); ?>><?php echo esc_html($label === $slug ? $slug : "$label"); ?></label>
				<?php endforeach; ?>
				</div>
				<?php if (!$hub) : ?><input type="text" name="<?php echo $name('types_extra'); ?>" value="<?php echo esc_attr(implode(',', $mine)); ?>" placeholder="event,tournament" class="regular-text"><p class="description"><?php esc_html_e('Hub content type slugs, comma-separated (the hub could not be reached for the list).', 'hub-client'); ?></p>
				<?php elseif ($extra) : ?><input type="hidden" name="<?php echo $name('types_extra'); ?>" value="<?php echo esc_attr(implode(',', $extra)); ?>"><?php endif; ?>
			</td></tr>
			<tr><th><?php esc_html_e('Archive page', 'hub-client'); ?></th><td><label><input type="checkbox" name="<?php echo $name('archive'); ?>" value="1" <?php checked($new || !empty($d['archive'])); ?>> <?php esc_html_e('A list page at the URL base', 'hub-client'); ?></label></td></tr>
			<tr><th><?php esc_html_e('Menu icon', 'hub-client'); ?></th><td><div class="hubc-icons">
				<?php foreach (self::ICONS as $ic) : ?><label title="<?php echo esc_attr($ic); ?>"><input type="radio" name="<?php echo $name('icon'); ?>" value="<?php echo esc_attr($ic); ?>" <?php checked($ic, $icon); ?>><span class="dashicons dashicons-<?php echo esc_attr($ic); ?>"></span></label><?php endforeach; ?>
			</div></td></tr>
			<?php if (!$new) : ?>
			<tr><th><?php esc_html_e('Remove', 'hub-client'); ?></th><td><label><input type="checkbox" name="<?php echo $name('delete'); ?>" value="1"> <?php printf(esc_html__('Remove this post type — its articles move back to %s', 'hub-client'), esc_html(HC_Sync::post_type() === 'post' ? __('Posts', 'hub-client') : __('Hub articles', 'hub-client'))); ?></label></td></tr>
			<?php endif; ?>
		</table>
		<?php
		echo '</div>';
	}
}
