<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Media;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Puts one image into the media library.
 *
 * The plugin used to call media_handle_upload() / media_handle_sideload() after loading
 * three wp-admin files (image, file, media) in a block, some way above the call. WordPress.org
 * asks for a core file to be loaded with require_once and for a function FROM THAT FILE to be
 * called straight after it - and of those three, only one was ever called by name. So the
 * work is written out here as the steps it consists of, each beside the one file it needs:
 *
 *   wp-admin/includes/file.php   ->  wp_handle_upload() / wp_handle_sideload()
 *   wp-admin/includes/image.php  ->  wp_generate_attachment_metadata()
 *
 * wp-admin/includes/media.php is not loaded at all: it is where audio and video are read,
 * and everything this class accepts is an image.
 *
 * This class decides nothing about WHETHER a file is acceptable. Callers validate first - the
 * upload error, the size cap, the count, and the real MIME through wp_check_filetype_and_ext()
 * - and hand over an array whose `name` is already sanitize_file_name()d and whose `type` is
 * the MIME they derived, never the browser's. The list below is passed to WordPress
 * explicitly all the same, so a site that allows other uploads does not widen it here.
 */
final class Uploader {

    /** The image types the plugin stores, in the form wp_handle_upload() expects. */
    public const IMAGE_MIMES = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    ];

    /**
     * Store a file that arrived as an HTTP upload.
     *
     * @param array<string, mixed> $file One validated $_FILES entry: name, type, tmp_name, error, size.
     * @return int|WP_Error The attachment id.
     */
    public static function store(array $file) {
        return self::attach($file, false, '');
    }

    /**
     * Store a file that is already on this server (downloaded, generated).
     *
     * @param array<string, mixed> $file  name and tmp_name.
     * @param string               $title Attachment title; the file name is used when empty.
     * @return int|WP_Error The attachment id.
     */
    public static function sideload(array $file, string $title = '') {
        return self::attach($file, true, $title);
    }

    /**
     * @param array<string, mixed> $file
     * @return int|WP_Error
     */
    private static function attach(array $file, bool $sideload, string $title) {
        // WordPress's uploader lives in a file only wp-admin loads; a REST request does not
        // have it. Loaded here for the call that follows.
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = ['test_form' => false, 'mimes' => self::IMAGE_MIMES];
        $handled   = $sideload ? wp_handle_sideload($file, $overrides) : wp_handle_upload($file, $overrides);

        if (!is_array($handled) || isset($handled['error'])) {
            return new WP_Error(
                'plugixa_recipe_upload_failed',
                is_array($handled) ? (string) $handled['error'] : __('The image could not be saved.', 'plugixa-recipe'),
                ['status' => 500]
            );
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $handled['type'],
                'post_title'     => '' !== $title ? sanitize_text_field($title) : sanitize_text_field(pathinfo($handled['file'], PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'guid'           => $handled['url'],
            ],
            $handled['file']
        );

        if (is_wp_error($attachment_id) || 0 === $attachment_id) {
            // The file is on disk but nothing points at it: removed rather than left as an
            // orphan nobody will find in the media library.
            wp_delete_file($handled['file']);

            return new WP_Error('plugixa_recipe_upload_failed', __('The image could not be saved.', 'plugixa-recipe'), ['status' => 500]);
        }

        // The sizes and dimensions. Same reason as the uploader above, a different file.
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $handled['file']));

        return (int) $attachment_id;
    }
}
