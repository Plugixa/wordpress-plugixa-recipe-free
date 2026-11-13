<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Repositories;

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The option lists the editors offer: saved shortcodes, views and categories for the
 * Gutenberg block's inspector, saved shortcodes and views for the Elementor widget's
 * controls.
 *
 * Editor-only, uncached reads of names and ids. The two editors' lists are kept as separate
 * methods because their ORDER BY differs (the Elementor ones carry an id tiebreaker). This
 * class writes nothing, so it never flushes.
 *
 * No file-scoped phpcs:disable here: the direct-query sniffs are answered per line, which
 * is how the block's reads were already annotated, and a file may use only one mechanism
 * per sniff.
 */
final class EditorOptionsRepository extends Repository {

    /** Nothing is cached here; named for the base class's contract only. */
    protected function entities(): array {
        return ['shortcodes'];
    }

    /**
     * Saved shortcodes for the block editor dropdown.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function block_shortcodes() {
        global $wpdb;
        $table = $this->table('shortcodes');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        return $wpdb->get_results(
            $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $table),
            ARRAY_A
        );
    }

    /**
     * Views for the block editor dropdown.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function block_views() {
        global $wpdb;
        $table = $this->table('views');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        return $wpdb->get_results(
            $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $table),
            ARRAY_A
        );
    }

    /**
     * Categories for the block editor.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function block_categories() {
        global $wpdb;
        $table = $this->table('categories');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, block-editor load only.
        return $wpdb->get_results(
            $wpdb->prepare('SELECT id, name, slug FROM %i ORDER BY display_order ASC', $table),
            ARRAY_A
        );
    }

    /**
     * Published recipes for the "Recipe" block and Elementor widget, by title. Capped:
     * the list is printed into the editor page.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function recipes() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, editor load only.
        return $wpdb->get_results(
            $wpdb->prepare('SELECT id, title FROM %i WHERE status = \'published\' ORDER BY title ASC, id ASC LIMIT 500', $this->table('recipes')),
            ARRAY_A
        );
    }

    /**
     * Saved shortcodes for the Elementor control.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function elementor_shortcodes() {
        global $wpdb;
        $table = $this->table('shortcodes');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, Elementor editor load only.
        return $wpdb->get_results($wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC, id ASC', $table), ARRAY_A);
    }

    /**
     * Views for the Elementor control.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function elementor_views() {
        global $wpdb;
        $table = $this->table('views');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; one small read, Elementor editor load only.
        return $wpdb->get_results($wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC, id ASC', $table), ARRAY_A);
    }
}
