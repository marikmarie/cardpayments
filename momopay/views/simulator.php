<?php
/** MTN MoMo Pay browser test workspace. */
$ready = static fn(string $key): bool => !empty($configuration[$key]);
$newReference = static fn(): string => \App\PaymentReference::generate();
$resultJson = $result ? (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') : '';
$lastReference = $last_reference ?: $newReference();
$panelVisibility = static fn(string $tab): string => $active_tab === $tab ? '' : ' hidden';
?>

<section class="test-hero godigital-hero">
  <div><h1>MoMo Pay</h1><p>MTN Collection and Disbursement with a simple UAT workspace.</p></div>
  <span class="session-pill"><?= \App\View::e(strtoupper($configuration['target_environment'])) ?></span>
</section>

<section class="check-grid godigital-check-grid">
  <article class="check-card <?= $ready('base_url_configured') ? 'ready' : 'attention' ?>"><span class="check-icon">1</span><div><strong>Gateway</strong><p><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Add the API URL.' ?></p></div><span class="check-state"><?= $ready('base_url_configured') ? 'ready' : 'needed' ?></span></article>
  <article class="check-card <?= $ready('collection_ready') ? 'ready' : 'attention' ?>"><span class="check-icon">2</span><div><strong>Collection</strong><p><?= $ready('collection_ready') ? 'Credentials are loaded.' : 'Add Collection credentials.' ?></p></div><span class="check-state"><?= $ready('collection_ready') ? 'ready' : 'needed' ?></span></article>
  <article class="check-card <?= $ready('disbursement_ready') ? 'ready' : 'attention' ?>"><span class="check-icon">3</span><div><strong>Disbursement</strong><p><?= $ready('disbursement_ready') ? 'Credentials are loaded.' : 'Add Disbursement credentials.' ?></p></div><span class="check-state"><?= $ready('disbursement_ready') ? 'ready' : 'needed' ?></span></article>
</section>

<section class="panel form-section momopay-workspace">

  <div class="pegasus-tabs" data-momopay-tabs data-default-tab="<?= \App\View::e($active_tab) ?>">
    <button type="button" class="pegasus-tab" data-momopay-tab="connection">Connection</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="collection">Collect</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="disbursement">Payout</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="withdrawal">Withdraw</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="status">Status</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="account">Account</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="sit">SIT cases</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="callbacks">Callback</button>
    <button type="button" class="pegasus-tab" data-momopay-tab="logs">Logs</button>
  </div>

  <div data-momopay-panel="connection"<?= $panelVisibility('connection') ?>>
    <div class="godigital-summary-grid">
      <div><span>Base URL</span><strong><?= $ready('base_url_configured') ? \App\View::e($configuration['base_url']) : 'Not configured' ?></strong></div>
      <div><span>Target environment</span><strong><?= \App\View::e($configuration['target_environment']) ?></strong></div>
      <div><span>Default currency</span><strong><?= \App\View::e($configuration['currency']) ?></strong></div>
    </div>
    <div class="momopay-token-actions">
      <form method="post" action="<?= $url('/momopay-tester/token') ?>"><input type="hidden" name="product" value="collection"><button class="primary-action" type="submit">Check Collection token</button></form>
      <form method="post" action="<?= $url('/momopay-tester/token') ?>"><input type="hidden" name="product" value="disbursement"><button class="outline-action" type="submit">Check Disbursement token</button></form>
    </div>
  </div>

  <form method="post" action="<?= $url('/momopay-tester/collection') ?>" class="momopay-operation-form" data-momopay-panel="collection"<?= $panelVisibility('collection') ?>>
    <p class="helper-copy">A collection asks the customer to approve payment on their MTN MoMo account.</p>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($lastReference) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount (<?= \App\View::e($configuration['currency']) ?>)<input name="amount" inputmode="decimal" value="1000.00" required></label>
      <label>Customer phone<input name="phone_number" inputmode="numeric" placeholder="2567…" required></label>
      <label>Currency<input name="currency" value="<?= \App\View::e($configuration['currency']) ?>" maxlength="3" required></label>
      <label class="wide">Message<input name="message" value="MoMo Pay collection" maxlength="160" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request payment</button></div>
  </form>

  <form method="post" action="<?= $url('/momopay-tester/disbursement') ?>" class="momopay-operation-form" data-momopay-panel="disbursement"<?= $panelVisibility('disbursement') ?>>
    <p class="helper-copy">A payout transfers funds from the configured Disbursement account to the recipient.</p>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount (<?= \App\View::e($configuration['currency']) ?>)<input name="amount" inputmode="decimal" value="1000.00" required></label>
      <label>Recipient phone<input name="phone_number" inputmode="numeric" placeholder="2567…" required></label>
      <label>Currency<input name="currency" value="<?= \App\View::e($configuration['currency']) ?>" maxlength="3" required></label>
      <label class="wide">Message<input name="message" value="MoMo Pay payout" maxlength="160" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Send payout</button></div>
  </form>

  <form method="post" action="<?= $url('/momopay-tester/withdrawal') ?>" class="momopay-operation-form" data-momopay-panel="withdrawal"<?= $panelVisibility('withdrawal') ?>>
    <p class="helper-copy">Uses the enabled Collection v2 withdrawal endpoint from the supplied MTN collection. Confirm the final result from the callback.</p>
    <div class="compact-form-grid">
      <label>Reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" required></label>
      <label>Amount (<?= \App\View::e($configuration['currency']) ?>)<input name="amount" inputmode="decimal" value="1000.00" required></label>
      <label>Customer phone<input name="phone_number" inputmode="numeric" placeholder="2567…" required></label>
      <label>Currency<input name="currency" value="<?= \App\View::e($configuration['currency']) ?>" maxlength="3" required></label>
      <label class="wide">Message<input name="message" value="MoMo Pay withdrawal" maxlength="160" required></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Request withdrawal</button></div>
  </form>

  <form method="post" action="<?= $url('/momopay-tester/status') ?>" class="momopay-operation-form" data-momopay-panel="status"<?= $panelVisibility('status') ?>>
    <p class="helper-copy">Use the PMT reference returned by this workspace. You can also use an MTN request UUID and choose its product.</p>
    <div class="compact-form-grid">
      <label class="wide">PMT or MTN reference<input name="reference" value="<?= \App\View::e($last_reference) ?>" placeholder="PMT1234567" required></label>
      <label>Product if using MTN UUID<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label>
    </div>
    <div class="form-actions compact-actions"><button class="primary-action" type="submit">Check status</button></div>
  </form>

  <div class="momopay-account-grid" data-momopay-panel="account"<?= $panelVisibility('account') ?>>
    <form method="post" action="<?= $url('/momopay-tester/balance') ?>" class="momopay-operation-form"><h4>Account balance</h4><div class="compact-form-grid"><label>Product<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label></div><div class="form-actions compact-actions"><button class="primary-action" type="submit">Check balance</button></div></form>
    <form method="post" action="<?= $url('/momopay-tester/account-holder') ?>" class="momopay-operation-form"><h4>Account holder</h4><div class="compact-form-grid"><label>Product<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label><label>Phone number<input name="phone_number" inputmode="numeric" placeholder="2567…" required></label><label>Check<select name="query"><option value="active">Active status</option><option value="basicuserinfo">Basic user info</option></select></label></div><div class="form-actions compact-actions"><button class="outline-action" type="submit">Check account</button></div></form>
  </div>

  <div data-momopay-panel="sit"<?= $panelVisibility('sit') ?>>
    <div class="warning"><strong>Sandbox SIT runner</strong><p>Run one case at a time with sandbox credentials. Logs retain the actual HTTP status and raw body returned by the MTN MoMo API for every outbound request. MTN controls approval, rejection, timeout, funds, and limit outcomes.</p></div>
    <form method="post" action="<?= $url('/momopay-tester/sit') ?>" class="momopay-operation-form">
      <div class="compact-form-grid">
        <label>Product<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label>
        <label>SIT case<select name="case" required>
          <optgroup label="OAuth 2.0 authentication"><option value="auth_invalid_subscription">Invalid subscription key</option><option value="auth_invalid_api_key">Invalid API key</option><option value="auth_valid">Valid token</option><option value="auth_second_token">Second valid token</option></optgroup>
          <optgroup label="RequestToPay or Transfer"><option value="payment_approved">Successful request</option><option value="payment_rejected">Subscriber rejects</option><option value="payment_timeout">Approval timeout</option><option value="payment_duplicate_reference">Duplicate MTN UUID</option><option value="payment_missing_amount">Missing amount</option><option value="payment_missing_currency">Missing currency</option><option value="payment_insufficient_funds">Insufficient funds</option><option value="payment_invalid_party">Invalid recipient</option><option value="payment_daily_limit">Exceeds daily limit</option><option value="payment_invalid_subscription">Invalid subscription key</option><option value="payment_invalid_target">Invalid target environment</option><option value="payment_invalid_token">Invalid bearer token</option><option value="payment_https_callback">HTTPS callback URL</option><option value="payment_http_callback">HTTP callback URL</option></optgroup>
          <optgroup label="Transaction status"><option value="status_valid">Valid MTN UUID</option><option value="status_invalid_reference">Unknown MTN UUID</option><option value="status_invalid_subscription">Invalid subscription key</option><option value="status_invalid_target">Invalid target environment</option><option value="status_invalid_token">Invalid bearer token</option></optgroup>
          <optgroup label="Account holder"><option value="account_valid">Valid account check</option><option value="account_invalid_type">Invalid account holder type</option><option value="account_invalid_id">Invalid account holder ID</option><option value="account_invalid_subscription">Invalid subscription key</option><option value="account_invalid_target">Invalid target environment</option><option value="account_invalid_token">Invalid bearer token</option></optgroup>
          <optgroup label="Balance"><option value="balance_valid">Valid balance</option><option value="balance_invalid_subscription">Invalid subscription key</option><option value="balance_invalid_target">Invalid target environment</option><option value="balance_invalid_token">Invalid bearer token</option></optgroup>
        </select></label>
        <label>PMT reference (payment)<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" placeholder="PMT1234567"></label>
        <label>MTN UUID (status or duplicate)<input name="provider_reference" placeholder="xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx"></label>
        <label>Amount<input name="amount" inputmode="decimal" placeholder="1000.00"></label>
        <label>Phone number<input name="phone_number" inputmode="numeric" placeholder="2567…"></label>
        <label>Currency<input name="currency" value="<?= \App\View::e($configuration['currency']) ?>" maxlength="3"></label>
        <label>Account query<select name="query"><option value="active">Active status</option><option value="basicuserinfo">Basic user info</option></select></label>
        <label class="wide">Callback URL (HTTP or HTTPS case)<input name="callback_url" placeholder="Use the configured callback for other cases"></label>
        <label class="wide">Message<input name="message" value="MoMo SIT test" maxlength="160"></label>
      </div>
      <div class="form-actions compact-actions"><button class="primary-action" type="submit">Run SIT case</button></div>
    </form>
  </div>

  <div data-momopay-panel="callbacks"<?= $panelVisibility('callbacks') ?>>
    <div class="godigital-summary-grid one-column"><div><span>Callback endpoint</span><strong class="copyable-value"><?= \App\View::e($configuration['callback_url'] ?: 'Set APP_URL or MOMOPAY_CALLBACK_URL') ?></strong></div></div>
    <div class="warning"><strong>Register this callback with MTN</strong><p>The receiver accepts both POST and PUT callbacks and records each payload received from MTN. Callback payloads appear in Logs; no sample provider response is shown here.</p></div>
  </div>

  <div data-momopay-panel="logs"<?= $panelVisibility('logs') ?>>
    <?php if ($activity): ?>
      <div class="event-list godigital-events"><?php foreach ($activity as $entry): ?><div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e($entry['message'] ?? '') ?></strong><small><?= \App\View::e((string) ($entry['at'] ?? '')) ?></small><?php if (!empty($entry['context'])): ?><details class="log-context"><summary>Details</summary><pre><?= \App\View::e(json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></div></div><?php endforeach; ?></div>
    <?php else: ?><div class="event-empty"><span>◍</span><p>Requests, responses, and callbacks appear here.</p></div><?php endif; ?>
  </div>

  <?php if ($result): ?><section class="godigital-result" data-momopay-panel="<?= \App\View::e($active_tab) ?>"><div class="section-title"><span>✓</span><div><h3>Request metadata and provider response</h3><p>Logs retain the raw MTN response; credentials and customer phone numbers are masked.</p></div></div><pre class="code-block"><code><?= \App\View::e($resultJson) ?></code></pre></section><?php endif; ?>
</section>

<section class="api-test-band"><div><p class="eyebrow">Server API</p><h3>MoMo Pay API</h3><p>Use a dashboard-issued <code>X-API-Key</code>.</p></div><a class="primary-action" href="<?= $url('/api/v1/momopay/openapi.json') ?>">Open API JSON</a></section>

<script>
(() => {
  const tabs = document.querySelector('[data-momopay-tabs]');
  if (!tabs) return;
  const buttons = tabs.querySelectorAll('[data-momopay-tab]');
  const panels = document.querySelectorAll('[data-momopay-panel]');
  const show = (tab) => {
    buttons.forEach((button) => button.classList.toggle('is-active', button.dataset.momopayTab === tab));
    panels.forEach((panel) => { panel.hidden = panel.dataset.momopayPanel !== tab; });
  };
  buttons.forEach((button) => button.addEventListener('click', () => show(button.dataset.momopayTab)));
  show(tabs.dataset.defaultTab || 'connection');
})();
</script>
