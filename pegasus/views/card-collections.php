<?php /** @var bool $configured */ /** @var array $collections */ /** @var array $log */ /** @var string $last_collection_id */ /** @var array|null $status_result */ /** @var string $active_tab */ ?>
<section class="create-heading">
  <p class="eyebrow">PegPay Web</p>
  <h1>Card collections</h1>
  <p>Create a secure payment session. PegPay collects the customer's card details; CissyTech never receives or stores them.</p>
</section>

<nav class="pegasus-tabs" aria-label="PegPay collection type">
  <a class="pegasus-tab" href="<?= $url('/pegasus-tester') ?>">Mobile money and payouts</a>
  <a class="pegasus-tab is-active" href="<?= $url('/pegasus-card') ?>">Card collections</a>
</nav>

<nav class="pegasus-tabs" aria-label="Card collection functions" data-card-default-tab="<?= \App\View::e($active_tab) ?>">
  <button type="button" class="pegasus-tab" data-card-tab="collections">Collections</button>
  <button type="button" class="pegasus-tab" data-card-tab="status">Status</button>
  <button type="button" class="pegasus-tab" data-card-tab="log">Log</button>
</nav>

<div data-card-panel="collections">
<?php if (!$configured): ?>
  <section class="warning"><strong>Card gateway needs setup</strong><p>Add the PegPay Web URL, vendor code, password, secret code, and merchant code to <code>.env</code>.</p></section>
<?php endif; ?>

<section class="panel form-section">
  <div class="section-title">
    <span>1</span>
    <div><h3>Start a card payment</h3><p>The customer is redirected to PegPay's secure page to choose card or mobile money.</p></div>
  </div>
  <form class="form-grid" action="<?= $url('/pegasus-card/checkout') ?>" method="post">
    <label>Amount
      <input name="amount" type="number" min="1" step="0.01" value="500" required>
    </label>
    <label>Currency
      <select name="currency"><option value="UGX">UGX</option><option value="USD">USD</option></select>
    </label>
    <label class="wide">What is the payment for?
      <input name="description" value="CissyTech test payment" maxlength="200" required>
    </label>
    <label>Customer name <small>Optional</small>
      <input name="customer_name" maxlength="120" placeholder="Customer name">
    </label>
    <label>Customer email <small>Optional</small>
      <input name="customer_email" type="email" maxlength="160" placeholder="customer@example.com">
    </label>
    <div class="form-actions compact-actions wide"><button class="primary-action">Continue to PegPay</button></div>
  </form>
</section>

<section class="panel form-section">
  <div class="section-title">
    <span>2</span>
    <div><h3>Recent card collections</h3><p>The return status is accepted only after its PegPay digital signature is verified.</p></div>
  </div>
  <?php if (!$collections): ?>
    <p>No card payment sessions have been created yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Reference</th><th>Payment</th><th>Customer</th><th>Status</th><th>PegPay ID</th></tr></thead>
      <tbody><?php foreach ($collections as $collection): ?><tr>
        <td><strong><?= \App\View::e($collection['id']) ?></strong><small><?= \App\View::e($collection['description']) ?></small></td>
        <td><?= \App\View::e($collection['currency']) ?> <?= \App\View::e($collection['amount']) ?></td>
        <td><?= \App\View::e($collection['customer_name'] ?: 'Not supplied') ?></td>
        <td><span class="status <?= strtolower((string) ($collection['status'] ?? 'pending')) ?>"><?= \App\View::e($collection['status'] ?? 'PENDING') ?></span></td>
        <td><?= \App\View::e($collection['pegpay_transaction_id'] ?? 'Waiting') ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>
</div>

<section class="panel form-section" data-card-panel="status" hidden>
  <div class="section-title">
    <span>↻</span>
    <div>
      <h3>Check card collection status</h3>
      <p>Query PegPay Web's QueryStatus endpoint using a card reference created here. A pending payment can be checked again after five seconds.</p>
    </div>
  </div>
  <form class="form-grid" action="<?= $url('/pegasus-card/status') ?>" method="post">
    <label>
      Card collection reference
      <input name="vendor_transaction_id" value="<?= \App\View::e($last_collection_id) ?>" maxlength="60" required>
    </label>
    <div class="form-actions compact-actions">
      <button class="secondary-action">Check status</button>
    </div>
  </form>
  <?php if ($status_result): ?>
    <p><strong>Latest status: <?= \App\View::e($status_result['record']['status'] ?? 'PENDING') ?></strong></p>
    <pre class="code-block"><?= \App\View::e(json_encode($status_result['provider_response'] ?? $status_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</section>

<section class="panel form-section" data-card-panel="log" hidden>
  <div class="section-title">
    <span>≡</span>
    <div>
      <h3>PegPay card activity log</h3>
      <p><?= $log['writable'] ? 'Showing the latest redacted checkout and return events from the card log.' : 'The card log file is not writable. Database events are shown when available.' ?></p>
    </div>
  </div>
  <?php if ($log['entries']): ?>
    <pre class="code-block"><?= \App\View::e(json_encode($log['entries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php else: ?>
    <p>No card checkout events have been logged yet. Start a card payment to create UAT evidence.</p>
  <?php endif; ?>
</section>

<script>
(() => {
  const tabs = document.querySelector('[data-card-default-tab]');
  if (!tabs) return;
  const showTab = (name) => {
    document.querySelectorAll('[data-card-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.cardTab === name));
    document.querySelectorAll('[data-card-panel]').forEach((panel) => { panel.hidden = panel.dataset.cardPanel !== name; });
  };
  tabs.querySelectorAll('[data-card-tab]').forEach((button) => button.addEventListener('click', () => showTab(button.dataset.cardTab)));
  showTab(tabs.dataset.cardDefaultTab || 'collections');
})();
</script>
