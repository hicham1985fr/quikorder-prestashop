{extends file='page.tpl'}

{block name='page_title'}
  {if $qo_valid}
    {l s='Order Confirmed' mod='quickorder'}
  {else}
    {l s='Order Not Found' mod='quickorder'}
  {/if}
{/block}

{block name='page_content'}
<style>
  /* ═══════════ Quick Order — Thank You Page ═══════════ */
  .qo-ty { max-width: 680px; margin: 0 auto 40px; font-family: inherit; color: #333; }
  .qo-ty *, .qo-ty *::before, .qo-ty *::after { box-sizing: border-box; }

  /* ── Success banner ── */
  .qo-ty-banner {
      text-align: center;
      padding: 32px 20px 28px;
      background: linear-gradient(135deg, #e8f8ee 0%, #f0fdf4 100%);
      border: 1px solid #b8e6c8;
      border-radius: 12px;
      margin-bottom: 20px;
  }
  .qo-ty-banner-icon {
      width: 64px; height: 64px; margin: 0 auto 14px;
      background: #27ae60; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
  }
  .qo-ty-banner-icon svg { width: 32px; height: 32px; fill: none; stroke: #fff; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
  .qo-ty-banner h2 { margin: 0 0 6px; font-size: 22px; font-weight: 700; color: #1a5632; }
  .qo-ty-banner p { margin: 0; font-size: 14px; color: #4a7c5c; }

  /* ── Info grid (reference, date, status) ── */
  .qo-ty-info {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-bottom: 20px;
  }
  .qo-ty-info-item {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 14px 16px;
      text-align: center;
  }
  .qo-ty-info-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 4px; }
  .qo-ty-info-value { font-size: 15px; font-weight: 700; color: #222; word-break: break-all; }

  /* ── Card sections ── */
  .qo-ty-card {
      background: #fff;
      border: 1px solid #e9ecef;
      border-radius: 10px;
      margin-bottom: 16px;
      overflow: hidden;
  }
  .qo-ty-card-hdr {
      padding: 14px 18px;
      font-size: 14px;
      font-weight: 700;
      color: #555;
      background: #f8f9fa;
      border-bottom: 1px solid #e9ecef;
      display: flex;
      align-items: center;
      gap: 8px;
  }
  .qo-ty-card-hdr svg { width: 18px; height: 18px; fill: none; stroke: #e67e22; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  .qo-ty-card-body { padding: 18px; }

  /* ── Product row ── */
  .qo-ty-product {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 12px 0;
  }
  .qo-ty-product + .qo-ty-product { border-top: 1px solid #f0f0f0; }
  .qo-ty-product-img {
      width: 72px; height: 72px; border-radius: 8px;
      background: #f5f5f5; flex-shrink: 0;
      overflow: hidden; display: flex; align-items: center; justify-content: center;
  }
  .qo-ty-product-img img { width: 100%; height: 100%; object-fit: cover; }
  .qo-ty-product-img .qo-no-img { font-size: 28px; color: #ccc; }
  .qo-ty-product-info { flex: 1; min-width: 0; }
  .qo-ty-product-name { font-size: 14px; font-weight: 600; color: #222; margin-bottom: 3px; }
  .qo-ty-product-attrs { font-size: 12px; color: #888; margin-bottom: 3px; }
  .qo-ty-product-qty { font-size: 12px; color: #666; }
  .qo-ty-product-price { text-align: right; flex-shrink: 0; }
  .qo-ty-product-price .unit { font-size: 12px; color: #888; }
  .qo-ty-product-price .total { font-size: 15px; font-weight: 700; color: #222; }

  /* ── Customer / Address details ── */
  .qo-ty-detail-row {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      font-size: 14px;
  }
  .qo-ty-detail-row + .qo-ty-detail-row { border-top: 1px solid #f5f5f5; }
  .qo-ty-detail-label { color: #888; flex-shrink: 0; }
  .qo-ty-detail-value { color: #222; font-weight: 500; text-align: right; word-break: break-word; max-width: 65%; }

  /* ── Totals ── */
  .qo-ty-totals { padding: 14px 18px; }
  .qo-ty-total-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
  .qo-ty-total-row.sub { color: #666; }
  .qo-ty-total-row.grand {
      border-top: 2px solid #e67e22;
      margin-top: 8px; padding-top: 12px;
      font-size: 18px; font-weight: 700; color: #e67e22;
  }

  /* ── COD badge ── */
  .qo-ty-cod {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 18px;
      background: #fef9f0;
      border: 1px solid #f5deb3;
      border-radius: 10px;
      margin-bottom: 16px;
  }
  .qo-ty-cod-icon {
      width: 40px; height: 40px; border-radius: 50%;
      background: #e67e22; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
  .qo-ty-cod-icon svg { width: 20px; height: 20px; fill: none; stroke: #fff; stroke-width: 2; }
  .qo-ty-cod-text strong { display: block; font-size: 14px; color: #222; }
  .qo-ty-cod-text span { font-size: 12px; color: #888; }

  /* ── Actions ── */
  .qo-ty-actions { text-align: center; margin-top: 24px; }
  .qo-ty-btn {
      display: inline-block;
      padding: 13px 36px;
      font-size: 15px;
      font-weight: 700;
      color: #fff;
      background: #e67e22;
      border: none;
      border-radius: 8px;
      text-decoration: none;
      transition: background .2s, transform .1s;
  }
  .qo-ty-btn:hover { background: #cf6d17; color: #fff; text-decoration: none; transform: translateY(-1px); }

  /* ── Error state ── */
  .qo-ty-error {
      text-align: center;
      padding: 48px 20px;
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 12px;
  }
  .qo-ty-error-icon { width: 64px; height: 64px; margin: 0 auto 14px; background: #e74c3c; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; }
  .qo-ty-error-icon svg { width: 28px; height: 28px; fill: none; stroke: #fff; stroke-width: 3; stroke-linecap: round; }
  .qo-ty-error h2 { margin: 0 0 8px; font-size: 20px; color: #991b1b; }
  .qo-ty-error p { margin: 0 0 20px; font-size: 14px; color: #b91c1c; }

  /* ── Responsive ── */
  @media (max-width: 600px) {
      .qo-ty-info { grid-template-columns: 1fr; }
      .qo-ty-product { flex-wrap: wrap; }
      .qo-ty-product-price { width: 100%; text-align: left; margin-top: 4px; }
      .qo-ty-detail-value { max-width: 55%; }
      .qo-ty-total-row.grand { font-size: 16px; }
  }

  /* ── Related Products (Upselling) ── */
  .qo-ty-related { margin-top: 32px; padding-top: 28px; border-top: 2px solid #f0f0f0; }
  .qo-ty-related-title {
      font-size: 18px; font-weight: 700; color: #222;
      margin: 0 0 16px; text-align: center; letter-spacing: -.2px;
  }
  .qo-ty-related-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
  }
  .qo-ty-related-card {
      display: flex; flex-direction: column;
      border: 1px solid #e9ecef; border-radius: 10px;
      overflow: hidden; text-decoration: none; color: inherit;
      background: #fff; transition: box-shadow .2s, transform .15s;
  }
  .qo-ty-related-card:hover {
      box-shadow: 0 6px 20px rgba(0,0,0,.10);
      transform: translateY(-3px); color: inherit; text-decoration: none;
  }
  .qo-ty-related-img {
      width: 100%; aspect-ratio: 1 / 1; overflow: hidden;
      background: #f5f5f5; display: flex; align-items: center; justify-content: center;
  }
  .qo-ty-related-img img { width: 100%; height: 100%; object-fit: cover; }
  .qo-ty-related-info { padding: 8px 10px 4px; flex: 1; }
  .qo-ty-related-name {
      font-size: 12px; font-weight: 600; color: #222; margin-bottom: 4px;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  .qo-ty-related-price { font-size: 13px; font-weight: 700; color: #e67e22; }
  .qo-ty-related-cta {
      margin: 8px 10px 10px; padding: 7px 4px; text-align: center;
      background: #e67e22; color: #fff; border-radius: 6px;
      font-size: 12px; font-weight: 700; transition: background .2s;
  }
  .qo-ty-related-card:hover .qo-ty-related-cta { background: #cf6d17; }
  @media (max-width: 500px) {
      .qo-ty-related-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  }
</style>

<div class="qo-ty">
  {if $qo_valid}

    {* ── Success Banner ── *}
    <div class="qo-ty-banner">
      <div class="qo-ty-banner-icon">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
      </div>
      <h2>{l s='Thank You for Your Order!' mod='quickorder'}</h2>
      <p>{l s='Your order has been placed successfully. We will contact you soon.' mod='quickorder'}</p>
    </div>

    {* ── Info Grid ── *}
    <div class="qo-ty-info">
      <div class="qo-ty-info-item">
        <div class="qo-ty-info-label">{l s='Order Reference' mod='quickorder'}</div>
        <div class="qo-ty-info-value">{$qo_reference|escape:'htmlall':'UTF-8'}</div>
      </div>
      <div class="qo-ty-info-item">
        <div class="qo-ty-info-label">{l s='Date' mod='quickorder'}</div>
        <div class="qo-ty-info-value">{$qo_date|escape:'htmlall':'UTF-8'}</div>
      </div>
      <div class="qo-ty-info-item">
        <div class="qo-ty-info-label">{l s='Status' mod='quickorder'}</div>
        <div class="qo-ty-info-value">{$qo_status|escape:'htmlall':'UTF-8'}</div>
      </div>
    </div>

    {* ── Products ── *}
    <div class="qo-ty-card">
      <div class="qo-ty-card-hdr">
        <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
        {l s='Products Ordered' mod='quickorder'}
      </div>
      <div class="qo-ty-card-body">
        {foreach $qo_products as $product}
          <div class="qo-ty-product">
            <div class="qo-ty-product-img">
              {if $product.image_url}
                <img src="{$product.image_url|escape:'htmlall':'UTF-8'}" alt="{$product.name|escape:'htmlall':'UTF-8'}" loading="lazy">
              {else}
                <span class="qo-no-img">&#128722;</span>
              {/if}
            </div>
            <div class="qo-ty-product-info">
              <div class="qo-ty-product-name">{$product.name|escape:'htmlall':'UTF-8'}</div>
              {if $product.attributes}
                <div class="qo-ty-product-attrs">{$product.attributes|escape:'htmlall':'UTF-8'}</div>
              {/if}
              <div class="qo-ty-product-qty">{l s='Qty:' mod='quickorder'} {$product.quantity|intval}</div>
            </div>
            <div class="qo-ty-product-price">
              {if $product.quantity > 1}
                <div class="unit">{$product.unit_price nofilter} × {$product.quantity|intval}</div>
              {/if}
              <div class="total">{$product.total nofilter}</div>
            </div>
          </div>
        {/foreach}
      </div>

      {* ── Totals ── *}
      <div class="qo-ty-totals">
        <div class="qo-ty-total-row sub">
          <span>{l s='Subtotal' mod='quickorder'}</span>
          <span>{$qo_total_products nofilter}</span>
        </div>
        {if $qo_has_shipping}
          <div class="qo-ty-total-row sub">
            <span>{l s='Shipping' mod='quickorder'}</span>
            <span>{$qo_total_shipping nofilter}</span>
          </div>
        {else}
          <div class="qo-ty-total-row sub">
            <span>{l s='Shipping' mod='quickorder'}</span>
            <span>{l s='Free' mod='quickorder'}</span>
          </div>
        {/if}
        <div class="qo-ty-total-row grand">
          <span>{l s='Total' mod='quickorder'}</span>
          <span>{$qo_total_paid nofilter}</span>
        </div>
      </div>
    </div>

    {* ── Payment Method ── *}
    <div class="qo-ty-cod">
      <div class="qo-ty-cod-icon">
        <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
      </div>
      <div class="qo-ty-cod-text">
        <strong>{l s='Cash on Delivery' mod='quickorder'}</strong>
        <span>{l s='Payment upon receiving your order' mod='quickorder'}</span>
      </div>
    </div>

    {* ── Customer & Delivery Info ── *}
    <div class="qo-ty-card">
      <div class="qo-ty-card-hdr">
        <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
        {l s='Delivery Details' mod='quickorder'}
      </div>
      <div class="qo-ty-card-body">
        <div class="qo-ty-detail-row">
          <span class="qo-ty-detail-label">{l s='Name' mod='quickorder'}</span>
          <span class="qo-ty-detail-value">{$qo_customer_name|escape:'htmlall':'UTF-8'}</span>
        </div>
        {if $qo_phone}
          <div class="qo-ty-detail-row">
            <span class="qo-ty-detail-label">{l s='Phone' mod='quickorder'}</span>
            <span class="qo-ty-detail-value">{$qo_phone|escape:'htmlall':'UTF-8'}</span>
          </div>
        {/if}
        {if $qo_address}
          <div class="qo-ty-detail-row">
            <span class="qo-ty-detail-label">{l s='Address' mod='quickorder'}</span>
            <span class="qo-ty-detail-value">{$qo_address|escape:'htmlall':'UTF-8'}</span>
          </div>
        {/if}
      </div>
    </div>

    {* ── Seller Facebook Pixel(s) ── *}
    {if isset($qo_seller_pixels) && $qo_seller_pixels|count > 0}
      {foreach $qo_seller_pixels as $pixel_id}
        {if $pixel_id}
{literal}
<!-- Facebook Pixel Code for Seller -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window,document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
{/literal}
  fbq('init', '{$pixel_id|escape:'javascript':'UTF-8'}');
  fbq('track', 'PageView');
  fbq('track', 'Purchase', {ldelim}
    value: {$qo_total_paid_raw|floatval},
    currency: '{$qo_currency_iso|escape:'javascript':'UTF-8'}',
    content_type: 'product',
    order_id: '{$qo_reference|escape:'javascript':'UTF-8'}'
  {rdelim});
{literal}
</script>
<noscript>
  <img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={/literal}{$pixel_id|escape:'url':'UTF-8'}{literal}&ev=Purchase&noscript=1"/>
</noscript>
<!-- End Facebook Pixel Code -->
{/literal}
        {/if}
      {/foreach}
    {/if}

    {* ── Action ──
       The Marketplace, not the homepage: this CTA follows a completed
       purchase, and the error state below is the one that belongs on « accueil ». *}
    <div class="qo-ty-actions">
      <a href="{$qo_shop_url|escape:'htmlall':'UTF-8'}" class="qo-ty-btn">
        {l s='Continue Shopping' mod='quickorder'}
      </a>
    </div>

    {* ── Related Products ── *}
    {if isset($qo_related_products) && $qo_related_products|count > 0}
      <div class="qo-ty-related">
        <h3 class="qo-ty-related-title">{l s='You May Also Like' mod='quickorder'}</h3>
        <div class="qo-ty-related-grid">
          {foreach $qo_related_products as $related}
            <a href="{$related.url|escape:'htmlall':'UTF-8'}" class="qo-ty-related-card">
              <div class="qo-ty-related-img">
                {if $related.image_url}
                  <img src="{$related.image_url|escape:'htmlall':'UTF-8'}"
                       alt="{$related.name|escape:'htmlall':'UTF-8'}" loading="lazy">
                {else}
                  <span class="qo-no-img">&#128722;</span>
                {/if}
              </div>
              <div class="qo-ty-related-info">
                <div class="qo-ty-related-name">{$related.name|escape:'htmlall':'UTF-8'}</div>
                <div class="qo-ty-related-price">{$related.price nofilter}</div>
              </div>
              <div class="qo-ty-related-cta">{l s='Shop Now' mod='quickorder'}</div>
            </a>
          {/foreach}
        </div>
      </div>
    {/if}

  {else}

    {* ── Error State ── *}
    <div class="qo-ty-error">
      <div class="qo-ty-error-icon">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </div>
      <h2>{l s='Order Not Found' mod='quickorder'}</h2>
      <p>{l s='This link is invalid or has expired.' mod='quickorder'}</p>
      <a href="{$qo_home_url|escape:'htmlall':'UTF-8'}" class="qo-ty-btn">
        {l s='Back to Home' mod='quickorder'}
      </a>
    </div>

  {/if}
</div>
{/block}
