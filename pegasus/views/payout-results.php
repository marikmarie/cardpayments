<?php /** @var array $review */ /** @var array $results */ ?>
<section class="create-heading">
  <p class="eyebrow">Payout results</p>
  <h1><?= \App\View::e($review['type'] === 'salary' ? 'Salary batch sent' : 'Allowance batch sent') ?></h1>
  <p>Each row shows the response returned by PegPay and the ID saved in the transaction table.</p>
</section>
<section class="panel form-section">
  <div class="table-wrap"><table>
    <thead><tr><th>Staff member</th><th>Amount</th><th>Status</th><th>PegPay ID</th></tr></thead>
    <tbody><?php foreach ($results as $item): $transaction = $item['transaction']; ?><tr>
      <td><strong><?= \App\View::e($item['name']) ?></strong><small><?= \App\View::e($item['department']) ?></small></td>
      <td>UGX <?= number_format((int) $item['amount']) ?></td>
      <td><span class="status <?= strtolower((string) ($transaction['status'] ?? '')) ?>"><?= \App\View::e($transaction['status'] ?? 'UNKNOWN') ?></span></td>
      <td><?= \App\View::e($transaction['pegpay_id'] ?? 'Not returned') ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="form-actions" style="margin-top:18px"><a class="outline-action" href="<?= $url('/pegasus-payouts') ?>">Start another payout</a><a class="primary-action" href="<?= $url('/pegasus-tester') ?>">Open PegPay log</a></div>
</section>
