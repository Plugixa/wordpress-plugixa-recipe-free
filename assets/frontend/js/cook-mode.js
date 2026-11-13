/**
 * Plugixa Recipe - cook mode: the method one step at a time, with the screen kept on.
 *
 * The dialog shows a COPY of a step as the page drew it (text, photo, tip, time), so there
 * is one place a step is written and cook mode cannot say something the page does not.
 * Left / Right move between steps, Escape leaves, and the step reached is remembered per
 * recipe on this device.
 *
 * The screen is kept on with the Screen Wake Lock API where the browser has it. A lock is
 * dropped whenever the tab is hidden, so it is asked for again when the tab comes back.
 * Where the API is missing the dialog says so plainly instead of pretending.
 *
 * Nothing here talks to the server, and no markup is built from a string.
 *
 * @package PlugixaRecipe
 */
(function () {
    'use strict';

    var words = (window.plugixaRecipeTools || {}).i18n || {};

    function initRecipe(root) {
        var dialog = root.querySelector('dialog[data-pxr-cook]');
        var bar = root.querySelector('[data-pxr-method-tools]');
        var steps = Array.prototype.slice.call(root.querySelectorAll('[data-pxr-step]'));
        if (!dialog || !bar || !steps.length || typeof dialog.showModal !== 'function') return;

        var openButton = bar.querySelector('[data-pxr-cook-open]');
        var stepBox = dialog.querySelector('[data-pxr-cook-step]');
        var groupBox = dialog.querySelector('[data-pxr-cook-group]');
        var countBox = dialog.querySelector('[data-pxr-cook-count]');
        var progress = dialog.querySelector('[data-pxr-cook-progress]');
        var progressBar = dialog.querySelector('[data-pxr-cook-progress-bar]');
        var prev = dialog.querySelector('[data-pxr-cook-prev]');
        var next = dialog.querySelector('[data-pxr-cook-next]');
        var awake = dialog.querySelector('[data-pxr-cook-awake]');
        var asleep = dialog.querySelector('[data-pxr-cook-asleep]');
        var dock = root.querySelector('[data-pxr-timer-dock]');
        var dockHome = dock ? dock.parentNode : null;
        var dockNext = dock ? dock.nextSibling : null;

        var storeKey = 'pxr-recipe-cook-' + (root.getAttribute('data-pxr-recipe-id') || '');
        var index = 0;
        var lock = null;

        /** The heading a step sits under, if its group has one. */
        function groupOf(step) {
            var list = step.parentNode;
            var before = list ? list.previousElementSibling : null;
            return before && before.hasAttribute('data-pxr-step-group') ? before.textContent.trim() : '';
        }

        function show(to) {
            index = Math.max(0, Math.min(steps.length - 1, to));
            var step = steps[index];
            var source = step.querySelector('.pxr-custom-single__step-content') || step;

            while (stepBox.firstChild) stepBox.removeChild(stepBox.firstChild);
            var copy = source.cloneNode(true);
            // A clone carries no listeners: give its timer buttons theirs back.
            var timers = window.PlugixaRecipeTimers ? window.PlugixaRecipeTimers.of(step) : null;
            copy.querySelectorAll('[data-pxr-timer-start]').forEach(function (button) {
                if (!timers) { button.disabled = true; return; }
                button.addEventListener('click', function () {
                    timers.start(
                        (words.step || 'Step %d').replace('%d', String(index + 1)),
                        parseInt(button.getAttribute('data-pxr-timer-start'), 10)
                    );
                });
            });
            // Ids must stay unique in the document.
            copy.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); });
            stepBox.appendChild(copy);

            var group = groupOf(step);
            groupBox.textContent = group;
            groupBox.hidden = group === '';

            countBox.textContent = (words.stepOf || 'Step %1$d of %2$d')
                .replace('%1$d', String(index + 1)).replace('%2$d', String(steps.length));

            var done = Math.round(((index + 1) / steps.length) * 100);
            progress.setAttribute('aria-valuenow', String(done));
            progressBar.style.width = done + '%';

            prev.disabled = index === 0;
            next.textContent = index === steps.length - 1 ? (words.finish || 'Finish') : (words.next || 'Next');

            try { window.localStorage.setItem(storeKey, String(index)); } catch (e) { /* this visit only */ }
            stepBox.scrollTop = 0;
        }

        function keepAwake() {
            if (!('wakeLock' in navigator)) {
                asleep.hidden = false;
                return;
            }
            navigator.wakeLock.request('screen').then(function (sentinel) {
                lock = sentinel;
                awake.hidden = false;
                asleep.hidden = true;
            }, function () {
                // Refused (battery saver, permissions policy): say so rather than promise.
                awake.hidden = true;
                asleep.hidden = false;
            });
        }

        function letSleep() {
            if (lock) {
                lock.release().catch(function () {});
                lock = null;
            }
            awake.hidden = true;
            asleep.hidden = true;
        }

        function open() {
            var stored = 0;
            try { stored = parseInt(window.localStorage.getItem(storeKey), 10) || 0; } catch (e) { stored = 0; }

            // A modal dialog makes the rest of the page inert, running timers included.
            if (dock) dialog.querySelector('.pxr-cook__inner').appendChild(dock);

            show(stored < steps.length ? stored : 0);
            dialog.showModal();
            stepBox.focus();
            keepAwake();
        }

        function close() {
            if (dialog.open) dialog.close();
        }

        dialog.addEventListener('close', function () {
            letSleep();
            if (dock && dockHome) dockHome.insertBefore(dock, dockNext);
            if (openButton) openButton.focus();
        });

        // The browser drops a wake lock when the tab is hidden; take it again on return.
        document.addEventListener('visibilitychange', function () {
            if (dialog.open && document.visibilityState === 'visible') keepAwake();
        });

        openButton.addEventListener('click', open);
        dialog.querySelector('[data-pxr-cook-close]').addEventListener('click', close);
        prev.addEventListener('click', function () { show(index - 1); stepBox.focus(); });
        next.addEventListener('click', function () {
            if (index === steps.length - 1) {
                // Finished: the next visit starts from the top.
                try { window.localStorage.removeItem(storeKey); } catch (e) { /* nothing stored */ }
                close();
                return;
            }
            show(index + 1);
            stepBox.focus();
        });

        dialog.addEventListener('keydown', function (event) {
            if (event.target.closest('input, textarea, select')) return;
            if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
            if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
        });

        // A click on the dimmed area around the dialog leaves, like Escape.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) close();
        });

        bar.hidden = false;
    }

    function init() {
        document.querySelectorAll('.pxr-custom-single').forEach(initRecipe);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
