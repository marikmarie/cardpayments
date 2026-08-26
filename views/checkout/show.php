<?php
$status = strtoupper((string) ($link['status'] ?? 'CREATED'));
$complete = in_array($status, ['PAID', 'COMPLETED'], true);
$closed = in_array($status, ['PAID', 'COMPLETED', 'CANCELED'], true);
?>
<section class="checkout-card">
  <p class="eyebrow">CissyTech payment link</p>
  <h1><?= $complete ? 'Payment received' : ($status === 'CANCELED' ? 'Payment link closed' : 'Complete your payment') ?></h1>
  <p class="checkout-copy">
    <?= $complete ? 'This payment has been received.' : ($status === 'CANCELED' ? 'This payment link is no longer available.' : ' Continue to enter your card details.') ?>
  </p>

  <div class="checkout-summary">
    <div><span>Reference</span><strong><?= \App\View::e($link['invoice_number']) ?></strong></div>
    <div><span>Amount</span><strong><?= \App\View::e($link['currency']) ?> <?= \App\View::e($link['amount']) ?></strong></div>
    <div class="wide"><span>For</span><strong><?= \App\View::e($link['description']) ?></strong></div>
  </div>

  <div class="checkout-status-row">
    <span>Status</span>
    <span class="status <?= strtolower(\App\View::e($status)) ?>"><?= \App\View::e($status) ?></span>
  </div>

  <?php if (!$closed): ?>
    <a class="primary-action checkout-button" href="<?= \App\View::e($cybersource_url) ?>">Continue to secure payment</a>
  <?php endif; ?>

  <form class="checkout-refresh" action="<?= $url('/pay/' . $link['id'] . '/refresh') ?>" method="post">
    <button class="ghost-button">Check payment status</button>
  </form>
  <p class="checkout-note">Card details are handled by CyberSource. CissyTech does not collect or store card details.</p>
</section>
