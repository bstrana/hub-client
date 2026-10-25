<?php
defined('ABSPATH') || exit;

/**
 * Small admin UI kit: page header with status chips, tabs, cards, chips, copy buttons.
 * Plain CSS/JS printed once per page; WordPress admin colours; works without JS
 * (all panels stay visible) and remembers the last tab per page.
 */
class HC_UI {

	private static bool $printed = false;

	public static function assets(): void {
		if (self::$printed) return;
		self::$printed = true;
		?>
		<style>
		.hubui{--hu-accent:#3858e9;--hu-accent-soft:#eef1fd;--hu-border:#dcdcde;--hu-muted:#646970;--hu-card:#fff;max-width:1180px}
		.hubui-head{display:flex;flex-wrap:wrap;align-items:center;gap:12px 18px;margin:18px 0 6px;padding:18px 22px;background:linear-gradient(135deg,var(--hu-accent) 0%,#6b3fd4 100%);border-radius:10px;color:#fff}
		.hubui-head h1{color:#fff;margin:0;padding:0;font-size:22px;line-height:1.3}
		.hubui-head .hubui-sub{opacity:.85;font-size:13px;margin-top:2px}
		.hubui-head .hubui-chips{margin-left:auto;display:flex;flex-wrap:wrap;gap:6px}
		.hubui-head .hubui-chip{background:rgba(255,255,255,.16);color:#fff;border-color:rgba(255,255,255,.28)}
		.hubui-head .hubui-chip.ok::before{background:#7ee2a0}.hubui-head .hubui-chip.warn::before{background:#ffd66b}.hubui-head .hubui-chip.bad::before{background:#ff9a9a}
		.hubui-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;line-height:18px;border:1px solid transparent;white-space:nowrap}
		.hubui-chip::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
		.hubui-chip.ok{background:#edfaef;color:#007017;border-color:#b8e6c3}
		.hubui-chip.warn{background:#fcf9e8;color:#8a6100;border-color:#f0e0a6}
		.hubui-chip.bad{background:#fcf0f1;color:#b32d2e;border-color:#f4c2c3}
		.hubui-chip.info{background:#f0f6fc;color:#135e96;border-color:#c5d9ed}
		.hubui-tabs{display:flex;flex-wrap:wrap;gap:4px;margin:16px 0 0;border-bottom:1px solid var(--hu-border)}
		.hubui-tabs button{appearance:none;border:1px solid transparent;border-bottom:0;background:none;padding:10px 16px;margin-bottom:-1px;font-size:14px;font-weight:500;color:var(--hu-muted);cursor:pointer;border-radius:8px 8px 0 0;display:inline-flex;align-items:center;gap:7px}
		.hubui-tabs button:hover{color:var(--hu-accent);background:var(--hu-accent-soft)}
		.hubui-tabs button[aria-selected="true"]{color:var(--hu-accent);background:#f0f0f1;border-color:var(--hu-border);border-bottom:1px solid #f0f0f1}
		.hubui-tabs .dashicons{font-size:17px;width:17px;height:17px}
		.hubui-tabs .hubui-count{background:var(--hu-accent);color:#fff;border-radius:999px;font-size:11px;padding:0 7px;line-height:17px}
		.hubui-panel{padding-top:16px}
		.hubui.js .hubui-panel[hidden]{display:none}
		.hubui-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start}
		.hubui-card{background:var(--hu-card);border:1px solid var(--hu-border);border-radius:10px;padding:18px 20px;margin:0 0 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.hubui-card.accent{border-top:3px solid var(--hu-accent)}
		.hubui-card h2{margin:0 0 4px;font-size:15px;display:flex;align-items:center;gap:8px}
		.hubui-card h2 .dashicons{color:var(--hu-accent)}
		.hubui-card > .hubui-desc{color:var(--hu-muted);margin:0 0 12px}
		.hubui-card .form-table{margin-top:0}
		.hubui-card .form-table th{padding-left:0;width:220px}
		.hubui-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px}
		.hubui-code{display:flex;align-items:center;gap:8px;background:#f6f7f7;border:1px solid var(--hu-border);border-radius:6px;padding:6px 8px;font-family:Menlo,Consolas,monospace;font-size:12.5px;word-break:break-all}
		.hubui-code code{background:none;padding:0;flex:1}
		.hubui-copy{cursor:pointer}
		.hubui-copy.copied,.hubui-copied{outline:2px solid #00a32a;outline-offset:1px}
		.hubui-table{width:100%;border-collapse:collapse}
		.hubui-table td,.hubui-table th{padding:8px 10px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top}
		.hubui-table th{font-weight:600;color:#1d2327}
		.hubui-muted{color:var(--hu-muted)}
		.hubui-kv{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:0}
		.hubui-kv dt{color:var(--hu-muted)}.hubui-kv dd{margin:0}
		@media (max-width:782px){.hubui-head .hubui-chips{margin-left:0}.hubui-card .form-table th{width:auto}}
		</style>
		<script>
		(function () {
			function init(root) {
				root.classList.add('js');
				var key = 'hubui-tab:' + (root.dataset.key || location.pathname + location.search);
				var buttons = root.querySelectorAll('.hubui-tabs [data-tab]');
				if (!buttons.length) return;
				function show(id, push) {
					var found = false;
					buttons.forEach(function (b) { var on = b.dataset.tab === id; b.setAttribute('aria-selected', on ? 'true' : 'false'); if (on) found = true; });
					if (!found) return false;
					root.querySelectorAll('.hubui-panel[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== id; });
					try { localStorage.setItem(key, id); } catch (e) {}
					if (push && history.replaceState) history.replaceState(null, '', '#' + id);
					return true;
				}
				buttons.forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); show(b.dataset.tab, true); }); });
				var start = (location.hash || '').replace('#', '');
				var stored = null; try { stored = localStorage.getItem(key); } catch (e) {}
				if (!(start && show(start)) && !(stored && show(stored))) show(buttons[0].dataset.tab);
				window.addEventListener('hashchange', function () { var h = (location.hash || '').replace('#', ''); if (h) show(h); });
				// A field that fails validation inside a hidden tab: open that tab.
				root.addEventListener('invalid', function (e) { var p = e.target.closest('.hubui-panel'); if (p && p.hidden) show(p.dataset.panel, true); }, true);
			}
			function copy(el) {
				var text = el.dataset.copy || el.textContent;
				var done = function () { el.classList.add('copied'); setTimeout(function () { el.classList.remove('copied'); }, 900); };
				if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {}); else { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
			}
			document.addEventListener('click', function (e) { var el = e.target.closest('.hubui-copy'); if (el) { e.preventDefault(); copy(el); } });
			document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('.hubui[data-tabs]').forEach(init); });
		})();
		</script>
		<?php
	}

	/** @param array<int,array{0:string,1:string}> $chips [text, ok|warn|bad|info] */
	public static function header(string $title, string $sub = '', array $chips = []): void {
		echo '<div class="hubui-head"><div><h1>' . esc_html($title) . '</h1>';
		if ($sub !== '') echo '<div class="hubui-sub">' . esc_html($sub) . '</div>';
		echo '</div>';
		if ($chips) {
			echo '<div class="hubui-chips">';
			foreach ($chips as [$text, $state]) echo self::chip($text, $state);
			echo '</div>';
		}
		echo '</div><hr class="wp-header-end" style="border:0;margin:0">'; // admin notices go below the header
	}

	public static function chip(string $text, string $state = 'info'): string {
		return '<span class="hubui-chip ' . esc_attr(in_array($state, ['ok', 'warn', 'bad', 'info'], true) ? $state : 'info') . '">' . esc_html($text) . '</span>';
	}

	/** @param array<string,array{0:string,1?:string,2?:int}> $tabs id => [label, dashicon, count] */
	public static function tabs(array $tabs): void {
		echo '<nav class="hubui-tabs" role="tablist">';
		foreach ($tabs as $id => $t) {
			printf('<button type="button" role="tab" data-tab="%s" aria-selected="false">%s%s%s</button>',
				esc_attr($id),
				!empty($t[1]) ? '<span class="dashicons dashicons-' . esc_attr($t[1]) . '" aria-hidden="true"></span>' : '',
				esc_html($t[0]),
				isset($t[2]) && $t[2] !== null ? ' <span class="hubui-count">' . (int) $t[2] . '</span>' : '');
		}
		echo '</nav>';
	}

	public static function panel_open(string $id): void {
		echo '<div class="hubui-panel" role="tabpanel" data-panel="' . esc_attr($id) . '">';
	}

	public static function panel_close(): void {
		echo '</div>';
	}

	public static function card_open(string $title, string $desc = '', string $icon = '', bool $accent = false): void {
		echo '<div class="hubui-card' . ($accent ? ' accent' : '') . '"><h2>'
			. ($icon !== '' ? '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>' : '')
			. esc_html($title) . '</h2>';
		if ($desc !== '') echo '<p class="hubui-desc">' . esc_html($desc) . '</p>';
	}

	public static function card_close(): void {
		echo '</div>';
	}

	/** A code line with a copy button. */
	public static function code(string $text): string {
		return '<div class="hubui-code"><code>' . esc_html($text) . '</code><button type="button" class="button button-small hubui-copy" data-copy="' . esc_attr($text) . '">' . esc_html__('Copy', 'hub-client') . '</button></div>';
	}

	/** Open the page wrapper (tabs = true enables tab switching). */
	public static function open(string $key, bool $tabs = true): void {
		self::assets();
		echo '<div class="wrap hubui"' . ($tabs ? ' data-tabs="1"' : '') . ' data-key="' . esc_attr($key) . '">';
	}

	public static function close(): void {
		echo '</div>';
	}
}
