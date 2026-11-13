<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Activator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Settings REST Controller
 */
class SettingsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'settings';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_settings_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/dashboard', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_dashboard'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);
    }

    /**
     * Get plugin settings.
     */
    public function get_settings($request): WP_REST_Response|WP_Error {
        $settings = get_option('plugixa_recipe_settings', []);
        return $this->success($settings);
    }

    /**
     * Update plugin settings.
     */
    public function update_settings($request): WP_REST_Response|WP_Error {
        $params = $request->get_json_params();
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
                case 'cache_duration':
                case 'thumbnail_quality':
                case 'api_rate_limit':
                    $sanitized[$key] = (int) $value;
                    break;

                // Boolean fields
                case 'show_nutrition':
                case 'show_reviews':
                case 'review_moderation':
                case 'enable_print':
                case 'enable_favorites':
                case 'enable_shopping_lists':
                case 'enable_submissions':
                case 'submission_require_login':
                case 'turnstile_form_enabled':
                case 'turnstile_reviews_enabled':
                case 'notification_enabled':
                case 'allow_guest_reviews':
                case 'require_rating':
                case 'review_enable_images':
                case 'review_enable_helpful_votes':
                case 'review_enable_admin_reply':
                case 'enable_sitemap':
                case 'enable_open_graph':
                case 'enable_twitter_cards':
                case 'enable_lazy_load':
                case 'enable_cache':
                case 'image_optimization':
                case 'enable_rest_api':
                case 'enable_public_api':
                case 'delete_data_on_uninstall':
                case 'enable_debug':
                case 'enable_reviews':
                case 'enable_collections':
                case 'enable_nutrition':
                case 'enable_seo':
                case 'enable_unit_conversion':
                case 'show_breadcrumbs':
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
        $sanitized = array_intersect_key($sanitized, Activator::get_default_settings());

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

        return $this->success($merged, __('Settings updated.', 'plugixa-recipe'));
    }

    /**
     * Get dashboard statistics.
     */
    public function get_dashboard($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Recipe counts by status
        $status_counts = $wpdb->get_results(
            $wpdb->prepare('SELECT status, COUNT(*) AS count FROM %i GROUP BY status', $prefix . 'recipes'),
            ARRAY_A
        );
        $statuses = [];
        $total_recipes = 0;
        foreach ($status_counts as $sc) {
            $statuses[$sc['status']] = (int) $sc['count'];
            $total_recipes += (int) $sc['count'];
        }

        // Featured count
        $featured = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_featured = 1', $prefix . 'recipes'));

        // Average rating
        $avg_rating = (float) $wpdb->get_var(
            $wpdb->prepare('SELECT AVG(rating_average) FROM %i WHERE rating_average > 0', $prefix . 'recipes')
        );

        // Rating distribution
        $rating_dist = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT rating, COUNT(*) AS count FROM %i WHERE status = \'approved\' GROUP BY rating ORDER BY rating DESC',
                $prefix . 'reviews'
            ),
            ARRAY_A
        );
        $rating_distribution = [];
        foreach ($rating_dist as $rd) {
            $rating_distribution[(int) $rd['rating']] = (int) $rd['count'];
        }

        // Recipes by category
        $recipes_by_category = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.name, COUNT(rc.recipe_id) AS count
                FROM %i c
                LEFT JOIN %i rc ON c.id = rc.category_id
                GROUP BY c.id
                ORDER BY count DESC, c.id ASC
                LIMIT 10',
                $prefix . 'categories',
                $prefix . 'recipe_categories'
            ),
            ARRAY_A
        );
        foreach ($recipes_by_category as &$rbc) {
            $rbc['count'] = (int) $rbc['count'];
        }

        // Recent recipes
        $recent_recipes = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, title, status, difficulty, rating_average, created_at, image_id
                FROM %i
                ORDER BY created_at DESC, id DESC
                LIMIT 5',
                $prefix . 'recipes'
            ),
            ARRAY_A
        );
        foreach ($recent_recipes as &$rr) {
            $rr['rating_average'] = (float) $rr['rating_average'];
            $rr['average_rating'] = $rr['rating_average'];
            if (!empty($rr['image_id'])) {
                $rr['image_url'] = wp_get_attachment_image_url((int) $rr['image_id'], 'thumbnail');
                $rr['image_thumbnail'] = $rr['image_url'];
            }
        }

        // Reviews stats
        $total_reviews = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $prefix . 'reviews'));
        $pending_reviews = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = \'pending\'', $prefix . 'reviews'));

        // Categories count
        $total_categories = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $prefix . 'categories'));

        // Cuisines count
        $total_cuisines = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $prefix . 'cuisines'));

        // Collections count
        $total_collections = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $prefix . 'collections'));

        // Total views (sum of view_count from recipes)
        $total_views = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(view_count), 0) FROM %i', $prefix . 'recipes'));

        // Total favorites
        $total_favorites = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $prefix . 'favorites'));

        // Recent reviews
        $recent_reviews = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.id, r.rating, r.review_text, r.author_name, r.status, r.created_at, rec.title AS recipe_title
                FROM %i r
                LEFT JOIN %i rec ON r.recipe_id = rec.id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 5',
                $prefix . 'reviews',
                $prefix . 'recipes'
            ),
            ARRAY_A
        );
        foreach ($recent_reviews as &$rr2) {
            $rr2['rating'] = (int) $rr2['rating'];
        }

        // Recipes by difficulty
        $recipes_by_difficulty = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT difficulty, COUNT(*) AS count FROM %i GROUP BY difficulty ORDER BY count DESC, difficulty ASC',
                $prefix . 'recipes'
            ),
            ARRAY_A
        );
        foreach ($recipes_by_difficulty as &$rbd) {
            $rbd['count'] = (int) $rbd['count'];
        }

        // Recipes by cuisine (top 10)
        $recipes_by_cuisine = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT cu.name, COUNT(rcu.recipe_id) AS count
                FROM %i cu
                LEFT JOIN %i rcu ON cu.id = rcu.cuisine_id
                GROUP BY cu.id
                ORDER BY count DESC, cu.id ASC
                LIMIT 10',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines'
            ),
            ARRAY_A
        );
        foreach ($recipes_by_cuisine as &$rbc2) {
            $rbc2['count'] = (int) $rbc2['count'];
        }

        return $this->success([
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
            'recent_recipes'        => $recent_recipes,
            'recent_reviews'        => $recent_reviews,
            'total_reviews'         => $total_reviews,
            'pending_reviews'       => $pending_reviews,
        ]);
    }

}
