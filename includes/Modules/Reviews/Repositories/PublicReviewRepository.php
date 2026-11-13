<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer for the public routes. Content reads go through PlugixaRecipe\Core\Database\Cache, which the static sniff cannot trace through the wrapper; the per-user/per-IP state lookups (favourite, like, helpful vote), the write paths and the view/like counter updates are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed file-wide.

use PlugixaRecipe\Core\Database\Repository;

defined('ABSPATH') || exit;

/**
 * Every query the PUBLIC review routes run: the approved reviews shown under a recipe, a
 * visitor's submission, and the helpful votes.
 *
 * Kept apart from ReviewRepository on purpose. That one filters status through a bound gate
 * for the admin list and selects every column; here `status = 'approved'` and
 * `status = 'published'` are hard literals no caller can switch off, and the review columns
 * are a written-out public list that never names author_email, author_ip or user_id. Nothing
 * in this repository reads a review or a recipe in any other status, and there is no gate in it.
 */
final class PublicReviewRepository extends Repository {

    /** A review also changes the recipe's rating aggregate shown in recipe lists. */
    protected function entities(): array {
        return ['reviews', 'recipes'];
    }

    /** How many approved reviews a recipe has. Read-through object cache; key = recipe id. */
    public function count_approved(int $recipe_id): int {
        global $wpdb;

        return (int) $this->remember('frontend_count|' . $recipe_id, function () use ($wpdb, $recipe_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE recipe_id = %d AND status = \'approved\'',
                $this->table('reviews'),
                $recipe_id
            ));
        });
    }

    /**
     * One page of a recipe's approved reviews, public columns only.
     *
     * ORDER BY is not one identifier here - each option is a different column set with
     * its own directions, and a direction keyword cannot be bound at all. So the sort is
     * a match over five *literal* queries rather than an assembled fragment: the string
     * handed to prepare() is a constant in every arm, and $sort only chooses which arm
     * runs. Any unknown value falls to 'newest'. Each arm ends with `id` as a
     * deterministic tiebreaker - without it, reviews sharing a created_at (or a rating,
     * or a helpful_count) could repeat across pages and others be skipped entirely.
     *
     * Read-through object cache; key = recipe id + sort + pagination.
     *
     * @param string $sort oldest, highest, lowest, helpful - anything else is newest.
     * @return array<int, array<string, mixed>>
     */
    public function approved_page(int $recipe_id, string $sort, int $limit, int $offset): array {
        global $wpdb;

        $reviews_key = 'frontend_list|' . $recipe_id . '|' . $sort . '|' . $limit . '|' . $offset;
        $reviews_table = $this->table('reviews');

        return (array) $this->remember($reviews_key, function () use ($wpdb, $reviews_table, $recipe_id, $sort, $limit, $offset) {
            // The column list is written out rather than assembled, so the string handed to
            // prepare() is a literal in every arm. It must stay identical to
            // PlugixaRecipe\Modules\Recipes\Columns::review_public(), which is the canonical allowlist and
            // is what SqlLiteralTest compares these literals against. author_email,
            // author_ip and user_id are absent on purpose - see that class's docblock.
            $rows = match ($sort) {
                'oldest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at ASC, id ASC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'highest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating DESC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'lowest' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY rating ASC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                'helpful' => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY helpful_count DESC, created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
                default => $wpdb->get_results($wpdb->prepare(
                    'SELECT id, recipe_id, author_name, rating,
                        review_text, images, is_verified, helpful_count,
                        admin_reply, admin_reply_at, created_at
                      FROM %i
                      WHERE recipe_id = %d AND status = \'approved\'
                      ORDER BY created_at DESC, id DESC
                      LIMIT %d OFFSET %d',
                    $reviews_table, $recipe_id, $limit, $offset
                ), ARRAY_A),
            };

            return $rows ?: [];
        });
    }

    /**
     * The id of a logged-in user's helpful vote on a review, if there is one.
     * Per-user state: deliberately not cached.
     *
     * @return string|null
     */
    public function helpful_vote_by_user(int $review_id, int $user_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE review_id = %d AND user_id = %d',
            $this->table('review_helpful_votes'), $review_id, $user_id
        ));
    }

    /**
     * The id of a guest's helpful vote on a review, keyed on the IP it was cast from, if
     * there is one. Per-IP state: deliberately not cached.
     *
     * @return string|null
     */
    public function helpful_vote_by_ip(int $review_id, string $ip_address) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE review_id = %d AND ip_address = %s AND user_id IS NULL',
            $this->table('review_helpful_votes'), $review_id, $ip_address
        ));
    }

    /** Is there such a recipe, and is it published? Uncached. */
    public function published_recipe_exists(int $recipe_id): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND status = \'published\'',
            $this->table('recipes'),
            $recipe_id
        ));
    }

    /**
     * Store a visitor's review.
     *
     * The first of a submission's writes, and it does not invalidate the cache itself: the
     * images, the rating and the notification follow it, and submitted() below is the one
     * invalidation that closes the whole submission - exactly where it has always been.
     *
     * @param array<string, mixed> $data
     * @return int The new id, or 0 when the insert failed.
     */
    public function insert(array $data): int {
        global $wpdb;

        $wpdb->insert($this->table('reviews'), $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * Record the attachments uploaded with a review. Part of a submission; see insert().
     *
     * @param string $images JSON list of attachment ids.
     */
    public function set_images(int $review_id, string $images): void {
        global $wpdb;

        $wpdb->update($this->table('reviews'), ['images' => $images], ['id' => $review_id]);
    }

    /**
     * A recipe's title, for the notification mail. Uncached, and not pinned to a status:
     * the submission has already been refused unless the recipe is published.
     *
     * @return string|null
     */
    public function recipe_title(int $recipe_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT title FROM %i WHERE id = %d', $this->table('recipes'), $recipe_id));
    }

    /**
     * Close a submission: the review row, its images and the recipe's rating are written.
     *
     * Its own method because the invalidation comes last, after the notification has been
     * sent, and everything between insert() and here is orchestrated by the service.
     */
    public function submitted(): void {
        // Content write: a review also changes the recipe's rating aggregate shown in recipe lists.
        $this->flush();
    }

    /**
     * An APPROVED review's id and helpful count, as the database has them right now.
     *
     * @return object|null Null when there is no such review, or it is not approved.
     */
    public function find_approved(int $review_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT id, helpful_count FROM %i WHERE id = %d AND status = \'approved\'',
            $this->table('reviews'),
            $review_id
        ));
    }

    /**
     * Retract a helpful vote - the caller's own, found by helpful_vote_by_user() or
     * helpful_vote_by_ip().
     *
     * @param int|string $vote_id
     */
    public function remove_helpful_vote($vote_id): void {
        global $wpdb;

        $wpdb->delete($this->table('review_helpful_votes'), ['id' => $vote_id]);
    }

    /**
     * Record a helpful vote.
     *
     * @param array<string, mixed> $vote review_id, user_id (null for a guest), ip_address, created_at.
     */
    public function add_helpful_vote(array $vote): void {
        global $wpdb;

        $wpdb->insert($this->table('review_helpful_votes'), $vote);
    }

    /**
     * Count one more helpful vote on a review.
     *
     * Counter increment only: deliberately does NOT flush the cache — helpful votes fire
     * straight off a rendered review list, so flushing here would invalidate the whole
     * cache on every vote. Cached lists therefore carry eventually-consistent helpful counts.
     *
     * @param int $current The count read by find_approved().
     * @return int The count that was stored.
     */
    public function increment_helpful(int $review_id, int $current): int {
        global $wpdb;

        $new_count = $current + 1;
        $wpdb->update($this->table('reviews'), ['helpful_count' => $new_count], ['id' => $review_id]);

        return $new_count;
    }

    /**
     * Count one helpful vote fewer on a review, never below zero.
     *
     * Counter increment only: deliberately does NOT flush the cache — helpful votes fire
     * straight off a rendered review list, so flushing here would invalidate the whole
     * cache on every vote. Cached lists therefore carry eventually-consistent helpful counts.
     *
     * @param int $current The count read by find_approved().
     * @return int The count that was stored.
     */
    public function decrement_helpful(int $review_id, int $current): int {
        global $wpdb;

        $new_count = max(0, $current - 1);
        $wpdb->update($this->table('reviews'), ['helpful_count' => $new_count], ['id' => $review_id]);

        return $new_count;
    }
}
