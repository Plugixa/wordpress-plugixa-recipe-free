/**
 * Plugixa Recipe - the shopping list.
 *
 * The list is the visitor's and lives in their browser (localStorage), so it works with
 * nobody logged in and on a page served from a cache. For a logged-in visitor it is ALSO
 * kept in their account, which is what carries it to their other devices:
 *
 *   - on load, the account's copy is fetched. If this browser holds changes the account
 *     has not seen (`dirty`), the two are merged and sent back; otherwise the account's
 *     copy replaces the local one.
 *   - after every change the whole list is sent (debounced). The page is the editor and
 *     the account holds its copy, so there are no per-line ids to keep in step.
 *
 * Two places use it: the list page ([data-pxr-shopping-list]) and the "Add to shopping
 * list" button on a recipe ([data-pxr-add-to-list]).
 *
 * No markup is built from a string.
 *
 * @package PlugixaRecipe
 */
(function () {
    'use strict';

    var config = window.plugixaRecipeAccount || {};
    var words = config.i18n || {};
    var account = window.PlugixaRecipeAccount || null;

    var KEY = 'pxr-shopping-list';
    var listeners = [];
    var state = { items: [], dirty: false };

    /* ─── Amounts ──────────────────────────────────────────────── */

    /** "2", "2.5", "1/2", "1 1/2" -> a number; anything else (a range, "a pinch") -> null. */
    function toNumber(text) {
        text = String(text || '').trim().replace(',', '.');
        var match;
        if (/^\d+(\.\d+)?$/.test(text)) return parseFloat(text);
        if ((match = text.match(/^(\d+)\s+(\d+)\/(\d+)$/))) return parseInt(match[1], 10) + parseInt(match[2], 10) / parseInt(match[3], 10);
        if ((match = text.match(/^(\d+)\/(\d+)$/))) return parseInt(match[1], 10) / parseInt(match[2], 10);
        return null;
    }

    var FRACTIONS = [[1 / 4, '1/4'], [1 / 3, '1/3'], [1 / 2, '1/2'], [2 / 3, '2/3'], [3 / 4, '3/4']];

    function fromNumber(value) {
        var whole = Math.floor(value + 0.001);
        var rest = value - whole;
        if (rest < 0.02) return String(whole);
        for (var i = 0; i < FRACTIONS.length; i++) {
            if (Math.abs(rest - FRACTIONS[i][0]) < 0.02) return (whole ? whole + ' ' : '') + FRACTIONS[i][1];
        }
        return String(Math.round(value * 100) / 100);
    }

    /* ─── Combining lines ──────────────────────────────────────── */

    /**
     * What makes two lines "the same thing to buy".
     *
     * TODO(owner): the default is strict - same name AND same unit, ignoring case and
     * surrounding spaces. So "200 g flour" and "1 cup flour" stay two lines (they cannot
     * be added up), and "Onion" and "onions" stay two lines as well. Things to weigh:
     *   - folding plurals ("onion" / "onions") reads better but also folds "peas" / "pea
     *     shoots"-style near misses if done by cutting an "s";
     *   - ignoring the unit merges names but leaves an amount nobody can read
     *     ("200 g + 1 cup flour"), which merge() below would then have to print.
     *
     * @param {{name:string, quantity:string, unit:string}} item
     * @returns {string}
     */
    function mergeKey(item) {
        return String(item.name || '').trim().toLowerCase() + '|' + String(item.unit || '').trim().toLowerCase();
    }

    /**
     * One line out of two that share a mergeKey().
     *
     * TODO(owner): the default adds the amounts when both are plain numbers ("200" + "150"
     * -> "350"), and otherwise keeps both, written out ("1-2 + 3"), because a wrong total
     * on a shopping list is worse than an awkward one. A line with no amount ("salt")
     * merged with one that has an amount keeps the amount. The merged line is unticked
     * unless both were ticked: something new was added to it.
     *
     * @param {{name:string, quantity:string, unit:string, checked:boolean}} a Already on the list.
     * @param {{name:string, quantity:string, unit:string, checked:boolean}} b Being added.
     * @returns {{name:string, quantity:string, unit:string, checked:boolean}}
     */
    function merge(a, b) {
        var first = toNumber(a.quantity);
        var second = toNumber(b.quantity);
        var quantity;

        if (first !== null && second !== null) {
            quantity = fromNumber(first + second);
        } else {
            quantity = [a.quantity, b.quantity].filter(function (part) { return String(part || '').trim() !== ''; }).join(' + ');
        }

        return { name: a.name, quantity: quantity, unit: a.unit, checked: !!(a.checked && b.checked) };
    }

    function clean(item) {
        return {
            name: String(item && item.name || '').trim().slice(0, 500),
            quantity: String(item && item.quantity || '').trim().slice(0, 50),
            unit: String(item && item.unit || '').trim().slice(0, 50),
            checked: !!(item && item.checked)
        };
    }

    /** `extra` folded into `base`: merged where the key matches, appended where it does not. */
    function combine(base, extra) {
        var out = base.map(clean);
        extra.map(clean).forEach(function (item) {
            if (!item.name) return;
            var key = mergeKey(item);
            for (var i = 0; i < out.length; i++) {
                if (mergeKey(out[i]) === key) {
                    out[i] = clean(merge(out[i], item));
                    return;
                }
            }
            out.push(item);
        });
        return out;
    }

    /* ─── The store ────────────────────────────────────────────── */

    function load() {
        try {
            var stored = JSON.parse(window.localStorage.getItem(KEY) || 'null');
            if (stored && Array.isArray(stored.items)) {
                state = { items: stored.items.map(clean).filter(function (item) { return item.name; }), dirty: !!stored.dirty };
            }
        } catch (e) { /* unreadable or blocked: an empty list for this visit */ }
    }

    function persist() {
        try { window.localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* this visit only */ }
    }

    function changed() {
        listeners.forEach(function (listener) { listener(state.items); });
    }

    var syncing = null;
    var canSync = !!(account && config.serverLists);
    var said = function () {};

    function push() {
        if (!canSync) return;
        window.clearTimeout(syncing);
        syncing = window.setTimeout(function () {
            account.api('frontend/shopping-list', { method: 'PUT', body: { items: state.items } }).then(function () {
                state.dirty = false;
                persist();
                said(words.synced || 'Saved to your account.');
            }, function (reason) {
                // Not logged in, or the Shopping Lists app is off: the list stays local.
                if (reason && (reason.status === 401 || reason.status === 404)) canSync = false;
                said(words.local || 'Kept on this device.');
            });
        }, 800);
    }

    function set(items) {
        state.items = items.map(clean).filter(function (item) { return item.name; });
        state.dirty = true;
        persist();
        changed();
        push();
    }

    var pulled = null;

    /** Bring the account's copy and this browser's together, once per page. */
    function pull() {
        if (pulled) return pulled;
        if (!canSync) return (pulled = Promise.resolve(false));

        return (pulled = account.api('frontend/shopping-list').then(function (answer) {
            var remote = (answer && answer.data ? answer.data : []).map(clean);

            if (state.dirty && state.items.length) {
                state.items = combine(remote, state.items);
                persist();
                changed();
                push();
            } else {
                state.items = remote;
                state.dirty = false;
                persist();
                changed();
            }
            return true;
        }, function (reason) {
            if (reason && (reason.status === 401 || reason.status === 404)) canSync = false;
            return false;
        }));
    }

    /* ─── The list page ────────────────────────────────────────── */

    function lineText(item) {
        return [item.quantity, item.unit, item.name].filter(Boolean).join(' ');
    }

    function initPage(root) {
        var list = root.querySelector('[data-pxr-shopping-items]');
        var template = root.querySelector('template[data-pxr-shopping-template]');
        var empty = root.querySelector('[data-pxr-shopping-empty]');
        var actions = root.querySelector('[data-pxr-shopping-actions]');
        var status = root.querySelector('[data-pxr-shopping-status]');
        var signedOut = root.querySelector('[data-pxr-shopping-signed-out]');
        var form = root.querySelector('[data-pxr-shopping-form]');

        said = function (text) { if (status) status.textContent = text; };

        function draw(items) {
            while (list.firstChild) list.removeChild(list.firstChild);

            items.forEach(function (item, index) {
                var row = template.content.firstElementChild.cloneNode(true);
                var check = row.querySelector('[data-pxr-shopping-check]');
                var remove = row.querySelector('[data-pxr-shopping-remove]');

                row.querySelector('[data-pxr-shopping-text]').textContent = lineText(item);
                row.classList.toggle('pxr-shopping__item--done', item.checked);
                check.checked = item.checked;
                check.addEventListener('change', function () {
                    var next = state.items.slice();
                    next[index] = clean({ name: item.name, quantity: item.quantity, unit: item.unit, checked: check.checked });
                    set(next);
                });
                remove.setAttribute('aria-label', (words.remove || 'Remove %s').replace('%s', item.name));
                remove.addEventListener('click', function () {
                    set(state.items.filter(function (other, at) { return at !== index; }));
                });

                list.appendChild(row);
            });

            empty.hidden = items.length > 0;
            actions.hidden = items.length === 0;
        }

        listeners.push(draw);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var name = form.elements.name.value.trim();
            if (!name) return;
            set(combine(state.items, [{ name: name, quantity: form.elements.quantity.value, unit: '', checked: false }]));
            form.reset();
            form.elements.name.focus();
        });

        root.querySelector('[data-pxr-shopping-clear-checked]').addEventListener('click', function () {
            set(state.items.filter(function (item) { return !item.checked; }));
        });
        root.querySelector('[data-pxr-shopping-clear]').addEventListener('click', function () {
            // window.confirm is the one prompt a visitor already knows for "this cannot be undone".
            if (window.confirm(words.confirm || 'Clear the whole list?')) set([]);
        });
        root.querySelector('[data-pxr-shopping-print]').addEventListener('click', function () { window.print(); });
        root.querySelector('[data-pxr-shopping-copy]').addEventListener('click', function () {
            var text = state.items.map(function (item) { return (item.checked ? '[x] ' : '[ ] ') + lineText(item); }).join('\n');
            var done = function () { said(words.copied || 'Copied'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            }
        });

        root.hidden = false;
        draw(state.items);

        if (account) {
            account.whoami().then(function (me) {
                if (signedOut) signedOut.hidden = me.loggedIn;
            });
        }
    }

    /* ─── "Add to shopping list" on a recipe ───────────────────── */

    function initAddButton(bar) {
        var recipe = bar.closest('.pxr-custom-single');
        var button = bar.querySelector('[data-pxr-add-to-list-button]');
        var status = bar.querySelector('[data-pxr-add-to-list-status]');
        var link = bar.querySelector('[data-pxr-add-to-list-link]');
        if (!recipe || !button) return;

        button.addEventListener('click', function () {
            var lines = [];

            // As the page shows them now (scaled, converted); ticked lines are "I have this".
            recipe.querySelectorAll('.pxr-custom-single__ingredient').forEach(function (row) {
                var check = row.querySelector('[data-pxr-ingredient-check]');
                var text = row.querySelector('[data-pxr-ingredient-name]');
                if (!text || (check && check.checked)) return;

                var amount = text.querySelector('[data-pxr-ingredient-amount]');
                var unit = text.querySelector('[data-pxr-ingredient-unit]');
                lines.push({
                    name: text.getAttribute('data-pxr-ingredient-name'),
                    quantity: amount ? amount.textContent.trim() : '',
                    unit: unit ? unit.textContent.trim() : '',
                    checked: false
                });
            });

            if (!lines.length) {
                status.textContent = words.nothing || 'There is nothing left to add.';
                return;
            }

            // The account's copy first, so the lines are added to the list as it really is.
            button.disabled = true;
            pull().then(function () {
                button.disabled = false;
                set(combine(state.items, lines));
                status.textContent = lines.length === 1
                    ? (words.addedOne || '1 item added to your shopping list.')
                    : (words.added || '%d items added to your shopping list.').replace('%d', String(lines.length));

                if (link && config.listUrl) {
                    link.href = config.listUrl;
                    link.textContent = words.viewList || 'View list';
                    link.hidden = false;
                }
            });
        });

        bar.hidden = false;
    }

    function init() {
        load();
        document.querySelectorAll('[data-pxr-shopping-list]').forEach(initPage);
        document.querySelectorAll('[data-pxr-add-to-list]').forEach(initAddButton);

        // Another tab changed the list: show it here too.
        window.addEventListener('storage', function (event) {
            if (event.key !== KEY) return;
            load();
            changed();
        });

        // The list page needs the account's copy at once. A recipe page asks only when
        // the button is pressed: asking on every recipe view would put one uncached
        // request behind every cached page.
        if (document.querySelector('[data-pxr-shopping-list]')) pull();
    }

    // Exposed for the tests, which exercise the arithmetic without a page.
    window.PlugixaRecipeShoppingList = { mergeKey: mergeKey, merge: merge, combine: combine, toNumber: toNumber };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
