<?php
defined('ABSPATH') || exit;

/**
 * Element creator: pick a pattern and a hub source, get a ready Bricks section to paste
 * into the builder (Ctrl/Cmd+V, or right-click → Paste). Built in the browser as Bricks'
 * own clipboard format ("bricksCopiedElements") with native elements and {hub:…} tags,
 * so everything stays editable and styleable in Bricks. Lists of any length use a
 * Shortcode element with [hub_loop].
 */
class HC_Creator {

	public static function render(): void {
		$patterns = [
			'hero'   => ['cover-image', __('Hero', 'hub-client'), __('Badge, title, subtitle, image, two buttons', 'hub-client')],
			'cards'  => ['grid-view', __('Cards (fixed number)', 'hub-client'), __('Native Bricks cards — 1st, 2nd, 3rd … item', 'hub-client')],
			'list'   => ['list-view', __('Cards (any number)', 'hub-client'), __('A loop that grows with the hub', 'hub-client')],
			'text'   => ['text-page', __('Text block', 'hub-client'), __('Title, content and a button', 'hub-client')],
			'event'  => ['calendar-alt', __('Next event', 'hub-client'), __('Date, place, directions, live stream, map', 'hub-client')],
			'events' => ['calendar', __('Upcoming events', 'hub-client'), __('List of events that haven\'t ended', 'hub-client')],
			'logos'  => ['awards', __('Logo row', 'hub-client'), __('Partners or sponsors with links', 'hub-client')],
			'faq'    => ['editor-help', __('FAQ', 'hub-client'), __('Questions that open and close', 'hub-client')],
		];
		?>
		<div class="hubc-wrap">
			<div class="hubui-card accent hubc-controls">
				<h2><span class="dashicons dashicons-layout" aria-hidden="true"></span><?php esc_html_e('1. Pattern', 'hub-client'); ?></h2>
				<div class="hubc-patterns" role="radiogroup">
					<?php $first = true; foreach ($patterns as $id => [$icon, $label, $desc]) : ?>
						<label class="hubc-pattern"><input type="radio" name="hubc_pattern" value="<?php echo esc_attr($id); ?>" <?php checked($first); $first = false; ?>>
							<span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($desc); ?></small></label>
					<?php endforeach; ?>
				</div>

				<h2 style="margin-top:14px"><span class="dashicons dashicons-database" aria-hidden="true"></span><?php esc_html_e('2. Content from', 'hub-client'); ?></h2>
				<div class="hubc-row">
					<label><input type="radio" name="hubc_source" value="placement" checked> <?php esc_html_e('Placement', 'hub-client'); ?></label>
					<label><input type="radio" name="hubc_source" value="type"> <?php esc_html_e('Content type (newest)', 'hub-client'); ?></label>
					<label><input type="radio" name="hubc_source" value="tag"> <?php esc_html_e('Tag (newest)', 'hub-client'); ?></label>
					<label><input type="radio" name="hubc_source" value="next"> <?php esc_html_e('Event type (upcoming)', 'hub-client'); ?></label>
					<span class="hubui-muted"><?php esc_html_e('Which one: the pickers at the top.', 'hub-client'); ?></span>
				</div>

				<h2 style="margin-top:14px"><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span><?php esc_html_e('3. Options', 'hub-client'); ?></h2>
				<div class="hubc-row">
					<label><?php esc_html_e('Section title', 'hub-client'); ?> <input type="text" id="hubc_title" class="regular-text" style="width:16em" placeholder="<?php esc_attr_e('optional', 'hub-client'); ?>"></label>
					<label><?php esc_html_e('Items', 'hub-client'); ?> <input type="number" id="hubc_count" min="1" max="12" value="3" class="small-text"></label>
					<label><?php esc_html_e('Columns', 'hub-client'); ?> <input type="number" id="hubc_cols" min="1" max="6" value="3" class="small-text"></label>
					<label><?php esc_html_e('Button text', 'hub-client'); ?> <input type="text" id="hubc_more" value="<?php esc_attr_e('Read more', 'hub-client'); ?>" style="width:9em"></label>
				</div>
				<div class="hubc-row">
					<label><input type="radio" name="hubc_theme" value="light" checked> <?php esc_html_e('Light', 'hub-client'); ?></label>
					<label><input type="radio" name="hubc_theme" value="dark"> <?php esc_html_e('Dark', 'hub-client'); ?></label>
					<label><?php esc_html_e('Accent', 'hub-client'); ?> <input type="color" id="hubc_accent" value="#3858e9"></label>
					<label><input type="checkbox" id="hubc_lang"> <?php esc_html_e('Fixed language (picker above) instead of the page\'s language', 'hub-client'); ?></label>
				</div>
			</div>

			<div class="hubui-card accent">
				<h2><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><?php esc_html_e('4. Paste into Bricks', 'hub-client'); ?></h2>
				<p class="hubui-desc"><?php esc_html_e('Copy, open the page in Bricks, select where it should go (or nothing, for the end of the page) and press Ctrl+V / Cmd+V — or right-click → Paste. Everything stays editable: change texts, styles and classes in Bricks as usual.', 'hub-client'); ?></p>
				<p class="hubui-actions">
					<button type="button" class="button button-primary button-hero" id="hubc_copy"><span class="dashicons dashicons-admin-page" style="margin-top:12px"></span> <?php esc_html_e('Copy for Bricks', 'hub-client'); ?></button>
					<button type="button" class="button" id="hubc_copy_sc"><?php esc_html_e('Copy as shortcode (other builders)', 'hub-client'); ?></button>
					<span id="hubc_msg" class="hubui-chip ok" hidden><?php esc_html_e('Copied — now paste in Bricks', 'hub-client'); ?></span>
				</p>
				<div class="hubc-preview">
					<div><strong><?php esc_html_e('Structure', 'hub-client'); ?></strong><ul id="hubc_tree"></ul></div>
					<div><strong><?php esc_html_e('Shortcode version', 'hub-client'); ?></strong><pre id="hubc_sc"></pre></div>
				</div>
				<details><summary><?php esc_html_e('Show the Bricks JSON', 'hub-client'); ?></summary><textarea id="hubc_json" readonly rows="12" class="large-text code"></textarea></details>
			</div>
		</div>
		<style>
			.hubc-patterns{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
			.hubc-pattern{position:relative;display:flex;flex-direction:column;gap:3px;border:1px solid #dcdcde;border-radius:10px;padding:12px;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s}
			.hubc-pattern:hover{border-color:#3858e9}
			.hubc-pattern input{position:absolute;opacity:0;pointer-events:none}
			.hubc-pattern .dashicons{color:#3858e9;font-size:22px;width:22px;height:22px}
			.hubc-pattern small{color:#646970}
			.hubc-pattern:has(input:checked){border-color:#3858e9;box-shadow:0 0 0 2px #3858e9 inset;background:#eef1fd}
			.hubc-row{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin:6px 0}
			.hubc-preview{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:12px 0}
			.hubc-preview ul{margin:6px 0 0;font-family:Menlo,Consolas,monospace;font-size:12px}
			.hubc-preview li{margin:2px 0}
			.hubc-preview li span{color:#646970}
			.hubc-preview pre{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;white-space:pre-wrap;font-size:12px;max-height:340px;overflow:auto;margin:6px 0 0}
			@media (max-width:960px){.hubc-preview{grid-template-columns:1fr}}
		</style>
		<script>
		(function () {
			var $ = function (s) { return document.querySelector(s); };
			var val = function (name) { var e = document.querySelector('input[name="' + name + '"]:checked'); return e ? e.value : ''; };
			var picks = function () { return window.hubPicks || {}; };
			var pick = function (k, d) { var p = picks()[k]; return p && p.value ? p.value : d; };
			var siteUrl = <?php echo wp_json_encode(home_url('/')); ?>;
			var bricksVersion = <?php echo wp_json_encode(defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : '2.0'); ?>;
			var T9 = <?php echo wp_json_encode(['directions' => __('Directions', 'hub-client'), 'live' => __('Watch live', 'hub-client'), 'next' => __('Next event', 'hub-client'), 'none' => __('No upcoming events.', 'hub-client')]); ?>;

			function rid() { var c = 'abcdefghijklmnopqrstuvwxyz0123456789', s = ''; for (var i = 0; i < 6; i++) s += c[Math.floor(Math.random() * c.length)]; return s; }

			function build() {
				var pattern = val('hubc_pattern');
				var source  = val('hubc_source');
				// Some patterns only make sense with one source.
				if (pattern === 'event' || pattern === 'events') source = 'next';
				var P = pick('P', 'home-hero'), T = pick('T', 'article'), E = pick('E', 'event'), G = pick('G', 'my-tag'), L = pick('L', '');
				var lang  = $('#hubc_lang').checked ? L : '';
				var count = Math.max(1, Math.min(12, parseInt($('#hubc_count').value, 10) || 3));
				var cols  = Math.max(1, Math.min(6, parseInt($('#hubc_cols').value, 10) || 3));
				var title = $('#hubc_title').value.trim();
				var more  = $('#hubc_more').value.trim() || 'Read more';
				var dark  = val('hubc_theme') === 'dark';
				var accent = $('#hubc_accent').value || '#3858e9';
				var C = dark ? { bg: '#0f172a', text: '#f8fafc', muted: '#cbd5e1', card: '#1e293b', border: '#334155' } : { bg: '#ffffff', text: '#0f172a', muted: '#475569', card: '#f8fafc', border: '#e2e8f0' };

				var src = { placement: P, type: 'type.' + T, tag: 'tag.' + G, next: 'next.' + E }[source];
				var article = source !== 'placement';     // items with their own page: link to it
				var tag = function (field, i) {
					var t = '{hub:' + src + ':' + field;
					if ((i && i > 1) || lang) t += ':' + (i || 1);
					if (lang) t += ':' + lang;
					return t + '}';
				};
				var scSrc = { placement: 'placement="' + P + '"', type: 'type="' + T + '"', tag: 'tag="' + G + '"', next: 'type="' + E + '" upcoming="1"' }[source] + (lang ? ' lang="' + lang + '"' : '');

				// ---- element helpers
				var els = [];
				function el(name, parent, settings, label) {
					var e = { id: rid(), name: name, parent: parent ? parent.id : 0, children: [], settings: settings || {} };
					if (label) e.label = label;
					if (parent) parent.children.push(e.id);
					els.push(e);
					return e;
				}
				var cls = function (c) { return { _cssClasses: c }; };
				var typo = function (size, weight, color, extra) { var t = { 'font-size': String(size), 'font-weight': String(weight || 400), color: { hex: color || C.text } }; for (var k in (extra || {})) t[k] = extra[k]; return t; };
				var pad = function (t, r, b, l) { return { top: String(t), right: String(r), bottom: String(b), left: String(l) }; };
				var radius = function (n) { return { radius: { top: String(n), right: String(n), bottom: String(n), left: String(n) } }; };
				function section(cssClass, label) {
					var s = el('section', null, { _background: { color: { hex: C.bg } }, _padding: pad(80, 20, 80, 20), '_padding:mobile_portrait': pad(50, 16, 50, 16), _hidden: cls(cssClass) }, label);
					var c = el('container', s, { _rowGap: '28', _alignItems: 'stretch' });
					return c;
				}
				function heading(parent, text, tagName, size, extra) {
					var s = { text: text, tag: tagName, _typography: typo(size, 700, C.text, extra || {}) };
					if (tagName === 'h1') s['_typography:mobile_portrait'] = { 'font-size': String(Math.round(size * 0.7)) };
					return el('heading', parent, s);
				}
				function textBasic(parent, text, size, color) { return el('text-basic', parent, { text: text, _typography: typo(size || 17, 400, color || C.muted, { 'line-height': '1.6' }) }); }
				function button(parent, text, link, primary) {
					return el('button', parent, {
						text: text,
						link: { type: 'meta', useDynamicData: link },
						_padding: pad(12, 22, 12, 22),
						_border: Object.assign({ width: pad(2, 2, 2, 2), style: 'solid', color: { hex: accent } }, radius(8)),
						_background: primary ? { color: { hex: accent } } : { color: { raw: 'rgba(0,0,0,0)' } },
						_typography: typo(15, 600, primary ? '#ffffff' : accent)
					});
				}
				function image(parent, tagText, ratio) {
					return el('image', parent, { image: { useDynamicData: tagText, size: 'large' }, _width: '100%', _aspectRatio: ratio || '16/9', _objectFit: 'cover', _border: radius(10) });
				}
				function shortcode(parent, code) { return el('shortcode', parent, { shortcode: code }); }
				function maybeTitle(c, center) { if (title) heading(c, title, 'h2', 34, center ? { 'text-align': 'center' } : {}); }

				var sc = '';   // shortcode version for other builders
				var loopCss = '<style>.hub-cards{display:grid;grid-template-columns:repeat(' + cols + ',1fr);gap:24px}.hub-cards .card{background:' + C.card + ';border:1px solid ' + C.border + ';border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:10px;color:' + C.text + '}.hub-cards img{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:8px}.hub-cards a.btn{color:' + accent + ';font-weight:600}@media(max-width:767px){.hub-cards{grid-template-columns:1fr}}</style>';

				if (pattern === 'hero') {
					var c = section('hub-hero', 'Hub hero');
					var row = el('block', c, { _direction: 'row', _columnGap: '48', _rowGap: '48', _alignItems: 'center', '_direction:mobile_portrait': 'column' });
					var txt = el('block', row, { _rowGap: '16', _flexBasis: '0', _flexGrow: '1' });
					el('text-basic', txt, { text: tag('badge'), _padding: pad(4, 12, 4, 12), _border: radius(999), _background: { color: { hex: accent } }, _typography: typo(13, 600, '#ffffff', { 'text-transform': 'uppercase', 'letter-spacing': '1' }), _alignSelf: 'flex-start' });
					heading(txt, tag('title'), 'h1', 52, { 'line-height': '1.1' });
					textBasic(txt, tag('subtitle'), 20);
					var btns = el('block', txt, { _direction: 'row', _columnGap: '12', _rowGap: '12', _flexWrap: 'wrap' });
					button(btns, article ? more : tag('cta_label'), tag(article ? 'link' : 'cta_url'), true);
					if (!article) button(btns, tag('cta2_label'), tag('cta2_url'), false);
					var imgCol = el('block', row, { _flexBasis: '0', _flexGrow: '1', _width: '100%' });
					image(imgCol, tag('image'), '4/3');
					sc = '<section class="hub-hero">\n  <span class="badge">[hub ' + scSrc + ' field="badge"]</span>\n  <h1>[hub ' + scSrc + ' field="title"]</h1>\n  <p>[hub ' + scSrc + ' field="subtitle"]</p>\n  [hub_link ' + scSrc + ' class="btn"]\n  [hub_link ' + scSrc + ' to="cta2" class="btn btn-2"]\n  [hub_image ' + scSrc + ' size="large" loading="eager"]\n</section>';
				} else if (pattern === 'cards') {
					var c2 = section('hub-cards', 'Hub cards');
					maybeTitle(c2);
					var grid = el('block', c2, { _display: 'grid', _gridTemplateColumns: 'repeat(' + cols + ', 1fr)', '_gridTemplateColumns:tablet_portrait': 'repeat(' + Math.min(cols, 2) + ', 1fr)', '_gridTemplateColumns:mobile_portrait': '1fr', _gridGap: '24' });
					for (var i = 1; i <= count; i++) {
						var card = el('block', grid, { _rowGap: '12', _padding: pad(18, 18, 22, 18), _background: { color: { hex: C.card } }, _border: Object.assign({ width: pad(1, 1, 1, 1), style: 'solid', color: { hex: C.border } }, radius(12)), _hidden: cls('hub-card') }, 'Card ' + i);
						image(card, tag('image', i));
						heading(card, tag('title', i), 'h3', 20);
						textBasic(card, tag('excerpt', i), 15);
						button(card, article ? more : tag('cta_label', i), tag(article ? 'link' : 'cta_url', i), false);
					}
					sc = loopCss + '\n[hub_loop ' + scSrc + ' limit="' + count + '" wrap="div" class="hub-cards"]\n  <article class="card">\n    [hub_image size="medium_large"]\n    <h3>[hub field="title"]</h3>\n    <p>[hub field="excerpt"]</p>\n    ' + (article ? '[hub_link to="article" text="' + more + '" class="btn"]' : '[hub_link class="btn"]') + '\n  </article>\n[/hub_loop]';
				} else if (pattern === 'list') {
					var c3 = section('hub-list', 'Hub list');
					maybeTitle(c3);
					sc = loopCss + '\n[hub_loop ' + scSrc + ' limit="' + Math.max(count, 6) + '" wrap="div" class="hub-cards" empty=""]\n  <article class="card">\n    [hub_image size="medium_large"]\n    <h3>[hub field="title"]</h3>\n    <p>[hub field="excerpt"]</p>\n    ' + (article ? '[hub_link to="article" text="' + more + '" class="btn"]' : '[hub_link class="btn"]') + '\n  </article>\n[/hub_loop]';
					shortcode(c3, sc);
				} else if (pattern === 'text') {
					var c4 = section('hub-text', 'Hub text');
					var inner = el('block', c4, { _rowGap: '18' });
					heading(inner, tag('title'), 'h2', 34);
					el('text', inner, { text: tag('html'), _typography: typo(17, 400, C.muted, { 'line-height': '1.7' }) });
					button(inner, article ? more : tag('cta_label'), tag(article ? 'link' : 'cta_url'), true);
					sc = '<div class="hub-text">\n  <h2>[hub ' + scSrc + ' field="title"]</h2>\n  [hub ' + scSrc + ' field="html"]\n  [hub_link ' + scSrc + ' class="btn"]\n</div>';
				} else if (pattern === 'event') {
					var c5 = section('hub-next-event', 'Hub next event');
					var card5 = el('block', c5, { _direction: 'row', _columnGap: '32', _rowGap: '32', '_direction:mobile_portrait': 'column', _padding: pad(28, 28, 28, 28), _background: { color: { hex: C.card } }, _border: Object.assign({ width: pad(1, 1, 1, 1), style: 'solid', color: { hex: C.border } }, radius(14)) });
					var info = el('block', card5, { _rowGap: '12', _flexBasis: '0', _flexGrow: '1' });
					el('text-basic', info, { text: title || T9.next, _typography: typo(13, 700, accent, { 'text-transform': 'uppercase', 'letter-spacing': '1' }) });
					heading(info, tag('title'), 'h2', 32);
					textBasic(info, tag('event_date') + ' · ' + tag('event_time'), 18, C.text);
					textBasic(info, tag('location'), 16);
					var b5 = el('block', info, { _direction: 'row', _columnGap: '10', _rowGap: '10', _flexWrap: 'wrap' });
					button(b5, more, tag('link'), true);
					button(b5, T9.directions, tag('map_url'), false);
					button(b5, T9.live, tag('stream_url'), false);
					var mapCol = el('block', card5, { _flexBasis: '0', _flexGrow: '1', _width: '100%' });
					shortcode(mapCol, '[hub_map ' + scSrc + ' height="300" link=""]');
					sc = '<div class="hub-next-event">\n  <h2>[hub ' + scSrc + ' field="title" default="—"]</h2>\n  <p>[hub ' + scSrc + ' field="event_date"] · [hub ' + scSrc + ' field="event_time"] · [hub ' + scSrc + ' field="location"]</p>\n  [hub_link ' + scSrc + ' to="article" text="' + more + '"]\n  [hub_link ' + scSrc + ' to="map" text="' + T9.directions + '"]\n  [hub_link ' + scSrc + ' to="stream" text="' + T9.live + '"]\n  [hub_map ' + scSrc + ' height="300" link=""]\n</div>';
				} else if (pattern === 'events') {
					var c6 = section('hub-events', 'Hub upcoming events');
					maybeTitle(c6);
					sc = '<style>.hub-events-list{list-style:none;margin:0;padding:0;display:grid;gap:12px}.hub-events-list li{display:grid;grid-template-columns:150px 1fr auto;gap:16px;align-items:center;background:' + C.card + ';border:1px solid ' + C.border + ';border-radius:12px;padding:14px 18px;color:' + C.text + '}.hub-events-list time{font-weight:700;color:' + accent + '}.hub-events-list a{color:' + accent + ';font-weight:600}@media(max-width:767px){.hub-events-list li{grid-template-columns:1fr}}</style>\n[hub_loop ' + scSrc + ' limit="' + Math.max(count, 5) + '" wrap="ul" class="hub-events-list" empty="' + T9.none + '"]\n  <li>\n    <time>[hub field="event_start" format="j.n.Y. H:i"]</time>\n    <div><strong>[hub_link to="article" text="title"]</strong><br>[hub field="location"]</div>\n    <span>[hub_link to="map" text="' + T9.directions + '"] [hub_link to="stream" text="Live"]</span>\n  </li>\n[/hub_loop]';
					shortcode(c6, sc);
				} else if (pattern === 'logos') {
					var c7 = section('hub-logos', 'Hub logos');
					maybeTitle(c7, true);
					sc = '<style>.hub-logo-row{display:flex;flex-wrap:wrap;gap:28px 40px;align-items:center;justify-content:center}.hub-logo-row img{max-height:64px;width:auto;filter:grayscale(1);opacity:.8;transition:.2s}.hub-logo-row a:hover img{filter:none;opacity:1}</style>\n[hub_loop ' + scSrc + ' limit="' + Math.max(count, 12) + '" wrap="div" class="hub-logo-row"]\n  <a href="[hub field=\'cta_url\']" target="_blank" rel="noopener" title="[hub field=\'title\']">[hub_image size="medium"]</a>\n[/hub_loop]';
					shortcode(c7, sc);
				} else if (pattern === 'faq') {
					var c8 = section('hub-faq', 'Hub FAQ');
					maybeTitle(c8);
					sc = '<style>.hub-faq-list details{border-bottom:1px solid ' + C.border + ';padding:14px 0;color:' + C.text + '}.hub-faq-list summary{cursor:pointer;font-weight:600;font-size:18px;list-style:none;display:flex;justify-content:space-between;gap:12px}.hub-faq-list summary::after{content:"+";color:' + accent + ';font-size:22px;line-height:1}.hub-faq-list details[open] summary::after{content:"–"}.hub-faq-list .a{padding-top:10px;color:' + C.muted + '}</style>\n[hub_loop ' + scSrc + ' limit="' + Math.max(count, 10) + '" wrap="div" class="hub-faq-list"]\n  <details>\n    <summary>[hub field="title"]</summary>\n    <div class="a">[hub field="html"]</div>\n  </details>\n[/hub_loop]';
					shortcode(c8, sc);
				}

				var data = { content: els, source: 'bricksCopiedElements', sourceUrl: siteUrl, version: bricksVersion, globalClasses: [], globalElements: [] };
				return { data: data, sc: sc, pattern: pattern, source: source };
			}

			function tree(els) {
				var byId = {}; els.forEach(function (e) { byId[e.id] = e; });
				var out = '';
				function walk(e, depth) {
					var hint = e.settings.text || (e.settings.image && e.settings.image.useDynamicData) || (e.settings.shortcode ? '[shortcode]' : '') || e.label || '';
					out += '<li style="margin-left:' + (depth * 14) + 'px">' + e.name + (hint ? ' <span>' + String(hint).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }).slice(0, 70) + '</span>' : '') + '</li>';
					e.children.forEach(function (id) { walk(byId[id], depth + 1); });
				}
				els.filter(function (e) { return e.parent === 0; }).forEach(function (e) { walk(e, 0); });
				return out;
			}

			var current = null;
			function update() {
				current = build();
				$('#hubc_tree').innerHTML = tree(current.data.content);
				$('#hubc_sc').textContent = current.sc;
				$('#hubc_json').value = JSON.stringify(current.data, null, 2);
				document.querySelectorAll('input[name="hubc_source"]').forEach(function (r) {
					var fixed = current.pattern === 'event' || current.pattern === 'events';
					r.disabled = fixed && r.value !== 'next';
					if (fixed && r.value === 'next') r.checked = true;
				});
			}
			function copyText(text, msg) {
				var ok = function () { var m = $('#hubc_msg'); m.textContent = msg; m.hidden = false; setTimeout(function () { m.hidden = true; }, 2500); };
				if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok, function () { fallback(text); ok(); });
				else { fallback(text); ok(); }
			}
			function fallback(text) { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (e) {} t.remove(); }

			document.addEventListener('DOMContentLoaded', function () {
				document.querySelectorAll('.hubc-wrap input').forEach(function (i) { i.addEventListener('input', update); i.addEventListener('change', update); });
				document.addEventListener('hub-picks', update);
				document.querySelectorAll('.hub-pick select').forEach(function (s) { s.addEventListener('change', update); });
				$('#hubc_copy').addEventListener('click', function () { update(); copyText(JSON.stringify(current.data), <?php echo wp_json_encode(__('Copied — now paste in Bricks (Ctrl/Cmd+V)', 'hub-client')); ?>); });
				$('#hubc_copy_sc').addEventListener('click', function () { copyText(current.sc, <?php echo wp_json_encode(__('Shortcode copied', 'hub-client')); ?>); });
				update();
			});
			window.hubCreatorBuild = build; // for testing
		})();
		</script>
		<?php
	}
}
