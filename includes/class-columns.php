<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit column lists for the public (unauthenticated) read paths.
 *
 * Every query that renders to a visitor - the frontend REST controller, the shortcode
 * and widget renderers, the archive, the single-recipe page, the print view and the
 * JSON-LD builder - selects from these lists instead of `SELECT *`. A wildcard select
 * means any column added to a table in a later release is published to anonymous
 * callers by default; naming the columns inverts that, so a new column is private
 * until someone deliberately adds it here.
 *
 * What is deliberately absent:
 * - recipes.submission_ip, reviews.author_email, reviews.author_ip - personal data
 *   captured from the public submission and review forms. None of it is rendered
 *   anywhere, and it must never reach a REST response. These three are the reason
 *   this class exists: they sit on the same rows the frontend reads, so every
 *   `SELECT *` on a public path was publishing a submitter's IP address.
 * - recipes.created_by / modified_by, collections.user_id - WordPress user IDs.
 *   Publishing them lets an anonymous visitor enumerate the site's editors.
 * - recipes.modified_at, reviews.modified_at - editorial audit trail.
 * - recipes.status, collections.status/visibility - the gating columns themselves. The
 *   WHERE clause already pins them, so echoing them back adds nothing but tells a
 *   caller what other states exist.
 * - recipes.seo_score / seo_title / seo_description / seo_keywords / focus_keyword -
 *   overlay-owned working data, consumed only by the admin screens.
 * - recipes.enable_schema - an internal rendering flag.
 *
 * Where a specific server-side query genuinely needs one of those (the JSON-LD builder
 * resolves an author display name from created_by; the SEO meta tags read status to
 * decide on a noindex), the call site appends that single column to the list and says
 * why. Those results are never returned to the client.
 */
final class Columns {

    /**
     * Public recipe columns, qualified with a table alias.
     *
     * @param string $alias Table alias used in the query (e.g. 'r').
     * @return string Comma-separated column list for a SELECT.
     */
    public static function recipe_public( string $alias = 'r' ): string {
        return self::qualify( $alias, [
            'id',
            'language_code',
            'translation_group',
            'title',
            'slug',
            'description',
            'excerpt',
            'notes',
            'tips',
            'image_id',
            'gallery',
            'tags',
            'equipment',
            'video_url',
            'servings',
            'servings_unit',
            'yield_amount',
            'yield_unit',
            'prep_time',
            'cook_time',
            'rest_time',
            'total_time',
            'difficulty',
            'cost',
            'season',
            'is_featured',
            'is_editor_pick',
            'display_order',
            'source',
            'source_url',
            'author_name',
            'view_count',
            'rating_average',
            'rating_count',
            'like_count',
            'is_vegetarian',
            'is_vegan',
            'is_gluten_free',
            'is_dairy_free',
            'is_keto',
            'is_low_carb',
            'is_nut_free',
            'is_paleo',
            'is_sugar_free',
            'is_halal',
            'is_kosher',
            'published_at',
            'created_at',
        ] );
    }

    /**
     * Public recipe columns for the summary card used by lists, grids and widgets.
     *
     * A card shows an image, times, difficulty, rating and the diet badges - it never
     * shows the body copy, so the long text columns stay out of the result set.
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function recipe_card( string $alias = 'r' ): string {
        return self::qualify( $alias, [
            'id',
            'language_code',
            'translation_group',
            'title',
            'slug',
            'description',
            'excerpt',
            'image_id',
            'video_url',
            'servings',
            'servings_unit',
            'prep_time',
            'cook_time',
            'rest_time',
            'total_time',
            'difficulty',
            'cost',
            'season',
            'is_featured',
            'is_editor_pick',
            'display_order',
            'author_name',
            'view_count',
            'rating_average',
            'rating_count',
            'like_count',
            'is_vegetarian',
            'is_vegan',
            'is_gluten_free',
            'is_dairy_free',
            'is_keto',
            'is_low_carb',
            'is_nut_free',
            'is_paleo',
            'is_sugar_free',
            'is_halal',
            'is_kosher',
            'published_at',
            'created_at',
        ] );
    }

    /**
     * Public review columns. Excludes author_email and author_ip.
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function review_public( string $alias = '' ): string {
        return self::qualify( $alias, [
            'id',
            'recipe_id',
            // NOT user_id. The column stays on the table - submit_review() writes the
            // reviewer's WP user id, and the helpful-vote dedupe reads it server-side -
            // but publishing it contradicted this class's own rule two paragraphs up:
            // a WordPress user id on an anonymous endpoint lets a visitor enumerate which
            // accounts exist. Nothing rendered it; GET /frontend/recipes/{id}/reviews was
            // returning it to the world for no reader.
            'author_name',
            'rating',
            'review_text',
            'images',
            'is_verified',
            'helpful_count',
            'admin_reply',
            'admin_reply_at',
            'created_at',
        ] );
    }

    /**
     * Public collection columns. Excludes the status/visibility gating columns.
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function collection_public( string $alias = 'col' ): string {
        return self::qualify( $alias, [
            'id',
            'name',
            'slug',
            'description',
            'image_id',
            'type',
            'is_featured',
            'display_order',
            'created_at',
        ] );
    }

    /**
     * Ingredient columns (the whole table - it holds no private data).
     *
     * Listed explicitly anyway so a column added later is not published by default.
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function ingredient_public( string $alias = '' ): string {
        return self::qualify( $alias, [
            'id',
            'recipe_id',
            'group_name',
            'group_order',
            'sort_order',
            'quantity',
            'unit',
            'name',
            'notes',
            'link_url',
        ] );
    }

    /**
     * Instruction columns (the whole table - it holds no private data).
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function instruction_public( string $alias = '' ): string {
        return self::qualify( $alias, [
            'id',
            'recipe_id',
            'group_name',
            'group_order',
            'sort_order',
            'title',
            'instruction_text',
            'image_id',
            'video_url',
            'duration',
        ] );
    }

    /**
     * Nutrition columns (the whole table - it holds no private data).
     *
     * @param string $alias Table alias used in the query.
     * @return string Comma-separated column list for a SELECT.
     */
    public static function nutrition_public( string $alias = '' ): string {
        return self::qualify( $alias, [
            'id',
            'recipe_id',
            'serving_size',
            'calories',
            'total_fat',
            'saturated_fat',
            'trans_fat',
            'cholesterol',
            'sodium',
            'total_carbohydrate',
            'dietary_fiber',
            'total_sugars',
            'protein',
            'vitamin_a',
            'vitamin_c',
            'calcium',
            'iron',
            'potassium',
            'source',
        ] );
    }

    /**
     * Prefix each column with the table alias and join them for a SELECT clause.
     *
     * @param string   $alias   Table alias, or '' for an unaliased single-table query.
     * @param string[] $columns Column names.
     * @return string
     */
    private static function qualify( string $alias, array $columns ): string {
        if ( '' === $alias ) {
            return implode( ', ', $columns );
        }

        return implode(
            ', ',
            array_map(
                static fn( string $column ): string => $alias . '.' . $column,
                $columns
            )
        );
    }
}
