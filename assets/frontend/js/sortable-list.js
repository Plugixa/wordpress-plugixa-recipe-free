/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe sortable list - reorder the rows of a list by drag, by button, or by key.
 *
 * Loaded with the recipe submission form as the handle "plugixa-recipe-sortable". No
 * library: the native HTML drag-and-drop API does not work on touch screens and has no
 * keyboard story, and a vendored one would be a third-party file to keep and to disclose
 * for a hundred lines of behaviour.
 *
 * Three ways to move a row, because no single one works for everybody:
 *   - drag its handle (mouse, pen or touch - Pointer Events);
 *   - focus the handle and press Arrow Up / Arrow Down;
 *   - call move(row, -1 | 1), which is what the form's own up / down buttons do.
 * Every move is announced to a screen reader through a live region.
 *
 * Usage:
 *   var list = PlugixaRecipeSortable.attach(container, {
 *       row: '[data-pxr-row]',          // what is reordered
 *       handle: '[data-pxr-handle]',    // what is dragged and focused
 *       onChange: function () {},       // after any move
 *       announce: function (position, total) { return 'Moved to ' + position + ' of ' + total; }
 *   });
 *   list.move(row, 1);
 */

(function (root) {
    'use strict';

    function attach(container, options) {
        var rowSelector = options.row;
        var handleSelector = options.handle;
        var onChange = options.onChange || function () {};
        var dragging = null;
        var live = document.createElement('div');

        // Polite, and visually hidden with the form's own utility class.
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('role', 'status');
        live.className = 'pxr-custom-sr-only';
        container.parentNode.insertBefore(live, container.nextSibling);

        function rows() {
            return Array.prototype.slice.call(container.querySelectorAll(rowSelector)).filter(function (row) {
                return row.parentNode === container;
            });
        }

        function announce(row) {
            var all = rows();
            var position = all.indexOf(row) + 1;
            live.textContent = options.announce ? options.announce(position, all.length) : position + ' / ' + all.length;
        }

        /** Move a row one place up (-1) or down (1). Answers whether it moved. */
        function move(row, direction) {
            var all = rows();
            var index = all.indexOf(row);
            var target = index + direction;
            var focused = document.activeElement;

            if (index === -1 || target < 0 || target >= all.length) {
                return false;
            }

            if (direction < 0) {
                container.insertBefore(row, all[target]);
            } else {
                container.insertBefore(row, all[target].nextSibling);
            }

            // Moving a node drops focus in some browsers; put it back where it was.
            if (focused && row.contains(focused) && document.activeElement !== focused) {
                focused.focus();
            }

            announce(row);
            onChange();
            return true;
        }

        /* ── Keyboard: the handle is a button; the arrows move its row. ── */

        container.addEventListener('keydown', function (event) {
            var handle = event.target.closest ? event.target.closest(handleSelector) : null;
            var row;

            if (!handle || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
                return;
            }

            row = handle.closest(rowSelector);
            if (row && row.parentNode === container) {
                event.preventDefault();
                move(row, event.key === 'ArrowUp' ? -1 : 1);
            }
        });

        /* ── Pointer: drag the handle; the row follows by swapping with what it passes. ── */

        container.addEventListener('pointerdown', function (event) {
            var handle = event.target.closest ? event.target.closest(handleSelector) : null;
            var row;

            if (!handle || (event.pointerType === 'mouse' && event.button !== 0)) {
                return;
            }

            row = handle.closest(rowSelector);
            if (!row || row.parentNode !== container) {
                return;
            }

            dragging = { row: row, handle: handle, pointerId: event.pointerId, moved: false };
            row.classList.add('pxr-custom-row--dragging');
            // Keeps the pointer's events coming to the handle even when it leaves it.
            if (handle.setPointerCapture) {
                try { handle.setPointerCapture(event.pointerId); } catch (ignore) { /* a pointer that is already gone */ }
            }
            event.preventDefault();
        });

        container.addEventListener('pointermove', function (event) {
            var all;
            var index;
            var before;
            var after;
            var box;

            if (!dragging || event.pointerId !== dragging.pointerId) {
                return;
            }

            all = rows();
            index = all.indexOf(dragging.row);
            before = all[index - 1];
            after = all[index + 1];

            // Past the middle of a neighbour: change places with it.
            if (before) {
                box = before.getBoundingClientRect();
                if (event.clientY < box.top + box.height / 2) {
                    container.insertBefore(dragging.row, before);
                    dragging.moved = true;
                    return;
                }
            }
            if (after) {
                box = after.getBoundingClientRect();
                if (event.clientY > box.top + box.height / 2) {
                    container.insertBefore(dragging.row, after.nextSibling);
                    dragging.moved = true;
                }
            }
        });

        function drop(event) {
            var done = dragging;

            if (!done || (event && event.pointerId !== done.pointerId)) {
                return;
            }

            dragging = null;
            done.row.classList.remove('pxr-custom-row--dragging');
            if (done.moved) {
                announce(done.row);
                onChange();
            }
        }

        container.addEventListener('pointerup', drop);
        container.addEventListener('pointercancel', drop);

        return { move: move, rows: rows };
    }

    root.PlugixaRecipeSortable = { attach: attach };
})(typeof window !== 'undefined' ? window : this);
