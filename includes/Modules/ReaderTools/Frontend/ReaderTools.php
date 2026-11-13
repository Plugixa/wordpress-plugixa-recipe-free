<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\ReaderTools\Frontend;

defined('ABSPATH') || exit;

/**
 * Puts the reader tools on a single recipe page.
 *
 * The markup is server-rendered (so every label is translated here and no script builds
 * HTML from a string); the scripts only move, copy and fill it.
 */
final class ReaderTools {

    public function init(): void {
        add_action('plugixa_recipe_single_assets', [$this, 'enqueue']);
        add_action('plugixa_recipe_single_ingredient_tools', [$this, 'ingredient_tools'], 10, 2);
        add_action('plugixa_recipe_single_method_tools', [$this, 'method_tools'], 10, 2);
        add_action('plugixa_recipe_single_tools', [$this, 'shell'], 10, 2);

        // The print view applies the scale and units the reader chose on the page.
        add_filter('plugixa_recipe_print_script_dependencies', [$this, 'on_print_view']);
    }

    public function enqueue(): void {
        // A recipe embedded twice in a post: wp_localize_script() APPENDS, so a second
        // pass would print the object twice.
        if (wp_script_is('plugixa-recipe-tools', 'enqueued')) {
            return;
        }

        $base = PLUGIXA_RECIPE_URL . 'assets/frontend/js/';

        // After the page's own script: recipes.js hands the amounts over to recipe-tools.js.
        wp_enqueue_script('plugixa-recipe-tools', $base . 'recipe-tools.js', ['plugixa-recipe-frontend'], PLUGIXA_RECIPE_VERSION, true);
        wp_enqueue_script('plugixa-recipe-timers', $base . 'timers.js', [], PLUGIXA_RECIPE_VERSION, true);
        wp_enqueue_script('plugixa-recipe-cook-mode', $base . 'cook-mode.js', ['plugixa-recipe-timers'], PLUGIXA_RECIPE_VERSION, true);

        $this->localize();
    }

    /**
     * The print view is a document of its own with one script; the reader's scale and
     * units are applied there by the same arithmetic, so that file comes along. It has
     * no dependency of its own there (the page's script is not on the print view).
     *
     * @param string[] $handles
     * @return string[]
     */
    public function on_print_view($handles): array {
        wp_register_script('plugixa-recipe-tools', PLUGIXA_RECIPE_URL . 'assets/frontend/js/recipe-tools.js', [], PLUGIXA_RECIPE_VERSION, true);
        $this->localize();

        return array_merge((array) $handles, ['plugixa-recipe-tools']);
    }

    private function localize(): void {
        // Words only. Nothing here expires, so a page cache can keep the page for a year.
        wp_localize_script('plugixa-recipe-tools', 'plugixaRecipeTools', [
            'units' => [
                'ml'   => _x('ml', 'unit: millilitre', 'plugixa-recipe'),
                'l'    => _x('L', 'unit: litre', 'plugixa-recipe'),
                'g'    => _x('g', 'unit: gram', 'plugixa-recipe'),
                'kg'   => _x('kg', 'unit: kilogram', 'plugixa-recipe'),
                'tsp'  => _x('tsp', 'unit: teaspoon', 'plugixa-recipe'),
                'tbsp' => _x('tbsp', 'unit: tablespoon', 'plugixa-recipe'),
                'cup'  => _x('cup', 'unit: one cup', 'plugixa-recipe'),
                'cups' => _x('cups', 'unit: several cups', 'plugixa-recipe'),
                'oz'   => _x('oz', 'unit: ounce', 'plugixa-recipe'),
                'lb'   => _x('lb', 'unit: pound', 'plugixa-recipe'),
            ],
            'i18n'  => [
                'copied'     => __('Copied', 'plugixa-recipe'),
                'copyFailed' => __('Could not copy', 'plugixa-recipe'),
                /* translators: %s: what the timer is for, e.g. "Step 3". */
                'timerDone'  => __('%s: time is up', 'plugixa-recipe'),
                /* translators: %s: a length of time, e.g. "10 min". */
                'startTimer' => __('Start a %s timer', 'plugixa-recipe'),
                /* translators: %d: step number. */
                'step'       => __('Step %d', 'plugixa-recipe'),
                /* translators: 1: current step number, 2: number of steps. */
                'stepOf'     => __('Step %1$d of %2$d', 'plugixa-recipe'),
                'pause'      => __('Pause', 'plugixa-recipe'),
                'resume'     => __('Resume', 'plugixa-recipe'),
                'finish'     => __('Finish', 'plugixa-recipe'),
                'next'       => __('Next', 'plugixa-recipe'),
            ],
        ]);
    }

    /**
     * @param object $recipe    The presented recipe.
     * @param string $unique_id The page's id prefix.
     */
    public function ingredient_tools($recipe, $unique_id): void {
        $this->view('reader-tools/toolbar.php', $recipe, (string) $unique_id);
    }

    /**
     * @param object $recipe    The presented recipe.
     * @param string $unique_id The page's id prefix.
     */
    public function method_tools($recipe, $unique_id): void {
        $this->view('reader-tools/method-bar.php', $recipe, (string) $unique_id);
    }

    /**
     * @param object $recipe    The presented recipe.
     * @param string $unique_id The page's id prefix.
     */
    public function shell($recipe, $unique_id): void {
        $this->view('reader-tools/my-notes.php', $recipe, (string) $unique_id);
        $this->view('reader-tools/shell.php', $recipe, (string) $unique_id);
    }

    /**
     * The page has two layouts and draws the ingredient list in one of them, but a
     * template included twice would print two toolbars with the same ids.
     *
     * @param object $recipe
     */
    private function view(string $name, $recipe, string $unique_id): void {
        static $drawn = [];

        if (isset($drawn[$name . $unique_id])) {
            return;
        }
        $drawn[$name . $unique_id] = true;

        $template = plugixa_recipe_template($name);
        if ('' !== $template) {
            include $template;
        }
    }
}
