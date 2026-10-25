<?php
defined('ABSPATH') || exit;

/**
 * Settings → Hub shortcodes: a cheat sheet filled in with this hub's real
 * placements, content types and languages, with copy buttons.
 */
class HC_Cheatsheet {

	public static function init(): void {
		add_action('admin_menu', fn() => add_options_page(__('Hub shortcodes', 'hub-client'), __('Hub shortcodes', 'hub-client'), 'edit_posts', 'hub-shortcodes', [__CLASS__, 'render']));
		add_filter('plugin_action_links_' . plugin_basename(HUB_CLIENT_DIR . 'hub-client.php'), function ($links) {
			array_unshift($links,
				'<a href="' . esc_url(admin_url('options-general.php?page=hub-shortcodes')) . '">' . esc_html__('Shortcodes', 'hub-client') . '</a>',
				'<a href="' . esc_url(admin_url('options-general.php?page=hub-client')) . '">' . esc_html__('Settings', 'hub-client') . '</a>'
			);
			return $links;
		});
	}

	/** Placements, types and languages from the hub — opening this page refreshes them if over an hour old. */
	private static function meta(): array {
		return HC_Meta::get(true);
	}

	public static function render(): void {
		$meta  = self::meta();
		$place = array_column((array) ($meta['placements'] ?? []), 'name', 'slug') ?: ['home-hero' => 'home-hero'];
		$types = array_column((array) ($meta['content_types'] ?? []), 'name', 'slug') ?: ['news' => 'news'];
		$langs = (array) ($meta['languages'] ?? []) ?: ['hr', 'en'];
		$P = (string) array_key_first($place);
		$T = isset($types['news']) ? 'news' : (string) array_key_first($types);
		$E = (string) (HC_Blocks::event_types(array_keys($types))[0] ?? 'event');
		$L = HC_Blocks::current_lang() ?: (string) ($langs[0] ?? 'hr');

		// $rows: [code, what it does]. Tokens {P} {T} {E} {L} are replaced live by the pickers.
		$bricks = [
			['{hub:{P}:title}', __('Heading / Basic Text — title of the first item', 'hub-client')],
			['{hub:{P}:excerpt}', __('Short text', 'hub-client')],
			['{hub:{P}:image}', __('Image element → source Dynamic data (full-size image)', 'hub-client')],
			['{hub:{P}:cta_label}', __('Button text', 'hub-client')],
			['{hub:{P}:cta_url}', __('Button / link → Dynamic data', 'hub-client')],
			['{hub:{P}:title:2}', __('2nd item of the placement (3rd = :3 …)', 'hub-client')],
			['{hub:{P}:title:1:{L}}', __('A specific language', 'hub-client')],
			['{hub:type.{T}:title:1}', __('Newest item of a content type', 'hub-client')],
			['{hub:type.{T}:link:1}', __('Link to this site\'s page for the newest article', 'hub-client')],
			['{hub:{P}:subtitle}', __('Subtitle', 'hub-client')],
			['{hub:{P}:badge}', __('Badge text', 'hub-client')],
			['{hub:{P}:cta2_label} / {hub:{P}:cta2_url}', __('Second button text / link', 'hub-client')],
			['{hub:{P}:image_focus}', __('Image focal point — Image element → Style → Object position, or a custom CSS value', 'hub-client')],
			['{hub:next.{E}:title}', __('Next upcoming event (soonest first, ended ones drop out)', 'hub-client')],
			['{hub:next.{E}:event_date} {hub:next.{E}:event_time}', __('Its date and time (site formats)', 'hub-client')],
			['{hub:next.{E}:location} / {hub:next.{E}:map_url}', __('Location / directions link', 'hub-client')],
			['{hub:next.{E}:map_lat} {hub:next.{E}:map_lng}', __('Map element → coordinates', 'hub-client')],
			['{hub:next.{E}:stream_url}', __('Live stream link', 'hub-client')],
		];
		$shortcodes = [
			['[hub placement="{P}" field="title"]', __('One field of the first item', 'hub-client')],
			['[hub placement="{P}" index="2" field="excerpt"]', __('2nd item', 'hub-client')],
			['[hub type="{T}" field="title"]', __('Newest item of a content type', 'hub-client')],
			['[hub placement="{P}" field="title" lang="{L}"]', __('Specific language (default: the page\'s language)', 'hub-client')],
			['[hub placement="{P}" field="date" format="j.n.Y."]', __('Date with a format', 'hub-client')],
			['[hub placement="{P}" field="title" default="—"]', __('Text when there is nothing', 'hub-client')],
			['[hub_image placement="{P}" size="large" class="hero-img"]', __('Complete image with srcset and alt (sizes: thumbnail, medium, medium_large, large, full)', 'hub-client')],
			['[hub_link placement="{P}" class="btn"]', __('Complete button: CTA link with CTA text', 'hub-client')],
			['[hub_link type="{T}" to="article" text="title"]', __('Link to this site\'s article page; text = a field or literal text', 'hub-client')],
			['[hub_loop placement="{P}" limit="6"] … [/hub_loop]', __('Repeat for each item; inside use the shortcodes without placement', 'hub-client')],
			['[hub_loop type="{T}" limit="3" empty="Nema novosti"] … [/hub_loop]', __('Latest items of a type; text when none', 'hub-client')],
			['[hub_link placement="{P}" to="cta2" class="btn btn-2"]', __('Second button', 'hub-client')],
			['[hub_loop type="{E}" upcoming="1" limit="5"] … [/hub_loop]', __('Upcoming events, soonest first; ended ones drop out by themselves', 'hub-client')],
			['[hub type="{E}" upcoming="1" field="title"]', __('Next upcoming event (works with every shortcode)', 'hub-client')],
			['[hub field="event_start" format="j.n.Y. H:i"]', __('Event date with your own format', 'hub-client')],
			['[hub_link to="map" text="Upute za dolazak"]', __('Directions link (opens Google Maps)', 'hub-client')],
			['[hub_link to="stream" text="Gledaj uživo"]', __('Live stream link', 'hub-client')],
			['[hub_map type="{E}" upcoming="1" height="320"]', __('Embedded map with directions link (OpenStreetMap; provider="google" for Google)', 'hub-client')],
		];
		$fields = [
			'title' => __('title', 'hub-client'), 'excerpt' => __('short text', 'hub-client'), 'html' => __('full content with its markup (Rich Text or Shortcode element)', 'hub-client'),
			'image' => __('image URL (large)', 'hub-client'), 'image_full' => __('image URL (original)', 'hub-client'), 'image_alt' => __('image alt text', 'hub-client'),
			'cta_url' => __('button link', 'hub-client'), 'cta_label' => __('button text', 'hub-client'), 'date' => __('publish date (format="…")', 'hub-client'),
			'link' => __('this site\'s page for the article (if kept as an article)', 'hub-client'), 'slug' => 'slug', 'id' => __('hub ID', 'hub-client'),
			'lang' => __('language', 'hub-client'), 'content_type' => __('content type', 'hub-client'),
			'subtitle' => __('subtitle', 'hub-client'), 'badge' => __('badge text', 'hub-client'),
			'cta2_url' => __('second button link', 'hub-client'), 'cta2_label' => __('second button text', 'hub-client'),
			'image_focus' => __('focal point for object-position ("center" if not set)', 'hub-client'),
			'event_start' => __('event start, date + time (format="…")', 'hub-client'), 'event_end' => __('event end (format="…")', 'hub-client'),
			'event_date' => __('event start date', 'hub-client'), 'event_time' => __('event start time', 'hub-client'),
			'location' => __('location / address', 'hub-client'), 'map_url' => __('directions link', 'hub-client'),
			'map_lat' => __('latitude', 'hub-client'), 'map_lng' => __('longitude', 'hub-client'), 'stream_url' => __('live stream link', 'hub-client'),
			'seo_title' => __('SEO title', 'hub-client'), 'seo_description' => __('meta description', 'hub-client'),
		];
		$recipes = [
			__('Hero (Shortcode element)', 'hub-client') => "<section class=\"hero\">\n  [hub_image placement=\"{P}\" size=\"full\" class=\"hero-img\" loading=\"eager\"]\n  <h1>[hub placement=\"{P}\" field=\"title\"]</h1>\n  [hub placement=\"{P}\" field=\"html\"]\n  [hub_link placement=\"{P}\" class=\"btn\"]\n</section>",
			__('Cards from a placement', 'hub-client') => "[hub_loop placement=\"{P}\" wrap=\"div\" class=\"cards\"]\n  <article class=\"card\">\n    [hub_image size=\"medium_large\" class=\"card-img\"]\n    <h3>[hub field=\"title\"]</h3>\n    <p>[hub field=\"excerpt\"]</p>\n    [hub_link class=\"btn\"]\n  </article>\n[/hub_loop]",
			__('Hero with subtitle, badge and two buttons', 'hub-client') => "<section class=\"hero\">\n  [hub_image placement=\"{P}\" size=\"full\" class=\"hero-img\" loading=\"eager\"]\n  <span class=\"badge\">[hub placement=\"{P}\" field=\"badge\"]</span>\n  <h1>[hub placement=\"{P}\" field=\"title\"]</h1>\n  <p class=\"subtitle\">[hub placement=\"{P}\" field=\"subtitle\"]</p>\n  [hub_link placement=\"{P}\" class=\"btn\"]\n  [hub_link placement=\"{P}\" to=\"cta2\" class=\"btn btn-2\"]\n</section>",
			__('Upcoming events', 'hub-client') => "[hub_loop type=\"{E}\" upcoming=\"1\" limit=\"5\" wrap=\"ul\" class=\"events\" empty=\"Trenutno nema najavljenih događaja\"]\n  <li>\n    <time>[hub field=\"event_start\" format=\"j.n.Y. H:i\"]</time>\n    <strong>[hub_link to=\"article\" text=\"title\"]</strong>\n    <span>[hub field=\"location\"]</span>\n    [hub_link to=\"map\" text=\"Upute\"]\n    [hub_link to=\"stream\" text=\"Uživo\"]\n  </li>\n[/hub_loop]",
			__('Next event with map', 'hub-client') => "<div class=\"next-event\">\n  <h3>[hub type=\"{E}\" upcoming=\"1\" field=\"title\" default=\"Nema najavljenih događaja\"]</h3>\n  <p>[hub type=\"{E}\" upcoming=\"1\" field=\"event_date\"] · [hub type=\"{E}\" upcoming=\"1\" field=\"location\"]</p>\n  [hub_map type=\"{E}\" upcoming=\"1\" height=\"280\"]\n</div>",
			__('Latest articles with links', 'hub-client') => "[hub_loop type=\"{T}\" limit=\"3\" wrap=\"ul\" class=\"news\"]\n  <li>\n    [hub_image size=\"medium\"]\n    [hub_link to=\"article\" text=\"title\"]\n    <time>[hub field=\"date\"]</time>\n  </li>\n[/hub_loop]",
		];
		$store = HC_Blocks::live_registry();
		?>
		<div class="wrap hub-cheat">
			<h1><?php esc_html_e('Hub shortcodes', 'hub-client'); ?></h1>
			<?php if (!empty($meta['error'])) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(__('Couldn\'t load placements from the hub (%s). Examples use placeholders; check Settings → Hub client.', 'hub-client'), $meta['error'])); ?></p></div>
			<?php endif; ?>

