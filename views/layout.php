<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= \App\View::e(($title ?? 'CissyTech Payments') . ' · CissyTech') ?></title>
  <link rel="stylesheet" href="<?= $url('/assets/app.css') ?>">
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="<?= $url('/links') ?>">
      <span class="brand-mark">▯</span>
      <span>Cissy<span>Tech</span><small>Payments</small></span>
    </a>

    <p class="workspace-label">Menu</p>
    <nav class="side-nav">
      <a class="<?= ($active_nav ?? 'overview') === 'overview' ? 'active' : '' ?>" href="<?= $url('/links') ?>">
        <span>▦</span> Overview
      </a>
      <a class="<?= ($active_nav ?? '') === 'create' ? 'active' : '' ?>" href="<?= $url('/links/create') ?>">
        <span>＋</span> Create invoice
      </a>
      <a class="<?= ($active_nav ?? '') === 'vendor' ? 'active' : '' ?>" href="<?= $url('/vendor-simulator') ?>">
        <span>↗</span> Vendor simulator
      </a>
      <a class="<?= ($active_nav ?? '') === 'api' ? 'active' : '' ?>" href="<?= $url('/developers/api') ?>">
        <span>⌘</span> API reference
      </a>
    </nav>


  </aside>

  <div class="workspace">
    <header class="topbar">
      <h2><?= \App\View::e($title ?? 'Overview') ?></h2>
      <a class="primary-action" href="<?= $url('/links/create') ?>">New invoice</a>
    </header>

    <main class="content">
      <?php if (!empty($flash)): ?>
        <div class="flash <?= \App\View::e($flash['type']) ?>">
          <?= \App\View::e($flash['message']) ?>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
