{**
 * Quick Order Form – v1.5.0
 *
 * Form contains: Price display, Full Name, Phone, Address, Quantity, Submit.
 * NO variants/combinations — those are handled by native PrestaShop selectors.
 * JS reads id_product_attribute from the native PS form.
 *}

{* ══════════════════════ HTML ══════════════════════ *}
<div id="qo-box">
  <div class="qo-hdr">
    <span class="qo-hdr-left">&#9889; {l s='Quick Order' mod='quickorder'}</span>
    <span class="qo-hdr-badge">{l s='Cash on Delivery' mod='quickorder'}</span>
  </div>

  {if $qo_in_stock}
  <div class="qo-body" id="qo-body">

    {* Hidden fields *}
    <input type="hidden" id="qo_hid_product" value="{$qo_id_product|intval}">
    <input type="hidden" id="qo_hid_token" value="{$qo_token|escape:'htmlall':'UTF-8'}">
    <input type="hidden" id="qo_hid_url" value="{$qo_action_url|escape:'htmlall':'UTF-8'}">

    {* Price display *}
    <div class="qo-price-bar">
        {if $qo_price_old > 0}
            <span class="qo-price-old">{$qo_price_old|string_format:"%.0f"}{$qo_currency_sign}</span>
            <span class="qo-discount-badge">-{$qo_discount_percent}%</span>
        {/if}
        <span class="qo-price-current">{$qo_price|string_format:"%.0f"}{$qo_currency_sign}</span>
    </div>

    {* Urgency *}
    <div class="qo-urgency">
        &#9200; {l s='عرض محدود — اطلب الآن قبل نفاذ الكمية!' mod='quickorder'}
    </div>

    {* Full Name *}
    <div class="qo-f">
      <label>{l s='Full Name' mod='quickorder'} <span class="qo-r">*</span></label>
      <input type="text" id="qo_name" placeholder="{l s='e.g. Ahmed Mohamed' mod='quickorder'}"
             required minlength="3" maxlength="64" autocomplete="name">
    </div>

    {* Phone *}
    <div class="qo-f">
      <label>{l s='Phone Number' mod='quickorder'} <span class="qo-r">*</span></label>
      <input type="tel" id="qo_phone" placeholder="{l s='e.g. 0601234567' mod='quickorder'}"
             required minlength="8" maxlength="20" autocomplete="tel">
    </div>

    {* Delivery Address *}
    <div class="qo-f">
      <label>{l s='Delivery Address' mod='quickorder'} <span class="qo-r">*</span></label>
      <textarea id="qo_addr" rows="2"
                placeholder="{l s='Full delivery address' mod='quickorder'}"
                required minlength="5" maxlength="255"></textarea>
    </div>

    {* ── QO-GEO-1 — City and postcode ────────────────────────────────────
       Two fields, side by side on anything wider than a phone. The city is
       required because a delivery address without one is not deliverable; the
       postcode is optional because many Moroccan customers genuinely do not
       know theirs, and asking them to invent one is how this module ended up
       writing 00000 on 706 addresses.

       `inputmode="numeric"` rather than `type="number"`: a number input
       strips leading zeros, and `10000` is a real Rabat postcode.            *}
    <div class="qo-f qo-geo">
      <div class="qo-geo-city">
        <label>{l s='City' mod='quickorder'} <span class="qo-r">*</span></label>
        <input type="text" id="qo_city"
               placeholder="{l s='e.g. Casablanca' mod='quickorder'}"
               autocomplete="address-level2"
               required maxlength="64">
      </div>
      <div class="qo-geo-zip">
        <label>{l s='Postcode' mod='quickorder'}
               <span class="qo-opt">({l s='optional' mod='quickorder'})</span></label>
        <input type="text" id="qo_zip"
               placeholder="{l s='e.g. 20000' mod='quickorder'}"
               autocomplete="postal-code"
               inputmode="numeric" maxlength="12">
      </div>
    </div>

    {* Variants placeholder: populated by JS from native PrestaShop selectors
       (only visible when variants are successfully cloned into slot) *}
    {if $qo_has_variants}
      <div class="qo-f qo-variants-wrap" id="qo-variants-wrap" style="display:none;">
        <div id="qo-variants-slot"></div>
      </div>
    {/if}

    {* Quantity field — hidden only when variants are visible *}
    <div class="qo-f qo-qty-wrap" id="qo-qty-wrap">
      <label>{l s='Quantity' mod='quickorder'} <span class="qo-r">*</span></label>
      <input type="number" id="qo_qty" value="1" min="1" max="99" required>
    </div>

    {* Submit *}
    <button type="button" id="qo-btn">
      <span class="qo-txt">{l s='اشتري الآن' mod='quickorder'}</span>
      <span class="qo-spin">&#9203; {l s='Processing...' mod='quickorder'}</span>
    </button>

    <div id="qo-msg" role="alert"></div>
  </div>
  {else}
  <div class="qo-body" style="text-align:center; padding: 24px 16px;">
    <div style="font-size: 32px; margin-bottom: 8px; opacity: 0.4;">&#128722;</div>
    <p style="color: #e74c3c; font-weight: 700; font-size: 14px; margin: 0 0 4px;">
      {l s='هذا المنتج غير متوفر حالياً' mod='quickorder'}
    </p>
    <p style="color: #888; font-size: 12px; margin: 0;">
      {l s='سيتم إعادة توفيره قريباً إن شاء الله' mod='quickorder'}
    </p>
  </div>
  {/if}
