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

            // The free build's Pro features page, header button, dashboard card and App
            // Manager line (src/modules/pro-features). Never shown by the premium build: see
            // Core\App\Edition.
            'pro_nav'            => __('Upgrade to Pro', 'plugixa-recipe'),
            'pro_see_features'   => __('See Pro features', 'plugixa-recipe'),
            'pro_see_plans'      => __('See Pro plans', 'plugixa-recipe'),
            'pro_compare_plans'  => __('Compare plans', 'plugixa-recipe'),
            'pro_page_title'     => __('Plugixa Recipe Pro', 'plugixa-recipe'),
            'pro_page_intro'     => __('Everything in the free plugin keeps working. Pro adds the tools below for writing recipes faster, moving them between sites and plugins, and selling alongside them. Your recipes, reviews and settings stay as they are when you upgrade.', 'plugixa-recipe'),
            'pro_owned_note'     => __('Already bought Pro? Download it from your Plugixa account and install it. It takes over from this plugin and keeps all your data.', 'plugixa-recipe'),
            'pro_group_writing'  => __('Writing recipes', 'plugixa-recipe'),
            'pro_group_moving'   => __('Moving recipes', 'plugixa-recipe'),
            'pro_group_growing'  => __('Growing your site', 'plugixa-recipe'),
            'pro_group_readers'  => __('For your readers', 'plugixa-recipe'),
            'pro_f_plans' => __('Collections and meal plans', 'plugixa-recipe'),
            'pro_f_plans_desc' => __('Group recipes into collections and cookbooks, and plan the week meal by meal.', 'plugixa-recipe'),
            'pro_f_list' => __('Shopping lists', 'plugixa-recipe'),
            'pro_f_list_desc' => __('Readers add a recipe\'s ingredients to a list that works without an account and follows them when they log in.', 'plugixa-recipe'),
            'pro_f_submit' => __('Recipes from your readers', 'plugixa-recipe'),
            'pro_f_submit_desc' => __('A form for visitors to send you their recipes, with photos and steps, straight into moderation.', 'plugixa-recipe'),
            'pro_f_calc' => __('Calculated nutrition', 'plugixa-recipe'),
            'pro_f_calc_desc' => __('Work out a recipe\'s nutrition facts from its ingredients with the USDA food database.', 'plugixa-recipe'),
            'pro_f_tools' => __('Cooking tools', 'plugixa-recipe'),
            'pro_f_tools_desc' => __('Metric and US units, step timers, a distraction-free cook mode and private notes on every recipe.', 'plugixa-recipe'),
            'pro_f_ai'           => __('AI recipe drafting', 'plugixa-recipe'),
            'pro_f_ai_desc'      => __('Draft a full recipe from a topic, a web address or a document, then edit it before you publish.', 'plugixa-recipe'),
            'pro_f_fields'       => __('Custom fields', 'plugixa-recipe'),
            'pro_f_fields_desc'  => __('Add your own fields to the recipe form and show them on the recipe page.', 'plugixa-recipe'),
            'pro_f_demo'         => __('Demo content', 'plugixa-recipe'),
            'pro_f_demo_desc'    => __('Fill a new site with sample recipes in one click to try every layout, and remove them again.', 'plugixa-recipe'),
            'pro_f_files'        => __('Import and export', 'plugixa-recipe'),
            'pro_f_files_desc'   => __('Move recipes in and out as CSV or JSON, with their ingredients, steps and nutrition.', 'plugixa-recipe'),
            'pro_f_plugins'      => __('Import from other recipe plugins', 'plugixa-recipe'),
            'pro_f_plugins_desc' => __('Bring recipes over from WP Recipe Maker, Tasty Recipes, Create by Mediavine, WP Delicious, Cooked, Zip Recipes and Recipe Card Blocks.', 'plugixa-recipe'),
            'pro_f_seo'          => __('SEO analyzer', 'plugixa-recipe'),
            'pro_f_seo_desc'     => __('A score for each recipe against on-page and rich result checks, with a site-wide overview.', 'plugixa-recipe'),
            'pro_f_shop'         => __('WooCommerce products', 'plugixa-recipe'),
            'pro_f_shop_desc'    => __('Link recipes to products and show them in a tab on the product page.', 'plugixa-recipe'),
            'pro_f_support'      => __('Priority support', 'plugixa-recipe'),
            'pro_f_support_desc' => __('Faster answers from the team that builds the plugin.', 'plugixa-recipe'),
            'pro_card_title'     => __('Get more from Plugixa Recipe', 'plugixa-recipe'),
            'pro_card_body'      => __('AI recipe drafting, imports from other recipe plugins, an SEO analyzer and WooCommerce products come with Pro.', 'plugixa-recipe'),
            'pro_card_hide'      => __('Hide this card', 'plugixa-recipe'),
            'pro_apps_more'      => __('More apps come with Pro.', 'plugixa-recipe'),
            'pro_apps_link'      => __('See what Pro adds', 'plugixa-recipe'),
        ];
    }
}
