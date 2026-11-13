<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Cloudflare Turnstile widget script for a form that asks for it.
 *
 * Only the script tag lives here. Verifying the token is PublicController::verify_turnstile()
 * - the single implementation, and the one that fails closed.
 */
final class Turnstile {

    /**
     * Enqueue Cloudflare Turnstile for a public form.
     *
     * The templates used to print a raw <script src> tag, which WordPress.org rejects
     * (WordPress.WP.EnqueuedResources.NonEnqueuedScript). It only loads when the site
     * owner has switched the captcha on and supplied their own site key, so the plugin
     * contacts Cloudflare only on an explicit opt-in.
     *
     * The site key is NOT put in the URL (reCAPTCHA v3 needed `?render=<key>`; Turnstile
     * reads it from the widget's data-sitekey attribute), so the script URL is constant
     * and cacheable, and the key is not sprayed across referrer logs.
     *
     * @param bool   $enabled  Whether the captcha is switched on for this form.
     * @param string $site_key The site owner's Turnstile site key.
     */
    public static function enqueue(bool $enabled, string $site_key): void {
        if (!$enabled || $site_key === '') {
            return;
        }

        wp_enqueue_script(
            'plugixa-recipe-turnstile',
            // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare Turnstile is a CAPTCHA service, not asset offloading. Plugin Check's offloading list carries the pattern '(?<!api\.)cloudflare\.com', whose lookbehind exempts only api.cloudflare.com, so every other *.cloudflare.com host matches and Turnstile's challenges.cloudflare.com is caught by a rule aimed at CDN-hosted JS/CSS. The endpoint cannot be self-hosted: Cloudflare requires both the widget script and the siteverify call to go to its own host, and a token verified anywhere else would be meaningless. It is off by default, requires the site owner's own keys, and is disclosed in readme.txt under == External services ==.
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            [],
            null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Third-party Cloudflare Turnstile endpoint: always served current, and must not carry a plugin ?ver= cache-buster we do not control.
            true
        );
    }
}