			<div class="hub-pick">
				<label><?php esc_html_e('Placement', 'hub-client'); ?>
					<select data-token="P"><?php foreach ($place as $slug => $name) printf('<option value="%s">%s</option>', esc_attr($slug), esc_html($name === $slug ? $slug : "$name ($slug)")); ?></select></label>
				<label><?php esc_html_e('Content type', 'hub-client'); ?>
					<select data-token="T"><?php foreach ($types as $slug => $name) printf('<option value="%s"%s>%s</option>', esc_attr($slug), selected($slug, $T, false), esc_html($name === $slug ? $slug : "$name ($slug)")); ?></select></label>
				<label><?php esc_html_e('Event type', 'hub-client'); ?>
					<select data-token="E"><?php foreach (($types ?: [$E => $E]) as $slug => $name) printf('<option value="%s"%s>%s</option>', esc_attr($slug), selected($slug, $E, false), esc_html($name === $slug ? $slug : "$name ($slug)")); ?></select></label>
				<label><?php esc_html_e('Language', 'hub-client'); ?>
					<select data-token="L"><?php foreach ($langs as $l) printf('<option value="%s"%s>%s</option>', esc_attr($l), selected($l, $L, false), esc_html($l)); ?></select></label>
				<span class="description"><?php esc_html_e('Pick values and every example below updates. Click a code to copy it.', 'hub-client'); ?></span>
			</div>

