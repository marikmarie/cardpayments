<section class="checkout-card login-card">
  <div>
    <p class="eyebrow">CissyTech Payments</p>
    <h1>Sign in to the dashboard</h1>
    <p class="checkout-copy">Enter the dashboard access token to continue.</p>
  </div>

  <?php if (!$configured): ?>
    <div class="flash error">Dashboard login is not configured. Add DASHBOARD_ACCESS_TOKEN to the environment.</div>
  <?php else: ?>
    <form class="login-form" method="post" action="<?= $url('/login') ?>">
      <label>
        Dashboard access token
        <input name="token" type="password" autocomplete="current-password" required autofocus>
      </label>
      <button class="payment-button" type="submit">Sign in</button>
    </form>
  <?php endif; ?>
</section>
