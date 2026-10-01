<?php /** @var array $result */ ?>
<?php $success = !empty($result['valid']) && ($result['status'] ?? '') === 'SUCCESS'; ?>
<section class="checkout-card">
  <p class="eyebrow">PegPay payment</p>
  <h1><?= $success ? 'Payment successful' : 'Payment update received' ?></h1>
  <p class="checkout-copy"><?= $success ? 'Your payment was confirmed by PegPay.' : \App\View::e($result['reason'] ?? 'We are waiting for a confirmed payment result.') ?></p>
  <?php if (!empty($result['record'])): ?><div class="checkout-summary">
    <div><span>Amount</span><strong><?= \App\View::e($result['record']['currency']) ?> <?= \App\View::e($result['record']['amount']) ?></strong></div>
    <div><span>Reference</span><strong><?= \App\View::e($result['vendorId'] ?? $result['record']['id']) ?></strong></div>
  </div><?php endif; ?>
  <p class="checkout-note"><?= !empty($result['valid']) ? 'PegPay response signature verified.' : 'This response could not be verified. Do not treat it as a completed payment.' ?></p>
</section>
