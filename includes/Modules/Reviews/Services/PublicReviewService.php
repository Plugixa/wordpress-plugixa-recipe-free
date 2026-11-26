<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Reviews\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Reviews\Repositories\PublicReviewRepository;
use PlugixaRecipe\Modules\Reviews\Repositories\RecipeRatingRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What an anonymous visitor may do with reviews: read the approved ones under a published
 * recipe, submit one, and vote one helpful.
 *
 * The rules live here - who may post, how often, what a valid review is, whether it waits for
 * moderation - and so does the order they are checked in, which decides the error a visitor
 * sees. It holds no SQL: the pinned public queries are PublicReviewRepository's, the rating
 * recalculation is RecipeRatingRepository's.
 *
 * A submission is four calls, because two steps of it belong to the controller and sit
 * between them: validate_submission(), then the captcha (controller), then store(), then the
 * image upload (controller, which hands the attachments to attach_images()), then complete().
 * complete() is the step that invalidates the cache; a submission that is stored must reach it.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class PublicReviewService {

    private PublicReviewRepository $reviews;

    private RecipeRatingRepository $ratings;

    public function __construct(PublicReviewRepository $reviews, RecipeRatingRepository $ratings) {
        $this->reviews = $reviews;
        $this->ratings = $ratings;
    }

    /**
     * Approved reviews for a recipe, decorated for display.
     *
     * @param mixed                          $limit      The request's `limit`, or null for the configured default.
     * @param mixed                          $offset     The request's `offset`, or null for 0.
     * @param array{limit: int, offset: int} $pagination Echoed back in the response.
     * @param string                         $ip_address The caller's address, for a guest's helpful votes.
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function approved_for_recipe(int $recipe_id, $limit, $offset, array $pagination, string $ip_address): array {
        // Apply settings for default limit and sort order.
        $settings = get_option('plugixa_recipe_settings', []);
        $default_limit = (int) ($settings['reviews_per_page'] ?? 10);
        $limit = (int) ($limit ?? $default_limit);
        $offset = (int) ($offset ?? 0);

        $sort = $settings['reviews_default_sort'] ?? 'newest';

        $total = $this->reviews->count_approved($recipe_id);

        $reviews = $this->reviews->approved_page($recipe_id, $sort, $limit, $offset);

        // Check which reviews the current user has voted as helpful (per-user/IP state: not cached).
        $user_id = is_user_logged_in() ? get_current_user_id() : null;

        foreach ($reviews as &$review) {
            $review['rating'] = (int) $review['rating'];
            $review['is_verified'] = (bool) $review['is_verified'];
            $review['helpful_count'] = (int) $review['helpful_count'];

            // Check if current user/IP voted helpful on this review.
            if ($user_id) {
                $review['is_helpful'] = (bool) $this->reviews->helpful_vote_by_user((int) $review['id'], $user_id);
            } else {
                $review['is_helpful'] = (bool) $this->reviews->helpful_vote_by_ip((int) $review['id'], $ip_address);
            }

            if (!empty($review['images'])) {
                $ids = json_decode($review['images'], true);
                $review['images'] = [];
                if (is_array($ids)) {
                    foreach ($ids as $att_id) {
                        $url = wp_get_attachment_image_url((int) $att_id, 'medium');
                        $full = wp_get_attachment_image_url((int) $att_id, 'full');
                        if ($url) {
                            $review['images'][] = [
                                'id'   => (int) $att_id,
                                'url'  => $url,
                                'full' => $full ?: $url,
                            ];
                        }
                    }
                }
            }
        }
        unset($review);

        return [
            'items'  => $reviews,
            'total'  => $total,
            'limit'  => $pagination['limit'],
            'offset' => $pagination['offset'],
        ];
    }

    /** The transient that counts one address's review attempts this hour. */
    private static function rate_key(string $ip): string {
        return 'plugixa_recipe_review_' . md5($ip);
    }

    /**
     * Use up one of this address's attempts for the hour.
     *
     * Called for a review that was STORED and for a FAILED CAPTCHA, and for nothing else.
     * It used to be counted the moment the limit was read, so a visitor who mistyped their
     * email or wrote nine characters burned an attempt each time and could lock themselves
     * out without ever posting. A failed captcha still counts: that is the one refusal a
     * bot can produce at will, and each one is a request to Cloudflare.
     */
    public function count_attempt(string $ip): void {
        $key = self::rate_key($ip);
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }

    /**
     * Step 1 of a submission: may this visitor post this review at all?
     *
     * The checks run in this order and the first one to fail decides the message: recipe id,
     * published recipe, guest gate, hourly rate limit, name, email, text, rating. The rate
     * limit is only READ here; see count_attempt() for what uses one up.
     *
     * @param int                  $recipe_id
     * @param array<string, mixed> $fields    author_name, author_email, review_text already coerced
     *                                        to strings by the controller, unsanitised; rating raw.
     * @param string               $ip        The caller's address, for the rate limit.
     * @return array<string, mixed>|WP_Error The accepted submission: settings, recipe_id,
     *                                       author_name, author_email, review_text, rating.
     */
    public function validate_submission(int $recipe_id, array $fields, string $ip) {
        if (!$recipe_id) {
            return Failure::make(__('Recipe ID is required.', 'plugixa-recipe'));
        }

        if (!$this->reviews->published_recipe_exists($recipe_id)) {
            return Failure::make(__('Recipe not found.', 'plugixa-recipe'), 404);
        }

        // Guest review gate
        $settings = get_option('plugixa_recipe_settings', []);
        if (empty($settings['allow_guest_reviews']) && !is_user_logged_in()) {
            return Failure::make(__('You must be logged in to submit a review.', 'plugixa-recipe'), 403);
        }

        // Rate limiting (per IP, per hour) — mirrors submit_recipe.
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 10);
        if ((int) get_transient(self::rate_key($ip)) >= $max_per_hour) {
            return Failure::make(__('Too many reviews submitted. Please try again later.', 'plugixa-recipe'), 429);
        }

        $author_name = sanitize_text_field($fields['author_name']);
        if (empty($author_name)) {
            return Failure::make(__('Name is required.', 'plugixa-recipe'));
        }

        $author_email = sanitize_email($fields['author_email']);
        if (empty($author_email) || !is_email($author_email)) {
            return Failure::make(__('A valid email address is required.', 'plugixa-recipe'));
        }

        $review_text = wp_kses_post($fields['review_text']);
        if (empty(trim($review_text)) || mb_strlen(trim($review_text)) < 10) {
            return Failure::make(__('Review must be at least 10 characters.', 'plugixa-recipe'));
        }

        // Rating: required by default, optional if require_rating is false
        $require_rating = $settings['require_rating'] ?? true;
        $raw_rating = $fields['rating'];
        if ($require_rating && (empty($raw_rating) || (int) $raw_rating < 1)) {
            return Failure::make(__('Rating is required.', 'plugixa-recipe'));
        }
        // A rating that was sent is 1 to 5 or it is refused. It used to be clamped, so a
        // request for 99 stars was stored as 5 and counted into the recipe's average.
        if (!empty($raw_rating) && ((int) $raw_rating < 1 || (int) $raw_rating > 5)) {
            return Failure::make(__('Rating must be between 1 and 5.', 'plugixa-recipe'));
        }
        $rating = $raw_rating ? (int) $raw_rating : 0;

        return [
            'settings'     => $settings,
            'recipe_id'    => $recipe_id,
            'author_name'  => $author_name,
            'author_email' => $author_email,
            'review_text'  => $review_text,
            'rating'       => $rating,
        ];
    }

    /**
     * Step 2 of a submission: write the review row.
     *
     * Its status is status_for(): the moderation setting, or `approved` for a signed-in member
     * a listener trusts.
     *
     * @param array<string, mixed> $submission What validate_submission() returned.
     * @param string               $author_ip  The caller's address, as stored with the review.
     * @return array{id: int, status: string}
     */
    public function store(array $submission, string $author_ip): array {
        $settings = $submission['settings'];

        $default_status = self::status_for($settings, get_current_user_id(), (int) $submission['recipe_id']);

        $review_id = $this->reviews->insert([
            'recipe_id'    => $submission['recipe_id'],
            'user_id'      => is_user_logged_in() ? get_current_user_id() : null,
            'author_name'  => $submission['author_name'],
            'author_email' => $submission['author_email'],
            'author_ip'    => $author_ip,
            'rating'       => $submission['rating'],
            'review_text'  => $submission['review_text'],
            'status'       => $default_status,
            'is_verified'  => is_user_logged_in() ? 1 : 0,
            'created_at'   => current_time('mysql'),
        ]);

        return [
            'id'     => $review_id,
            'status' => $default_status,
        ];
    }

    /**
     * The status a new public review is stored with. The ONE place that decides it.
     *
     * Moderation off: `approved` for everybody. Moderation on: `pending`, unless the reviewer is
     * signed in and a listener on `plugixa_recipe_public_review_status` answers `approved`
     * (the premium Permissions app does, for a role with "Post reviews without approval").
     * A visitor who is not signed in always waits, and an answer that is neither status is
     * ignored, never stored.
     *
     * @since 1.5.0
     *
     * @param array<string, mixed> $settings  The plugin settings.
     * @param int                  $user_id   The reviewer, 0 when not signed in.
     * @param int                  $recipe_id The recipe reviewed.
     */
    public static function status_for(array $settings, int $user_id, int $recipe_id): string {
        if (empty($settings['review_moderation'])) {
            return 'approved';
        }

        if ($user_id <= 0) {
            return 'pending';
        }

        /**
         * Filters the status of a review a signed-in member posts while moderation is on.
         *
         * @since 1.5.0
         *
         * @param string $status    `pending`. Return `approved` to publish it straight away.
         * @param int    $user_id   The reviewer.
         * @param int    $recipe_id The recipe reviewed.
         */
        $status = apply_filters('plugixa_recipe_public_review_status', 'pending', $user_id, $recipe_id);

        return 'approved' === $status ? 'approved' : 'pending';
    }

    /**
     * Step 3 of a submission, only when images were uploaded with it: record them.
     *
     * @param int[] $image_ids Attachment ids the controller's upload block created.
     */
    public function attach_images(int $review_id, array $image_ids): void {
        $this->reviews->set_images($review_id, wp_json_encode($image_ids));
    }

    /**
     * Step 4 of a submission: the rating, the notification, and the cache.
     *
     * @param array<string, mixed>           $submission What validate_submission() returned.
     * @param array{id: int, status: string} $review     What store() returned.
     */
    public function complete(array $submission, array $review): void {
        $settings  = $submission['settings'];
        $recipe_id = $submission['recipe_id'];

        // Recalculate recipe rating if auto-approved
        if ($review['status'] === 'approved') {
            $this->ratings->recalculate($recipe_id);
        }

        // Tell the site owner. ReviewNotifier checks notification_enabled and the
        // recipients itself, and sends nothing when either is missing.
        if (!empty($settings['notification_enabled']) && !empty($settings['notification_recipients'])) {
            ReviewNotifier::send_new_review_notification([
                'recipe_id'    => $recipe_id,
                'recipe_title' => $this->reviews->recipe_title($recipe_id),
                'author_name'  => $submission['author_name'],
                'author_email' => $submission['author_email'],
                'rating'       => $submission['rating'],
                'review_text'  => $submission['review_text'],
            ]);
        }

        // Content write: a review also changes the recipe's rating aggregate shown in recipe lists.
        $this->reviews->submitted();
    }

    /**
     * Toggle the caller's helpful vote on an approved review.
     *
     * Keyed on the caller's own user id, or on the IP for a guest, so it can only ever add
     * or retract the caller's own vote.
     *
     * @param string $ip_address The caller's address: the guest's vote key and the rate-limit key.
     * @return array{is_helpful: bool, helpful_count: int}|WP_Error
     */
    public function toggle_helpful(int $review_id, string $ip_address) {
        $review = $this->reviews->find_approved($review_id);
        if (!$review) {
            return Failure::make(__('Review not found.', 'plugixa-recipe'), 404);
        }

        $settings = get_option('plugixa_recipe_settings', []);
        if (empty($settings['review_enable_helpful_votes'])) {
            return Failure::make(__('This feature is disabled.', 'plugixa-recipe'), 403);
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : null;

        // Rate limiting (per IP, per hour) — votes are lightweight so the cap is generous.
        $rate_key   = 'plugixa_recipe_helpful_' . md5($ip_address);
        $vote_count = (int) get_transient($rate_key);
        $max_per_hour = min((int) ($settings['api_rate_limit'] ?? 100), 60);
        if ($vote_count >= $max_per_hour) {
            return Failure::make(__('Too many requests. Please try again later.', 'plugixa-recipe'), 429);
        }
        set_transient($rate_key, $vote_count + 1, HOUR_IN_SECONDS);

        // Check for existing vote (by user_id if logged in, by IP if guest).
        if ($user_id) {
            $existing = $this->reviews->helpful_vote_by_user($review_id, $user_id);
        } else {
            $existing = $this->reviews->helpful_vote_by_ip($review_id, $ip_address);
        }

        if ($existing) {
            // Remove vote.
            $this->reviews->remove_helpful_vote($existing);
            $new_count = $this->reviews->decrement_helpful($review_id, (int) $review->helpful_count);

            return ['is_helpful' => false, 'helpful_count' => $new_count];
        }

        // Add vote.
        $this->reviews->add_helpful_vote([
            'review_id'  => $review_id,
            'user_id'    => $user_id,
            'ip_address' => $ip_address,
            'created_at' => current_time('mysql'),
        ]);
        $new_count = $this->reviews->increment_helpful($review_id, (int) $review->helpful_count);

        return ['is_helpful' => true, 'helpful_count' => $new_count];
    }
}
