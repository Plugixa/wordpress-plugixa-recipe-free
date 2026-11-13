<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\AppManager\Services;

use PlugixaRecipe\Core\Modules\AppState;
use PlugixaRecipe\Core\Modules\ModuleRegistry;

defined('ABSPATH') || exit;

/**
 * The one-time move of the old Settings > General `enable_*` switches into the App Manager.
 *
 * Lives here, not in Core, because it names six modules. It runs on the
 * `plugixa_recipe_settings_ready` action - AFTER the settings defaults were merged, never
 * before: a key this deletes that the defaults still listed would be put straight back, and
 * with the stamp already written it would never be deleted again. That happened once.
 */
final class LegacyToggles {

    /**
     * Settings > General feature switches that became App Manager modules.
     * setting key => module id.
     */
    private const FEATURE_TOGGLES = [
        'enable_reviews'        => 'reviews',
        'enable_favorites'      => 'favorites',
        'enable_collections'    => 'collections',
        'enable_shopping_lists' => 'shopping_lists',
        'enable_nutrition'      => 'nutrition',
        'enable_submissions'    => 'submissions',
    ];

    /**
     * Switches that were read by nothing (`enable_seo` never reached any code path;
     * `enable_unit_conversion` gated no UI), so they have no module to become.
     */
    private const DEAD_TOGGLES = [ 'enable_seo', 'enable_unit_conversion' ];

    /**
     * Carry the old enable_* switches over to the App Manager, once, then delete them.
     *
     * A site that had Reviews switched off must still have them off after the upgrade, and
     * the old key must be GONE rather than left to disagree with the App Manager later -
     * `update_settings()` merges and `set_default_options()` only ever adds, so a key is
     * removed only by code that removes it (the purge_custom_code_settings() lesson).
     *
     * The guard is a stamp AND a presence check: if the stamp is set but the apps option has
     * vanished, this runs again. The settings keys are already gone by then, so it simply
     * recreates the option and the module defaults apply - idempotent.
     *
     * A flag already stored in the App Manager wins over a stale setting.
     */
    public static function migrate(): void {
        $state = new AppState();

        if ( get_option( 'plugixa_recipe_feature_toggles_migrated' ) && $state->stored() ) {
            return;
        }

        $settings = get_option( 'plugixa_recipe_settings' );
        $patch    = [];

        if ( is_array( $settings ) ) {
            foreach ( self::FEATURE_TOGGLES as $key => $app ) {
                if ( array_key_exists( $key, $settings ) ) {
                    $patch[ $app ] = (bool) $settings[ $key ];
                    unset( $settings[ $key ] );
                }
            }
            foreach ( self::DEAD_TOGGLES as $key ) {
                unset( $settings[ $key ] );
            }
            update_option( 'plugixa_recipe_settings', $settings );
        }

        // save() writes the option even for an empty patch, which is what satisfies the
        // presence half of the guard on the next request.
        $state->save( array_diff_key( $patch, $state->flags() ) );
        update_option( 'plugixa_recipe_feature_toggles_migrated', '1' );

        // This request already resolved its modules from the pre-migration state.
        ModuleRegistry::instance()->refresh();
    }
}
