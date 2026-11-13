<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Security;

defined('ABSPATH') || exit;

/**
 * The plugin's own capabilities, and who gets them on activation.
 *
 * Granted to administrators only. Editors, authors and contributors reach the same screens
 * through the WordPress capability each check falls back to - see plugixa_recipe_user_can().
 */
final class Capabilities {

    public const ALL = [
        'plugixa_recipe_manage',
        'plugixa_recipe_edit',
        'plugixa_recipe_delete',
        'plugixa_recipe_publish',
        'plugixa_recipe_settings',
    ];

    public static function grant(): void {
        $admin = get_role('administrator');

        if ($admin) {
            foreach (self::ALL as $capability) {
                $admin->add_cap($capability);
            }
        }
    }
}
