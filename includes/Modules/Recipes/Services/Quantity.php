<?php
/**
 * @package PlugixaRecipe
 */

namespace PlugixaRecipe\Modules\Recipes\Services;

defined('ABSPATH') || exit;

/**
 * An ingredient quantity as numbers.
 *
 * `quantity` is what the author typed and what the page prints: "1 1/2", "½", "2-3". The
 * `amount` / `amount_max` columns are the same quantity as numbers, for scaling and unit
 * conversion. They are derived here on every save and never taken from the request, so the
 * two cannot disagree.
 *
 * Reads: a whole number, a decimal (point or comma), a fraction, a mixed number, the
 * vulgar-fraction characters, and a range of any two of those ("2-3", "2 – 3", "2 to 3").
 * Anything else is no number at all - a reader then shows the text and does not scale it.
 *
 * The public form's JavaScript reads the same grammar; tests/fixtures/quantity-cases.json
 * is asserted by both, which is what keeps them the same.
 */
final class Quantity {

    private const VULGAR = [
        '½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4',
        '⅕' => '1/5', '⅖' => '2/5', '⅗' => '3/5', '⅘' => '4/5', '⅙' => '1/6',
        '⅚' => '5/6', '⅛' => '1/8', '⅜' => '3/8', '⅝' => '5/8', '⅞' => '7/8',
    ];

    /** The largest value decimal(10,3) holds. */
    private const MAX = 9999999.999;

    /**
     * @param mixed $quantity The text quantity.
     * @return array{amount: ?float, amount_max: ?float} `amount_max` is set for a range only.
     */
    public static function parse($quantity): array {
        $none = ['amount' => null, 'amount_max' => null];

        if (!is_scalar($quantity)) {
            return $none;
        }

        $text = trim((string) $quantity);
        if ('' === $text) {
            return $none;
        }

        // "1½" is one and a half: give the fraction a space of its own.
        foreach (self::VULGAR as $char => $fraction) {
            $text = str_replace($char, ' ' . $fraction, $text);
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        $number = '(\d+ \d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?)';

        if (preg_match('/^' . $number . '$/u', $text, $m)) {
            $amount = self::value($m[1]);

            return null === $amount ? $none : ['amount' => $amount, 'amount_max' => null];
        }

        if (preg_match('/^' . $number . ' ?(?:-|–|—|to) ?' . $number . '$/ui', $text, $m)) {
            $low  = self::value($m[1]);
            $high = self::value($m[2]);

            // "3-2" is not a range anyone meant; treat the text as unreadable.
            if (null === $low || null === $high || $high <= $low) {
                return $none;
            }

            return ['amount' => $low, 'amount_max' => $high];
        }

        return $none;
    }

    /** One number: "2", "2.5", "2,5", "1/2" or "1 1/2". Null when it cannot be stored. */
    private static function value(string $text): ?float {
        if (preg_match('/^(\d+) (\d+)\/(\d+)$/', $text, $m)) {
            $value = 0 === (int) $m[3] ? null : (int) $m[1] + (int) $m[2] / (int) $m[3];
        } elseif (preg_match('/^(\d+)\/(\d+)$/', $text, $m)) {
            $value = 0 === (int) $m[2] ? null : (int) $m[1] / (int) $m[2];
        } else {
            $value = (float) str_replace(',', '.', $text);
        }

        if (null === $value || $value <= 0 || $value > self::MAX) {
            return null;
        }

        return round($value, 3);
    }
}
