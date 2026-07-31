<?php
/**
 * Quick Order — Live Kiosk Display
 *
 * Standalone page for the big confirmation-team screen.
 * No PrestaShop session required. Auth via ?token= URL parameter.
 */

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$tokenEncoded = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Twiza Shop — طلبات جديدة</title>
<style>
  /* ── Reset & Base ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: #0f172a;
    color: #e2e8f0;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
  }

  /* ── Header bar ── */
  .header {
    background: #1e293b;
    border-bottom: 2px solid #22c55e;
    padding: 14px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
  }
  .header-title {
    font-size: 1.6rem;
    font-weight: 700;
    color: #22c55e;
    letter-spacing: 0.03em;
  }
  .header-title span {
    color: #94a3b8;
    font-weight: 400;
    font-size: 1.1rem;
    margin-right: 12px;
  }
  .clock {
    font-size: 1.8rem;
    font-weight: 700;
    color: #f1f5f9;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.05em;
  }

  /* ── Orders grid ── */
  .orders-grid {
    flex: 1;
    padding: 24px 20px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 18px;
    align-content: start;
  }

  /* ── Order card ── */
  .order-card {
    background: #1e293b;
    border: 2px solid #334155;
    border-radius: 12px;
    padding: 18px 20px;
    transition: border-color 0.6s ease, box-shadow 0.6s ease;
    position: relative;
    overflow: hidden;
  }

  .order-card.new-order {
    border-color: #22c55e;
    animation: pulse-glow 2s ease-in-out infinite;
  }

  @keyframes pulse-glow {
    0%   { box-shadow: 0 0 0   0   rgba(34,197,94,0.5); }
    50%  { box-shadow: 0 0 22px 6px rgba(34,197,94,0.35); }
    100% { box-shadow: 0 0 0   0   rgba(34,197,94,0.5); }
  }

  .order-card.new-order::before {
    content: '★ جديد';
    position: absolute;
    top: 0; left: 0;
    background: #22c55e;
    color: #0f172a;
    font-size: 0.75rem;
    font-weight: 800;
    padding: 3px 10px;
    border-bottom-right-radius: 8px;
    letter-spacing: 0.05em;
  }

  .card-ref {
    font-size: 1.5rem;
    font-weight: 800;
    color: #22c55e;
    margin-bottom: 12px;
    margin-top: 10px;
  }

  .card-row {
    display: flex;
    flex-direction: column;
    margin-bottom: 10px;
  }

  .card-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    margin-bottom: 2px;
  }

  .card-value {
    font-size: 1.15rem;
    font-weight: 600;
    color: #f1f5f9;
    line-height: 1.3;
  }

  .card-value.phone {
    font-size: 1.4rem;
    font-weight: 800;
    color: #fbbf24;
    letter-spacing: 0.04em;
  }

  .card-value.total {
    font-size: 1.3rem;
    font-weight: 800;
    color: #818cf8;
  }

  .card-time {
    font-size: 0.72rem;
    color: #475569;
    margin-top: 10px;
    text-align: left;
  }

  /* ── Empty state ── */
  .empty-state {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    color: #334155;
  }
  .empty-state .icon { font-size: 5rem; margin-bottom: 16px; }
  .empty-state p { font-size: 1.3rem; }

  /* ── Status bar ── */
  .status-bar {
    background: #1e293b;
    border-top: 1px solid #334155;
    padding: 8px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.82rem;
    color: #64748b;
    flex-shrink: 0;
  }

  .status-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #22c55e;
    flex-shrink: 0;
  }

  .status-dot.error {
    background: #ef4444;
    animation: blink 1s step-start infinite;
  }

  @keyframes blink {
    50% { opacity: 0; }
  }

  .status-text { color: #94a3b8; }
  .status-text.error { color: #f87171; }

  /* ── No token error page ── */
  .error-page {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 16px;
    color: #ef4444;
    text-align: center;
    padding: 40px;
  }
  .error-page h1 { font-size: 2rem; }
  .error-page p  { font-size: 1rem; color: #94a3b8; }

  /* ── Slide-in animation for new cards ── */
  @keyframes slide-in {
    from { opacity: 0; transform: translateY(-20px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)     scale(1);    }
  }
  .order-card { animation: slide-in 0.35s ease-out; }
</style>
</head>
<body>

<?php if (empty($token)): ?>
<div class="error-page">
  <h1>⚠ رابط غير صحيح</h1>
  <p>يرجى استخدام الرابط الكامل من إعدادات الموديول — يجب أن يحتوي على <code>?token=...</code></p>
</div>
<?php else: ?>

<header class="header">
  <div class="header-title">
    <span>Twiza Shop</span>طلبات جديدة
  </div>
  <div class="clock" id="clock">--:--:--</div>
</header>

<main class="orders-grid" id="ordersGrid">
  <div class="empty-state" id="emptyState">
    <div class="icon">📋</div>
    <p>في انتظار الطلبات…</p>
  </div>
</main>

<footer class="status-bar">
  <div class="status-indicator">
    <div class="status-dot" id="statusDot"></div>
    <span class="status-text" id="statusText">متصل</span>
  </div>
  <span id="lastUpdate">—</span>
</footer>

<script>
(function () {
  'use strict';

  var TOKEN        = '<?= $tokenEncoded ?>';
  var POLL_URL     = 'poll.php?token=' + TOKEN;
  var POLL_INTERVAL     = 3000;   // ms between polls
  var HIGHLIGHT_DURATION = 30000; // ms card stays "new" (green glow)
  var MAX_CARDS         = 16;     // max cards on screen at once

  var grid       = document.getElementById('ordersGrid');
  var emptyState = document.getElementById('emptyState');
  var statusDot  = document.getElementById('statusDot');
  var statusText = document.getElementById('statusText');
  var lastUpdate = document.getElementById('lastUpdate');

  var audioCtx   = null;

  /* ── Clock ── */
  function updateClock() {
    var now = new Date();
    var h = String(now.getHours()).padStart(2, '0');
    var m = String(now.getMinutes()).padStart(2, '0');
    var s = String(now.getSeconds()).padStart(2, '0');
    document.getElementById('clock').textContent = h + ':' + m + ':' + s;
  }
  setInterval(updateClock, 1000);
  updateClock();

  /* ── Audio ── */
  function initAudio() {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
  }

  function playBeep() {
    try {
      initAudio();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      var osc  = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.type = 'sine';
      /* Two-tone alert: high then low */
      osc.frequency.setValueAtTime(880, audioCtx.currentTime);
      osc.frequency.setValueAtTime(660, audioCtx.currentTime + 0.18);
      gain.gain.setValueAtTime(0.45, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.55);
      osc.start(audioCtx.currentTime);
      osc.stop(audioCtx.currentTime + 0.6);
    } catch (e) { /* ignore audio errors */ }
  }

  /* Allow audio after first user gesture */
  document.addEventListener('click', initAudio, { once: true });
  document.addEventListener('touchstart', initAudio, { once: true });

  /* ── Status bar ── */
  function setConnected(ok, time) {
    if (ok) {
      statusDot.className  = 'status-dot';
      statusText.className = 'status-text';
      statusText.textContent = 'متصل';
      if (time) {
        lastUpdate.textContent = 'آخر تحديث: ' + time;
      }
    } else {
      statusDot.className  = 'status-dot error';
      statusText.className = 'status-text error';
      statusText.textContent = 'جاري إعادة الاتصال…';
    }
  }

  /* ── Format time string from DB datetime ── */
  function formatTime(dateStr) {
    /* dateStr: "2025-04-27 14:32:05" */
    var parts = dateStr ? dateStr.split(' ') : [];
    return parts[1] ? parts[1].substring(0, 5) : dateStr;
  }

  /* ── Format total ── */
  function formatTotal(val) {
    return parseFloat(val).toFixed(2) + ' MAD';
  }

  /* ── Build card HTML ── */
  function buildCard(order) {
    var card = document.createElement('div');
    card.className = 'order-card new-order';
    card.dataset.idNotification = order.id_notification;

    card.innerHTML =
      '<div class="card-ref">#' + escHtml(order.reference) + '</div>' +

      '<div class="card-row">' +
        '<div class="card-label">الاسم</div>' +
        '<div class="card-value">' + escHtml(order.customer_name) + '</div>' +
      '</div>' +

      '<div class="card-row">' +
        '<div class="card-label">الهاتف</div>' +
        '<div class="card-value phone">' + escHtml(order.phone) + '</div>' +
      '</div>' +

      '<div class="card-row">' +
        '<div class="card-label">العنوان</div>' +
        '<div class="card-value">' + escHtml(order.address) + '</div>' +
      '</div>' +

      '<div class="card-row">' +
        '<div class="card-label">المنتج</div>' +
        '<div class="card-value">' + escHtml(order.product_name) + ' × ' + escHtml(order.quantity) + '</div>' +
      '</div>' +

      '<div class="card-row">' +
        '<div class="card-label">المبلغ</div>' +
        '<div class="card-value total">' + formatTotal(order.total_paid) + '</div>' +
      '</div>' +

      '<div class="card-time">' + escHtml(formatTime(order.created_at)) + '</div>';

    /* Remove "new" class after HIGHLIGHT_DURATION */
    setTimeout(function () {
      card.classList.remove('new-order');
    }, HIGHLIGHT_DURATION);

    return card;
  }

  /* ── Render new orders ── */
  function renderOrders(orders) {
    if (!orders || orders.length === 0) return;

    /* Hide empty state */
    if (emptyState.parentNode) {
      emptyState.remove();
    }

    /* Prepend cards (newest first) */
    for (var i = orders.length - 1; i >= 0; i--) {
      var card = buildCard(orders[i]);
      grid.insertBefore(card, grid.firstChild);
    }

    /* Trim oldest cards beyond MAX_CARDS */
    var cards = grid.querySelectorAll('.order-card');
    for (var j = MAX_CARDS; j < cards.length; j++) {
      cards[j].remove();
    }

    playBeep();
  }

  /* ── Poll ── */
  function poll() {
    fetch(POLL_URL, { cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        var now = new Date();
        var hh  = String(now.getHours()).padStart(2, '0');
        var mm  = String(now.getMinutes()).padStart(2, '0');
        var ss  = String(now.getSeconds()).padStart(2, '0');
        setConnected(true, hh + ':' + mm + ':' + ss);
        renderOrders(data.orders);
      })
      .catch(function () {
        setConnected(false, null);
        /* fetch() will retry on next interval automatically */
      });
  }

  /* ── Escape HTML ── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ── Start ── */
  poll();
  setInterval(poll, POLL_INTERVAL);

})();
</script>

<?php endif; ?>
</body>
</html>
