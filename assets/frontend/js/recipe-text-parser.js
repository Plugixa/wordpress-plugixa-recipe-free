/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe text parser - pasted recipe text into ingredient and step rows.
 *
 * ONE file, two users: the public submission form loads it as the script handle
 * "plugixa-recipe-parser", and the admin editor imports this same file (see
 * assets/backend/app/src/lib/recipeParser.ts). Hand-written, dependency-free and not
 * compiled, so there is exactly one set of rules for what "1 1/2 cups flour, sifted" means.
 *
 * It only PROPOSES rows. Both users show what it made of each line for review before
 * anything is added to a recipe: a parser that guesses silently is worse than no parser.
 *
 * The quantity grammar is the same one PlugixaRecipe\Modules\Recipes\Services\Quantity
 * reads on the server. tests/fixtures/quantity-cases.json is asserted against both.
 */

(function (root) {
    'use strict';

    var VULGAR = {
        '½': '1/2', '⅓': '1/3', '⅔': '2/3', '¼': '1/4', '¾': '3/4',
        '⅕': '1/5', '⅖': '2/5', '⅗': '3/5', '⅘': '4/5', '⅙': '1/6',
        '⅚': '5/6', '⅛': '1/8', '⅜': '3/8', '⅝': '5/8', '⅞': '7/8'
    };
    var VULGAR_CHARS = Object.keys(VULGAR).join('');

    // The largest value the amount column (decimal(10,3)) holds.
    var MAX = 9999999.999;

    // One number: a mixed number, a fraction, or a decimal with a point or a comma.
    var NUMBER = '(\\d+ \\d+\\/\\d+|\\d+\\/\\d+|\\d+(?:[.,]\\d+)?)';
    var SINGLE = new RegExp('^' + NUMBER + '$');
    var RANGE = new RegExp('^' + NUMBER + ' ?(?:-|–|—|to) ?' + NUMBER + '$', 'i');

    // The same, as it may open an ingredient line: digits and/or a vulgar fraction, then
    // optionally a range. What it captures is handed to parseQuantity() to be judged.
    var ONE = '(?:\\d+(?:[.,]\\d+)?(?:\\s*\\/\\s*\\d+)?(?:\\s+\\d+\\s*\\/\\s*\\d+)?\\s*[' + VULGAR_CHARS + ']?|[' + VULGAR_CHARS + '])';
    var LEADING = new RegExp('^(' + ONE + '(?:\\s*(?:-|–|—|\\bto\\b)\\s*' + ONE + ')?)(?=\\s|$|[a-zA-Z(])\\s*(.*)$', 'i');

    // Words a recipe uses for a unit, and the abbreviation each one means. A unit is only
    // recognised when that abbreviation is one of the site's own units (see matchUnit).
    var ALIASES = {
        teaspoon: 'tsp', teaspoons: 'tsp', tsp: 'tsp', tsps: 'tsp',
        tablespoon: 'tbsp', tablespoons: 'tbsp', tbsp: 'tbsp', tbsps: 'tbsp', tbs: 'tbsp', tbl: 'tbsp',
        cup: 'cup', cups: 'cup',
        ounce: 'oz', ounces: 'oz', oz: 'oz',
        pound: 'lb', pounds: 'lb', lb: 'lb', lbs: 'lb',
        gram: 'g', grams: 'g', gramme: 'g', grammes: 'g', g: 'g', gr: 'g',
        kilogram: 'kg', kilograms: 'kg', kilo: 'kg', kilos: 'kg', kg: 'kg',
        milliliter: 'ml', milliliters: 'ml', millilitre: 'ml', millilitres: 'ml', ml: 'ml',
        liter: 'L', liters: 'L', litre: 'L', litres: 'L', l: 'L',
        pint: 'pt', pints: 'pt', pt: 'pt',
        quart: 'qt', quarts: 'qt', qt: 'qt',
        gallon: 'gal', gallons: 'gal', gal: 'gal',
        piece: 'pc', pieces: 'pc', pc: 'pc', pcs: 'pc',
        pinch: 'pinch', pinches: 'pinch',
        clove: 'clove', cloves: 'clove',
        slice: 'slice', slices: 'slice',
        dash: 'dash', dashes: 'dash',
        can: 'can', cans: 'can', tin: 'can', tins: 'can',
        bunch: 'bunch', bunches: 'bunch',
        sprig: 'sprig', sprigs: 'sprig',
        stick: 'stick', sticks: 'stick',
        package: 'package', packages: 'package', pkg: 'package', packet: 'package', packets: 'package'
    };

    function round3(value) {
        return Math.round(value * 1000) / 1000;
    }

    /** One number as text -> a float, or null when it cannot be stored. */
    function numberValue(text) {
        var m;
        var value;

        if ((m = /^(\d+) (\d+)\/(\d+)$/.exec(text))) {
            value = Number(m[3]) === 0 ? null : Number(m[1]) + Number(m[2]) / Number(m[3]);
        } else if ((m = /^(\d+)\/(\d+)$/.exec(text))) {
            value = Number(m[2]) === 0 ? null : Number(m[1]) / Number(m[2]);
        } else {
            value = parseFloat(text.replace(',', '.'));
        }

        if (value === null || isNaN(value) || value <= 0 || value > MAX) {
            return null;
        }

        return round3(value);
    }

    /**
     * A quantity as numbers: {amount, amount_max}. amount_max is set for a range only; both
     * are null when the text is not a number ("a pinch"), and the text is then shown as typed.
     */
    function parseQuantity(text) {
        var none = { amount: null, amount_max: null };
        var value;
        var m;
        var low;
        var high;

        if (typeof text !== 'string' && typeof text !== 'number') {
            return none;
        }

        value = String(text).trim();
        if (value === '') {
            return none;
        }

        // "1½" is one and a half: give the fraction a space of its own.
        value = value.replace(new RegExp('[' + VULGAR_CHARS + ']', 'g'), function (ch) {
            return ' ' + VULGAR[ch];
        });
        value = value.replace(/\s+/g, ' ').trim();

        if ((m = SINGLE.exec(value))) {
            low = numberValue(m[1]);
            return low === null ? none : { amount: low, amount_max: null };
        }

        if ((m = RANGE.exec(value))) {
            low = numberValue(m[1]);
            high = numberValue(m[2]);
            // "3-2" is not a range anyone meant; treat the text as unreadable.
            if (low === null || high === null || high <= low) {
                return none;
            }
            return { amount: low, amount_max: high };
        }

        return none;
    }

    /** "- ", "* ", a bullet, "1." / "1)" and "[ ]" in front of a pasted line. */
    function stripMarker(line) {
        return line
            .replace(/^\s*(?:[-*•▪●–—]|\[[ xX]?\])\s+/, '')
            // "3. 1 cup rice": a number, a dot AND a space is a list marker, never a decimal.
            .replace(/^\s*\d{1,2}[.)]\s+/, '')
            .trim();
    }

    /**
     * The site unit a word means, or '' when it is not a unit here.
     *
     * @param {string} word  A word from the line.
     * @param {Array}  units The site's units: [{ value: 'tbsp', name: 'Tablespoon' }, ...].
     */
    function matchUnit(word, units) {
        var key = String(word).toLowerCase().replace(/\.$/, '');
        var i;
        var unit;
        var alias;

        if (key === '') {
            return '';
        }

        for (i = 0; i < units.length; i += 1) {
            unit = units[i];
            if (String(unit.value).toLowerCase() === key || String(unit.name || '').toLowerCase() === key) {
                return unit.value;
            }
        }

        // "T" and "t" are the one pair where case is the whole meaning.
        alias = word === 'T' ? 'tbsp' : (word === 't' ? 'tsp' : ALIASES[key]);
        if (!alias) {
            return '';
        }

        for (i = 0; i < units.length; i += 1) {
            if (String(units[i].value).toLowerCase() === alias.toLowerCase()) {
                return units[i].value;
            }
        }

        // No unit list was given (a page that has none): the alias stands for itself.
        return units.length === 0 ? alias : '';
    }

    /**
     * What is left of a line once the quantity and the unit are taken: the name, and the
     * notes that go beside it.
     *
     * @param {string} rest e.g. "diced tomatoes, drained" or "(14 oz) can tomatoes".
     * @returns {{name: string, notes: string}}
     */
    function splitRest(rest) {
        // TODO(owner): decide where a name ends and its notes begin. Lines worth trying:
        //   "can (14 oz) diced tomatoes, drained"
        //   "large eggs"                      (is "large" part of the name, or a note?)
        //   "salt and pepper to taste"
        //   "butter, softened, plus more for the pan"
        // This default is deliberately cautious: it only moves what is clearly an aside -
        // text in brackets, and everything after the first comma - and never rewrites a name.
        var notes = [];
        var name = String(rest).replace(/\s*\(([^()]*)\)\s*/g, function (_all, inside) {
            if (inside.trim() !== '') {
                notes.push(inside.trim());
            }
            return ' ';
        });
        var comma = name.indexOf(',');

        if (comma !== -1) {
            notes.push(name.slice(comma + 1).trim());
            name = name.slice(0, comma);
        }

        return {
            name: name.replace(/\s+/g, ' ').trim(),
            notes: notes.filter(Boolean).join(', ')
        };
    }

    /**
     * One pasted line as an ingredient row.
     *
     * @param {string} line
     * @param {Array}  [units] The site's units; see matchUnit().
     * @returns {{kind: string, quantity: string, unit: string, name: string, notes: string, sure: boolean}|null}
     *          null for a blank line. `sure` is false when the row should be looked at.
     */
    function parseIngredientLine(line, units) {
        var text = stripMarker(String(line === null || line === undefined ? '' : line));
        var list = Array.isArray(units) ? units : [];
        var row = { kind: 'item', quantity: '', unit: '', name: '', notes: '', sure: true };
        var m;
        var words;
        var unit;
        var rest;

        if (text === '') {
            return null;
        }

        // "For the sauce:" is a heading. A line that opens with a number is not, colon or no.
        if (/:$/.test(text) && !/^[\d¼-¾⅐-⅞]/.test(text)) {
            row.kind = 'group';
            row.name = text.replace(/:$/, '').trim();
            return row;
        }

        m = LEADING.exec(text);
        if (m && parseQuantity(m[1]).amount !== null) {
            row.quantity = m[1].replace(/\s+/g, ' ').replace(/\s*\/\s*/g, '/').trim();
            text = m[2];
        }

        words = text.split(/\s+/);
        // "fl oz" is the one unit that is two words.
        if (words.length > 1 && /^fl\.?$/i.test(words[0]) && /^oz\.?$/i.test(words[1]) && matchUnit('fl oz', list)) {
            row.unit = matchUnit('fl oz', list);
            words = words.slice(2);
        } else {
            unit = matchUnit(words[0] || '', list);
            // A unit needs something after it ("2 cloves" is two cloves, not two of nothing)
            // and a quantity before it ("can of beans" opens with a word, not a unit) - except
            // the two that are their own quantity: "pinch of salt", "dash of bitters".
            if (unit && words.length > 1 && (row.quantity !== '' || unit === 'pinch' || unit === 'dash')) {
                row.unit = unit;
                words = words.slice(1);
            }
        }

        // "2 cups of flour"
        if (row.unit !== '' && words.length > 1 && /^of$/i.test(words[0])) {
            words = words.slice(1);
        }

        rest = splitRest(words.join(' '));
        row.name = rest.name;
        row.notes = rest.notes;

        // Worth a look: nothing to name, or a line with neither a quantity nor a unit
        // (often a stray sentence that was pasted along with the list).
        row.sure = row.name !== '' && (row.quantity !== '' || row.unit !== '' || row.name.split(' ').length <= 4);

        return row;
    }

    /** A pasted block as ingredient rows, one per non-blank line. */
    function parseIngredientBlock(text, units) {
        return String(text === null || text === undefined ? '' : text)
            .split(/\r\n|\r|\n/)
            .map(function (line) { return parseIngredientLine(line, units); })
            .filter(Boolean);
    }

    /**
     * A pasted block as step rows: [{kind: 'item', text}, {kind: 'group', name}, ...].
     *
     * With blank lines in it, each PARAGRAPH is a step (a step wrapped over several lines
     * stays one step). Without, each line is. "1.", "2)" and "Step 3:" in front are dropped;
     * a short line that ends with a colon is a heading.
     */
    function parseStepBlock(text) {
        var source = String(text === null || text === undefined ? '' : text).replace(/\r\n|\r/g, '\n').trim();
        var chunks;

        if (source === '') {
            return [];
        }

        chunks = /\n\s*\n/.test(source)
            ? source.split(/\n\s*\n/).map(function (paragraph) { return paragraph.replace(/\s*\n\s*/g, ' '); })
            : source.split('\n');

        return chunks
            .map(function (chunk) {
                var step = chunk.trim()
                    .replace(/^(?:step\s*)?\d{1,2}\s*[.):\-]\s+/i, '')
                    .replace(/^[-*•]\s+/, '')
                    .trim();

                if (step === '') {
                    return null;
                }
                if (/:$/.test(step) && step.length <= 60 && !/[.!?]/.test(step.slice(0, -1))) {
                    return { kind: 'group', name: step.replace(/:$/, '').trim() };
                }
                return { kind: 'item', text: step };
            })
            .filter(Boolean);
    }

    /**
     * A step's timer as it is typed, in seconds; null for text that is not a time, and for zero.
     *
     * A bare number is MINUTES - what a "duration" field has always meant, and what most
     * steps need. For anything finer: "90s", "1:30", "1:05:00", "1h 20m", "2 min".
     */
    function parseTimer(text) {
        var value = String(text === null || text === undefined ? '' : text).trim().toLowerCase();
        var seconds = null;
        var parts;
        var m;

        if (value === '') {
            return null;
        }

        if (/^\d+([.,]\d+)?$/.test(value)) {
            seconds = Math.round(parseFloat(value.replace(',', '.')) * 60);
        } else if (/^\d+:\d{1,2}(:\d{1,2})?$/.test(value)) {
            parts = value.split(':').map(Number);
            seconds = parts.length === 3 ? parts[0] * 3600 + parts[1] * 60 + parts[2] : parts[0] * 60 + parts[1];
        } else {
            m = /^(?:(\d+)\s*h(?:ours?|rs?)?)?\s*(?:(\d+)\s*m(?:in(?:ute)?s?)?)?\s*(?:(\d+)\s*s(?:ec(?:ond)?s?)?)?$/.exec(value);
            if (m && (m[1] || m[2] || m[3])) {
                seconds = Number(m[1] || 0) * 3600 + Number(m[2] || 0) * 60 + Number(m[3] || 0);
            }
        }

        return seconds !== null && seconds > 0 ? seconds : null;
    }

    /** Seconds as the field shows them: whole minutes as a bare number, anything else as m:ss. */
    function formatTimer(seconds) {
        var total = Math.round(Number(seconds) || 0);
        var hours;
        var minutes;
        var rest;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };

        if (total <= 0) {
            return '';
        }
        if (total % 60 === 0) {
            return String(total / 60);
        }

        hours = Math.floor(total / 3600);
        minutes = Math.floor((total % 3600) / 60);
        rest = pad(total % 60);

        return hours > 0 ? hours + ':' + pad(minutes) + ':' + rest : minutes + ':' + rest;
    }

    root.PlugixaRecipeParser = {
        parseQuantity: parseQuantity,
        parseIngredientLine: parseIngredientLine,
        parseIngredientBlock: parseIngredientBlock,
        parseStepBlock: parseStepBlock,
        parseTimer: parseTimer,
        formatTimer: formatTimer,
        splitRest: splitRest,
        matchUnit: matchUnit
    };
})(typeof globalThis !== 'undefined' ? globalThis : this);
