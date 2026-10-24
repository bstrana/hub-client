<?php
defined('ABSPATH') || exit;

/**
 * Copies hub images into this site's media library, once per image URL.
 * Used for article copies and for page sections, so images are served from
 * this site's own domain (crawlable, same-origin, picked up by this site's
 * image optimiser / CDN) with WordPress-generated sizes and srcset.
 */
class HC_Media {

	/** @return int attachment ID, or 0 if it couldn't be copied */
	public static function local_id(string $url, string $alt = '', int $parent = 0): int {
		if ($url === '') return 0;
		static $seen = [];
		if (isset($seen[$url])) return $seen[$url];

		$existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_hub_image_src', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1, 'suppress_filters' => true]);
		if ($existing) {
			$id = (int) $existing[0];
		} else {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$id = media_sideload_image($url, $parent, $alt, 'id');
			if (is_wp_error($id)) {
				HC_Sync::log('image', $parent, $id->get_error_message() . ' (' . $url . ')');
				return $seen[$url] = 0;
			}
			$id = (int) $id;
			update_post_meta($id, '_hub_image_src', $url);
		}
		if ($alt !== '' && get_post_meta($id, '_wp_attachment_image_alt', true) !== $alt) {
			update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt));
		}
		return $seen[$url] = $id;
	}

	/** Add local attachment IDs to a list of hub items (in place). */
	public static function localize_items(array $items): array {
		if (!hub_client_opt('copy_images')) return $items;
		foreach ($items as &$item) {
			$src = (string) ($item['image']['full'] ?? $item['image']['url'] ?? '');
			if ($src !== '') {
				$item['image']['local_id'] = self::local_id($src, (string) ($item['image']['alt'] ?? ''));
			}
		}
		return $items;
	}
}