			<h2><?php esc_html_e('In Bricks: which to use where', 'hub-client'); ?></h2>
			<table class="widefat striped hub-where"><tbody>
				<tr><td><strong><?php esc_html_e('Heading, Basic Text, Button text, Image source, Link URL, attributes', 'hub-client'); ?></strong></td><td><?php esc_html_e('Dynamic tags {hub:…} — Bricks does not run shortcodes in these fields.', 'hub-client'); ?></td></tr>
				<tr><td><strong><?php esc_html_e('Shortcode element', 'hub-client'); ?></strong></td><td><?php esc_html_e('[hub_loop] lists, [hub_image], [hub_link], the html field, any HTML you write around them.', 'hub-client'); ?></td></tr>
				<tr><td><strong><?php esc_html_e('Rich Text', 'hub-client'); ?></strong></td><td><?php esc_html_e('Either.', 'hub-client'); ?></td></tr>
			</tbody></table>

			<h2><?php esc_html_e('Bricks dynamic tags', 'hub-client'); ?></h2>
			<?php self::table($bricks); ?>

			<h2><?php esc_html_e('Shortcodes', 'hub-client'); ?></h2>
			<?php self::table($shortcodes); ?>

			<h2><?php esc_html_e('Fields', 'hub-client'); ?></h2>
			<p class="hub-fields"><?php foreach ($fields as $f => $d) printf('<code class="hub-copy" data-copy="%1$s">%1$s</code> <span>%2$s</span> ', esc_html($f), esc_html($d)); ?></p>

