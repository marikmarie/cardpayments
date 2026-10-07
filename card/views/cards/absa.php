<?php
/** @var array $configured */
/** @var array $log */
?>
<section class="test-hero">
  <div>
    <p class="eyebrow">Card payments</p>
    <h1>Absa card workspace</h1>
    <p>Create hosted card payment links and review the gateway activity in one place. The current implementation routes card invoices through the configured CyberSource gateway; no card number or CVV reaches this application.</p>
  </div>
  <span class="session-pill"><?= \App\View::e($environment) ?></span>
</section>

<section class="check-grid">
  <article class="check-card <?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'ready' : 'attention' ?>">
    <span class="check-icon">1</span>
    <div><strong>Gateway credentials</strong><p><?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'Merchant credentials are loaded on the server.' : 'Add the card gateway merchant credentials in .env.' ?></p></div>
    <span class="check-state"><?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $configured['webhook'] ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div><strong>Webhook validation</strong><p><?= $configured['webhook'] ? 'The webhook validation key is configured.' : 'Add the webhook key before relying on automatic status updates.' ?></p></div>
    <span class="check-state"><?= $configured['webhook'] ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card neutral">
    <span class="check-icon">3</span>
    <div><strong>Hosted checkout</strong><p>Customers enter card details only on the provider-hosted checkout page.</p></div>
    <span class="check-state">safe</span>
  </article>
</section>

<section class="panel card-workspace-actions">
  <div>
    <p class="eyebrow">Next action</p>
    <h3>Create a hosted card payment</h3>
    <p>Use a unique invoice number. The dashboard keeps a local record and the provider log below records the request without secrets.</p>
  </div>
  <div class="row-actions"><a class="primary-action" href="<?= $url('/links/create') ?>">Create payment link</a><a class="outline-action" href="<?= $url('/links') ?>">View invoices</a></div>
</section>

<section class="panel provider-log-panel">
  <div class="panel-header">
    <div><p class="eyebrow">Logs</p><h3>Absa / CyberSource card activity</h3><p>Requests and responses are reduced to safe operational details. Card data, signatures, secrets, and customer fields are excluded.</p></div>
  </div>
  <?php if ($log['entries']): ?>
    <div class="event-list provider-log-list">
      <?php foreach ($log['entries'] as $entry): ?>
        <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e(trim(($entry['type'] ?? 'Event') . ' · ' . ($entry['operation'] ?? 'card gateway'))) ?></strong><small><?= \App\View::e((string) ($entry['time'] ?? '')) ?><?= !empty($entry['status']) ? ' · ' . \App\View::e((string) $entry['status']) : '' ?><?= !empty($entry['message']) ? ' · ' . \App\View::e((string) $entry['message']) : '' ?></small></div></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="event-empty"><span>◌</span><p>No card gateway events yet. Create a payment link or refresh an invoice to populate this log.</p></div>
  <?php endif; ?>
</section>
