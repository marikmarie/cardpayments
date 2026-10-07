<?php
/** @var array $configuration */
/** @var array|null $result */
/** @var array $activity */
$resultJson = $result ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
$ready = static fn(string $key): bool => !empty($configuration[$key]);
$newReference = static fn(string $prefix): string => $prefix . '-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
$lastReference = $last_reference ?: $newReference('GD-C2B');
?>

<section class="test-hero godigital-hero">
  <div>
    <p class="eyebrow">Tanzania mobile money · UAT workspace</p>
    <h1>Test GoDigital safely</h1>
    <p>Run OAuth, C2B collection, B2C payout, status, and wallet checks from one server-side workspace. Keys never appear in this browser.</p>
  </div>
  <span class="session-pill">TZS only</span>
</section>

<section class="check-grid godigital-check-grid">
  <article class="check-card <?= $ready('base_url_configured') ? 'ready' : 'attention' ?>">
    <span class="check-icon">1</span>
    <div><strong>Gateway</strong><p><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Add the GoDigital UAT or production base URL.' ?></p></div>
    <span class="check-state"><?= $ready('base_url_configured') ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div><strong>OAuth client</strong><p><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'Client ID and secret are loaded on the server.' : 'Set the client ID and secret in .env.' ?></p></div>
    <span class="check-state"><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $ready('callback_is_https') ? 'ready' : 'attention' ?>">
    <span class="check-icon">3</span>
    <div><strong>HTTPS callback</strong><p><?= $ready('callback_is_https') ? 'A public callback URL is configured.' : 'Use a public HTTPS callback before submitting a payment.' ?></p></div>
    <span class="check-state"><?= $ready('callback_is_https') ? 'ready' : 'needed' ?></span>
  </article>
</section>

