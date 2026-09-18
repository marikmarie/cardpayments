<?php
/** @var array $samples */
/** @var array|null $result */
/** @var array|null $verification */
/** @var array|null $status_result */
/** @var array|null $balance_result */
/** @var string $last_transaction_id */
/** @var array $log */
/** @var array $mobile_networks */
/** @var array $payout_networks */
/** @var array $bank_test_accounts */
/** @var string $active_tab */
$testProfiles = ['AIRTEL' => ['account' => '256702685176', 'network' => 'AIRTEL']];
foreach ($bank_test_accounts as $code => $recipient) {
  $testProfiles[$code] = ['account' => $recipient['account'], 'network' => $recipient['network']];
}
?>
<section class="create-heading">
  <p class="eyebrow">PegPay UAT</p>
  <h1>Test collections and payouts</h1>
  <p>Use Pegasus test accounts only. The examples include mobile-money collections, mobile payouts, and bank payouts.</p>
</section>

<section class="warning">
  <strong>Private key required</strong>
  <p>PULL collections use MTN or Airtel as documented. PUSH payouts can use mobile money or the listed bank codes. Set <code>PEGASUS_PRIVATE_KEY_PATH</code> to the RSA private key, never to a public certificate.</p>
</section>

<nav class="pegasus-tabs" aria-label="PegPay tester functions" data-default-tab="<?= \App\View::e($active_tab) ?>">
  <button type="button" class="pegasus-tab" data-pegasus-tab="verify">Verify</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="collect">Collect</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="mobile-payout">Mobile payout</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="bank-payout">Bank payout</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="status">Status</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="balance">Balance</button>
  <button type="button" class="pegasus-tab" data-pegasus-tab="log">Log</button>
</nav>

