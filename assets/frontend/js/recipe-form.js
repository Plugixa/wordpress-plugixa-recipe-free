/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Recipe Submission Form — Frontend JS
 *
 * Tabs with Back / Next, the category picker, the ingredient and step lists (add, group,
 * reorder, paste), image uploads with preview, a draft kept in the browser, the modal, and
 * the submission itself.
 *
 * Loaded after two scripts it is built on:
 *   recipe-text-parser.js  PlugixaRecipeParser - what a pasted line means (shared with the admin editor)
 *   sortable-list.js       PlugixaRecipeSortable - moving a row by drag, button or key
 *
 * Every message shown is in window.plugixaRecipeForm.i18n, printed by the shortcode: the
 * English text beside each t() call is only what is used if that is missing.
 *
 * @package PlugixaRecipe
 * @since   1.2.0
 */
(function () {
    'use strict';

    const CONFIG  = window.plugixaRecipeForm || {};
    const STRINGS = CONFIG.i18n || {};
    const UNITS   = Array.isArray(CONFIG.units) ? CONFIG.units : [];
    const PARSER  = window.PlugixaRecipeParser || null;
    const SORTER  = window.PlugixaRecipeSortable || null;
    const MAX_STEP_PHOTOS = parseInt(CONFIG.maxStepPhotos, 10) || 8;

    /** A translated message, with %d / %1$d filled from the values given. */
    function t(key, fallback, ...values) {
        let next = 0;
        return String(STRINGS[key] || fallback)
            .replace(/%(\d+)\$[ds]/g, (_all, position) => String(values[parseInt(position, 10) - 1] ?? ''))
            .replace(/%[ds]/g, () => String(values[next++] ?? ''));
    }

    /* ───────────────────────────── RowList ── */

    /**
     * One of the form's two lists: rows made from <template>s, flat, with heading rows.
     *
     * A heading row groups every row under it up to the next heading - the same model the
     * admin editor uses, so a recipe reads the same way in both. The list never drops below
     * one item row: removing the last one empties it instead.
     */
    class RowList {
        constructor(container, options) {
            this.container = container;
            this.options   = options;
            const card     = container.closest('.pxr-custom-form-card') || container.parentElement;
            this.itemTemplate    = card.querySelector(options.itemTemplate);
            this.headingTemplate = card.querySelector('[data-pxr-heading-template]');

            this.sortable = SORTER ? SORTER.attach(container, {
                row: '[data-pxr-row]',
                handle: '[data-pxr-handle]',
                onChange: () => this.changed(),
                announce: (position, total) => t('moved', 'Moved to position %1$d of %2$d.', position, total),
            }) : null;

            container.addEventListener('click', (event) => {
                const row = event.target.closest('[data-pxr-row]');
                if (!row || row.parentElement !== container) return;

                if (event.target.closest('[data-pxr-remove-row]')) {
                    this.remove(row);
                } else if (event.target.closest('[data-pxr-move]') && this.sortable) {
                    this.sortable.move(row, parseInt(event.target.closest('[data-pxr-move]').dataset.pxrMove, 10));
                }
            });

            this.add('item');
        }

        rows()  { return Array.from(this.container.children).filter(el => el.matches('[data-pxr-row]')); }
        items() { return this.rows().filter(row => !row.matches('[data-pxr-heading-row]')); }

        /** Add a row at the end (or after `after`), filled from `values` by input name. */
        add(kind, values = {}, after = null) {
            const template = kind === 'group' ? this.headingTemplate : this.itemTemplate;
            if (!template) return null;

            const row = template.content.firstElementChild.cloneNode(true);
            Object.keys(values).forEach(name => {
                const input = row.querySelector(`[name="${name}"]`);
                if (input) input.value = values[name];
            });

            this.container.insertBefore(row, after ? after.nextSibling : null);
            this.changed();
            return row;
        }

        remove(row) {
            const isItem = !row.matches('[data-pxr-heading-row]');
            if (isItem && this.items().length <= 1) {
                // The last item is emptied, not removed: the list always has a row to type in.
                row.querySelectorAll('input:not([type="file"]), textarea').forEach(el => { el.value = ''; });
                if (this.options.onEmptied) this.options.onEmptied(row);
            } else {
                const neighbour = row.previousElementSibling || row.nextElementSibling;
                row.remove();
                const focus = neighbour && neighbour.querySelector('input:not([type="file"]), textarea');
                if (focus) focus.focus();
            }
            this.changed();
        }

        /** Empty the list back to one blank item. */
        reset() {
            this.container.textContent = '';
            this.add('item');
        }

        /** True while nobody has typed anything: one item, every field empty. */
        untouched() {
            const rows = this.rows();
            return rows.length === 1 && !rows[0].matches('[data-pxr-heading-row]')
                && Array.from(rows[0].querySelectorAll('input:not([type="file"]), textarea')).every(el => el.value.trim() === '');
        }

        changed() {
            let number = 0;
            this.items().forEach(row => {
                const badge = row.querySelector('[data-pxr-step-number]');
                if (badge) badge.textContent = String(++number);
            });
            if (this.options.onChange) this.options.onChange();
        }

        /**
         * The rows as the request carries them: each item with the group it sits under.
         * `read(row)` turns one item row into an object, or null to leave it out.
         */
        collect(read) {
            const out = [];
            let group = '';
            let groupOrder = 0;
            let opened = false;

            this.rows().forEach(row => {
                if (row.matches('[data-pxr-heading-row]')) {
                    // The first heading keeps group 0 only when nothing came before it.
                    if (opened || out.length > 0) groupOrder++;
                    opened = true;
                    group = row.querySelector('[name="group_heading[]"]').value.trim();
                    return;
                }
                const item = read(row);
                if (item) out.push(Object.assign(item, { group_name: group, group_order: groupOrder }));
            });

            return out;
        }

        /** Everything typed, headings included, for the browser draft. */
        snapshot() {
            return this.rows().map(row => {
                const values = {};
                row.querySelectorAll('input:not([type="file"]), textarea').forEach(el => { values[el.name] = el.value; });
                return { kind: row.matches('[data-pxr-heading-row]') ? 'group' : 'item', values };
            });
        }

        restore(snapshot) {
            if (!Array.isArray(snapshot) || snapshot.length === 0) return;
            this.container.textContent = '';
            snapshot.forEach(entry => this.add(entry.kind === 'group' ? 'group' : 'item', entry.values || {}));
            if (this.items().length === 0) this.add('item');
        }
    }

    /* ───────────────────────────── RecipeSubmissionForm ── */

    class RecipeSubmissionForm {
        constructor(wrapper) {
            this.wrapper    = wrapper;
            this.form       = wrapper.querySelector('.pxr-custom-recipe-form');
            this.apiUrl     = wrapper.dataset.apiUrl;
            this.nonce      = wrapper.dataset.nonce;
            this.maxFileSize = parseInt(wrapper.dataset.maxFileSize, 10) * 1024 * 1024;
            this.messageEl  = wrapper.querySelector('[data-pxr-form-message]');
            this.submitBtn  = wrapper.querySelector('[data-pxr-submit-btn]');
            this.btnText    = wrapper.querySelector('[data-pxr-btn-text]');
            this.btnSpinner = wrapper.querySelector('[data-pxr-btn-spinner]');

            if (!this.form) return;

            this.maxGalleryImages = 10;
            // Set while the form is being filled by code (a restore, a reset): not an edit.
            this.quiet = true;

            this.initTabs();
            this.initMultiselect();
            this.initLists();
            this.initPaste();
            this.initImageUpload();
            this.initGallery();
            this.initDraft();
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));
            this.form.addEventListener('reset', () => {
                // resetForm() resets the form itself, which lands here again: `quiet` says so.
                if (this.quiet) return;
                // After the browser has emptied the fields.
                setTimeout(() => { this.resetForm(); this.discardDraft(); }, 0);
            });

            // Clear a field's error as soon as it is corrected, and note the edit for the draft.
            ['input', 'change'].forEach(type => {
                this.form.addEventListener(type, (e) => {
                    this.clearError(e.target);
                    this.scheduleDraft();
                });
            });

            this.quiet = false;
        }

        clearError(el) {
            if (!el.classList || !el.classList.contains('pxr-custom-form-input--error')) return;
            el.classList.remove('pxr-custom-form-input--error');
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
            const errMsg = el.parentElement.querySelector('.pxr-custom-field-error');
            if (errMsg) errMsg.remove();
        }

        /* ── Tabs ── */

        initTabs() {
            this.tabs = Array.from(this.wrapper.querySelectorAll('[data-pxr-tab]'));
            if (!this.tabs.length) return;

            this.tabs.forEach(tab => tab.addEventListener('click', () => this.showTab(tab.dataset.pxrTab)));

            this.backBtn = this.wrapper.querySelector('[data-pxr-step-back]');
            this.nextBtn = this.wrapper.querySelector('[data-pxr-step-next]');
            if (this.backBtn) this.backBtn.addEventListener('click', () => this.stepTab(-1));
            if (this.nextBtn) this.nextBtn.addEventListener('click', () => this.stepTab(1));

            this.showTab(this.tabs[0].dataset.pxrTab);
        }

        currentTab() {
            return this.tabs.findIndex(tab => tab.classList.contains('pxr-custom-form-tab--active'));
        }

        showTab(name) {
            this.tabs.forEach(tab => {
                const active = tab.dataset.pxrTab === name;
                tab.classList.toggle('pxr-custom-form-tab--active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            this.wrapper.querySelectorAll('[data-pxr-panel]').forEach(panel => {
                panel.classList.toggle('pxr-custom-form-panel--active', panel.dataset.pxrPanel === name);
            });

            const index = this.currentTab();
            if (this.backBtn) this.backBtn.hidden = index <= 0;
            if (this.nextBtn) this.nextBtn.hidden = index >= this.tabs.length - 1;
        }

        stepTab(direction) {
            const target = this.tabs[this.currentTab() + direction];
            if (!target) return;
            this.showTab(target.dataset.pxrTab);
            this.wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.focus({ preventScroll: true });
        }

        /* ── Multiselect Dropdown ── */

        initMultiselect() {
            this.wrapper.querySelectorAll('[data-pxr-multiselect]').forEach(ms => {
                const trigger     = ms.querySelector('[data-pxr-multiselect-trigger]');
                const dropdown    = ms.querySelector('[data-pxr-multiselect-dropdown]');
                const tagsWrap    = ms.querySelector('[data-pxr-multiselect-tags]');
                const placeholder = ms.querySelector('[data-pxr-multiselect-placeholder]');
                const checkboxes  = ms.querySelectorAll('[data-pxr-multiselect-checkbox]');

                if (!trigger || !dropdown) return;

                const updateTags = () => {
                    tagsWrap.textContent = '';
                    let count = 0;
                    checkboxes.forEach(cb => {
                        if (cb.checked) {
                            count++;
                            const tag = document.createElement('span');
                            tag.className = 'pxr-custom-multiselect__tag';
                            tag.textContent = cb.dataset.label;
                            const remove = document.createElement('button');
                            remove.type = 'button';
                            remove.className = 'pxr-custom-multiselect__tag-remove';
                            remove.dataset.value = cb.value;
                            remove.textContent = '\u00d7';
                            tag.appendChild(remove);
                            tagsWrap.appendChild(tag);
                        }
                    });
                    placeholder.style.display = count > 0 ? 'none' : '';
                };

                // Toggle dropdown
                trigger.addEventListener('click', (e) => {
                    if (e.target.closest('.pxr-custom-multiselect__tag-remove')) return;
                    dropdown.classList.toggle('pxr-custom-multiselect__dropdown--open');
                });

                // Checkbox change
                dropdown.addEventListener('change', () => {
                    updateTags();
                    // Clear category validation error.
                    if (trigger) trigger.classList.remove('pxr-custom-form-input--error');
                    const errMsg = ms.querySelector('.pxr-custom-field-error');
                    if (errMsg) errMsg.remove();
                });

                // Remove tag
                tagsWrap.addEventListener('click', (e) => {
                    const removeBtn = e.target.closest('.pxr-custom-multiselect__tag-remove');
                    if (!removeBtn) return;
                    const val = removeBtn.dataset.value;
                    checkboxes.forEach(cb => {
                        if (cb.value === val) cb.checked = false;
                    });
                    updateTags();
                });

                // Close on outside click
                document.addEventListener('click', (e) => {
                    if (!ms.contains(e.target)) {
                        dropdown.classList.remove('pxr-custom-multiselect__dropdown--open');
                    }
                });
            });
        }

        /* ── Ingredient and step lists ── */

        initLists() {
            const ingredientRows  = this.wrapper.querySelector('[data-pxr-ingredient-rows]');
            const instructionRows = this.wrapper.querySelector('[data-pxr-instruction-rows]');

            if (ingredientRows) {
                this.ingredients = new RowList(ingredientRows, {
                    itemTemplate: '[data-pxr-ingredient-template]',
                    onChange: () => this.scheduleDraft(),
                });

                const add = this.wrapper.querySelector('[data-pxr-add-ingredient]');
                if (add) add.addEventListener('click', () => this.focusRow(this.ingredients.add('item')));

                // Enter moves down the list instead of submitting a half-written recipe; on the
                // last row, once it holds something, it adds the next one.
                ingredientRows.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter' || !e.target.matches('input')) return;
                    e.preventDefault();

                    const row  = e.target.closest('[data-pxr-row]');
                    const rows = this.ingredients.rows();
                    const next = rows[rows.indexOf(row) + 1];
                    const empty = Array.from(row.querySelectorAll('input')).every(el => el.value.trim() === '');

                    if (next) {
                        this.focusRow(next);
                    } else if (!empty) {
                        this.focusRow(this.ingredients.add('item'));
                    }
                });

                // Several lines pasted into one row are a list: read them as one.
                ingredientRows.addEventListener('paste', (e) => {
                    const text = (e.clipboardData || window.clipboardData).getData('text');
                    if (!/[\r\n]/.test(text.trim())) return;
                    e.preventDefault();
                    this.applyPaste('ingredients', text);
                });
            }

            if (instructionRows) {
                this.instructions = new RowList(instructionRows, {
                    itemTemplate: '[data-pxr-instruction-template]',
                    onChange: () => this.scheduleDraft(),
                    onEmptied: (row) => this.setStepPhoto(row, null),
                });

                const add = this.wrapper.querySelector('[data-pxr-add-instruction]');
                if (add) add.addEventListener('click', () => this.focusRow(this.instructions.add('item'), 'textarea'));

                // A step's photo: chosen, checked for size, remembered on its row.
                instructionRows.addEventListener('change', (e) => {
                    if (!e.target.matches('[data-pxr-step-photo]')) return;
                    const row  = e.target.closest('[data-pxr-row]');
                    const file = e.target.files && e.target.files[0];
                    e.target.value = '';
                    if (!file) return;

                    if (file.size > this.maxFileSize) {
                        this.showMessage('error', t('fileTooLarge', 'That file is too large. The maximum size is %dMB.', Math.round(this.maxFileSize / 1024 / 1024)));
                        return;
                    }
                    const taken = this.instructions.items().filter(item => item.pxrPhoto && item !== row).length;
                    if (taken >= MAX_STEP_PHOTOS) {
                        this.showMessage('error', t('stepPhotosFull', 'At most %d steps can have a photo.', MAX_STEP_PHOTOS));
                        return;
                    }
                    this.setStepPhoto(row, file);
                });
                instructionRows.addEventListener('click', (e) => {
                    const remove = e.target.closest('[data-pxr-step-photo-remove]');
                    if (remove) this.setStepPhoto(remove.closest('[data-pxr-row]'), null);
                });
            }

            this.wrapper.querySelectorAll('[data-pxr-add-heading]').forEach(button => {
                button.addEventListener('click', () => {
                    const list = button.dataset.pxrAddHeading === 'instructions' ? this.instructions : this.ingredients;
                    if (list) this.focusRow(list.add('group'));
                });
            });
        }

        focusRow(row, selector = 'input:not([type="file"])') {
            const field = row && row.querySelector(selector);
            if (field) field.focus();
        }

        setStepPhoto(row, file) {
            if (!row) return;
            row.pxrPhoto = file || null;
            const label  = row.querySelector('[data-pxr-step-photo-label]');
            const remove = row.querySelector('[data-pxr-step-photo-remove]');
            if (label) label.textContent = file ? file.name : t('addPhoto', 'Add a photo');
            if (remove) remove.hidden = !file;
        }

        /* ── Paste a list ── */

        initPaste() {
            this.wrapper.querySelectorAll('[data-pxr-paste-toggle]').forEach(toggle => {
                const kind  = toggle.dataset.pxrPasteToggle;
                const panel = this.wrapper.querySelector(`[data-pxr-paste-panel="${kind}"]`);
                if (!panel) return;

                const area  = panel.querySelector('[data-pxr-paste-text]');
                const close = () => {
                    panel.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                };

                toggle.addEventListener('click', () => {
                    panel.hidden = !panel.hidden;
                    toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                    if (!panel.hidden) area.focus();
                });
                panel.querySelector('[data-pxr-paste-cancel]').addEventListener('click', () => {
                    close();
                    toggle.focus();
                });
                panel.querySelector('[data-pxr-paste-apply]').addEventListener('click', () => {
                    if (this.applyPaste(kind, area.value)) {
                        area.value = '';
                        close();
                    }
                });
            });
        }

        /**
         * Turn pasted text into rows of the list. The list itself is the review: every row is
         * editable and nothing is sent until the form is submitted. A row the parser was
         * unsure of is marked, so the eye goes to it.
         */
        applyPaste(kind, text) {
            const list = kind === 'instructions' ? this.instructions : this.ingredients;
            if (!list || !PARSER) return false;

            const parsed = kind === 'instructions' ? PARSER.parseStepBlock(text) : PARSER.parseIngredientBlock(text, UNITS);
            if (!parsed.length) {
                this.showMessage('error', t('pastedNothing', 'Nothing in that text looked like a list.'));
                return false;
            }

            if (list.untouched()) list.container.textContent = '';

            let first = null;
            parsed.forEach(entry => {
                let row;
                if (entry.kind === 'group') {
                    row = list.add('group', { 'group_heading[]': entry.name });
                } else if (kind === 'instructions') {
                    row = list.add('item', { 'instructions[]': entry.text });
                } else {
                    row = list.add('item', { 'ing_qty[]': entry.quantity, 'ing_unit[]': entry.unit, 'ing_name[]': entry.name, 'ing_notes[]': entry.notes });
                    if (row && entry.sure === false) {
                        row.classList.add('pxr-custom-row--check');
                        row.title = t('checkRow', 'Check this row: it did not read like an ingredient.');
                    }
                }
                first = first || row;
            });

            this.showMessage('success', t('pasted', '%d row(s) added from your text. Check them before submitting.', parsed.length));
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }

        /* ── Image Upload ── */

        initImageUpload() {
            const zone      = this.wrapper.querySelector('[data-pxr-upload-zone]');
            this.fileInput  = this.wrapper.querySelector('[data-pxr-file-input]');
            this.previewWrap = this.wrapper.querySelector('[data-pxr-upload-preview]');
            this.previewImg  = this.wrapper.querySelector('[data-pxr-upload-preview-img]');
            const removeBtn  = this.wrapper.querySelector('[data-pxr-upload-remove]');

            if (!zone || !this.fileInput) return;

            zone.addEventListener('click', (e) => {
                if (e.target.closest('[data-pxr-upload-remove]')) return;
                this.fileInput.click();
            });

            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                zone.classList.add('pxr-custom-upload-area--dragover');
            });

            zone.addEventListener('dragleave', () => {
                zone.classList.remove('pxr-custom-upload-area--dragover');
            });

            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('pxr-custom-upload-area--dragover');
                if (e.dataTransfer.files.length) {
                    this.fileInput.files = e.dataTransfer.files;
                    this.handleFileSelect(e.dataTransfer.files[0], zone);
                }
            });

            this.fileInput.addEventListener('change', () => {
                if (this.fileInput.files.length) {
                    this.handleFileSelect(this.fileInput.files[0], zone);
                }
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.fileInput.value = '';
                    this.previewWrap.classList.add('pxr:hidden');
                    this.previewImg.removeAttribute('src');
                    zone.classList.remove('has-preview');
                });
            }
        }

        handleFileSelect(file, zone) {
            // Clear image validation error.
            zone.classList.remove('pxr-custom-form-input--error');
            const errMsg = zone.parentElement.querySelector('.pxr-custom-field-error');
            if (errMsg) errMsg.remove();

            if (file.size > this.maxFileSize) {
                const sizeMB = Math.round(this.maxFileSize / 1024 / 1024);
                this.showMessage('error', t('fileTooLarge', 'That file is too large. The maximum size is %dMB.', sizeMB));
                this.fileInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                this.previewImg.src = e.target.result;
                this.previewWrap.classList.remove('pxr:hidden');
                zone.classList.add('has-preview');
            };
            reader.readAsDataURL(file);
        }

        /* ── Gallery Upload ── */

        initGallery() {
            const zone        = this.wrapper.querySelector('[data-pxr-gallery-zone]');
            this.galleryInput = this.wrapper.querySelector('[data-pxr-gallery-input]');
            this.galleryGrid  = this.wrapper.querySelector('[data-pxr-gallery-grid]');
            this.galleryPlaceholder = this.wrapper.querySelector('[data-pxr-gallery-placeholder]');
            this.galleryFiles = [];

            if (!zone || !this.galleryInput) return;

            zone.addEventListener('click', (e) => {
                if (e.target.closest('.pxr-custom-gallery-item__remove')) return;
                this.galleryInput.click();
            });

            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                zone.classList.add('pxr-custom-upload-area--dragover');
            });

            zone.addEventListener('dragleave', () => {
                zone.classList.remove('pxr-custom-upload-area--dragover');
            });

            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('pxr-custom-upload-area--dragover');
                if (e.dataTransfer.files.length) {
                    this.addGalleryFiles(e.dataTransfer.files);
                }
            });

            this.galleryInput.addEventListener('change', () => {
                if (this.galleryInput.files.length) {
                    this.addGalleryFiles(this.galleryInput.files);
                }
            });

            this.galleryGrid.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('.pxr-custom-gallery-item__remove');
                if (!removeBtn) return;
                e.stopPropagation();
                const idx = parseInt(removeBtn.dataset.index, 10);
                this.galleryFiles.splice(idx, 1);
                this.renderGalleryPreviews();
            });
        }

        addGalleryFiles(fileList) {
            const sizeMB = Math.round(this.maxFileSize / 1024 / 1024);
            for (const file of fileList) {
                if (this.galleryFiles.length >= this.maxGalleryImages) {
                    this.showMessage('error', t('galleryFull', 'At most %d gallery images are allowed.', this.maxGalleryImages));
                    break;
                }
                if (file.size > this.maxFileSize) {
                    this.showMessage('error', t('fileTooLarge', 'That file is too large. The maximum size is %dMB.', sizeMB));
                    continue;
                }
                this.galleryFiles.push(file);
            }
            this.renderGalleryPreviews();
        }

        renderGalleryPreviews() {
            this.galleryGrid.textContent = '';
            this.galleryFiles.forEach((file, i) => {
                const item = document.createElement('div');
                item.className = 'pxr-custom-gallery-item';
                const img = document.createElement('img');
                img.className = 'pxr-custom-gallery-item__img';
                img.alt = file.name;
                const reader = new FileReader();
                reader.onload = (e) => { img.src = e.target.result; };
                reader.readAsDataURL(file);
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'pxr-custom-gallery-item__remove';
                removeBtn.dataset.index = i;
                removeBtn.textContent = '\u00d7';
                item.appendChild(img);
                item.appendChild(removeBtn);
                this.galleryGrid.appendChild(item);
            });
            if (this.galleryPlaceholder) {
                this.galleryPlaceholder.style.display = this.galleryFiles.length > 0 ? 'none' : '';
            }
        }

        /* ── Draft kept in the browser ── */

        /**
         * What has been typed is kept in THIS browser, so a closed tab or a dropped connection
         * does not cost a visitor their recipe. Text only - never the photos, the captcha or
         * the honeypot - under a key of this page, for thirty days, and gone on submit, on
         * Reset and on Discard. It is offered back, never restored unasked: on a shared
         * computer the last visitor's recipe must not appear in the next one's form.
         */
        initDraft() {
            this.draftKey = 'plugixaRecipe:formDraft:v1:' + window.location.pathname;
            const notice  = this.wrapper.querySelector('[data-pxr-draft-notice]');
            const draft   = this.readDraft();
            if (!notice || !draft) return;

            notice.hidden = false;
            this.draftOffered = true;
            notice.querySelector('[data-pxr-draft-restore]').addEventListener('click', () => {
                this.restoreDraft(draft);
                notice.hidden = true;
                this.draftOffered = false;
            });
            notice.querySelector('[data-pxr-draft-discard]').addEventListener('click', () => {
                this.discardDraft();
                notice.hidden = true;
                this.draftOffered = false;
            });
        }

        readDraft() {
            try {
                const draft = JSON.parse(window.localStorage.getItem(this.draftKey) || 'null');
                const fresh = draft && typeof draft.savedAt === 'number' && Date.now() - draft.savedAt < 30 * 24 * 60 * 60 * 1000;
                if (draft && !fresh) window.localStorage.removeItem(this.draftKey);
                return fresh ? draft : null;
            } catch (e) {
                return null;
            }
        }

        discardDraft() {
            clearTimeout(this.draftTimer);
            try { window.localStorage.removeItem(this.draftKey); } catch (e) { /* no storage, no draft */ }
        }

        /** Fields the draft holds: everything typed, minus what must never be stored. */
        draftFields() {
            return Array.from(this.form.elements).filter(el => el.name
                && !['file', 'submit', 'reset', 'button', 'hidden'].includes(el.type)
                && !['website_url', 'turnstile_token', 'cf-turnstile-response'].includes(el.name)
                && !el.closest('[data-pxr-row]'));
        }

        scheduleDraft() {
            // A draft still on offer is not overwritten before it has been answered.
            if (this.quiet || this.draftOffered || !this.draftKey) return;
            clearTimeout(this.draftTimer);
            this.draftTimer = setTimeout(() => {
                const fields = {};
                this.draftFields().forEach(el => {
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        if (el.checked) (fields[el.name] = fields[el.name] || []).push(el.value);
                    } else if (el.value !== '') {
                        fields[el.name] = el.value;
                    }
                });
                try {
                    window.localStorage.setItem(this.draftKey, JSON.stringify({
                        savedAt: Date.now(),
                        fields,
                        ingredients: this.ingredients ? this.ingredients.snapshot() : [],
                        instructions: this.instructions ? this.instructions.snapshot() : [],
                    }));
                } catch (e) { /* private mode or a full quota: the form works without a draft */ }
            }, 1200);
        }

        restoreDraft(draft) {
            this.quiet = true;
            this.draftFields().forEach(el => {
                const stored = draft.fields ? draft.fields[el.name] : undefined;
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = Array.isArray(stored) && stored.includes(el.value);
                } else if (typeof stored === 'string') {
                    el.value = stored;
                }
            });
            if (this.ingredients) this.ingredients.restore(draft.ingredients);
            if (this.instructions) this.instructions.restore(draft.instructions);
            // The category picker shows its choices as tags; redraw them from the checkboxes.
            this.wrapper.querySelectorAll('[data-pxr-multiselect-dropdown]').forEach(dropdown => {
                dropdown.dispatchEvent(new Event('change', { bubbles: false }));
            });
            this.quiet = false;
        }

        /* ── Messages ── */

        showMessage(type, text) {
            this.messageEl.className = 'pxr-custom-form-message';
            this.messageEl.classList.add(type === 'success' ? 'pxr-custom-form-message--success' : 'pxr-custom-form-message--error');
            this.messageEl.textContent = text;
            this.messageEl.classList.remove('pxr:hidden');
            this.messageEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        hideMessage() {
            this.messageEl.classList.add('pxr:hidden');
        }

        /* ── Loading State ── */

        setLoading(loading) {
            this.submitBtn.disabled = loading;
            this.btnText.textContent = loading ? t('submitting', 'Submitting...') : t('submit', 'Submit Recipe');
            this.btnSpinner.classList.toggle('pxr:hidden', !loading);
        }

        /* ── Validation ── */

        validateForm() {
            const errors = [];
            const val = (name) => {
                const el = this.form.querySelector(`[name="${name}"]`);
                return el ? el.value.trim() : '';
            };
            const minutes = (name, required, requiredMessage) => {
                const value = val(name);
                if (value === '') {
                    if (required) errors.push({ field: name, tab: 'details', message: requiredMessage });
                } else if (parseInt(value, 10) < 0 || parseInt(value, 10) > 9999 || isNaN(parseInt(value, 10))) {
                    errors.push({ field: name, tab: 'details', message: t('timeRange', 'Enter a time between 0 and 9999 minutes.') });
                }
            };

            // Basic tab.
            if (!val('title')) {
                errors.push({ field: 'title', tab: 'basic', message: t('titleRequired', 'Recipe title is required.') });
            }
            if (!val('description')) {
                errors.push({ field: 'description', tab: 'basic', message: t('descRequired', 'Description is required.') });
            }
            // Image (basic tab).
            const fileInput = this.wrapper.querySelector('[data-pxr-file-input]');
            const previewEl = this.wrapper.querySelector('[data-pxr-upload-preview]');
            const previewVisible = previewEl && !previewEl.classList.contains('pxr:hidden');
            if ((!fileInput || !fileInput.files || !fileInput.files.length) && !previewVisible) {
                errors.push({ field: 'recipe_image', tab: 'basic', message: t('imageRequired', 'Recipe image is required.') });
            }

            // Details tab.
            if (this.form.querySelector('input[name="categories[]"]') && this.form.querySelectorAll('input[name="categories[]"]:checked').length === 0) {
                errors.push({ field: 'categories[]', tab: 'details', message: t('categoryRequired', 'Please select at least one category.') });
            }
            if (!val('difficulty')) {
                errors.push({ field: 'difficulty', tab: 'details', message: t('difficultyRequired', 'Difficulty is required.') });
            }
            const servings = val('servings');
            if (!servings || parseInt(servings, 10) < 1 || parseInt(servings, 10) > 999) {
                errors.push({ field: 'servings', tab: 'details', message: t('servingsRange', 'Servings must be between 1 and 999.') });
            }
            minutes('prep_time', true, t('prepRequired', 'Prep time is required.'));
            minutes('cook_time', true, t('cookRequired', 'Cook time is required.'));
            minutes('rest_time', false, '');

            // Ingredients tab.
            if (this.ingredients && !this.ingredients.items().some(row => row.querySelector('[name="ing_name[]"]').value.trim())) {
                errors.push({ element: this.ingredients.items()[0].querySelector('[name="ing_name[]"]'), tab: 'ingredients', message: t('ingredientRequired', 'At least one ingredient is required.') });
            }

            // Instructions tab.
            if (this.instructions) {
                const steps = this.instructions.items();
                if (!steps.some(row => row.querySelector('[name="instructions[]"]').value.trim())) {
                    errors.push({ element: steps[0].querySelector('[name="instructions[]"]'), tab: 'instructions', message: t('stepRequired', 'At least one instruction step is required.') });
                }
                steps.forEach(row => {
                    const timer = row.querySelector('[name="inst_timers[]"]');
                    if (timer && timer.value.trim() !== '' && PARSER && PARSER.parseTimer(timer.value) === null) {
                        errors.push({ element: timer, tab: 'instructions', message: t('timerInvalid', 'Use minutes, or a time such as 1:30, 90s or 1h 20m.') });
                    }
                });
            }

            // Extras tab — optional URL validation.
            const urlPattern = /^https?:\/\/.+/i;
            ['video_url', 'source_url'].forEach(name => {
                if (val(name) && !urlPattern.test(val(name))) {
                    errors.push({ field: name, tab: 'extras', message: t('urlInvalid', 'Please enter a valid URL (starting with http:// or https://).') });
                }
            });

            // Nutrition tab — non-negative values.
            this.form.querySelectorAll('[name^="nutrition_"]').forEach(input => {
                if (input.type === 'number' && input.value !== '' && parseFloat(input.value) < 0) {
                    errors.push({ element: input, tab: 'nutrition', message: t('notNegative', 'This value cannot be negative.') });
                }
            });

            // Custom fields tab — required validation.
            const checkedRadioGroups = new Set();
            this.form.querySelectorAll('[name^="custom_field_"]').forEach(input => {
                if (input.type === 'radio') {
                    const name = input.name;
                    if (checkedRadioGroups.has(name)) return;
                    checkedRadioGroups.add(name);
                    if (input.required && !this.form.querySelector(`input[name="${name}"]:checked`)) {
                        errors.push({ element: input, tab: 'custom-fields', message: t('fieldRequired', 'This field is required.') });
                    }
                } else if (input.type === 'checkbox') {
                    // Checkbox groups are optional by default (no required attr on multi-check)
                } else if (input.required && !input.value.trim()) {
                    errors.push({ element: input, tab: 'custom-fields', message: t('fieldRequired', 'This field is required.') });
                }
            });

            return errors;
        }

        showFieldErrors(errors) {
            this.clearFieldErrors();

            const tabsWithErrors = new Set();
            let serial = 0;
            // Mark one element and say why beneath it, tied together for a screen reader.
            const mark = (el, holder, message) => {
                const msg = document.createElement('div');
                msg.className = 'pxr-custom-field-error';
                msg.id = `${this.wrapper.id}-error-${++serial}`;
                msg.textContent = message;
                holder.appendChild(msg);
                el.classList.add('pxr-custom-form-input--error');
                el.setAttribute('aria-invalid', 'true');
                el.setAttribute('aria-describedby', msg.id);
            };

            errors.forEach(err => {
                tabsWithErrors.add(err.tab);

                // Special handling: categories multiselect trigger.
                if (err.field === 'categories[]') {
                    const ms = this.wrapper.querySelector('[data-pxr-multiselect]');
                    const trigger = ms && ms.querySelector('[data-pxr-multiselect-trigger]');
                    if (trigger) {
                        mark(trigger, ms, err.message);
                        err.element = trigger;
                    }
                    return;
                }

                // Special handling: image upload zone.
                if (err.field === 'recipe_image') {
                    const zone = this.wrapper.querySelector('[data-pxr-upload-zone]');
                    if (zone) {
                        mark(zone, zone.parentElement, err.message);
                        err.element = zone;
                    }
                    return;
                }

                const el = err.element || this.form.querySelector(`[name="${err.field}"]`);
                if (el) {
                    mark(el, el.parentElement, err.message);
                    err.element = el;
                }
            });

            // Mark tabs that have errors.
            tabsWithErrors.forEach(tab => {
                const tabBtn = this.wrapper.querySelector(`[data-pxr-tab="${tab}"]`);
                if (tabBtn) tabBtn.classList.add('pxr-custom-form-tab--has-error');
            });

            // Go to the first problem: its tab, then the field itself.
            if (errors.length > 0) {
                this.showMessage('error', t('fixErrors', 'Please fix %d problem(s) before submitting.', errors.length));
                this.showTab(errors[0].tab);

                const first = errors[0].element;
                if (first) {
                    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    first.focus({ preventScroll: true });
                }
            }
        }

        clearFieldErrors() {
            this.wrapper.querySelectorAll('.pxr-custom-form-input--error').forEach(el => {
                el.classList.remove('pxr-custom-form-input--error');
                el.removeAttribute('aria-invalid');
                el.removeAttribute('aria-describedby');
            });
            this.wrapper.querySelectorAll('.pxr-custom-field-error').forEach(el => el.remove());
            this.wrapper.querySelectorAll('.pxr-custom-form-tab--has-error').forEach(el => {
                el.classList.remove('pxr-custom-form-tab--has-error');
            });
        }

        /* ── Form Submission ── */

        handleSubmit(e) {
            e.preventDefault();
            this.hideMessage();
            this.clearFieldErrors();

            // Client-side validation.
            const errors = this.validateForm();
            if (errors.length > 0) {
                this.showFieldErrors(errors);
                return;
            }

            const formData = new FormData(this.form);
            const value = (row, name) => (row.querySelector(`[name="${name}"]`)?.value || '').trim();

            // The ingredients, each with the group heading it sits under.
            const ingredients = this.ingredients ? this.ingredients.collect(row => {
                const name = value(row, 'ing_name[]');
                return name ? { quantity: value(row, 'ing_qty[]'), unit: value(row, 'ing_unit[]'), name, notes: value(row, 'ing_notes[]') } : null;
            }) : [];

            // The steps. A photo travels as step_images[n]; the step says which n is its own.
            const photos = [];
            const instructions = this.instructions ? this.instructions.collect(row => {
                const text = value(row, 'instructions[]');
                if (!text) return null;

                const step = {
                    title: value(row, 'inst_titles[]'),
                    instruction_text: text,
                    timer_seconds: (PARSER && PARSER.parseTimer(value(row, 'inst_timers[]'))) || 0,
                };
                if (row.pxrPhoto && photos.length < MAX_STEP_PHOTOS) {
                    step.image_index = photos.length;
                    photos.push(row.pxrPhoto);
                }
                return step;
            }) : [];

            // Collect nutrition as JSON object.
            const nutrition = {};
            let hasNutrition = false;
            const nutritionPrefix = 'nutrition_';
            const servingSizeInput = this.form.querySelector('[name="nutrition_serving_size"]');
            if (servingSizeInput && servingSizeInput.value.trim()) {
                nutrition.serving_size = servingSizeInput.value.trim();
                hasNutrition = true;
            }
            const nutritionFields = ['calories','total_fat','saturated_fat','trans_fat','cholesterol',
                'sodium','total_carbohydrate','dietary_fiber','total_sugars',
                'protein','vitamin_a','vitamin_c','calcium','iron','potassium'];
            nutritionFields.forEach(field => {
                const input = this.form.querySelector(`[name="${nutritionPrefix}${field}"]`);
                if (input && input.value !== '') {
                    nutrition[field] = parseFloat(input.value);
                    hasNutrition = true;
                }
            });

            // Collect custom fields as JSON object.
            const customFields = {};
            this.form.querySelectorAll('[name^="custom_field_"]').forEach(input => {
                const key = input.name.replace('custom_field_', '').replace('[]', '');
                if (input.type === 'checkbox') {
                    if (!customFields[key]) customFields[key] = [];
                    if (input.checked) customFields[key].push(input.value);
                } else if (input.type === 'radio') {
                    if (input.checked) customFields[key] = input.value;
                } else {
                    customFields[key] = input.value;
                }
            });
            // Convert checkbox arrays to comma-separated strings.
            Object.keys(customFields).forEach(key => {
                if (Array.isArray(customFields[key])) {
                    customFields[key] = customFields[key].join(',');
                }
            });

            // Remove raw row inputs, nutrition inputs, and custom field inputs, then append JSON.
            ['ing_qty[]', 'ing_unit[]', 'ing_name[]', 'ing_notes[]', 'instructions[]', 'inst_titles[]', 'inst_timers[]', 'group_heading[]', 'nutrition_serving_size']
                .forEach(name => formData.delete(name));
            nutritionFields.forEach(f => formData.delete(nutritionPrefix + f));
            this.form.querySelectorAll('[name^="custom_field_"]').forEach(input => {
                formData.delete(input.name);
            });

            formData.append('ingredients', JSON.stringify(ingredients));
            formData.append('instructions', JSON.stringify(instructions));
            photos.forEach((file, index) => formData.append(`step_images[${index}]`, file));
            if (hasNutrition) {
                formData.append('nutrition', JSON.stringify(nutrition));
            }
            if (Object.keys(customFields).length > 0) {
                formData.append('custom_fields', JSON.stringify(customFields));
            }

            // Append gallery files (replace the empty file input with actual files).
            formData.delete('gallery_images[]');
            if (this.galleryFiles && this.galleryFiles.length > 0) {
                this.galleryFiles.forEach(file => {
                    formData.append('gallery_images[]', file);
                });
            }

            // Cloudflare Turnstile needs no code here: the widget is rendered with
            // data-response-field-name="turnstile_token", so its token is already a
            // field of this form and new FormData(form) collected it above.
            this.submitRecipe(formData);
        }

        submitRecipe(formData) {
            this.setLoading(true);

            // Only a signed-in user's page has a nonce (plugixa_recipe_rest_nonce()); a visitor
            // sends none, because an empty or stale X-WP-Nonce is answered with a 403.
            fetch(this.apiUrl, {
                method: 'POST',
                headers: this.nonce ? { 'X-WP-Nonce': this.nonce } : {},
                body: formData,
                credentials: 'same-origin',
            })
            .then(res => res.json().then(json => ({ ok: res.ok, data: json })))
            .then(result => {
                this.setLoading(false);
                if (result.ok && result.data.success !== false) {
                    const msg = result.data.message || t('submitted', 'Your recipe has been submitted successfully!');
                    this.resetForm();
                    this.discardDraft();
                    this.showMessage('success', msg);

                    // Auto-close modal after success.
                    const modal = this.wrapper.closest('.pxr-custom-submission-modal');
                    if (modal) {
                        setTimeout(() => {
                            modal.classList.remove('pxr-active');
                            document.body.style.overflow = '';
                        }, 1500);
                    }
                } else {
                    const msg = (result.data && result.data.message)
                        ? result.data.message
                        : t('failed', 'An error occurred. Please try again.');
                    this.showMessage('error', msg);
                }
            })
            .catch(() => {
                this.setLoading(false);
                this.showMessage('error', t('network', 'Network error. Please check your connection and try again.'));
            });
        }

        resetForm() {
            this.quiet = true;
            this.form.reset();
            this.clearFieldErrors();
            this.hideMessage();

            // Reset image preview.
            if (this.previewWrap) {
                this.previewWrap.classList.add('pxr:hidden');
                this.previewImg.removeAttribute('src');
                const zone = this.wrapper.querySelector('[data-pxr-upload-zone]');
                if (zone) zone.classList.remove('has-preview');
            }

            // Both lists back to one empty row.
            if (this.ingredients) this.ingredients.reset();
            if (this.instructions) this.instructions.reset();

            // Reset multiselect tags.
            this.wrapper.querySelectorAll('[data-pxr-multiselect-tags]').forEach(el => { el.textContent = ''; });
            this.wrapper.querySelectorAll('[data-pxr-multiselect-placeholder]').forEach(p => { p.style.display = ''; });

            // Reset gallery.
            if (this.galleryFiles) {
                this.galleryFiles = [];
                if (this.galleryGrid) this.galleryGrid.textContent = '';
                if (this.galleryPlaceholder) this.galleryPlaceholder.style.display = '';
            }

            if (this.tabs && this.tabs.length) this.showTab(this.tabs[0].dataset.pxrTab);
            this.quiet = false;
        }
    }

    /* ───────────────────────────── RecipeFormModal ── */

    class RecipeFormModal {
        constructor(triggerBtn) {
            const modalId = triggerBtn.dataset.pxrModalTrigger;
            this.modal = document.getElementById(modalId);
            if (!this.modal) return;

            triggerBtn.addEventListener('click', () => this.open());

            this.modal.querySelectorAll('[data-pxr-modal-close]').forEach(el => {
                el.addEventListener('click', (e) => {
                    if (el.tagName === 'DIV' && e.target !== el) return;
                    this.close();
                });
            });

            this.modal.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.close();
            });
        }

        open() {
            this.modal.classList.add('pxr-active');
            document.body.style.overflow = 'hidden';
            const firstInput = this.modal.querySelector('input:not([type=hidden]):not([type=file]):not([type=checkbox]), textarea');
            if (firstInput) firstInput.focus();
        }

        close() {
            this.modal.classList.remove('pxr-active');
            document.body.style.overflow = '';
        }
    }

    /* ───────────────────────────── Init ── */

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-pxr-recipe-form]').forEach(el => {
            new RecipeSubmissionForm(el);
        });

        document.querySelectorAll('[data-pxr-modal-trigger]').forEach(btn => {
            new RecipeFormModal(btn);
        });
    });
})();
