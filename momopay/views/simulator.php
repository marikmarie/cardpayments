<?php
/** MTN MoMo Pay browser test workspace. */
$ready = static fn(string $key): bool => !empty($configuration[$key]);
$newReference = static fn(): string => \App\PaymentReference::generate();
$apiKeyOnce = is_array($result) ? trim((string) ($result['api_key_once'] ?? '')) : '';
$resultForDisplay = is_array($result) ? $result : [];
$providerRequest = is_array($resultForDisplay['provider_request'] ?? null) ? $resultForDisplay['provider_request'] : [];
$providerResponse = is_array($resultForDisplay['provider_response'] ?? null) ? $resultForDisplay['provider_response'] : [];
$providerHttpStatus = (int) ($resultForDisplay['provider_http_status'] ?? 0);
$resultError = trim((string) ($resultForDisplay['error'] ?? ''));
unset($resultForDisplay['api_key_once'], $resultForDisplay['provider_request'], $resultForDisplay['provider_response'], $resultForDisplay['provider_http_status'], $resultForDisplay['error']);
$resultMetadataJson = $resultForDisplay ? (json_encode($resultForDisplay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') : '';
$providerRequestJson = $providerRequest ? (json_encode($providerRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') : '';
$providerResponseJson = $providerResponse ? (json_encode($providerResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') : '';
$sitCaseGroups = [];
foreach ($sit_cases as $caseId => $caseDefinition) {
  $sitCaseGroups[(string) ($caseDefinition['group'] ?? 'Other')][$caseId] = $caseDefinition;
}
$sitCaseJson = json_encode($sit_cases, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
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
    <button type="button" class="pegasus-tab" data-momopay-tab="setup">Setup</button>
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

  <div data-momopay-panel="setup"<?= $panelVisibility('setup') ?>>
    <div class="warning"><strong>Sandbox provisioning</strong><p>Complete MTN portal signup and product subscription first. This tab runs only the workbook's API-user and API-key provisioning calls in the sandbox. Production credentials are issued in the MTN Partner Portal.</p></div>
    <div class="godigital-summary-grid">
      <div><span>Provisioning key</span><strong><?= $ready('provisioning_ready') ? 'configured' : 'add MOMOPAY_PROVISIONING_SUBSCRIPTION_KEY' ?></strong></div>
      <div><span>Suggested callback host</span><strong><?= \App\View::e($configuration['provider_callback_host'] ?: 'enter a public host below') ?></strong></div>
      <div><span>Environment</span><strong><?= \App\View::e(strtoupper($configuration['target_environment'])) ?></strong></div>
    </div>
    <div class="momopay-account-grid">
      <form method="post" action="<?= $url('/momopay-tester/provision-api-user') ?>" class="momopay-operation-form">
        <h4>1. Create API user</h4>
        <p class="helper-copy">Leave API user blank to generate an MTN-compliant UUID v4. Use the public host only, without a path.</p>
        <div class="compact-form-grid">
          <label>Product<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label>
          <label>API user UUID (optional)<input name="api_user" placeholder="Leave blank to generate"></label>
          <label class="wide">Provider callback host<input name="provider_callback_host" value="<?= \App\View::e($configuration['provider_callback_host']) ?>" placeholder="payments.example.com" required></label>
        </div>
        <div class="form-actions compact-actions"><button class="primary-action" type="submit">Create API user</button></div>
      </form>
      <form method="post" action="<?= $url('/momopay-tester/provision-api-key') ?>" class="momopay-operation-form">
        <h4>2. Create API key</h4>
        <p class="helper-copy">Use the exact API-user UUID above. Copy the returned API key immediately into the product's `.env` API-key value.</p>
        <div class="compact-form-grid">
          <label>Product<select name="product"><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label>
          <label class="wide">API user UUID<input name="api_user" placeholder="xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx" required></label>
        </div>
        <div class="form-actions compact-actions"><button class="outline-action" type="submit">Create API key</button></div>
      </form>
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
   <form method="post" action="<?= $url('/momopay-tester/sit') ?>" class="momopay-operation-form" data-momopay-sit-form>
      <div class="compact-form-grid">
        <label>Product<select name="product" data-momopay-sit-product><option value="collection">Collection</option><option value="disbursement">Disbursement</option></select></label>
        <label>SIT case<select name="case" data-momopay-sit-case required><?php foreach ($sitCaseGroups as $group => $cases): ?><optgroup label="<?= \App\View::e($group) ?>"><?php foreach ($cases as $caseId => $caseDefinition): ?><option value="<?= \App\View::e($caseId) ?>"><?= \App\View::e((string) $caseDefinition['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
        <label data-sit-field="reference">PMT reference<input name="reference" value="<?= \App\View::e($newReference()) ?>" maxlength="10" pattern="PMT[A-Z0-9]{7}" placeholder="PMT1234567"></label>
        <label data-sit-field="workbook_reference">Workbook Reference ID<input name="workbook_reference" placeholder="xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx"></label>
        <label data-sit-field="provider_reference">MTN X-Reference-Id<input name="provider_reference" placeholder="xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx"></label>
        <label data-sit-field="amount">Amount (EUR)<input name="amount" inputmode="decimal" placeholder="1000.00"></label>
        <label data-sit-field="phone_number">Sandbox phone number<input name="phone_number" inputmode="numeric" placeholder="46733123450"></label>
        <label data-sit-field="currency">Currency<input name="currency" value="EUR" maxlength="3"></label>
        <label data-sit-field="query">Account query<select name="query"><option value="active">Active status</option><option value="basicuserinfo">Basic user info</option></select></label>
        <label class="wide" data-sit-field="callback_url">Callback URL<input name="callback_url" placeholder="Use the configured callback for other cases"></label>
        <label class="wide" data-sit-field="message">Message<input name="message" value="MoMo SIT test" maxlength="160"></label>
      </div>
      <section class="momopay-sit-guide" data-momopay-sit-guide aria-live="polite">
        <h4 data-sit-title></h4>
        <p data-sit-description></p>
        <div class="godigital-summary-grid">
          <div><span>Sandbox test number</span><strong data-sit-test-number></strong></div>
          <div><span>Reference ID</span><strong data-sit-reference-note></strong></div>
          <div><span>Test currency</span><strong data-sit-currency></strong></div>
        </div>
      </section>
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

  <?php if ($result): ?><section class="godigital-result" data-momopay-panel="<?= \App\View::e($active_tab) ?>"><div class="section-title"><span>✓</span><div><h3>Request and MTN response</h3><p>Both sides of this test are shown here. Secrets and customer numbers are masked.</p></div></div><?php if ($resultError !== ''): ?><div class="warning"><strong>MTN rejected this request</strong><p><?= \App\View::e($resultError) ?></p></div><?php endif; ?><?php if ($apiKeyOnce !== ''): ?><div class="warning"><strong>Copy the API key now</strong><p>This one-time value is returned by MTN and is never written to Logs. Put it in the selected product's `.env` API-key value.</p><pre class="code-block"><code><?= \App\View::e($apiKeyOnce) ?></code></pre></div><?php endif; ?><div class="momopay-exchange-grid"><article class="code-block"><span>REQUEST SENT</span><pre><code><?= \App\View::e($providerRequestJson ?: 'No provider request was created.') ?></code></pre></article><article class="code-block"><span>MTN RESPONSE<?= $providerHttpStatus > 0 ? ' · HTTP ' . \App\View::e((string) $providerHttpStatus) : '' ?></span><pre><code><?= \App\View::e($providerResponseJson ?: 'No provider response was returned.') ?></code></pre></article></div><?php if ($resultMetadataJson !== ''): ?><details class="momopay-result-metadata"><summary>Test metadata</summary><pre class="code-block"><code><?= \App\View::e($resultMetadataJson) ?></code></pre></details><?php endif; ?></section><?php endif; ?>
</section>

<section class="api-test-band"><div><p class="eyebrow">Server API</p><h3>MoMo Pay API</h3><p>Use a dashboard-issued <code>X-API-Key</code>.</p></div><a class="primary-action" href="<?= $url('/api/v1/momopay/openapi.json') ?>">Open API JSON</a></section>

<script>
(() => {
  const tabs = document.querySelector('[data-momopay-tabs]');
  if (!tabs) return;
  const buttons = tabs.querySelectorAll('[data-momopay-tab]');
  const panels = document.querySelectorAll('[data-momopay-panel]');
  const sitForm = document.querySelector('[data-momopay-sit-form]');
  if (sitForm) {
    const sitCases = <?= $sitCaseJson ?>;
    const product = sitForm.querySelector('[data-momopay-sit-product]');
    const caseSelect = sitForm.querySelector('[data-momopay-sit-case]');
    const guide = sitForm.querySelector('[data-momopay-sit-guide]');
    const inputFor = (name) => sitForm.querySelector(`[name="${name}"]`);
    const labelFor = (name) => sitForm.querySelector(`[data-sit-field="${name}"]`);
    const valueForProduct = (value, productName) => {
      if (Array.isArray(value)) return '';
      if (!value || typeof value !== 'object') return value || '';
      return value[productName] || value.default || '';
    };
    const newUuid = () => {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.random() * 16 | 0;
        return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
      });
    };
    const requiredFor = (field, caseId) => {
      if (field === 'provider_reference') return ['payment_duplicate_reference', 'status_valid'].includes(caseId);
      return ['reference', 'workbook_reference', 'amount', 'phone_number', 'currency', 'callback_url', 'message'].includes(field);
    };
    const updateSitCase = () => {
      const caseId = caseSelect.value;
      const definition = sitCases[caseId];
      if (!definition) return;
      const fields = definition.fields || [];
      ['reference', 'workbook_reference', 'provider_reference', 'amount', 'phone_number', 'currency', 'query', 'callback_url', 'message'].forEach((field) => {
        const label = labelFor(field);
        const input = inputFor(field);
        const visible = fields.includes(field);
        if (label) label.hidden = !visible;
        if (!input) return;
        input.required = visible && requiredFor(field, caseId);
        if (!visible) return;
        const defaultValue = valueForProduct((definition.defaults || {})[field], product.value);
        if (defaultValue !== '') input.value = defaultValue;
        if (field === 'workbook_reference' && !input.value) input.value = newUuid();
      });

      const testNumber = valueForProduct(definition.test_numbers, product.value);
      guide.querySelector('[data-sit-title]').textContent = definition.label || caseId;
      guide.querySelector('[data-sit-description]').textContent = definition.description || '';
      guide.querySelector('[data-sit-test-number]').textContent = testNumber
        ? `${testNumber}${testNumber === '46733123449' ? ' (an unlisted number; MTN treats other numbers as Success)' : ''}`
        : 'No MSISDN test number is sent for this endpoint.';
      guide.querySelector('[data-sit-reference-note]').textContent = definition.reference_note || '';
      guide.querySelector('[data-sit-currency]').textContent = fields.includes('currency') ? 'EUR' : 'Not sent for this case.';
    };
    product.addEventListener('change', updateSitCase);
    caseSelect.addEventListener('change', updateSitCase);
    updateSitCase();
  }
  const show = (tab) => {
    buttons.forEach((button) => button.classList.toggle('is-active', button.dataset.momopayTab === tab));
    panels.forEach((panel) => { panel.hidden = panel.dataset.momopayPanel !== tab; });
  };
  buttons.forEach((button) => button.addEventListener('click', () => show(button.dataset.momopayTab)));
  show(tabs.dataset.defaultTab || 'connection');
})();
</script>