<section class="panel form-section" data-pegasus-panel="verify">
  <div class="section-title">
    <span>✓</span>
    <div>
      <h3>Verify recipient</h3>
      <p>Document test examples: <code>256702685176</code> with AIRTEL, or <code>60001256421</code> with ABSA.</p>
    </div>
  </div>
  <form class="form-grid" action="<?= $url('/pegasus-tester/verify') ?>" method="post">
    <label>
      Mobile number or bank account
      <input id="verify-account" name="account" inputmode="numeric" value="256702685176" required>
    </label>
    <label>
      Network or bank
      <select name="network" data-account-target="verify-account" required>
        <?php foreach ($payout_networks as $code => $name): ?>
          <option value="<?= $code ?>" <?= $code === 'AIRTEL' ? 'selected' : '' ?>><?= \App\View::e($name) ?> (<?= $code ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="form-actions compact-actions">
      <button class="secondary-action">Verify recipient</button>
    </div>
  </form>
  <?php if ($verification): ?>
    <p><strong>Response returned by PegPay</strong></p>
    <pre class="code-block"><?= \App\View::e(json_encode($verification['provider_response'] ?? $verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</section>

<section class="panel form-section" data-pegasus-panel="status" hidden>
  <div class="section-title">
    <span>↻</span>
    <div>
      <h3>Check transaction status</h3>
      <p>Enter a transaction created in this tester. For a pending payout, wait at least five seconds before checking.</p>
    </div>
  </div>
  <form class="form-grid" action="<?= $url('/pegasus-tester/status') ?>" method="post">
    <label>
      Vendor transaction ID
      <input name="vendor_transaction_id" value="<?= \App\View::e($last_transaction_id) ?>" maxlength="60" required>
    </label>
    <div class="form-actions compact-actions">
      <button class="secondary-action">Check status</button>
    </div>
  </form>
  <?php if ($status_result): ?>
    <p><strong>Response returned by PegPay</strong></p>
    <pre class="code-block"><?= \App\View::e(json_encode($status_result['provider_response'] ?? $status_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</section>

<section class="panel form-section" data-pegasus-panel="balance" hidden>
  <div class="section-title">
    <span>₵</span>
    <div>
      <h3>Get balance</h3>
      <p>Retrieve the current PegPay vendor-account balance.</p>
    </div>
  </div>
  <form class="form-actions compact-actions" action="<?= $url('/pegasus-tester/balance') ?>" method="post">
    <button class="secondary-action">Get balance</button>
  </form>
  <?php if ($balance_result): ?>
    <p><strong>Response returned by PegPay</strong></p>
    <pre class="code-block"><?= \App\View::e(json_encode($balance_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</section>

<div class="link-form">
  <?php foreach ($samples as $sample): ?>
    <?php $tab = $sample['type'] === 'PULL' ? 'collect' : ($sample['channel'] === 'bank' ? 'bank-payout' : 'mobile-payout'); ?>
    <form class="panel form-section" action="<?= $url('/pegasus-tester/transactions') ?>" method="post" data-pegasus-panel="<?= $tab ?>" hidden>
      <input type="hidden" name="transaction_type" value="<?= \App\View::e($sample['type']) ?>">
      <?php $networks = $sample['type'] === 'PULL' ? $mobile_networks : $payout_networks; ?>
      <div class="section-title">
        <span><?= $sample['type'] === 'PULL' ? '↓' : '↑' ?></span>
        <div>
          <h3><?= \App\View::e($sample['title']) ?> <small>(<?= \App\View::e($sample['type']) ?>)</small></h3>
          <p><?= $sample['type'] === 'PULL' ? 'Collect from an MTN or Airtel mobile wallet.' : ($sample['channel'] === 'bank' ? 'Send to a bank account using its PegPay bank code.' : 'Send from your account to a mobile-money recipient.') ?></p>
        </div>
      </div>

      <div class="form-grid">
        <label>
          Vendor transaction ID
          <input name="vendor_transaction_id" value="<?= \App\View::e($sample['reference']) ?>" maxlength="60" required>
        </label>
        <label>
          Amount (UGX)
          <input name="amount" type="number" min="500" step="1" value="<?= \App\View::e($sample['amount']) ?>" required>
        </label>
        <label>
          From account
          <input id="<?= $tab ?>-from-account" name="from_account" inputmode="numeric" value="<?= \App\View::e($sample['from_account']) ?>" required>
        </label>
        <label>
          From network
          <select name="from_network" data-account-target="<?= $tab ?>-from-account" required>
            <?php foreach ($networks as $code => $name): ?>
              <option value="<?= $code ?>" <?= $code === $sample['from_network'] ? 'selected' : '' ?>><?= \App\View::e($name) ?> (<?= $code ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($sample['channel'] === 'bank'): ?>
          <label class="wide">
            Bank test recipient
            <select id="bank-test-recipient" name="bank_test_recipient" data-account-target="bank-to-account" data-network-target="bank-to-network" required>
              <?php foreach ($bank_test_accounts as $code => $recipient): ?>
                <option value="<?= \App\View::e($code) ?>" <?= $code === $sample['to_network'] ? 'selected' : '' ?>>
                  <?= \App\View::e($recipient['name']) ?> — <?= \App\View::e($recipient['account']) ?> (<?= \App\View::e($recipient['network']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Recipient account
            <input id="bank-to-account" value="<?= \App\View::e($sample['to_account']) ?>" disabled>
          </label>
          <label>
            Recipient bank
            <input id="bank-to-network" value="<?= \App\View::e($sample['to_network']) ?>" disabled>
          </label>
        <?php elseif ($sample['type'] === 'PUSH'): ?>
          <label>
            To account
            <input id="<?= $tab ?>-to-account" name="to_account" inputmode="numeric" value="<?= \App\View::e($sample['to_account']) ?>" required>
          </label>
          <label>
            To network
            <select name="to_network" data-account-target="<?= $tab ?>-to-account" required>
              <?php foreach ($networks as $code => $name): ?>
                <option value="<?= $code ?>" <?= $code === $sample['to_network'] ? 'selected' : '' ?>><?= \App\View::e($name) ?> (<?= $code ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>
          Customer name
          <input name="customer_name" value="<?= \App\View::e($sample['customer_name']) ?>" maxlength="100">
        </label>
        <label>
          Customer reference
          <input name="customer_reference" value="<?= \App\View::e($sample['customer_reference']) ?>" maxlength="100">
        </label>
        <label class="wide">
          Narration
          <input name="narration" value="<?= \App\View::e($sample['narration']) ?>" maxlength="200">
        </label>
      </div>
      <div class="form-actions compact-actions">
        <button class="primary-action"><?= \App\View::e($sample['button']) ?></button>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<?php if ($result): ?>
  <section class="panel form-section" data-pegasus-panel="<?= \App\View::e($active_tab) ?>">
    <div class="section-title">
      <span>✓</span>
      <div>
        <h3>Latest PegPay response</h3>
        <p>Check a PENDING transaction with the PegPay status API after the provider's required wait time.</p>
      </div>
    </div>
    <pre class="code-block"><?= \App\View::e(json_encode($result['data']['provider_response'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </section>
<?php endif; ?>

<section class="panel form-section" data-pegasus-panel="log" hidden>
  <div class="section-title">
    <span>≡</span>
    <div>
      <h3>PegPay activity log</h3>
      <p><?= $log['writable'] ? 'Writing redacted requests and responses to storage/pegasus.log.' : 'The server cannot write storage/pegasus.log. Make the storage folder writable.' ?></p>
    </div>
  </div>
  <?php if ($log['entries']): ?>
    <pre class="code-block"><?= \App\View::e(json_encode($log['entries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php else: ?>
    <p>No PegPay requests have been logged yet.</p>
  <?php endif; ?>
</section>

<script>
(() => {
  const profiles = <?= json_encode($testProfiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tabs = document.querySelector('.pegasus-tabs');
  const showTab = (name) => {
    document.querySelectorAll('[data-pegasus-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.pegasusTab === name));
    document.querySelectorAll('[data-pegasus-panel]').forEach((panel) => { panel.hidden = panel.dataset.pegasusPanel !== name; });
  };
  tabs.querySelectorAll('[data-pegasus-tab]').forEach((button) => button.addEventListener('click', () => showTab(button.dataset.pegasusTab)));
  showTab(tabs.dataset.defaultTab || 'verify');

  document.querySelectorAll('[data-account-target]').forEach((select) => {
    const fill = () => {
      const profile = profiles[select.value];
      const account = document.getElementById(select.dataset.accountTarget);
      const network = document.getElementById(select.dataset.networkTarget);
      if (!account) return;
      account.value = profile ? profile.account : '';
      account.placeholder = profile ? '' : 'No supplied UAT account for this network';
      if (network) network.value = profile ? profile.network : select.value;
    };
    select.addEventListener('change', fill);
    if (select.id === 'bank-test-recipient') fill();
  });
})();
</script>
