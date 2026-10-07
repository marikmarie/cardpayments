<?php /** @var array $staff */ /** @var array|null $review */ /** @var array $log */ ?>
<?php if (!$review): ?>
  <section class="create-heading">
    <p class="eyebrow">Payouts</p>
    <h1>Prepare staff payments</h1>
    <p>Select staff, choose the payment type, and review the batch before sending it to PegPay.</p>
  </section>

  <form class="panel form-section" action="<?= $url('/pegasus-payouts/review') ?>" method="post">
    <div class="section-title">
      <span>1</span>
      <div><h3>Select payment type</h3><p>Use the supplied Airtel UAT wallet while testing.</p></div>
    </div>
    <div class="form-grid">
      <label>Payment type
        <select name="payout_type"><option value="allowance">Daily allowance</option><option value="salary">Monthly salary</option></select>
      </label>
    </div>
    <div class="section-title" style="margin-top:22px">
      <span>2</span>
      <div><h3>Select staff</h3><p>These are sample staff records for the UAT journey.</p></div>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Select</th><th>Staff member</th><th>Department</th><th>Payment wallet</th></tr></thead>
      <tbody><?php foreach ($staff as $person): ?><tr>
        <td><input style="width:auto;margin:0" type="checkbox" name="staff[]" value="<?= \App\View::e($person['id']) ?>" checked></td>
        <td><strong><?= \App\View::e($person['name']) ?></strong></td>
        <td><?= \App\View::e($person['department']) ?></td>
        <td>Airtel test wallet ending <?= \App\View::e(substr($person['phone'], -4)) ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="form-actions compact-actions" style="margin-top:18px"><button class="primary-action">Review selected payments</button></div>
  </form>
<?php else: ?>
  <?php $total = array_sum(array_map(static fn(array $item): int => (int) $item['amount'], $review['items'])); ?>
  <section class="create-heading">
    <p class="eyebrow">Payout review</p>
    <h1>Review <?= \App\View::e($review['type'] === 'salary' ? 'monthly salaries' : 'daily allowances') ?></h1>
    <p><?= count($review['items']) ?> staff selected · Total UGX <?= number_format($total) ?></p>
  </section>
  <section class="panel form-section">
    <div class="table-wrap"><table>
      <thead><tr><th>Staff member</th><th>Department</th><th>Amount</th><th>Payment type</th></tr></thead>
      <tbody><?php foreach ($review['items'] as $item): ?><tr>
        <td><strong><?= \App\View::e($item['name']) ?></strong></td>
        <td><?= \App\View::e($item['department']) ?></td>
        <td>UGX <?= number_format((int) $item['amount']) ?></td>
        <td><?= \App\View::e($review['type'] === 'salary' ? 'Monthly salary' : 'Daily allowance') ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="warning"><strong>UAT only</strong><p>Sending this batch creates one PegPay payout request per selected staff member using the supplied UAT wallet.</p></div>
    <div class="form-actions"><a class="outline-action" href="<?= $url('/pegasus-payouts') ?>">Back</a><form action="<?= $url('/pegasus-payouts/send') ?>" method="post"><button class="primary-action">Send <?= count($review['items']) ?> payouts</button></form></div>
  </section>
<?php endif; ?>

<section class="panel provider-log-panel">
  <div class="panel-header"><div><p class="eyebrow">Logs</p><h3>PegPay payout activity</h3><p>Latest redacted requests and responses for the PegPay payment rail.</p></div><a class="outline-action" href="<?= $url('/pegasus-tester') ?>">Open PegPay tester</a></div>
  <?php if ($log['entries']): ?>
    <pre class="code-block"><code><?= \App\View::e(json_encode($log['entries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></code></pre>
  <?php else: ?>
    <div class="event-empty"><span>◌</span><p>No payout activity has been logged yet.</p></div>
  <?php endif; ?>
</section>
