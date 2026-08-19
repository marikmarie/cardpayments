<?php
$total = count($links);
$created = count(array_filter($links, fn($link) => in_array($link['status'], ['CREATED', 'SENT'], true)));
$paid = count(array_filter($links, fn($link) => in_array($link['status'], ['PAID', 'COMPLETED'], true)))
    + count(array_filter($transactions, fn($transaction) => $transaction['status'] === 'AUTHORIZED'));
$volume = array_sum(array_map(
    fn($link) => in_array($link['status'], ['PAID', 'COMPLETED'], true) ? (float) $link['amount'] : 0,
    $links
)) + array_sum(array_map(
    fn($transaction) => $transaction['status'] === 'AUTHORIZED' ? (float) $transaction['amount'] : 0,
    $transactions
));
?>

<section class="welcome-row">
  <div>
    <p class="eyebrow">Cybersource integration</p>
    <h1>Welcome to CissyTech Payments.</h1>
    <p>Process card payments through the API, or create secure Cybersource-hosted checkout links.</p>
  </div>
  <a class="outline-action" href="<?= $url('/links/create') ?>">Create a payment link <span>→</span></a>
</section>

<section class="metric-grid">
  <article class="metric-card cyan">
    <span class="metric-icon">↗</span>
    <p>Payment links</p>
    <strong><?= $total ?></strong>
    <small><?= $created ?> awaiting payment</small>
  </article>
  <article class="metric-card blue">
    <span class="metric-icon">✓</span>
    <p>Processed payments</p>
    <strong><?= $paid ?></strong>
    <small>Authorized, paid, or completed</small>
  </article>
  <article class="metric-card dark">
    <span class="metric-icon">⌁</span>
    <p>Payment volume</p>
    <strong>UGX <?= number_format($volume, 0) ?></strong>
    <small>Successful sandbox payments</small>
  </article>
</section>

<section class="panel recent-panel">
  <div class="panel-header">
    <div>
      <h3>Recent payment links</h3>
      <p>Manage the hosted checkout links created for your customers.</p>
    </div>
    <a href="<?= $url('/links/create') ?>">View all <span>→</span></a>
  </div>

  <?php if (!$links): ?>
    <div class="empty-state">
      <span>✦</span>
      <h3>Create your first payment link</h3>
      <p>Send customers to a secure CyberSource hosted checkout page.</p>
      <a class="primary-action" href="<?= $url('/links/create') ?>">Create payment link</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Customer</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($links as $link): ?>
            <tr>
              <td>
                <strong><?= \App\View::e($link['invoice_number']) ?></strong>
                <a href="<?= \App\View::e($link['payment_url']) ?>" target="_blank" rel="noreferrer">Open checkout ↗</a>
              </td>
              <td>
                <strong class="customer-name"><?= \App\View::e($link['customer_name']) ?></strong>
                <small><?= \App\View::e($link['customer_email']) ?></small>
              </td>
              <td><strong><?= \App\View::e($link['currency']) ?> <?= \App\View::e($link['amount']) ?></strong></td>
              <td>
                <span class="status <?= strtolower(\App\View::e($link['status'])) ?>">
                  <?= \App\View::e($link['status']) ?>
                </span>
              </td>
              <td><small><?= \App\View::e(substr($link['created_at'], 0, 10)) ?></small></td>
              <td>
                <div class="row-actions">
                  <form action="<?= $url('/links/' . $link['id'] . '/sync') ?>" method="post">
                    <button class="ghost-button">Refresh</button>
                  </form>
                  <form action="<?= $url('/links/' . $link['id'] . '/send') ?>" method="post">
                    <button class="ghost-button">Email customer</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section id="api" class="api-band">
  <div>
    <p class="eyebrow">Developer tools</p>
    <h3>Connect your own systems</h3>
    <p>Use <code>POST /api/v1/payments</code> for direct sales, or <code>POST /api/v1/payment-links</code> for hosted checkout.</p>
    <a class="docs-link" href="<?= $url('/developers/api') ?>">View API reference →</a>
  </div>
  <form action="<?= $url('/api-keys') ?>" method="post" class="key-form">
    <input name="name" placeholder="e.g. Storefront API" required>
    <button>Create API key</button>
  </form>
</section>

<?php if (!empty($api_key)): ?>
  <section class="key-card">
    <div>
      <p class="eyebrow">Copy this now</p>
      <strong>Your new API key</strong>
    </div>
    <code><?= \App\View::e($api_key['token']) ?></code>
    <small>This full value is never shown again.</small>
  </section>
<?php endif; ?>

<section class="panel api-keys-panel">
  <div class="panel-header">
    <div>
      <h3>API keys</h3>
      <p>Give each connected system its own key. Revoke a key immediately when it is no longer needed.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Integration</th>
          <th>Created</th>
          <th>Last used</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($api_keys)): ?>
          <tr>
            <td colspan="4"><small>No API keys yet. Create one above for your first connected system.</small></td>
          </tr>
        <?php else: ?>
          <?php foreach ($api_keys as $key): ?>
            <tr>
              <td>
                <strong><?= \App\View::e($key['name']) ?></strong>
                <small>Key ending …<?= \App\View::e(substr($key['id'], -6)) ?></small>
              </td>
              <td><small><?= \App\View::e(substr($key['created_at'], 0, 10)) ?></small></td>
              <td>
                <small><?= !empty($key['last_used_at']) ? \App\View::e(substr($key['last_used_at'], 0, 16) . ' UTC') : 'Not used yet' ?></small>
              </td>
              <td>
                <form action="<?= $url('/api-keys/' . $key['id'] . '/revoke') ?>" method="post">
                  <button class="ghost-button revoke-button">Revoke</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
