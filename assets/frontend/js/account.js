/**
 * Plugixa Recipe - the visitor's account pages: saved recipes, submitted recipes, tabs.
 *
 * The page is the same for every visitor (a page cache serves one copy), so nothing on it
 * says who is looking. This script asks: `whoami()` calls admin-ajax, which knows the
 * visitor by their cookie, and answers with a REST nonce when they are logged in. Every
 * personal request then goes to REST with that nonce.
 *
 * Rows are drawn by cloning the <template> the page prints; no markup is built from a
 * string.
 *
 * @package PlugixaRecipe
 */
(function () {
    'use strict';

    var config = window.plugixaRecipeAccount || {};
    var words = config.i18n || {};
    var asked = null;

    /** Who is looking. One request per page, shared by everything that needs it. */
    function whoami() {
        if (asked) return asked;

        var url = String(config.ajaxUrl || '');
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'action=plugixa_recipe_whoami';

        asked = fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                var data = answer && answer.success && answer.data ? answer.data : {};
                return { loggedIn: !!data.loggedIn, nonce: data.restNonce || '' };
            })
            .catch(function () { return { loggedIn: false, nonce: '' }; });

        return asked;
    }

    /**
     * A REST request as the logged-in visitor. Rejects with { status } when the server
     * says no, so each caller can tell "not logged in" from "that route does not exist".
     */
    function api(path, options) {
        options = options || {};

        return whoami().then(function (me) {
            if (!me.loggedIn) return Promise.reject({ status: 401 });

            var headers = { 'X-WP-Nonce': me.nonce };
            if (options.body !== undefined) headers['Content-Type'] = 'application/json';

            return fetch(String(config.restUrl || '') + path, {
                method: options.method || 'GET',
                headers: headers,
                body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
                credentials: 'same-origin',
                cache: 'no-store'
            });
        }).then(function (response) {
            if (!response.ok) return Promise.reject({ status: response.status });
            return response.json();
        });
    }

    /* ─── A list of the visitor's rows ─────────────────────────── */

    function show(root, part) {
        ['loading', 'signed-out', 'empty', 'error'].forEach(function (name) {
            var node = root.querySelector('[data-pxr-account-' + name + ']');
            if (node) node.hidden = name !== part;
        });
    }

    function fail(root) {
        var node = root.querySelector('[data-pxr-account-error]');
        if (node) node.textContent = words.error || 'Something went wrong. Please reload the page.';
        show(root, 'error');
    }

    function fillLinks(row, item) {
        row.querySelectorAll('[data-pxr-card-link]').forEach(function (link) {
            if (item.url) {
                link.href = item.url;
            } else {
                link.hidden = true;
            }
        });
        row.querySelectorAll('[data-pxr-card-title]').forEach(function (node) { node.textContent = item.title; });

        var image = row.querySelector('[data-pxr-card-image]');
        if (image) {
            if (item.image) {
                image.src = item.image;
            } else {
                image.parentNode.hidden = true;
            }
        }
    }

    function initSaved(root) {
        var list = root.querySelector('[data-pxr-account-list]');
        var template = root.querySelector('template[data-pxr-account-template]');
        var live = root.querySelector('[data-pxr-account-live]');

        api('frontend/account/saved').then(function (answer) {
            var items = answer && answer.data ? answer.data : [];
            if (!items.length) { show(root, 'empty'); return; }

            items.forEach(function (item) {
                var row = template.content.firstElementChild.cloneNode(true);
                fillLinks(row, item);

                var meta = [item.time, item.difficulty, item.rating ? '★ ' + item.rating : ''].filter(Boolean);
                row.querySelector('[data-pxr-card-meta]').textContent = meta.join(' · ');

                var remove = row.querySelector('[data-pxr-card-remove]');
                remove.setAttribute('aria-label', (words.remove || 'Remove %s').replace('%s', item.title));
                remove.addEventListener('click', function () {
                    remove.disabled = true;
                    // The same toggle the Save button on a recipe uses.
                    api('frontend/favorites/' + item.id, { method: 'POST' }).then(function () {
                        list.removeChild(row);
                        if (live) live.textContent = words.removed || 'Removed from your saved recipes.';
                        if (!list.children.length) show(root, 'empty');
                    }, function () {
                        remove.disabled = false;
                        fail(root);
                    });
                });

                list.appendChild(row);
            });
            show(root, '');
        }, function (reason) {
            if (reason && reason.status === 401) { show(root, 'signed-out'); return; }
            fail(root);
        });
    }

    function initSubmissions(root) {
        var list = root.querySelector('[data-pxr-account-list]');
        var template = root.querySelector('template[data-pxr-account-template]');

        api('frontend/account/submissions').then(function (answer) {
            var items = answer && answer.data ? answer.data : [];
            if (!items.length) { show(root, 'empty'); return; }

            items.forEach(function (item) {
                var row = template.content.firstElementChild.cloneNode(true);
                fillLinks(row, item);

                // A recipe that is not live has no page: its title is text, not a link.
                var plain = row.querySelector('[data-pxr-card-plain-title]');
                if (item.url) {
                    plain.hidden = true;
                } else {
                    plain.textContent = item.title;
                }

                row.querySelector('[data-pxr-card-meta]').textContent = item.date || '';
                var status = row.querySelector('[data-pxr-card-status]');
                status.textContent = item.status;
                status.classList.toggle('pxr-account__status--live', !!item.live);

                list.appendChild(row);
            });
            show(root, '');
        }, function (reason) {
            if (reason && reason.status === 401) { show(root, 'signed-out'); return; }
            fail(root);
        });
    }

    /* ─── Tabs ─────────────────────────────────────────────────── */

    function initTabs(root) {
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));

        function select(tab, focus) {
            tabs.forEach(function (other) {
                var on = other === tab;
                other.setAttribute('aria-selected', String(on));
                other.tabIndex = on ? 0 : -1;
                var panel = document.getElementById(other.getAttribute('aria-controls'));
                if (panel) panel.hidden = !on;
            });
            if (focus) tab.focus();
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { select(tab, false); });
            tab.addEventListener('keydown', function (event) {
                var to = null;
                if (event.key === 'ArrowRight') to = tabs[(index + 1) % tabs.length];
                if (event.key === 'ArrowLeft') to = tabs[(index - 1 + tabs.length) % tabs.length];
                if (event.key === 'Home') to = tabs[0];
                if (event.key === 'End') to = tabs[tabs.length - 1];
                if (to) { event.preventDefault(); select(to, true); }
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-pxr-account="saved"]').forEach(initSaved);
        document.querySelectorAll('[data-pxr-account="submissions"]').forEach(initSubmissions);
        document.querySelectorAll('[data-pxr-account-tabs]').forEach(initTabs);
    }

    window.PlugixaRecipeAccount = { whoami: whoami, api: api };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
