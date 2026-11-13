/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Print View Script
 *
 * Loaded only on the /recipe-print/{id}/ standalone document, enqueued as the
 * handle "plugixa-recipe-print" by Modules\Recipes\Frontend\PrintPage.
 *
 * Three jobs:
 *   1. Print what the reader was looking at. The recipe page's reader tools keep the
 *      chosen scale and unit system in localStorage; when their script is on this
 *      document too (Reader Tools switched on), each ingredient line is written by the
 *      SAME function that writes it on the page. Without it the list stays as written.
 *   2. Let the reader leave out images, notes and tips, or nutrition.
 *   3. Open the print dialog (the button, or at once with ?auto=1).
 *
 * Nothing here talks to the server, and no markup is built from a string.
 */

(function () {
    'use strict';

    var root = document.querySelector('[data-pxr-print]');
    if (!root) return;

    function readStore(key) {
        try {
            return JSON.parse(window.localStorage.getItem(key) || 'null');
        } catch (e) {
            // Storage blocked or the value unreadable: print the recipe as written.
            return null;
        }
    }

    /* ─── The reader's scale and units ─────────────────────────── */

    function applyReaderChoice() {
        var tools = window.PlugixaRecipeTools;
        if (!tools || !tools.present || !tools.readLine) return;

        // The same key, and the same limits, as the reader tools' script.
        var stored = readStore('pxr-recipe-tools-' + (root.getAttribute('data-pxr-recipe-id') || ''));
        if (!stored) return;

        var ratio = stored.ratio > 0 && stored.ratio <= 100 ? stored.ratio : 1;
        var system = ['original', 'metric', 'us'].indexOf(stored.system) !== -1 ? stored.system : 'original';
        if (ratio === 1 && system === 'original') return;

        root.querySelectorAll('[data-pxr-amount]').forEach(function (span) {
            var line = tools.readLine(span);
            var shown = tools.present(line, ratio, system);
            if (line.amountEl) line.amountEl.textContent = shown.amount;
            if (line.unitEl) line.unitEl.textContent = shown.unit;
        });

        if (ratio !== 1) sayScaled(ratio);
    }

    /** A sheet of paper cannot show a control's state: it has to say the amounts changed. */
    function sayScaled(ratio) {
        var note = root.querySelector('[data-pxr-print-scaled]');
        var servings = parseInt(root.getAttribute('data-pxr-servings'), 10) || 0;
        var scaled = Math.max(1, Math.round(servings * ratio));

        var servingsEl = root.querySelector('[data-pxr-print-servings]');
        if (servings && servingsEl) servingsEl.textContent = String(scaled);

        if (!note) return;
        var text = servings
            ? (note.getAttribute('data-servings-text') || '').replace('{n}', String(scaled))
            : (note.getAttribute('data-ratio-text') || '').replace('{n}', String(Math.round(ratio * 100) / 100));
        if (!text) return;

        note.textContent = text;
        note.hidden = false;
    }

    /* ─── What to leave out ────────────────────────────────────── */

    var OPTIONS_KEY = 'pxr-print-options';

    /**
     * What a reader who has never chosen gets.
     *
     * TODO(owner): this decides what the first print of every visitor looks like, and
     * `?auto=1` opens the dialog before they can change it. Everything on is the page as
     * the author made it; `images: false` saves ink and often a whole sheet, which is why
     * some recipe sites default to it. The choice is remembered across recipes on this
     * device, so the default only ever applies once per reader.
     */
    var DEFAULTS = { images: true, notes: true, nutrition: true };

    function initOptions() {
        var box = root.querySelector('[data-pxr-print-options]');
        if (!box) return;

        var stored = readStore(OPTIONS_KEY) || {};
        var state = {};
        Object.keys(DEFAULTS).forEach(function (name) {
            state[name] = typeof stored[name] === 'boolean' ? stored[name] : DEFAULTS[name];
        });

        function apply() {
            root.querySelectorAll('[data-pxr-print-part]').forEach(function (part) {
                part.hidden = state[part.getAttribute('data-pxr-print-part')] === false;
            });
        }

        box.querySelectorAll('[data-pxr-print-option]').forEach(function (input) {
            var name = input.getAttribute('data-pxr-print-option');
            input.checked = state[name] !== false;
            input.addEventListener('change', function () {
                state[name] = input.checked;
                apply();
                try {
                    window.localStorage.setItem(OPTIONS_KEY, JSON.stringify(state));
                } catch (e) { /* private mode, storage full: the choice lasts for this sheet */ }
            });
        });

        apply();
        box.hidden = false;
    }

    /* ─── Printing ─────────────────────────────────────────────── */

    applyReaderChoice();
    initOptions();

    var button = root.querySelector('[data-pxr-print-now]');
    if (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    }

    var params = new URLSearchParams(window.location.search);
    if (params.get('auto') === '1') {
        window.addEventListener('load', function () {
            window.print();
        });
    }
})();
