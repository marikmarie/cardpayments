<?php /** @var array $collection */ /** @var string $gateway_url */ /** @var array $fields */ ?>
<section class="checkout-card">
  <p class="eyebrow">PegPay secure checkout</p>
  <h1>Continue to payment</h1>
  <p class="checkout-copy">You are being taken to PegPay's secure page to complete this payment.</p>
  <div class="checkout-summary">
    <div><span>Amount</span><strong><?= \App\View::e($collection['currency']) ?> <?= \App\View::e($collection['amount']) ?></strong></div>
    <div><span>Reference</span><strong><?= \App\View::e($collection['id']) ?></strong></div>
  </div>
  <form id="pegpay-card-checkout" action="<?= \App\View::e($gateway_url) ?>" method="post">
    <?php foreach ($fields as $name => $value): ?>
      <input type="hidden" name="<?= \App\View::e($name) ?>" value="<?= \App\View::e($value) ?>">
    <?php endforeach; ?>
    <button class="primary-action checkout-button">Continue to PegPay</button>
  </form>
  <p class="checkout-note">Card details are entered on PegPay, not on CissyTech.</p>
</section>
<script>document.getElementById('pegpay-card-checkout').submit();</script>
