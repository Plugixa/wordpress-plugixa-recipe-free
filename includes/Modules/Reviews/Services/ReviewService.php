<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Reviews\Repositories\ReviewRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What may be done to a review on the admin side: read, edit, reply, moderate, delete.
 *
 * Deliberately no create: a review is only ever written by a visitor, through
 * PublicReviewService. The rules live here rather than in the controller - which fields an
 * edit may change, which moderation statuses exist, which bulk actions map to which status -
 * so they hold for any caller. It holds no SQL: every query belongs to ReviewRepository, which
 * also recalculates the recipe's rating and invalidates the cache as part of each write.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null. The
 * controller maps both onto the response.
 */
final class ReviewService {

    /** Columns the list endpoint can sort by - the ORDER BY allow-list. */
    private const SORTABLE = ['created_at', 'rating', 'author_name', 'helpful_count'];

    /** The moderation statuses a single review can be moved to. */
    private const STATUSES = ['pending', 'approved', 'rejected', 'spam'];

    /** Bulk action => the status it sets. */
    private const BULK_STATUS = [
        'approve' => 'approved',
        'reject'  => 'rejected',
        'spam'    => 'spam',
        'pending' => 'pending',
    ];

    private ReviewRepository $reviews;

    public function __construct(ReviewRepository $reviews) {
        $this->reviews = $reviews;
    }

    /**
     * Reviews list with filters, each row with its recipe title.
     *
     * @param array<string, mixed>                  $filters    As read by the controller.
     * @param array{limit: int, offset: int}        $pagination
     * @param array{orderby: string, order: string} $sort
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $filters, array $pagination, array $sort): array {
        $search     = !empty($filters['search']) ? (string) $filters['search'] : '';
        $status     = !empty($filters['status']) ? (string) $filters['status'] : '';
        $recipe_id  = !empty($filters['recipe_id']) ? (int) $filters['recipe_id'] : 0;
        $rating     = !empty($filters['rating']) ? (int) $filters['rating'] : 0;
        $min_rating = !empty($filters['min_rating']) ? (int) $filters['min_rating'] : 0;

        $orderby = in_array($sort['orderby'], self::SORTABLE) ? $sort['orderby'] : 'created_at';
        $order   = $sort['order'] === 'ASC' ? 'ASC' : 'DESC';

        $total = $this->reviews->count($search, $status, $recipe_id, $rating, $min_rating);
        $items = $this->reviews->page($search, $status, $recipe_id, $rating, $min_rating, $orderby, $order, $pagination['limit'], $pagination['offset']);

        foreach ($items as &$item) {
            $item = $this->decorate($item);
        }
        unset($item);

        return [
            'items'  => $items,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /**
     * One review with its recipe title.
     *
     * @return array<string, mixed>|null Null when there is no such review.
     */
    public function get(int $id): ?array {
        $item = $this->reviews->find($id);

        if (null === $item) {
            return null;
        }

        return $this->decorate($item);
    }

