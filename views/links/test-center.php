<?php
$statusLabel = fn(array $link): string => strtoupper((string) ($link['status'] ?? 'CREATED'));
?>

<section class="test-hero">
  <div>
    <h1>Test payments</h1>
    <p>Create a link, pay on CyberSource, then refresh its status.</p>
  </div>
  <a class="primary-action" href="<?= $url('/links/create') ?>">New test link</a>
</section>

<section class="check-grid">
  <article class="check-card ready">
    <span class="check-icon">1</span>
    <div>
      <strong>Hosted checkout</strong>
      <p>Create and open a link.</p>
    </div>
    <span class="check-state">Ready</span>
  </article>
  <article class="check-card <?= $webhook_ready ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div>
      <strong>Webhook</strong>
      <p><?= $webhook_ready ? 'Ready to receive updates.' : 'Not configured.' ?></p>
    </div>
    <span class="check-state"><?= $webhook_ready ? 'Configured' : 'Set up' ?></span>
  </article>
  <article class="check-card neutral">
    <span class="check-icon">3</span>
    <div>
      <strong>Direct card API</strong>
      <p>Disabled.</p>
    </div>
    <span class="check-state">Disabled</span>
  </article>
</section>

<section class="panel test-links-panel">
  <div class="panel-header">
    <div>
      <h3>Payment links</h3>
      <p>Refresh to get the latest status.</p>
    </div>
    <a href="<?= $url('/links/create') ?>">New link <span>→</span></a>
  </div>

  <?php if (!$links): ?>
    <div class="empty-state">
      <span>✓</span>
      <h3>No payment links</h3>
      <a class="primary-action" href="<?= $url('/links/create') ?>">Create link</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Checkout</th>
            <th>Status</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($links as $link): ?>
            <tr>
              <td>
                <strong><?= \App\View::e($link['invoice_number']) ?></strong>
                <small><?= \App\View::e($link['currency']) ?> <?= \App\View::e($link['amount']) ?></small>
              </td>
              <td>
                <a class="checkout-link" href="<?= \App\View::e(\App\Services\CheckoutLink::selectedUrl($link, $checkout_type)) ?>" target="_blank" rel="noreferrer">Open checkout ↗</a>
              </td>
              <td>
                <span class="status <?= strtolower(\App\View::e($statusLabel($link))) ?>">
                  <?= \App\View::e($statusLabel($link)) ?>
                </span>
              </td>
              <td>
                <small><?= !empty($link['refreshed_at']) ? \App\View::e(substr((string) $link['refreshed_at'], 0, 16) . ' UTC') : 'Not refreshed yet' ?></small>
              </td>
              <td>
                <div class="row-actions">
                  <form action="<?= $url('/test-center/links/' . $link['id'] . '/refresh') ?>" method="post">
                    <button class="ghost-button">Refresh</button>
                  </form>
                  <?php if (!in_array($statusLabel($link), ['PAID', 'COMPLETED', 'CANCELED'], true)): ?>
                    <form action="<?= $url('/test-center/links/' . $link['id'] . '/email') ?>" method="post">
                      <button class="ghost-button">Email customer</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="test-detail-grid">
  <article class="panel webhook-panel">
    <div class="panel-header">
      <div>
        <h3>Webhook monitor</h3>
        <p>CyberSource sends payment updates here.</p>
      </div>
      <span class="status <?= $webhook_ready ? 'paid' : 'created' ?>">
        <?= $webhook_ready ? 'READY' : 'NOT CONFIGURED' ?>
      </span>
    </div>
    <div class="webhook-body">
      <label>
        Callback URL
        <input readonly value="<?= \App\View::e($callback_url) ?>">
      </label>
      <label>
        Health-check URL
        <input readonly value="<?= \App\View::e($callback_url . '/health') ?>">
      </label>
      <p class="helper-copy">
        <?= $webhook_ready ? 'Signed events are shown below.' : 'Set a Webhooks Digital Signature Key after deployment.' ?>
      </p>
      <a class="docs-link" href="<?= $url('/developers/api#webhooks') ?>">Webhook setup notes →</a>
    </div>
  </article>

  <article class="panel event-panel">
    <div class="panel-header">
      <div>
      <h3>Events</h3>
      </div>
      <a href="<?= $url('/webhooks/cybersource/health') ?>" target="_blank" rel="noreferrer">Check health ↗</a>
    </div>
    <div class="event-list">
      <?php if (!$events): ?>
        <div class="event-empty">
          <span>◌</span>
          <p>No events yet.</p>
        </div>
      <?php else: ?>
        <?php foreach (array_slice($events, 0, 5) as $event): ?>
          <?php $payload = $event['payload'] ?? []; ?>
          <div class="event-row">
            <span class="event-dot"></span>
            <div>
              <strong><?= \App\View::e($payload['eventType'] ?? 'CyberSource event') ?></strong>
              <small><?= \App\View::e($event['received_at'] ?? '') ?></small>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="api-test-band">
  <div>
    <h3>API testing</h3>
    <p>Use the API reference to test your integration.</p>
  </div>
  <a class="outline-action" href="<?= $url('/developers/api') ?>">API reference</a>
</section>