			<h2><?php esc_html_e('Ready-made', 'hub-client'); ?></h2>
			<?php foreach ($recipes as $title => $code) : ?>
				<h3><?php echo esc_html($title); ?></h3>
				<pre class="hub-copy hub-tpl" data-tpl="<?php echo esc_attr($code); ?>"></pre>
			<?php endforeach; ?>

			<h2><?php esc_html_e('Good to know', 'hub-client'); ?></h2>
			<ul class="ul-disc">
				<li><?php echo wp_kses_post(__('Never put a shortcode inside <code>href="…"</code> with double quotes — use <code>[hub_link]</code> or <code>[hub_image]</code>, which output the whole tag.', 'hub-client')); ?></li>
				<li><?php esc_html_e('Use [hub_link] without a closing tag — always self-closing.', 'hub-client'); ?></li>
				<li><?php esc_html_e('Empty output? Open the page logged in as administrator: a small red note in its place says why. Visitors never see it.', 'hub-client'); ?></li>
				<li><?php esc_html_e('Content is stored on this site and refreshed only when the hub reports a change. A brand-new section loads the first time an editor views it (or with "Refresh sections now").', 'hub-client'); ?></li>
				<li><?php esc_html_e('Language: by default the page\'s Polylang/WPML language; otherwise the first of "Languages to keep".', 'hub-client'); ?></li>
			</ul>

