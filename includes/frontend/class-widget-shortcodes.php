<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Frontend;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table reads for the eight widget shortcodes. Every list read goes through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; only the per-user like/favourite lookups are deliberately uncached. Every query is a constant literal with table and column identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so every `...$args` call site reports "found 1 replacement, expected N". The queries here pass their placeholder values in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff: NotPrepared and InterpolatedNotPrepared both stay enabled.

// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams -- False positive: this file contains no get_posts()/WP_Query call. "exclude" here is a SHORTCODE attribute naming plugin-owned custom-table category/cuisine IDs to omit; it is absint()-mapped and bound as %d placeholders, and never reaches WP_Query.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;

/**
 * Widget Shortcodes — compact, sidebar-friendly recipe elements.
 *
 * [plugixa_categories]       — Category list with links
 * [plugixa_cuisines]         — Cuisine list with links
 * [plugixa_latest_recipes]   — Latest recipes compact list
 * [plugixa_popular_recipes]  — Popular recipes compact list
 * [plugixa_related_recipes]  — Related recipe cards
 * [plugixa_recipe_search]    — Recipe search box
 * [plugixa_recipe_of_day]    — Recipe of the day
 * [plugixa_recipe_count]     — Total recipe count badge
 */
class WidgetShortcodes {

    /** @var bool Prevent double-enqueue of CSS */
    private bool $assets_enqueued = false;

    /**
     * Register all widget shortcodes.
     */
    public function register(): void {
        add_shortcode( 'plugixa_categories', [ $this, 'render_categories' ] );
        add_shortcode( 'plugixa_cuisines', [ $this, 'render_cuisines' ] );
        add_shortcode( 'plugixa_latest_recipes', [ $this, 'render_latest_recipes' ] );
        add_shortcode( 'plugixa_popular_recipes', [ $this, 'render_popular_recipes' ] );
        add_shortcode( 'plugixa_related_recipes', [ $this, 'render_related_recipes' ] );
        add_shortcode( 'plugixa_recipe_search', [ $this, 'render_recipe_search' ] );
        add_shortcode( 'plugixa_recipe_of_day', [ $this, 'render_recipe_of_day' ] );
        add_shortcode( 'plugixa_recipe_count', [ $this, 'render_recipe_count' ] );
    }

