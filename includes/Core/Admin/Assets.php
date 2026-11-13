<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Core\Admin;

use PlugixaRecipe\Core\App\Edition;
use PlugixaRecipe\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Assets Class - Handles React app assets (HMR dev / production build)
 */
class Assets {

    /**
     * wp-admin stylesheets this screen drops.
     *
     * These style screens the app does not render - no list table, no post editor, no
     * dashboard widgets. `forms` is not merely unused, it is actively wrong: see
     * strip_unused_admin_styles().
     *
     * NOT dropped, unlike the rest of the Plugixa family: `media` and `media-views`. This
     * app opens the REAL WordPress media modal (ImagePicker.tsx / GalleryPicker.tsx call
     * window.wp.media) and runs TinyMCE (WysiwygEditor.tsx), so dropping those two would
     * leave the picker rendering unstyled.
     */
    private const DROP_STYLES = [
        'forms',
        'dashboard',
        'list-tables',
        'edit',
        'revisions',
        'themes',
        'about',
        'nav-menus',
        'widgets',
        'wp-pointer',
    ];

    /**
     * Marks this screen's <body>, so the surviving resets can reach MUI's portals.
     *
     * App.tsx currently pins every MUI overlay (Popover, Popper, Modal, Menu, Dialog,
     * Tooltip) to #plugixa-recipe-root via a `container` default prop, so portals stay
     * inside the mount point today. The reset is body-scoped anyway: adding a new overlay
     * type, or dropping one of those props, would silently move fields outside the reach of
     * a root-scoped rule and put the focus ring back on every dialog.
     */
    private const BODY_CLASS = 'plugixa-recipe-screen';

    private string $plugin_path;
    private string $plugin_url;
    private string $version;

    /** Set by enqueue_app_assets(), which only runs on this plugin's screens. */
    private bool $on_plugin_screen = false;

    public function __construct(string $plugin_path, string $plugin_url, string $version) {
        $this->plugin_path = $plugin_path;
        $this->plugin_url = $plugin_url;
        $this->version = $version;
    }

    public function enqueue_app_assets(): void {
        // Admin::enqueue_assets() has already checked we are on a plugin screen; record it
        // so the two filters below can gate on it. add_filter() holds a reference to $this,
        // which is what keeps this instance alive until the styles are actually printed.
        $this->on_plugin_screen = true;
        add_filter('print_styles_array', [$this, 'strip_unused_admin_styles']);
        add_filter('admin_body_class', [$this, 'mark_body']);

        $hmr_file = $this->plugin_path . 'includes/Core/Admin/Hmr.php';

        if (file_exists($hmr_file)) {
            require_once $hmr_file;
            $hmr = new Hmr($this->plugin_path);
            $hmr->enqueue_assets();
        } else {
            $this->enqueue_prod_assets();
        }

        $this->enqueue_admin_fixes();
        $this->enqueue_bundled_fonts();
        $this->enqueue_wp_media();
        $this->reset_admin_chrome();
        $this->inject_config();
    }

    /**
     * Take wp-admin's unused stylesheets off this one screen.
     *
     * THIS IS A REMOVAL, NOT A RESET. Do not "simplify" it back into one.
     *
     * WordPress styles bare form elements - input[type=text], select, textarea - at
     * specificity (0,1,1). Every MUI rule is a single Emotion class, (0,1,0). Where MUI
     * declares a property it emits a COMPOUND selector (0,2,0) and wins, which is why
     * padding and background survive; but where MUI declares nothing, WordPress applies
     * unopposed. Measured on a MUI size="small" field before this fix:
     *
     *     forms.css   input[type=text]  min-height: 40px   <- MUI sets none
     *     result      a 40px control rendered 57px tall (40 + 8.5 + 8.5 padding)
     *
     * A reset cannot win this. Any selector specific enough to beat (0,1,1) is ALSO more
     * specific than MUI's (0,1,0), so a rule that neutralises WordPress's min-height
     * clobbers MUI's sizing in the same breath and the field ends up with neither.
     *
     * And it has to be removed HERE. `wp-admin` is a meta handle that DEPENDS on `forms`,
     * so wp_dequeue_style('forms') silently does nothing - dependency resolution puts it
     * straight back. `print_styles_array` fires inside WP_Styles::all_deps(), AFTER
     * resolution, on the final list of handles about to print. A handle removed here is
     * removed even though something depends on it, and no core object is mutated. It also
     * survives load-styles.php concatenation, because that happens after the list settles.
     *
     * @param string[] $handles Handles WordPress is about to print.
     * @return string[]
     */
    public function strip_unused_admin_styles(array $handles): array {
        if (!$this->on_plugin_screen) {
            return $handles;
        }

        return array_values(array_diff($handles, self::DROP_STYLES));
    }

