<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator\Services;

use PlugixaRecipe\Core\Http\Failure;
use PlugixaRecipe\Modules\NutritionCalculator\Repositories\FoodMatchRepository;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Proposes a food for each ingredient of a recipe, and remembers the ones the editor
 * confirmed.
 *
 * It only ever PROPOSES. Nothing here writes a recipe's nutrition: the editor sees every
 * match and every weight, changes what is wrong, and the figures go into the form's own
 * fields, where they are saved like values typed by hand.
 */
final class FoodMatcher {

    /** One recipe's worth; a longer list is cut, not refused. */
    private const MAX_INGREDIENTS = 60;

    /** A search answer is public reference data that changes a few times a year. */
    private const SEARCH_TTL = WEEK_IN_SECONDS;

    private FoodMatchRepository $matches;

    private UsdaClient $usda;

    public function __construct(FoodMatchRepository $matches, ?UsdaClient $usda = null) {
        $this->matches = $matches;
        $this->usda    = $usda ?? new UsdaClient();
    }

    /**
     * What an ingredient is filed under: its name, lower-cased, spaces collapsed.
     */
    public static function key(string $name): string {
        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        return function_exists('mb_substr') ? mb_substr($key, 0, 191) : substr($key, 0, 191);
    }

    /**
     * Candidates for each named ingredient, in the order given.
     *
     * A name the editor matched before comes back as that one food, marked `remembered`,
     * with the unit weights they entered then, and costs no request.
     *
     * @param array<int, mixed> $names Raw, untrusted.
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function match(array $names) {
        $out  = [];
        $seen = [];

        foreach (array_slice($names, 0, self::MAX_INGREDIENTS) as $name) {
            $name = is_scalar($name) ? sanitize_text_field((string) $name) : '';
            $key  = self::key($name);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $known = $this->matches->find($key);
            if ($known) {
                $out[] = [
                    'name'       => $name,
                    'remembered' => true,
                    'unit_grams' => (object) (json_decode((string) ($known['unit_grams'] ?? ''), true) ?: []),
                    'candidates' => [[
                        'fdc_id'      => (int) $known['fdc_id'],
                        'description' => (string) $known['description'],
                        'nutrients'   => (object) (json_decode((string) $known['nutrients'], true) ?: []),
                    ]],
                ];
                continue;
            }

            $foods = $this->search($key);
            if (is_wp_error($foods)) {
                // The key is wrong or the service is down: say so once, not once per line.
                return $foods;
            }

            $out[] = [
                'name'       => $name,
                'remembered' => false,
                'unit_grams' => (object) [],
                'candidates' => array_map(static function (array $food): array {
                    $food['nutrients'] = (object) $food['nutrients'];
                    return $food;
                }, $foods),
            ];
        }

        return $out;
    }

    /**
     * Remember what the editor confirmed, so the next recipe starts from it.
     *
     * @param array<int, mixed> $confirmed Raw, untrusted: {name, fdc_id, description, nutrients, unit, grams_per_unit}.
     * @return int How many were stored.
     */
    public function remember(array $confirmed): int {
        $stored  = 0;
        $columns = array_flip(UsdaClient::columns());

        foreach (array_slice($confirmed, 0, self::MAX_INGREDIENTS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key    = self::key(is_scalar($item['name'] ?? null) ? sanitize_text_field((string) $item['name']) : '');
            $fdc_id = absint($item['fdc_id'] ?? 0);
            if ('' === $key || !$fdc_id) {
                continue;
            }

            // Only our own columns, only numbers: this is stored and later sent to an editor.
            $nutrients = [];
            foreach ((array) ($item['nutrients'] ?? []) as $column => $value) {
                if (isset($columns[$column]) && is_numeric($value) && (float) $value >= 0) {
                    $nutrients[$column] = round((float) $value, 3);
                }
            }

            $known      = $this->matches->find($key);
            $unit_grams = $known ? (array) (json_decode((string) ($known['unit_grams'] ?? ''), true) ?: []) : [];
            // A different food weighs differently: its unit weights do not carry over.
            if ($known && (int) $known['fdc_id'] !== $fdc_id) {
                $unit_grams = [];
            }
            $unit  = strtolower(trim(is_scalar($item['unit'] ?? null) ? sanitize_text_field((string) $item['unit']) : ''));
            $grams = is_numeric($item['grams_per_unit'] ?? null) ? (float) $item['grams_per_unit'] : 0.0;
            if ($grams > 0 && $grams < 100000) {
                // '' is "one of them": 2 eggs, 1 onion.
                $unit_grams[$unit] = round($grams, 2);
            }

            $this->matches->save(
                $key,
                $fdc_id,
                sanitize_text_field(is_scalar($item['description'] ?? null) ? (string) $item['description'] : ''),
                $nutrients,
                $unit_grams
            );
            $stored++;
        }

        return $stored;
    }

    public function ready(): bool {
        return $this->usda->has_key();
    }

    /**
     * @return array<int, array{fdc_id:int, description:string, nutrients:array<string, float>}>|WP_Error
     */
    private function search(string $key) {
        if (!$this->usda->has_key()) {
            return Failure::make(__('Add your USDA FoodData Central API key in Settings first.', 'plugixa-recipe'), 400);
        }

        $transient = 'plugixa_recipe_usda_' . md5($key);
        $cached    = get_transient($transient);
        if (is_array($cached)) {
            return $cached;
        }

        $foods = $this->usda->search($key);
        if (!is_wp_error($foods)) {
            set_transient($transient, $foods, self::SEARCH_TTL);
        }

        return $foods;
    }
}
