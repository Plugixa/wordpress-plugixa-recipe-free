<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Modules\Recipes\DietFlags;
use PlugixaRecipe\Modules\Recipes\Enums;
use PlugixaRecipe\Modules\Recipes\Repositories\SinglePageRepository;

defined('ABSPATH') || exit;

/**
 * A published recipe as a reader sees it: the one struct the single-recipe views draw.
 *
 * It was SinglePage::build_recipe_object(), which passed on about half of what a recipe
 * stores. Equipment, notes, tips, tags, courses, yield, source, five of the eleven diet
 * flags, an ingredient's link / optional / substitution and a step's title / tip / video /
 * timer were all saved by the editor and shown to nobody.
 *
 * Reads only. What belongs to one request (the view counter, "is it in my favourites")
 * stays with the caller, so a second render path can use this without counting a view.
 */
final class RecipePresenter {

    private SinglePageRepository $repository;

    public function __construct(?SinglePageRepository $repository = null) {
        $this->repository = $repository ?? new SinglePageRepository();
    }

    /**
     * @return object|null Null when the recipe does not exist or is not published.
     */
    public function recipe(int $id): ?object {
        // The row carries the public columns plus `status`, which Frontend\MetaTags reads
        // off this struct (see SinglePageRepository::find_published()).
        $recipe = $this->repository->find_published($id);

        if (!$recipe) {
            return null;
        }

        $recipe_id = (int) $recipe['id'];
        $title     = (string) ($recipe['title'] ?? '');

        // Image
        if (!empty($recipe['image_id'])) {
            $recipe['image_url'] = wp_get_attachment_image_url((int) $recipe['image_id'], 'large') ?: '';
            $recipe['image_alt'] = get_post_meta((int) $recipe['image_id'], '_wp_attachment_image_alt', true) ?: $title;
        } else {
            $recipe['image_url'] = '';
            $recipe['image_alt'] = $title;
        }

        $recipe['author_name'] = (string) ($recipe['author_name'] ?? '');

        // The date as people read it, and as a machine does (<time datetime> takes ISO 8601).
        $published = !empty($recipe['published_at']) && '0000-00-00 00:00:00' !== $recipe['published_at']
            ? (string) $recipe['published_at']
            : (string) ($recipe['created_at'] ?? '');
        $recipe['published_date'] = '' !== $published ? wp_date(get_option('date_format'), strtotime($published)) : '';
        $recipe['published_iso']  = '' !== $published ? (string) mysql2date('c', $published, false) : '';

        $recipe['rating']       = (float) ($recipe['rating_average'] ?? 0);
        $recipe['rating_count'] = (int) ($recipe['rating_count'] ?? 0);

        // Terms - child rows cached under the owning recipe.
        $recipe['categories'] = $this->repository->categories($recipe_id);
        $cuisines             = $this->repository->cuisines($recipe_id);
        $recipe['cuisines']   = $cuisines;
        $recipe['cuisine']    = !empty($cuisines) ? $cuisines[0]['name'] : '';
        $recipe['courses']    = $this->repository->courses($recipe_id);

        $recipe['ingredient_groups']  = $this->ingredient_groups(
            $this->repository->ingredients($recipe_id),
            new UnitResolver($this->repository->units())
        );
        $recipe['instruction_groups'] = $this->instruction_groups($this->repository->instructions($recipe_id));

        $nutrition_row       = $this->repository->nutrition($recipe_id);
        $recipe['nutrition'] = $nutrition_row ? (object) $nutrition_row : null;

        // Approved reviews. $sort_key only chooses which of the repository's five literal
        // queries runs; it never reaches the SQL, and an unknown value falls to 'newest'.
        $settings          = (array) get_option('plugixa_recipe_settings', []);
        $recipe['reviews'] = $this->repository->approved_reviews($recipe_id, $settings['reviews_default_sort'] ?? 'newest');

        /** This filter is documented in Api\FrontendApi::get_recipe(). */
        $recipe = apply_filters('plugixa_recipe_prepare_recipe_frontend', $recipe, $recipe_id);

        // All eleven flags, from the one list.
        $recipe['diet_badges'] = array_values(DietFlags::of($recipe));

        // An origin mark is not a credit: see Enums::ORIGIN_MARKS.
        if (in_array((string) ($recipe['source'] ?? ''), Enums::ORIGIN_MARKS, true)) {
            $recipe['source'] = '';
        }

        $difficulty                 = (string) ($recipe['difficulty'] ?? '');
        $recipe['difficulty_label'] = Enums::difficulty_labels()[$difficulty] ?? $difficulty;

        $recipe['equipment_items'] = array_map(
            static fn(array $item): object => (object) $item,
            Equipment::decode($recipe['equipment'] ?? '')
        );
        $recipe['tag_list'] = self::tags($recipe['tags'] ?? '');

        // "24 cookies": how much the recipe makes, when that is not a number of servings.
        // Nothing when it only repeats the servings ("6 servings" beside "Servings: 6").
        $yield_unit      = trim((string) ($recipe['yield_unit'] ?? ''));
        $repeats         = in_array(strtolower($yield_unit), ['', 'serving', 'servings', strtolower(trim((string) ($recipe['servings_unit'] ?? '')))], true);
        $recipe['yield'] = $repeats ? '' : trim(self::number($recipe['yield_amount'] ?? '') . ' ' . $yield_unit);

        $recipe['gallery_urls'] = $this->gallery($recipe['gallery'] ?? '', $title);
        $recipe['video_embed']  = $this->video_embed((string) ($recipe['video_url'] ?? ''));

        $recipe['permalink'] = plugixa_recipe_permalink($recipe_id, sanitize_title($title));
        $recipe['print_url'] = home_url("/recipe-print/{$recipe_id}/?auto=1");

        return (object) $recipe;
    }

