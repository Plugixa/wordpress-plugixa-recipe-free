<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

defined('ABSPATH') || exit;

/**
 * One ingredient or instruction as its table row: sanitised, with the derived columns filled.
 *
 * Five writers store these rows - a recipe save, the two "replace the list" routes, a public
 * submission and the importers - and four of them carried a copy of this mapping. A column
 * added to one copy and not the others is lost on whichever route missed it, so there is one.
 *
 * Derived, never taken from the request: an ingredient's `amount` / `amount_max` (from the
 * text `quantity`, see Quantity) and whichever of a step's `duration` (whole minutes) and
 * `timer_seconds` the payload left out.
 */
final class ChildRows {

    /**
     * @param mixed $ing Raw payload row.
     * @return array<string, mixed>
     */
    public static function ingredient(int $recipe_id, $ing): array {
        $ing      = is_array($ing) ? $ing : [];
        $quantity = sanitize_text_field((string) ($ing['quantity'] ?? ''));
        $numbers  = Quantity::parse($quantity);

        return [
            'recipe_id'    => $recipe_id,
            'group_name'   => sanitize_text_field((string) ($ing['group_name'] ?? '')),
            'group_order'  => (int) ($ing['group_order'] ?? 0),
            'sort_order'   => (int) ($ing['sort_order'] ?? 0),
            'quantity'     => $quantity,
            'unit'         => sanitize_text_field((string) ($ing['unit'] ?? '')),
            'name'         => sanitize_text_field((string) ($ing['name'] ?? '')),
            'notes'        => sanitize_text_field((string) ($ing['notes'] ?? '')),
            'link_url'     => esc_url_raw((string) ($ing['link_url'] ?? '')),
            'amount'       => $numbers['amount'],
            'amount_max'   => $numbers['amount_max'],
            'is_optional'  => empty($ing['is_optional']) ? 0 : 1,
            'substitution' => sanitize_text_field((string) ($ing['substitution'] ?? '')),
        ];
    }

    /**
     * @param mixed $inst Raw payload row: an array, or a bare string holding the step text.
     * @return array<string, mixed>
     */
    public static function instruction(int $recipe_id, $inst): array {
        // The create gate accepts a bare string as a step, so every writer has to as well:
        // $inst['instruction_text'] on a string is null, and the step was stored empty.
        if (is_string($inst)) {
            $inst = ['instruction_text' => $inst];
        }
        $inst = is_array($inst) ? $inst : [];

        $timer    = isset($inst['timer_seconds']) && '' !== $inst['timer_seconds'] ? max(0, (int) $inst['timer_seconds']) : 0;
        $duration = isset($inst['duration']) && '' !== $inst['duration'] ? max(0, (int) $inst['duration']) : 0;

        if ($timer > 0 && 0 === $duration) {
            // Minutes are what export files and older templates read; a 40-second timer is 1.
            $duration = max(1, (int) round($timer / 60));
        } elseif ($duration > 0 && 0 === $timer) {
            $timer = $duration * 60;
        }

        return [
            'recipe_id'        => $recipe_id,
            'group_name'       => sanitize_text_field((string) ($inst['group_name'] ?? '')),
            'group_order'      => (int) ($inst['group_order'] ?? 0),
            'sort_order'       => (int) ($inst['sort_order'] ?? 0),
            'title'            => sanitize_text_field((string) ($inst['title'] ?? '')),
            'instruction_text' => wp_kses_post((string) ($inst['instruction_text'] ?? $inst['text'] ?? '')),
            'image_id'         => !empty($inst['image_id']) ? (int) $inst['image_id'] : null,
            'video_url'        => esc_url_raw((string) ($inst['video_url'] ?? '')),
            'duration'         => $duration > 0 ? $duration : null,
            'timer_seconds'    => $timer > 0 ? $timer : null,
            'tip'              => sanitize_textarea_field((string) ($inst['tip'] ?? '')),
        ];
    }
}