</div>

{if $qo_in_stock}
{* ══════════════════════ INLINE JAVASCRIPT ══════════════════════ *}
<script>
var QO_MSG = {
    err_name:     "{l s='Please enter your full name (min 3 characters).' mod='quickorder' js=1}",
    err_phone:    "{l s='Please enter a valid phone number (min 8 digits).' mod='quickorder' js=1}",
    err_address:  "{l s='Please enter a delivery address (min 5 characters).' mod='quickorder' js=1}",
    err_qty:      "{l s='Quantity must be at least 1.' mod='quickorder' js=1}",
    err_city:     "{l s='Please enter your city.' mod='quickorder' js=1}",
    err_city_real:"{l s='Please enter the real name of your city.' mod='quickorder' js=1}",
    err_zip:      "{l s='The postcode must be 5 digits (for example 20000), or left empty.' mod='quickorder' js=1}",
    err_server:   "{l s='Server error' mod='quickorder' js=1}",
    err_invalid:  "{l s='Server returned an invalid response.' mod='quickorder' js=1}",
    err_failed:   "{l s='Order failed. Please try again.' mod='quickorder' js=1}",
    err_generic:  "{l s='An error occurred.' mod='quickorder' js=1}",
    ok_placed:    "{l s='Order placed!' mod='quickorder' js=1}"
};
{literal}
(function(){
    "use strict";

    /* ══════════════════════════════════════════════════════════
     * CLONE native PrestaShop variant UI into our QO form + SYNC.
     *
     * Strategy (robust against PS re-renders):
     *   1. Hide the native .product-variants visually (CSS)
     *   2. CLONE its HTML into our slot
     *   3. When user interacts with the CLONE, forward the selection to
     *      the native (hidden) widget → PS's native JS handles everything
     *      (price update, id_product_attribute, stock, etc.)
     *   4. On PS "updatedProduct" event, refresh the clone from source
     *
     * Why not "move"? Because some themes/modules may re-render the
     * product section, destroying our moved node. Cloning keeps the
     * source intact (just hidden) — safer.
     * ══════════════════════════════════════════════════════════ */

    function getNativeVariants() {
        var slot = document.getElementById('qo-variants-slot');
        if (!slot) return null;
        var candidates = document.querySelectorAll('.product-variants');
        for (var i = 0; i < candidates.length; i++) {
            if (!slot.contains(candidates[i])) return candidates[i];
        }
        return null;
    }

    /* Forward a user change in the clone to the native element */
    function syncCloneToNative(cloneEl) {
        var name = cloneEl.getAttribute('name');
        if (!name) return;
        var native = getNativeVariants();
        if (!native) return;

        if (cloneEl.tagName === 'SELECT') {
            var nativeSel = native.querySelector('select[name="' + name + '"]');
            if (nativeSel && nativeSel.value !== cloneEl.value) {
                nativeSel.value = cloneEl.value;
                nativeSel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } else if (cloneEl.type === 'radio') {
            var nativeRadio = native.querySelector(
                'input[type="radio"][name="' + name + '"][value="' + cloneEl.value + '"]'
            );
            if (nativeRadio && !nativeRadio.checked) {
                nativeRadio.checked = true;
                nativeRadio.dispatchEvent(new Event('change', { bubbles: true }));
                nativeRadio.dispatchEvent(new Event('click', { bubbles: true }));
            }
        }
    }

    function attachCloneListeners(slot) {
        slot.querySelectorAll('select, input[type="radio"], input[type="checkbox"]').forEach(function(el) {
            el.addEventListener('change', function() { syncCloneToNative(el); });
            if (el.type === 'radio') {
                el.addEventListener('click', function() { syncCloneToNative(el); });
            }
        });

        /* Handle label clicks (common in radio/color pickers) */
        slot.querySelectorAll('label').forEach(function(lbl) {
            lbl.addEventListener('click', function(e) {
                setTimeout(function() {
                    var input = lbl.querySelector('input') || document.getElementById(lbl.htmlFor);
                    if (input) syncCloneToNative(input);
                }, 20);
            });
        });
    }

    function toggleQtyVisibility(variantsPresent) {
        var qtyWrap = document.getElementById('qo-qty-wrap');
        var vWrap = document.getElementById('qo-variants-wrap');
        if (variantsPresent) {
            if (qtyWrap) qtyWrap.style.display = 'none';
            if (vWrap)   vWrap.style.display   = '';
        } else {
            if (qtyWrap) qtyWrap.style.display = '';
            if (vWrap)   vWrap.style.display   = 'none';
        }
    }

    function cloneVariants() {
        var slot = document.getElementById('qo-variants-slot');
        if (!slot) { toggleQtyVisibility(false); return; }

        var native = getNativeVariants();
        if (!native) {
            /* No native variants found — show qty field as fallback */
            if (slot.children.length === 0) toggleQtyVisibility(false);
            return;
        }

        /* Mark native for CSS hide */
        native.classList.add('qo-native-variants-hidden');

        /* Clone the HTML */
        var clone = native.cloneNode(true);
        clone.classList.remove('qo-native-variants-hidden');
        clone.classList.remove('product-variants');
        clone.classList.remove('js-product-variants');
        clone.classList.add('qo-variants-clone');

        /* Remove IDs from clone to avoid DOM conflicts */
        clone.querySelectorAll('[id]').forEach(function(el) {
            el.removeAttribute('id');
        });
        if (clone.id) clone.removeAttribute('id');

        /* Replace slot content */
        while (slot.firstChild) slot.removeChild(slot.firstChild);
        slot.appendChild(clone);

        /* Sync current values from native → clone (in case user had selected before clone) */
        native.querySelectorAll('select').forEach(function(nSel) {
            var cSel = clone.querySelector('select[name="' + nSel.getAttribute('name') + '"]');
            if (cSel) cSel.value = nSel.value;
        });
        native.querySelectorAll('input[type="radio"]:checked').forEach(function(nR) {
            var cR = clone.querySelector(
                'input[type="radio"][name="' + nR.getAttribute('name') + '"][value="' + nR.value + '"]'
            );
            if (cR) cR.checked = true;
        });

        attachCloneListeners(slot);
        toggleQtyVisibility(true);
    }

    /* Run on load + after PS variant updates */
    cloneVariants();
    document.addEventListener('DOMContentLoaded', cloneVariants);
    if (typeof prestashop !== 'undefined' && prestashop.on) {
        prestashop.on('updatedProduct', function() { setTimeout(cloneVariants, 50); });
    }
    setTimeout(cloneVariants, 300);
    setTimeout(cloneVariants, 1000);

    function boot() {
        var btn   = document.getElementById('qo-btn');
        var msgEl = document.getElementById('qo-msg');

        if (!btn || !msgEl) {
            console.error('[QO] Critical elements missing');
            return;
        }

        console.log('[QO] v1.4.0 initialized');

        /* ══════════════════════════════════════════════════════════
         * Read group[] values from NATIVE PrestaShop variant selectors.
         *
         * PrestaShop stores variants in:
         *   - <select name="group[1]">    (dropdown for Size, etc.)
         *   - <input name="group[3]" type="radio">  (radio/color picker)
         *   - <input name="group[3]" type="hidden">  (some themes)
         *
         * These ALWAYS reflect the user's current selection and are
         * updated by PrestaShop's own JS when the user changes variants.
         * ══════════════════════════════════════════════════════════ */

        function collectGroupValues() {
            var groups = {};

            /* Select dropdowns: group[X] */
            var selects = document.querySelectorAll(
                'select[name^="group["]'
            );
            for (var i = 0; i < selects.length; i++) {
                var name = selects[i].name;
                var val  = selects[i].value;
                if (val) {
                    groups[name] = val;
                }
            }

            /* Radio buttons & color pickers: checked input[name="group[X]"] */
            var radios = document.querySelectorAll(
                'input[name^="group["]:checked'
            );
            for (var r = 0; r < radios.length; r++) {
                groups[radios[r].name] = radios[r].value;
            }

            /* Hidden inputs (some themes use these instead of selects) */
            var hiddens = document.querySelectorAll(
                'input[type="hidden"][name^="group["]'
            );
            for (var h = 0; h < hiddens.length; h++) {
                var hName = hiddens[h].name;
                /* Don't overwrite if we already got a value from select/radio */
                if (!groups[hName] && hiddens[h].value) {
                    groups[hName] = hiddens[h].value;
                }
            }

            return groups;
        }

        /* Also try to read id_product_attribute directly (bonus, not primary) */
        function tryReadIPA() {
            /* From prestashop object */
            try {
                if (typeof prestashop !== 'undefined' && prestashop.product) {
                    var v = parseInt(prestashop.product.id_product_attribute, 10);
                    if (v > 0) return v;
                }
            } catch(e) {}

            /* From hidden input */
            var inputs = document.querySelectorAll('input[name="id_product_attribute"]');
            for (var i = 0; i < inputs.length; i++) {
                var v = parseInt(inputs[i].value, 10);
                if (v > 0) return v;
            }

            return 0;
        }

        /* ── Helpers ── */
        function msg(text, ok) {
            msgEl.textContent = text;
            msgEl.className = ok ? 'qo-msg-ok' : 'qo-msg-err';
        }
        function clearMsg() {
            msgEl.textContent = '';
            msgEl.className = '';
        }
        function loading(on) {
            btn.disabled = on;
            btn.className = on ? 'qo-loading' : '';
        }

        /* ── Validate ── */
        function getQuantity() {
            var qtyField = document.getElementById('qo_qty');
            if (qtyField) {
                return parseInt(qtyField.value, 10) || 1;
            }
            /* No qty field (product has variants): default 1 — variant defines the pack size */
            return 1;
        }

        /* ── QO-GEO-1 — client-side geography checks ─────────────────────
           A CONVENIENCE, NOT A GATE. Every rule below is enforced again on
           the server, which is where a request that never touched this form
           is judged. These exist so the customer finds out before the round
           trip, and so the offending field is highlighted rather than
           described.

           Kept deliberately looser than the server: the browser cannot know
           the country's postcode format, and a client check that rejects a
           valid address is worse than one that lets the server say so.  */
        var QO_PLACEHOLDER_CITIES = [
            '-', '--', '---', '.', '..', '...', '_', '/',
            'city', 'ville', 'مدينة', 'na', 'nc', 'none', 'null',
            'unknown', 'inconnu', 'maroc', 'morocco', 'المغرب', 'ma', 'test'
        ];

        function markInvalid(id) {
            ['qo_name', 'qo_phone', 'qo_addr', 'qo_city', 'qo_zip'].forEach(function (each) {
                var el = document.getElementById(each);
                if (el) { el.classList.remove('qo-invalid'); }
            });

            var field = id ? document.getElementById(id) : null;

            if (field) {
                field.classList.add('qo-invalid');
                try { field.focus({ preventScroll: false }); } catch (e) { field.focus(); }
            }
        }

        function cityLooksReal(value) {
            var folded = value.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

            if (!folded) { return false; }
            if (QO_PLACEHOLDER_CITIES.indexOf(folded) !== -1) { return false; }

            /* Letters and digits only, so "A." is short and "سلا" is not. */
            return value.replace(/[^\p{L}\p{N}]+/gu, '').length >= 2;
        }

        function validate() {
            var n = document.getElementById('qo_name').value.trim();
            var p = document.getElementById('qo_phone').value.trim();
            var a = document.getElementById('qo_addr').value.trim();
            var c = document.getElementById('qo_city').value.trim();
            var z = document.getElementById('qo_zip').value.trim();
            var q = getQuantity();

            if (!n || n.length < 3) { markInvalid('qo_name');  return QO_MSG.err_name; }
            if (p.replace(/[^0-9]/g, '').length < 8) { markInvalid('qo_phone'); return QO_MSG.err_phone; }
            if (!a || a.length < 5) { markInvalid('qo_addr');  return QO_MSG.err_address; }
            if (!c) { markInvalid('qo_city'); return QO_MSG.err_city; }
            if (!cityLooksReal(c)) { markInvalid('qo_city'); return QO_MSG.err_city_real; }

            /* An empty postcode is an accepted answer. A non-empty one must
               look like a postcode — never silently replaced by a default. */
            if (z) {
                var digits = z.replace(/[\s\-]/g, '');
                if (!/^[0-9]{5}$/.test(digits) || digits === '00000') {
                    markInvalid('qo_zip');
                    return QO_MSG.err_zip;
                }
            }

            if (!q || q < 1) { markInvalid('qo_qty'); return QO_MSG.err_qty; }

            markInvalid(null);
            return null;
        }

        /* ── Submit ── */
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            clearMsg();

            var err = validate();
            if (err) { msg(err, false); return; }

            loading(true);

            /* Collect the ACTUAL variant selections from native PS selectors */
            var groups = collectGroupValues();
            var ipa    = tryReadIPA();

            console.log('[QO] group[] values:', JSON.stringify(groups));
            console.log('[QO] IPA (direct read):', ipa);

            var url = document.getElementById('qo_hid_url').value;
            var fd  = new FormData();

            fd.append('id_product',          document.getElementById('qo_hid_product').value);
            fd.append('id_product_attribute', String(ipa));
            fd.append('token',               document.getElementById('qo_hid_token').value);
            fd.append('ajax',                '1');
            fd.append('submitQuickOrder',    '1');
            fd.append('full_name',           document.getElementById('qo_name').value.trim());
            fd.append('phone',               document.getElementById('qo_phone').value.trim());
            fd.append('address',             document.getElementById('qo_addr').value.trim());

            /* QO-GEO-1 — WHAT THE CUSTOMER TYPED.
               This line used to read `fd.append('city', '')`, which is where
               every `-` in the address table came from. The value is sent
               verbatim: no canonicalisation, no spelling correction, no
               default. Interpreting it is somebody else's job, later. */
            fd.append('city',                document.getElementById('qo_city').value.trim());
            fd.append('postcode',            document.getElementById('qo_zip').value.trim());
            fd.append('quantity',            getQuantity());

            /* Append group[] values — the backend uses these to resolve
               the correct combination with the correct price */
            for (var key in groups) {
                if (groups.hasOwnProperty(key)) {
                    fd.append(key, groups[key]);
                }
            }

            /* ─────────────────────────────────────────────────────────────
               EXTENSION POINT — extra POST fields from other modules.

               Quick Order builds its request field by field and never
               serialises the page, so a hidden <input> added by another
               module is NOT sent. Modules that need something to travel with
               the submission listen for this event and put their fields in
               `detail.fields`.

               Deliberately generic: Quick Order knows no listener by name and
               depends on no other module. With nothing listening, the request
               body is byte-for-byte what it always was.

               Fires immediately before the request, so a listener sees the
               final product, quantity and variant selection.
               ───────────────────────────────────────────────────────────── */
            var qoContract = { fields: {} };

            try {
                document.dispatchEvent(new CustomEvent('quickorder:beforeSubmit', {
                    detail: qoContract
                }));
            } catch (extErr) {
                /* A listener that throws must not stop the customer ordering.
                   Its fields are simply absent, and the module that registered
                   it is responsible for noticing. */
                console.warn('[QO] a beforeSubmit listener failed:', extErr);
            }

            /* Field names Quick Order owns. A listener cannot overwrite one:
               allowing it would let any module change the product, the
               quantity or the CSRF token of an order it does not own. */
            var qoReserved = [
                'id_product', 'id_product_attribute', 'token', 'ajax',
                'submitQuickOrder', 'full_name', 'phone', 'address', 'city',
                'postcode', 'quantity', 'controller', 'fc', 'module'
            ];

            Object.keys(qoContract.fields || {}).forEach(function (name) {
                var value = qoContract.fields[name];

                if (qoReserved.indexOf(name) !== -1 || name.indexOf('group_') === 0) {
                    console.warn('[QO] refused a reserved field from a listener:', name);
                    return;
                }

                /* Scalars only. An object or an array would serialise as
                   "[object Object]" and mean nothing to the server. */
                if (value === null || value === undefined || value === '') { return; }
                if (typeof value === 'object' || typeof value === 'function') {
                    console.warn('[QO] refused a non-scalar field from a listener:', name);
                    return;
                }

                /* First writer wins, so two listeners cannot silently fight
                   over one name — the second is refused and says so. */
                if (fd.has(name)) {
                    console.warn('[QO] a field was already set by another listener:', name);
                    return;
                }

                fd.append(name, String(value));
            });

            console.log('[QO] POST → ' + url);

            fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(resp) {
                console.log('[QO] HTTP ' + resp.status + ' | URL: ' + resp.url);
                if (!resp.ok) throw new Error(QO_MSG.err_server + ': HTTP ' + resp.status);
                return resp.text();
            })
            .then(function(raw) {
                console.log('[QO] Raw response (' + raw.length + ' chars):', raw.substring(0, 500));

                /* Try strict JSON.parse first (clean response) */
                var trimmed = raw.trim();
                var data;
                try {
                    data = JSON.parse(trimmed);
                } catch(e1) {
                    /* Fallback: find JSON object in mixed HTML+JSON response */
                    var jsonStart = trimmed.indexOf('{"');
                    if (jsonStart === -1) {
                        console.error('[QO] No JSON in response. First 500 chars:', trimmed.substring(0, 500));
                        throw new Error(QO_MSG.err_invalid + ' [DEBUG: ' + trimmed.substring(0, 120) + ']');
                    }

                    var jsonStr = trimmed.substring(jsonStart);
                    var depth = 0, end = -1;
                    for (var ci = 0; ci < jsonStr.length; ci++) {
                        var ch = jsonStr[ci];
                        if (ch === '{') depth++;
                        else if (ch === '}') { depth--; if (depth === 0) { end = ci; break; } }
                        else if (ch === '"') {
                            ci++;
                            while (ci < jsonStr.length && jsonStr[ci] !== '"') {
                                if (jsonStr[ci] === '\\') ci++;
                                ci++;
                            }
                        }
                    }

                    if (end === -1) {
                        console.error('[QO] Malformed JSON:', trimmed.substring(0, 500));
                        throw new Error(QO_MSG.err_invalid + ' [DEBUG: ' + trimmed.substring(jsonStart, jsonStart + 120) + ']');
                    }

                    data = JSON.parse(jsonStr.substring(0, end + 1));
                }

                if (data.success && data.redirect_url) {
                    msg('\u2713 ' + (data.message || QO_MSG.ok_placed), true);
                    console.log('[QO] Redirect → ' + data.redirect_url);
                    setTimeout(function() { window.location.href = data.redirect_url; }, 800);
                } else {
                    throw new Error(data.message || QO_MSG.err_failed);
                }
            })
            .catch(function(ex) {
                console.error('[QO] Error:', ex);
                msg(ex.message || QO_MSG.err_generic, false);
                loading(false);
            });
        });
    }

    /* Boot */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
{/literal}
</script>
{/if}