    /**
     * Change the fields present in $params; fields that are absent are left alone.
     * modified_at is always stamped, so there is always something to write.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return true|WP_Error
     */
    public function update(int $id, array $params) {
        $existing = $this->reviews->find_uncached($id);
        if (null === $existing) {
            return Failure::make(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $data = [];

        if (isset($params['author_name'])) {
            $data['author_name'] = sanitize_text_field($params['author_name']);
        }
        if (isset($params['author_email'])) {
            $data['author_email'] = sanitize_email($params['author_email']);
        }
        if (isset($params['rating'])) {
            // Refused rather than clamped: an out-of-range value is a mistake to report,
            // not one to store as the nearest star.
            if ((int) $params['rating'] < 1 || (int) $params['rating'] > 5) {
                return Failure::make(__('Rating must be between 1 and 5.', 'plugixa-recipe'));
            }
            $data['rating'] = (int) $params['rating'];
        }
        if (isset($params['review_text'])) {
            $data['review_text'] = wp_kses_post($params['review_text']);
        }
        $status = $this->requested_status($params);
        if (null !== $status) {
            // Refused rather than stored: the column is a varchar under a case-insensitive
            // collation, so 'Approved' would be live while every check compares 'approved'.
            if (!in_array($status, self::STATUSES, true)) {
                return Failure::make(__('Invalid status.', 'plugixa-recipe'));
            }
            $data['status'] = $status;
        }
        if (isset($params['is_verified'])) {
            $data['is_verified'] = (int) $params['is_verified'];
        }
        if (isset($params['images'])) {
            $data['images'] = wp_json_encode($params['images']);
        }
        if (isset($params['admin_reply'])) {
            $reply = wp_kses_post( trim( $params['admin_reply'] ) );
            $data['admin_reply'] = $reply ?: null;
            $data['admin_reply_at'] = $reply ? current_time('mysql') : null;
        }

        $data['modified_at'] = current_time('mysql');

        $this->reviews->update($id, $data, (int) $existing['recipe_id']);

        return true;
    }

    /**
     * Delete a review.
     *
     * @return true|WP_Error
     */
    public function delete(int $id) {
        $review = $this->reviews->find_uncached($id);
        if (null === $review) {
            return Failure::make(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $this->reviews->delete($id, (int) $review['recipe_id']);

        return true;
    }

    /**
     * The status a payload names: null when it names none (absent, blank, or not text).
     *
     * ONE reading, shared by update(), set_status() and changes_status(), so the permission
     * check and the writer cannot disagree about what a payload asks for.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     */
    private function requested_status(array $params): ?string {
        if (!isset($params['status'])) {
            return null;
        }

        $status = sanitize_text_field($params['status']);

        return '' === $status ? null : $status;
    }

    /**
     * Whether an edit would also MODERATE the review - move it to another status.
     *
     * The controller asks this before update() and requires moderation rights when the
     * answer is yes.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     */
    public function changes_status(int $id, array $params): bool {
        $to = $this->requested_status($params);
        if (null === $to) {
            return false;
        }

        $review = $this->reviews->find_uncached($id);
        if (null === $review) {
            // Nothing to moderate; the handler answers 404.
            return false;
        }

        // Compared the way the database compares the stored value.
        return 0 !== strcasecmp((string) $review['status'], $to);
    }

    /**
     * Move a review to another moderation status.
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array{id: int, status: string}|WP_Error
     */
    public function set_status(int $id, array $params) {
        $status = (string) $this->requested_status($params);

        if (!in_array($status, self::STATUSES, true)) {
            return Failure::make(__('Invalid status.', 'plugixa-recipe'));
        }

        $review = $this->reviews->find_uncached($id);
        if (null === $review) {
            return Failure::make(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $this->reviews->set_status($id, [
            'status'      => $status,
            'modified_at' => current_time('mysql'),
        ], (int) $review['recipe_id']);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Bulk delete reviews.
     *
     * @param mixed $ids Raw payload value.
     * @return int|false|WP_Error Rows removed, as the database reported it.
     */
    public function bulk_delete($ids) {
        $ids = array_map('intval', is_array($ids) ? $ids : []);

        if (empty($ids)) {
            return Failure::make(__('No IDs provided.', 'plugixa-recipe'));
        }

        return $this->reviews->delete_many(implode(',', $ids));
    }

    /**
     * Bulk action on reviews (approve, reject, spam, pending).
     *
     * @param mixed $ids    Raw payload value.
     * @param mixed $action Raw payload value.
     * @return string|WP_Error The action that was applied.
     */
    public function bulk_action($ids, $action) {
        $ids    = array_map('intval', is_array($ids) ? $ids : []);
        $action = sanitize_text_field($action);

        if (empty($ids) || empty($action)) {
            return Failure::make(__('IDs and action are required.', 'plugixa-recipe'));
        }

        $now = current_time('mysql');

        if (!isset(self::BULK_STATUS[$action])) {
            return Failure::make(__('Invalid action.', 'plugixa-recipe'));
        }

        $this->reviews->set_status_many(implode(',', $ids), self::BULK_STATUS[$action], $now);

        return $action;
    }

    /**
     * The casts the admin app expects on a review row.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function decorate(array $item): array {
        $item['rating'] = (int) $item['rating'];
        $item['helpful_count'] = (int) $item['helpful_count'];
        $item['is_verified'] = (bool) $item['is_verified'];
        if (!empty($item['images'])) {
            $item['images'] = json_decode($item['images'], true);
        }

        return $item;
    }
}
