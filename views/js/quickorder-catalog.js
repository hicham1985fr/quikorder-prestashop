/**
 * Quick Order – Catalog Button Replacement
 *
 * Loaded ONLY when QUICKORDER_REPLACE_CART = 1 AND on catalog pages.
 * Registered via displayHeader → registerJavascript.
 *
 * What it does:
 *   - Finds every "Add to Cart" button inside product listing cards
 *   - Replaces each with a styled <a> link pointing to the product page
 *   - Label comes from PHP via Media::addJsDef (translatable)
 *
 * What it does NOT do:
 *   ❌ No cart interception
 *   ❌ No fetch / XMLHttpRequest hijacking
 *   ❌ No quick-view triggering
 *   ❌ No AJAX — just a normal <a href> redirect
 */
(function () {
    'use strict';

    /* Label from PHP — fallback to Arabic "Buy Now" */
    var LABEL = (typeof qo_buy_label !== 'undefined') ? qo_buy_label : '\u0625\u0634\u062A\u0631\u064A \u0627\u0644\u0622\u0646';

    /**
     * Find the product URL from a product card element.
     * Tries multiple selectors to support Classic + modern themes.
     */
    function getProductUrl(card) {
        var selectors = [
            '.product-thumbnail',         /* Classic theme */
            '.product-title a',           /* Classic theme */
            'a.product_img_link',         /* some themes */
            '.thumbnail-container > a',   /* some themes */
            'h3.product-title a',
            'h2 a',
            '.product-name a',
            'a[itemprop="url"]',          /* schema.org markup */
            '.product-miniature > a'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var el = card.querySelector(selectors[i]);
            if (el && el.getAttribute('href')) {
                return el.getAttribute('href');
            }
        }
        return null;
    }

    /**
     * Create the replacement "Buy Now" link.
     */
    function createBuyLink(href) {
        var link = document.createElement('a');
        link.href = href;
        link.textContent = LABEL;
        link.className = 'btn btn-primary qo-buy-now-btn';
        link.setAttribute('data-qo-replaced', '1');
        link.setAttribute('title', LABEL);

        /* Inline styles to guarantee visibility regardless of theme CSS */
        link.style.cssText = [
            'display:inline-block',
            'text-align:center',
            'text-decoration:none',
            'background:#e67e22',
            'color:#fff',
            'border:none',
            'border-radius:5px',
            'padding:8px 16px',
            'font-size:14px',
            'font-weight:700',
            'cursor:pointer',
            'transition:background .2s',
            'width:100%',
            'box-sizing:border-box',
            'line-height:1.4'
        ].join(';');

        link.addEventListener('mouseenter', function () { this.style.background = '#cf6d17'; });
        link.addEventListener('mouseleave', function () { this.style.background = '#e67e22'; });

        return link;
    }

    /**
     * Main replacement logic.
     * Scans for product cards and swaps their cart buttons.
     */
    function replaceButtons() {
        /* Product card selectors — covers Classic + popular themes */
        var cards = document.querySelectorAll([
            '.product-miniature',
            '.js-product-miniature',
            '.product-container',
            'article.product-miniature'
        ].join(','));

        if (!cards.length) return;

        var replaced = 0;

        for (var c = 0; c < cards.length; c++) {
            var card = cards[c];

            /* Skip if already processed */
            if (card.getAttribute('data-qo-done')) continue;

            var productUrl = getProductUrl(card);
            if (!productUrl) continue;

            /* Find the cart button(s) inside this card */
            var btns = card.querySelectorAll([
                'button[data-button-action="add-to-cart"]',
                '.add-to-cart',
                '.ajax_add_to_cart_button',
                'form.add-to-cart-or-refresh button[type="submit"]',
                'button[name="add"]'
            ].join(','));

            for (var b = 0; b < btns.length; b++) {
                var btn = btns[b];

                /* Skip if this specific button was already replaced */
                if (btn.getAttribute('data-qo-replaced')) continue;

                var newLink = createBuyLink(productUrl);

                /* Replace in DOM */
                if (btn.parentNode) {
                    btn.parentNode.replaceChild(newLink, btn);
                    replaced++;
                }
            }

            card.setAttribute('data-qo-done', '1');
        }

        if (replaced > 0) {
            console.log('[QO] Replaced ' + replaced + ' cart button(s) with Buy Now links');
        }
    }

    /**
     * Initialize: run once + observe for dynamically loaded products.
     */
    function init() {
        /* Initial run */
        replaceButtons();

        /* MutationObserver for infinite scroll, faceted search, etc. */
        if (typeof MutationObserver !== 'undefined') {
            var target = document.getElementById('products')
                      || document.getElementById('content')
                      || document.querySelector('.products')
                      || document.body;

            var observer = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes.length > 0) {
                        replaceButtons();
                        return;
                    }
                }
            });

            observer.observe(target, { childList: true, subtree: true });
        }

        /* PrestaShop native AJAX filtering event */
        if (typeof prestashop !== 'undefined' && prestashop.on) {
            prestashop.on('updateProductList', function () {
                /* Small delay to let DOM update */
                setTimeout(replaceButtons, 150);
            });
        }
    }

    /* Boot */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
