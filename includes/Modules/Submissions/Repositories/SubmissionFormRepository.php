<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Submissions\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the shortcode renderers. The listing and its child rows go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user like/favourite lookups and the small view/shortcode config reads are deliberately uncached. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The option lists of the public submission form: every category, cuisine and course, as
 * id + name, ordered by name.
 *
 * Kept apart from SubmissionRepository, which serves the REST routes. Each list is cached
 * under its OWN entity (not under one shared entity), so a write to the cuisines invalidates
 * the cuisine list and nothing else. Read-only: nothing here writes, so nothing flushes.
 */
final class SubmissionFormRepository extends Repository {

    /** The three term entities the lists are read from; each read names its own below. */
    protected function entities(): array {
        return ['categories', 'cuisines', 'courses'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function category_options(): array {
        global $wpdb;

        // Read-through object cache; these queries take no inputs.
        return (array) $this->remember_as('categories', 'form_options', function () use ($wpdb) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $this->table('categories')),
                ARRAY_A
            ) ?: [];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cuisine_options(): array {
        global $wpdb;

        return (array) $this->remember_as('cuisines', 'form_options', function () use ($wpdb) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $this->table('cuisines')),
                ARRAY_A
            ) ?: [];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function course_options(): array {
        global $wpdb;

        return (array) $this->remember_as('courses', 'form_options', function () use ($wpdb) {
            return $wpdb->get_results(
                $wpdb->prepare('SELECT id, name FROM %i ORDER BY name ASC', $this->table('courses')),
                ARRAY_A
            ) ?: [];
        });
    }
}
