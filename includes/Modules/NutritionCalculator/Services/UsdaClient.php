<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\NutritionCalculator\Services;

use PlugixaRecipe\Core\Http\Failure;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Asks USDA FoodData Central for foods by name.
 *
 * The one place this plugin's free build calls an outside service, and it does so only
 * when the site owner has switched the Nutrition Calculator on, entered their own API key,
 * and pressed "Calculate" on a recipe. What is sent: the ingredient's NAME and the key
 * (in a request header).
 * No visitor data, no recipe text, nothing about the site. readme.txt discloses it.
 *
 * Only "Foundation" and "SR Legacy" foods are asked for: generic foods analysed per 100 g.
 * Branded products are left out on purpose - there are hundreds of thousands, their figures
 * are the manufacturer's label, and "butter" would match a brand of popcorn.
 */
final class UsdaClient {

    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- An API the site owner opts into with their own key, not asset offloading; disclosed in readme.txt.
    private const SEARCH_URL = 'https://api.nal.usda.gov/fdc/v1/foods/search';

    /**
     * USDA nutrient number => our nutrition column, with the factor that turns the USDA
     * unit into ours. Vitamins A and C are stored as % Daily Value (FDA adult values:
     * 900 mcg RAE and 90 mg).
     *
     * @var array<string, array{0:string, 1:float}>
     */
    private const NUTRIENTS = [
        '208' => ['calories', 1.0],            // kcal
        '204' => ['total_fat', 1.0],           // g
        '606' => ['saturated_fat', 1.0],       // g
        '605' => ['trans_fat', 1.0],           // g
        '601' => ['cholesterol', 1.0],         // mg
        '307' => ['sodium', 1.0],              // mg
        '205' => ['total_carbohydrate', 1.0],  // g
        '291' => ['dietary_fiber', 1.0],       // g
        '269' => ['total_sugars', 1.0],        // g
        '203' => ['protein', 1.0],             // g
        '320' => ['vitamin_a', 100 / 900],     // mcg RAE -> % DV
        '401' => ['vitamin_c', 100 / 90],      // mg -> % DV
        '301' => ['calcium', 1.0],             // mg
        '303' => ['iron', 1.0],                // mg
        '306' => ['potassium', 1.0],           // mg
    ];

    private string $api_key;

    public function __construct(?string $api_key = null) {
        $settings      = (array) get_option('plugixa_recipe_settings', []);
        $this->api_key = trim((string) ($api_key ?? ($settings['usda_api_key'] ?? '')));
    }

    public function has_key(): bool {
        return '' !== $this->api_key;
    }

    /**
     * Up to five foods for a name, each with its nutrients per 100 g in OUR columns.
     *
     * @return array<int, array{fdc_id:int, description:string, nutrients:array<string, float>}>|WP_Error
     */
    public function search(string $name) {
        if (!$this->has_key()) {
            return Failure::make(__('Add your USDA FoodData Central API key in Settings first.', 'plugixa-recipe'), 400);
        }

        // The key goes in a header, not the address: addresses end up in server logs.
        $response = wp_remote_get(add_query_arg([
            'query'    => rawurlencode($name),
            'dataType' => rawurlencode('Foundation,SR Legacy'),
            'pageSize' => 5,
        ], self::SEARCH_URL), [
            'timeout' => 15,
            'headers' => ['X-Api-Key' => $this->api_key],
        ]);

        if (is_wp_error($response)) {
            return Failure::make(__('The food database could not be reached. Try again in a moment.', 'plugixa-recipe'), 502);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (403 === $status || 401 === $status) {
            return Failure::make(__('The food database refused the API key. Check it in Settings.', 'plugixa-recipe'), 400);
        }
        if (429 === $status) {
            return Failure::make(__('The food database is limiting requests for this key. Wait a while and try again.', 'plugixa-recipe'), 429);
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (200 !== $status || !is_array($body)) {
            return Failure::make(__('The food database sent an answer that could not be read.', 'plugixa-recipe'), 502);
        }

        return self::foods($body);
    }

    /**
     * The search answer as our own shape. Pure, so it is tested against a recorded answer.
     *
     * @param array<string, mixed> $body
     * @return array<int, array{fdc_id:int, description:string, nutrients:array<string, float>}>
     */
    public static function foods(array $body): array {
        $foods = [];

        foreach ((array) ($body['foods'] ?? []) as $food) {
            if (!is_array($food) || empty($food['fdcId'])) {
                continue;
            }

            $nutrients = [];
            foreach ((array) ($food['foodNutrients'] ?? []) as $nutrient) {
                $number = (string) ($nutrient['nutrientNumber'] ?? '');
                // Energy is listed twice (kcal and kJ) under different numbers; 208 is kcal.
                if (!isset(self::NUTRIENTS[$number]) || !is_numeric($nutrient['value'] ?? null)) {
                    continue;
                }
                [$column, $factor]  = self::NUTRIENTS[$number];
                $nutrients[$column] = round((float) $nutrient['value'] * $factor, 3);
            }

            $foods[] = [
                'fdc_id'      => (int) $food['fdcId'],
                'description' => sanitize_text_field((string) ($food['description'] ?? '')),
                'nutrients'   => $nutrients,
            ];
        }

        return $foods;
    }

    /** @return string[] Our nutrition columns that a food can fill. */
    public static function columns(): array {
        return array_column(self::NUTRIENTS, 0);
    }
}
