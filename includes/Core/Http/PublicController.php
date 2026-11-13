<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Http;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Base for the public (`/frontend/...`) REST controllers.
 *
 * These were one 1,500-line FrontendApi class; each module now owns its own public routes
 * (Recipes, Reviews, Collections, Favorites, Submissions), so switching a module off removes
 * them. What they share lives here - and it lives ONCE, because it used to live twice.
 *
 * Every route a subclass declares with `permission_callback => '__return_true'` is public
 * BY DESIGN and states beside it what it exposes and why anonymous access is safe:
 *
 * - Reads return exactly what the rendered page already shows - pinned to published /
 *   approved rows, selecting an explicit public column list (PlugixaRecipe\Modules\Recipes\Columns) that
 *   excludes submitter IPs, reviewer email addresses, user IDs and the audit trail.
 * - Writes are ones an anonymous visitor is deliberately allowed to perform (post a review,
 *   vote a review helpful, submit a recipe). A nonce would add nothing to an action open to
 *   logged-out callers, so abuse is bounded by Turnstile, a honeypot, per-IP hourly rate
 *   limits and moderation-before-publish.
 *
 * Routes that gate on identity rather than content (favourites, likes) require a login.
 *
 * RestPermissionsTest pins the exact public set; a new `__return_true` fails the build.
 */
abstract class PublicController extends RestController {

    /** Cloudflare Turnstile server-side verification endpoint. */
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare Turnstile is a CAPTCHA service, not asset offloading. Plugin Check's offloading list carries the pattern '(?<!api\.)cloudflare\.com', whose lookbehind exempts only api.cloudflare.com, so every other *.cloudflare.com host matches and Turnstile's challenges.cloudflare.com is caught by a rule aimed at CDN-hosted JS/CSS. The endpoint cannot be self-hosted: Cloudflare requires both the widget script and the siteverify call to go to its own host, and a token verified anywhere else would be meaningless. It is off by default, requires the site owner's own keys, and is disclosed in readme.txt under == External services ==.
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'frontend';
    }

    /**
     * Coerce one request value into a plain string before a sanitizer touches it.
     *
     * The submission payload accepts an ingredient or instruction either as a bare string
     * or as a keyed array, so both shapes reach the same code. A malformed third shape -
     * `{"name": []}` - reaches it too, and trim(), sanitize_text_field() and wp_kses_post()
     * all raise a TypeError on an array in PHP 8, which on a public endpoint is a 500 an
     * anonymous caller can trigger at will. Anything that is not scalar becomes ''.
     *
     * This deliberately does NOT sanitize: the caller picks the sanitizer that fits the
     * field (sanitize_text_field for a name, wp_kses_post for instruction text).
     *
     * @param mixed $value Raw value from the decoded request payload.
     */
    protected static function scalar_string($value): string {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Verify a Cloudflare Turnstile token - THE single implementation.
     *
     * The review form and the recipe submission form each used to carry a copy, and both
     * copies wrapped the verdict in `if (!is_wp_error($verify))`, so a network failure
     * skipped verification and let the submission through. Now in two modules, the
     * controllers share this one method rather than each keeping a copy again.
     *
     * Turnstile returns a pass/fail verdict, not reCAPTCHA v3's 0.0-1.0 score, so there is
     * no threshold to configure and none to get wrong.
     *
     * **This fails CLOSED.** A caller that cannot reach Cloudflare gets a distinct 503 so the
     * cause is diagnosable rather than looking like a rejected token.
     *
     * @param string $token  The `turnstile_token` supplied by the form.
     * @param string $secret The site owner's Turnstile secret key.
     * @return true|WP_Error True when the token is valid.
     */
    protected function verify_turnstile(string $token, string $secret) {
        if ('' === $token) {
            return $this->error(__('Captcha verification failed. Please try again.', 'plugixa-recipe'));
        }

        $verify = wp_remote_post(self::TURNSTILE_VERIFY_URL, [
            'timeout' => 10,
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            ],
        ]);

        if (is_wp_error($verify)) {
            return $this->error(
                __('Could not reach the captcha service. Please try again in a moment.', 'plugixa-recipe'),
                503
            );
        }

        $result = json_decode(wp_remote_retrieve_body($verify), true);

        if (!is_array($result) || empty($result['success'])) {
            return $this->error(__('Captcha verification failed. Please try again.', 'plugixa-recipe'));
        }

        return true;
    }
}
