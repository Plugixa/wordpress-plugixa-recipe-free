<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\App;

defined('ABSPATH') || exit;

/**
 * Which build is installed: free or premium.
 *
 * The premium build is the free one plus a `pro/` folder, so the folder is the answer. No
 * licence check: a licence is Freemius's business, and the free build must work with no
 * account at all. One helper, so the admin app's `isPro` flag and its "show the Pro features
 * page" flag can never disagree.
 *
 * The main plugin file's Freemius guard keeps its own `is_dir()`: it runs before any
 * autoloader is registered.
 */
final class Edition {

    /** The premium plans on plugixa.com: the free build's "See Pro plans" link. */
    public const PRICING_URL = 'https://www.plugixa.com/plugins/plugixa-recipe#pricing';

    /** `premium` when the `pro/` folder is on disk, `free` otherwise. */
    public static function current(): string {
        return is_dir(PLUGIXA_RECIPE_PATH . 'pro') ? 'premium' : 'free';
    }

    public static function is_premium(): bool {
        return 'premium' === self::current();
    }
}