    /* ═══════════════════════════════════════════════
       Taxonomy Widgets: Categories & Cuisines
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_categories] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_categories( $atts ): string {
        $atts = shortcode_atts( [
            'limit'      => 10,
            'orderby'    => 'display_order', // display_order, name, count
            'order'      => 'ASC',
            'show_count' => 'true',
            'style'      => 'list',          // list, pills, grid
            'exclude'    => '',
            'title'      => '',
        ], $atts, 'plugixa_categories' );

        return $this->render_taxonomy( 'category', $atts );
    }

    /**
     * Render [plugixa_cuisines] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_cuisines( $atts ): string {
        $atts = shortcode_atts( [
            'limit'      => 10,
            'orderby'    => 'display_order',
            'order'      => 'ASC',
            'show_count' => 'true',
            'style'      => 'list',
            'exclude'    => '',
            'title'      => '',
        ], $atts, 'plugixa_cuisines' );

        return $this->render_taxonomy( 'cuisine', $atts );
    }

    /**
     * Shared renderer for category/cuisine taxonomy widgets.
     */
    private function render_taxonomy( string $type, array $atts ): string {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $table        = $type === 'category' ? 'categories' : 'cuisines';
        $pivot_table  = $type === 'category' ? 'recipe_categories' : 'recipe_cuisines';
        $pivot_fk     = $type === 'category' ? 'category_id' : 'cuisine_id';
        $archive_base = plugixa_recipe_url_slug( $type === 'cuisine' ? 'cuisine' : 'category' );

        // Build query
        $limit  = absint( $atts['limit'] ) ?: 10;
        $order  = strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        // Allow-list mapped to [table alias, column]. The alias is kept separate from the
        // column because the column is bound with the %i identifier placeholder below, and
        // %i quotes its whole argument as ONE identifier - passing "t.display_order" would
        // ask MySQL for a column literally named "t.display_order". 'count' sorts by the
        // recipe_count SELECT alias, which has no table qualifier.
        $orderby_map = [
            'name'          => [ 't.', 'name' ],
            'count'         => [ '', 'recipe_count' ],
            'display_order' => [ 't.', 'display_order' ],
        ];
        [ $order_alias, $orderby ] = $orderby_map[ $atts['orderby'] ] ?? [ 't.', 'display_order' ];

        // Every optional filter is a gate placeholder: bind 0 and its clause is
        // constant-true, which keeps WHERE literal instead of PHP-assembled. The exclude
        // list binds as ONE %s via FIND_IN_SET rather than a variable-length NOT IN (...),
        // so the query stays a constant literal for any number of excluded ids.
        $exclude_ids = [];
        if ( ! empty( $atts['exclude'] ) ) {
            $exclude_ids = array_filter( array_map( 'absint', explode( ',', $atts['exclude'] ) ) );
        }
        $exclude_list = implode( ',', $exclude_ids );

        // Language filter
        $multilingual = \PlugixaRecipe\Multilingual::instance();
        $lang         = $multilingual->is_active() ? $multilingual->get_current_language() : '';

        // Bound values in placeholder order. %i identifiers and %s/%d values share one
        // positional list, so the table and column identifiers sit inline.
        $args = [
            $prefix . $table,
            $prefix . $pivot_table,
            $pivot_fk,
            $prefix . 'recipes',
            $exclude_ids ? 1 : 0, $exclude_list,
            $lang !== '' ? 1 : 0, $lang,
            $orderby,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. It captures exclude/limit/orderby/order/language.
        //
        // recipe_count is PUBLISHED recipes only: COUNT(DISTINCT r.id), where r joins only
        // when the recipe is published. It was COUNT(DISTINCT p.recipe_id), which counted
        // every pivot row whatever its recipe's status - drafts, pending submissions,
        // archived recipes, even pivot rows whose recipe no longer exists - on a public
        // widget. Because the count reads recipes.status, the key depends on 'recipes' too:
        // publishing a draft writes no term or pivot row. `v2` marks the change of query
        // shape, so no key written by the old query can be read back.
        $entity = $type === 'category' ? 'categories' : 'cuisines';
        $items  = Cache::remember( [ $entity, 'recipes' ], 'widget_taxonomy|v2|' . md5( wp_json_encode( $args ) . '|' . $order_alias . '|' . $order ), function () use ( $wpdb, $order_alias, $order, $args ) {
            // ORDER BY varies in two ways that cannot be bound: the direction keyword, and
            // whether the column is qualified with the t. alias ('count' sorts by the
            // recipe_count SELECT alias, which has none). That is four literal queries -
            // the column itself still binds with %i from the allow-list above.
            $qualified = 't.' === $order_alias;

            if ( $qualified && 'ASC' === $order ) {
                return $wpdb->get_results( $wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY t.%i ASC, t.id ASC
                    LIMIT %d',
                    ...$args
                ) ) ?: [];
            }

            if ( $qualified ) {
                return $wpdb->get_results( $wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY t.%i DESC, t.id DESC
                    LIMIT %d',
                    ...$args
                ) ) ?: [];
            }

            if ( 'ASC' === $order ) {
                return $wpdb->get_results( $wpdb->prepare(
                    'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                           COUNT(DISTINCT r.id) as recipe_count
                    FROM %i t
                    LEFT JOIN %i p ON t.id = p.%i
                    LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                    WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                      AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                    GROUP BY t.id
                    ORDER BY %i ASC, t.id ASC
                    LIMIT %d',
                    ...$args
                ) ) ?: [];
            }

            return $wpdb->get_results( $wpdb->prepare(
                'SELECT t.id, t.name, t.slug, t.description, t.image_id,
                       COUNT(DISTINCT r.id) as recipe_count
                FROM %i t
                LEFT JOIN %i p ON t.id = p.%i
                LEFT JOIN %i r ON p.recipe_id = r.id AND r.status = \'published\'
                WHERE ( %d = 0 OR NOT FIND_IN_SET(t.id, %s) )
                  AND ( %d = 0 OR (t.language_code = %s OR t.language_code IS NULL OR t.language_code = \'\') )
                GROUP BY t.id
                ORDER BY %i DESC, t.id DESC
                LIMIT %d',
                ...$args
            ) ) ?: [];
        } );

        if ( empty( $items ) ) {
            return '';
        }

        // Build permalink for each item
        foreach ( $items as $item ) {
            $item->permalink = home_url( "/{$archive_base}/" . rawurlencode( $item->slug ) . '/' );
            if ( ! empty( $item->image_id ) ) {
                $item->image_url = wp_get_attachment_image_url( (int) $item->image_id, 'thumbnail' ) ?: '';
            } else {
                $item->image_url = '';
            }
        }

        $this->enqueue_assets();

        return $this->render_template( 'categories', [
            'items'      => $items,
            'type'       => $type,
            'style'      => sanitize_key( $atts['style'] ),
            'show_count' => filter_var( $atts['show_count'], FILTER_VALIDATE_BOOLEAN ),
            'title'      => sanitize_text_field( $atts['title'] ),
            'unique_id'  => 'pxr-widget-' . uniqid(),
        ] );
    }

    /* ═══════════════════════════════════════════════
       Compact Recipe Lists: Latest & Popular
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_latest_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_latest_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'limit'          => 5,
            'show_thumbnail' => 'true',
            'show_category'  => 'true',
            'show_date'      => 'true',
            'thumbnail_size' => 'thumbnail',
            'category'       => '',
            'title'          => __( 'Latest Recipes', 'plugixa-recipe' ),
        ], $atts, 'plugixa_latest_recipes' );

        $recipes = $this->query_compact_recipes( [
            'limit'          => absint( $atts['limit'] ) ?: 5,
            'orderby'        => 'published_at',
            'order'          => 'DESC',
            'category'       => sanitize_text_field( $atts['category'] ),
            'thumbnail_size' => sanitize_key( $atts['thumbnail_size'] ),
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'latest-recipes', [
            'recipes'        => $recipes,
            'show_thumbnail' => filter_var( $atts['show_thumbnail'], FILTER_VALIDATE_BOOLEAN ),
            'show_category'  => filter_var( $atts['show_category'], FILTER_VALIDATE_BOOLEAN ),
            'show_date'      => filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN ),
            'title'          => sanitize_text_field( $atts['title'] ),
            'unique_id'      => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Render [plugixa_popular_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_popular_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'limit'          => 5,
            'orderby'        => 'views', // views, rating
            'show_thumbnail' => 'true',
            'show_category'  => 'true',
            'show_rating'    => 'false',
            'show_views'     => 'false',
            'title'          => __( 'Popular Recipes', 'plugixa-recipe' ),
        ], $atts, 'plugixa_popular_recipes' );

        $orderby = $atts['orderby'] === 'rating' ? 'rating_average' : 'view_count';

        $recipes = $this->query_compact_recipes( [
            'limit'          => absint( $atts['limit'] ) ?: 5,
            'orderby'        => $orderby,
            'order'          => 'DESC',
            'thumbnail_size' => 'thumbnail',
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'popular-recipes', [
            'recipes'        => $recipes,
            'show_thumbnail' => filter_var( $atts['show_thumbnail'], FILTER_VALIDATE_BOOLEAN ),
            'show_category'  => filter_var( $atts['show_category'], FILTER_VALIDATE_BOOLEAN ),
            'show_rating'    => filter_var( $atts['show_rating'], FILTER_VALIDATE_BOOLEAN ),
            'show_views'     => filter_var( $atts['show_views'], FILTER_VALIDATE_BOOLEAN ),
            'title'          => sanitize_text_field( $atts['title'] ),
            'unique_id'      => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Query recipes for compact list widgets (latest/popular).
     * Lightweight enrichment: image + first category + permalink only.
     */
    private function query_compact_recipes( array $config ): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        // Gated EXISTS instead of an optional INNER JOIN (a join is structure and cannot be
        // gated away by a placeholder), and the slug list binds as ONE %s via FIND_IN_SET
        // instead of a variable-length IN (...). Both keep the query a constant literal.
        // Slugs are sanitize_title() output, so they never contain FIND_IN_SET's comma.
        $cat_slugs = ! empty( $config['category'] )
            ? array_filter( array_map( 'trim', explode( ',', $config['category'] ) ) )
            : [];
        $cat_list = implode( ',', $cat_slugs );

        // Language filter
        $multilingual = \PlugixaRecipe\Multilingual::instance();
        $lang         = $multilingual->is_active() ? $multilingual->get_current_language() : '';

        $allowed_orderby = [ 'published_at', 'created_at', 'view_count', 'rating_average' ];
        $orderby = in_array( $config['orderby'], $allowed_orderby, true ) ? $config['orderby'] : 'published_at';
        $order   = strtoupper( $config['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = absint( $config['limit'] ) ?: 5;

        $args = [
            $prefix . 'recipes',
            $cat_slugs ? 1 : 0, $prefix . 'recipe_categories', $prefix . 'categories', $cat_list,
            $lang !== '' ? 1 : 0, $lang,
            $orderby,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. Captures the category filter, language,
        // orderby/order and limit.
        $results = Cache::remember( 'recipes', 'widget_compact|' . md5( wp_json_encode( $args ) . '|' . $order ), function () use ( $wpdb, $order, $args ) {
            // A direction keyword cannot be bound, so ASC/DESC is two literal queries. r.id
            // is a deterministic tiebreaker: seeded recipes routinely share a published_at,
            // and a tied sort may return any permutation.
            if ( 'ASC' === $order ) {
                return $wpdb->get_results( $wpdb->prepare(
                    'SELECT r.id, r.title, r.image_id, r.published_at, r.created_at,
                           r.rating_average, r.rating_count, r.view_count, r.difficulty,
                           r.prep_time, r.cook_time
                    FROM %i r
                    WHERE r.status = \'published\'
                      AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                               WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                      AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                    ORDER BY r.%i ASC, r.id ASC
                    LIMIT %d',
                    ...$args
                ) ) ?: [];
            }

            return $wpdb->get_results( $wpdb->prepare(
                'SELECT r.id, r.title, r.image_id, r.published_at, r.created_at,
                       r.rating_average, r.rating_count, r.view_count, r.difficulty,
                       r.prep_time, r.cook_time
                FROM %i r
                WHERE r.status = \'published\'
                  AND ( %d = 0 OR EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                           WHERE rc.recipe_id = r.id AND FIND_IN_SET(c.slug, %s)) )
                  AND ( %d = 0 OR (r.language_code = %s OR r.language_code IS NULL OR r.language_code = \'\') )
                ORDER BY r.%i DESC, r.id DESC
                LIMIT %d',
                ...$args
            ) ) ?: [];
        } );

        if ( empty( $results ) ) {
            return [];
        }

        $thumbnail_size = $config['thumbnail_size'] ?? 'thumbnail';

        // Batch-load first category for all recipes — read-through object cache; key = recipe ids.
        $recipe_ids = wp_list_pluck( $results, 'id' );
        $id_list    = implode( ',', $recipe_ids );
        $cat_rows   = Cache::remember( 'recipes', 'widget_compact_categories|' . wp_json_encode( $recipe_ids ), function () use ( $wpdb, $prefix, $id_list ) {
            return $wpdb->get_results( $wpdb->prepare(
                'SELECT rc.recipe_id, cat.name, cat.slug
                 FROM %i rc
                 INNER JOIN %i cat ON rc.category_id = cat.id
                 WHERE FIND_IN_SET(rc.recipe_id, %s)
                 ORDER BY rc.display_order ASC, cat.id ASC',
                $prefix . 'recipe_categories',
                $prefix . 'categories',
                $id_list
            ) ) ?: [];
        } );

        // Index: first category per recipe
        $cat_map = [];
        foreach ( $cat_rows as $row ) {
            $rid = (int) $row->recipe_id;
            if ( ! isset( $cat_map[ $rid ] ) ) {
                $cat_map[ $rid ] = $row;
            }
        }

        // Enrich each recipe
        $settings = $this->get_settings();
        foreach ( $results as $recipe ) {
            $rid = (int) $recipe->id;

            // Image
            if ( ! empty( $recipe->image_id ) ) {
                $recipe->image_url = wp_get_attachment_image_url( (int) $recipe->image_id, $thumbnail_size ) ?: '';
            } else {
                $recipe->image_url = '';
            }

            // First category
            $recipe->category_name = isset( $cat_map[ $rid ] ) ? $cat_map[ $rid ]->name : '';
            $recipe->category_slug = isset( $cat_map[ $rid ] ) ? $cat_map[ $rid ]->slug : '';

            // Permalink
            $slug = sanitize_title( $recipe->title ?? '' );
            $recipe->permalink = plugixa_recipe_permalink( (int) $rid, $slug );

            // Formatted date
            $date_format    = $settings['date_format'] ?? 'M j, Y';
            $date_str       = $recipe->published_at ?? $recipe->created_at;
            $recipe->date   = $date_str ? date_i18n( $date_format, strtotime( $date_str ) ) : '';

            // Aliases
            $recipe->rating = floatval( $recipe->rating_average ?? 0 );
        }

        return $results;
    }

    /* ═══════════════════════════════════════════════
       Related Recipes
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_related_recipes] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_related_recipes( $atts ): string {
        $atts = shortcode_atts( [
            'recipe_id' => 0,
            'limit'     => 4,
            'columns'   => 4,
            'relation'  => 'both', // category, cuisine, both
            'title'     => __( 'You May Also Like', 'plugixa-recipe' ),
        ], $atts, 'plugixa_related_recipes' );

        // Auto-detect recipe ID from single recipe page
        $recipe_id = absint( $atts['recipe_id'] );
        if ( ! $recipe_id ) {
            $recipe_id = absint( get_query_var( 'plugixa_recipe_id', 0 ) );
        }

        if ( ! $recipe_id ) {
            return '';
        }

        $recipes = $this->query_related_recipes( $recipe_id, [
            'limit'    => absint( $atts['limit'] ) ?: 4,
            'relation' => sanitize_key( $atts['relation'] ),
        ] );

        if ( empty( $recipes ) ) {
            return '';
        }

        $this->enqueue_assets();

        $columns = absint( $atts['columns'] ) ?: 4;

        return $this->render_template( 'related-recipes', [
            'recipes'   => $recipes,
            'columns'   => $columns,
            'title'     => sanitize_text_field( $atts['title'] ),
            'unique_id' => 'pxr-widget-' . uniqid(),
            // Provide a minimal view_config for recipe-card.php
            'view_config' => [
                'show_image'      => true,
                'show_rating'     => true,
                'show_meta'       => true,
                'show_difficulty' => true,
                'show_diet'       => false,
                'show_cuisine'    => false,
                'show_excerpt'    => false,
            ],
        ] );
    }

    /**
     * Query related recipes based on shared categories/cuisines.
     */
    private function query_related_recipes( int $recipe_id, array $config ): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $limit    = absint( $config['limit'] ) ?: 4;
        $relation = $config['relation'] ?? 'both';

        // Get current recipe's category and cuisine IDs
        $cat_ids     = [];
        $cuisine_ids = [];

        // Read-through object cache; child rows cached under the owning recipe.
        if ( in_array( $relation, [ 'category', 'both' ], true ) ) {
            $cat_ids = Cache::remember( 'recipes', 'related_cat_ids|' . $recipe_id, function () use ( $wpdb, $prefix, $recipe_id ) {
                return $wpdb->get_col( $wpdb->prepare(
                    'SELECT category_id FROM %i WHERE recipe_id = %d',
                    $prefix . 'recipe_categories',
                    $recipe_id
                ) ) ?: [];
            } );
        }

        if ( in_array( $relation, [ 'cuisine', 'both' ], true ) ) {
            $cuisine_ids = Cache::remember( 'recipes', 'related_cuisine_ids|' . $recipe_id, function () use ( $wpdb, $prefix, $recipe_id ) {
                return $wpdb->get_col( $wpdb->prepare(
                    'SELECT cuisine_id FROM %i WHERE recipe_id = %d',
                    $prefix . 'recipe_cuisines',
                    $recipe_id
                ) ) ?: [];
            } );
        }

        if ( empty( $cat_ids ) && empty( $cuisine_ids ) ) {
            return [];
        }

        // The old form built a UNION of one or two subqueries and joined it. Both branches
        // are now gated EXISTS clauses in a single literal query, and each id list binds as
        // ONE %s via FIND_IN_SET instead of a variable-length IN (...).
        //
        // NOTE the gate polarity. Everywhere else in this codebase an optional filter is
        // `( %d = 0 OR <clause> )` - constant-TRUE when disabled, because those filters AND
        // together. These two branches OR together (a UNION is a union), so a disabled
        // branch must be constant-FALSE instead: `( %d = 1 AND <clause> )`. Using the
        // constant-true form here would make `TRUE OR ...` match every published recipe.
        // The early return above guarantees at least one gate is 1.
        $cat_list = implode( ',', array_map( 'absint', $cat_ids ) );
        $cui_list = implode( ',', array_map( 'absint', $cuisine_ids ) );

        $args = [
            $prefix . 'recipes',
            $recipe_id,
            $cat_ids ? 1 : 0, $prefix . 'recipe_categories', $cat_list,
            $cuisine_ids ? 1 : 0, $prefix . 'recipe_cuisines', $cui_list,
            $limit,
        ];

        // Read-through object cache. The key is a stable digest of the query shape and the
        // bound values - never the SQL text. Captures the related category / cuisine ids,
        // the excluded recipe and the limit. The ORDER BY is entirely literal here, so
        // there is no identifier to bind.
        $results = Cache::remember( 'recipes', 'widget_related|' . md5( wp_json_encode( $args ) ), function () use ( $wpdb, $args ) {
            // Column list = Columns::recipe_card('r'), written out so the string handed to
            // prepare() is a constant literal; SqlLiteralTest pins the two together.
            return $wpdb->get_results( $wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                    r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                    r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                    r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                    r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                    r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                    r.is_halal, r.is_kosher, r.published_at, r.created_at
                FROM %i r
                WHERE r.status = \'published\' AND r.id != %d
                  AND (
                        ( %d = 1 AND EXISTS (SELECT 1 FROM %i rc WHERE rc.recipe_id = r.id AND FIND_IN_SET(rc.category_id, %s)) )
                     OR ( %d = 1 AND EXISTS (SELECT 1 FROM %i rcu WHERE rcu.recipe_id = r.id AND FIND_IN_SET(rcu.cuisine_id, %s)) )
                  )
                ORDER BY r.rating_average DESC, r.created_at DESC, r.id DESC
                LIMIT %d',
                ...$args
            ), ARRAY_A ) ?: [];
        } );

        if ( empty( $results ) ) {
            return [];
        }

        // Full enrichment for recipe-card.php
        return array_map( [ $this, 'enrich_recipe_for_card' ], $results );
    }

    /**
     * Enrich a recipe with all data recipe-card.php needs.
     */
    private function enrich_recipe_for_card( array $recipe ): object {
        global $wpdb;
        $prefix    = $wpdb->prefix . 'plugixa_recipe_';
        $recipe_id = (int) $recipe['id'];

        // Image
        if ( ! empty( $recipe['image_id'] ) ) {
            $recipe['image_url']    = wp_get_attachment_image_url( (int) $recipe['image_id'], 'medium_large' ) ?: '';
            $recipe['image_srcset'] = wp_get_attachment_image_srcset( (int) $recipe['image_id'], 'medium_large' ) ?: '';
        } else {
            $recipe['image_url']    = '';
            $recipe['image_srcset'] = '';
        }

        // Categories — child rows cached under the owning recipe.
        $recipe['categories'] = Cache::remember( 'recipes', 'card_categories|' . $recipe_id, function () use ( $wpdb, $prefix, $recipe_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                'SELECT c.id, c.name, c.slug FROM %i c
                 INNER JOIN %i rc ON c.id = rc.category_id
                 WHERE rc.recipe_id = %d ORDER BY rc.display_order ASC, c.id ASC',
                $prefix . 'categories',
                $prefix . 'recipe_categories',
                $recipe_id
            ), ARRAY_A ) ?: [];
        } );

        // Cuisines — child rows cached under the owning recipe.
        $recipe['cuisines'] = Cache::remember( 'recipes', 'card_cuisines|' . $recipe_id, function () use ( $wpdb, $prefix, $recipe_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                'SELECT cu.id, cu.name, cu.slug FROM %i cu
                 INNER JOIN %i rcu ON cu.id = rcu.cuisine_id
                 WHERE rcu.recipe_id = %d',
                $prefix . 'cuisines',
                $prefix . 'recipe_cuisines',
                $recipe_id
            ), ARRAY_A ) ?: [];
        } );

        // Diet badges
        $recipe['diet_badges'] = [];
        if ( ! empty( $recipe['is_vegetarian'] ) ) $recipe['diet_badges'][] = __( 'Vegetarian', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_vegan'] ) )      $recipe['diet_badges'][] = __( 'Vegan', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_gluten_free'] ) ) $recipe['diet_badges'][] = __( 'Gluten-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_dairy_free'] ) )  $recipe['diet_badges'][] = __( 'Dairy-Free', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_keto'] ) )        $recipe['diet_badges'][] = __( 'Keto', 'plugixa-recipe' );
        if ( ! empty( $recipe['is_low_carb'] ) )    $recipe['diet_badges'][] = __( 'Low-Carb', 'plugixa-recipe' );

        // Excerpt
        if ( ! empty( $recipe['description'] ) ) {
            $plain = wp_strip_all_tags( $recipe['description'] );
            $recipe['excerpt'] = mb_strlen( $plain ) > 150
                ? mb_substr( $plain, 0, 150 ) . "\u{2026}"
                : $plain;
        } else {
            $recipe['excerpt'] = '';
        }

        // Like/favorite status (per-user state: not cached).
        $recipe['like_count'] = isset( $recipe['like_count'] ) ? (int) $recipe['like_count'] : 0;
        if ( is_user_logged_in() ) {
            $uid = get_current_user_id();
            $recipe['is_liked']     = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $prefix . 'likes', $uid, $recipe_id
            ) );
            $recipe['is_favorited'] = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d AND recipe_id = %d', $prefix . 'favorites', $uid, $recipe_id
            ) );
        } else {
            $recipe['is_liked']     = false;
            $recipe['is_favorited'] = false;
        }

        // Template aliases
        $recipe['rating']    = $recipe['rating_average'] ?? 0;
        $recipe['cuisine']   = ! empty( $recipe['cuisines'][0]['name'] ) ? $recipe['cuisines'][0]['name'] : '';
        $slug                = sanitize_title( $recipe['title'] ?? '' );
        $recipe['permalink'] = plugixa_recipe_permalink( (int) $recipe_id, $slug );
        $recipe['print_url'] = home_url( "/recipe-print/{$recipe_id}/?auto=1" );

        return (object) $recipe;
    }

    /* ═══════════════════════════════════════════════
       Recipe Search Widget
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_recipe_search] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_search( $atts ): string {
        $atts = shortcode_atts( [
            'placeholder' => __( 'Search recipes...', 'plugixa-recipe' ),
            'style'       => 'simple', // simple, expanded
            'action'      => '',
            'title'       => '',
        ], $atts, 'plugixa_recipe_search' );

        $this->enqueue_assets();

        // For expanded style, load categories and cuisines
        $categories = [];
        $cuisines   = [];
        if ( $atts['style'] === 'expanded' ) {
            global $wpdb;
            $prefix     = $wpdb->prefix . 'plugixa_recipe_';
            // Read-through object cache; these queries take no inputs.
            $categories = Cache::remember( 'categories', 'widget_search_options', function () use ( $wpdb, $prefix ) {
                return $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, slug FROM %i ORDER BY display_order ASC, id ASC', $prefix . 'categories' ) ) ?: [];
            } );
            $cuisines   = Cache::remember( 'cuisines', 'widget_search_options', function () use ( $wpdb, $prefix ) {
                return $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, slug FROM %i ORDER BY display_order ASC, id ASC', $prefix . 'cuisines' ) ) ?: [];
            } );
        }

        return $this->render_template( 'recipe-search', [
            'placeholder' => sanitize_text_field( $atts['placeholder'] ),
            'style'       => sanitize_key( $atts['style'] ),
            'action'      => esc_url( $atts['action'] ) ?: '',
            'title'       => sanitize_text_field( $atts['title'] ),
            'categories'  => $categories,
            'cuisines'    => $cuisines,
            'unique_id'   => 'pxr-widget-' . uniqid(),
        ] );
    }

    /* ═══════════════════════════════════════════════
       Recipe of the Day
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_recipe_of_day] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_of_day( $atts ): string {
        $atts = shortcode_atts( [
            'style'       => 'card',  // card, compact
            'show_rating' => 'true',
            'show_time'   => 'true',
            'title'       => __( 'Recipe of the Day', 'plugixa-recipe' ),
        ], $atts, 'plugixa_recipe_of_day' );

        $recipe = $this->get_recipe_of_day();

        if ( ! $recipe ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_template( 'recipe-of-day', [
            'recipe'      => $recipe,
            'style'       => sanitize_key( $atts['style'] ),
            'show_rating' => filter_var( $atts['show_rating'], FILTER_VALIDATE_BOOLEAN ),
            'show_time'   => filter_var( $atts['show_time'], FILTER_VALIDATE_BOOLEAN ),
            'title'       => sanitize_text_field( $atts['title'] ),
            'unique_id'   => 'pxr-widget-' . uniqid(),
        ] );
    }

    /**
     * Get the "recipe of the day" — deterministic per day via transient + seeded random.
     */
    private function get_recipe_of_day(): ?object {
        $transient_key = 'plugixa_recipe_of_day_pick';
        $recipe_id     = get_transient( $transient_key );

        if ( $recipe_id ) {
            // Verify the recipe still exists and is published — read-through object cache.
            global $wpdb;
            $prefix = $wpdb->prefix . 'plugixa_recipe_';
            $recipes_table = $prefix . 'recipes';
            $recipe = Cache::remember( 'recipes', 'widget_of_day|' . (int) $recipe_id, function () use ( $wpdb, $recipes_table, $recipe_id ) {
                return $wpdb->get_row( $wpdb->prepare(
                    'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                        r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                        r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                        r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                        r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                        r.is_halal, r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.id = %d AND r.status = \'published\'',
                    $recipes_table,
                    $recipe_id
                ), ARRAY_A );
            } );

            if ( $recipe ) {
                return $this->enrich_recipe_for_card( $recipe );
            }
        }

        // Pick a new recipe — prefer featured, fallback to any published
        global $wpdb;
        $prefix    = $wpdb->prefix . 'plugixa_recipe_';
        $daily_seed = crc32( gmdate( 'Y-m-d' ) );

        // Read-through object cache; key = the daily seed (the only input).
        $recipes_table = $prefix . 'recipes';
        $recipe = Cache::remember( 'recipes', 'widget_of_day_featured|' . $daily_seed, function () use ( $wpdb, $recipes_table, $daily_seed ) {
            return $wpdb->get_row( $wpdb->prepare(
                'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                    r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                    r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                    r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                    r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                    r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                    r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                    r.is_halal, r.is_kosher, r.published_at, r.created_at
                  FROM %i r
                  WHERE r.status = \'published\' AND r.is_featured = 1
                  ORDER BY RAND(%d)
                  LIMIT 1',
                $recipes_table,
                $daily_seed
            ), ARRAY_A );
        } );

        if ( ! $recipe ) {
            $recipe = Cache::remember( 'recipes', 'widget_of_day_any|' . $daily_seed, function () use ( $wpdb, $recipes_table, $daily_seed ) {
                return $wpdb->get_row( $wpdb->prepare(
                    'SELECT r.id, r.language_code, r.translation_group, r.title, r.slug,
                        r.description, r.excerpt, r.image_id, r.video_url, r.servings,
                        r.servings_unit, r.prep_time, r.cook_time, r.rest_time, r.total_time,
                        r.difficulty, r.cost, r.season, r.is_featured, r.is_editor_pick,
                        r.display_order, r.author_name, r.view_count, r.rating_average, r.rating_count,
                        r.like_count, r.is_vegetarian, r.is_vegan, r.is_gluten_free, r.is_dairy_free,
                        r.is_keto, r.is_low_carb, r.is_nut_free, r.is_paleo, r.is_sugar_free,
                        r.is_halal, r.is_kosher, r.published_at, r.created_at
                      FROM %i r
                      WHERE r.status = \'published\'
                      ORDER BY RAND(%d)
                      LIMIT 1',
                    $recipes_table,
                    $daily_seed
                ), ARRAY_A );
            } );
        }

        if ( ! $recipe ) {
            return null;
        }

        set_transient( $transient_key, $recipe['id'], DAY_IN_SECONDS );

        return $this->enrich_recipe_for_card( $recipe );
    }

    /* ═══════════════════════════════════════════════
       Recipe Count Badge
       ═══════════════════════════════════════════════ */

    /**
     * Render [plugixa_recipe_count] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_recipe_count( $atts ): string {
        $atts = shortcode_atts( [
            'prefix'   => '',
            'suffix'   => ' ' . __( 'Recipes', 'plugixa-recipe' ),
            'style'    => 'badge', // badge, text
            'category' => '',
        ], $atts, 'plugixa_recipe_count' );

        $category = sanitize_text_field( $atts['category'] );
        $cache_key = 'plugixa_recipe_count_cache' . ( $category ? '_' . $category : '' );

        $count = get_transient( $cache_key );
        if ( false === $count ) {
            global $wpdb;
            $prefix = $wpdb->prefix . 'plugixa_recipe_';

            if ( $category ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i r
                     WHERE r.status = \'published\'
                       AND EXISTS (SELECT 1 FROM %i rc INNER JOIN %i c ON rc.category_id = c.id
                                    WHERE rc.recipe_id = r.id AND c.slug = %s)',
                    $prefix . 'recipes',
                    $prefix . 'recipe_categories',
                    $prefix . 'categories',
                    $category
                ) );
            } else {
                $count = (int) $wpdb->get_var(
                    $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = \'published\'', $prefix . 'recipes' )
                );
            }

            set_transient( $cache_key, $count, HOUR_IN_SECONDS );
        }

        $this->enqueue_assets();

        return $this->render_template( 'recipe-count', [
            'count'  => (int) $count,
            'prefix' => sanitize_text_field( $atts['prefix'] ),
            'suffix' => sanitize_text_field( $atts['suffix'] ),
            'style'  => sanitize_key( $atts['style'] ),
        ] );
    }

    /* ═══════════════════════════════════════════════
       Shared Helpers
       ═══════════════════════════════════════════════ */

    /**
     * Render a widget template and return the HTML.
     */
    private function render_template( string $template_name, array $data ): string {
        $template = PLUGIXA_RECIPE_PATH . "templates/widgets/{$template_name}.php";

        // Allow theme override
        $theme_template = get_stylesheet_directory() . "/plugixa-recipe/widgets/{$template_name}.php";
        if ( file_exists( $theme_template ) ) {
            $template = $theme_template;
        }

        if ( ! file_exists( $template ) ) {
            return '';
        }

        ob_start();
        extract( $data, EXTR_SKIP );
        include $template;
        return ob_get_clean();
    }

    /**
     * Enqueue frontend CSS (shared with main shortcode CSS).
     */
    private function enqueue_assets(): void {
        if ( $this->assets_enqueued ) {
            return;
        }

        $css_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/build/css/frontend.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css',
                [],
                PLUGIXA_RECIPE_VERSION
            );
        }

        // Frontend JS for interactive features (likes, favorites)
        $js_path = PLUGIXA_RECIPE_PATH . 'assets/frontend/js/recipes.js';
        if ( file_exists( $js_path ) ) {
            wp_enqueue_script(
                'plugixa-recipe-frontend',
                PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipes.js',
                [],
                PLUGIXA_RECIPE_VERSION,
                true
            );

            wp_localize_script( 'plugixa-recipe-frontend', 'plugixaRecipeConfig', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'restUrl'    => esc_url_raw( rest_url( 'plugixa-recipe/v1/' ) ),
                'nonce'      => wp_create_nonce( 'plugixa_recipe_frontend' ),
                'restNonce'  => wp_create_nonce( 'wp_rest' ),
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl'   => wp_login_url( get_permalink() ),
                'i18n'       => [
                    'tooltip' => [
                        'like'       => __( 'Like this recipe', 'plugixa-recipe' ),
                        'likeActive' => __( 'Unlike this recipe', 'plugixa-recipe' ),
                        'save'       => __( 'Save to favorites', 'plugixa-recipe' ),
                        'saveActive' => __( 'Remove from saved', 'plugixa-recipe' ),
                        'loginLike'  => __( 'Log in to like this recipe', 'plugixa-recipe' ),
                        'loginSave'  => __( 'Log in to save this recipe', 'plugixa-recipe' ),
                        'loginLink'  => __( 'Log in', 'plugixa-recipe' ),
                    ],
                ],
            ] );
        }

        $this->assets_enqueued = true;
    }

    /**
     * Get plugin settings.
     */
    private function get_settings(): array {
        $defaults = [
            'show_nutrition'     => true,
            'show_reviews'       => true,
            'enable_print'       => true,
            'enable_favorites'   => true,
            'enable_submissions' => false,
            'excerpt_length'     => 150,
            'date_format'        => 'M j, Y',
        ];

        return array_merge( $defaults, (array) get_option( 'plugixa_recipe_settings', [] ) );
    }
}
