<?php
/** @var array $health */
/** @var array $api_keys */
/** @var array $setup */
/** @var array $invoice */
/** @var string $last_reference */
/** @var array|null $result */
/** @var array|null $status_result */
/** @var string $active_tab */
$stamp = gmdate('YmdHis');
$selectedKey = (string) ($setup['api_key_id'] ?? '');
$branch = (string) ($setup['branch_code'] ?? 'KAMPALA-01');
$invoice = $invoice ?? [];
$invoiceKey = (string) ($invoice['api_key_id'] ?? $selectedKey);
$reference = (string) ($invoice['external_reference'] ?? "POS-INV-{$stamp}");
$idempotency = (string) ($invoice['idempotency_key'] ?? "EFRIS-{$stamp}");
?>
<section class="create-heading">
  <p class="eyebrow">EFRIS test workspace</p>
  <h1>Test your EFRIS API flow</h1>
  <p>Set up a local test tenant, submit one invoice, then check its stored status.</p>
</section>

<section class="efris-status">
  <div>
    <strong><?= \App\View::e(strtoupper((string) ($health['mode'] ?? 'mock'))) ?> mode</strong>
    <p><?= \App\View::e((string) ($health['message'] ?? '')) ?></p>
  </div>
  <span class="status created">No URA submission</span>
</section>

<section class="warning">
  <strong>Use this for CissyTech API testing now</strong>
  <p>It exercises the same validation, tenant selection and idempotency as your EFRIS API. It cannot issue a URA receipt until URA provides and approves the test connection, device and signed/encrypted interface setup.</p>
</section>

<nav class="efris-tabs" aria-label="EFRIS tester functions" data-default-tab="<?= \App\View::e($active_tab) ?>">
  <button type="button" class="efris-tab" data-efris-tab="setup">1. Test setup</button>
  <button type="button" class="efris-tab" data-efris-tab="invoice">2. Submit invoice</button>
  <button type="button" class="efris-tab" data-efris-tab="status">3. Check status</button>
  <button type="button" class="efris-tab" data-efris-tab="logs">Logs</button>
  <button type="button" class="efris-tab" data-efris-tab="readiness">URA readiness</button>
</nav>

