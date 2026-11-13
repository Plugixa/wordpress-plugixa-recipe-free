<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Deactivator
 */
class Deactivator {

    public static function deactivate(): void {
        self::clear_scheduled_events();
        flush_rewrite_rules();
    }

    private static function clear_scheduled_events(): void {
        wp_clear_scheduled_hook('plugixa_recipe_daily_cleanup');
    }
}
