<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Submissions\Frontend;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Core\Frontend\Assets;
use PlugixaRecipe\Modules\Recipes\Enums;
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
                 . '<a href="' . esc_url(wp_login_url(plugixa_recipe_current_url())) . '" class="pxr:inline-block pxr:rounded-md pxr:bg-indigo-600 pxr:px-4 pxr:py-2 pxr:text-sm pxr:font-semibold pxr:text-white hover:pxr:bg-indigo-500">'
                 . esc_html__('Log In', 'plugixa-recipe')
                 . '</a></div>';
        }

        Assets::listing('form');

        // Template variables.
        $unique_id     = 'pxr-form-' . wp_unique_id();
        $display_mode  = sanitize_text_field($atts['style']);
        $nonce         = plugixa_recipe_rest_nonce();
        $rest_url      = rest_url('plugixa-recipe/v1/frontend/submit-recipe');
        // The limit the form states and checks in the browser is the one the server applies:
        // the setting, or PHP's own upload limit when that is smaller (2 MB on many hosts).
        $max_file_size = max(1, min((int) ($settings['max_upload_size'] ?? 5), (int) floor(wp_max_upload_size() / MB_IN_BYTES)));

        $turnstile_enabled  = ! empty($settings['turnstile_form_enabled']);
        $turnstile_site_key = $settings['turnstile_site_key'] ?? '';

        Turnstile::enqueue($turnstile_enabled, $turnstile_site_key);

        // Fetch categories, cuisines and courses (each a read-through cached list).
        $category_options = $this->options->category_options();
        $cuisine_options  = $this->options->cuisine_options();
        $course_options   = $this->options->course_options();

        // The same choices the admin editor offers, so a submission never stores one it lacks.
        $difficulty_options = Enums::difficulty_labels();
        $cost_options       = Enums::cost_labels();
        $season_options     = Enums::season_labels();
        // The site's own units. The form used to carry a list of seventeen of its own, which
        // was neither the units table nor what the admin editor offers.
        $unit_options = $this->options->unit_options();

        // What the form's script needs to know. Nothing here is a nonce or server state, so
        // a page served from a cache a week later is still correct.
        wp_localize_script('plugixa-recipe-form', 'plugixaRecipeForm', [
            'units'         => $unit_options,
            'maxStepPhotos' => 8,
            'i18n'          => self::strings(),
        ]);

        ob_start();

        $template = plugixa_recipe_template( 'submission-form.php' );
        if (file_exists($template)) {
            include $template;
        }

        return ob_get_clean();
    }

    /**
     * Every message the form's script shows. They were English literals in the script, which
     * no translation could reach.
     *
     * @return array<string, string>
     */
    private static function strings(): array {
        return [
            'submit'            => __('Submit Recipe', 'plugixa-recipe'),
            'submitting'        => __('Submitting...', 'plugixa-recipe'),
            'submitted'         => __('Your recipe has been submitted successfully!', 'plugixa-recipe'),
            'failed'            => __('An error occurred. Please try again.', 'plugixa-recipe'),
            'network'           => __('Network error. Please check your connection and try again.', 'plugixa-recipe'),
            /* translators: %d: number of problems in the form */
            'fixErrors'         => __('Please fix %d problem(s) before submitting.', 'plugixa-recipe'),
            /* translators: %d: maximum file size in megabytes */
            'fileTooLarge'      => __('That file is too large. The maximum size is %dMB.', 'plugixa-recipe'),
            /* translators: %d: maximum number of gallery images */
            'galleryFull'       => __('At most %d gallery images are allowed.', 'plugixa-recipe'),
            /* translators: %d: maximum number of step photos */
            'stepPhotosFull'    => __('At most %d steps can have a photo.', 'plugixa-recipe'),
            'titleRequired'     => __('Recipe title is required.', 'plugixa-recipe'),
            'descRequired'      => __('Description is required.', 'plugixa-recipe'),
            'imageRequired'     => __('Recipe image is required.', 'plugixa-recipe'),
            'categoryRequired'  => __('Please select at least one category.', 'plugixa-recipe'),
            'difficultyRequired' => __('Difficulty is required.', 'plugixa-recipe'),
            'servingsRange'     => __('Servings must be between 1 and 999.', 'plugixa-recipe'),
            'prepRequired'      => __('Prep time is required.', 'plugixa-recipe'),
            'cookRequired'      => __('Cook time is required.', 'plugixa-recipe'),
            'timeRange'         => __('Enter a time between 0 and 9999 minutes.', 'plugixa-recipe'),
            'ingredientRequired' => __('At least one ingredient is required.', 'plugixa-recipe'),
            'stepRequired'      => __('At least one instruction step is required.', 'plugixa-recipe'),
            'timerInvalid'      => __('Use minutes, or a time such as 1:30, 90s or 1h 20m.', 'plugixa-recipe'),
            'urlInvalid'        => __('Please enter a valid URL (starting with http:// or https://).', 'plugixa-recipe'),
            'notNegative'       => __('This value cannot be negative.', 'plugixa-recipe'),
            'fieldRequired'     => __('This field is required.', 'plugixa-recipe'),
            /* translators: 1: the row's new position, 2: number of rows */
            'moved'             => __('Moved to position %1$d of %2$d.', 'plugixa-recipe'),
            /* translators: %d: number of rows added from pasted text */
            'pasted'            => __('%d row(s) added from your text. Check them before submitting.', 'plugixa-recipe'),
            'pastedNothing'     => __('Nothing in that text looked like a list.', 'plugixa-recipe'),
            'checkRow'          => __('Check this row: it did not read like an ingredient.', 'plugixa-recipe'),
            'addPhoto'          => __('Add a photo', 'plugixa-recipe'),
            'draftSaved'        => __('Draft kept in this browser.', 'plugixa-recipe'),
        ];
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
