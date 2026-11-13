/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Print View Script
 *
 * Loaded only on the /recipe-print/{id}/ standalone document, enqueued as the
 * handle "plugixa-recipe-print" by PlugixaRecipe\Frontend\RecipePrint.
 */

(function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('auto') === '1') {
        window.addEventListener('load', function() {
            window.print();
        });
    }
})();
