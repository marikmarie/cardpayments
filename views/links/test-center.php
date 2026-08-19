<?php
$statusLabel = fn(array $link): string => strtoupper((string) ($link['status'] ?? 'CREATED'));
?>

<section class="test-hero">
  <div>
    <p class="eyebrow">Operations workspace</p>
    <h1>Test the complete payment flow.</h1>
    <p>Keep cards on CyberSource’s checkout page, then refresh the invoice here or let a signed webhook update it automatically.</p>
  </div>
  <a class="primary-action" href="<?= $url('/links/create') ?>">＋ Create test link</a>
</section>

<section class="check-grid">
  <article class="check-card ready">
    <span class="check-icon">1</span>
    <div>
      <strong>Hosted checkout</strong>
      <p>Create a link, then open it in a new tab to make the test payment.</p>
    </div>
    <span class="check-state">Ready</span>
  </article>
  <article class="check-card <?= $webhook_ready ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div>
      <strong>Automatic payment updates</strong>
      <p><?= $webhook_ready ? 'Your webhook signature key is configured.' : 'Add a CyberSource webhook signature key to enable this.' ?></p>
    </div>
    <span class="check-state"><?= $webhook_ready ? 'Configured' : 'Set up' ?></span>
  </article>
  <article class="check-card neutral">
    <span class="check-icon">3</span>
    <div>
      <strong>Direct card API</strong>
      <p>Intentionally disabled. It requires PCI DSS approval and never belongs in browser testing.</p>
    </div>
    <span class="check-state">Protected</span>
  </article>
</section>

<section class="panel test-flow-panel">
  <div class="panel-header">
    <div>
      <h3>How you confirm a customer has paid</h3>
      <p>Use this exact sequence for every test and live payment.</p>
    </div>
  </div>
  <div class="payment-flow">
    <div>
      <span>1</span>
      <strong>Create</strong>
      <small>Create an invoice and receive the secure payment URL.</small>
    </div>
    <i></i>
    <div>
      <span>2</span>
      <strong>Pay</strong>
      <small>Open the URL. The customer enters card details only on CyberSource.</small>
    </div>
    <i></i>
    <div>
      <span>3</span>
      <strong>Confirm</strong>
      <small>Use Refresh status below, or receive the signed <code>paid</code> webhook.</small>
    </div>
  </div>
</section>

<section class="panel test-links-panel">
  <div class="panel-header">
    <div>
      <h3>Hosted link tests</h3>
      <p>Refreshing calls CyberSource’s invoice-status endpoint. A <strong>PAID</strong> status confirms a completed payment.</p>
    </div>
    <a href="<?= $url('/links/create') ?>">New link <span>→</span></a>
  </div>

  <?php if (!$links): ?>
    <div class="empty-state">
      <span>✓</span>
      <h3>No open payment links</h3>
      <p>Create a new test link to begin a hosted checkout test.</p>
      <a class="primary-action" href="<?= $url('/links/create') ?>">Create payment link</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Checkout</th>
            <th>Current status</th>
            <th>Last refresh</th>
            <th>Test actions</th>
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
                <a class="checkout-link" href="<?= \App\View::e($link['payment_url']) ?>" target="_blank" rel="noreferrer">Open secure checkout ↗</a>
                <small>Card data stays with CyberSource</small>
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
                    <button class="ghost-button">Refresh status</button>
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
        <p>CyberSource sends signed payment events to this public URL.</p>
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
        <?= $webhook_ready ? 'The dashboard will record signed invoice events below.' : 'A local URL cannot receive CyberSource events. Put this app on a public HTTPS address, create a Webhooks Digital Signature Key, and add its key ID and secret to .env.' ?>
      </p>
      <a class="docs-link" href="<?= $url('/developers/api#webhooks') ?>">Webhook setup notes →</a>
    </div>
  </article>

  <article class="panel event-panel">
    <div class="panel-header">
      <div>
        <h3>Received events</h3>
        <p>Only valid signed CyberSource notifications are saved.</p>
      </div>
      <a href="<?= $url('/webhooks/cybersource/health') ?>" target="_blank" rel="noreferrer">Check health ↗</a>
    </div>
    <div class="event-list">
      <?php if (!$events): ?>
        <div class="event-empty">
          <span>◌</span>
          <p>No signed events received yet.</p>
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
    <p class="eyebrow">For systems integrating with you</p>
    <h3>External API test checklist</h3>
    <p>
      <code>POST /api/v1/payment-links</code> creates the hosted checkout. Then use
      <code>GET /api/v1/payment-links/{id}?refresh=true</code> to ask CyberSource for the latest payment status.
    </p>
  </div>
  <a class="outline-action" href="<?= $url('/developers/api') ?>">View API reference <span>→</span></a>
</section>
