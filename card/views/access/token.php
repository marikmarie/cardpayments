<section class="checkout-card access-card">
  <div>
    <p class="eyebrow">Dashboard access</p>
    <h1>Enter your access token</h1>
    <p class="checkout-copy">Use the token configured for this CissyTech Payments dashboard.</p>
  </div>

  <?php if (!$configured): ?>
    <div class="flash error">Dashboard access is not configured. Add DASHBOARD_ACCESS_TOKEN to the environment.</div>
  <?php else: ?>
    <form class="access-form" method="post" action="<?= $url('/access') ?>">
      <label>
        Access token
        <input name="token" type="password" autocomplete="current-password" required autofocus>
      </label>
      <button class="payment-button" type="submit">Continue</button>
    </form>
  <?php endif; ?>
</section>
