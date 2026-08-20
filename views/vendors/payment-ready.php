<section class="payment-ready-hero">
  <span class="success-mark">✓</span>
  <p class="eyebrow">Link created</p>
  <h1>Payment link ready</h1>
  <p>Use this link to open CyberSource checkout.</p>
</section>

<section class="payment-ready-grid">
  <article class="panel payment-ready-card">
    <div class="payment-summary">
      <div>
        <span>Vendor order</span>
        <strong><?= \App\View::e($link['invoice_number']) ?></strong>
      </div>
      <div>
        <span>Amount</span>
        <strong><?= \App\View::e($link['currency']) ?> <?= \App\View::e($link['amount']) ?></strong>
      </div>
    </div>
    <label>
      Payment URL
      <input readonly value="<?= \App\View::e($link['payment_url']) ?>">
    </label>
    <a class="primary-action full-action" href="<?= \App\View::e($link['payment_url']) ?>">Open payment page</a>
  </article>

  <article class="panel vendor-response-card">
    <div class="panel-header">
      <div>
        <h3>API response</h3>
      </div>
    </div>
    <pre><?= \App\View::e($api_response) ?></pre>
  </article>
</section>

<section class="next-step-band">
  <div>
    <h3>Confirm payment</h3>
    <p>Use a webhook or refresh the link status before fulfilling the order.</p>
  </div>
  <a class="outline-action" href="<?= $url('/test-center') ?>">Check status</a>
</section>