    /**
     * Put this plugin's class on the admin <body>. See BODY_CLASS.
     *
     * `admin_body_class` is applied after `admin_enqueue_scripts` has run, so the flag is
     * already set by the time this fires.
     *
     * @param string $classes Existing body classes.
     */
    public function mark_body(string $classes): string {
        return $this->on_plugin_screen ? trim($classes . ' ' . self::BODY_CLASS) : $classes;
    }

    /**
     * What is left after the strip. DELIBERATELY TINY - keep it that way.
     *
     * Every selector here is an ID or a body-scoped class, and every MUI rule is a single
     * Emotion class, so anything this method says, MUI loses. The test for adding a line is
     * therefore NOT "does it look right" - it is: which wp-admin rule is this beating, and
     * can that rule be removed instead? Removing it is nearly always correct.
     */
    private function reset_admin_chrome(): void {
        $body = 'body.' . self::BODY_CLASS;

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- style-less handle; it exists only to carry the inline CSS below, and $this->version is passed anyway.
        wp_register_style('plugixa-recipe-admin-reset', false, [], $this->version);
        wp_enqueue_style('plugixa-recipe-admin-reset');
        wp_add_inline_style(
            'plugixa-recipe-admin-reset',
            // Layout: the app is a full-height application, not a document. Admin notices
            // are deliberately left alone - hiding them would hide Freemius licence prompts
            // and genuine WordPress warnings.
            '#wpcontent{padding-left:0}'
            . '#wpbody-content{padding-bottom:0}'
            . '#wpfooter{display:none}'
            . '#plugixa-recipe-root{margin:0}'
            // THE FOCUS RING. It is NOT in `forms` and cannot be stripped with it.
            //
            // The admin COLOUR SCHEME - wp-admin/css/colors/<scheme>/colors.css - carries
            // its own copy:
            //
            //     input[type=text]:focus, … { box-shadow: 0 0 0 2px <theme colour> }
            //                                                                 (0,2,1)
            //
            // and that stylesheet paints the admin menu, so it stays. MUI shows focus by
            // thickening the fieldset it draws BEHIND the input and sets no box-shadow on
            // the input itself - so WordPress's ring is not overriding anything, it is
            // simply drawn on top and the field wears both. Measured before this fix:
            // rgb(56,88,233) 0 0 0 2px, on top of MUI's own outline.
            //
            // box-shadow ONLY. WordPress also sets border-color, but MUI's input has no
            // visible border to colour - resetting one we cannot see would mean overriding
            // one MUI may want later. (0,2,2) beats the colour scheme's (0,2,1).
            . $body . ' input:focus,' . $body . ' select:focus,' . $body . ' textarea:focus'
            . '{box-shadow:none}'
            // `common.css` stays - the menu and the notices need it - and it hard-codes
            // WordPress's greys on h1-h6 (measured #1d2327 and #3c434a on the app's own
            // headings). MUI Typography only *inherits* colour, so a bare element rule beats
            // it. Colour only: weight, size and margin belong to the theme and to every `sx`.
            . $body . ' h1,' . $body . ' h2,' . $body . ' h3,'
            . $body . ' h4,' . $body . ' h5,' . $body . ' h6{color:inherit}'
        );
    }

