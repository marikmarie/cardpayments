<?php /** @var bool $configured */ /** @var array $collections */ ?>
<section class="create-heading">
  <p class="eyebrow">PegPay Web</p>
  <h1>Card collections</h1>
  <p>Create a secure payment session. PegPay collects the customer's card details; CissyTech never receives or stores them.</p>
</section>

<nav class="pegasus-tabs" aria-label="PegPay collection type">
  <a class="pegasus-tab" href="<?= $url('/pegasus-tester') ?>">Mobile money and payouts</a>
  <a class="pegasus-tab is-active" href="<?= $url('/pegasus-card') ?>">Card collections</a>
</nav>

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
