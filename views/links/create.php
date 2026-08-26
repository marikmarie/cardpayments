<section class="create-heading">
  <h1>New invoice</h1>
  <p><?= $checkout_type === 'cissytech' ? 'CissyTech payment page is active.' : 'CyberSource payment page is active.' ?> Card details are entered on CyberSource.</p>
</section>

<form class="link-form compact-link-form" action="<?= $url('/links') ?>" method="post">
  <section class="panel form-section compact-form-section">
    <div class="section-title">
      <div>
        <h3>Payment details</h3>
      </div>
    </div>

    <div class="compact-form-grid">
      <label>
        Invoice number
        <input name="invoice_number" placeholder="INV1001">
      </label>
      <label>
        Amount
        <input name="amount" type="number" min="1" step="0.01" placeholder="100000" required>
      </label>
      <label>
        Currency
        <input name="currency" value="UGX" maxlength="3" required>
      </label>
      <label>
        Due date
        <input name="due_date" type="date" value="<?= gmdate('Y-m-d', strtotime('+7 days')) ?>" required>
      </label>
      <label>
        Customer name <small>Optional</small>
        <input name="customer_name" placeholder="Mariam Tukas">
      </label>
      <label>
        Customer email 
        <input name="customer_email" type="email" placeholder="maiam@gmail.com">
      </label>
      <label class="wide">
        Description
        <textarea name="description" placeholder="What is this payment for?" required></textarea>
      </label>
    </div>

    <div class="compact-options">
      <label class="toggle">
        <input type="checkbox" name="allow_partial" value="1">
        <span></span>
        <div>
          <strong>Allow partial payment</strong>
          <small>More than one payment.</small>
        </div>
      </label>
      <label class="toggle">
        <input type="checkbox" name="send" value="1">
        <span></span>
        <div>
          <strong>Send email</strong>
          <small>Requires customer email.</small>
        </div>
      </label>
    </div>
  </section>

  <div class="form-actions compact-actions">
    <button class="primary-action">Create invoice</button>
  </div>
</form>
