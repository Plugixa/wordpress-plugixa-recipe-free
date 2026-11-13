<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Settings\Services;

use PlugixaRecipe\Core\Settings;
use PlugixaRecipe\Modules\Settings\Repositories\DashboardStatsRepository;

defined('ABSPATH') || exit;

/**
 * The plugin's settings, and the figures on the admin dashboard.
 *
 * Settings live in one WordPress option, read and written here with get_option() /
 * update_option() - there is no table and so no repository for them. What may be stored is
 * decided here: each key is sanitised by type, the payload is allow-listed against the
 * build's own defaults, and only then merged over what is already stored.
 *
 * The dashboard figures are read by DashboardStatsRepository; this class turns its rows into
 * the response (casts, image URLs, the twelve-month series). It holds no SQL.
 */
final class SettingsService {

    private DashboardStatsRepository $stats;

    public function __construct(DashboardStatsRepository $stats) {
        $this->stats = $stats;
    }

    /**
     * The stored settings.
     *
     * @return mixed Whatever the option holds; an empty array when it was never saved.
     */
    public function get() {
        return get_option('plugixa_recipe_settings', []);
    }

    /**
     * Sanitise, allow-list and persist a settings payload.
     *
     * @param array<string, mixed> $params The raw request payload.
     * @return array<string, mixed> The settings as stored after the merge.
     */
    public function update(array $params): array {
        $current = get_option('plugixa_recipe_settings', []);

        // Sanitize settings
        $sanitized = [];
        foreach ($params as $key => $value) {
            switch ($key) {
                // Integer fields
                case 'items_per_page':
                case 'excerpt_length':
                case 'default_servings':
                case 'max_upload_size':
                case 'reviews_per_page':
                case 'review_max_images':
                case 'thumbnail_quality':
                case 'api_rate_limit':
                    $sanitized[$key] = (int) $value;
                    break;

                // Boolean fields
                case 'show_nutrition':
                case 'show_reviews':
                case 'review_moderation':
                case 'enable_print':
                case 'submission_require_login':
                case 'turnstile_form_enabled':
                case 'turnstile_reviews_enabled':
                case 'notification_enabled':
                case 'allow_guest_reviews':
                case 'require_rating':
                case 'review_enable_images':
                case 'review_enable_helpful_votes':
                case 'review_enable_admin_reply':
                case 'enable_open_graph':
                case 'enable_twitter_cards':
                case 'noindex_draft_recipes':
                case 'enable_lazy_load':
                case 'image_optimization':
                case 'delete_data_on_uninstall':
                    $sanitized[$key] = (bool) $value;
                    break;

                // Email field
                case 'notification_recipients':
                    $sanitized[$key] = sanitize_text_field($value);
                    break;

                // URL fields
                case 'source_url':
                    $sanitized[$key] = esc_url_raw($value);
                    break;

                // Default: sanitize as text
                default:
                    if (is_string($value)) {
                        $sanitized[$key] = sanitize_text_field($value);
                    } elseif (is_array($value)) {
                        $sanitized[$key] = $value; // Allow arrays (e.g., complex configs)
                    } else {
                        $sanitized[$key] = $value;
                    }
                    break;
            }
        }

        // Only keys the build actually knows about may be persisted. In the free build
        // that is the free defaults; in the premium build the pro overlay has already
        // added its own keys to them through plugixa_recipe_settings_defaults. Without
        // this, a stale admin bundle could still write premium keys into a free site.
        $sanitized = array_intersect_key($sanitized, Settings::get_default_settings());

        /**
         * Filter the sanitized settings before they are persisted.
         *
         * The premium overlay re-applies strict casts for its own keys (float, int,
         * bool) here, because the free switch above no longer names them.
         *
         * @param array<string,mixed> $sanitized Sanitized values, already allow-listed.
         * @param array<string,mixed> $params    The raw request payload.
         * @param array<string,mixed> $current   The currently stored settings.
         */
        $sanitized = apply_filters('plugixa_recipe_sanitize_settings', $sanitized, $params, $current);

        $merged = array_merge($current, $sanitized);
        update_option('plugixa_recipe_settings', $merged);

        return $merged;
    }

