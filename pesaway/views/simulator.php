<?php
/** PesaWay non-FX, non-crypto UAT workspace. */
$ready = static fn(string $key): bool => (bool) ($configuration[$key] ?? false);
$newReference = static fn(): string => \App\PaymentReference::generate();
$resultJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
$today = gmdate('Y-m-d');
?>

<section class="page-intro">
  <div>
    <p class="eyebrow">PesaWay integration</p>
    <h1>Collections, payouts and services</h1>
    <p>Use the documented PesaWay products from one workspace. FX and crypto are not included.</p>
  </div>
  <span class="status-pill">Region: <?= \App\View::e(strtoupper($configuration['region'])) ?></span>
</section>

<section class="integration-checks">
  <article class="check-card <?= $ready('base_url_configured') ? 'ready' : 'attention' ?>"><span class="check-icon">1</span><div><strong>API URL</strong><p><?= $ready('base_url_configured') ? 'Configured' : 'Needed in .env' ?></p></div><span class="check-state"><?= $ready('base_url_configured') ? 'ready' : 'needed' ?></span></article>
  <article class="check-card <?= $ready('consumer_key_configured') && $ready('consumer_secret_configured') ? 'ready' : 'attention' ?>"><span class="check-icon">2</span><div><strong>OAuth</strong><p><?= $ready('consumer_key_configured') && $ready('consumer_secret_configured') ? 'Configured' : 'Add consumer key and secret' ?></p></div><span class="check-state"><?= $ready('consumer_key_configured') && $ready('consumer_secret_configured') ? 'ready' : 'needed' ?></span></article>
  <article class="check-card <?= $ready('merchant_code_configured') ? 'ready' : 'attention' ?>"><span class="check-icon">3</span><div><strong>Merchant</strong><p><?= $ready('merchant_code_configured') ? 'Configured' : 'Add merchant code' ?></p></div><span class="check-state"><?= $ready('merchant_code_configured') ? 'ready' : 'needed' ?></span></article>
  <article class="check-card <?= $ready('callback_is_https') ? 'ready' : 'attention' ?>"><span class="check-icon">4</span><div><strong>Callback</strong><p><?= $ready('callback_is_https') ? 'Public HTTPS URL configured' : 'Set a public HTTPS URL' ?></p></div><span class="check-state"><?= $ready('callback_is_https') ? 'ready' : 'needed' ?></span></article>
</section>

