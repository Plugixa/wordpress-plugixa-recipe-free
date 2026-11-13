/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * PlugixaRecipe Frontend Scripts
 *
 * Handles: category filtering, Load More pagination, and Swiper slider initialization.
 */
(function () {
    'use strict';

    /* ─── Unified Multi-Filter System (AND logic) ── */

    /**
     * Apply all active filters on a container using AND logic.
     * A recipe item is shown only if it matches ALL active filters.
     */
    function applyAllFilters(container) {
        var items = container.querySelectorAll('.pxr-custom-grid__item, .pxr-custom-list__item, .pxr-custom-masonry__item');

        // Get active category (button or dropdown)
        var catSelect = container.querySelector('[data-pxr-category-select]');
        var activeCatBtn = container.querySelector('.pxr-custom-category-btn--active');
        var activeCategory = catSelect ? catSelect.value : (activeCatBtn ? activeCatBtn.getAttribute('data-pxr-category') : 'all');

        // Get active cuisine
        var activeCuiBtn = container.querySelector('.pxr-custom-cuisine-btn--active');
        var activeCuisine = activeCuiBtn ? activeCuiBtn.getAttribute('data-pxr-cuisine') : 'all';

        // Get active letter
        var activeAlphaBtn = container.querySelector('.pxr-custom-alpha-btn--active');
        var activeLetter = activeAlphaBtn ? activeAlphaBtn.getAttribute('data-pxr-letter') : 'all';

        // Get ingredient search query
        var ingredientInput = container.querySelector('.pxr-custom-ingredient-search');
        var ingredientQuery = ingredientInput ? ingredientInput.value.trim().toLowerCase() : '';

        items.forEach(function (item) {
            var visible = true;

            // Category filter
            if (activeCategory !== 'all') {
                var cats = (item.getAttribute('data-pxr-categories') || '').split(',');
                if (cats.indexOf(activeCategory) === -1) visible = false;
            }

            // Cuisine filter
            if (visible && activeCuisine !== 'all') {
                var cuisines = (item.getAttribute('data-pxr-cuisines') || '').split(',');
                if (cuisines.indexOf(activeCuisine) === -1) visible = false;
            }

            // Alphabetical filter
            if (visible && activeLetter !== 'all') {
                var title = (item.getAttribute('data-pxr-title') || '').trim();
                var firstLetter = title.charAt(0).toUpperCase();
                if (firstLetter !== activeLetter) visible = false;
            }

            // Ingredient search filter
            if (visible && ingredientQuery) {
                var ingredients = (item.getAttribute('data-pxr-ingredients') || '').toLowerCase();
                if (ingredients.indexOf(ingredientQuery) === -1) visible = false;
            }

            if (visible) {
                item.removeAttribute('data-pxr-hidden');
                item.style.display = '';
            } else {
                item.setAttribute('data-pxr-hidden', '');
                item.style.display = 'none';
            }
        });
    }

    /**
     * Generic filter nav initializer — binds click on nav buttons,
     * updates active state, then calls applyAllFilters.
     */
    function initFilterNav(navSelector, btnSelector, activeClass, dataAttr) {
        document.querySelectorAll(navSelector).forEach(function (nav) {
            var container = nav.closest('.pxr-custom-container');
            if (!container) return;

            var buttons = nav.querySelectorAll(btnSelector);
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    // Update active state
                    buttons.forEach(function (b) {
                        b.classList.remove(activeClass);
                        b.setAttribute('aria-pressed', 'false');
                    });
                    btn.classList.add(activeClass);
                    btn.setAttribute('aria-pressed', 'true');

                    applyAllFilters(container);
                });
            });
        });
    }

    function initCategoryFilters() {
        initFilterNav(
            '.pxr-custom-category-nav',
            '.pxr-custom-category-btn',
            'pxr-custom-category-btn--active',
            'data-pxr-category'
        );

        // Dropdown variant
        document.querySelectorAll('[data-pxr-category-select]').forEach(function (select) {
            var container = select.closest('.pxr-custom-container');
            if (!container) return;

            select.addEventListener('change', function () {
                // Sync the hidden active state for applyAllFilters
                container.pxrActiveCategory = select.value;
                applyAllFilters(container);
            });
        });
    }

    function initCuisineFilters() {
        initFilterNav(
            '.pxr-custom-cuisine-nav',
            '.pxr-custom-cuisine-btn',
            'pxr-custom-cuisine-btn--active',
            'data-pxr-cuisine'
        );
    }

    function initAlphaFilters() {
        initFilterNav(
            '.pxr-custom-alpha-nav',
            '.pxr-custom-alpha-btn:not([disabled])',
            'pxr-custom-alpha-btn--active',
            'data-pxr-letter'
        );
    }

    function initIngredientFilter() {
        document.querySelectorAll('.pxr-custom-ingredient-search').forEach(function (input) {
            var container = input.closest('.pxr-custom-container');
            if (!container) return;

            var debounceTimer;
            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    applyAllFilters(container);
                }, 300);
            });
        });
    }

    /* ─── Requests from a page that may be days old ── */

    // A listing is expected to sit in a page cache, so everything on it has to work on a
    // page that is days old. Two things on such a page have expired: the nonce printed
    // for "load more" (admin-ajax then answers 403), and - for a signed-out visitor - any
    // REST nonce, which is why the page no longer carries one (see restHeaders()).

    /**
     * Headers for a REST request: the nonce only when the page has one.
     *
     * PHP prints a REST nonce for a signed-in user and an empty string for a visitor
     * (plugixa_recipe_rest_nonce()). A visitor needs none on a public route, and a stale or
     * EMPTY X-WP-Nonce is not ignored by WordPress - it is answered with 403 before the
     * route runs. So the header is left out entirely when there is nothing to send.
     */
    function restHeaders(headers) {
        headers = headers || {};
        if (typeof plugixaRecipeConfig !== 'undefined' && plugixaRecipeConfig.restNonce) {
            headers['X-WP-Nonce'] = plugixaRecipeConfig.restNonce;
        }
        return headers;
    }

    var freshListingNonce = null;

    function listingAjaxUrl() {
        return (typeof plugixaRecipeConfig !== 'undefined' && plugixaRecipeConfig.ajaxUrl)
            ? plugixaRecipeConfig.ajaxUrl
            : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    }

    function listingText(key, fallback) {
        return (typeof plugixaRecipeConfig !== 'undefined' && plugixaRecipeConfig.i18n && plugixaRecipeConfig.i18n[key])
            ? plugixaRecipeConfig.i18n[key]
            : fallback;
    }

    function listingErrorText() {
        return listingText('error', 'An error occurred while loading recipes.');
    }

    function postListingPage(configKey, page, nonce) {
        var formData = new FormData();
        formData.append('action', 'plugixa_recipe_load_recipes');
        formData.append('nonce', nonce);
        formData.append('page', page);
        formData.append('config_key', configKey);

        return fetch(listingAjaxUrl(), { method: 'POST', body: formData, credentials: 'same-origin' });
    }

    function fetchFreshListingNonce() {
        var url = listingAjaxUrl();
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'action=plugixa_recipe_fresh_nonce';

        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (!answer || !answer.success || !answer.data || !answer.data.nonce) {
                    throw new Error('no nonce');
                }
                freshListingNonce = answer.data.nonce;
                return freshListingNonce;
            });
    }

    /**
     * One page of a listing: the single request path for "load more", the page numbers
     * and infinite scroll.
     *
     * When the page's own nonce is refused the request asks for a current one and is sent
     * once more; that nonce is kept for the rest of the visit. Resolves with
     * { html, has_more }. Every refusal REJECTS, so each caller can say something: these
     * controls used to do nothing at all when the server said no.
     */
    function loadListingPage(configKey, page, pageNonce) {
        return postListingPage(configKey, page, freshListingNonce || pageNonce)
            .then(function (response) {
                if (response.status !== 403) return response;
                return fetchFreshListingNonce().then(function (nonce) {
                    return postListingPage(configKey, page, nonce);
                });
            })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (!answer || !answer.success || !answer.data) {
                    throw new Error('refused');
                }
                return answer.data;
            });
    }

    function appendListingItems(grid, html) {
        var temp = document.createElement('div');
        temp.innerHTML = html || '';
        temp.querySelectorAll('.pxr-custom-grid__item, .pxr-custom-masonry__item, .pxr-custom-list__item').forEach(function (item) {
            grid.appendChild(item);
        });
        initVideoCards();
    }

    /** A line under a control that failed, for the controls that have no spinner to speak through. */
    function showListingError(control) {
        var note = control.parentNode.querySelector('.pxr-listing-error');
        if (!note) {
            note = document.createElement('p');
            note.className = 'pxr-listing-error';
            note.setAttribute('role', 'alert');
            note.style.textAlign = 'center';
            note.style.margin = '12px 0 0';
            control.parentNode.insertBefore(note, control.nextSibling);
        }
        note.textContent = listingErrorText();
        note.style.display = '';
    }

    function clearListingError(control) {
        var note = control.parentNode.querySelector('.pxr-listing-error');
        if (note) note.style.display = 'none';
    }

    /* ─── Load More Pagination ─────────────────────── */
    function initLoadMore() {
        document.querySelectorAll('.pxr-load-more').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var pagination = btn.closest('.pxr-pagination');
                if (!pagination) return;

                var configKey = pagination.getAttribute('data-config-key');
                var nonce = btn.getAttribute('data-nonce');
                var page = parseInt(btn.getAttribute('data-page'), 10);
                var spinner = pagination.querySelector('.pxr-loading-spinner');
                var wrapper = pagination.closest('.pxr-recipes-wrapper');
                var grid = wrapper ? wrapper.querySelector('.pxr-custom-grid, .pxr-custom-masonry, .pxr-custom-list') : null;

                if (!grid || !configKey) return;

                // The message stays next to the button after a failure, so the visitor can
                // read it and try again. (It used to be written and hidden in the same tick.)
                var failed = false;

                btn.disabled = true;
                if (spinner) {
                    spinner.textContent = listingText('loading', 'Loading...');
                    spinner.style.display = 'inline';
                }

                loadListingPage(configKey, page, nonce)
                .then(function (data) {
                    appendListingItems(grid, data.html);

                    btn.setAttribute('data-page', page + 1);

                    if (!data.has_more) {
                        btn.style.display = 'none';
                        if (spinner) {
                            spinner.textContent = listingText('noMore', 'No more recipes to load.');
                            spinner.style.display = 'inline';
                        }
                    }
                })
                .catch(function () {
                    failed = true;
                    if (spinner) {
                        spinner.textContent = listingErrorText();
                        spinner.style.display = 'inline';
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                    if (spinner && !failed && btn.style.display !== 'none') {
                        spinner.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ─── Numbers Pagination (AJAX page replace) ──── */
    function initNumbersPagination() {
        document.querySelectorAll('.pxr-custom-pagination').forEach(function (nav) {
            nav.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-pxr-page]');
                if (!btn) return;
                e.preventDefault();

                var page = parseInt(btn.getAttribute('data-pxr-page'), 10);
                var configKey = nav.getAttribute('data-config-key');
                var nonce = nav.getAttribute('data-nonce');
                var wrapper = nav.closest('.pxr-recipes-wrapper');
                var grid = wrapper ? wrapper.querySelector('.pxr-custom-grid, .pxr-custom-masonry, .pxr-custom-list') : null;

                if (!grid || !configKey || !page) return;

                // Loading state
                nav.style.opacity = '0.5';
                nav.style.pointerEvents = 'none';
                clearListingError(nav);

                loadListingPage(configKey, page, nonce)
                .then(function (data) {
                    // Replace grid contents
                    grid.innerHTML = '';
                    appendListingItems(grid, data.html);

                    // Update active page button
                    var totalPages = parseInt(nav.getAttribute('data-total-pages'), 10) || 1;
                    updatePaginationButtons(nav, page, totalPages);

                    // Scroll to top of grid
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                })
                .catch(function () {
                    // The page shown stays as it is, and says why nothing happened.
                    showListingError(nav);
                })
                .finally(function () {
                    nav.style.opacity = '1';
                    nav.style.pointerEvents = '';
                });
            });
        });
    }

    function updatePaginationButtons(nav, currentPage, totalPages) {
        nav.innerHTML = '';

        // Prev button
        if (currentPage > 1) {
            var prev = document.createElement('a');
            prev.href = '#';
            prev.className = 'pxr-custom-pagination__btn pxr-custom-pagination__btn--prev pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200';
            prev.setAttribute('data-pxr-page', currentPage - 1);
            prev.setAttribute('aria-label', 'Previous page');
            prev.innerHTML = '<svg class="pxr:w-5 pxr:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>';
            nav.appendChild(prev);
        }

        // Page numbers
        for (var i = 1; i <= totalPages; i++) {
            if (i === currentPage) {
                var span = document.createElement('span');
                span.className = 'pxr-custom-pagination__btn pxr-custom-pagination__btn--current pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:font-semibold';
                span.setAttribute('aria-current', 'page');
                span.textContent = i;
                nav.appendChild(span);
            } else {
                var a = document.createElement('a');
                a.href = '#';
                a.className = 'pxr-custom-pagination__btn pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200';
                a.setAttribute('data-pxr-page', i);
                a.textContent = i;
                nav.appendChild(a);
            }
        }

        // Next button
        if (currentPage < totalPages) {
            var next = document.createElement('a');
            next.href = '#';
            next.className = 'pxr-custom-pagination__btn pxr-custom-pagination__btn--next pxr:inline-flex pxr:items-center pxr:justify-center pxr:w-10 pxr:h-10 pxr:rounded-full pxr:border pxr:transition-colors pxr:duration-200';
            next.setAttribute('data-pxr-page', currentPage + 1);
            next.setAttribute('aria-label', 'Next page');
            next.innerHTML = '<svg class="pxr:w-5 pxr:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
            nav.appendChild(next);
        }
    }

    /* ─── Infinite Scroll Pagination ────────────────── */
    function initInfiniteScroll() {
        document.querySelectorAll('.pxr-pagination--infinite').forEach(function (pagination) {
            var sentinel = pagination.querySelector('.pxr-infinite-sentinel');
            if (!sentinel) return;

            var configKey = pagination.getAttribute('data-config-key');
            var nonce = sentinel.getAttribute('data-nonce');
            var wrapper = pagination.closest('.pxr-recipes-wrapper');
            var grid = wrapper ? wrapper.querySelector('.pxr-custom-grid, .pxr-custom-masonry, .pxr-custom-list') : null;
            var spinner = pagination.querySelector('.pxr-loading-spinner');
            var loading = false;
            var page = parseInt(sentinel.getAttribute('data-page'), 10) || 2;
            var hasMore = true;

            if (!grid || !configKey) return;

            var observer = new IntersectionObserver(function (entries) {
                if (!entries[0].isIntersecting || loading || !hasMore) return;
                var failed = false;

                loading = true;
                if (spinner) spinner.style.display = 'inline';

                loadListingPage(configKey, page, nonce)
                .then(function (data) {
                    appendListingItems(grid, data.html);
                    page++;

                    if (!data.has_more) {
                        hasMore = false;
                        observer.disconnect();
                        pagination.style.display = 'none';
                    }
                })
                .catch(function () {
                    // Stop asking, and leave the reason where the next recipes would be.
                    failed = true;
                    hasMore = false;
                    observer.disconnect();
                    if (spinner) {
                        spinner.textContent = listingErrorText();
                        spinner.style.display = 'inline';
                    }
                })
                .finally(function () {
                    loading = false;
                    if (spinner && !failed) spinner.style.display = 'none';
                });
            }, { rootMargin: '200px' });

            observer.observe(sentinel);
        });
    }

    /* ─── Swiper 12 Slider Initialization ─────────── */
    var SINGLE_SLIDE_EFFECTS = ['fade', 'cube', 'flip', 'cards', 'creative'];

    function initSliders() {
        if (typeof Swiper === 'undefined') return;

        document.querySelectorAll('.pxr-custom-slider').forEach(function (el) {
            var d = el.dataset;
            var effect = d.pxrEffect || 'slide';
            var isSingleSlide = SINGLE_SLIDE_EFFECTS.indexOf(effect) !== -1;

            var slidesDesktop = parseInt(d.pxrSlidesDesktop, 10) || 3;
            var slidesTablet  = parseInt(d.pxrSlidesTablet, 10) || 2;
            var slidesMobile  = parseInt(d.pxrSlidesMobile, 10) || 1;

            // Count actual slides and cap slidesPerView to prevent empty space
            var slideCount = el.querySelectorAll('.swiper-slide').length;
            if (slideCount === 0) return;
            slidesDesktop = Math.min(slidesDesktop, slideCount);
            slidesTablet  = Math.min(slidesTablet, slideCount);
            slidesMobile  = Math.min(slidesMobile, slideCount);

            var config = {
                slidesPerView: isSingleSlide ? 1 : slidesMobile,
                spaceBetween: parseInt(d.pxrSpaceBetween, 10) || 24,
                speed: parseInt(d.pxrSpeed, 10) || 400,
                loop: d.pxrLoop === 'true' && slideCount >= slidesDesktop + 1,
                grabCursor: true,

                // A11y — always enabled
                a11y: {
                    prevSlideMessage: 'Previous slide',
                    nextSlideMessage: 'Next slide',
                    paginationBulletMessage: 'Go to slide {{index}}',
                },
            };

            // Responsive breakpoints (skip for single-slide effects)
            if (!isSingleSlide) {
                config.breakpoints = {
                    640:  { slidesPerView: slidesTablet },
                    1024: { slidesPerView: slidesDesktop },
                };
            }

            // Effect
            if (effect !== 'slide') {
                config.effect = effect;
                if (effect === 'fade') {
                    config.fadeEffect = { crossFade: true };
                } else if (effect === 'coverflow') {
                    config.coverflowEffect = { rotate: 30, stretch: 0, depth: 100, modifier: 1, slideShadows: true };
                } else if (effect === 'creative') {
                    config.creativeEffect = {
                        prev: { shadow: true, translate: [0, 0, -400] },
                        next: { translate: ['100%', 0, 0] },
                    };
                }
            }

            // Navigation (built-in module)
            if (d.pxrArrows === 'true') {
                config.navigation = {
                    nextEl: el.querySelector('.swiper-button-next'),
                    prevEl: el.querySelector('.swiper-button-prev'),
                };
            }

            // Pagination (built-in module)
            if (d.pxrDots === 'true') {
                var pagStyle = d.pxrPaginationStyle || 'bullets';
                var pagConfig = {
                    el: el.querySelector('.swiper-pagination'),
                    clickable: true,
                };
                if (pagStyle === 'dynamic') {
                    pagConfig.type = 'bullets';
                    pagConfig.dynamicBullets = true;
                } else if (pagStyle === 'fraction') {
                    pagConfig.type = 'fraction';
                } else if (pagStyle === 'progressbar') {
                    pagConfig.type = 'progressbar';
                } else {
                    pagConfig.type = 'bullets';
                }
                config.pagination = pagConfig;
            }

            // Autoplay
            if (d.pxrAutoplay === 'true') {
                config.autoplay = {
                    delay: parseInt(d.pxrAutoplayDelay, 10) || 5000,
                    disableOnInteraction: false,
                    pauseOnMouseEnter: d.pxrPauseOnHover === 'true',
                };
            }

            // Keyboard
            if (d.pxrKeyboard === 'true') {
                config.keyboard = { enabled: true, onlyInViewport: true };
            }

            // Free Mode
            if (d.pxrFreeMode === 'true') {
                config.freeMode = { enabled: true };
            }

            new Swiper(el, config);
        });
    }

    /* ─── Fraction Helpers ────────────────────────── */

    /**
     * Parse a fraction/mixed-number string into a number.
     * Handles: "3", "2.5", "1/2", "1 1/2", "1/4"
     */
    function parseFraction(str) {
        if (!str || typeof str !== 'string') return NaN;
        str = str.trim();
        if (!str) return NaN;

        // Pure decimal / integer
        if (/^[\d.]+$/.test(str)) return parseFloat(str);

        // Mixed number: "1 1/2"
        var mixedMatch = str.match(/^(\d+)\s+(\d+)\/(\d+)$/);
        if (mixedMatch) {
            return parseInt(mixedMatch[1], 10) + parseInt(mixedMatch[2], 10) / parseInt(mixedMatch[3], 10);
        }

        // Simple fraction: "1/2"
        var fracMatch = str.match(/^(\d+)\/(\d+)$/);
        if (fracMatch) {
            return parseInt(fracMatch[1], 10) / parseInt(fracMatch[2], 10);
        }

        return NaN;
    }

    /**
     * Format a number back to a friendly display string.
     * Whole numbers → "2", common fractions → "1/2", mixed → "1 1/2",
     * others → rounded to 2 decimals.
     */
    function formatAmount(num) {
        if (isNaN(num) || num <= 0) return '';

        var whole = Math.floor(num);
        var frac = num - whole;

        // Common fraction map (tolerance ±0.01)
        var fractions = [
            [1 / 8, '1/8'], [1 / 4, '1/4'], [1 / 3, '1/3'],
            [3 / 8, '3/8'], [1 / 2, '1/2'], [5 / 8, '5/8'],
            [2 / 3, '2/3'], [3 / 4, '3/4'], [7 / 8, '7/8']
        ];

        // Whole number
        if (frac < 0.01) return String(whole);

        // Close to next whole number
        if (frac > 0.99) return String(whole + 1);

        // Try to match a common fraction
        for (var i = 0; i < fractions.length; i++) {
            if (Math.abs(frac - fractions[i][0]) < 0.01) {
                return whole > 0 ? whole + ' ' + fractions[i][1] : fractions[i][1];
            }
        }

        // Fallback: round to 2 decimals
        var rounded = Math.round(num * 100) / 100;
        return rounded === Math.floor(rounded) ? String(Math.floor(rounded)) : String(rounded);
    }

    /* ─── Servings Adjuster ──────────────────────── */
    function initServingsAdjuster() {
        document.querySelectorAll('[data-pxr-servings-adjuster]').forEach(function (container) {
            var originalServings = parseInt(container.getAttribute('data-pxr-original-servings'), 10);
            if (!originalServings) return;

            var input      = container.querySelector('[data-pxr-servings-input]');
            var decreaseBtn = container.querySelector('[data-pxr-servings-decrease]');
            var increaseBtn = container.querySelector('[data-pxr-servings-increase]');
            if (!input) return;

            // Find the recipe root to scope ingredient lookup
            var recipeRoot = container.closest('.pxr-custom-single');
            if (!recipeRoot) return;

            // Store original amounts once
            var ingredientSpans = recipeRoot.querySelectorAll('[data-pxr-amount]');
            var originals = [];
            ingredientSpans.forEach(function (span) {
                var amountEl = span.querySelector('[data-pxr-ingredient-amount]');
                var raw = span.getAttribute('data-pxr-amount');
                originals.push({
                    span: span,
                    amountEl: amountEl,
                    original: raw,
                    parsed: parseFraction(raw)
                });
            });

            function updateAmounts(newServings) {
                var ratio = newServings / originalServings;
                originals.forEach(function (item) {
                    if (!item.amountEl || isNaN(item.parsed)) return;
                    var scaled = item.parsed * ratio;
                    item.amountEl.textContent = formatAmount(scaled);
                });
            }

            function clamp(val) {
                return Math.max(1, Math.min(100, val));
            }

            function setServings(val) {
                val = clamp(val);
                input.value = val;
                updateAmounts(val);
            }

            if (decreaseBtn) {
                decreaseBtn.addEventListener('click', function () {
                    setServings(parseInt(input.value, 10) - 1);
                });
            }

            if (increaseBtn) {
                increaseBtn.addEventListener('click', function () {
                    setServings(parseInt(input.value, 10) + 1);
                });
            }

            input.addEventListener('change', function () {
                var val = parseInt(input.value, 10);
                setServings(isNaN(val) ? originalServings : val);
            });

            input.addEventListener('input', function () {
                var val = parseInt(input.value, 10);
                if (!isNaN(val) && val >= 1 && val <= 100) {
                    updateAmounts(val);
                }
            });
        });
    }

    /* ─── Share Dropdown ─────────────────────────── */
    function initShareDropdown() {
        document.querySelectorAll('[data-pxr-share-toggle]').forEach(function (toggle) {
            var wrapper = toggle.closest('.pxr-custom-share');
            if (!wrapper) return;
            var menu = wrapper.querySelector('[data-pxr-share-menu]');
            if (!menu) return;

            function openMenu() {
                menu.classList.remove('pxr:hidden');
                toggle.setAttribute('aria-expanded', 'true');
            }

            function closeMenu() {
                menu.classList.add('pxr:hidden');
                toggle.setAttribute('aria-expanded', 'false');
            }

            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = toggle.getAttribute('aria-expanded') === 'true';
                isOpen ? closeMenu() : openMenu();
            });

            document.addEventListener('click', function (e) {
                if (!wrapper.contains(e.target)) closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });
        });
    }

    /* ─── Copy Link ──────────────────────────────── */
    function initCopyLink() {
        document.querySelectorAll('[data-pxr-copy-link]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-pxr-copy-link') || window.location.href;
                var originalText = btn.textContent;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function () {
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = originalText; }, 2000);
                    });
                } else {
                    // Fallback for older browsers
                    var textarea = document.createElement('textarea');
                    textarea.value = url;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    btn.textContent = 'Copied!';
                    setTimeout(function () { btn.textContent = originalText; }, 2000);
                }
            });
        });
    }

    /* ─── Favorite Toggle ───────────────────────── */
    function initFavoriteToggle() {
        document.querySelectorAll('[data-pxr-favorite]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) return;

                var recipeId = btn.getAttribute('data-pxr-favorite');
                if (!recipeId || typeof plugixaRecipeConfig === 'undefined') return;

                // Require login
                if (!plugixaRecipeConfig.isLoggedIn) {
                    var label = btn.querySelector('.pxr-custom-btn__label');
                    if (label) {
                        var origText = label.textContent;
                        label.textContent = 'Please log in';
                        setTimeout(function () { label.textContent = origText; }, 2000);
                    }
                    return;
                }

                btn.disabled = true;

                fetch(plugixaRecipeConfig.restUrl + 'favorites/' + recipeId, {
                    method: 'POST',
                    headers: restHeaders({ 'Content-Type': 'application/json' }),
                    credentials: 'same-origin'
                })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.success && result.data) {
                        var isFav = result.data.is_favorited;
                        var svg = btn.querySelector('svg');
                        var label = btn.querySelector('.pxr-custom-btn__label');

                        btn.classList.toggle('pxr-custom-btn--favorited', isFav);
                        btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
                        btn.setAttribute('aria-label', isFav ? 'Remove from favorites' : 'Add to favorites');

                        if (svg) {
                            svg.setAttribute('fill', isFav ? 'currentColor' : 'none');
                        }
                        if (label) {
                            label.textContent = isFav ? 'Saved' : 'Save';
                        }
                    }
                })
                .catch(function () {
                    // Silently fail — button re-enables below
                })
                .finally(function () {
                    btn.disabled = false;
                });
            });
        });
    }

    /* ─── Review Helpers ────────────────────────── */
    var _avatarColors = ['#4F46E5','#0891B2','#059669','#D97706','#DC2626','#7C3AED','#DB2777','#2563EB'];

    function pxrGetInitials(name) {
        var parts = (name || '').trim().split(/\s+/);
        var first = parts[0] ? parts[0].charAt(0).toUpperCase() : '?';
        var last  = parts.length > 1 ? parts[parts.length - 1].charAt(0).toUpperCase() : '';
        return first + last;
    }

    function pxrAvatarColor(name) {
        var hash = 0;
        for (var i = 0; i < name.length; i++) hash = ((hash << 5) - hash) + name.charCodeAt(i);
        return _avatarColors[Math.abs(hash) % _avatarColors.length];
    }

    /* ─── Review Helpful Votes ───────────────────── */
    function initReviewHelpful() {
        if (typeof plugixaRecipeConfig === 'undefined' || !plugixaRecipeConfig.reviewHelpfulEnabled) return;

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-pxr-helpful-btn]');
            if (!btn || btn.disabled) return;

            var reviewId = btn.getAttribute('data-review-id');
            if (!reviewId) return;

            btn.disabled = true;

            fetch(plugixaRecipeConfig.restUrl + 'frontend/reviews/' + reviewId + '/helpful', {
                method: 'POST',
                headers: restHeaders(),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success && result.data) {
                    var count = result.data.helpful_count || 0;
                    var isActive = result.data.is_helpful;
                    var label = btn.querySelector('[data-pxr-helpful-label]');
                    if (label) {
                        label.textContent = count > 0 ? 'Helpful (' + count + ')' : 'Helpful';
                    }
                    btn.setAttribute('data-helpful-count', count);
                    btn.classList.toggle('pxr-helpful-btn--active', isActive);
                }
            })
            .finally(function() { btn.disabled = false; });
        });
    }

    /* ─── Review Pagination (Load More) ──────────── */
    function initReviewPagination() {
        var PER_PAGE = (typeof plugixaRecipeConfig !== 'undefined' && plugixaRecipeConfig.reviewsPerPage) ? parseInt(plugixaRecipeConfig.reviewsPerPage, 10) : 10;

        document.querySelectorAll('.pxr-load-more-reviews').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var recipeId = btn.getAttribute('data-recipe-id');
                var page     = parseInt(btn.getAttribute('data-page'), 10);
                var total    = parseInt(btn.getAttribute('data-total'), 10);
                var wrapper  = btn.closest('.pxr-custom-single__review-pagination');
                var spinner  = wrapper ? wrapper.querySelector('.pxr-loading-spinner') : null;
                var list     = document.querySelector('[data-pxr-review-list]');

                if (!recipeId || !list || typeof plugixaRecipeConfig === 'undefined') return;

                btn.disabled = true;
                if (spinner) spinner.style.display = 'inline';

                fetch(plugixaRecipeConfig.restUrl + 'frontend/recipes/' + recipeId + '/reviews?per_page=' + PER_PAGE + '&page=' + page, {
                    method: 'GET',
                    headers: restHeaders(),
                    credentials: 'same-origin'
                })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.success && result.data && result.data.items) {
                        var items = result.data.items;

                        items.forEach(function (review) {
                            var card = document.createElement('div');
                            card.className = 'pxr-custom-review pxr:p-4 pxr:rounded-lg pxr:border';
                            card.setAttribute('data-review-id', review.id);

                            var authorName = review.author_name || 'Anonymous';
                            var initials = pxrGetInitials(authorName);
                            var avatarColor = pxrAvatarColor(authorName);
                            var dateHtml = '';
                            if (review.created_at) {
                                var d = new Date(review.created_at);
                                var formatted = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                                dateHtml = '<time class="pxr-custom-review__date pxr:text-xs pxr:opacity-60" datetime="' + review.created_at + '">' + formatted + '</time>';
                            }

                            var starsHtml = '';
                            if (review.rating) {
                                starsHtml = '<div class="pxr-custom-review__rating pxr:flex pxr:gap-0.5">';
                                for (var i = 1; i <= 5; i++) {
                                    var cls = i <= review.rating ? 'pxr-custom-star--full' : 'pxr-custom-star--empty';
                                    starsHtml += '<svg class="pxr-custom-star ' + cls + ' pxr:w-3.5 pxr:h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
                                        + '<path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>'
                                        + '</svg>';
                                }
                                starsHtml += '</div>';
                            }

                            var textHtml = review.review_text
                                ? '<div class="pxr-custom-review__text pxr:text-sm pxr:leading-relaxed">' + review.review_text + '</div>'
                                : '';

                            var imagesHtml = '';
                            if (review.images && review.images.length) {
                                imagesHtml = '<div class="pxr-custom-review__images pxr:flex pxr:flex-wrap pxr:gap-2 pxr:mt-3">';
                                review.images.forEach(function(img) {
                                    imagesHtml += '<a href="' + (img.full || img.url) + '" target="_blank" rel="noopener" class="pxr-custom-review__image-link pxr:block pxr:w-16 pxr:h-16 pxr:rounded-lg pxr:overflow-hidden pxr:border pxr:transition-opacity hover:pxr:opacity-80">'
                                        + '<img src="' + img.url + '" alt="Review photo" class="pxr:w-full pxr:h-full pxr:object-cover" loading="lazy" />'
                                        + '</a>';
                                });
                                imagesHtml += '</div>';
                            }

                            var helpfulHtml = '';
                            if (plugixaRecipeConfig.reviewHelpfulEnabled) {
                                var hCount = review.helpful_count || 0;
                                var hLabel = hCount > 0 ? 'Helpful (' + hCount + ')' : 'Helpful';
                                helpfulHtml = '<div class="pxr-custom-review__actions pxr:flex pxr:items-center pxr:gap-3 pxr:mt-3">'
                                    + '<button type="button" class="pxr-helpful-btn pxr:inline-flex pxr:items-center pxr:gap-1.5 pxr:px-3 pxr:py-1 pxr:rounded-full pxr:border pxr:text-xs pxr:font-medium pxr:cursor-pointer pxr:transition-colors pxr:duration-200 pxr:bg-transparent" data-pxr-helpful-btn data-review-id="' + review.id + '" data-helpful-count="' + hCount + '">'
                                        + '<svg class="pxr:w-3.5 pxr:h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3" /></svg>'
                                        + '<span data-pxr-helpful-label>' + hLabel + '</span>'
                                    + '</button></div>';
                            }

                            var adminReplyHtml = '';
                            if (review.admin_reply) {
                                var replyDate = '';
                                if (review.admin_reply_at) {
                                    var rd = new Date(review.admin_reply_at);
                                    replyDate = '<time class="pxr:text-xs pxr:opacity-60">' + rd.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) + '</time>';
                                }
                                adminReplyHtml = '<div class="pxr-custom-review__admin-reply pxr:mt-3 pxr:ml-12 pxr:p-3 pxr:rounded-lg pxr:border-l-4 pxr:text-sm" style="background: var(--pxr-bg-secondary, #f9fafb); border-left-color: var(--pxr-primary, #4F46E5);">'
                                    + '<div class="pxr:flex pxr:items-center pxr:gap-2 pxr:mb-1">'
                                        + '<svg class="pxr:w-3.5 pxr:h-3.5 pxr:opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>'
                                        + '<span class="pxr:font-semibold pxr:text-xs">Admin</span>'
                                        + replyDate
                                    + '</div>'
                                    + '<div class="pxr:leading-relaxed">' + review.admin_reply + '</div>'
                                + '</div>';
                            }

                            card.innerHTML =
                                '<div class="pxr-custom-review__header pxr:flex pxr:items-center pxr:justify-between pxr:mb-2">'
                                    + '<div class="pxr:flex pxr:items-center pxr:gap-3">'
                                        + '<div class="pxr-custom-review__avatar pxr:w-9 pxr:h-9 pxr:rounded-full pxr:flex pxr:items-center pxr:justify-center pxr:text-xs pxr:font-bold pxr:text-white pxr:shrink-0 pxr:select-none" style="background-color:' + avatarColor + ';">' + initials + '</div>'
                                        + '<div>'
                                            + '<span class="pxr-custom-review__author pxr:font-semibold pxr:text-sm pxr:block">' + authorName + '</span>'
                                            + dateHtml
                                        + '</div>'
                                    + '</div>'
                                    + starsHtml
                                + '</div>'
                                + textHtml
                                + imagesHtml
                                + helpfulHtml
                                + adminReplyHtml;

                            list.appendChild(card);
                        });

                        var loaded = (page - 1) * PER_PAGE + items.length;
                        if (loaded >= total) {
                            btn.style.display = 'none';
                            if (spinner) spinner.style.display = 'none';
                        } else {
                            btn.setAttribute('data-page', page + 1);
                        }
                    }
                })
                .catch(function () {
                    if (spinner) {
                        spinner.textContent = 'An error occurred while loading reviews.';
                        spinner.style.display = 'inline';
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                    if (spinner && btn.style.display !== 'none') {
                        spinner.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ─── Button Tooltips (singleton, event delegation) ── */
    function initTooltips() {
        if (typeof plugixaRecipeConfig === 'undefined') return;

        var tooltip = document.createElement('div');
        tooltip.className = 'pxr-custom-tooltip';
        tooltip.id = 'pxr-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.setAttribute('aria-hidden', 'true');
        tooltip.innerHTML =
            '<div class="pxr-custom-tooltip__inner">' +
                '<span class="pxr-custom-tooltip__text"></span>' +
                '<a class="pxr-custom-tooltip__login-link" href="#"></a>' +
            '</div>' +
            '<span class="pxr-custom-tooltip__arrow"></span>';
        document.body.appendChild(tooltip);

        var textEl = tooltip.querySelector('.pxr-custom-tooltip__text');
        var linkEl = tooltip.querySelector('.pxr-custom-tooltip__login-link');
        var activeBtn = null;
        var showTimer = null;
        var hideTimer = null;
        var isTouchDevice = false;

        document.addEventListener('touchstart', function () { isTouchDevice = true; }, { once: true, passive: true });

        function getTooltipStrings() {
            var i18n = plugixaRecipeConfig.i18n && plugixaRecipeConfig.i18n.tooltip;
            return i18n || {
                like: 'Like this recipe',
                likeActive: 'Unlike this recipe',
                save: 'Save to favorites',
                saveActive: 'Remove from saved',
                loginLike: 'Log in to like this recipe',
                loginSave: 'Log in to save this recipe',
                loginLink: 'Log in'
            };
        }

        function resolveText(btn) {
            var type = btn.getAttribute('data-pxr-tooltip');
            var isActive = btn.classList.contains('--active');
            var strings = getTooltipStrings();

            if (!plugixaRecipeConfig.isLoggedIn) {
                return {
                    text: type === 'like' ? strings.loginLike : strings.loginSave,
                    showLogin: true,
                    loginText: strings.loginLink
                };
            }

            if (type === 'like') {
                return { text: isActive ? strings.likeActive : strings.like, showLogin: false };
            }
            return { text: isActive ? strings.saveActive : strings.save, showLogin: false };
        }

        function positionTooltip(btn) {
            var rect = btn.getBoundingClientRect();
            var tipRect = tooltip.getBoundingClientRect();
            var gap = 8;

            // Default: left of button
            var left = rect.left - tipRect.width - gap;
            var flipped = false;

            if (left < 8) {
                // Flip to right side
                left = rect.right + gap;
                flipped = true;
            }

            var top = rect.top + (rect.height / 2) - (tipRect.height / 2);
            // Keep within viewport vertically
            top = Math.max(8, Math.min(top, window.innerHeight - tipRect.height - 8));

            tooltip.style.top = top + 'px';
            tooltip.style.left = left + 'px';
            tooltip.classList.toggle('--right', flipped);
        }

        function show(btn) {
            clearTimeout(hideTimer);

            var info = resolveText(btn);
            textEl.textContent = info.text;

            if (info.showLogin) {
                linkEl.style.display = 'block';
                linkEl.href = plugixaRecipeConfig.loginUrl || '#';
                linkEl.textContent = info.loginText;
            } else {
                linkEl.style.display = 'none';
            }

            // Dark mode: mirror class if button is inside a .pxr-dark container
            var isDark = btn.closest('.pxr-dark');
            tooltip.classList.toggle('pxr-dark', !!isDark);

            // Make visible (opacity 0) to measure, then position, then animate in
            tooltip.style.visibility = 'hidden';
            tooltip.classList.add('--visible');
            positionTooltip(btn);
            tooltip.style.visibility = '';

            tooltip.setAttribute('aria-hidden', 'false');
            btn.setAttribute('aria-describedby', 'pxr-tooltip');
            activeBtn = btn;
        }

        function hide() {
            clearTimeout(showTimer);
            tooltip.classList.remove('--visible');
            tooltip.setAttribute('aria-hidden', 'true');
            if (activeBtn) {
                activeBtn.removeAttribute('aria-describedby');
                activeBtn = null;
            }
        }

        // Update tooltip text if it's currently shown for a given button
        window.pxrUpdateTooltip = function (btn) {
            if (activeBtn === btn) {
                var info = resolveText(btn);
                textEl.textContent = info.text;
            }
        };

        // Desktop: mouseover / mouseout (these bubble, unlike mouseenter/mouseleave)
        document.addEventListener('mouseover', function (e) {
            if (isTouchDevice) return;
            var target = e.target;
            if (!target || !target.closest) return;
            var btn = target.closest('[data-pxr-tooltip]');
            if (!btn) return;

            // Skip if already showing for this button
            if (activeBtn === btn) { clearTimeout(hideTimer); return; }

            clearTimeout(hideTimer);
            clearTimeout(showTimer);
            showTimer = setTimeout(function () { show(btn); }, 80);
        });

        document.addEventListener('mouseout', function (e) {
            if (isTouchDevice) return;
            var target = e.target;
            if (!target || !target.closest) return;
            var btn = target.closest('[data-pxr-tooltip]');
            if (!btn) return;

            // Check if moving to a child of the same button
            var related = e.relatedTarget;
            if (related && related.closest && related.closest('[data-pxr-tooltip]') === btn) return;

            clearTimeout(showTimer);
            hideTimer = setTimeout(hide, 100);
        });

        // Keep tooltip open when hovering over the tooltip itself (for login link)
        tooltip.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
        });

        tooltip.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(hide, 100);
        });

        // Mobile: show tooltip on touch for guests
        document.addEventListener('click', function (e) {
            if (!isTouchDevice) return;

            var btn = e.target.closest('[data-pxr-tooltip]');
            if (!btn) return;

            // For non-logged-in users: show tooltip instead of action
            if (!plugixaRecipeConfig.isLoggedIn) {
                if (activeBtn === btn && tooltip.classList.contains('--visible')) {
                    hide();
                } else {
                    show(btn);
                }
                return;
            }

            // Logged in on mobile: show brief tooltip after action
            show(btn);
            setTimeout(hide, 1500);
        });

        // Dismiss on outside click (mobile)
        document.addEventListener('click', function (e) {
            if (!activeBtn) return;
            if (e.target.closest('.pxr-custom-tooltip') || e.target.closest('[data-pxr-tooltip]')) return;
            hide();
        });

        // Keyboard: focus
        document.addEventListener('focusin', function (e) {
            var btn = e.target.closest('[data-pxr-tooltip]');
            if (btn) show(btn);
        });

        document.addEventListener('focusout', function (e) {
            var btn = e.target.closest('[data-pxr-tooltip]');
            if (btn) hideTimer = setTimeout(hide, 100);
        });

        // Dismiss on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activeBtn) hide();
        });

        // Dismiss on scroll
        window.addEventListener('scroll', function () {
            if (activeBtn) hide();
        }, { passive: true });
    }

    /* ─── Card Like Toggle (event delegation) ─────── */
    function initCardLike() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pxr-like]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var recipeId = btn.getAttribute('data-pxr-like');
            if (!recipeId || typeof plugixaRecipeConfig === 'undefined') return;

            // Guest → tooltip handles login prompt
            if (!plugixaRecipeConfig.isLoggedIn) return;

            // Optimistic UI
            var isActive = btn.classList.contains('--active');
            var allBtns = document.querySelectorAll('[data-pxr-like="' + recipeId + '"]');

            allBtns.forEach(function (b) {
                b.classList.toggle('--active', !isActive);
                b.classList.add('--pulse');
                b.setAttribute('aria-pressed', !isActive ? 'true' : 'false');
                b.setAttribute('aria-label', !isActive ? plugixaRecipeConfig.i18n.tooltip.likeActive : plugixaRecipeConfig.i18n.tooltip.like);
                var svg = b.querySelector('svg');
                if (svg) svg.setAttribute('fill', !isActive ? 'currentColor' : 'none');
                if (typeof window.pxrUpdateTooltip === 'function') window.pxrUpdateTooltip(b);
            });

            // Remove pulse class after animation
            setTimeout(function () {
                allBtns.forEach(function (b) { b.classList.remove('--pulse'); });
            }, 400);

            fetch(plugixaRecipeConfig.restUrl + 'frontend/likes/' + recipeId, {
                method: 'POST',
                headers: restHeaders({ 'Content-Type': 'application/json' }),
                credentials: 'same-origin'
            })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success) {
                    // Revert on failure
                    allBtns.forEach(function (b) {
                        b.classList.toggle('--active', isActive);
                        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        var svg = b.querySelector('svg');
                        if (svg) svg.setAttribute('fill', isActive ? 'currentColor' : 'none');
                    });
                }
            })
            .catch(function () {
                // Revert on error
                allBtns.forEach(function (b) {
                    b.classList.toggle('--active', isActive);
                    b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    var svg = b.querySelector('svg');
                    if (svg) svg.setAttribute('fill', isActive ? 'currentColor' : 'none');
                });
            });
        });
    }

    /* ─── Card Save/Favorite Toggle (event delegation) ── */
    function initCardSave() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pxr-card-favorite]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            var recipeId = btn.getAttribute('data-pxr-card-favorite');
            if (!recipeId || typeof plugixaRecipeConfig === 'undefined') return;

            // Guest → tooltip handles login prompt
            if (!plugixaRecipeConfig.isLoggedIn) return;

            // Optimistic UI
            var isActive = btn.classList.contains('--active');
            var allBtns = document.querySelectorAll('[data-pxr-card-favorite="' + recipeId + '"]');

            allBtns.forEach(function (b) {
                b.classList.toggle('--active', !isActive);
                b.setAttribute('aria-pressed', !isActive ? 'true' : 'false');
                b.setAttribute('aria-label', !isActive ? plugixaRecipeConfig.i18n.tooltip.saveActive : plugixaRecipeConfig.i18n.tooltip.save);
                var svg = b.querySelector('svg');
                if (svg) svg.setAttribute('fill', !isActive ? 'currentColor' : 'none');
                if (typeof window.pxrUpdateTooltip === 'function') window.pxrUpdateTooltip(b);
            });

            fetch(plugixaRecipeConfig.restUrl + 'frontend/favorites/' + recipeId, {
                method: 'POST',
                headers: restHeaders({ 'Content-Type': 'application/json' }),
                credentials: 'same-origin'
            })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success) {
                    // Revert on failure
                    allBtns.forEach(function (b) {
                        b.classList.toggle('--active', isActive);
                        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                        var svg = b.querySelector('svg');
                        if (svg) svg.setAttribute('fill', isActive ? 'currentColor' : 'none');
                    });
                }
            })
            .catch(function () {
                // Revert on error
                allBtns.forEach(function (b) {
                    b.classList.toggle('--active', isActive);
                    b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    var svg = b.querySelector('svg');
                    if (svg) svg.setAttribute('fill', isActive ? 'currentColor' : 'none');
                });
            });
        });
    }

    /* ─── Ingredient Checkboxes ──────────────────── */
    function initIngredientCheckboxes() {
        document.querySelectorAll('[data-pxr-ingredient-check]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var li = checkbox.closest('.pxr-custom-single__ingredient');
                if (!li) return;
                li.classList.toggle('pxr-custom-single__ingredient--checked', checkbox.checked);
            });
        });
    }

    /* ─── Gallery Lightbox ────────────────────────── */
    function initGalleryLightbox() {
        if (typeof GLightbox === 'undefined') return;
        if (!document.querySelector('[data-pxr-gallery]')) return;

        GLightbox({
            selector: '.glightbox',
            touchNavigation: true,
            loop: true,
            autoplayVideos: false,
            // GLightbox ships a default that pulls the Plyr player from cdn.plyr.io the
            // first time a slide is a video. This gallery only ever holds local attachment
            // images -- every href is a wp_get_attachment_image_url() -- so that branch is
            // unreachable today. Nulled anyway so it stays unreachable by construction
            // rather than by accident: an undisclosed third-party CDN must not be one
            // gallery change away. GLightbox's asset injector no-ops on a null source.
            plyr: { css: null, js: null },
            openEffect: 'fade',
            closeEffect: 'fade',
            zoomable: true,
            draggable: true,
            preload: true,
        });
    }

    /* ─── Video Card Playback ─────────────────────── */
    function initVideoCards() {
        var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        document.querySelectorAll('.pxr-custom-card__video-container').forEach(function (vc) {
            if (vc._pxrVideoInit) return;
            vc._pxrVideoInit = true;

            var playback = vc.getAttribute('data-pxr-video-playback');
            var card = vc.closest('.pxr-custom-card');
            var video = vc.querySelector('video');
            var playIcon = card ? card.querySelector('.pxr-custom-card__play-icon') : null;

            // On touch devices, fall back hover → click
            if (isTouch && playback === 'hover') {
                playback = 'click';
            }

            if (playback === 'hover' && video) {
                card.addEventListener('mouseenter', function () {
                    vc.classList.add('--playing');
                    video.play().catch(function () {});
                });
                card.addEventListener('mouseleave', function () {
                    vc.classList.remove('--playing');
                    video.pause();
                    video.currentTime = 0;
                });
            } else if (playback === 'click' && video) {
                if (playIcon) {
                    playIcon.style.pointerEvents = 'auto';
                    playIcon.style.cursor = 'pointer';
                }
                var trigger = playIcon || vc;
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (vc.classList.contains('--playing')) {
                        vc.classList.remove('--playing');
                        video.pause();
                        video.currentTime = 0;
                    } else {
                        vc.classList.add('--playing');
                        video.play().catch(function () {});
                    }
                });
            }
            // icon-only: no JS needed, play icon is decorative
        });
    }

    /* ─── Initialize ──────────────────────────────── */
    function init() {
        initCategoryFilters();
        initCuisineFilters();
        initAlphaFilters();
        initIngredientFilter();
        initLoadMore();
        initNumbersPagination();
        initInfiniteScroll();
        initSliders();
        initServingsAdjuster();
        initShareDropdown();
        initCopyLink();
        initTooltips();
        initFavoriteToggle();
        initCardLike();
        initCardSave();
        initIngredientCheckboxes();
        initReviewPagination();
        initReviewHelpful();
        initGalleryLightbox();
        initVideoCards();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
