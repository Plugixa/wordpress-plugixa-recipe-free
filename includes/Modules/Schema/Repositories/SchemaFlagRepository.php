<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Schema\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One custom-table write: the per-recipe structured-data switch, followed by a flush of the recipe cache.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * The per-recipe "include structured data" switch the editor's SEO card sends.
 *
 * Kept apart from JsonLdRepository on purpose: that one is the public read path and holds
 * no write at all.
 */
final class SchemaFlagRepository extends Repository {

    protected function entities(): array {
        return ['recipes'];
    }

    /** Reached from plugixa_recipe_save_recipe. */
    public function set_enabled(int $recipe_id, bool $enabled): void {
        global $wpdb;

        $wpdb->update($this->table('recipes'), ['enable_schema' => (int) $enabled], ['id' => $recipe_id]);

        $this->flush();
    }
}
