<?php
/** @var array $configuration */
/** @var array|null $result */
/** @var array $activity */
$resultJson = $result ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
$ready = static fn(string $key): bool => !empty($configuration[$key]);
$newReference = static fn(): string => \App\PaymentReference::generate();
$lastReference = $last_reference ?: $newReference();
?>

<section class="test-hero godigital-hero">
  <div>
    <h1>GoDigital</h1>
    <p>OAuth, collections, payouts, status, balance, and name checks.</p>
  </div>
  <span class="session-pill">TZS only</span>
</section>

<section class="check-grid godigital-check-grid">
  <article class="check-card <?= $ready('base_url_configured') ? 'ready' : 'attention' ?>">
    <span class="check-icon">1</span>
    <div><strong>Gateway</strong><p><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Add the API URL.' ?></p></div>
    <span class="check-state"><?= $ready('base_url_configured') ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div><strong>OAuth</strong><p><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'Credentials are loaded.' : 'Set the client credentials.' ?></p></div>
    <span class="check-state"><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $ready('callback_is_https') ? 'ready' : 'attention' ?>">
    <span class="check-icon">3</span>
    <div><strong>Callback</strong><p><?= $ready('callback_is_https') ? 'Public HTTPS URL configured.' : 'Set a public HTTPS URL.' ?></p></div>
    <span class="check-state"><?= $ready('callback_is_https') ? 'ready' : 'needed' ?></span>
  </article>
</section>

