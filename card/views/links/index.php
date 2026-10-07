<?php
// Card module invoice overview view.
$total = count($links);
$created = count(array_filter($links, fn($link) => in_array($link['status'], ['CREATED', 'SENT'], true)));
$paid = count(array_filter($links, fn($link) => in_array($link['status'], ['PAID', 'COMPLETED'], true)));
$volume = array_sum(array_map(
    fn($link) => in_array($link['status'], ['PAID', 'COMPLETED'], true) ? (float) $link['amount'] : 0,
    $links
));
?>

<section class="welcome-row">
  <div>
    <p class="eyebrow">Payment operations</p>
    <h1>Payments overview</h1>
    <p>Card links, settlement progress, and activity.</p>
  </div>
  <a class="outline-action" href="<?= $url('/links/create') ?>">Create payment</a>
</section>

<section class="metric-grid">
  <article class="metric-card">
    <p>Open links</p>
    <strong><?= $created ?></strong>
  </article>
  <article class="metric-card">
    <p>Processed payments</p>
    <strong><?= $paid ?></strong>
  </article>
  <article class="metric-card">
    <p>Received</p>
    <strong>UGX <?= number_format($volume, 0) ?></strong>
  </article>
</section>

<section class="panel checkout-setting">
  <form action="<?= $url('/settings/checkout-type') ?>" method="post">
    <label>
      Checkout
      <select name="checkout_type">
        <option value="cissytech" <?= $checkout_type === 'cissytech' ? 'selected' : '' ?>>CissyTech checkout</option>
        <option value="cybersource" <?= $checkout_type === 'cybersource' ? 'selected' : '' ?>>Direct card checkout</option>
      </select>
    </label>
    <button class="primary-action">Save</button>
  </form>
  <small><?= $checkout_type === 'cissytech' ? 'CissyTech page first.' : 'Provider checkout first.' ?></small>
</section>

<section class="panel provider-log-panel">
  <div class="panel-header">
    <div><p class="eyebrow">Card payments</p><h3>Absa / CyberSource activity</h3><p>Recent safe request and response events.</p></div>
    <a class="outline-action" href="<?= $url('/absa-cards') ?>">Open card workspace</a>
  </div>
  <?php if ($card_log['entries']): ?>
    <div class="event-list provider-log-list">
      <?php foreach ($card_log['entries'] as $entry): ?>
        <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e(trim(($entry['type'] ?? 'Event') . ' · ' . ($entry['operation'] ?? 'card gateway'))) ?></strong><small><?= \App\View::e((string) ($entry['time'] ?? '')) ?><?= !empty($entry['status']) ? ' · ' . \App\View::e((string) $entry['status']) : '' ?><?= !empty($entry['message']) ? ' · ' . \App\View::e((string) $entry['message']) : '' ?><?= !empty($entry['correlation_id']) ? ' · Support ID ' . \App\View::e((string) $entry['correlation_id']) : '' ?></small></div></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="event-empty"><span>◌</span><p>No card activity yet.</p></div>
  <?php endif; ?>
</section>

<section class="panel recent-panel">
  <div class="panel-header">
    <div>
      <h3>Recent invoices</h3>
    </div>
    <a href="<?= $url('/links/create') ?>">New invoice</a>
  </div>

  <?php if (!$links): ?>
    <div class="empty-state">
      <span>✦</span>
      <h3>No invoices yet</h3>
      <a class="primary-action" href="<?= $url('/links/create') ?>">Create invoice</a>
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
                <a href="<?= \App\View::e(\App\Services\CheckoutLink::selectedUrl($link, $checkout_type)) ?>" target="_blank" rel="noreferrer">Open checkout ↗</a>
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
    <h3>API keys</h3>
    <p>Create a key for a connected system.</p>
    <a class="docs-link" href="<?= $url('/developers/api') ?>">API reference</a>
  </div>
  <form action="<?= $url('/api-keys') ?>" method="post" class="key-form">
    <input name="name" placeholder="e.g. Online shop" required>
    <button>Create key</button>
  </form>
</section>

<?php if (!empty($api_key)): ?>
  <section class="key-card">
    <div>
      <p class="eyebrow">New key</p>
      <strong>Copy it now</strong>
    </div>
    <code><?= \App\View::e($api_key['token']) ?></code>
    <small>It will not be shown again.</small>
  </section>
<?php endif; ?>

<section class="panel api-keys-panel">
  <div class="panel-header">
    <div>
      <h3>API keys</h3>
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
            <td colspan="4"><small>No API keys yet.</small></td>
          </tr>
        <?php else: ?>
          <?php foreach ($api_keys as $key): ?>
            <tr>
              <td>
                <strong><?= \App\View::e($key['name']) ?></strong>
                <small>Integration ID <?= \App\View::e($key['id']) ?></small>
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
