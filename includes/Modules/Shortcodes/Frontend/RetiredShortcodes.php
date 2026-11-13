<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Shortcodes\Frontend;

defined('ABSPATH') || exit;

/**
 * Tags an earlier version of this plugin registered and this one does not.
 *
 * A page written for 1.3.0 may still hold one of them. Unregistered, WordPress prints the
 * tag itself as text in the middle of the page; registered here, it prints nothing for a
 * visitor. Each is registered only when no other code has registered it by then, so whatever
 * does handle the tag always wins - the same happens when the app that handles it is
 * switched off.
 *
 * Someone who can switch apps on sees a short note instead of the empty space, saying why and
 * where to change it: a blank page explained nothing, and a "Form" page went blank unnoticed.
 *
 * @since 1.4.0
 */
final class RetiredShortcodes {

    /** Tags last registered by this plugin's free build in 1.3.0. */
    private const TAGS = [
        'plugixa_recipe_form',
        'plugixa_shopping_list',
        'plugixa_my_submissions',
    ];

    public function register(): void {
        foreach (self::TAGS as $tag) {
            if (!shortcode_exists($tag)) {
                add_shortcode($tag, [$this, 'render']);
            }
        }
    }

    /**
     * Nothing for a visitor; the note for whoever may switch apps (the App Manager's own rule).
     *
     * @param array<string, mixed>|string $atts
     */
    public function render($atts = [], ?string $content = null, string $tag = ''): string {
        return self::switched_off($tag);
    }

    /**
     * The same, for a handler that stays registered while its app is off and so is reached
     * instead of the stub above (the submission form's).
     */
    public static function switched_off(string $tag): string {
        if (!in_array($tag, self::TAGS, true) || !plugixa_recipe_user_can('plugixa_recipe_settings', 'manage_options')) {
            return '';
        }

        $shortcode_tag = $tag;
        $apps_url      = admin_url('admin.php?page=plugixa-recipe#/apps');

        // Nothing else on such a page loads the plugin's stylesheet; the note needs it. Same
        // handle and URL as Core\Frontend\Assets, so a page that has it already is unchanged.
        wp_enqueue_style('plugixa-recipe-frontend', PLUGIXA_RECIPE_URL . 'assets/frontend/build/css/frontend.css', [], PLUGIXA_RECIPE_VERSION);

        ob_start();
        include plugixa_recipe_template('retired-shortcode-notice.php');

        return (string) ob_get_clean();
    }
}
