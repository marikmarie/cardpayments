<?php
/** @var array $configured */
/** @var array $log */
?>
<section class="test-hero">
  <div>
    <p class="eyebrow">Card payments</p>
    <h1>Absa cards</h1>
    <p>Create hosted links and review safe gateway activity.</p>
  </div>
  <span class="session-pill"><?= \App\View::e($environment) ?></span>
</section>

<section class="check-grid">
  <article class="check-card <?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'ready' : 'attention' ?>">
    <span class="check-icon">1</span>
    <div><strong>Credentials</strong><p><?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'Loaded on the server.' : 'Add them to .env.' ?></p></div>
    <span class="check-state"><?= $configured['merchant_id'] && $configured['key_id'] && $configured['shared_secret'] ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card <?= $configured['webhook'] ? 'ready' : 'attention' ?>">
    <span class="check-icon">2</span>
    <div><strong>Webhook</strong><p><?= $configured['webhook'] ? 'Validation key is configured.' : 'Add the validation key.' ?></p></div>
    <span class="check-state"><?= $configured['webhook'] ? 'ready' : 'needed' ?></span>
  </article>
  <article class="check-card neutral">
    <span class="check-icon">3</span>
    <div><strong>Checkout</strong><p>Card details stay with the provider.</p></div>
    <span class="check-state">safe</span>
  </article>
</section>

<section class="panel card-workspace-actions">
  <div>
    <p class="eyebrow">Cards</p>
    <h3>Create a payment link</h3>
    <p>Use a unique invoice number.</p>
  </div>
  <div class="row-actions"><a class="primary-action" href="<?= $url('/links/create') ?>">Create payment link</a><a class="outline-action" href="<?= $url('/links') ?>">View invoices</a></div>
</section>

<section class="panel provider-log-panel">
  <div class="panel-header">
    <div><p class="eyebrow">Logs</p><h3>Card activity</h3><p>Safe gateway request and response events.</p></div>
  </div>
  <?php if ($log['entries']): ?>
    <div class="event-list provider-log-list">
      <?php foreach ($log['entries'] as $entry): ?>
        <div class="event-row"><span class="event-dot"></span><div><strong><?= \App\View::e(trim(($entry['type'] ?? 'Event') . ' · ' . ($entry['operation'] ?? 'card gateway'))) ?></strong><small><?= \App\View::e((string) ($entry['time'] ?? '')) ?><?= !empty($entry['status']) ? ' · ' . \App\View::e((string) $entry['status']) : '' ?><?= !empty($entry['message']) ? ' · ' . \App\View::e((string) $entry['message']) : '' ?><?= !empty($entry['correlation_id']) ? ' · Support ID ' . \App\View::e((string) $entry['correlation_id']) : '' ?><?= !empty($entry['diagnostic']) ? ' · ' . \App\View::e((string) $entry['diagnostic']) : '' ?></small></div></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="event-empty"><span>◌</span><p>No card activity yet.</p></div>
  <?php endif; ?>
</section>