<section class="panel form-section pesaway-workspace">
  <div class="section-title"><span>◌</span><div><h3>PesaWay console</h3><p>Every provider request and response is recorded in Logs with secrets redacted.</p></div></div>

  <div class="pegasus-tabs" data-pesaway-tabs data-default-tab="<?= \App\View::e($active_tab) ?>">
    <button type="button" class="pegasus-tab" data-pesaway-tab="connection">Overview</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="channels">Channels</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="mobile">Mobile money</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="bank">Bank</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="airtime">Airtime</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="transactions">Transactions</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="refunds">Refunds</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="account">Account</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="sms">SMS</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="callbacks">Callbacks</button>
    <button type="button" class="pegasus-tab" data-pesaway-tab="logs">Logs</button>
  </div>

  <div data-pesaway-panel="connection">
    <div class="godigital-summary-grid">
      <div><span>Base URL</span><strong><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Not configured' ?></strong></div>
      <div><span>Region header</span><strong><?= \App\View::e(strtoupper($configuration['region'])) ?></strong></div>
      <div><span>OAuth credentials</span><strong><?= $ready('consumer_key_configured') && $ready('consumer_secret_configured') ? 'Configured' : 'Not configured' ?></strong></div>
    </div>
    <form method="post" action="<?= $url('/pesaway-tester/token') ?>" class="form-actions compact-actions"><button class="primary-action" type="submit">Check OAuth connection</button></form>
  </div>

  <form method="post" action="<?= $url('/pesaway-tester/channels') ?>" class="pesaway-operation-form" data-pesaway-panel="channels">
    <p class="helper-copy">Lists payment channels enabled for the merchant. Crypto channel data is removed before it is displayed.</p>
    <div class="compact-form-grid">
      <label>Transaction type<select name="transaction_type"><option value="Payins">Payins</option><option value="Payouts">Payouts</option></select></label>
      <label>Country (optional)<input name="country" maxlength="30" placeholder="TZ"></label>
      <label>Currency (optional)<input name="currency" maxlength="3" placeholder="TZS"></label>
      <label>Merchant code (optional)<input name="merchant_code" maxlength="100" placeholder="Use configured code"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Get active channels</button></div>
  </form>

  <div class="pesaway-operation-set" data-pesaway-panel="mobile">
    <form method="post" action="<?= $url('/pesaway-tester/mobile-b2c') ?>" class="pesaway-operation-form">
      <h4>Send to customer</h4><p class="helper-copy">B2C payout to a mobile number.</p>
      <div class="compact-form-grid">
        <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
        <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
        <label>Phone number<input name="phone_number" inputmode="numeric" placeholder="2557…" required></label>
        <label>Channel<input name="channel" value="Vodacom MPESA" required></label>
        <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
        <label>Reason<input name="reason" value="PesaWay payout" required></label>
      </div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send B2C payment</button></div>
    </form>

    <form method="post" action="<?= $url('/pesaway-tester/mobile-b2b') ?>" class="pesaway-operation-form">
      <h4>Send to business</h4><p class="helper-copy">B2B payout to a mobile-money account number.</p>
      <div class="compact-form-grid">
        <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
        <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
        <label>Account number<input name="account_number" required></label>
        <label>Channel<input name="channel" value="MPESA Paybill" required></label>
        <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
        <label>Reason<input name="reason" value="PesaWay business payout" required></label>
      </div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send B2B payment</button></div>
    </form>

    <form method="post" action="<?= $url('/pesaway-tester/mobile-c2b') ?>" class="pesaway-operation-form">
      <h4>Collect from customer</h4><p class="helper-copy">C2B collection request. Confirm the final result from a callback or query.</p>
      <div class="compact-form-grid">
        <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
        <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
        <label>Phone number<input name="phone_number" inputmode="numeric" placeholder="2557…" required></label>
        <label>Channel<input name="channel" value="Vodacom MPESA" required></label>
        <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
        <label>Reason<input name="reason" value="PesaWay collection" required></label>
      </div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request collection</button></div>
    </form>

    <div class="pesaway-split-forms">
      <form method="post" action="<?= $url('/pesaway-tester/mobile-authorize') ?>" class="pesaway-operation-form">
        <h4>Authorise with OTP</h4><div class="compact-form-grid"><label>Transaction ID<input name="transaction_id" required></label><label>OTP<input name="otp" inputmode="numeric" required></label></div><div class="form-actions compact-actions"><button class="outline-action" type="submit">Submit OTP</button></div>
      </form>
      <form method="post" action="<?= $url('/pesaway-tester/mobile-query') ?>" class="pesaway-operation-form">
        <h4>Query mobile status</h4><div class="compact-form-grid"><label class="wide">Transaction reference<input name="transaction_reference" value="<?= \App\View::e($last_reference) ?>" required></label></div><div class="form-actions compact-actions"><button class="outline-action" type="submit">Check status</button></div>
      </form>
    </div>
  </div>

  <div class="pesaway-operation-set" data-pesaway-panel="bank">
    <form method="post" action="<?= $url('/pesaway-tester/bank-payout') ?>" class="pesaway-operation-form">
      <h4>Bank payout</h4><p class="helper-copy">Send a payment to the bank account and bank name supplied by the recipient.</p>
      <div class="compact-form-grid">
        <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
        <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
        <label>Account number<input name="account_number" required></label>
        <label>Bank name<input name="bank_name" required></label>
        <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
        <label>Reason<input name="reason" value="PesaWay bank payout" required></label>
      </div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send bank payout</button></div>
    </form>
    <form method="post" action="<?= $url('/pesaway-tester/bank-query') ?>" class="pesaway-operation-form">
      <h4>Query bank status</h4><div class="compact-form-grid"><label class="wide">Transaction reference<input name="transaction_reference" value="<?= \App\View::e($last_reference) ?>" required></label></div><div class="form-actions compact-actions"><button class="outline-action" type="submit">Check status</button></div>
    </form>
  </div>

  <form method="post" action="<?= $url('/pesaway-tester/airtime') ?>" class="pesaway-operation-form" data-pesaway-panel="airtime">
    <p class="helper-copy">Send airtime to a customer phone number.</p>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
      <label>Phone number<input name="phone_number" inputmode="numeric" placeholder="2557…" required></label>
      <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
      <label class="wide">Description<input name="description" value="PesaWay airtime" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send airtime</button></div>
  </form>

  <form method="post" action="<?= $url('/pesaway-tester/pull') ?>" class="pesaway-operation-form" data-pesaway-panel="transactions">
    <p class="helper-copy">Retrieve a page of completed or in-progress collections, payments, or refunds.</p>
    <div class="compact-form-grid">
      <label>Start date<input type="date" name="start_date" value="<?= $today ?>" required></label>
      <label>End date<input type="date" name="end_date" value="<?= $today ?>" required></label>
      <label>Transaction type<select name="transaction_type"><option value="Collection">Collection</option><option value="Payment">Payment</option><option value="Refund">Refund</option></select></label>
      <label>Status (optional)<select name="status"><option value="">All/default</option><option value="Complete">Complete</option><option value="Processing">Processing</option><option value="Failed">Failed</option><option value="Rejected">Rejected</option></select></label>
      <label>Offset<input name="offset" type="number" min="0" value="0" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Pull transactions</button></div>
  </form>

  <form method="post" action="<?= $url('/pesaway-tester/refund') ?>" class="pesaway-operation-form" data-pesaway-panel="refunds">
    <p class="helper-copy">The request receives PesaWay’s documented timestamp and HMAC signature. Set the separate refund secret first.</p>
    <div class="compact-form-grid">
      <label>Original reference<input name="original_reference" required></label>
      <label>New refund reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount<input name="amount" inputmode="decimal" value="1000.00" required></label>
      <label>Currency<input name="currency" value="TZS" maxlength="3" required></label>
      <label class="wide">Reason<input name="reason" value="Customer refund" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request refund</button></div>
  </form>

  <form method="post" action="<?= $url('/pesaway-tester/balance') ?>" class="pesaway-operation-form" data-pesaway-panel="account">
    <p class="helper-copy">Checks the configured merchant account balance.</p>
    <div class="compact-form-grid"><label>Currency (optional)<input name="currency" maxlength="3" placeholder="TZS"></label><label>Merchant code (optional)<input name="merchant_code" maxlength="100" placeholder="Use configured code"></label></div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check account balance</button></div>
  </form>

  <div class="pesaway-operation-set" data-pesaway-panel="sms">
    <form method="post" action="<?= $url('/pesaway-tester/sms') ?>" class="pesaway-operation-form">
      <h4>Send SMS</h4><p class="helper-copy">Separate multiple recipient numbers with commas or new lines.</p>
      <div class="compact-form-grid"><label class="wide">Message<input name="message" value="Your payment is being processed." required></label><label class="wide">Destination<input name="destination" inputmode="numeric" placeholder="2557…" required></label></div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send SMS</button></div>
    </form>
    <form method="post" action="<?= $url('/pesaway-tester/sms-balance') ?>" class="pesaway-operation-form"><h4>SMS balance</h4><div class="form-actions compact-actions"><button class="outline-action" type="submit">Check SMS balance</button></div></form>
  </div>

  <div data-pesaway-panel="callbacks">
    <div class="godigital-summary-grid one-column"><div><span>Callback endpoint</span><strong class="copyable-value"><?= \App\View::e($configuration['callback_url'] ?: 'Set APP_URL or PESAWAY_CALLBACK_URL') ?></strong></div></div>
    <div class="warning"><strong>Register this endpoint with PesaWay</strong><p>It accepts payment callbacks and returns HTTP 200 after recording them. Generic callbacks can use <code>Signature</code>; refund callbacks use <code>X-Timestamp</code>, <code>X-Signature</code>, and <code>X-Event-ID</code> when their matching secrets are configured.</p></div>
    <pre class="code-block"><code><?= \App\View::e(json_encode(['status' => 'RECEIVED', 'duplicate' => false], JSON_PRETTY_PRINT)) ?></code></pre>
  </div>

  <div data-pesaway-panel="logs">
    <?php if ($activity): ?>
      <div class="event-list godigital-events">
        <?php foreach ($activity as $entry): ?>
          <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e($entry['message'] ?? '') ?></strong><small><?= \App\View::e((string) ($entry['at'] ?? '')) ?></small><?php if (!empty($entry['context'])): ?><details class="log-context"><summary>Details</summary><pre><?= \App\View::e(json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="event-empty"><span>◌</span><p>Requests, responses, and callbacks appear here.</p></div>
    <?php endif; ?>
  </div>

  <?php if ($result): ?>
    <section class="godigital-result" data-pesaway-panel="<?= \App\View::e($active_tab) ?>"><div class="section-title"><span>✓</span><div><h3>Latest response</h3><p>Credentials, signatures, OTPs, and account numbers are excluded.</p></div></div><pre class="code-block"><code><?= \App\View::e($resultJson) ?></code></pre></section>
  <?php endif; ?>
</section>

<section class="api-test-band"><div><p class="eyebrow">Server API</p><h3>PesaWay API</h3><p>Use a dashboard-issued <code>X-API-Key</code>.</p></div><a class="primary-action" href="<?= $url('/api/v1/pesaway/openapi.json') ?>">Open API JSON</a></section>

<script>
(() => {
  const tabs = document.querySelector('[data-pesaway-tabs]');
  const show = (tab) => {
    document.querySelectorAll('[data-pesaway-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.pesawayTab === tab));
    document.querySelectorAll('[data-pesaway-panel]').forEach((panel) => { panel.hidden = panel.dataset.pesawayPanel !== tab; });
  };
  tabs.querySelectorAll('[data-pesaway-tab]').forEach((button) => button.addEventListener('click', () => show(button.dataset.pesawayTab)));
  show(tabs.dataset.defaultTab || 'connection');
})();
</script>
