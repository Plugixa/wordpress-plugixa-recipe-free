/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Review Form
 *
 * Enqueued as the handle "plugixa-recipe-review-form" by templates/review-form.php.
 * Translated strings arrive via wp_localize_script() as plugixaRecipeReviewL10n.
 *
 * This was previously an inline <script> in the template, keyed to a per-instance
 * element id. It now discovers every review form in the document and initialises
 * each independently, so multiple forms on one page work without the id round-trip.
 */

(function () {
    'use strict';

    var L10N = window.plugixaRecipeReviewL10n || {};

    function t(key, fallback) {
        return Object.prototype.hasOwnProperty.call(L10N, key) ? L10N[key] : fallback;
    }

    function initReviewForm(form) {
        var formWrapper = form.closest('.pxr-custom-review-form-wrapper');
        if (!formWrapper) return;

        var recipeId    = form.getAttribute('data-pxr-recipe-id');
        var restUrl     = form.getAttribute('data-pxr-rest-url');
        var nonce       = form.getAttribute('data-pxr-nonce');
        var ratingInput = form.querySelector('[data-pxr-rating-value]');
        var starButtons = form.querySelectorAll('[data-pxr-star-value]');
        var submitBtn   = form.querySelector('[data-pxr-review-submit]');
        var btnText     = submitBtn ? submitBtn.querySelector('.pxr-custom-btn__text') : null;
        var btnSpinner  = submitBtn ? submitBtn.querySelector('.pxr-custom-btn__spinner') : null;
        var messagesEl  = formWrapper.querySelector('[data-pxr-review-messages]');
        var successEl   = formWrapper.querySelector('[data-pxr-review-success]');
        var errorEl     = formWrapper.querySelector('[data-pxr-review-error]');

        var currentRating = 0;
        var selectedFiles = [];

        // Image upload handling
        var imageInput   = form.querySelector('[data-pxr-review-images-input]');
        var imagePreview = formWrapper.querySelector('[data-pxr-review-images-preview]');
        var imageTrigger = formWrapper.querySelector('[data-pxr-review-images-trigger]');
        var maxFiles     = 3;

        if (imageInput) {
            maxFiles = parseInt(imageInput.getAttribute('data-max-files'), 10) || 3;
            var maxSize = (parseInt(imageInput.getAttribute('data-max-size'), 10) || 5) * 1024 * 1024;

            imageInput.addEventListener('change', function () {
                var newFiles  = Array.from(this.files);
                var remaining = maxFiles - selectedFiles.length;
                if (remaining <= 0) {
                    showMessage('error', t('maxImages', 'Maximum number of images reached.'));
                    this.value = '';
                    return;
                }

                newFiles.slice(0, remaining).forEach(function (file) {
                    if (file.size > maxSize) return;
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
                    selectedFiles.push(file);
                    addImagePreview(file, selectedFiles.length - 1);
                });

                this.value = '';
                updateTriggerVisibility();
            });
        }

        function addImagePreview(file, index) {
            if (!imagePreview) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                var wrapper = document.createElement('div');
                wrapper.className = 'pxr-review-image-thumb pxr:relative pxr:w-20 pxr:h-20 pxr:rounded-lg pxr:overflow-hidden pxr:border';
                wrapper.setAttribute('data-pxr-image-index', index);

                var img = document.createElement('img');
                img.src = e.target.result;
                img.alt = '';
                img.className = 'pxr:w-full pxr:h-full pxr:object-cover';

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'pxr-review-image-remove pxr:absolute pxr:top-0.5 pxr:right-0.5 pxr:w-5 pxr:h-5 pxr:rounded-full pxr:border-0 pxr:cursor-pointer pxr:flex pxr:items-center pxr:justify-center pxr:text-xs pxr:leading-none';
                removeBtn.style.background = 'rgba(0,0,0,.6)';
                removeBtn.style.color = '#fff';
                removeBtn.setAttribute('aria-label', t('remove', 'Remove'));
                removeBtn.textContent = '×';

                removeBtn.addEventListener('click', function () {
                    var idx = parseInt(wrapper.getAttribute('data-pxr-image-index'), 10);
                    selectedFiles[idx] = null;
                    wrapper.remove();
                    updateTriggerVisibility();
                });

                wrapper.appendChild(img);
                wrapper.appendChild(removeBtn);
                imagePreview.appendChild(wrapper);
            };
            reader.readAsDataURL(file);
        }

        function updateTriggerVisibility() {
            if (!imageTrigger) return;
            var activeCount = selectedFiles.filter(function (f) { return f !== null; }).length;
            imageTrigger.style.display = activeCount >= maxFiles ? 'none' : '';
        }

        // Star rating interaction
        starButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentRating = parseInt(this.getAttribute('data-pxr-star-value'), 10);
                ratingInput.value = currentRating;
                updateStars(currentRating);
            });

            btn.addEventListener('mouseenter', function () {
                highlightStars(parseInt(this.getAttribute('data-pxr-star-value'), 10));
            });

            btn.addEventListener('mouseleave', function () {
                highlightStars(currentRating);
            });
        });

        function updateStars(value) {
            starButtons.forEach(function (btn) {
                var starValue  = parseInt(btn.getAttribute('data-pxr-star-value'), 10);
                var svg        = btn.querySelector('svg');
                var isSelected = starValue <= value;
                btn.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                svg.classList.toggle('pxr-custom-star--full', isSelected);
                svg.classList.toggle('pxr-custom-star--empty', !isSelected);
            });
        }

        function highlightStars(value) {
            starButtons.forEach(function (btn) {
                var starValue = parseInt(btn.getAttribute('data-pxr-star-value'), 10);
                var svg       = btn.querySelector('svg');
                svg.classList.toggle('pxr-custom-star--full', starValue <= value);
                svg.classList.toggle('pxr-custom-star--empty', starValue > value);
            });
        }

        function showMessage(type, text) {
            messagesEl.classList.remove('pxr:hidden');
            if (type === 'success') {
                successEl.classList.remove('pxr:hidden');
                errorEl.classList.add('pxr:hidden');
            } else {
                errorEl.textContent = text;
                errorEl.classList.remove('pxr:hidden');
                successEl.classList.add('pxr:hidden');
            }
        }

        function setLoading(loading) {
            if (submitBtn) submitBtn.disabled = loading;
            if (btnText) {
                btnText.textContent = loading
                    ? t('submitting', 'Submitting...')
                    : t('submit', 'Submit Review');
            }
            if (btnSpinner) btnSpinner.classList.toggle('pxr:hidden', !loading);
        }

        // Form submission
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Client-side validation
            var ratingRequired = form.hasAttribute('data-pxr-rating-required');
            if (ratingRequired && !currentRating) {
                showMessage('error', t('selectRating', 'Please select a rating.'));
                return;
            }

            var authorName = form.querySelector('[name="author_name"]').value.trim();
            if (!authorName) {
                showMessage('error', t('nameRequired', 'Name is required.'));
                return;
            }

            var authorEmail = form.querySelector('[name="author_email"]').value.trim();
            if (!authorEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(authorEmail)) {
                showMessage('error', t('invalidEmail', 'Please enter a valid email address.'));
                return;
            }

            var reviewText = form.querySelector('[name="review_text"]').value.trim();
            if (!reviewText || reviewText.length < 10) {
                showMessage('error', t('reviewTooShort', 'Review must be at least 10 characters.'));
                return;
            }

            // Build FormData to support image uploads.
            var submitData = new FormData();
            submitData.append('recipe_id', recipeId);
            submitData.append('rating', currentRating);
            submitData.append('author_name', authorName);
            submitData.append('author_email', authorEmail);
            submitData.append('review_text', reviewText);
            submitData.append('_wpnonce', nonce);

            // Append selected images.
            selectedFiles.forEach(function (file) {
                if (file) submitData.append('review_images[]', file);
            });

            // Turnstile writes its token into a field named turnstile_token (see
            // data-response-field-name on the widget). This form builds its FormData by
            // hand rather than from the <form>, so the field is copied across explicitly.
            // When the captcha is off the field is absent and an empty value is sent: the
            // server decides, and it fails closed.
            var captchaField = form.querySelector('[name="turnstile_token"]');
            if (captchaField) {
                submitData.append('turnstile_token', captchaField.value);
            }

            submitReview(submitData);
        });

        function submitReview(formPayload) {
            setLoading(true);
            messagesEl.classList.add('pxr:hidden');

            fetch(restUrl, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                body: formPayload,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { ok: response.ok, data: json };
                    });
                })
                .then(function (result) {
                    setLoading(false);
                    if (result.ok) {
                        showMessage('success');
                        form.reset();
                        currentRating = 0;
                        updateStars(0);
                        selectedFiles = [];
                        if (imagePreview) imagePreview.innerHTML = '';
                        updateTriggerVisibility();
                    } else {
                        var msg = (result.data && result.data.message)
                            ? result.data.message
                            : t('genericError', 'An error occurred. Please try again.');
                        showMessage('error', msg);
                    }
                })
                .catch(function () {
                    setLoading(false);
                    showMessage('error', t('networkError', 'Network error. Please check your connection and try again.'));
                });
        }
    }

    function init() {
        document.querySelectorAll('[data-pxr-review-form]').forEach(initReviewForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
