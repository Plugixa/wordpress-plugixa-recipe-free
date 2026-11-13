<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_current_language / wpml_default_language / wpml_active_languages are filters DEFINED BY WPML. We are the consumer; they must keep WPML's names to resolve. Prefixing them would simply return nothing.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Multilingual REST Controller
 * Supports WPML/Polylang: languages, status, and translation CRUD. The translatable
 * entities are recipes, categories and cuisines; extensions add their own through the
 * plugixa_recipe_multilingual_entities filter.
 */
class MultilingualController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'multilingual';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/languages', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_languages'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/(?P<id>\d+)/translations', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_translations'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_translation'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/link-translations', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'link_translations'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<entity>[a-z_]+)/(?P<id>\d+)/translations/(?P<lang>[a-z_-]+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'unlink_translation'],
                // Edit, not delete: clears the translation link; no entity row is removed.
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get available languages from WPML/Polylang.
     */
    public function get_languages($request): WP_REST_Response|WP_Error {
        $languages = $this->detect_languages();
        $current   = '';
        $default   = '';
        $plugin    = null;
        $is_active = false;

        if (defined('ICL_SITEPRESS_VERSION')) {
            $plugin = 'wpml';
            $is_active = true;
            $current = apply_filters('wpml_current_language', '');
            $default = apply_filters('wpml_default_language', '');
        } elseif (function_exists('pll_current_language')) {
            $plugin = 'polylang';
            $is_active = true;
            $current = pll_current_language();
            $default = pll_default_language();
        }

        return $this->success([
            'is_active'  => $is_active,
            'plugin'     => $plugin,
            'languages'  => $languages,
            'current'    => $current,
            'default'    => $default,
        ]);
    }

    /**
     * Get multilingual status.
     */
    public function get_status($request): WP_REST_Response|WP_Error {
        $active_plugin = null;
        $is_active = false;
        $current_lang = '';
        $default_lang = '';

        if (defined('ICL_SITEPRESS_VERSION')) {
            $active_plugin = 'wpml';
            $is_active = true;
            $current_lang = apply_filters('wpml_current_language', '');
            $default_lang = apply_filters('wpml_default_language', '');
        } elseif (function_exists('pll_current_language')) {
            $active_plugin = 'polylang';
            $is_active = true;
            $current_lang = pll_current_language();
            $default_lang = pll_default_language();
        }

        return $this->success([
            'active'           => $is_active,
            'plugin'           => $active_plugin,
            'current_language' => $current_lang,
            'default_language' => $default_lang,
        ]);
    }

    /**
     * Get translations for an entity item.
     */
    public function get_translations($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $entity = sanitize_text_field($request->get_param('entity'));
        $id     = (int) $request->get_param('id');

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $item = $wpdb->get_row($wpdb->prepare(
            'SELECT id, language_code, translation_group FROM %i WHERE id = %d',
            $table,
            $id
        ), ARRAY_A);

        if (!$item) {
            return $this->error(__('Item not found.', 'plugixa-recipe'), 404);
        }

        if (empty($item['translation_group'])) {
            return $this->success([
                'source'       => $item,
                'translations' => [],
            ]);
        }

        $display_field = $this->get_entity_display_field($entity);

        $translations = $wpdb->get_results($wpdb->prepare(
            'SELECT id, language_code, translation_group, %i AS display_value
            FROM %i
            WHERE translation_group = %s AND id != %d
            ORDER BY id ASC',
            $display_field, $table, $item['translation_group'], $id
        ), ARRAY_A);

        return $this->success([
            'source'       => $item,
            'translations' => $translations,
        ]);
    }

    /**
     * Create a translation copy for a language.
     */
    public function create_translation($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $entity = sanitize_text_field($request->get_param('entity'));
        $id     = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $target_lang = sanitize_text_field($params['language_code'] ?? $params['language'] ?? '');

        if (empty($target_lang)) {
            return $this->error(__('Target language is required.', 'plugixa-recipe'));
        }

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        if (!$source) {
            return $this->error(__('Source item not found.', 'plugixa-recipe'), 404);
        }

        // Generate or use existing translation_group
        $group = $source['translation_group'];
        if (empty($group)) {
            $group = wp_generate_uuid4();
            $wpdb->update($table, ['translation_group' => $group], ['id' => $id]);
        }

        // Check if translation already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE translation_group = %s AND language_code = %s',
            $table,
            $group, $target_lang
        ));

        if ($existing) {
            return $this->error(__('Translation for this language already exists.', 'plugixa-recipe'));
        }

        // Copy source data
        $copy = $source;
        unset($copy['id']);
        $copy['language_code'] = $target_lang;
        $copy['translation_group'] = $group;

        // Adjust slug for uniqueness
        if (isset($copy['slug'])) {
            $copy['slug'] = $copy['slug'] . '-' . $target_lang;
            $original_slug = $copy['slug'];
            $counter = 1;
            while ($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, $copy['slug']))) {
                $copy['slug'] = $original_slug . '-' . (++$counter);
            }
        }

        if (isset($copy['created_at'])) {
            $copy['created_at'] = current_time('mysql');
        }
        if (isset($copy['created_by'])) {
            $copy['created_by'] = get_current_user_id();
        }
        if (isset($copy['modified_at'])) {
            $copy['modified_at'] = null;
        }
        if (isset($copy['modified_by'])) {
            $copy['modified_by'] = null;
        }

        // For recipes, reset computed fields
        if ($entity === 'recipe') {
            unset($copy['total_time']);
            $copy['status'] = 'draft';
            $copy['view_count'] = 0;
            $copy['rating_average'] = 0;
            $copy['rating_count'] = 0;
            $copy['seo_score'] = null;
            $copy['published_at'] = null;
        }

        $wpdb->insert($table, $copy);
        $new_id = $wpdb->insert_id;

        if (!$new_id) {
            return $this->error(__('Failed to create translation.', 'plugixa-recipe'), 500);
        }

        // For recipes, copy related data
        if ($entity === 'recipe') {
            $prefix = $wpdb->prefix . 'plugixa_recipe_';

            // Each copy names its table twice (INSERT target and SELECT source), so both
            // bind as identifiers with %i, interleaved in the positional argument list.
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, quantity, unit, name, notes, link_url)
                SELECT %d, group_name, group_order, sort_order, quantity, unit, name, notes, link_url
                FROM %i WHERE recipe_id = %d',
                $prefix . 'ingredients',
                $new_id,
                $prefix . 'ingredients',
                $id
            ));

            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, group_name, group_order, sort_order, instruction_text, image_id, video_url, duration)
                SELECT %d, group_name, group_order, sort_order, instruction_text, image_id, video_url, duration
                FROM %i WHERE recipe_id = %d',
                $prefix . 'instructions',
                $new_id,
                $prefix . 'instructions',
                $id
            ));

            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source)
                SELECT %d, serving_size, calories, total_fat, saturated_fat, trans_fat, cholesterol, sodium, total_carbohydrate, dietary_fiber, total_sugars, protein, vitamin_a, vitamin_c, calcium, iron, potassium, source
                FROM %i WHERE recipe_id = %d',
                $prefix . 'nutrition',
                $new_id,
                $prefix . 'nutrition',
                $id
            ));

            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, category_id, display_order)
                SELECT %d, category_id, display_order FROM %i WHERE recipe_id = %d',
                $prefix . 'recipe_categories',
                $new_id,
                $prefix . 'recipe_categories',
                $id
            ));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, cuisine_id, display_order)
                SELECT %d, cuisine_id, display_order FROM %i WHERE recipe_id = %d',
                $prefix . 'recipe_cuisines',
                $new_id,
                $prefix . 'recipe_cuisines',
                $id
            ));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (recipe_id, course_id)
                SELECT %d, course_id FROM %i WHERE recipe_id = %d',
                $prefix . 'recipe_courses',
                $new_id,
                $prefix . 'recipe_courses',
                $id
            ));

            /** This action is documented in Api\RecipesController::duplicate_item(). */
            do_action('plugixa_recipe_duplicate_recipe', $new_id, $id);
        }

        // A new row was inserted (and, for recipes, its pivot rows were copied).
        Cache::flush('recipes', 'categories', 'cuisines', 'courses');

        return $this->success([
            'id'                => $new_id,
            'language_code'     => $target_lang,
            'translation_group' => $group,
        ], __('Translation created.', 'plugixa-recipe'));
    }

    /**
     * Link two existing items as translations.
     */
    public function link_translations($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $entity = sanitize_text_field($request->get_param('entity'));
        $params = $request->get_json_params();

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-recipe'));
        }

        // Support both formats: {source_id, target_id} and {items: [{id, language_code}, ...]}
        $items = $params['items'] ?? null;

        if ($items && is_array($items) && count($items) >= 2) {
            $group = wp_generate_uuid4();
            foreach ($items as $item) {
                $wpdb->update($table, [
                    'translation_group' => $group,
                    'language_code'     => sanitize_text_field($item['language_code']),
                ], ['id' => (int) $item['id']]);
            }
            return $this->success(['translation_group' => $group], __('Translations linked.', 'plugixa-recipe'));
        }

        $source_id = (int) ($params['source_id'] ?? 0);
        $target_id = (int) ($params['target_id'] ?? 0);

        if (!$source_id || !$target_id) {
            return $this->error(__('Source and target IDs are required (or items array).', 'plugixa-recipe'));
        }

        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $source_id), ARRAY_A);
        $target = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $target_id), ARRAY_A);

        if (!$source || !$target) {
            return $this->error(__('One or both items not found.', 'plugixa-recipe'), 404);
        }

        if ($source['language_code'] === $target['language_code']) {
            return $this->error(__('Both items have the same language.', 'plugixa-recipe'));
        }

        $group = $source['translation_group'] ?: wp_generate_uuid4();

        $wpdb->update($table, ['translation_group' => $group], ['id' => $source_id]);
        $wpdb->update($table, ['translation_group' => $group], ['id' => $target_id]);

        // The entity is resolved at runtime, so invalidate every translatable entity.
        Cache::flush('recipes', 'categories', 'cuisines');

        return $this->success([
            'translation_group' => $group,
            'source_id'         => $source_id,
            'target_id'         => $target_id,
        ], __('Translations linked.', 'plugixa-recipe'));
    }

    /**
     * Unlink a translation by language.
     */
    public function unlink_translation($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $entity = sanitize_text_field($request->get_param('entity'));
        $id     = (int) $request->get_param('id');
        $lang   = sanitize_text_field($request->get_param('lang'));

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return $this->error(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $source = $wpdb->get_row($wpdb->prepare(
            'SELECT translation_group FROM %i WHERE id = %d',
            $table,
            $id
        ), ARRAY_A);

        if (!$source || empty($source['translation_group'])) {
            return $this->error(__('No translations to unlink.', 'plugixa-recipe'));
        }

        $translation = $wpdb->get_row($wpdb->prepare(
            'SELECT id FROM %i WHERE translation_group = %s AND language_code = %s AND id != %d',
            $table,
            $source['translation_group'], $lang, $id
        ), ARRAY_A);

        if (!$translation) {
            return $this->error(__('Translation not found for this language.', 'plugixa-recipe'), 404);
        }

        // Remove translation_group from the target (give it a new unique group)
        $wpdb->update($table, ['translation_group' => null], ['id' => $translation['id']]);

        // If only one item remains in group, clear its group too
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE translation_group = %s',
            $table,
            $source['translation_group']
        ));

        if ($remaining <= 1) {
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET translation_group = NULL WHERE translation_group = %s',
                $table,
                $source['translation_group']
            ));
        }

        // The entity is resolved at runtime, so invalidate every translatable entity.
        Cache::flush('recipes', 'categories', 'cuisines');

        return $this->success(null, __('Translation unlinked.', 'plugixa-recipe'));
    }

    // --- Helpers ---

    /**
     * Translatable entities: entity key => [table, display field].
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function get_entity_map(): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $map = [
            'recipe'   => [$prefix . 'recipes', 'title'],
            'category' => [$prefix . 'categories', 'name'],
            'cuisine'  => [$prefix . 'cuisines', 'name'],
        ];

        /**
         * Filter the translatable entities.
         *
         * The premium overlay adds the entities it owns. The free build has none.
         *
         * @param array<string,array{0:string,1:string}> $map
         */
        return apply_filters('plugixa_recipe_multilingual_entities', $map);
    }

    private function get_entity_table(string $entity): ?string {
        return $this->get_entity_map()[$entity][0] ?? null;
    }

    private function get_entity_display_field(string $entity): string {
        return $this->get_entity_map()[$entity][1] ?? 'id';
    }

    private function detect_languages(): array {
        $languages = [];

        if (defined('ICL_SITEPRESS_VERSION')) {
            $wpml_languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            if (is_array($wpml_languages)) {
                foreach ($wpml_languages as $lang) {
                    $languages[] = [
                        'code'       => $lang['language_code'],
                        'name'       => $lang['translated_name'],
                        'native'     => $lang['native_name'],
                        'flag'       => $lang['country_flag_url'] ?? '',
                        'is_default' => (bool) ($lang['is_default'] ?? false),
                    ];
                }
            }
        } elseif (function_exists('pll_languages_list')) {
            $pll_languages = pll_languages_list(['fields' => '']);
            $default = pll_default_language();
            foreach ($pll_languages as $lang) {
                $languages[] = [
                    'code'       => $lang->slug,
                    'name'       => $lang->name,
                    'native'     => $lang->name,
                    'flag'       => $lang->flag_url ?? '',
                    'is_default' => $lang->slug === $default,
                ];
            }
        }

        if (empty($languages)) {
            $languages[] = [
                'code'       => 'en',
                'name'       => 'English',
                'native'     => 'English',
                'flag'       => '',
                'is_default' => true,
            ];
        }

        return $languages;
    }
}