    /**
     * The media modal.
     *
     * Unlike other plugins in the family, this app opens the real WordPress media library -
     * window.wp.media in ImagePicker.tsx / GalleryPicker.tsx - so this stays, and `media` /
     * `media-views` stay out of DROP_STYLES.
     *
     * TinyMCE is NOT loaded any more. wp_enqueue_editor() and the `editor-buttons` style
     * were here for a WysiwygEditor component that was deleted; nothing in the app
     * references window.tinymce or wp.editor, and they cost every admin page load of the
     * app a second editor bundle.
     */
    private function enqueue_wp_media(): void {
        wp_enqueue_media();
    }

    private function enqueue_admin_fixes(): void {
        $css_file = $this->plugin_path . 'assets/backend/css/admin-fixes.css';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-recipe-admin-fixes',
                $this->plugin_url . 'assets/backend/css/admin-fixes.css',
                [],
                $this->version
            );
        }
    }

    /**
     * Load the self-hosted font faces (assets/frontend/fonts/) so the View font
     * picker can preview families locally without contacting any external CDN.
     */
    private function enqueue_bundled_fonts(): void {
        $css_file = $this->plugin_path . 'assets/frontend/fonts/fonts.css';

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-recipe-fonts',
                $this->plugin_url . 'assets/frontend/fonts/fonts.css',
                [],
                $this->version
            );
        }
    }

    private function enqueue_prod_assets(): void {
        // The premium overlay ships its own admin bundle (built with the pro modules
        // registered into the @pro registry); when present it supersedes the free one.
        // pro/ is stripped from the free build, so the free ZIP falls back to
        // assets/backend/build automatically.
        $build_rel = is_dir($this->plugin_path . 'pro/assets/backend/build')
            ? 'pro/assets/backend/build/'
            : 'assets/backend/build/';

        $build_path = $this->plugin_path . $build_rel;
        $build_url  = $this->plugin_url . $build_rel;

        $manifest_file = $build_path . '.vite/manifest.json';
        $entry_file = 'js/main.js';
        $chunks = [];

        if (file_exists($manifest_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the plugin's OWN Vite build manifest from disk (path built from PLUGIXA_RECIPE_PATH, guarded by file_exists above). Never a URL, so wp_remote_get() does not apply.
            $manifest = json_decode(file_get_contents($manifest_file), true);

            $entry_key = isset($manifest['src/main.tsx']) ? 'src/main.tsx' : 'src/main.jsx';

            // Read hashed entry filename from manifest
            if (isset($manifest[$entry_key]['file'])) {
                $entry_file = $manifest[$entry_key]['file'];
            }

            if (isset($manifest[$entry_key]['imports'])) {
                foreach ($manifest[$entry_key]['imports'] as $import) {
                    if (isset($manifest[$import]['file'])) {
                        $chunks[] = $manifest[$import]['file'];
                    }
                }
            }
        }

        add_action('admin_head', function() use ($build_url, $chunks) {
            foreach ($chunks as $chunk) {
                echo '<link rel="modulepreload" href="' . esc_url($build_url . $chunk) . '">' . "\n";
            }
        });

        // Admin app CSS (Tailwind pxr utilities). Vite emits it as a fixed
        // un-hashed name (cssCodeSplit:false) and the manifest entry does not
        // reference it, so it must be enqueued explicitly. The dev server
        // injects this CSS via JS, which is why HMR mode doesn't need it.
        $css_file = $build_path . 'css/main.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'plugixa-recipe-app',
                $build_url . 'css/main.css',
                [],
                (string) filemtime($css_file)
            );
        }

        // Use null version — the hashed filename provides cache busting
        wp_enqueue_script_module(
            'plugixa-recipe-app',
            $build_url . $entry_file,
            [],
            null
        );
    }

    private function inject_config(): void {
        $current_user = wp_get_current_user();

        $config = [
            // Used only when the URL carries no route of its own (see main.tsx).
            'initialRoute' => '/dashboard',
            'apiUrl'       => esc_url_raw(rest_url('plugixa-recipe/v1/')),
            'adminUrl'     => esc_url(admin_url()),
            'pluginUrl'    => esc_url($this->plugin_url),
            'nonce'        => wp_create_nonce('wp_rest'),
            'userId'       => get_current_user_id(),
            'siteName'     => get_bloginfo('name'),
            'permissions'  => $this->get_user_permissions(),
            'settings'     => $this->get_plugin_settings(),
            'i18n'         => $this->get_translations(),
            'currentUser'  => [
                'id'           => $current_user->ID,
                'display_name' => $current_user->display_name,
                'email'        => $current_user->user_email,
                'login'        => $current_user->user_login,
            ],
            // Presence of the pro/ overlay is the single source of truth for "is this the
            // premium build". There is no runtime licence object: the free build ships no
            // pro UI to gate, so this flag is purely informational (it lets a screen skip
            // a query whose route does not exist in free).
            'isPro'        => Edition::is_premium(),
            // `free` or `premium`. The free build shows a Pro features page and an upgrade
            // button to whoever manages settings; the premium build carries the same free
            // code and must not, so the app asks this rather than the presence of a screen.
            'edition'      => Edition::current(),
            // Where "See Pro plans" goes. A link the admin clicks, never a request. It travels
            // from here so the app's own source names no outside host.
            'pricingUrl'   => Edition::PRICING_URL,
            // Top bar: "View site", the non-production pill, and the account menu's log out.
            'homeUrl'      => esc_url_raw(home_url('/')),
            'env'          => wp_get_environment_type(),
            'logoutUrl'    => esc_url_raw(wp_logout_url(admin_url())),
        ];

        /**
         * The admin app's page config. The App Manager module adds the resolved module
         * state here (`apps`, `appRegistry`), which the app reads before its first render.
         *
         * @param array<string, mixed> $config
         */
        $config = (array) apply_filters('plugixa_recipe_admin_config', $config);

        add_action('admin_head', function () use ($config) {
            wp_print_inline_script_tag(
                'window.PlugixaRecipeConfig = ' . wp_json_encode($config) . ';'
            );
        });
    }

    /**
     * Capability payload handed to the React app.
     *
     * Must apply the same rule the REST layer applies (plugixa_recipe_user_can), or the
     * UI hides controls the API would accept. RestPermissionsTest asserts the two agree
     * for every role; it caught exactly that drift for the editor role, which the API
     * admitted through the edit_posts fallback while this payload reported false.
     */
    private function get_user_permissions(): array {
        // The same Capabilities::user_can() every route asks, for the recipes entity (the
        // controller RestPermissionsTest compares these flags with). An add-on that narrows
        // permissions on the `plugixa_recipe_user_can` filter is therefore reflected here too.
        return [
            'manage_options'          => current_user_can('manage_options'),
            'plugixa_recipe_manage'   => Capabilities::user_can('read', 'recipes'),
            'plugixa_recipe_edit'     => Capabilities::user_can('edit', 'recipes'),
            'plugixa_recipe_delete'   => Capabilities::user_can('delete', 'recipes'),
            'plugixa_recipe_publish'  => Capabilities::user_can('publish', 'recipes'),
            'plugixa_recipe_settings' => Capabilities::user_can('settings', 'recipes'),
        ];
    }

    private function get_plugin_settings(): array {
        $defaults = [
            'items_per_page'     => 20,
            'default_status'     => 'draft',
            'default_servings'   => 4,
            'measurement_system' => 'metric',
            'show_nutrition'     => true,
            'show_reviews'       => true,
            'enable_print'       => true,
            'date_format'        => 'M j, Y',
            'excerpt_length'     => 150,
        ];

        return array_merge($defaults, (array) get_option('plugixa_recipe_settings', []));
    }

    private function get_translations(): array {
        $strings = Strings::all();

        /**
         * Filter the admin i18n string map.
         *
         * Premium features live under pro/ and are stripped from the free build, so the
         * free build ships none of their labels. The keys the premium overlay adds here
         * match the `labelKey` values its React modules register into the proRegistry —
         * so in the free build the key is never registered (JS) and the string is never
         * emitted (PHP). Both halves vanish together.
         *
         * @param array<string,string> $strings
         */
        return apply_filters('plugixa_recipe_admin_i18n_strings', $strings);
    }
}
