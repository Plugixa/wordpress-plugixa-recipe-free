<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Admin;

defined('ABSPATH') || exit;

/**
 * Admin-app strings that belong to no module: the verbs and states every screen uses.
 *
 * A string about one feature lives in that module's own Strings.php, and the registry merges
 * them all into the page config (ModuleRegistry::strings()). The React app asks for a string
 * by key with an English fallback - t('save', 'Save') - so a key missing here is never a
 * blank label, only an untranslated one.
 */
final class Strings {

    /** @return array<string, string> i18n key => translated text */
    public static function all(): array {
        return [
            'save'           => __('Save', 'plugixa-recipe'),
            'cancel'         => __('Cancel', 'plugixa-recipe'),
            'delete'         => __('Delete', 'plugixa-recipe'),
            'edit'           => __('Edit', 'plugixa-recipe'),
            'add'            => __('Add', 'plugixa-recipe'),
            'search'         => __('Search', 'plugixa-recipe'),
            'loading'        => __('Loading...', 'plugixa-recipe'),
            'error'          => __('An error occurred', 'plugixa-recipe'),
            'success'        => __('Success', 'plugixa-recipe'),
            'confirm_delete' => __('Are you sure you want to delete?', 'plugixa-recipe'),
            'no_results'     => __('No results found', 'plugixa-recipe'),
            'status'         => __('Status', 'plugixa-recipe'),
            'draft'          => __('Draft', 'plugixa-recipe'),
            'published'      => __('Published', 'plugixa-recipe'),
            'pending'        => __('Pending', 'plugixa-recipe'),
            'archived'       => __('Archived', 'plugixa-recipe'),
            'featured'       => __('Featured', 'plugixa-recipe'),

            // Shared pickers and cards (src/shared).
            'collapse'             => __('Collapse', 'plugixa-recipe'),
            'expand'               => __('Expand', 'plugixa-recipe'),
            'gallery_add'          => __('Add Images', 'plugixa-recipe'),
            'gallery_image'        => __('Gallery image', 'plugixa-recipe'),
            'gallery_remove'       => __('Remove image', 'plugixa-recipe'),
            'gallery_reorder_hint' => __('Drag to reorder, or press Space and use the arrow keys.', 'plugixa-recipe'),
            'picker_add'           => __('Add', 'plugixa-recipe'),
            'picker_create_failed' => __('It could not be added.', 'plugixa-recipe'),
            'picker_loading'       => __('Searching...', 'plugixa-recipe'),
            'picker_none'          => __('Nothing found', 'plugixa-recipe'),
            'picker_placeholder'   => __('Type to search', 'plugixa-recipe'),
        ];
    }
}