<section class="panel form-section godigital-workspace">
  <div class="section-title">
    <span>◉</span>
    <div>
      <h3>GoDigital test console</h3>
      <p>A request is only final after its callback or a follow-up status check reports a final provider status.</p>
    </div>
  </div>

  <div class="pegasus-tabs" data-godigital-tabs data-default-tab="<?= \App\View::e($active_tab) ?>">
    <button type="button" class="pegasus-tab" data-godigital-tab="connection">Connection</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="collection">C2B collection</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="disbursement">B2C payout</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="status">Status</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="balance">Wallet</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="callbacks">Callbacks</button>
    <button type="button" class="pegasus-tab" data-godigital-tab="activity">Activity</button>
  </div>

  <div data-godigital-panel="connection">
    <div class="godigital-summary-grid">
      <div><span>Base URL</span><strong><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Not configured' ?></strong></div>
      <div><span>Merchant ID</span><strong><?= $ready('merchant_id_configured') ? 'Configured' : 'Not configured' ?></strong></div>
      <div><span>OAuth credentials</span><strong><?= $ready('client_id_configured') && $ready('client_secret_configured') ? 'Configured' : 'Not configured' ?></strong></div>
    </div>
    <div class="warning">
      <strong>Server IP must be allow-listed</strong>
      <p>GoDigital protects the OAuth and payment APIs with an IP allow-list. Give them this server’s public outbound IP before testing. A <code>PGW-1009</code> response means the IP has not been approved.</p>
    </div>
    <form method="post" action="<?= $url('/godigital-tester/token') ?>" class="form-actions compact-actions">
      <button class="primary-action" type="submit">Check OAuth connection</button>
    </form>
  </div>

  <form method="post" action="<?= $url('/godigital-tester/collection') ?>" class="godigital-payment-form" data-godigital-panel="collection">
    <div class="warning">
      <strong>C2B is asynchronous</strong>
      <p>The customer still has to approve the payment. An accepted API response is not a completed collection.</p>
    </div>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($lastReference) ?>" maxlength="120" required></label>
      <label>Amount (TZS)<input name="amount" inputmode="decimal" value="10000.00" required></label>
      <label>Provider<select name="provider_code" required><option value="VODACOM">Vodacom</option><option value="YAS">Yas</option><option value="AIRTEL">Airtel</option><option value="HALOTEL">Halotel</option></select></label>
      <label>Customer MSISDN<input name="msisdn" inputmode="numeric" value="255754123456" pattern="255[0-9]{9}" required></label>
      <label class="wide">Narration<input name="narration" value="GoDigital C2B UAT collection" maxlength="200"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request collection</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/disbursement') ?>" class="godigital-payment-form" data-godigital-panel="disbursement">
    <div class="warning">
      <strong>Use a UAT-approved recipient</strong>
      <p>Confirm the provider and MSISDN with GoDigital before sending a payout. Check the final provider status after submission.</p>
    </div>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($newReference('GD-B2C')) ?>" maxlength="120" required></label>
      <label>Amount (TZS)<input name="amount" inputmode="decimal" value="10000.00" required></label>
      <label>Provider<select name="provider_code" required><option value="VODACOM">Vodacom</option><option value="YAS">Yas</option><option value="AIRTEL">Airtel</option><option value="HALOTEL">Halotel</option></select></label>
      <label>Recipient MSISDN<input name="msisdn" inputmode="numeric" value="255754123456" pattern="255[0-9]{9}" required></label>
      <label class="wide">Narration<input name="narration" value="GoDigital B2C UAT payout" maxlength="200"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request payout</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/status') ?>" class="godigital-payment-form" data-godigital-panel="status">
    <p class="helper-copy">Use the same reference supplied in the original collection or payout request.</p>
    <div class="compact-form-grid">
      <label class="wide">Payment reference<input name="reference" value="<?= \App\View::e($last_reference) ?>" placeholder="GD-C2B-..." maxlength="120" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check provider status</button></div>
  </form>

  <form method="post" action="<?= $url('/godigital-tester/balance') ?>" class="godigital-payment-form" data-godigital-panel="balance">
    <p class="helper-copy">Leave this blank to use the GoDigital client ID from the server configuration.</p>
    <div class="compact-form-grid">
      <label class="wide">Client ID (optional)<input name="client_id" maxlength="120" placeholder="Configured client ID"></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check wallet balance</button></div>
  </form>

  <div data-godigital-panel="callbacks">
    <div class="godigital-summary-grid one-column">
      <div><span>Callback endpoint</span><strong class="copyable-value"><?= \App\View::e($configuration['callback_url'] ?: 'Set APP_URL or GODIGITAL_CALLBACK_URL') ?></strong></div>
    </div>
    <div class="warning">
      <strong>Callback requirements</strong>
      <p>Configure this public HTTPS endpoint with GoDigital. It accepts JSON <code>POST</code> callbacks, acknowledges accepted deliveries with HTTP 200, and de-duplicates callback IDs and transaction IDs. <?= \App\View::e($configuration['signature_note']) ?></p>
    </div>
    <pre class="code-block"><code><?= \App\View::e(json_encode(['status' => 'RECEIVED', 'message' => 'Callback accepted'], JSON_PRETTY_PRINT)) ?></code></pre>
  </div>

  <div data-godigital-panel="activity">
    <?php if ($activity): ?>
      <div class="event-list godigital-events">
        <?php foreach ($activity as $entry): ?>
          <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e($entry['message'] ?? '') ?></strong><small><?= \App\View::e(($entry['at'] ?? '') . (!empty($entry['context']) ? ' · ' . json_encode($entry['context'], JSON_UNESCAPED_SLASHES) : '')) ?></small></div></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="event-empty"><span>◌</span><p>Requests and callbacks will appear here. Phone numbers are masked in this activity list.</p></div>
    <?php endif; ?>
  </div>

  <?php if ($result): ?>
    <section class="godigital-result" data-godigital-panel="<?= \App\View::e($active_tab) ?>">
      <div class="section-title"><span>✓</span><div><h3>Latest GoDigital response</h3><p>Provider data is shown for this test; it does not include OAuth credentials.</p></div></div>
      <pre class="code-block"><code><?= \App\View::e($resultJson) ?></code></pre>
    </section>
  <?php endif; ?>
</section>

<section class="api-test-band">
  <div><p class="eyebrow">Server API</p><h3>Integrate from your backend</h3><p>Use a dashboard-issued <code>X-API-Key</code> with the documented GoDigital endpoints. Never expose GoDigital client credentials to web or mobile clients.</p></div>
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
  show(tabs.dataset.defaultTab || 'connection');
})();
</script>
