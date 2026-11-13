<?php
/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace PlugixaRecipe\Api;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table data layer. Reads go through PlugixaRecipe\Cache, which the static sniff cannot trace through the wrapper; the write and bulk paths are uncached by nature and flush the affected entities afterwards. Every query is a constant literal with table identifiers bound via %i and values via $wpdb->prepare(), so the PreparedSQL.NotPrepared / InterpolatedNotPrepared sniffs are satisfied on their own and are NOT suppressed anywhere in this file.

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- This sniff counts prepare() arguments syntactically and cannot see through the spread operator, so the `...$args` call sites report the wrong count. Those values are passed in one pre-built positional array (built immediately above each call, table identifiers included) precisely so the SQL can stay a constant literal. This is an argument-counting warning, not a security sniff.

defined( 'ABSPATH' ) || exit;

use PlugixaRecipe\Cache;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Units REST Controller
 */
class UnitsController extends RestController {

    public function __construct() {
        parent::__construct();
        $this->rest_base = 'units';
    }

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/convert', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'convert'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
    }

    /**
     * Get all available units, optionally filtered by system or type.
     */
    public function get_items($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';

        $system = $request->get_param('system');
        $system = $system ? sanitize_text_field($system) : '';

        $type = $request->get_param('type');
        $type = $type ? sanitize_text_field($type) : '';

        // Each filter is a gate placeholder: bind 0 and the clause is constant-true, which
        // keeps WHERE literal instead of PHP-assembled. `system` stays backticked because it
        // became a reserved word in MySQL 8.0.3; `type` is backticked for symmetry.
        $args = [
            $prefix . 'units',
            $system !== '' ? 1 : 0, $system,
            $type !== '' ? 1 : 0, $type,
        ];

        // Cache key: a stable digest of the bound values, never the SQL text. The ORDER BY
        // here is entirely literal, so there is no identifier to bind.
        $items = Cache::remember('units', 'items|' . md5(wp_json_encode($args)), function () use ($wpdb, $args) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i
                  WHERE ( %d = 0 OR `system` = %s )
                    AND ( %d = 0 OR `type` = %s )
                  ORDER BY `system` ASC, `type` ASC, name ASC, id ASC',
                ...$args
            ), ARRAY_A);
        });

        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['conversion_factor'] = (float) $item['conversion_factor'];
        }

        // Group by type for convenience
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['type']][] = $item;
        }

        return $this->success([
            'items'   => $items,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Convert a quantity from one unit to another.
     *
     * Both units must share the same type (volume, weight, count).
     * Conversion uses the base unit factor: result = quantity * (from_factor / to_factor)
     */
    public function convert($request): WP_REST_Response|WP_Error {
        global $wpdb;
        $prefix = $wpdb->prefix . 'plugixa_recipe_';
        $params = $request->get_json_params();

        $quantity  = isset($params['quantity']) ? (float) $params['quantity'] : null;
        $from_unit = sanitize_text_field($params['from_unit'] ?? '');
        $to_unit   = sanitize_text_field($params['to_unit'] ?? '');

        if ($quantity === null || empty($from_unit) || empty($to_unit)) {
            return $this->error(__('quantity, from_unit, and to_unit are required.', 'plugixa-recipe'));
        }

        // Look up units by abbreviation or name
        $from = Cache::remember('units', "lookup:{$from_unit}", function () use ($wpdb, $prefix, $from_unit) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM %i WHERE abbreviation = %s OR name = %s LIMIT 1',
                $prefix . 'units',
                $from_unit, $from_unit
            ), ARRAY_A);
        });

        $to = Cache::remember('units', "lookup:{$to_unit}", function () use ($wpdb, $prefix, $to_unit) {
            return $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM %i WHERE abbreviation = %s OR name = %s LIMIT 1',
                $prefix . 'units',
                $to_unit, $to_unit
            ), ARRAY_A);
        });

        if (!$from) {
            /* translators: %s: the source unit abbreviation that could not be found. */
            return $this->error(sprintf(__('Unknown unit: %s', 'plugixa-recipe'), $from_unit));
        }
        if (!$to) {
            /* translators: %s: the target unit abbreviation that could not be found. */
            return $this->error(sprintf(__('Unknown unit: %s', 'plugixa-recipe'), $to_unit));
        }

        // Validate same type
        if ($from['type'] !== $to['type']) {
            return $this->error(sprintf(
                /* translators: %1$s: source unit name, %2$s: source unit type, %3$s: target unit name, %4$s: target unit type. */
                __('Cannot convert between %1$s (%2$s) and %3$s (%4$s). Units must be the same type.', 'plugixa-recipe'),
                $from['name'], $from['type'], $to['name'], $to['type']
            ));
        }

        $from_factor = (float) $from['conversion_factor'];
        $to_factor   = (float) $to['conversion_factor'];

        if ($to_factor == 0) {
            return $this->error(__('Invalid conversion factor.', 'plugixa-recipe'));
        }

        // Convert: quantity in base units / target factor
        $converted = $quantity * ($from_factor / $to_factor);

        return $this->success([
            'original_quantity'  => $quantity,
            'original_unit'     => $from['abbreviation'],
            'converted_quantity' => round($converted, 4),
            'converted_unit'    => $to['abbreviation'],
            'from'              => $from,
            'to'                => $to,
        ]);
    }
}
