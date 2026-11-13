<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Views\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the shortcode renderers. The listing and its child rows go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user like/favourite lookups and the small view/shortcode config reads are deliberately uncached. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The FRONTEND read of `views`: the stored config of one saved view, and nothing else.
 *
 * Kept apart from ViewRepository, which serves the admin REST routes. Every read here selects
 * the `config` column only, is deliberately uncached (as it always was), and this class
 * writes nothing - so it never flushes.
 */
final class ViewReadRepository extends Repository {

    protected function entities(): array {
        return ['views'];
    }

    /**
     * The view with this id.
     *
     * @return object|null The row (`config` only), or null when there is none.
     */
    public function config_by_id(int $id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT config FROM %i WHERE id = %d', $this->table('views'), $id
        ));
    }

    /**
     * The view with this slug. The caller sanitises the slug.
     *
     * @return object|null The row (`config` only), or null when there is none.
     */
    public function config_by_slug(string $slug) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT config FROM %i WHERE slug = %s', $this->table('views'), $slug
        ));
    }

    /**
     * The view marked as the default.
     *
     * @return object|null The row (`config` only), or null when no view is the default.
     */
    public function default_config() {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT config FROM %i WHERE is_default = 1 LIMIT 1', $this->table('views')));
    }

    /**
     * The oldest view - the last fallback when nothing is marked as the default.
     *
     * @return object|null The row (`config` only), or null when the table is empty.
     */
    public function first_config() {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT config FROM %i ORDER BY id ASC LIMIT 1', $this->table('views')));
    }
}
