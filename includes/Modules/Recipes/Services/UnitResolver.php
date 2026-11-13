<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

defined('ABSPATH') || exit;

/**
 * Says what an ingredient's unit text measures, so a reader can switch between metric and
 * US amounts.
 *
 * A unit is free text ("cup", "Cups", "tbsp"). It resolves only when the SITE's own units
 * table has it, as a volume or a weight: the table is where an owner adds or corrects
 * units, and a guess here ("c" is a cup? "T" a tablespoon?) would convert a line wrongly
 * with nothing on the page to say so. An unresolved line is simply left as written.
 */
final class UnitResolver {

    /** @var array<string, array{type:string, system:string, factor:float}> lower-case spelling => unit */
    private array $known = [];

    /**
     * @param array<int, array<string, mixed>> $units Rows of the units table.
     */
    public function __construct(array $units) {
        foreach ($units as $unit) {
            $type   = (string) ($unit['type'] ?? '');
            $factor = (float) ($unit['conversion_factor'] ?? 0);

            // A count ("clove", "pinch") has nothing to convert to.
            if (!in_array($type, ['volume', 'weight'], true) || $factor <= 0) {
                continue;
            }

            $resolved = [
                'type'   => $type,
                // Anything that is not metric converts TO metric; the table says "imperial".
                'system' => 'metric' === ($unit['system'] ?? '') ? 'metric' : 'us',
                // Millilitres or grams in one of this unit.
                'factor' => $factor,
            ];

            foreach ([(string) ($unit['abbreviation'] ?? ''), (string) ($unit['name'] ?? '')] as $spelling) {
                $spelling = self::key($spelling);
                if ('' === $spelling) {
                    continue;
                }
                // The first row to claim a spelling keeps it; "cups" and "tbsp." follow it.
                $this->known += [$spelling => $resolved, $spelling . 's' => $resolved];
            }
        }
    }

    /**
     * @return array{type:string, system:string, factor:float}|null Null when the unit is not
     *         a volume or weight this site knows.
     */
    public function resolve(string $unit): ?array {
        return $this->known[self::key($unit)] ?? null;
    }

    private static function key(string $unit): string {
        return rtrim(strtolower(trim($unit)), '.');
    }
}