<section class="panel compact-form-section" data-efris-panel="setup">
  <div class="section-title">
    <span>1</span>
    <div>
      <h3>Local test tenant</h3>
      <p>Use a CissyTech Integration ID from Overview. These are test identifiers only; never enter URA private keys here.</p>
    </div>
  </div>
  <?php if (!$api_keys): ?>
    <div class="warning"><strong>Create an integration key first</strong><p>Go to Overview, create an API key, then return here and choose its Integration ID.</p></div>
  <?php else: ?>
    <form class="compact-form-grid" action="<?= $url('/efris-tester/setup') ?>" method="post">
      <label>Integration ID
        <select name="api_key_id" required>
          <option value="">Choose integration</option>
          <?php foreach ($api_keys as $key): ?>
            <option value="<?= \App\View::e($key['id']) ?>" <?= $selectedKey === $key['id'] ? 'selected' : '' ?>><?= \App\View::e($key['name']) ?> — <?= \App\View::e($key['id']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Tenant name<input name="name" value="<?= \App\View::e($setup['name'] ?? 'CissyTech UAT Pilot') ?>" required></label>
      <label>Tenant ID<input name="tenant_id" value="<?= \App\View::e($setup['tenant_id'] ?? 'cissytech-pilot') ?>" maxlength="64" required></label>
      <label>Test TIN<input name="tin" value="<?= \App\View::e($setup['tin'] ?? 'TEST-TIN-001') ?>" required></label>
      <label>Branch code<input name="branch_code" value="<?= \App\View::e($branch) ?>" required></label>
      <label>URA branch ID<input name="ura_branch_id" value="<?= \App\View::e($setup['ura_branch_id'] ?? 'TEST-BRANCH-01') ?>" required></label>
      <label>URA device number<input name="device_number" value="<?= \App\View::e($setup['device_number'] ?? 'TEST-DEVICE-01') ?>" required></label>
      <div class="form-actions compact-actions wide"><button class="primary-action">Save test setup</button></div>
    </form>
  <?php endif; ?>
</section>

<section class="panel compact-form-section" data-efris-panel="invoice" hidden>
  <div class="section-title">
    <span>2</span>
    <div>
      <h3>Submit a test invoice</h3>
      <p>To test idempotency, submit the same form again with the same reference and idempotency key.</p>
    </div>
  </div>
  <form class="compact-form-grid" action="<?= $url('/efris-tester/invoices') ?>" method="post">
    <label>Integration ID
      <select name="api_key_id" required>
        <option value="">Choose integration</option>
        <?php foreach ($api_keys as $key): ?>
          <option value="<?= \App\View::e($key['id']) ?>" <?= $invoiceKey === $key['id'] ? 'selected' : '' ?>><?= \App\View::e($key['name']) ?> — <?= \App\View::e($key['id']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Invoice reference<input name="external_reference" value="<?= \App\View::e($reference) ?>" maxlength="100" required></label>
    <label>Idempotency key<input name="idempotency_key" value="<?= \App\View::e($idempotency) ?>" maxlength="100" required></label>
    <label>Branch code<input name="branch_code" value="<?= \App\View::e($invoice['branch_code'] ?? $branch) ?>" required></label>
    <label>Amount (UGX)<input name="total_amount" type="number" min="1" step="0.01" value="<?= \App\View::e($invoice['total_amount'] ?? '50000.00') ?>" required></label>
    <label>Payment method
      <?php $paymentMethod = (string) ($invoice['payment_method'] ?? 'CASH'); ?>
      <select name="payment_method"><option <?= $paymentMethod === 'CASH' ? 'selected' : '' ?>>CASH</option><option <?= $paymentMethod === 'MOBILE_MONEY' ? 'selected' : '' ?>>MOBILE_MONEY</option><option <?= $paymentMethod === 'CARD' ? 'selected' : '' ?>>CARD</option><option <?= $paymentMethod === 'BANK_TRANSFER' ? 'selected' : '' ?>>BANK_TRANSFER</option></select>
    </label>
    <label>Product code<input name="product_code" value="<?= \App\View::e($invoice['product_code'] ?? 'ITEM-001') ?>" required></label>
    <label>Quantity<input name="quantity" type="number" min="0.0001" step="0.0001" value="<?= \App\View::e($invoice['quantity'] ?? '2') ?>" required></label>
    <label>Unit price<input name="unit_price" type="number" min="0.01" step="0.01" value="<?= \App\View::e($invoice['unit_price'] ?? '25000.00') ?>" required></label>
    <label>Currency<input name="currency" value="<?= \App\View::e($invoice['currency'] ?? 'UGX') ?>" maxlength="3" required></label>
    <?php $buyerType = (string) ($invoice['buyer_type'] ?? 'B2C'); ?>
    <label>Buyer type<select name="buyer_type"><option <?= $buyerType === 'B2C' ? 'selected' : '' ?>>B2C</option><option <?= $buyerType === 'B2B' ? 'selected' : '' ?>>B2B</option></select></label>
    <label>Buyer name<input name="buyer_name" value="<?= \App\View::e($invoice['buyer_name'] ?? 'Cash Customer') ?>" required></label>
    <label>Buyer TIN <small>Optional for B2C</small><input name="buyer_tin" value="<?= \App\View::e($invoice['buyer_tin'] ?? '') ?>" placeholder="Buyer TIN"></label>
    <label>Discount<input name="discount" type="number" min="0" step="0.01" value="<?= \App\View::e($invoice['discount'] ?? '0.00') ?>" required></label>
    <div class="form-actions compact-actions wide"><button class="primary-action">Submit test invoice</button></div>
  </form>
  <?php if ($result): ?>
    <div class="request-grid">
      <div><p><strong>Request sent to the CissyTech test gateway</strong></p><pre class="code-block"><?= \App\View::e(json_encode($result['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>
      <div><p><strong>Response</strong></p><pre class="code-block"><?= \App\View::e(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>
    </div>
  <?php endif; ?>
</section>

<section class="panel compact-form-section" data-efris-panel="status" hidden>
  <div class="section-title">
    <span>3</span>
    <div><h3>Check invoice status</h3><p>Retrieve a mock invoice created by this integration.</p></div>
  </div>
  <form class="compact-form-grid" action="<?= $url('/efris-tester/status') ?>" method="post">
    <label>Integration ID
      <select name="api_key_id" required>
        <option value="">Choose integration</option>
        <?php foreach ($api_keys as $key): ?>
          <option value="<?= \App\View::e($key['id']) ?>" <?= $invoiceKey === $key['id'] ? 'selected' : '' ?>><?= \App\View::e($key['name']) ?> — <?= \App\View::e($key['id']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Invoice reference<input name="external_reference" value="<?= \App\View::e($last_reference) ?>" placeholder="POS-INV-000172" required></label>
    <div class="form-actions compact-actions"><button class="secondary-action">Check status</button></div>
  </form>
  <?php if ($status_result): ?><p><strong>Stored test response</strong></p><pre class="code-block"><?= \App\View::e(json_encode($status_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
</section>

<section class="panel compact-form-section" data-efris-panel="logs" hidden>
  <div class="section-title">
    <span>≡</span>
    <div><h3>EFRIS activity log</h3><p>Shows local tenant setup, mock invoice acceptance, and status checks. No URA credentials or private keys are recorded.</p></div>
  </div>
  <?php if ($activity): ?>
    <div class="event-list">
      <?php foreach ($activity as $event): ?>
        <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e(str_replace('.', ' · ', (string) ($event['action'] ?? '')) ) ?></strong><small><?= \App\View::e((string) ($event['at'] ?? '')) ?><?= !empty($event['reference']) ? ' · ' . \App\View::e((string) $event['reference']) : '' ?></small></div></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="event-empty"><span>◌</span><p>No EFRIS events yet. Save a test tenant or submit a mock invoice to begin the audit trail.</p></div>
  <?php endif; ?>
</section>

<section class="panel compact-form-section" data-efris-panel="readiness" hidden>
  <div class="section-title"><span>!</span><div><h3>Before real URA UAT</h3><p>The tester will become a real UAT tool after URA enables the following for CissyTech and the pilot taxpayer.</p></div></div>
  <ul class="efris-list">
    <li>Test-environment taxpayer TIN, branch and registered device.</li>
    <li>URA test connection details and the approved current Interface Design.</li>
    <li>Approved certificate/thumbprint, protected private key and required key exchange.</li>
    <li>Test dictionaries and product setup, then joint UAT for T101, T104, T115, T119, T130 and T109.</li>
  </ul>
  <p><a class="docs-link" href="<?= $url('/api/v1/efris/openapi.json') ?>">Open CissyTech EFRIS API specification</a></p>
</section>

<script>
(() => {
  const tabs = document.querySelector('.efris-tabs');
  const show = (name) => {
    document.querySelectorAll('[data-efris-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.efrisTab === name));
    document.querySelectorAll('[data-efris-panel]').forEach((panel) => { panel.hidden = panel.dataset.efrisPanel !== name; });
  };
  tabs.querySelectorAll('[data-efris-tab]').forEach((button) => button.addEventListener('click', () => show(button.dataset.efrisTab)));
  show(tabs.dataset.defaultTab || 'setup');
})();
</script>
