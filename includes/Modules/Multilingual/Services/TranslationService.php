<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Multilingual\Services;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_current_language / wpml_default_language / wpml_active_languages are filters DEFINED BY WPML. We are the consumer; they must keep WPML's names to resolve. Prefixing them would simply return nothing.

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Multilingual\Repositories\TranslationRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * What a translation link is and what may be done to one, for the admin REST routes.
 *
 * Supports WPML/Polylang: languages, status, and translation CRUD. The translatable
 * entities are recipes, categories and cuisines; extensions add their own through the
 * plugixa_recipe_multilingual_entities filter.
 *
 * It owns the entity allow-list. The entity arrives in the URL and decides which table is
 * read and written, so nothing reaches the repository except a table this class looked up.
 * It holds no SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown; "not found" on a read is null.
 *
 * Not to be confused with Multilingual in this folder, the WPML/Polylang detection
 * singleton the frontend uses.
 */
final class TranslationService {

    private TranslationRepository $translations;

    public function __construct(TranslationRepository $translations) {
        $this->translations = $translations;
    }

    /**
     * Available languages from WPML/Polylang.
     *
     * @return array<string, mixed>
     */
    public function languages(): array {
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

        return [
            'is_active'  => $is_active,
            'plugin'     => $plugin,
            'languages'  => $languages,
            'current'    => $current,
            'default'    => $default,
        ];
    }

    /**
     * Multilingual status.
     *
     * @return array<string, mixed>
     */
    public function status(): array {
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

        return [
            'active'           => $is_active,
            'plugin'           => $active_plugin,
            'current_language' => $current_lang,
            'default_language' => $default_lang,
        ];
    }

    /**
     * Translations of an entity item.
     *
     * @param mixed $entity Raw route value.
     * @return array<string, mixed>|WP_Error|null Null when there is no such item.
     */
    public function translations($entity, int $id) {
        $entity = sanitize_text_field($entity);

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return Failure::make(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $item = $this->translations->find_translation_info($table, $id);

        if (!$item) {
            return null;
        }

        if (empty($item['translation_group'])) {
            return [
                'source'       => $item,
                'translations' => [],
            ];
        }

        $display_field = $this->get_entity_display_field($entity);

        $translations = $this->translations->siblings($table, $display_field, $item['translation_group'], $id);

        return [
            'source'       => $item,
            'translations' => $translations,
        ];
    }

    /**
     * Create a translation copy for a language.
     *
     * @param mixed                $entity Raw route value.
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|WP_Error
     */
    public function create($entity, int $id, array $params) {
        $entity = sanitize_text_field($entity);
        $target_lang = sanitize_text_field($params['language_code'] ?? $params['language'] ?? '');

        if (empty($target_lang)) {
            return Failure::make(__('Target language is required.', 'plugixa-recipe'));
        }

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return Failure::make(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $source = $this->translations->find_uncached($table, $id);
        if (!$source) {
            return Failure::make(__('Source item not found.', 'plugixa-recipe'), 404);
        }

        // Generate or use existing translation_group
        $group = $source['translation_group'];
        if (empty($group)) {
            $group = wp_generate_uuid4();
            $this->translations->update_translation_fields($table, $id, ['translation_group' => $group]);
        }

        // Check if translation already exists
        $existing = $this->translations->translation_in_group($table, $group, $target_lang);

        if ($existing) {
            return Failure::make(__('Translation for this language already exists.', 'plugixa-recipe'));
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
            while ($this->translations->slug_taken($table, $copy['slug'])) {
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

        $new_id = $this->translations->insert_copy($table, $copy);

        if (!$new_id) {
            return Failure::make(__('Failed to create translation.', 'plugixa-recipe'), 500);
        }

        // For recipes, copy related data
        if ($entity === 'recipe') {
            $this->translations->copy_recipe_children($new_id, $id);

            /** This action is documented in Api\RecipesController::duplicate_item(). */
            do_action('plugixa_recipe_duplicate_recipe', $new_id, $id);
        }

        $this->translations->flush_translation_created();

        return [
            'id'                => $new_id,
            'language_code'     => $target_lang,
            'translation_group' => $group,
        ];
    }

    /**
     * Link existing items as translations of each other.
     *
     * @param mixed                $entity Raw route value.
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|WP_Error
     */
    public function link($entity, array $params) {
        $entity = sanitize_text_field($entity);

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return Failure::make(__('Invalid entity type.', 'plugixa-recipe'));
        }

        // Support both formats: {source_id, target_id} and {items: [{id, language_code}, ...]}
        $items = $params['items'] ?? null;

        if ($items && is_array($items) && count($items) >= 2) {
            $group = wp_generate_uuid4();
            $languages = [];
            foreach ($items as $item) {
                $languages[(int) $item['id']] = sanitize_text_field($item['language_code']);
            }
            $this->translations->link_items($table, $languages, $group);

            return ['translation_group' => $group];
        }

        $source_id = (int) ($params['source_id'] ?? 0);
        $target_id = (int) ($params['target_id'] ?? 0);

        if (!$source_id || !$target_id) {
            return Failure::make(__('Source and target IDs are required (or items array).', 'plugixa-recipe'));
        }

        $source = $this->translations->find_uncached($table, $source_id);
        $target = $this->translations->find_uncached($table, $target_id);

        if (!$source || !$target) {
            return Failure::make(__('One or both items not found.', 'plugixa-recipe'), 404);
        }

        if ($source['language_code'] === $target['language_code']) {
            return Failure::make(__('Both items have the same language.', 'plugixa-recipe'));
        }

        $group = $source['translation_group'] ?: wp_generate_uuid4();

        $this->translations->link_pair($table, $source_id, $target_id, $group);

        return [
            'translation_group' => $group,
            'source_id'         => $source_id,
            'target_id'         => $target_id,
        ];
    }

    /**
     * Unlink a translation by language.
     *
     * @param mixed $entity Raw route value.
     * @param mixed $lang   Raw route value.
     * @return true|WP_Error
     */
    public function unlink($entity, int $id, $lang) {
        $entity = sanitize_text_field($entity);
        $lang   = sanitize_text_field($lang);

        $table = $this->get_entity_table($entity);
        if (!$table) {
            return Failure::make(__('Invalid entity type.', 'plugixa-recipe'));
        }

        $source = $this->translations->find_group($table, $id);

        if (!$source || empty($source['translation_group'])) {
            return Failure::make(__('No translations to unlink.', 'plugixa-recipe'));
        }

        $translation = $this->translations->find_other_translation($table, $source['translation_group'], $lang, $id);

        if (!$translation) {
            return Failure::make(__('Translation not found for this language.', 'plugixa-recipe'), 404);
        }

        $this->translations->unlink($table, $translation['id'], $source['translation_group']);

        return true;
    }

    // --- Helpers ---

    /**
     * Translatable entities: entity key => [table, display field].
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function get_entity_map(): array {
        $map = [
            'recipe'   => [$this->translations->table_name('recipes'), 'title'],
            'category' => [$this->translations->table_name('categories'), 'name'],
            'cuisine'  => [$this->translations->table_name('cuisines'), 'name'],
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
