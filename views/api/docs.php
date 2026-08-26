<section class="docs-hero">
  <div>
    <p class="eyebrow">Developer documentation</p>
    <h1>Build on the CissyTech Payments API.</h1>
    <p>Create an invoice from your backend. The active payment page is set on Overview. Card details always go to CyberSource.</p>
  </div>
  <a class="primary-action" href="<?= $url('/api/v1/openapi.json') ?>" target="_blank" rel="noreferrer">
    Download OpenAPI JSON ↗
  </a>
</section>

<section class="docs-layout">
  <aside class="docs-nav">
    <strong>On this page</strong>
    <a href="#start">Get started</a>
    <a href="#payments">Process a payment</a>
    <a href="#links">Create an invoice</a>
    <a href="#retrieve">Confirm payment</a>
    <a href="#webhooks">Webhooks</a>
    <a href="#errors">Errors &amp; security</a>
  </aside>

  <div class="docs-content">
    <section id="start" class="docs-section panel">
      <p class="eyebrow">01 / Get started</p>
      <h2>Authenticate every request</h2>
      <p>Create an API key from the dashboard, then send it in the <code>X-API-Key</code> header. All requests and responses use JSON.</p>
      <div class="code-block">
        <span>BASE URL</span>
        <code><?= \App\View::e($base_url) ?></code>
      </div>
      <div class="code-block">
        <span>REQUEST HEADERS</span>
        <pre>Content-Type: application/json
X-API-Key: plk_test_...</pre>
      </div>
    </section>

    <section id="payments" class="docs-section panel">
      <p class="eyebrow">02 / Direct payments — optional</p>
      <div class="endpoint-title">
        <div>
          <h2>Process a card payment</h2>
          <p>Submits a sale to CyberSource and returns your local transaction record with the provider IDs.</p>
        </div>
        <span class="method post">POST</span>
      </div>
      <code class="path">/api/v1/payments</code>
      <div class="warning">
        <strong>Disabled by default</strong>
        <p>This endpoint returns <code>403</code> until CissyTech enables it after PCI DSS approval. Use it only from a PCI-compliant server; never send card number or CVV from a browser.</p>
      </div>
      <div class="request-grid">
        <div>
          <h3>Request body</h3>
          <pre>{ "amount": "1000.00", "currency": "UGX", "reference": "ORDER-1002", "card": { "number": "4111111111111111", "expiration_month": "12", "expiration_year": "2031", "security_code": "123" }, "bill_to": { "firstName": "John", "lastName": "Doe", "address1": "1 Market St", "locality": "Kampala", "administrativeArea": "Central", "postalCode": "256", "country": "UG", "email": "mariam@gmail.com" } }</pre>
        </div>
        <div>
          <h3>201 response</h3>
          <pre>{ "data": { "id": "your-transaction-id", "provider_payment_id": "cybersource-id", "processor_transaction_id": "processor-id", "status": "AUTHORIZED", "reference": "ORDER-1002", "amount": "1000.00", "currency": "UGX" } }</pre>
        </div>
      </div>
    </section>

    <section id="links" class="docs-section panel">
      <p class="eyebrow">03 / Hosted checkout</p>
      <div class="endpoint-title">
        <div>
          <h2>Create an invoice</h2>
          <p>Creates one CyberSource invoice and returns the payment link currently selected on Overview.</p>
        </div>
        <span class="method post">POST</span>
      </div>
      <code class="path">/api/v1/payment-links</code>
      <div class="request-grid">
        <div>
          <h3>Request body</h3>
          <pre>{ "amount": "1000.00", "currency": "UGX", "invoice_number": "ORDER-1001", "description": "Order payment", "send": false, "customer": { "name": "Mariam", "email": "mariam@gmail.com" } }</pre>
        </div>
        <div>
          <h3>201 response</h3>
          <pre>{ "data": { "invoice_number": "ORDER-1001", "payment_url": "active payment link" } }</pre>
        </div>
      </div>
      <div class="warning">
        <strong>Choose the checkout once</strong>
        <p>On Overview, select CissyTech for a branded page before CyberSource or CyberSource for direct checkout. Every vendor receives only the active link.</p>
      </div>
    </section>

    <section id="retrieve" class="docs-section panel">
      <p class="eyebrow">04 / Confirm payment</p>
      <div class="endpoint-title">
        <div>
          <h2>Retrieve or refresh a payment link</h2>
          <p>Read the local link record, or ask CyberSource for the current invoice status after the customer finishes hosted checkout.</p>
        </div>
        <span class="method get">GET</span>
      </div>
      <code class="path">/api/v1/payment-links/{id}?refresh=true</code>
      <div class="warning">
        <strong>How to know the customer paid</strong>
        <p>Call this URL with <code>refresh=true</code>. A returned <code>PAID</code> status confirms CyberSource has completed the invoice. Without <code>refresh=true</code>, the API only returns the last locally known status.</p>
      </div>
      <p>Returns <code>200</code> with the payment-link record, <code>401</code> for an invalid API key, <code>404</code> when the ID does not exist, or <code>502</code> when CyberSource could not be reached for a refresh.</p>
    </section>

    <section id="webhooks" class="docs-section panel">
      <p class="eyebrow">05 / Automatic status updates</p>
      <div class="endpoint-title">
        <div>
          <h2>Receive signed CyberSource webhooks</h2>
          <p>Use this in production so invoices update automatically rather than waiting for your system to refresh them.</p>
        </div>
        <span class="method post">POST</span>
      </div>
      <code class="path">/webhooks/cybersource</code>
      <p>Set a public HTTPS callback URL and health-check URL in CyberSource. Create a dedicated Webhooks Digital Signature Key and put its key ID and secret in your environment. CissyTech validates the <code>v-c-signature</code> header before saving an event or updating a link to <code>PAID</code>, <code>PARTIALLY_PAID</code>, <code>CANCELED</code>, or <code>SENT</code>. See the Test center for the exact URLs.</p>
    </section>

    <section id="errors" class="docs-section panel">
      <p class="eyebrow">06 / Errors &amp; security</p>
      <h2>Build safely</h2>
      <div class="doc-list">
        <div><strong>400</strong><span>The JSON request body is invalid.</span></div>
        <div><strong>401</strong><span>The <code>X-API-Key</code> is missing or invalid.</span></div>
        <div><strong>403</strong><span>Direct card payments have not been enabled for this account.</span></div>
        <div><strong>422</strong><span>Input validation or CyberSource rejected the request.</span></div>
        <div><strong>502</strong><span>CyberSource could not be reached for a requested status refresh.</span></div>
      </div>
      <p>Use a unique order reference for reconciliation, keep API keys on your server, and store the returned provider payment ID. CissyTech verifies signed CyberSource invoice webhooks before updating a payment-link status. The machine-readable contract is available at <a href="<?= $url('/api/v1/openapi.json') ?>">/api/v1/openapi.json</a>.</p>
    </section>
  </div>
</section>
