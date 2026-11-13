/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Recipe Submission Form — Frontend JS
 *
 * Handles tabs, multiselect dropdown, dynamic ingredient/instruction rows,
 * image upload with preview, modal open/close, and AJAX form submission.
 *
 * @package PlugixaRecipe
 * @since   1.2.0
 */
(function () {
    'use strict';

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

            this.initTabs();
            this.initMultiselect();
            this.initIngredients();
            this.initInstructions();
            this.initImageUpload();
            this.initGallery();
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));

            // Clear field-level error on user input.
            this.form.addEventListener('input', (e) => {
                const el = e.target;
                if (el.classList.contains('pxr-custom-form-input--error')) {
                    el.classList.remove('pxr-custom-form-input--error');
                    const errMsg = el.parentElement.querySelector('.pxr-custom-field-error');
                    if (errMsg) errMsg.remove();
                }
            });
            this.form.addEventListener('change', (e) => {
                const el = e.target;
                if (el.classList.contains('pxr-custom-form-input--error')) {
                    el.classList.remove('pxr-custom-form-input--error');
                    const errMsg = el.parentElement.querySelector('.pxr-custom-field-error');
                    if (errMsg) errMsg.remove();
                }
            });
        }

        /* ── Tabs ── */

        initTabs() {
            const tabs   = this.wrapper.querySelectorAll('[data-pxr-tab]');
            const panels = this.wrapper.querySelectorAll('[data-pxr-panel]');
            if (!tabs.length) return;

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('pxr-custom-form-tab--active'));
                    panels.forEach(p => p.classList.remove('pxr-custom-form-panel--active'));
                    tab.classList.add('pxr-custom-form-tab--active');
                    const panel = this.wrapper.querySelector(`[data-pxr-panel="${tab.dataset.pxrTab}"]`);
                    if (panel) panel.classList.add('pxr-custom-form-panel--active');
                });
            });
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
                    tagsWrap.innerHTML = '';
                    let count = 0;
                    checkboxes.forEach(cb => {
                        if (cb.checked) {
                            count++;
                            const tag = document.createElement('span');
                            tag.className = 'pxr-custom-multiselect__tag';
                            tag.innerHTML = `${cb.dataset.label}<button type="button" class="pxr-custom-multiselect__tag-remove" data-value="${cb.value}">&times;</button>`;
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

        /* ── Ingredients ── */

        initIngredients() {
            this.ingredientContainer = this.wrapper.querySelector('[data-pxr-ingredient-rows]');
            const addBtn = this.wrapper.querySelector('[data-pxr-add-ingredient]');
            if (!this.ingredientContainer || !addBtn) return;

            addBtn.addEventListener('click', () => {
                const template = this.ingredientContainer.querySelector('[data-pxr-ingredient-row]');
                const row = template.cloneNode(true);
                row.querySelectorAll('input, select').forEach(el => { el.value = ''; });
                row.querySelector('[data-pxr-remove-ingredient]').disabled = false;
                this.ingredientContainer.appendChild(row);
                row.querySelector('input[name="ing_name[]"]').focus();
                this.updateRemoveButtons();
            });

            this.ingredientContainer.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-pxr-remove-ingredient]');
                if (btn && this.ingredientContainer.querySelectorAll('[data-pxr-ingredient-row]').length > 1) {
                    btn.closest('[data-pxr-ingredient-row]').remove();
                    this.updateRemoveButtons();
                }
            });
        }

        /* ── Instructions ── */

        initInstructions() {
            this.instructionContainer = this.wrapper.querySelector('[data-pxr-instruction-rows]');
            const addBtn = this.wrapper.querySelector('[data-pxr-add-instruction]');
            if (!this.instructionContainer || !addBtn) return;

            addBtn.addEventListener('click', () => {
                const template = this.instructionContainer.querySelector('[data-pxr-instruction-row]');
                const row = template.cloneNode(true);
                row.querySelectorAll('input, textarea').forEach(el => { el.value = ''; });
                row.querySelector('[data-pxr-remove-instruction]').disabled = false;
                this.instructionContainer.appendChild(row);
                this.renumberSteps();
                row.querySelector('textarea').focus();
                this.updateRemoveButtons();
            });

            this.instructionContainer.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-pxr-remove-instruction]');
                if (btn && this.instructionContainer.querySelectorAll('[data-pxr-instruction-row]').length > 1) {
                    btn.closest('[data-pxr-instruction-row]').remove();
                    this.renumberSteps();
                    this.updateRemoveButtons();
                }
            });
        }

        renumberSteps() {
            this.instructionContainer.querySelectorAll('[data-pxr-step-number]').forEach((el, i) => {
                el.textContent = i + 1;
            });
        }

        updateRemoveButtons() {
            if (this.ingredientContainer) {
                const rows = this.ingredientContainer.querySelectorAll('[data-pxr-ingredient-row]');
                rows.forEach(row => {
                    row.querySelector('[data-pxr-remove-ingredient]').disabled = rows.length <= 1;
                });
            }
            if (this.instructionContainer) {
                const rows = this.instructionContainer.querySelectorAll('[data-pxr-instruction-row]');
                rows.forEach(row => {
                    row.querySelector('[data-pxr-remove-instruction]').disabled = rows.length <= 1;
                });
            }
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
                    this.previewImg.src = '';
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
                this.showMessage('error', `File is too large. Maximum size is ${sizeMB}MB.`);
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
                    this.showMessage('error', `Maximum ${this.maxGalleryImages} gallery images allowed.`);
                    break;
                }
                if (file.size > this.maxFileSize) {
                    this.showMessage('error', `"${file.name}" is too large. Maximum size is ${sizeMB}MB.`);
                    continue;
                }
                this.galleryFiles.push(file);
            }
            this.renderGalleryPreviews();
        }

        renderGalleryPreviews() {
            this.galleryGrid.innerHTML = '';
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
                removeBtn.innerHTML = '&times;';
                item.appendChild(img);
                item.appendChild(removeBtn);
                this.galleryGrid.appendChild(item);
            });
            if (this.galleryPlaceholder) {
                this.galleryPlaceholder.style.display = this.galleryFiles.length > 0 ? 'none' : '';
            }
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
            this.btnText.textContent = loading ? 'Submitting...' : 'Submit Recipe';
            this.btnSpinner.classList.toggle('pxr:hidden', !loading);
        }

        /* ── Validation ── */

        validateForm() {
            const errors = [];
            const val = (name) => {
                const el = this.form.querySelector(`[name="${name}"]`);
                return el ? el.value.trim() : '';
            };

            // Basic tab.
            if (!val('title')) {
                errors.push({ field: 'title', tab: 'basic', message: 'Recipe title is required.' });
            }
            if (!val('description')) {
                errors.push({ field: 'description', tab: 'basic', message: 'Description is required.' });
            }
            // Image (basic tab).
            const fileInput = this.wrapper.querySelector('[data-pxr-file-input]');
            const previewEl = this.wrapper.querySelector('[data-pxr-upload-preview]');
            const previewVisible = previewEl && !previewEl.classList.contains('pxr:hidden');
            if ((!fileInput || !fileInput.files || !fileInput.files.length) && !previewVisible) {
                errors.push({ field: 'recipe_image', tab: 'basic', message: 'Recipe image is required.' });
            }

            // Details tab — categories.
            const checkedCategories = this.form.querySelectorAll('input[name="categories[]"]:checked');
            if (checkedCategories.length === 0) {
                errors.push({ field: 'categories[]', tab: 'details', message: 'Please select at least one category.' });
            }

            // Details tab.
            if (!val('difficulty')) {
                errors.push({ field: 'difficulty', tab: 'details', message: 'Difficulty is required.' });
            }
            const servings = val('servings');
            if (!servings || parseInt(servings, 10) < 1 || parseInt(servings, 10) > 999) {
                errors.push({ field: 'servings', tab: 'details', message: 'Servings must be between 1 and 999.' });
            }
            const prepTime = val('prep_time');
            if (prepTime === '') {
                errors.push({ field: 'prep_time', tab: 'details', message: 'Prep time is required.' });
            } else if (parseInt(prepTime, 10) < 0 || parseInt(prepTime, 10) > 9999) {
                errors.push({ field: 'prep_time', tab: 'details', message: 'Prep time must be 0–9999 minutes.' });
            }
            const cookTime = val('cook_time');
            if (cookTime === '') {
                errors.push({ field: 'cook_time', tab: 'details', message: 'Cook time is required.' });
            } else if (parseInt(cookTime, 10) < 0 || parseInt(cookTime, 10) > 9999) {
                errors.push({ field: 'cook_time', tab: 'details', message: 'Cook time must be 0–9999 minutes.' });
            }
            const restTime = val('rest_time');
            if (restTime !== '' && (parseInt(restTime, 10) < 0 || parseInt(restTime, 10) > 9999)) {
                errors.push({ field: 'rest_time', tab: 'details', message: 'Rest time must be 0–9999 minutes.' });
            }

            // Ingredients tab.
            let hasIngredient = false;
            this.wrapper.querySelectorAll('[data-pxr-ingredient-row]').forEach(row => {
                if (row.querySelector('input[name="ing_name[]"]')?.value.trim()) hasIngredient = true;
            });
            if (!hasIngredient) {
                errors.push({ field: 'ing_name[]', tab: 'ingredients', message: 'At least one ingredient is required.' });
            }

            // Instructions tab.
            let hasInstruction = false;
            this.wrapper.querySelectorAll('[data-pxr-instruction-row]').forEach(row => {
                if (row.querySelector('textarea[name="instructions[]"]')?.value.trim()) hasInstruction = true;
            });
            if (!hasInstruction) {
                errors.push({ field: 'instructions[]', tab: 'instructions', message: 'At least one instruction step is required.' });
            }

            // Extras tab — optional URL validation.
            const urlPattern = /^https?:\/\/.+/i;
            const videoUrl = val('video_url');
            if (videoUrl && !urlPattern.test(videoUrl)) {
                errors.push({ field: 'video_url', tab: 'extras', message: 'Please enter a valid URL (starting with http:// or https://).' });
            }
            const sourceUrl = val('source_url');
            if (sourceUrl && !urlPattern.test(sourceUrl)) {
                errors.push({ field: 'source_url', tab: 'extras', message: 'Please enter a valid URL (starting with http:// or https://).' });
            }

            // Nutrition tab — non-negative values.
            const nutritionFields = ['calories','total_fat','saturated_fat','trans_fat','cholesterol',
                'sodium','total_carbohydrate','dietary_fiber','total_sugars',
                'protein','vitamin_a','vitamin_c','calcium','iron','potassium'];
            nutritionFields.forEach(field => {
                const input = this.form.querySelector(`[name="nutrition_${field}"]`);
                if (input && input.value !== '' && parseFloat(input.value) < 0) {
                    errors.push({ field: `nutrition_${field}`, tab: 'nutrition', message: `${field.replace(/_/g, ' ')} cannot be negative.` });
                }
            });

            // Custom fields tab — required validation.
            const checkedRadioGroups = new Set();
            this.form.querySelectorAll('[name^="custom_field_"]').forEach(input => {
                if (input.type === 'radio') {
                    const name = input.name;
                    if (checkedRadioGroups.has(name)) return;
                    checkedRadioGroups.add(name);
                    if (input.required) {
                        const anyChecked = this.form.querySelector(`input[name="${name}"]:checked`);
                        if (!anyChecked) {
                            const label = input.closest('div')?.parentElement?.querySelector('label')?.textContent?.trim() || name;
                            errors.push({ field: name, tab: 'custom-fields', message: `${label} is required.` });
                        }
                    }
                } else if (input.type === 'checkbox') {
                    // Checkbox groups are optional by default (no required attr on multi-check)
                } else if (input.required && !input.value.trim()) {
                    const label = input.closest('div')?.querySelector('label')?.textContent?.trim() || input.name;
                    errors.push({ field: input.name, tab: 'custom-fields', message: `${label} is required.` });
                }
            });

            return errors;
        }

        showFieldErrors(errors) {
            this.clearFieldErrors();

            const tabsWithErrors = new Set();

            errors.forEach(err => {
                // Special handling: categories multiselect trigger.
                if (err.field === 'categories[]') {
                    const ms = this.wrapper.querySelector('[data-pxr-multiselect]');
                    if (ms) {
                        const trigger = ms.querySelector('[data-pxr-multiselect-trigger]');
                        if (trigger) trigger.classList.add('pxr-custom-form-input--error');
                        const msg = document.createElement('div');
                        msg.className = 'pxr-custom-field-error';
                        msg.textContent = err.message;
                        ms.appendChild(msg);
                    }
                    tabsWithErrors.add(err.tab);
                    return;
                }

                // Special handling: image upload zone.
                if (err.field === 'recipe_image') {
                    const zone = this.wrapper.querySelector('[data-pxr-upload-zone]');
                    if (zone) {
                        zone.classList.add('pxr-custom-form-input--error');
                        const msg = document.createElement('div');
                        msg.className = 'pxr-custom-field-error';
                        msg.textContent = err.message;
                        zone.parentElement.appendChild(msg);
                    }
                    tabsWithErrors.add(err.tab);
                    return;
                }

                // Default handling.
                const el = this.form.querySelector(`[name="${err.field}"]`);
                if (el) {
                    el.classList.add('pxr-custom-form-input--error');
                    // Insert inline error message after the input.
                    const msg = document.createElement('div');
                    msg.className = 'pxr-custom-field-error';
                    msg.textContent = err.message;
                    el.parentElement.appendChild(msg);
                }
                tabsWithErrors.add(err.tab);
            });

            // Mark tabs that have errors.
            tabsWithErrors.forEach(tab => {
                const tabBtn = this.wrapper.querySelector(`[data-pxr-tab="${tab}"]`);
                if (tabBtn) tabBtn.classList.add('pxr-custom-form-tab--has-error');
            });

            // Navigate to the first error's tab.
            if (errors.length > 0) {
                const firstTab = this.wrapper.querySelector(`[data-pxr-tab="${errors[0].tab}"]`);
                if (firstTab) firstTab.click();

                const firstField = this.form.querySelector(`[name="${errors[0].field}"]`);
                if (firstField) {
                    firstField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstField.focus();
                }

                const count = errors.length;
                this.showMessage('error', `Please fix ${count} error${count > 1 ? 's' : ''} before submitting.`);
            }
        }

        clearFieldErrors() {
            this.wrapper.querySelectorAll('.pxr-custom-form-input--error').forEach(el => {
                el.classList.remove('pxr-custom-form-input--error');
            });
            this.wrapper.querySelectorAll('.pxr-custom-field-error').forEach(el => el.remove());
            this.wrapper.querySelectorAll('.pxr-custom-form-tab--has-error').forEach(el => {
                el.classList.remove('pxr-custom-form-tab--has-error');
            });
            // Clear special elements (upload zone, multiselect trigger).
            this.wrapper.querySelectorAll('[data-pxr-upload-zone].pxr-custom-form-input--error').forEach(el => {
                el.classList.remove('pxr-custom-form-input--error');
            });
            this.wrapper.querySelectorAll('[data-pxr-multiselect-trigger].pxr-custom-form-input--error').forEach(el => {
                el.classList.remove('pxr-custom-form-input--error');
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

            // Collect structured ingredients as JSON (qty + unit + name + notes).
            const ingredients = [];
            this.wrapper.querySelectorAll('[data-pxr-ingredient-row]').forEach(row => {
                const name = row.querySelector('input[name="ing_name[]"]')?.value.trim();
                if (!name) return;
                ingredients.push({
                    quantity: row.querySelector('input[name="ing_qty[]"]')?.value.trim() || '',
                    unit:     row.querySelector('select[name="ing_unit[]"]')?.value || '',
                    name,
                    notes:    row.querySelector('input[name="ing_notes[]"]')?.value.trim() || '',
                });
            });

            // Collect instructions as JSON (title + text).
            const instructions = [];
            this.wrapper.querySelectorAll('[data-pxr-instruction-row]').forEach(row => {
                const text = row.querySelector('textarea[name="instructions[]"]')?.value.trim();
                if (!text) return;
                instructions.push({
                    title:            row.querySelector('input[name="inst_titles[]"]')?.value.trim() || '',
                    instruction_text: text,
                });
            });

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
            formData.delete('ing_qty[]');
            formData.delete('ing_unit[]');
            formData.delete('ing_name[]');
            formData.delete('ing_notes[]');
            formData.delete('instructions[]');
            formData.delete('inst_titles[]');
            formData.delete('nutrition_serving_size');
            nutritionFields.forEach(f => formData.delete(nutritionPrefix + f));
            this.form.querySelectorAll('[name^="custom_field_"]').forEach(input => {
                formData.delete(input.name);
            });

            formData.append('ingredients', JSON.stringify(ingredients));
            formData.append('instructions', JSON.stringify(instructions));
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

            fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'X-WP-Nonce': this.nonce },
                body: formData,
                credentials: 'same-origin',
            })
            .then(res => res.json().then(json => ({ ok: res.ok, data: json })))
            .then(result => {
                this.setLoading(false);
                if (result.ok && result.data.success !== false) {
                    const msg = result.data.message || 'Your recipe has been submitted successfully!';
                    this.showMessage('success', msg);
                    this.resetForm();

                    // Switch to first tab
                    const firstTab = this.wrapper.querySelector('[data-pxr-tab]');
                    if (firstTab) firstTab.click();

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
                        : 'An error occurred. Please try again.';
                    this.showMessage('error', msg);
                }
            })
            .catch(() => {
                this.setLoading(false);
                this.showMessage('error', 'Network error. Please check your connection and try again.');
            });
        }

        resetForm() {
            this.form.reset();
            this.clearFieldErrors();

            // Reset image preview.
            if (this.previewWrap) {
                this.previewWrap.classList.add('pxr:hidden');
                this.previewImg.src = '';
                const zone = this.wrapper.querySelector('[data-pxr-upload-zone]');
                if (zone) zone.classList.remove('has-preview');
            }

            // Reset dynamic rows to single row.
            if (this.ingredientContainer) {
                while (this.ingredientContainer.querySelectorAll('[data-pxr-ingredient-row]').length > 1) {
                    this.ingredientContainer.lastElementChild.remove();
                }
            }
            if (this.instructionContainer) {
                while (this.instructionContainer.querySelectorAll('[data-pxr-instruction-row]').length > 1) {
                    this.instructionContainer.lastElementChild.remove();
                }
                this.renumberSteps();
            }
            this.updateRemoveButtons();

            // Reset multiselect tags.
            this.wrapper.querySelectorAll('[data-pxr-multiselect-tags]').forEach(t => { t.innerHTML = ''; });
            this.wrapper.querySelectorAll('[data-pxr-multiselect-placeholder]').forEach(p => { p.style.display = ''; });

            // Reset gallery.
            if (this.galleryFiles) {
                this.galleryFiles = [];
                if (this.galleryGrid) this.galleryGrid.innerHTML = '';
                if (this.galleryPlaceholder) this.galleryPlaceholder.style.display = '';
            }
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