			<h2><?php esc_html_e('Sections in use on this site', 'hub-client'); ?></h2>
			<?php if (!$store) : ?>
				<p><?php esc_html_e('None yet. They appear here once a page with hub content has been viewed.', 'hub-client'); ?></p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr>
					<th><?php esc_html_e('Source', 'hub-client'); ?></th><th><?php esc_html_e('Language', 'hub-client'); ?></th><th><?php esc_html_e('Items', 'hub-client'); ?></th><th><?php esc_html_e('Last refreshed', 'hub-client'); ?></th><th><?php esc_html_e('Status', 'hub-client'); ?></th>
				</tr></thead><tbody>
				<?php foreach ($store as $e) : $q = $e['q']; ?>
					<tr>
						<td><code><?php echo esc_html(!empty($q['placement']) ? 'placement=' . $q['placement'] : 'type=' . ($q['type'] ?? '')); ?></code><?php echo isset($q['per_page']) ? ' <span class="description">limit ' . (int) $q['per_page'] . '</span>' : ''; ?></td>
						<td><?php echo esc_html($q['lang'] ?? '—'); ?></td>
						<td><?php echo isset($e['count']) ? (int) $e['count'] : '—'; ?></td>
						<td><?php echo !empty($e['fetched']) ? esc_html(wp_date('Y-m-d H:i', (int) $e['fetched'])) : esc_html__('not loaded yet', 'hub-client'); ?></td>
						<td><?php echo !empty($e['error']) ? '<span style="color:#b32d2e">' . esc_html($e['error']) . '</span>' : '<span style="color:#008a20">OK</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php if (current_user_can('manage_options')) : ?>
					<p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hub_client_refresh'), 'hub_client_refresh')); ?>"><?php esc_html_e('Refresh sections now', 'hub-client'); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<style>
			.hub-cheat h2 { margin-top: 2em; }
			.hub-pick { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; position: sticky; top: 32px; z-index: 5; }
			.hub-pick label { display: flex; gap: 6px; align-items: center; font-weight: 600; }
			.hub-cheat table.hub-codes td:first-child { width: 48%; }
			.hub-copy { cursor: copy; }
			.hub-cheat code.hub-copy { display: inline-block; padding: 3px 6px; }
			.hub-cheat pre.hub-copy { background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px 14px; max-width: 900px; white-space: pre-wrap; font-size: 13px; }
			.hub-copy.copied { outline: 2px solid #00a32a; }
			.hub-fields { line-height: 2.2; max-width: 1000px; }
			.hub-fields span { color: #646970; margin-right: 12px; }
		</style>
		<script>
		(function () {
			const picks = {};
			document.querySelectorAll('.hub-pick select').forEach(s => { picks[s.dataset.token] = s; s.addEventListener('change', fill); });
			function fill() {
				document.querySelectorAll('[data-tpl]').forEach(el => {
					let t = el.dataset.tpl;
					for (const k in picks) t = t.split('{' + k + '}').join(picks[k].value);
					el.textContent = t;
					el.dataset.copy = t;
				});
			}
			document.addEventListener('click', e => {
				const el = e.target.closest('.hub-copy');
				if (!el) return;
				const text = el.dataset.copy || el.textContent;
				const done = () => { el.classList.add('copied'); setTimeout(() => el.classList.remove('copied'), 900); };
				if (navigator.clipboard) navigator.clipboard.writeText(text).then(done); else {
					const r = document.createRange(); r.selectNodeContents(el); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done();
				}
			});
			fill();
		})();
		</script>
		<?php
	}

	private static function table(array $rows): void {
		echo '<table class="widefat striped hub-codes"><tbody>';
		foreach ($rows as [$code, $what]) {
			printf('<tr><td><code class="hub-copy" data-tpl="%s"></code></td><td>%s</td></tr>', esc_attr($code), esc_html($what));
		}
		echo '</tbody></table>';
	}
}