    /**
     * Rows in stored order, cut into groups wherever the group name changes.
     *
     * `amount` is the quantity AS TEXT ("1 1/2") - the servings adjuster and child themes
     * read it under that name. The number derived from it is `amount_value`.
     *
     * `unit_type` / `unit_system` / `unit_factor` say what the unit measures when the site's
     * units table knows it as a volume or weight; they are empty for any other line, which
     * a reader's unit switch then leaves as written.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, object> { name, items: [ { amount, unit, name, text, notes, ... } ] }
     */
    public function ingredient_groups(array $rows, ?UnitResolver $units = null): array {
        $groups  = [];
        $current = null;

        foreach ($rows as $row) {
            $group_name = (string) ($row['group_name'] ?? '');

            if (null === $current || $current->name !== $group_name) {
                $current  = (object) ['name' => $group_name, 'items' => []];
                $groups[] = $current;
            }

            $measure = $units ? $units->resolve((string) ($row['unit'] ?? '')) : null;

            $current->items[] = (object) [
                'unit_type'    => $measure['type'] ?? '',
                'unit_system'  => $measure['system'] ?? '',
                'unit_factor'  => $measure['factor'] ?? 0.0,
                'amount'       => $row['quantity'] ?? '',
                'unit'         => $row['unit'] ?? '',
                'name'         => $row['name'] ?? '',
                'text'         => trim(($row['quantity'] ?? '') . ' ' . ($row['unit'] ?? '') . ' ' . ($row['name'] ?? '')),
                'notes'        => $row['notes'] ?? '',
                'amount_value' => isset($row['amount']) ? (float) $row['amount'] : null,
                'amount_max'   => isset($row['amount_max']) ? (float) $row['amount_max'] : null,
                'link_url'     => (string) ($row['link_url'] ?? ''),
                'is_optional'  => !empty($row['is_optional']),
                'substitution' => (string) ($row['substitution'] ?? ''),
            ];
        }

        return $groups;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, object> { name, steps: [ { title, text, image_url, tip, video_url, timer_seconds, timer_label } ] }
     */
    public function instruction_groups(array $rows): array {
        $groups  = [];
        $current = null;

        foreach ($rows as $row) {
            $group_name = (string) ($row['group_name'] ?? '');

            if (null === $current || $current->name !== $group_name) {
                $current  = (object) ['name' => $group_name, 'steps' => []];
                $groups[] = $current;
            }

            // Seconds are the precise value; a row written before they existed has minutes.
            $seconds = (int) ($row['timer_seconds'] ?? 0);
            if ($seconds <= 0 && !empty($row['duration'])) {
                $seconds = (int) $row['duration'] * MINUTE_IN_SECONDS;
            }

            $current->steps[] = (object) [
                'title'         => self::step_title((string) ($row['title'] ?? '')),
                'text'          => $row['instruction_text'] ?? '',
                'image_url'     => !empty($row['image_id']) ? (wp_get_attachment_image_url((int) $row['image_id'], 'medium') ?: '') : '',
                'tip'           => (string) ($row['tip'] ?? ''),
                'video_url'     => (string) ($row['video_url'] ?? ''),
                'timer_seconds' => $seconds,
                'timer_label'   => self::duration_label($seconds),
            ];
        }

        return $groups;
    }

    /**
     * A step's title, unless it only counts ("Step 3"): the page numbers the steps itself.
     */
    private static function step_title(string $title): string {
        $title = trim($title);

        return preg_match('/^(step\s*)?\d+[.:)]?$/i', $title) ? '' : $title;
    }

    /**
     * A length of time as a cook says it: "45 sec", "20 min", "1 hr 30 min".
     */
    public static function duration_label(int $seconds): string {
        if ($seconds <= 0) {
            return '';
        }
        if ($seconds < MINUTE_IN_SECONDS) {
            /* translators: %d: number of seconds. */
            return sprintf(__('%d sec', 'plugixa-recipe'), $seconds);
        }

        $minutes = (int) round($seconds / MINUTE_IN_SECONDS);
        $hours   = intdiv($minutes, 60);
        $minutes = $minutes % 60;

        // The same words as the page's prep / cook / total times.
        if (0 === $hours) {
            /* translators: %d: number of minutes. */
            return sprintf(_n('%d min', '%d mins', $minutes, 'plugixa-recipe'), $minutes);
        }
        if (0 === $minutes) {
            /* translators: %d: number of hours. */
            return sprintf(_n('%d hr', '%d hrs', $hours, 'plugixa-recipe'), $hours);
        }

        /* translators: 1: number of hours, 2: number of minutes. */
        return sprintf(__('%1$d hr %2$d min', 'plugixa-recipe'), $hours, $minutes);
    }

    /**
     * The stored tags (a JSON list, or the comma-separated text older rows hold) as names.
     *
     * @param mixed $stored
     * @return string[]
     */
    private static function tags($stored): array {
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored  = is_array($decoded) ? $decoded : explode(',', $stored);
        }
        if (!is_array($stored)) {
            return [];
        }

        $tags = [];
        foreach ($stored as $tag) {
            $tag = is_scalar($tag) ? trim((string) $tag) : '';
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    /** A stored decimal without its padding: "24.00" is 24, "1.50" is 1.5, nothing is ''. */
    private static function number($value): string {
        if (!is_numeric($value) || (float) $value <= 0) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param mixed $stored JSON list of attachment ids.
     * @return array<int, object>
     */
    private function gallery($stored, string $title): array {
        $ids = is_string($stored) && '' !== $stored ? json_decode($stored, true) : $stored;
        if (!is_array($ids)) {
            return [];
        }

        $images = [];
        foreach ($ids as $id) {
            $id    = (int) $id;
            $large = wp_get_attachment_image_url($id, 'large');
            if (!$large) {
                continue;
            }
            $images[] = (object) [
                'id'        => $id,
                'url'       => $large,
                'full'      => wp_get_attachment_image_url($id, 'full') ?: $large,
                'thumbnail' => wp_get_attachment_image_url($id, 'thumbnail') ?: $large,
                'alt'       => get_post_meta($id, '_wp_attachment_image_alt', true) ?: $title,
            ];
        }

        return $images;
    }

    /** oEmbed for a hosted video, a <video> element for a file. */
    private function video_embed(string $url): string {
        if ('' === $url) {
            return '';
        }

        $extension = strtolower(pathinfo(wp_parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (in_array($extension, ['mp4', 'webm', 'ogg'], true)) {
            return sprintf(
                '<video controls preload="metadata" playsinline><source src="%s" type="video/%s">%s</video>',
                esc_url($url),
                esc_attr($extension),
                esc_html__('Your browser does not support the video tag.', 'plugixa-recipe')
            );
        }

        return (string) (wp_oembed_get($url, ['width' => 1200]) ?: '');
    }
}
