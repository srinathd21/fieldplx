<?php
$pageTitle = 'Terms of Service - FieldPlx';
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
      <h1>Terms of <span>Service</span></h1>
      <p>These Terms of Service govern access to and use of FieldPlx, including the website, web application, mobile application, business workspaces, subscriptions, and related services provided by CorePLX.</p>
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
          <a href="#agreement">1. Agreement</a>
          <a href="#service">2. The FieldPlx service</a>
          <a href="#accounts">3. Accounts and administrators</a>
          <a href="#customer-data">4. Customer data</a>
          <a href="#acceptable-use">5. Acceptable use</a>
          <a href="#subscriptions">6. Trials, subscriptions and billing</a>
          <a href="#mobile">7. Mobile and location features</a>
          <a href="#third-party">8. Third-party services</a>
          <a href="#ip">9. Intellectual property</a>
          <a href="#confidentiality">10. Confidentiality and security</a>
          <a href="#availability">11. Availability and changes</a>
          <a href="#termination">12. Suspension and termination</a>
          <a href="#disclaimers">13. Disclaimers</a>
          <a href="#liability">14. Limitation of liability</a>
          <a href="#indemnity">15. Indemnity</a>
          <a href="#law">16. Governing law</a>
          <a href="#changes">17. Changes to these Terms</a>
          <a href="#contact">18. Contact</a>
        </aside>

        <div class="legal-content">
          <section class="legal-card legal-highlight" id="agreement">
            <h2>1. Agreement to these Terms</h2>
            <p>By creating a FieldPlx workspace, accepting an invitation, purchasing or starting a trial, or otherwise accessing FieldPlx, you agree to these Terms of Service and the FieldPlx Privacy Policy.</p>
            <p>If you use FieldPlx on behalf of a company or other organization, you represent that you have authority to bind that organization to these Terms. In that case, “you” and “Customer” refer to that organization.</p>
          </section>

          <section class="legal-card" id="service">
            <h2>2. The FieldPlx service</h2>
            <p>FieldPlx is a business field-service operations platform. Depending on the selected plan and enabled modules, it may include customer management, employee/user administration, scheduling, service requests, jobs, visits, work orders, quotations, invoices, payments, products, inventory, location-enabled field workflows, photos and file attachments, reports, notifications and related administration tools.</p>
            <p>Features, limits, modules and availability may differ by subscription plan, workspace configuration, country, platform or device.</p>
          </section>

          <section class="legal-card" id="accounts">
            <h2>3. Accounts, tenant administrators and authorized users</h2>
            <ul>
              <li>Each business workspace may have one or more administrator accounts responsible for configuring the workspace and managing users.</li>
              <li>Customers are responsible for keeping account information accurate and for maintaining the confidentiality of usernames, tenant codes, passwords and authentication credentials.</li>
              <li>Customers are responsible for all activity performed by their authorized users unless caused by a security failure attributable to FieldPlx.</li>
              <li>Administrators must promptly disable or remove access for users who should no longer access the workspace.</li>
              <li>You must notify FieldPlx promptly if you believe an account has been compromised or used without authorization.</li>
            </ul>
          </section>

          <section class="legal-card" id="customer-data">
            <h2>4. Customer data and business responsibility</h2>
            <p>“Customer Data” means information, files, photos, records and other content submitted to FieldPlx by or for a Customer, including employee/user information, customer/contact information, location records, job details, service notes, quotations, invoices, payment records, products, inventory and attachments.</p>
            <p>The Customer retains its rights in Customer Data. The Customer grants FieldPlx a limited right to host, process, transmit, back up and otherwise use Customer Data only as necessary to provide, secure, support and improve the service and to comply with law.</p>
            <p>The Customer is responsible for ensuring it has a lawful basis and any required notices, consents or permissions to collect and use Customer Data, including employee location information, customer contact details and job-site photos.</p>
          </section>

          <section class="legal-card" id="acceptable-use">
            <h2>5. Acceptable use</h2>
            <p>You may not use FieldPlx to:</p>
            <ul>
              <li>violate applicable law or the rights of another person;</li>
              <li>upload malware, malicious code or content designed to disrupt systems;</li>
              <li>attempt to gain unauthorized access to another workspace, account or system;</li>
              <li>circumvent security, subscription, usage or access controls;</li>
              <li>scrape, reverse engineer or copy the service except where applicable law expressly permits it;</li>
              <li>use FieldPlx to harass, stalk, unlawfully monitor or otherwise misuse employee or customer location information;</li>
              <li>upload content you do not have the right to use;</li>
              <li>use the service in a manner that materially interferes with the stability, security or performance of FieldPlx.</li>
            </ul>
          </section>

          <section class="legal-card" id="subscriptions">
            <h2>6. Trials, subscriptions, plans and billing</h2>
            <p>FieldPlx may offer free trials, paid plans, promotional periods or plan-specific limits. The plan selected for a workspace determines available modules, user limits, branch limits, storage limits, pricing and other service entitlements.</p>
            <ul>
              <li>Trial terms, length and available features may change from time to time.</li>
              <li>Paid subscriptions are billed according to the pricing and billing cycle presented at purchase or agreed with the Customer.</li>
              <li>Taxes may apply depending on the Customer's location and applicable law.</li>
              <li>Unless otherwise stated, fees already paid are non-refundable except where required by law or expressly agreed in writing.</li>
              <li>If a subscription expires, is cancelled or is not paid when due, FieldPlx may restrict or suspend access to paid features.</li>
            </ul>
          </section>

          <section class="legal-card" id="mobile">
            <h2>7. Mobile app, camera and location-enabled features</h2>
            <p>Some FieldPlx features may require device permissions such as location, camera, photos or file access. Users must grant the relevant permission before the feature can operate.</p>
            <p>Customers are responsible for enabling location tracking only for legitimate business purposes and for providing any legally required employee notice or consent. FieldPlx must not be used for covert or unlawful employee monitoring.</p>
          </section>

          <section class="legal-card" id="third-party">
            <h2>8. Third-party services and integrations</h2>
            <p>FieldPlx may rely on or connect with third-party services such as hosting providers, email providers, mapping/location services, payment providers, analytics tools or business integrations. Your use of a third-party service may also be governed by that provider's own terms and privacy policy.</p>
            <p>FieldPlx is not responsible for third-party services that are outside our control, although we select and manage service providers with reasonable care.</p>
          </section>

          <section class="legal-card" id="ip">
            <h2>9. Intellectual property</h2>
            <p>FieldPlx, including its software, interface, design, branding, workflows, documentation and related materials, is owned by CorePLX or its licensors and is protected by applicable intellectual-property laws.</p>
            <p>Subject to these Terms and payment of applicable fees, FieldPlx grants the Customer a limited, non-exclusive, non-transferable right to use the service during the applicable subscription or trial period for its internal business operations.</p>
          </section>

          <section class="legal-card" id="confidentiality">
            <h2>10. Confidentiality and security</h2>
            <p>Each party may receive confidential business information from the other. Each party agrees to use reasonable care to protect confidential information and to use it only as necessary for the relationship unless disclosure is required by law.</p>
            <p>Customers are responsible for configuring permissions appropriately and for not sharing credentials between users. FieldPlx uses reasonable technical and organizational measures designed to protect the service and Customer Data.</p>
          </section>

          <section class="legal-card" id="availability">
            <h2>11. Availability, maintenance and service changes</h2>
            <p>We aim to keep FieldPlx available and reliable, but uninterrupted service cannot be guaranteed. Maintenance, security work, updates, internet outages, third-party failures or events beyond our reasonable control may affect availability.</p>
            <p>We may update, improve, replace or discontinue features. Where a material change significantly affects paid use, we will provide notice when reasonably practical.</p>
          </section>

          <section class="legal-card" id="termination">
            <h2>12. Suspension and termination</h2>
            <p>We may suspend or terminate access if a Customer materially breaches these Terms, fails to pay applicable fees, creates a security risk, uses the service unlawfully or if suspension is required by law.</p>
            <p>A Customer may stop using FieldPlx or cancel according to the applicable subscription arrangement. After termination, access may end and Customer Data may be deleted after an appropriate retention period, subject to backups and legal retention requirements.</p>
          </section>

          <section class="legal-card" id="disclaimers">
            <h2>13. Disclaimers</h2>
            <p>FieldPlx is provided on an “as available” basis. To the maximum extent permitted by law, we disclaim implied warranties that are not expressly stated, including implied warranties of merchantability, fitness for a particular purpose and non-infringement.</p>
            <p>FieldPlx is a business operations platform and does not provide legal, tax, accounting, employment, safety or regulatory advice. Customers remain responsible for their own business decisions and compliance obligations.</p>
          </section>

          <section class="legal-card" id="liability">
            <h2>14. Limitation of liability</h2>
            <p>To the maximum extent permitted by applicable law, FieldPlx/CorePLX will not be liable for indirect, incidental, special, consequential, exemplary or punitive damages, or for loss of profits, revenue, goodwill or data arising from use of the service.</p>
            <p>To the maximum extent permitted by law, our aggregate liability arising out of or relating to FieldPlx will not exceed the fees paid by the Customer for the service during the twelve months immediately before the event giving rise to the claim, unless a different limit is required by law or agreed in writing.</p>
          </section>

          <section class="legal-card" id="indemnity">
            <h2>15. Indemnity</h2>
            <p>To the extent permitted by law, the Customer agrees to defend, indemnify and hold FieldPlx/CorePLX harmless from third-party claims arising from the Customer's unlawful use of FieldPlx, Customer Data, violation of another person's rights or breach of these Terms.</p>
          </section>

          <section class="legal-card" id="law">
            <h2>16. Governing law and disputes</h2>
            <p>These Terms are governed by the law stated in the Customer's applicable order form, subscription agreement or other written agreement with FieldPlx/CorePLX. If no separate governing-law clause has been agreed, the applicable governing law and venue should be confirmed by CorePLX before publication of these Terms.</p>
          </section>

          <section class="legal-card" id="changes">
            <h2>17. Changes to these Terms</h2>
            <p>We may update these Terms to reflect changes to FieldPlx, business practices or legal requirements. The “Last updated” date will be revised when changes are published. Continued use after the effective date of updated Terms constitutes acceptance where permitted by law.</p>
          </section>

          <section class="legal-card" id="contact">
            <h2>18. Contact</h2>
            <p>Questions about these Terms can be directed to FieldPlx/CorePLX using the contact details published on <strong>fieldplx.com</strong>.</p>
          </section>
        </div>
      </div>
    </div>
  </section>

  <section class="legal-cta">
    <div class="container-fluid site-container">
      <div class="legal-cta-box">
        <div>
          <h2>Want to understand how FieldPlx handles data?</h2>
          <p>Read the Privacy Policy for details about account, customer, job, photo and location information.</p>
        </div>
        <a href="privacy-policy.php" class="btn btn-brand btn-lg">Read Privacy Policy</a>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
