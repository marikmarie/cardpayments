<section class="vendor-hero">
  <div>
    <h1>Vendor test</h1>
    <p>Create an invoice and receive its payment URL in this browser. </p>
  </div>
</section>

<section class="vendor-layout">
  <form class="panel vendor-form" action="<?= $url('/vendor-simulator/payment-links') ?>" method="post">
    <div class="panel-header">
      <div>
        <h3>Vendor order</h3>
      </div>
    </div>
    <div class="vendor-form-body">
      <label>
        Vendor order reference
        <input name="invoice_number" placeholder="ORDER1001" maxlength="20" required>
      </label>
      <div class="vendor-fields">
        <label>
          Amount
          <input name="amount" type="number" min="1" step="0.01" placeholder="100000" required>
        </label>
        <label>
          Currency
          <input name="currency" value="UGX" maxlength="3" required>
        </label>
      </div>
      <label>
        Description
        <input name="description" placeholder="Order payment" required>
      </label>
      <label>
        Due date
        <input name="due_date" type="date" value="<?= gmdate('Y-m-d', strtotime('+7 days')) ?>" required>
      </label>
      <label>
        Customer name <small>Optional</small>
        <input name="customer_name" placeholder="Customer name">
      </label>
      <label>
        Customer email 
        <input name="customer_email" type="email" placeholder="mariam@gmail.com">
      </label>
      <button class="primary-action">Create invoice</button>
    </div>
  </form>

  <aside class="panel vendor-note">
    <h3>How it works</h3>
    <p>The vendor server receives the active <code>payment_url</code>. Change the active link on Overview. Keep <code>X-API-Key</code> on the server.</p>
    <pre>POST /api/v1/payment-links
send: false

201 → payment_url</pre>
    <a class="docs-link" href="<?= $url('/developers/api#links') ?>">API reference</a>
  </aside>
</section>

<?php if (!empty($session_link)): ?>
  <section class="session-link-card">
    <div>
      <p class="eyebrow">Current session</p>
      <strong><?= \App\View::e($session_link['invoice_number']) ?></strong>
      <small><?= \App\View::e($session_link['currency']) ?> <?= \App\View::e($session_link['amount']) ?> · <?= \App\View::e($session_link['status']) ?></small>
    </div>
    <a class="outline-action" href="<?= \App\View::e(\App\Services\CheckoutLink::selectedUrl($session_link, $checkout_type)) ?>">Open payment page</a>
  </section>
<?php endif; ?>
