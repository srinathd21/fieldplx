<?php
$pageTitle = 'Privacy Policy - FieldPlx';
include __DIR__ . '/topbar.php';

$effectiveDate = '4 September 2026';
$lastUpdated = '4 September 2026';
?>

<style>
  .legal-page{background:#fff;color:#071c2f;}
  .legal-page .site-container{max-width:1460px;}
  .legal-hero{position:relative;overflow:hidden;background:#031321;color:#fff;padding:92px 0 78px;}
  .legal-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 14% 25%,rgba(56,184,19,.18),transparent 28%),linear-gradient(135deg,#031321 0%,#071c2f 55%,#0a253c 100%);}
  .legal-hero .site-container{position:relative;z-index:1;}
  .legal-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;padding:7px 12px;border:1px solid rgba(255,255,255,.18);border-radius:999px;background:rgba(255,255,255,.07);font-size:.74rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;}
  .legal-hero h1{margin:0 0 16px;max-width:900px;font-size:clamp(2.7rem,5vw,4.7rem);line-height:1.02;font-weight:900;letter-spacing:-.045em;color:#fff;}
  .legal-hero h1 span{color:#38b813;}
  .legal-hero p{max-width:820px;margin:0;color:#e6eef2;font-size:1.02rem;line-height:1.75;}
  .legal-meta{display:flex;flex-wrap:wrap;gap:12px 22px;margin-top:22px;color:#cbd6dc;font-size:.82rem;}
  .legal-section{padding:64px 0 74px;}
  .legal-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:34px;align-items:start;}
  .legal-toc{position:sticky;top:92px;border:1px solid #e3e7ea;border-radius:12px;background:#fff;box-shadow:0 8px 22px rgba(7,28,47,.045);overflow:hidden;}
  .legal-toc-head{padding:16px 18px;border-bottom:1px solid #edf0f2;background:#f7f9fa;font-weight:900;font-size:.9rem;}
  .legal-toc a{display:block;padding:11px 18px;border-bottom:1px solid #f0f2f3;color:#4f5e68;font-size:.82rem;line-height:1.35;}
  .legal-toc a:last-child{border-bottom:0;}
  .legal-toc a:hover{background:#f5faf4;color:#2f9d17;}
  .legal-content{min-width:0;}
  .legal-card{margin-bottom:18px;padding:24px 26px;border:1px solid #e3e7ea;border-radius:12px;background:#fff;box-shadow:0 8px 22px rgba(7,28,47,.04);}
  .legal-card h2{margin:0 0 12px;color:#071c2f;font-size:1.35rem;font-weight:900;letter-spacing:-.02em;}
  .legal-card h3{margin:20px 0 8px;color:#143047;font-size:1rem;font-weight:900;}
  .legal-card p,.legal-card li{color:#5d6b75;font-size:.92rem;line-height:1.72;}
  .legal-card p{margin:0 0 12px;}
  .legal-card p:last-child{margin-bottom:0;}
  .legal-card ul{margin:8px 0 0;padding-left:20px;}
  .legal-card li{margin-bottom:8px;}
  .legal-highlight{padding:18px 20px;border:1px solid #d9ead4;border-radius:10px;background:linear-gradient(135deg,#f3faf1,#fbfdfb);}
  .legal-highlight strong{color:#2f9d17;}
  .legal-table-wrap{overflow-x:auto;margin-top:14px;border:1px solid #e5e9eb;border-radius:10px;}
  .legal-table{width:100%;border-collapse:collapse;min-width:760px;}
  .legal-table th,.legal-table td{padding:13px 14px;border-bottom:1px solid #edf0f2;text-align:left;vertical-align:top;font-size:.84rem;line-height:1.55;}
  .legal-table th{background:#f7f9fa;color:#173046;font-weight:900;}
  .legal-table td{color:#5d6b75;}
  .legal-table tr:last-child td{border-bottom:0;}
  .legal-cta{padding:0 0 72px;}
  .legal-cta-box{display:flex;justify-content:space-between;align-items:center;gap:24px;padding:30px 34px;border-radius:12px;background:#071c2f;color:#fff;}
  .legal-cta-box h2{margin:0 0 6px;color:#fff;font-size:1.6rem;font-weight:900;}
  .legal-cta-box p{margin:0;color:#d5dfe5;font-size:.9rem;line-height:1.55;}
  .legal-cta-box a{flex:0 0 auto;}
  @media(max-width:991.98px){.legal-layout{grid-template-columns:1fr}.legal-toc{position:static}.legal-hero{padding:70px 0 58px;}}
  @media(max-width:767.98px){.legal-section{padding:48px 0 58px}.legal-card{padding:20px 18px}.legal-cta-box{flex-direction:column;align-items:flex-start;padding:26px 22px}.legal-cta-box a{width:100%}}
</style>

<main class="legal-page">
  <section class="legal-hero">
    <div class="container-fluid site-container">
      <div class="legal-kicker">FieldPlx Legal</div>
      <h1>Privacy <span>Policy</span></h1>
      <p>This Privacy Policy explains how FieldPlx collects, uses, stores, and shares information when businesses, administrators, employees, field workers, and other authorized users use the FieldPlx website, web application, mobile application, and related services.</p>
      <div class="legal-meta">
        <span><strong>Effective:</strong> <?= htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8'); ?></span>
        <span><strong>Last updated:</strong> <?= htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
  </section>

  <section class="legal-section">
    <div class="container-fluid site-container">
      <div class="legal-layout">
        <aside class="legal-toc">
          <div class="legal-toc-head">On this page</div>
          <a href="#scope">1. Scope</a>
          <a href="#collect">2. Information we collect</a>
          <a href="#location">3. Location data</a>
          <a href="#use">4. How we use information</a>
          <a href="#share">5. How we share information</a>
          <a href="#retention">6. Data retention</a>
          <a href="#security">7. Security</a>
          <a href="#choices">8. Your choices and rights</a>
          <a href="#children">9. Children</a>
          <a href="#international">10. International processing</a>
          <a href="#changes">11. Changes</a>
          <a href="#contact">12. Contact</a>
        </aside>

        <div class="legal-content">
          <section class="legal-card legal-highlight" id="scope">
            <h2>1. Scope and who controls the data</h2>
            <p><strong>FieldPlx</strong> is a field-service operations platform provided by CorePLX. Businesses use FieldPlx to manage employees, customers, jobs, scheduling, service activity, quotations, invoices, payments, products, inventory, and related business operations.</p>
            <p>For information a business uploads or enters about its employees, customers, jobs, and operations, that business generally decides why and how the information is used. FieldPlx processes that information to provide the service to the business. For account, security, billing, product improvement, and website information that FieldPlx determines how to use, FieldPlx acts as the party responsible for that processing.</p>
          </section>

          <section class="legal-card" id="collect">
            <h2>2. Information we collect</h2>
            <p>FieldPlx collects information that is necessary to operate a multi-user field-service platform. The exact information collected depends on the features enabled by the business and how users use the service.</p>

            <div class="legal-table-wrap">
              <table class="legal-table">
                <thead><tr><th>Category</th><th>Examples</th><th>Why it is used</th></tr></thead>
                <tbody>
                  <tr><td>Business and tenant information</td><td>Business name, legal name, tenant code, business type, registration/tax details, addresses, country, currency, timezone, subscription plan, logos and invoice branding.</td><td>Workspace setup, billing, localization, account administration and business documents.</td></tr>
                  <tr><td>Employee and user accounts</td><td>Name, employee code, email, phone number, alternate phone, job title, branch, department, role, administrator status, bookable/field-worker status, profile image, labor rate and login/account status.</td><td>Authentication, workforce management, scheduling, role administration, job assignment and payroll/operational calculations where enabled.</td></tr>
                  <tr><td>Customer and contact information</td><td>Customer name, phone number, email, service address, billing address, alternate contacts, customer notes, account history and related communication details.</td><td>Customer management, scheduling, service delivery, quotations, invoices, follow-up and support.</td></tr>
                  <tr><td>Job and service information</td><td>Service requests, job descriptions, job status, scheduled dates/times, assigned workers, visits, work orders, assessments, checklists, technician notes, service history and completion information.</td><td>Plan, dispatch, perform, document and complete field-service work.</td></tr>
                  <tr><td>Location information</td><td>Precise GPS coordinates or approximate location collected from an authorized employee or field worker device when location-enabled features are used, including travel, arrival, attendance or field activity location.</td><td>Travel/arrival confirmation, attendance, field operations, routing, job verification and location-based workflow features.</td></tr>
                  <tr><td>Photos, images and uploaded files</td><td>Job-site photos, before/after images, equipment images, profile photos, customer/job attachments, logos, invoice logos and other uploaded documents.</td><td>Document service work, support job records, verify completion and maintain business records.</td></tr>
                  <tr><td>Quotations, invoices and payment records</td><td>Estimates, quotations, invoice numbers, line items, taxes, discounts, payment status, payment references, amounts, due dates and financial transaction records entered into FieldPlx.</td><td>Create business documents, track amounts due/paid and maintain accounting-related operational records.</td></tr>
                  <tr><td>Products, services and inventory</td><td>Product/service names, SKUs, descriptions, units, internal costs, customer prices, tax rates, stock levels, stock movements and service pricing.</td><td>Quotations, jobs, invoices, pricing and inventory management.</td></tr>
                  <tr><td>Device, log and security information</td><td>IP address, browser/device type, operating system, timestamps, session identifiers, login activity, audit/activity logs, error logs and security events.</td><td>Authentication, fraud prevention, troubleshooting, security, auditability and service reliability.</td></tr>
                  <tr><td>Support and communications</td><td>Messages sent to FieldPlx, demo/trial requests, support requests, feedback and communications with administrators or support personnel.</td><td>Respond to requests, provide support, administer trials and improve the service.</td></tr>
                </tbody>
              </table>
            </div>

            <h3>Information we do not intend to collect unnecessarily</h3>
            <p>Businesses should not upload sensitive personal information that is not necessary for legitimate field-service operations. FieldPlx is not designed to require sensitive categories such as medical records, government benefit information, or unrelated highly sensitive personal information unless a specific lawful business workflow requires it.</p>
          </section>

          <section class="legal-card" id="location">
            <h2>3. Location data</h2>
            <p>FieldPlx may collect precise or approximate device location when an authorized user uses location-enabled functionality, such as starting travel to a job, confirming arrival, recording attendance, or other field-operation actions.</p>
            <ul>
              <li>Location is used to support field-service workflows and is associated with the relevant employee/user, job, visit, attendance entry, or activity record.</li>
              <li>Location collection depends on device permissions and the features enabled by the business.</li>
              <li>If a feature requires continuous or background location, the application should request the applicable operating-system permission and clearly explain that use before collection begins.</li>
              <li>Users can control device-level location permissions through their device settings, although disabling location may prevent some FieldPlx features from working correctly.</li>
            </ul>
          </section>

          <section class="legal-card" id="use">
            <h2>4. How we use information</h2>
            <ul>
              <li>Provide, maintain, secure and operate FieldPlx.</li>
              <li>Create and administer business workspaces, users, roles, branches and subscriptions.</li>
              <li>Authenticate users and manage access to business data.</li>
              <li>Schedule, assign, dispatch and track field work.</li>
              <li>Maintain customer, service request, job, work order, visit, quotation, invoice, payment, product and inventory records.</li>
              <li>Support location-based field operations where enabled.</li>
              <li>Send transactional emails such as account creation, login credential or service-related notices.</li>
              <li>Provide customer support and investigate technical issues.</li>
              <li>Detect misuse, protect accounts, maintain audit trails and enforce security controls.</li>
              <li>Improve performance, usability and reliability based on aggregated usage and operational information.</li>
              <li>Comply with applicable legal, tax, accounting, security and regulatory obligations.</li>
            </ul>
          </section>

          <section class="legal-card" id="share">
            <h2>5. How we share information</h2>
            <p>FieldPlx does not sell customer, employee, job, or location information as a product. Information may be shared only as needed to operate the service or when required by law.</p>
            <ul>
              <li><strong>Within the customer's business:</strong> with authorized administrators, managers, employees and other users based on the business's account configuration and access controls.</li>
              <li><strong>Service providers:</strong> with infrastructure, hosting, email delivery, storage, security, analytics, payment, support or other vendors that help us operate FieldPlx, subject to appropriate contractual and security obligations.</li>
              <li><strong>Business-directed integrations:</strong> when a business connects FieldPlx with a third-party service or explicitly directs information to be sent to another provider.</li>
              <li><strong>Legal and safety reasons:</strong> when necessary to comply with law, court order or lawful government request, or to protect FieldPlx, users, customers or others from fraud, abuse or security threats.</li>
              <li><strong>Corporate transactions:</strong> in connection with a merger, acquisition, financing, reorganization, sale of assets or similar transaction, subject to applicable confidentiality obligations.</li>
            </ul>
          </section>

          <section class="legal-card" id="retention">
            <h2>6. Data retention</h2>
            <p>We retain information for as long as needed to provide FieldPlx, maintain business and audit records, comply with legal obligations, resolve disputes, enforce agreements and protect the service. A business may also retain data in FieldPlx according to its own operational or legal requirements.</p>
            <p>When an account or workspace is deleted or terminated, information may remain for a limited period in backups, logs, audit records or records we are legally required to retain. Where practical and permitted by law, information is deleted or de-identified when it is no longer needed.</p>
          </section>

          <section class="legal-card" id="security">
            <h2>7. Security</h2>
            <p>FieldPlx uses administrative, technical and organizational safeguards designed to protect information, including authenticated user accounts, role-based controls, password hashing, session protections, audit logging and encrypted transport where configured.</p>
            <p>No online service can guarantee absolute security. Businesses are responsible for keeping administrator accounts secure, assigning appropriate user access, using strong passwords and promptly removing access for users who no longer need it.</p>
          </section>

          <section class="legal-card" id="choices">
            <h2>8. Your choices and privacy rights</h2>
            <p>Depending on applicable law and your relationship with the FieldPlx customer, you may have rights to request access, correction, deletion, restriction, portability or objection to certain processing.</p>
            <p>If your information was entered into FieldPlx by your employer or another business, you should normally submit your request to that business first because it controls the relevant customer, employee and job records. FieldPlx will assist its business customers with valid requests where required.</p>
            <p>You can also control certain permissions, including device location and camera/photo access, through your device settings. Disabling a permission may limit related app features.</p>
          </section>

          <section class="legal-card" id="children">
            <h2>9. Children</h2>
            <p>FieldPlx is intended for businesses and authorized workforce users. It is not directed to children for personal or consumer use, and we do not knowingly seek to collect personal information from children through the service.</p>
          </section>

          <section class="legal-card" id="international">
            <h2>10. International processing</h2>
            <p>FieldPlx may use service providers or infrastructure located in countries other than the country where a user is located. Where required, we use appropriate safeguards for cross-border transfers of personal information.</p>
          </section>

          <section class="legal-card" id="changes">
            <h2>11. Changes to this Privacy Policy</h2>
            <p>We may update this Privacy Policy as FieldPlx changes, new features are introduced, or legal requirements evolve. We will update the “Last updated” date above and may provide additional notice for material changes where appropriate.</p>
          </section>

          <section class="legal-card" id="contact">
            <h2>12. Contact</h2>
            <p>For privacy questions, data-rights requests or concerns about FieldPlx, contact FieldPlx/CorePLX using the contact details published on <strong>fieldplx.com</strong>. If your request concerns information maintained by a FieldPlx customer, please identify the business or tenant involved so the request can be routed correctly.</p>
          </section>
        </div>
      </div>
    </div>
  </section>

  <section class="legal-cta">
    <div class="container-fluid site-container">
      <div class="legal-cta-box">
        <div>
          <h2>Questions about your data?</h2>
          <p>Contact FieldPlx through the contact information published on fieldplx.com.</p>
        </div>
        <a href="terms-of-service.php" class="btn btn-brand btn-lg">Read Terms of Service</a>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
