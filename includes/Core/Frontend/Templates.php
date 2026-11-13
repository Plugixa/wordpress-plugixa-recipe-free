<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Core\Frontend;

defined('ABSPATH') || exit;

/**
 * Where the plugin's own templates live, now that each module keeps its own.
 *
 * Templates used to sit in one root templates/ folder and were included by path. They are
 * owned by modules now - the recipe card by Recipes, the nutrition label by Nutrition - and
 * several are shared: the card is drawn by four layouts, the archive and a widget. So a
 * template is asked for by NAME, and this class knows which folders to look in.
 *
 * Each module adds its `views/` directory from registerServices(). Core therefore names no
 * module, and a disabled module's templates still resolve - registerServices() runs for
 * every module, which matters because the single-recipe template includes the nutrition
 * label and the review form whether or not those apps are switched on.
 *
 * This resolves PLUGIN files only. A child theme's override of a layout or a widget is
 * looked up by the code that renders it (`<theme>/plugixa-recipe/<name>.php`), before it
 * ever asks here, and that lookup is deliberately unchanged.
 */
final class Templates {

    /** @var string[] Absolute directories, each with a trailing slash. */
    private static array $paths = [];

    public static function add_path(string $directory): void {
        $directory = trailingslashit(wp_normalize_path($directory));

        if (!in_array($directory, self::$paths, true)) {
            self::$paths[] = $directory;
        }
    }

    /**
     * Absolute path of a plugin template, e.g. 'recipe-card.php' or 'widgets/categories.php'.
     *
     * Template names are unique across modules. An unknown name returns '' - the callers
     * that take a name from configuration already check file_exists() and fall back.
     */
    public static function locate(string $name): string {
        // A name comes from our own code or a sanitised layout slug, never straight from a
        // request - but it ends up in an include, so it gets no chance to climb out.
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if ('' === $name || false !== strpos($name, '..')) {
            return '';
        }

        foreach (self::$paths as $directory) {
            if (is_file($directory . $name)) {
                return $directory . $name;
            }
        }

        return '';
    }

    /**
     * Answer a request for one of the plugin's own URLs that names nothing: a recipe id
     * that is not published, a category or cuisine slug that does not exist.
     *
     * The rewrite rule matched, so WordPress has no post to look for and would fall back to
     * the main query - the blog - with a 200. That is a soft 404: a search engine indexes
     * the blog under every mistyped or deleted recipe address. This marks the query as not
     * found, sends the status, and hands back the theme's 404 template.
     *
     * @param string $template What template_include was about to use, kept as the fallback.
     */
    public static function not_found(string $template): string {
        global $wp_query;

        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        $not_found = get_404_template();

        return '' !== $not_found ? $not_found : $template;
    }
}
