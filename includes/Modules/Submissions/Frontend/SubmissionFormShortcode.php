<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Submissions\Frontend;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Assets;
use PlugixaRecipe\Modules\Submissions\Repositories\SubmissionFormRepository;
use PlugixaRecipe\Core\Frontend\Turnstile;

/**
 * [plugixa_recipe_form] - the public recipe submission form.
 *
 * The tag is always registered; it renders nothing while the Submissions app is off, because
 * the form posts to a route that only exists while the module is on.
 */
final class SubmissionFormShortcode {

    private SubmissionFormRepository $options;

    public function __construct() {
        $this->options = new SubmissionFormRepository();
    }

    public function register(): void {
        add_shortcode('plugixa_recipe_form', [$this, 'render_submission_form']);
    }

    /**
     * Render the [plugixa_recipe_form] shortcode for frontend submissions.
     */
    public function render_submission_form($atts): string {
        // `style` is the one attribute. `categories`, `cuisines` and `redirect` were accepted
        // here and advertised on the settings screen, and nothing ever read them.
        $atts = shortcode_atts([
            'style' => 'inline',
        ], $atts, 'plugixa_recipe_form');

        $settings = $this->get_settings();
        // The form posts to the Submissions module's route, which only exists while the
        // module is on - so the form only exists then too.
        if (!plugixa_recipe_app_enabled('submissions')) {
            return '';
        }

        // Login gate (configurable via settings).
        $require_login = ! empty($settings['submission_require_login']);
        if ($require_login && ! is_user_logged_in()) {
            return '<div class="pxr-custom-submission-login pxr:max-w-2xl pxr:mx-auto pxr:text-center pxr:py-12">'
                 . '<p class="pxr:text-gray-600 pxr:text-sm pxr:mb-4">'
                 . esc_html__('You must be logged in to submit a recipe.', 'plugixa-recipe')
                 . '</p>'
                 . '<a href="' . esc_url(wp_login_url(get_permalink())) . '" class="pxr:inline-block pxr:rounded-md pxr:bg-indigo-600 pxr:px-4 pxr:py-2 pxr:text-sm pxr:font-semibold pxr:text-white hover:pxr:bg-indigo-500">'
                 . esc_html__('Log In', 'plugixa-recipe')
                 . '</a></div>';
        }

        Assets::listing('form');

        // Template variables.
        $unique_id     = 'pxr-form-' . wp_unique_id();
        $display_mode  = sanitize_text_field($atts['style']);
        $nonce         = plugixa_recipe_rest_nonce();
        $rest_url      = rest_url('plugixa-recipe/v1/frontend/submit-recipe');
        $max_file_size = (int) ($settings['max_upload_size'] ?? 5);

        $turnstile_enabled  = ! empty($settings['turnstile_form_enabled']);
        $turnstile_site_key = $settings['turnstile_site_key'] ?? '';

        Turnstile::enqueue($turnstile_enabled, $turnstile_site_key);

        // Fetch categories, cuisines and courses (each a read-through cached list).
        $category_options = $this->options->category_options();
        $cuisine_options  = $this->options->cuisine_options();
        $course_options   = $this->options->course_options();

        $difficulty_options = [
            'easy'   => __('Easy', 'plugixa-recipe'),
            'medium' => __('Medium', 'plugixa-recipe'),
            'hard'   => __('Hard', 'plugixa-recipe'),
        ];
        $cost_options = [
            'budget'    => __('Budget', 'plugixa-recipe'),
            'medium'    => __('Medium', 'plugixa-recipe'),
            'expensive' => __('Expensive', 'plugixa-recipe'),
        ];
        $season_options = [
            'all'    => __('All Seasons', 'plugixa-recipe'),
            'spring' => __('Spring', 'plugixa-recipe'),
            'summer' => __('Summer', 'plugixa-recipe'),
            'autumn' => __('Autumn', 'plugixa-recipe'),
            'winter' => __('Winter', 'plugixa-recipe'),
        ];
        $unit_options = [
            ''      => __('Unit', 'plugixa-recipe'),
            'tsp'   => 'tsp',
            'tbsp'  => 'tbsp',
            'cup'   => 'cup',
            'oz'    => 'oz',
            'lb'    => 'lb',
            'g'     => 'g',
            'kg'    => 'kg',
            'ml'    => 'ml',
            'l'     => 'l',
            'piece' => 'piece',
            'slice' => 'slice',
            'pinch' => 'pinch',
            'dash'  => 'dash',
            'clove' => 'clove',
            'can'   => 'can',
            'bunch' => 'bunch',
            'sprig' => 'sprig',
        ];

        ob_start();

        $template = plugixa_recipe_template( 'submission-form.php' );
        if (file_exists($template)) {
            include $template;
        }

        return ob_get_clean();
    }

    /**
     * Get plugin settings.
     */
    private function get_settings(): array {
        $defaults = [
            'show_nutrition'       => true,
            'show_reviews'         => true,
            'enable_print'         => true,
            'excerpt_length'       => 150,
            'date_format'          => 'M j, Y',
            'measurement_system'   => 'metric',
            'enable_lazy_load'     => true,
        ];

        return array_merge($defaults, (array) get_option('plugixa_recipe_settings', []));
    }
}
