<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Modules\Schema\Services;

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Modules\Recipes\DietFlags;
use PlugixaRecipe\Modules\Recipes\RenderedRecipes;
use PlugixaRecipe\Modules\Recipes\Services\Equipment;
use PlugixaRecipe\Modules\Schema\Repositories\JsonLdRepository;

/**
 * Structured data (JSON-LD) for the recipes a page shows.
 *
 * A page says one of two things about a recipe, and RenderedRecipes records which:
 * - the page is ABOUT it (the recipe's own page): a full schema.org Recipe - ingredients,
 *   steps in their sections, tools, nutrition, rating, reviews, video - and a breadcrumb;
 * - the page LISTS it (a listing, an archive): one ItemList of links to the recipes' own
 *   pages, which is where the full description belongs.
 *
 * Nothing is described that the page did not render.
 */
class JsonLd {

    /**
     * Most full Recipe payloads to emit on one page.
     *
     * A page is rarely about more than one recipe; a post that embeds several is the case
     * this bounds.
     */
    private const MAX_SCHEMA_RECIPES = 20;

    /** Most entries in a page's ItemList: a limit="-1" listing must not print thousands. */
    private const MAX_LIST_ITEMS = 100;

    /** Every query this builder runs. */
    private JsonLdRepository $repository;

    /** No arguments: Module.php and the tests construct this with a bare `new JsonLd()`. */
    public function __construct() {
        $this->repository = new JsonLdRepository();
    }

    /**
     * Initialize schema output hooks.
     */
    public function init(): void {
        add_action('wp_footer', [$this, 'output_schema'], 20);
    }

    /**
     * Output JSON-LD structured data in the page footer.
     *
     * Runs on wp_footer, which is after the content has been produced, so the registry is
     * populated by the time this reads it.
     */
    public function output_schema(): void {
        // Only describe recipes this page actually rendered. The old gate was "did any
        // plugin shortcode run", which was both too wide and too narrow: the submission
        // form set it too (so a bare form page advertised twenty recipes it never showed),
        // while the single-recipe page does not use a shortcode at all and so carried no
        // structured data. See PlugixaRecipe\Modules\Recipes\RenderedRecipes.
        if (!RenderedRecipes::has()) {
            return;
        }

        $settings = (array) get_option('plugixa_recipe_settings', []);
        if (empty($settings['enable_schema'])) {
            return;
        }

        // The recipes the page is about: each described in full.
        $subjects = $this->get_page_recipes(array_slice(RenderedRecipes::subjects(), 0, self::MAX_SCHEMA_RECIPES), true);
        foreach ($subjects as $recipe) {
            self::print_json_ld($this->build_recipe_schema($recipe));
        }

        // A breadcrumb describes where ONE page sits. It used to be printed once per recipe -
        // twenty of them on an archive - and its second crumb linked to /recipes/, an
        // address this plugin has no rule for.
        if (1 === count($subjects) && ($settings['enable_breadcrumb_schema'] ?? true)) {
            self::print_json_ld($this->build_breadcrumb_schema($subjects[0]));
        }

        // The recipes the page only lists: one list of links to their own pages.
        $listed = $this->get_page_recipes(array_slice(RenderedRecipes::listed(), 0, self::MAX_LIST_ITEMS), false);
        if (!empty($listed)) {
            self::print_json_ld($this->build_item_list($listed));
        }
    }

