<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Account\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for one signed-in visitor's own rows. Deliberately uncached: the answer is personal and changes whenever that visitor saves or submits. Every query is a constant literal with table names bound via %i and values via $wpdb->prepare(), so the PreparedSQL sniffs are satisfied on their own and are NOT suppressed here.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * One visitor's own rows: what they saved and what they sent in.
 *
 * Every method takes the user id as its FIRST filter and has no variant without it; the
 * controller passes get_current_user_id() and nothing from the request.
 */
final class AccountRepository extends Repository {

    /** Nothing is cached or flushed here. */
    protected function entities(): array {
        return ['recipes'];
    }

    /**
     * The first published page whose content carries one of two shortcodes; 0 when none.
     * Asked once a day at most (AccountPages keeps the answer in a transient).
     *
     * @param array{0:string, 1:string} $shortcodes Opening text, e.g. '[plugixa_shopping_list'.
     */
    public function page_with_shortcode(array $shortcodes): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT ID FROM %i
              WHERE post_type = %s AND post_status = %s
                AND ( post_content LIKE %s OR post_content LIKE %s )
              ORDER BY ID ASC
              LIMIT 1',
            $wpdb->posts,
            'page',
            'publish',
            '%' . $wpdb->esc_like((string) $shortcodes[0]) . '%',
            '%' . $wpdb->esc_like((string) $shortcodes[1]) . '%'
        ));
    }

    /**
     * The published recipes a user saved, newest save first.
     *
     * A saved recipe that was since unpublished is left out, not shown as a dead link:
     * `status = 'published'` is a hard literal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function saved(int $user_id, int $limit): array {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT r.id, r.title, r.slug, r.image_id, r.total_time, r.difficulty,
                    r.rating_average, r.rating_count
               FROM %i f
              INNER JOIN %i r ON r.id = f.recipe_id
              WHERE f.user_id = %d AND r.status = \'published\'
              ORDER BY f.created_at DESC, f.id DESC
              LIMIT %d',
            $this->table('favorites'),
            $this->table('recipes'),
            $user_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * The recipes a user created, newest first, WITH their status - the one place a status
     * goes to a visitor, because it is the status of their own recipe ("is it live yet?").
     *
     * @return array<int, array<string, mixed>>
     */
    public function submissions(int $user_id, int $limit): array {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, slug, image_id, status, created_at
               FROM %i
              WHERE created_by = %d
              ORDER BY created_at DESC, id DESC
              LIMIT %d',
            $this->table('recipes'),
            $user_id,
            $limit
        ), ARRAY_A);
    }
}
