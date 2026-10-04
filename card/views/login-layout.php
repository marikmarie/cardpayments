<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= \App\View::e(($title ?? 'Sign in') . ' · CissyTech Payments') ?></title>
  <link rel="stylesheet" href="<?= $url('/assets/app.css') ?>">
</head>
<body class="checkout-body">
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