    /**
     * Encode a schema array and print it as a JSON-LD script tag.
     *
     * Uses wp_print_inline_script_tag() rather than hand-concatenating a <script>
     * element: WordPress.org rejects hand-built script tags, and this routes the
     * output through core's inline-script pipeline (so a CSP nonce filter, for
     * instance, applies to it like any other inline script).
     *
     * JSON_UNESCAPED_SLASHES is deliberately NOT set. Escaping "/" as "\/" is what
     * stops a "</script>" sequence inside any string value from terminating the
     * element early and turning recipe content into live markup; the JSON is
     * identical to a parser either way. Combined with strip_tags_deep() below, a
     * review author calling themselves "<script>alert(1)</script>" cannot break out.
     *
     * @param array<string,mixed> $schema
     */
    private static function print_json_ld(array $schema): void {
        $json = wp_json_encode(
            self::strip_tags_deep($schema),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if (false === $json) {
            return;
        }

        wp_print_inline_script_tag($json, ['type' => 'application/ld+json']);
    }

    /**
     * Strip HTML from every string in a schema payload, preserving structure and types.
     *
     * JSON-LD carries no markup by definition, so this is applied once to the whole
     * assembled array instead of at each of the dozen places a string enters it -
     * the recipe title, the servings unit, category and cuisine names, ingredient
     * lines, and above all the review author name, which arrives from an anonymous
     * public submission. Non-string scalars (ratingValue, position, reviewCount) are
     * returned untouched so they stay numbers in the encoded JSON.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function strip_tags_deep($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::strip_tags_deep($item);
            }
            return $out;
        }

        return is_string($value) ? wp_strip_all_tags($value) : $value;
    }

    /** The public address of a recipe - the same one its page declares canonical. */
    private static function url(array $recipe): string {
        return plugixa_recipe_permalink((int) $recipe['id'], (string) ($recipe['slug'] ?? ''));
    }

    /**
     * Build a complete schema.org/Recipe structured data array.
     *
     * @param array $recipe Recipe data from database.
     * @return array JSON-LD schema array.
     */
    private function build_recipe_schema(array $recipe): array {
        $url = self::url($recipe);

        $schema = [
            '@context' => 'https://schema.org',
            // Always Recipe. A setting once swapped this for HowTo, leaving every other
            // property a Recipe's: markup that is neither.
            '@type'    => 'Recipe',
            '@id'      => $url . '#recipe',
            'url'      => $url,
            'name'     => $recipe['title'] ?? '',
        ];

        // Image
        if (!empty($recipe['image_id'])) {
            $image_url = wp_get_attachment_image_url((int) $recipe['image_id'], 'full');
            if ($image_url) {
                $schema['image'] = [$image_url];
                // Add additional image sizes for Google
                $medium = wp_get_attachment_image_url((int) $recipe['image_id'], 'medium_large');
                $thumb = wp_get_attachment_image_url((int) $recipe['image_id'], 'thumbnail');
                if ($medium) $schema['image'][] = $medium;
                if ($thumb) $schema['image'][] = $thumb;
            }
        }

        // Author
        $author_name = $recipe['author_name'] ?? '';
        if (empty($author_name) && !empty($recipe['created_by'])) {
            $user = get_userdata((int) $recipe['created_by']);
            $author_name = $user ? $user->display_name : get_bloginfo('name');
        }
        $schema['author'] = [
            '@type' => 'Person',
            'name'  => $author_name ?: get_bloginfo('name'),
        ];

        // Date published
        if (!empty($recipe['published_at'])) {
            $schema['datePublished'] = gmdate('Y-m-d', strtotime($recipe['published_at']));
        } elseif (!empty($recipe['created_at'])) {
            $schema['datePublished'] = gmdate('Y-m-d', strtotime($recipe['created_at']));
        }

        // Description
        if (!empty($recipe['description'])) {
            $schema['description'] = wp_strip_all_tags($recipe['description']);
        }

        // Times (ISO 8601 duration)
        if (!empty($recipe['prep_time'])) {
            $schema['prepTime'] = $this->minutes_to_iso8601((int) $recipe['prep_time']);
        }
        if (!empty($recipe['cook_time'])) {
            $schema['cookTime'] = $this->minutes_to_iso8601((int) $recipe['cook_time']);
        }
        $total = ((int) ($recipe['prep_time'] ?? 0)) + ((int) ($recipe['cook_time'] ?? 0)) + ((int) ($recipe['rest_time'] ?? 0));
        if ($total > 0) {
            $schema['totalTime'] = $this->minutes_to_iso8601($total);
        }

        // Yield: the bare number first (what a search engine reads), then as the page words it,
        // then what the batch makes ("24 cookies") when the recipe says so.
        $yield = [];
        if (!empty($recipe['servings'])) {
            $yield[] = (string) (int) $recipe['servings'];
            $yield[] = trim((int) $recipe['servings'] . ' ' . (($recipe['servings_unit'] ?? '') ?: __('servings', 'plugixa-recipe')));
        }
        if (!empty($recipe['yield_amount'])) {
            $yield[] = trim($recipe['yield_amount'] . ' ' . ($recipe['yield_unit'] ?? ''));
        }
        if ($yield) {
            $schema['recipeYield'] = array_values(array_unique($yield));
        }

        // Category: the course (dinner, dessert) is what the property means; the site's own
        // categories follow it.
        $category_names = array_values(array_unique(array_filter(array_merge(
            array_column((array) ($recipe['courses'] ?? []), 'name'),
            array_column((array) ($recipe['categories'] ?? []), 'name')
        ))));
        if ($category_names) {
            $schema['recipeCategory'] = count($category_names) === 1 ? $category_names[0] : $category_names;
        }

        if (!empty($recipe['cuisines'])) {
            $cuisine_names = array_column($recipe['cuisines'], 'name');
            if (!empty($cuisine_names)) {
                $schema['recipeCuisine'] = count($cuisine_names) === 1 ? $cuisine_names[0] : $cuisine_names;
            }
        }

        // Nutrition
        if (!empty($recipe['nutrition'])) {
            $schema['nutrition'] = $this->build_nutrition_schema($recipe['nutrition']);
        }

        // Ingredients
        $ingredients = $this->get_recipe_ingredients((int) $recipe['id']);
        if (!empty($ingredients)) {
            $schema['recipeIngredient'] = [];
            foreach ($ingredients as $ing) {
                $line = '';
                if (!empty($ing['quantity'])) {
                    $line .= $ing['quantity'] . ' ';
                }
                if (!empty($ing['unit'])) {
                    $line .= $ing['unit'] . ' ';
                }
                $line .= $ing['name'];
                if (!empty($ing['notes'])) {
                    $line .= ', ' . $ing['notes'];
                }
                $schema['recipeIngredient'][] = trim($line);
            }
        }

        // Equipment
        $tools = Equipment::names($recipe['equipment'] ?? '');
        if ($tools) {
            $schema['tool'] = array_map(static fn (string $name): array => ['@type' => 'HowToTool', 'name' => $name], $tools);
        }

        // Instructions
        $instructions = $this->build_instructions($this->get_recipe_instructions((int) $recipe['id']));
        if ($instructions) {
            $schema['recipeInstructions'] = $instructions;
        }

        // Ratings and reviews only while the Reviews module is on. With it off the page shows
        // neither, and structured data must describe only what the page rendered - a rating
        // in the JSON-LD that no visitor can see is exactly what Google's review-snippet
        // policy penalises. (The old enable_reviews switch hid the reviews and left this in.)
        $reviews_on = plugixa_recipe_app_enabled('reviews');

        // Aggregate rating
        if ($reviews_on && !empty($recipe['rating_count']) && (int) $recipe['rating_count'] > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format((float) ($recipe['rating_average'] ?? 0), 1),
                'reviewCount' => (int) $recipe['rating_count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        // Reviews
        $reviews = $reviews_on ? $this->get_recipe_reviews((int) $recipe['id']) : [];
        if (!empty($reviews)) {
            $schema['review'] = [];
            foreach ($reviews as $review) {
                $review_schema = [
                    '@type'         => 'Review',
                    'reviewRating'  => [
                        '@type'       => 'Rating',
                        'ratingValue' => (int) $review['rating'],
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    ],
                    'author' => [
                        '@type' => 'Person',
                        'name'  => $review['author_name'],
                    ],
                ];
                if (!empty($review['review_text'])) {
                    $review_schema['reviewBody'] = wp_strip_all_tags($review['review_text']);
                }
                if (!empty($review['created_at'])) {
                    $review_schema['datePublished'] = gmdate('Y-m-d', strtotime($review['created_at']));
                }
                $schema['review'][] = $review_schema;
            }
        }

        // Video
        if (!empty($recipe['video_url'])) {
            $schema['video'] = $this->build_video_schema($recipe);
        }

        // Diets. A flag schema.org has a diet for is a formal claim (suitableForDiet); the
        // rest are said as keywords, with the recipe's own tags. DietFlags decides which.
        $diets    = DietFlags::of($recipe);
        $formal   = array_intersect_key(DietFlags::schema_map(), $diets);
        $keywords = array_merge(
            array_filter(array_map('strval', (array) json_decode((string) ($recipe['tags'] ?? ''), true)), static fn (string $tag): bool => '' !== trim($tag)),
            array_values(array_diff_key($diets, $formal))
        );
        if ($formal) {
            $schema['suitableForDiet'] = array_values(array_map(static fn (string $diet): string => 'https://schema.org/' . $diet, $formal));
        }
        if ($keywords) {
            $schema['keywords'] = implode(', ', array_values(array_unique($keywords)));
        }

        return $schema;
    }

    /**
     * The steps as recipeInstructions: a HowToSection for each named group ("Make the dough"),
     * holding its steps; steps under no heading stand on their own.
     *
     * The groups were flattened into one run of steps, which is not how the page reads and
     * loses what a guided, step-by-step result is built from.
     *
     * @param array<int, array<string, mixed>> $rows In group, then sort order.
     * @return array<int, array<string, mixed>>
     */
    private function build_instructions(array $rows): array {
        $out     = [];
        $section = null;

        foreach ($rows as $row) {
            $step = [
                '@type' => 'HowToStep',
                'text'  => wp_strip_all_tags((string) $row['instruction_text']),
            ];
            if ('' !== trim((string) ($row['title'] ?? ''))) {
                $step['name'] = $row['title'];
            }
            if (!empty($row['image_id'])) {
                $step_image = wp_get_attachment_image_url((int) $row['image_id'], 'large');
                if ($step_image) {
                    $step['image'] = $step_image;
                }
            }

            $group = trim((string) ($row['group_name'] ?? ''));
            if ('' === $group) {
                $section = null;
                $out[]   = $step;
                continue;
            }

            if (null === $section || $out[$section]['name'] !== $group) {
                $out[]   = ['@type' => 'HowToSection', 'name' => $group, 'itemListElement' => []];
                $section = array_key_last($out);
            }
            $out[$section]['itemListElement'][] = $step;
        }

        return $out;
    }

    /**
     * The recipes a page lists, as one ItemList of links to their own pages.
     *
     * @param array<int, array<string, mixed>> $recipes In the order the page shows them.
     */
    private function build_item_list(array $recipes): array {
        $items = [];
        foreach (array_values($recipes) as $index => $recipe) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'url'      => self::url($recipe),
                'name'     => $recipe['title'] ?? '',
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Where a recipe's page sits: Home, its first category (when it has one), the recipe.
     *
     * Every crumb but the last links to a page that exists. There is no "Recipes" crumb:
     * the plugin has no page of all recipes to send it to.
     */
    private function build_breadcrumb_schema(array $recipe): array {
        $items = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => __('Home', 'plugixa-recipe'),
                'item'     => home_url('/'),
            ],
        ];

        if (!empty($recipe['categories']) && is_array($recipe['categories'])) {
            $cat      = (array) $recipe['categories'][0];
            $cat_name = (string) ($cat['name'] ?? '');
            $cat_slug = (string) ($cat['slug'] ?? '');
            if ('' !== $cat_name && '' !== $cat_slug) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => $cat_name,
                    'item'     => plugixa_recipe_archive_url('category', $cat_slug),
                ];
            }
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => count($items) + 1,
            'name'     => $recipe['title'] ?? '',
            'item'     => self::url($recipe),
        ];

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Build NutritionInformation schema.
     */
    private function build_nutrition_schema(array $nutrition): array {
        $schema = ['@type' => 'NutritionInformation'];

        $map = [
            'serving_size'       => 'servingSize',
            'calories'           => 'calories',
            'total_fat'          => 'fatContent',
            'saturated_fat'      => 'saturatedFatContent',
            'trans_fat'          => 'transFatContent',
            'cholesterol'        => 'cholesterolContent',
            'sodium'             => 'sodiumContent',
            'total_carbohydrate' => 'carbohydrateContent',
            'dietary_fiber'      => 'fiberContent',
            'total_sugars'       => 'sugarContent',
            'protein'            => 'proteinContent',
        ];

        $unit_map = [
            'calories'           => ' calories',
            'total_fat'          => ' g',
            'saturated_fat'      => ' g',
            'trans_fat'          => ' g',
            'cholesterol'        => ' mg',
            'sodium'             => ' mg',
            'total_carbohydrate' => ' g',
            'dietary_fiber'      => ' g',
            'total_sugars'       => ' g',
            'protein'            => ' g',
        ];

        foreach ($map as $db_key => $schema_key) {
            if (isset($nutrition[$db_key]) && $nutrition[$db_key] !== null && $nutrition[$db_key] !== '') {
                $value = $nutrition[$db_key];
                if (isset($unit_map[$db_key])) {
                    // The column is a decimal: "290.00 calories" is the number as stored, not as said.
                    $value = (is_numeric($value) ? (string) (float) $value : $value) . $unit_map[$db_key];
                }
                $schema[$schema_key] = $value;
            }
        }

        return $schema;
    }

    /**
     * Build VideoObject schema from recipe video URL.
     */
    private function build_video_schema(array $recipe): array {
        $video = [
            '@type' => 'VideoObject',
            'name'  => $recipe['title'] ?? '',
        ];

        if (!empty($recipe['description'])) {
            $video['description'] = wp_strip_all_tags($recipe['description']);
        }

        $video_url = $recipe['video_url'];

        // Detect YouTube
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            $video['contentUrl'] = $video_url;
            $video['embedUrl'] = 'https://www.youtube.com/embed/' . $matches[1];
            // hqdefault exists for every video; maxresdefault only for some, and a thumbnail
            // that answers 404 fails the whole VideoObject.
            $video['thumbnailUrl'] = 'https://img.youtube.com/vi/' . $matches[1] . '/hqdefault.jpg';
        }
        // Detect Vimeo
        elseif (preg_match('/vimeo\.com\/(\d+)/', $video_url, $matches)) {
            $video['contentUrl'] = $video_url;
            $video['embedUrl'] = 'https://player.vimeo.com/video/' . $matches[1];
        }
        // Self-hosted
        else {
            $video['contentUrl'] = $video_url;
        }

        // Without a thumbnail of its own, the recipe's image stands in: VideoObject needs one.
        if (empty($video['thumbnailUrl']) && !empty($recipe['image_id'])) {
            $thumbnail = wp_get_attachment_image_url((int) $recipe['image_id'], 'large');
            if ($thumbnail) {
                $video['thumbnailUrl'] = $thumbnail;
            }
        }

        // The stored time is the site's local time: say so with its offset, instead of
        // stamping it "Z" as if it were UTC.
        if (!empty($recipe['published_at'])) {
            $video['uploadDate'] = mysql2date('c', $recipe['published_at'], false);
        }

        return $video;
    }