<section class="panel form-section godigital-workspace">
  <div class="section-title">
    <span>◉</span>
    <div>
      <h3>Test console</h3>
      <p>Confirm payments with a callback or status check.</p>
    </div>
  </div>

  <div class="pegasus-tabs" data-godigital-tabs data-default-tab="<?= \App\View::e($active_tab) ?>">
    <button type="button" class="pegasus-tab" data-godigital-tab="connection">OAuth</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="collection">Collect</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="disbursement">Payout</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="status">Status</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="balance">Balance</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="name-check">Name check</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="callbacks">Callback</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="activity">Logs</button>
  </div>

  <div data-godigital-panel="connection">
    <div class="godigital-summary-grid">
      <div><span>Base URL</span><strong><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Not configured' ?></strong></div>
      <div><span>Merchant ID</span><strong><?= $ready('merchant_id_configured') ? 'Configured' : 'Not configured' ?></strong></div>
      <div><span>OAuth credentials</span><strong><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'Configured' : 'Not configured' ?></strong></div>
    </div>
 
    <form method="post" action="<?= $url('/godigital-tester/token') ?>" class="form-actions compact-actions">
      <button class="primary-action" type="submit">Check OAuth connection</button>
    </form>
  </div>

  <form method="post" action="<?= $url('/godigital-tester/collection') ?>" class="godigital-payment-form" data-godigital-panel="collection">
 
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($lastReference) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount (TZS)<input name="amount" inputmode="decimal" value="10000.00" required></label>
      <label>Provider<select name="provider_code" data-godigital-provider required><option value="VODACOM" data-test-msisdn="255766271010">Vodacom (M-Pesa)</option><option value="YAS" data-test-msisdn="255712691323">Yas (Mixx)</option><option value="AIRTEL" data-test-msisdn="255692478295">Airtel</option><option value="HALOTEL" data-test-msisdn="255622160766">Halotel (HaloPesa)</option></select></label>
      <label>Customer MSISDN<input name="msisdn" data-godigital-msisdn inputmode="numeric" value="255766271010" pattern="255[0-9]{9}" required></label>
      <label class="wide">Narration<input name="narration" value="GoDigital collection" maxlength="200"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request collection</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/disbursement') ?>" class="godigital-payment-form" data-godigital-panel="disbursement">
  
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount (TZS)<input name="amount" inputmode="decimal" value="10000.00" required></label>
      <label>Provider<select name="provider_code" data-godigital-provider required>
        <option value="VODACOM" data-test-msisdn="255766271010">Vodacom (M-Pesa)</option>
        <option value="YAS" data-test-msisdn="255712691323">Yas (Mixx)</option>
        <option value="AIRTEL" data-test-msisdn="255692478295">Airtel</option>
      <option value="HALOTEL" data-test-msisdn="255622160766">Halotel (HaloPesa)</option></select></label>
      <label>Recipient MSISDN<input name="msisdn" data-godigital-msisdn inputmode="numeric" value="255766271010" pattern="255[0-9]{9}" required></label>
      <label class="wide">Narration<input name="narration" value="GoDigital payout" maxlength="200"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request payout</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/status') ?>" class="godigital-payment-form" data-godigital-panel="status">
    <p class="helper-copy">Use the payment reference.</p>
    <div class="compact-form-grid">
      <label class="wide">Payment reference<input name="reference" value="<?= \App\View::e($last_reference) ?>" placeholder="PMT0000000" maxlength="120" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check provider status</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/balance') ?>" class="godigital-payment-form" data-godigital-panel="balance">
    <p class="helper-copy">Leave blank to use the configured client ID.</p>
    <div class="compact-form-grid">
      <label class="wide">Client ID (optional)<input name="client_id" maxlength="120" placeholder="Configured client ID"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check wallet balance</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/name-check') ?>" class="godigital-payment-form" data-godigital-panel="name-check">
    <p class="helper-copy">Confirm the recipient before payment.</p>
    <div class="compact-form-grid">
      <label>Provider<select name="provider_code" data-godigital-provider required><option value="VODACOM" data-test-msisdn="255766271010">Vodacom (M-Pesa)</option><option value="YAS" data-test-msisdn="255712691323">Yas (Mixx)</option><option value="AIRTEL" data-test-msisdn="255692478295">Airtel</option><option value="HALOTEL" data-test-msisdn="255622160766">Halotel (HaloPesa)</option></select></label>
      <label>MSISDN<input name="msisdn" data-godigital-msisdn inputmode="numeric" value="255766271010" pattern="255[0-9]{9}" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check recipient name</button></div>
  </form>

  <div data-godigital-panel="callbacks">
    <div class="godigital-summary-grid one-column">
      <div><span>Callback endpoint</span><strong class="copyable-value"><?= \App\View::e($configuration['callback_url'] ?: 'Set APP_URL or GODIGITAL_CALLBACK_URL') ?></strong></div>
    </div>
    <div class="warning">
      <strong>Register this URL with GoDigital</strong>
      <p>It accepts JSON callbacks and acknowledges them with HTTP 200. <?= \App\View::e($configuration['signature_note']) ?></p>
    </div>
    <pre class="code-block"><code><?= \App\View::e(json_encode(['status' => 'RECEIVED', 'message' => 'Callback accepted'], JSON_PRETTY_PRINT)) ?></code></pre>
  </div>

  <div data-godigital-panel="activity">
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
    <section class="godigital-result" data-godigital-panel="<?= \App\View::e($active_tab) ?>">
      <div class="section-title"><span>✓</span><div><h3>Latest response</h3><p>Credentials are excluded.</p></div></div>
      <pre class="code-block"><code><?= \App\View::e($resultJson) ?></code></pre>
    </section>
  <?php endif; ?>
</section>

<section class="api-test-band">
  <div><p class="eyebrow">Server API</p><h3>GoDigital API</h3><p>Use a dashboard-issued <code>X-API-Key</code>.</p></div>
  <a class="primary-action" href="<?= $url('/api/v1/godigital/openapi.json') ?>">Open API JSON</a>
</section>

<script>
(() => {
  const tabs = document.querySelector('[data-godigital-tabs]');
  const show = (tab) => {
    document.querySelectorAll('[data-godigital-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.godigitalTab === tab));
    document.querySelectorAll('[data-godigital-panel]').forEach((panel) => { panel.hidden = panel.dataset.godigitalPanel !== tab; });
  };
  tabs.querySelectorAll('[data-godigital-tab]').forEach((button) => button.addEventListener('click', () => show(button.dataset.godigitalTab)));
  document.querySelectorAll('[data-godigital-provider]').forEach((provider) => {
    const msisdn = provider.closest('form')?.querySelector('[data-godigital-msisdn]');
    const setTestMsisdn = () => {
      const value = provider.selectedOptions[0]?.dataset.testMsisdn;
      if (msisdn && value) msisdn.value = value;
    };
    provider.addEventListener('change', setTestMsisdn);
    setTestMsisdn();
  });
  show(tabs.dataset.defaultTab || 'connection');
})();
</script>
