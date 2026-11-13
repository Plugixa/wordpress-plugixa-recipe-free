<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\Recipes\Repositories\UnitRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * The measurement units a recipe can use, and conversion between two of them.
 *
 * The rules live here rather than in the controller: a conversion needs a quantity and two
 * known units of the same type, and a zero target factor cannot be divided by. It holds no
 * SQL: every query is the repository's.
 *
 * A refusal is returned as a WP_Error, never thrown. The controller maps it onto the response.
 */
final class UnitService {

    private UnitRepository $units;

    public function __construct(UnitRepository $units) {
        $this->units = $units;
    }

    /**
     * Get all available units, optionally filtered by system or type.
     *
     * @param mixed $system Raw request value.
     * @param mixed $type   Raw request value.
     * @return array{items: array<int, array<string, mixed>>, grouped: array<string, array<int, array<string, mixed>>>}
     */
    public function list($system, $type): array {
        $system = $system ? sanitize_text_field($system) : '';
        $type   = $type ? sanitize_text_field($type) : '';

        $items = $this->units->all($system, $type);

        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['conversion_factor'] = (float) $item['conversion_factor'];
        }
        // Without this the next loop writes each unit INTO the last slot through the
        // dangling reference, so the list ended with its second-to-last unit twice.
        unset($item);

        // Group by type for convenience
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['type']][] = $item;
        }

        return [
            'items'   => $items,
            'grouped' => $grouped,
        ];
    }

    /**
     * Convert a quantity from one unit to another.
     *
     * Both units must share the same type (volume, weight, count).
     * Conversion uses the base unit factor: result = quantity * (from_factor / to_factor)
     *
     * @param array<string, mixed> $params Raw, untrusted payload.
     * @return array<string, mixed>|WP_Error
     */
    public function convert(array $params) {
        $quantity  = isset($params['quantity']) ? (float) $params['quantity'] : null;
        $from_unit = sanitize_text_field($params['from_unit'] ?? '');
        $to_unit   = sanitize_text_field($params['to_unit'] ?? '');

        if ($quantity === null || empty($from_unit) || empty($to_unit)) {
            return Failure::make(__('quantity, from_unit, and to_unit are required.', 'plugixa-recipe'));
        }

        // Look up units by abbreviation or name
        $from = $this->units->lookup($from_unit);
        $to   = $this->units->lookup($to_unit);

        if (!$from) {
            /* translators: %s: the source unit abbreviation that could not be found. */
            return Failure::make(sprintf(__('Unknown unit: %s', 'plugixa-recipe'), $from_unit));
        }
        if (!$to) {
            /* translators: %s: the target unit abbreviation that could not be found. */
            return Failure::make(sprintf(__('Unknown unit: %s', 'plugixa-recipe'), $to_unit));
        }

        // Validate same type
        if ($from['type'] !== $to['type']) {
            return Failure::make(sprintf(
                /* translators: %1$s: source unit name, %2$s: source unit type, %3$s: target unit name, %4$s: target unit type. */
                __('Cannot convert between %1$s (%2$s) and %3$s (%4$s). Units must be the same type.', 'plugixa-recipe'),
                $from['name'], $from['type'], $to['name'], $to['type']
            ));
        }

        $from_factor = (float) $from['conversion_factor'];
        $to_factor   = (float) $to['conversion_factor'];

        if ($to_factor == 0) {
            return Failure::make(__('Invalid conversion factor.', 'plugixa-recipe'));
        }

        // Convert: quantity in base units / target factor
        $converted = $quantity * ($from_factor / $to_factor);

        return [
            'original_quantity'  => $quantity,
            'original_unit'     => $from['abbreviation'],
            'converted_quantity' => round($converted, 4),
            'converted_unit'    => $to['abbreviation'],
            'from'              => $from,
            'to'                => $to,
        ];
    }
}
