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

    /** @var array<string, string> A unit NAME of the site, lower-case => the ending its plural takes. */
    private array $words = [];

    /**
     * @param array<int, array<string, mixed>> $units Rows of the units table.
     */
    public function __construct(array $units) {
        foreach ($units as $unit) {
            $type   = (string) ($unit['type'] ?? '');
            $factor = (float) ($unit['conversion_factor'] ?? 0);

            // Every unit's NAME is a word that has a plural - a count ("Clove") as much as a
            // measure ("Cup"). Its abbreviation is not, unless it is the name itself.
            $word = self::key((string) ($unit['name'] ?? ''));
            if ('' !== $word) {
                $this->words += [$word => preg_match('/(s|x|z|ch|sh)$/', $word) ? 'es' : 's'];
            }

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

    /**
     * A unit as it reads beside one, and beside more than one: "clove" / "cloves".
     *
     * Only for a unit written as a NAME in this site's units table, in either form, and with
     * the plural English makes of it ("s"; "es" after s, x, z, ch, sh) - so the caller must
     * ask only on a site whose language is English. Anything else is null, and is then shown
     * as the author wrote it whatever the amount: an abbreviation ("pc", "g", "tbsp") takes
     * no "s", and a word the table does not have ("leaf", "handful") is not guessed at.
     *
     * The author's own letters are kept: "Cloves" gives "Clove" / "Cloves".
     *
     * @return array{one:string, many:string}|null
     */
    public function words(string $unit): ?array {
        $written = rtrim(trim($unit), '.');
        $key     = strtolower($written);

        if (isset($this->words[$key])) {
            return ['one' => $written, 'many' => $written . $this->words[$key]];
        }

        foreach ($this->words as $word => $ending) {
            if ($key === $word . $ending) {
                return ['one' => substr($written, 0, -strlen($ending)), 'many' => $written];
            }
        }

        return null;
    }

    /**
     * Every spelling of a volume or weight the site knows, with what it measures:
     * ['tbsp' => ['volume', 'us', 14.7868], 'g' => ['weight', 'metric', 1.0], ...]. For a
     * script that adds two amounts of one thing written in two units (the shopping list).
     *
     * @return array<string, array{0:string, 1:string, 2:float}>
     */
    public function measures(): array {
        $out = [];
        foreach ($this->known as $spelling => $unit) {
            $out[$spelling] = [$unit['type'], $unit['system'], $unit['factor']];
        }

        return $out;
    }

    /**
     * Every unit word of the site in its two forms, lower-case: [['clove', 'cloves'], ...].
     * For a script that has to know two written units are one (the shopping list).
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public function word_pairs(): array {
        $pairs = [];
        foreach ($this->words as $word => $ending) {
            $pairs[] = [$word, $word . $ending];
        }

        return $pairs;
    }

    /**
     * Does the page read in English? The only language words() and word_pairs() can make a
     * plural in; every caller asks here before using them.
     */
    public static function reads_in_english(): bool {
        return 0 === strpos(strtolower((string) determine_locale()), 'en');
    }

    private static function key(string $unit): string {
        return rtrim(strtolower(trim($unit)), '.');
    }
}
