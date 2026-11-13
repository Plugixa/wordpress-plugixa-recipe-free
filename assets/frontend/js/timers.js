/**
 * Plugixa Recipe - step timers.
 *
 * A step's time chip (data-pxr-timer-seconds, printed by the page) becomes a button, and
 * so does a time written in a step's text ("simmer for 20 minutes") when the step has no
 * chip of its own. Running timers sit in a dock at the bottom of the screen; several can
 * run at once, each counting towards its own end time, so a throttled background tab
 * comes back showing the right remainder instead of a slow one.
 *
 * When one ends: a short tone, a vibration where the device has one, and the same words
 * in a live region for a screen reader.
 *
 * Nothing here talks to the server, and no markup is built from a string: rows are cloned
 * from the <template> the page prints.
 *
 * @package PlugixaRecipe
 */
(function () {
    'use strict';

    var words = (window.plugixaRecipeTools || {}).i18n || {};

    function say(key, fallback, value) {
        return (words[key] || fallback).replace(/%(\d\$)?[sd]/, value);
    }

    function clock(seconds) {
        seconds = Math.max(0, Math.ceil(seconds));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var mm = (h && m < 10 ? '0' : '') + m;
        return (h ? h + ':' : '') + mm + ':' + (s < 10 ? '0' : '') + s;
    }

    /* ─── Times written in a step's text ───────────────────────── */

    // "20 minutes", "1 hour", "30-40 mins", "1 1/2 hours", "45 sec". English words: a
    // recipe in another language keeps its chips, which do not depend on this.
    var DURATION = /(\d+(?:[.,]\d+)?(?:\s+\d\/\d)?)(?:\s*(?:-|–|to)\s*(\d+(?:[.,]\d+)?))?\s*(seconds?|secs?|minutes?|mins?|hours?|hrs?)\b/gi;

    function toNumber(text) {
        var parts = String(text).replace(',', '.').trim().split(/\s+/);
        var value = parseFloat(parts[0]);
        if (parts[1]) {
            var fraction = parts[1].split('/');
            value += parseInt(fraction[0], 10) / parseInt(fraction[1], 10);
        }
        return value;
    }

    /**
     * How long a timer a written time deserves, in seconds - or null for "no button".
     *
     * TODO(owner): three judgement calls, each visible to every reader of every recipe:
     *   1. A range ("30-40 minutes"): the default takes the LOW end, so the cook is called
     *      back to look rather than called back to something burnt. The high end, or the
     *      middle, are both defensible.
     *   2. What is too short to be worth a button. Default: under 30 seconds.
     *   3. What is too long. Default: over 12 hours ("marinate for 2 days" is not a
     *      kitchen timer; a phone will not keep a tab alive that long anyway).
     *
     * @param {{low:number, high:(number|null), unit:string}} match low/high in `unit`
     *        (second | minute | hour).
     * @returns {number|null}
     */
    function pickDuration(match) {
        var each = { second: 1, minute: 60, hour: 3600 }[match.unit];
        var seconds = Math.round(match.low * each);

        if (seconds < 30 || seconds > 12 * 3600) return null;
        return seconds;
    }

    function unitOf(word) {
        word = word.toLowerCase();
        if (word.charAt(0) === 's') return 'second';
        if (word.charAt(0) === 'm') return 'minute';
        return 'hour';
    }

    /** Wrap each written time in a step's text in a button. Text nodes only. */
    function linkWrittenTimes(textRoot, label, start) {
        var walker = document.createTreeWalker(textRoot, NodeFilter.SHOW_TEXT);
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);

        nodes.forEach(function (node) {
            if (node.parentNode.closest('a, button')) return;

            var text = node.nodeValue;
            var pieces = [];
            var last = 0;
            var found;
            DURATION.lastIndex = 0;

            while ((found = DURATION.exec(text)) !== null) {
                var seconds = pickDuration({
                    low: toNumber(found[1]),
                    high: found[2] ? toNumber(found[2]) : null,
                    unit: unitOf(found[3])
                });
                if (seconds === null) continue;

                pieces.push(document.createTextNode(text.slice(last, found.index)));
                pieces.push(timerButton(found[0], seconds, label, start, 'pxr-timer-link'));
                last = found.index + found[0].length;
            }

            if (!pieces.length) return;
            pieces.push(document.createTextNode(text.slice(last)));
            pieces.forEach(function (piece) { node.parentNode.insertBefore(piece, node); });
            node.parentNode.removeChild(node);
        });
    }

    function timerButton(text, seconds, label, start, className) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = text;
        button.setAttribute('data-pxr-timer-start', String(seconds));
        button.setAttribute('aria-label', say('startTimer', 'Start a %s timer', text));
        button.addEventListener('click', function () { start(label, seconds); });
        return button;
    }

    /* ─── The dock ─────────────────────────────────────────────── */

    function initRecipe(root) {
        var dock = root.querySelector('[data-pxr-timer-dock]');
        var list = root.querySelector('[data-pxr-timer-list]');
        var template = root.querySelector('template[data-pxr-timer-template]');
        var live = root.querySelector('[data-pxr-timer-live]');
        if (!dock || !list || !template) return null;

        var timers = [];
        var ticking = null;
        var audio = null;

        function tone() {
            try {
                var Context = window.AudioContext || window.webkitAudioContext;
                if (!Context) return;
                audio = audio || new Context();
                [0, 0.35, 0.7].forEach(function (offset) {
                    var oscillator = audio.createOscillator();
                    var gain = audio.createGain();
                    oscillator.frequency.value = 880;
                    gain.gain.setValueAtTime(0.0001, audio.currentTime + offset);
                    gain.gain.exponentialRampToValueAtTime(0.3, audio.currentTime + offset + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + offset + 0.25);
                    oscillator.connect(gain);
                    gain.connect(audio.destination);
                    oscillator.start(audio.currentTime + offset);
                    oscillator.stop(audio.currentTime + offset + 0.3);
                });
            } catch (e) { /* no sound: the dock and the live region still say it */ }
        }

        function remaining(timer) {
            return timer.paused ? timer.left : (timer.endsAt - Date.now()) / 1000;
        }

        function draw(timer) {
            var left = remaining(timer);
            timer.time.textContent = clock(left);
            timer.toggle.textContent = timer.paused ? (words.resume || 'Resume') : (words.pause || 'Pause');
            timer.toggle.hidden = timer.done;
            timer.row.classList.toggle('pxr-timer--done', timer.done);
        }

        function tick() {
            timers.forEach(function (timer) {
                if (!timer.done && !timer.paused && remaining(timer) <= 0) {
                    timer.done = true;
                    tone();
                    if (navigator.vibrate) navigator.vibrate([300, 150, 300]);
                    if (live) live.textContent = say('timerDone', '%s: time is up', timer.label);
                }
                draw(timer);
            });

            if (!timers.some(function (timer) { return !timer.done && !timer.paused; })) {
                window.clearInterval(ticking);
                ticking = null;
            }
        }

        function run() {
            if (ticking === null) ticking = window.setInterval(tick, 250);
        }

        function remove(timer) {
            timers.splice(timers.indexOf(timer), 1);
            list.removeChild(timer.row);
            dock.hidden = timers.length === 0;
        }

        function start(label, seconds) {
            // The click that starts a timer is what lets the browser play its tone later.
            try {
                var Context = window.AudioContext || window.webkitAudioContext;
                if (Context) {
                    audio = audio || new Context();
                    if (audio.state === 'suspended') audio.resume();
                }
            } catch (e) { /* silent timer */ }

            var row = template.content.firstElementChild.cloneNode(true);
            var timer = {
                label: label,
                row: row,
                time: row.querySelector('[data-pxr-timer-time]'),
                toggle: row.querySelector('[data-pxr-timer-toggle]'),
                endsAt: Date.now() + seconds * 1000,
                left: seconds,
                paused: false,
                done: false
            };
            row.querySelector('[data-pxr-timer-label]').textContent = label;

            timer.toggle.addEventListener('click', function () {
                if (timer.paused) {
                    timer.endsAt = Date.now() + timer.left * 1000;
                    timer.paused = false;
                    run();
                } else {
                    timer.left = Math.max(0, remaining(timer));
                    timer.paused = true;
                }
                draw(timer);
            });
            row.querySelector('[data-pxr-timer-remove]').addEventListener('click', function () { remove(timer); });

            timers.push(timer);
            list.appendChild(row);
            dock.hidden = false;
            if (live) live.textContent = '';
            draw(timer);
            run();
            return timer;
        }

        // Each step: its chip, or failing that the times in its words.
        root.querySelectorAll('[data-pxr-step]').forEach(function (step) {
            var label = say('step', 'Step %d', step.getAttribute('data-pxr-step'));
            var chip = step.querySelector('[data-pxr-timer-seconds]');
            var seconds = chip ? parseInt(chip.getAttribute('data-pxr-timer-seconds'), 10) : 0;

            if (chip && seconds > 0) {
                var button = timerButton(chip.textContent.trim(), seconds, label, start, chip.className + ' pxr-timer-chip');
                chip.parentNode.replaceChild(button, chip);
                return;
            }

            var text = step.querySelector('[data-pxr-step-text]');
            if (text) linkWrittenTimes(text, label, start);
        });

        return { start: start, count: function () { return timers.length; } };
    }

    var byRoot = [];

    function init() {
        document.querySelectorAll('.pxr-custom-single').forEach(function (root) {
            var api = initRecipe(root);
            if (api) byRoot.push({ root: root, api: api });
        });
    }

    window.PlugixaRecipeTimers = {
        clock: clock,
        pickDuration: pickDuration,
        /** The timers of the recipe an element belongs to (cook mode starts them too). */
        of: function (element) {
            for (var i = 0; i < byRoot.length; i++) {
                if (byRoot[i].root.contains(element)) return byRoot[i].api;
            }
            return null;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
