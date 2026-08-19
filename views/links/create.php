<section class="create-heading">
  <h1>Create a payment link</h1>
  <p>Customers complete payment securely on CyberSource. You never receive their card details.</p>
</section>

<form class="link-form compact-link-form" action="<?= $url('/links') ?>" method="post">
  <section class="panel form-section compact-form-section">
    <div class="section-title">
      <span>01</span>
      <div>
        <h3>Payment link details</h3>
        <p>Create a secure hosted checkout link. It is shown in your workspace unless you choose email.</p>
      </div>
    </div>

    <div class="compact-form-grid">
      <label>
        Invoice number
        <input name="invoice_number" placeholder="INV-1001">
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
        Customer name <small>(optional)</small>
        <input name="customer_name" placeholder="Mariam Tukas">
      </label>
      <label>
        Email <small>(only needed to email)</small>
        <input name="customer_email" type="email" placeholder="customer@example.com">
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
          <strong>Allow partial payments</strong>
          <small>Allow more than one payment.</small>
        </div>
      </label>
      <label class="toggle">
        <input type="checkbox" name="send" value="1">
        <span></span>
        <div>
          <strong>Email customer</strong>
          <small>Leave off to create the link and open it yourself.</small>
        </div>
      </label>
    </div>
  </section>

  <div class="form-actions compact-actions">
    <button class="primary-action">Create and show payment link <span>→</span></button>
  </div>
</form>