    /**
     * Convert minutes to ISO 8601 duration.
     *
     * @param int $minutes Number of minutes.
     * @return string ISO 8601 duration (e.g. PT1H30M).
     */
    private function minutes_to_iso8601(int $minutes): string {
        if ($minutes <= 0) {
            return 'PT0M';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        $duration = 'PT';
        if ($hours > 0) {
            $duration .= $hours . 'H';
        }
        if ($mins > 0 || $hours === 0) {
            $duration .= $mins . 'M';
        }

        return $duration;
    }

    /**
     * The published, schema-enabled recipes among the given ids, in the order given.
     *
     * @param int[] $ids
     * @param bool  $full True for a page's subjects: also read what a full Recipe needs.
     * @return array<int, array<string, mixed>>
     */
    private function get_page_recipes(array $ids, bool $full): array {
        if (empty($ids)) {
            return [];
        }

        // The id list travels as one comma-separated string: the repository binds it as a
        // single value, so its query stays a constant literal for any number of ids. Only
        // published, schema-enabled recipes come back.
        $id_list = implode(',', array_map('absint', $ids));
        $recipes = $this->repository->published_schema_recipes($id_list);

        if (empty($recipes)) {
            return [];
        }

        // IN () returns rows in storage order; restore the order the page rendered them in
        // so the structured data reads in the same sequence as the visible content.
        $position = array_flip($ids);
        usort(
            $recipes,
            static fn( $a, $b ) => ($position[(int) $a['id']] ?? PHP_INT_MAX) <=> ($position[(int) $b['id']] ?? PHP_INT_MAX)
        );

        // A listed recipe is a name and a link: nothing more is read for it.
        if (!$full) {
            return $recipes;
        }

        foreach ($recipes as &$recipe) {
            $recipe_id = (int) $recipe['id'];

            $recipe['categories'] = $this->repository->recipe_categories($recipe_id);
            $recipe['cuisines']   = $this->repository->recipe_cuisines($recipe_id);
            $recipe['courses']    = $this->repository->recipe_courses($recipe_id);
            $recipe['nutrition']  = $this->repository->recipe_nutrition($recipe_id);
        }
        unset($recipe);

        return $recipes;
    }

    /**
     * Get recipe ingredients.
     */
    private function get_recipe_ingredients(int $recipe_id): array {
        return $this->repository->recipe_ingredients($recipe_id);
    }

    /**
     * Get recipe instructions.
     */
    private function get_recipe_instructions(int $recipe_id): array {
        return $this->repository->recipe_instructions($recipe_id);
    }

    /**
     * Get approved recipe reviews.
     *
     * The approved-only filter is a hard literal in JsonLdRepository::approved_reviews().
     * Only build_recipe_schema() calls this, and only while the Reviews app is on.
     */
    private function get_recipe_reviews(int $recipe_id): array {
        return $this->repository->approved_reviews($recipe_id);
    }
}
