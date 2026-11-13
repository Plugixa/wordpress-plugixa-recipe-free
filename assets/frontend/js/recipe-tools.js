/**
 * Plugixa Recipe - reader tools: scale the amounts, switch units, copy the list.
 *
 * One function owns what an ingredient line shows: render(). An amount on the page is
 *   (the stored number) x (the servings ratio), converted to the chosen system, rounded.
 * The servings adjuster in recipes.js used to write the amounts itself; while this script
 * is on the page it only reports the ratio (the `pxr:scale` event) so that scaling and
 * converting cannot overwrite each other.
 *
 * A line is converted only when the server said what its unit measures
 * (data-pxr-unit-type / -system / -factor, from the site's units table). Any other line
 * is scaled if it has a number and otherwise left exactly as written.
 *
 * Nothing here talks to the server, and no markup is built from a string.
 *
 * @package PlugixaRecipe
 */
(function () {
    'use strict';

    var config = window.plugixaRecipeTools || {};
    var unitNames = config.units || {};
    var words = config.i18n || {};

    /* ─── Rounding and choosing a unit ─────────────────────────── */

    // Millilitres or grams in one of each unit a line can be converted TO.
    var TARGETS = {
        volume: {
            metric: [['ml', 1], ['l', 1000]],
            us: [['tsp', 4.92892], ['tbsp', 14.7868], ['cup', 236.588]]
        },
        weight: {
            metric: [['g', 1], ['kg', 1000]],
            us: [['oz', 28.3495], ['lb', 453.592]]
        }
    };

    /**
     * The unit a base amount (ml or g) is said in: the largest one it fills.
     * 10 ml is 2 tsp, 30 ml is 2 tbsp, 120 ml is 1/2 cup, 1500 g is 1.5 kg.
     */
    function pickUnit(type, system, base) {
        var list = TARGETS[type][system];
        var floors = { tbsp: 14, cup: 59, l: 1000, kg: 1000, lb: 453 };
        var chosen = list[0];
        for (var i = 1; i < list.length; i++) {
            if (base >= floors[list[i][0]]) chosen = list[i];
        }
        return chosen;
    }

    /**
     * Round a converted amount to something a cook can measure.
     *
     * TODO(owner): this decides what "1 cup" becomes in metric and how honest a converted
     * number looks. The default below is deliberately plain:
     *   - ml and g:   to the nearest 5 from 25 up (237 ml -> 235), to the nearest 1 below
     *   - L and kg:   two decimals (1.25 kg)
     *   - tsp, tbsp, cup: the nearest 1/4 (and 1/8 below one)
     *   - oz:         the nearest 1/4;   lb: the nearest 1/4
     * Things to weigh: rounding 237 ml to 250 is what cookbooks print but is 5% more
     * liquid, which matters in baking; rounding to 240 is the "metric cup" some countries
     * use; and coarse rounding compounds with scaling (x3 of a rounded number).
     *
     * @param {number} value Amount in `unit`, unrounded.
     * @param {string} unit  ml | l | g | kg | tsp | tbsp | cup | oz | lb
     * @returns {number}
     */
    function roundAmount(value, unit) {
        if (unit === 'ml' || unit === 'g') {
            return value >= 25 ? Math.round(value / 5) * 5 : Math.max(1, Math.round(value));
        }
        if (unit === 'l' || unit === 'kg') {
            return Math.round(value * 100) / 100;
        }
        var step = value < 1 ? 8 : 4;
        return Math.max(1 / step, Math.round(value * step) / step);
    }

    var FRACTIONS = [[1 / 8, '1/8'], [1 / 4, '1/4'], [1 / 3, '1/3'], [3 / 8, '3/8'], [1 / 2, '1/2'],
        [5 / 8, '5/8'], [2 / 3, '2/3'], [3 / 4, '3/4'], [7 / 8, '7/8']];

    /** 1.5 -> "1 1/2" when the unit is said in fractions, "1.5" when it is not. */
    function formatNumber(value, asFraction) {
        if (!(value > 0)) return '';
        var whole = Math.floor(value + 0.005);
        var rest = value - whole;
        if (rest < 0.01) return String(whole);

        if (asFraction) {
            for (var i = 0; i < FRACTIONS.length; i++) {
                if (Math.abs(rest - FRACTIONS[i][0]) < 0.02) {
                    return whole > 0 ? whole + ' ' + FRACTIONS[i][1] : FRACTIONS[i][1];
                }
            }
        }
        return String(Math.round(value * 100) / 100);
    }

    /**
     * A scaled amount in the author's own unit, as something a cook can measure:
     * 7/6 of 200 g is 233 g, not "233 1/3 g"; 7/6 of 1 tbsp is 1 1/8, not 1.17.
     */
    function tidy(value, metric) {
        if (value >= 10) return String(Math.round(value));
        if (metric) return String(Math.round(value * 10) / 10);

        var whole = Math.floor(value);
        var rest = value - whole;
        var best = ['', 0];
        var distance = rest;
        FRACTIONS.concat([[1, '']]).forEach(function (fraction) {
            if (Math.abs(rest - fraction[0]) < distance) {
                distance = Math.abs(rest - fraction[0]);
                best = [fraction[1], fraction[0]];
            }
        });
        if (best[1] === 1) return String(whole + 1);
        if (best[0] === '') return whole > 0 ? String(whole) : FRACTIONS[0][1];
        return whole > 0 ? whole + ' ' + best[0] : best[0];
    }

    /* ─── One ingredient line ──────────────────────────────────── */

    function number(text) {
        var value = parseFloat(text);
        return isNaN(value) ? null : value;
    }

    function readLine(span) {
        var amountEl = span.querySelector('[data-pxr-ingredient-amount]');
        var unitEl = span.querySelector('[data-pxr-ingredient-unit]');
        return {
            amountEl: amountEl,
            unitEl: unitEl,
            amountText: amountEl ? amountEl.textContent.trim() : '',
            unitText: unitEl ? unitEl.textContent.trim() : '',
            value: number(span.getAttribute('data-pxr-amount-value')),
            max: number(span.getAttribute('data-pxr-amount-max')),
            type: span.getAttribute('data-pxr-unit-type') || '',
            system: span.getAttribute('data-pxr-unit-system') || '',
            factor: number(span.getAttribute('data-pxr-unit-factor'))
        };
    }

    /** What a line shows for a servings ratio and a unit system. Pure: no DOM. */
    function present(line, ratio, system) {
        // No number the server could read ("a handful"): the author's words stand.
        if (line.value === null) {
            return { amount: line.amountText, unit: line.unitText };
        }

        var convert = system !== 'original' && line.type && line.factor && line.system !== system;
        var low = line.value * ratio;
        var high = line.max !== null && line.max > line.value ? line.max * ratio : null;

        if (!convert) {
            // Untouched at 1x, so "1 1/2" stays the author's "1 1/2".
            if (ratio === 1) return { amount: line.amountText, unit: line.unitText };
            var metric = line.system === 'metric';
            return {
                amount: tidy(low, metric) + (high !== null ? '–' + tidy(high, metric) : ''),
                unit: line.unitText
            };
        }

        // Pick the unit from the larger end of a range so both ends share it.
        var target = pickUnit(line.type, system, (high !== null ? high : low) * line.factor);
        var name = target[0];
        var fraction = system === 'us';
        var lowOut = roundAmount(low * line.factor / target[1], name);
        var highOut = high !== null ? roundAmount(high * line.factor / target[1], name) : null;
        var plural = name === 'cup' && (highOut !== null ? highOut : lowOut) > 1 ? 'cups' : name;

        return {
            amount: formatNumber(lowOut, fraction) + (highOut !== null && highOut > lowOut ? '–' + formatNumber(highOut, fraction) : ''),
            unit: unitNames[plural] || plural
        };
    }

    /* ─── One recipe ───────────────────────────────────────────── */

    function initRecipe(root) {
        var toolbar = root.querySelector('[data-pxr-tools]');
        if (!toolbar) return;

        var lines = [];
        root.querySelectorAll('[data-pxr-amount]').forEach(function (span) {
            lines.push(readLine(span));
        });

        var adjuster = root.querySelector('[data-pxr-servings-adjuster]');
        var servingsInput = adjuster ? adjuster.querySelector('[data-pxr-servings-input]') : null;
        var originalServings = adjuster ? parseInt(adjuster.getAttribute('data-pxr-original-servings'), 10) : 0;

        var storeKey = 'pxr-recipe-tools-' + (root.getAttribute('data-pxr-recipe-id') || '');
        var state = { ratio: 1, system: 'original' };

        function save() {
            try {
                window.localStorage.setItem(storeKey, JSON.stringify(state));
            } catch (e) { /* private mode, storage full: the choice lasts for this visit */ }
        }

        function render() {
            lines.forEach(function (line) {
                var shown = present(line, state.ratio, state.system);
                if (line.amountEl) line.amountEl.textContent = shown.amount;
                if (line.unitEl) line.unitEl.textContent = shown.unit;
            });
            toolbar.querySelectorAll('[data-pxr-scale]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(Math.abs(parseFloat(button.getAttribute('data-pxr-scale')) - state.ratio) < 0.001));
            });
            toolbar.querySelectorAll('[data-pxr-system]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button.getAttribute('data-pxr-system') === state.system));
            });
        }

        function setRatio(ratio, fromAdjuster) {
            if (!(ratio > 0)) return;
            state.ratio = ratio;
            // Keep the servings box in step with a preset (3x of 4 servings is 12).
            if (!fromAdjuster && servingsInput && originalServings) {
                servingsInput.value = Math.max(1, Math.round(originalServings * ratio));
            }
            render();
            save();
        }

        // recipes.js reports the ratio instead of writing amounts while this attribute is set.
        root.setAttribute('data-pxr-tools-active', '');
        root.addEventListener('pxr:scale', function (event) {
            setRatio(event.detail && event.detail.ratio, true);
        });

        toolbar.querySelectorAll('[data-pxr-scale]').forEach(function (button) {
            button.addEventListener('click', function () {
                setRatio(parseFloat(button.getAttribute('data-pxr-scale')), false);
            });
        });

        toolbar.querySelectorAll('[data-pxr-system]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.system = button.getAttribute('data-pxr-system');
                render();
                save();
            });
        });

        var copyButton = toolbar.querySelector('[data-pxr-copy-ingredients]');
        if (copyButton) {
            copyButton.addEventListener('click', function () {
                copyText(listAsText(root), function (ok) {
                    var status = toolbar.querySelector('[data-pxr-tools-status]');
                    if (status) status.textContent = ok ? (words.copied || 'Copied') : (words.copyFailed || 'Could not copy');
                    window.setTimeout(function () { if (status) status.textContent = ''; }, 2500);
                });
            });
        }

        // What the reader chose last time on this recipe, on this device.
        try {
            var stored = JSON.parse(window.localStorage.getItem(storeKey) || 'null');
            if (stored && stored.ratio > 0 && stored.ratio <= 100) state.ratio = stored.ratio;
            if (stored && ['original', 'metric', 'us'].indexOf(stored.system) !== -1
                && toolbar.querySelector('[data-pxr-system="' + stored.system + '"]')) {
                state.system = stored.system;
            }
        } catch (e) { /* unreadable: start as written */ }

        if (state.ratio !== 1 && servingsInput && originalServings) {
            servingsInput.value = Math.max(1, Math.round(originalServings * state.ratio));
        }

        toolbar.hidden = false;
        render();
    }

    /** The list as plain text, as it reads on the page now (scaled, converted). */
    function listAsText(root) {
        var out = [];
        var list = root.querySelector('.pxr-custom-single__ingredients');
        if (!list) return '';

        list.querySelectorAll('[data-pxr-ingredient-group], .pxr-custom-single__ingredient-text').forEach(function (node) {
            var text = node.textContent.replace(/\s+/g, ' ').trim();
            if (!text) return;
            if (node.hasAttribute('data-pxr-ingredient-group')) {
                out.push('', text.toUpperCase());
            } else {
                out.push('- ' + text);
            }
        });

        return out.join('\n').trim();
    }

    function copyText(text, done) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(legacyCopy(text)); });
        } else {
            done(legacyCopy(text));
        }
    }

    /** For a page served without https, where the clipboard API does not exist. */
    function legacyCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(area);
        return ok;
    }

    /* ─── My notes ─────────────────────────────────────────────── */

    /**
     * A visitor's own notes on a recipe. They never leave the browser: there is no
     * account behind them, so nothing to log in to and nobody else to read them.
     */
    function initNotes(root) {
        var box = root.querySelector('[data-pxr-my-notes]');
        var area = box ? box.querySelector('[data-pxr-my-notes-text]') : null;
        var status = box ? box.querySelector('[data-pxr-my-notes-status]') : null;
        if (!box || !area) return;

        var key = 'pxr-recipe-note-' + (root.getAttribute('data-pxr-recipe-id') || '');
        var timer = null;

        try {
            area.value = window.localStorage.getItem(key) || '';
        } catch (e) {
            // Storage is blocked: a box that forgets what is typed is worse than no box.
            return;
        }

        area.addEventListener('input', function () {
            window.clearTimeout(timer);
            if (status) status.textContent = '';
            timer = window.setTimeout(function () {
                var ok = true;
                try {
                    if (area.value.trim() === '') {
                        window.localStorage.removeItem(key);
                    } else {
                        window.localStorage.setItem(key, area.value);
                    }
                } catch (e) { ok = false; }
                if (status) status.textContent = status.getAttribute(ok ? 'data-saved' : 'data-failed') || '';
            }, 500);
        });

        box.hidden = false;
    }

    function init() {
        document.querySelectorAll('.pxr-custom-single').forEach(function (root) {
            initRecipe(root);
            initNotes(root);
        });
    }

    // Exposed for the tests, which exercise the arithmetic without a page, and for the
    // print view (print.js), which writes its lines with readLine() + present().
    window.PlugixaRecipeTools = { present: present, readLine: readLine, roundAmount: roundAmount, pickUnit: pickUnit, formatNumber: formatNumber, tidy: tidy };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