    /**
     * Dashboard statistics.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array {
        // Recipe counts by status
        $status_counts = $this->stats->recipe_counts_by_status();
        $statuses = [];
        $total_recipes = 0;
        foreach ($status_counts as $sc) {
            $statuses[$sc['status']] = (int) $sc['count'];
            $total_recipes += (int) $sc['count'];
        }

        // Featured count
        $featured = (int) $this->stats->featured_recipe_count();

        // Average rating
        $avg_rating = (float) $this->stats->average_recipe_rating();

        // Rating distribution
        $rating_dist = $this->stats->approved_rating_distribution();
        $rating_distribution = [];
        foreach ($rating_dist as $rd) {
            $rating_distribution[(int) $rd['rating']] = (int) $rd['count'];
        }

        // Recipes by category
        $recipes_by_category = $this->stats->recipes_by_category();
        foreach ($recipes_by_category as &$rbc) {
            $rbc['count'] = (int) $rbc['count'];
        }

        // Recent recipes
        $recent_recipes = $this->stats->recent_recipes();
        foreach ($recent_recipes as &$rr) {
            $rr['rating_average'] = (float) $rr['rating_average'];
            $rr['average_rating'] = $rr['rating_average'];
            if (!empty($rr['image_id'])) {
                $rr['image_url'] = wp_get_attachment_image_url((int) $rr['image_id'], 'thumbnail');
                $rr['image_thumbnail'] = $rr['image_url'];
            }
        }

        // Reviews stats
        $total_reviews = (int) $this->stats->count_all('reviews');
        $pending_reviews = (int) $this->stats->pending_review_count();

        // Categories count
        $total_categories = (int) $this->stats->count_all('categories');

        // Cuisines count
        $total_cuisines = (int) $this->stats->count_all('cuisines');

        // Collections count
        $total_collections = (int) $this->stats->count_all('collections');

        // Total views (sum of view_count from recipes)
        $total_views = (int) $this->stats->total_views();

        // Total favorites
        $total_favorites = (int) $this->stats->count_all('favorites');

        // Recent reviews
        $recent_reviews = $this->stats->recent_reviews();
        foreach ($recent_reviews as &$rr2) {
            $rr2['rating'] = (int) $rr2['rating'];
        }

        // Recipes by difficulty
        $recipes_by_difficulty = $this->stats->recipes_by_difficulty();
        foreach ($recipes_by_difficulty as &$rbd) {
            $rbd['count'] = (int) $rbd['count'];
        }

        // Recipes by cuisine (top 10)
        $recipes_by_cuisine = $this->stats->recipes_by_cuisine();
        foreach ($recipes_by_cuisine as &$rbc2) {
            $rbc2['count'] = (int) $rbc2['count'];
        }

        // Twelve-month series for the dashboard trend and the tile sparklines. The window
        // opens on the first day of the month eleven months back, in site time - the same
        // clock `created_at` is written in.
        $months = [];
        $cursor = (new \DateTimeImmutable('first day of this month', wp_timezone()))->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $months[$cursor->modify("+{$i} months")->format('Y-m')] = 0;
        }
        $since = array_key_first($months) . '-01 00:00:00';

        $recipes_by_month = $months;
        foreach ($this->stats->created_by_month('recipes', $since) as $row) {
            if (isset($recipes_by_month[$row['period']])) {
                $recipes_by_month[$row['period']] = (int) $row['count'];
            }
        }

        $reviews_by_month = $months;
        foreach ($this->stats->created_by_month('reviews', $since) as $row) {
            if (isset($reviews_by_month[$row['period']])) {
                $reviews_by_month[$row['period']] = (int) $row['count'];
            }
        }

        // Most viewed published recipes.
        $top_viewed = $this->stats->top_viewed_published();
        foreach ($top_viewed as &$tv) {
            $tv['id']         = (int) $tv['id'];
            $tv['view_count'] = (int) $tv['view_count'];
        }
        unset($tv);

        $series = static function (array $counts): array {
            $out = [];
            foreach ($counts as $period => $count) {
                $out[] = ['period' => $period, 'count' => $count];
            }
            return $out;
        };

        return [
            'total_recipes'         => $total_recipes,
            'published_recipes'     => $statuses['published'] ?? 0,
            'draft_recipes'         => $statuses['draft'] ?? 0,
            'pending_recipes'       => $statuses['pending'] ?? 0,
            'featured'              => $featured,
            'average_rating'        => round($avg_rating, 2),
            'total_categories'      => $total_categories,
            'total_cuisines'        => $total_cuisines,
            'total_collections'     => $total_collections,
            'total_views'           => $total_views,
            'total_favorites'       => $total_favorites,
            'rating_distribution'   => $rating_distribution,
            'recipes_by_category'   => $recipes_by_category,
            'recipes_by_cuisine'    => $recipes_by_cuisine,
            'recipes_by_difficulty' => $recipes_by_difficulty,
            'recipes_by_month'      => $series($recipes_by_month),
            'reviews_by_month'      => $series($reviews_by_month),
            'top_viewed'            => $top_viewed,
            'recent_recipes'        => $recent_recipes,
            'recent_reviews'        => $recent_reviews,
            'total_reviews'         => $total_reviews,
            'pending_reviews'       => $pending_reviews,
        ];
    }
}
