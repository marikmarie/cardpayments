<?php /* Card module public-payment layout. */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= \App\View::e(($title ?? 'Secure payment') . ' · CissyTech') ?></title>
  <link rel="stylesheet" href="<?= $url('/assets/app.css') ?>">
</head>
<body class="checkout-body">
  <header class="checkout-header">
    <a class="brand" href="<?= $url('/links') ?>">
      <span class="brand-mark">▯</span>
      <span>Cissy<span>Tech</span><small>Payments</small></span>
    </a>
    <span>Secure checkout</span>
  </header>
  <main class="checkout-main">
    <?php if (!empty($flash)): ?>
      <div class="flash <?= \App\View::e($flash['type']) ?>">
        <?= \App\View::e($flash['message']) ?>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</body>
</html>
