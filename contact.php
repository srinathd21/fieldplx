<?php
$pageTitle = 'Contact - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
  .contact-page {
    background: #fff;
    color: #071c2f;
  }

  .contact-page .site-container {
    max-width: 1460px;
  }

  .contact-hero {
    position: relative;
    overflow: hidden;
    min-height: 500px;
    display: flex;
    align-items: center;
    background: #06192a;
    color: #fff;
  }

  .contact-hero::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 60%;
    background:
      linear-gradient(90deg, rgba(6,25,42,1) 0%, rgba(6,25,42,.96) 18%, rgba(6,25,42,.62) 48%, rgba(6,25,42,.18) 76%, rgba(6,25,42,0) 100%),
      url('site-assets/contact/contact-hero.png') 68% center / cover no-repeat;
  }

  .contact-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
      radial-gradient(circle at 10% 20%, rgba(53,174,25,.18), transparent 25%),
      radial-gradient(circle at 82% 78%, rgba(38,121,176,.12), transparent 26%);
  }

  .contact-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .contact-hero-copy {
    max-width: 710px;
    padding: 72px 0;
  }

  .contact-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    padding: 7px 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 999px;
    background: rgba(255,255,255,.07);
    color: #fff;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-hero h1 {
    margin: 0 0 18px;
    max-width: 780px;
    font-size: clamp(2.8rem, 5vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
    color: #fff;
  }

  .contact-hero h1 span {
    color: #3eb51f;
  }

  .contact-hero p {
    max-width: 680px;
    margin: 0;
    color: #e2ebf1;
    font-size: 1.05rem;
    line-height: 1.72;
  }

  .contact-main {
    padding: 68px 0 76px;
    background: #f7f9fa;
  }

  .contact-layout {
    display: grid;
    grid-template-columns: .78fr 1.22fr;
    gap: 28px;
    align-items: stretch;
  }

  .contact-info-card,
  .contact-form-card {
    border: 1px solid #e2e7ea;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 12px 32px rgba(5,31,50,.055);
  }

  .contact-info-card {
    padding: 30px;
  }

  .contact-info-card .mini,
  .contact-form-card .mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .75rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-info-card h2,
  .contact-form-card h2 {
    margin: 0 0 12px;
    color: #071c2f;
    font-size: clamp(1.8rem, 3vw, 2.5rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .contact-info-card > p {
    margin: 0 0 26px;
    color: #66747f;
    font-size: .92rem;
    line-height: 1.62;
  }

  .contact-detail {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding: 16px 0;
    border-bottom: 1px solid #edf0f2;
  }

  .contact-detail:last-of-type {
    border-bottom: 0;
  }

  .contact-detail-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.05rem;
  }

  .contact-detail strong {
    display: block;
    margin-bottom: 3px;
    color: #142634;
    font-size: .85rem;
  }

  .contact-detail span {
    display: block;
    color: #6a7781;
    font-size: .8rem;
    line-height: 1.45;
    word-break: break-word;
  }

  .contact-placeholder-note {
    margin-top: 24px;
    padding: 14px 15px;
    border: 1px solid #eadfb8;
    border-radius: 10px;
    background: #fffaf0;
    color: #715d22;
    font-size: .78rem;
    line-height: 1.5;
  }

  .contact-product-note {
    margin-top: 18px;
    padding: 16px 17px;
    border-radius: 10px;
    background: #071c2f;
    color: #fff;
    font-size: .86rem;
    font-weight: 800;
  }

  .contact-form-card {
    padding: 30px;
  }

  .contact-form-card > p {
    margin: 0 0 24px;
    color: #66747f;
    font-size: .92rem;
    line-height: 1.6;
  }

  .contact-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .contact-field.full {
    grid-column: 1 / -1;
  }

  .contact-field label {
    display: block;
    margin-bottom: 6px;
    color: #263743;
    font-size: .78rem;
    font-weight: 800;
  }

  .contact-field .form-control,
  .contact-field .form-select {
    min-height: 46px;
    border-color: #dce2e6;
    border-radius: 8px;
    font-size: .88rem;
    box-shadow: none;
  }

  .contact-field .form-control:focus,
  .contact-field .form-select:focus {
    border-color: #58ad40;
    box-shadow: 0 0 0 3px rgba(53,174,25,.10);
  }

  .contact-field textarea.form-control {
    min-height: 130px;
    resize: vertical;
  }

  .contact-submit-wrap {
    margin-top: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  .contact-submit-note {
    margin: 0;
    max-width: 520px;
    color: #7a858d;
    font-size: .73rem;
    line-height: 1.45;
  }

  .contact-submit-wrap .btn {
    min-width: 180px;
    font-weight: 800;
  }

  .contact-reasons {
    padding: 70px 0 74px;
  }

  .contact-section-head {
    max-width: 820px;
    margin: 0 auto 34px;
    text-align: center;
  }

  .contact-section-head .mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-section-head h2 {
    margin: 0 0 12px;
    color: #071c2f;
    font-size: clamp(2rem, 3vw, 3rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .contact-section-head p {
    margin: 0;
    color: #66747f;
    font-size: .96rem;
    line-height: 1.65;
  }

  .reason-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }

  .reason-card {
    min-height: 170px;
    padding: 22px 20px;
    border: 1px solid #e2e8ec;
    border-radius: 13px;
    background: #fff;
    text-align: center;
    box-shadow: 0 9px 24px rgba(5,31,50,.04);
  }

  .reason-icon {
    width: 46px;
    height: 46px;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.1rem;
    font-weight: 900;
  }

  .reason-card h3 {
    margin: 0;
    color: #071c2f;
    font-size: .93rem;
    font-weight: 900;
    line-height: 1.35;
  }

  .contact-cta {
    padding: 0 0 76px;
  }

  .contact-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 28px;
    padding: 36px 38px;
    border-radius: 14px;
    background: linear-gradient(105deg, #071c2f, #0b3653);
    color: #fff;
  }

  .contact-cta-box h2 {
    margin: 0 0 8px;
    color: #fff;
    font-size: 1.9rem;
    font-weight: 900;
  }

  .contact-cta-box p {
    margin: 0;
    max-width: 760px;
    color: #d6e0e6;
    font-size: .91rem;
    line-height: 1.58;
  }

  .contact-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media (max-width: 1199.98px) {
    .contact-layout {
      grid-template-columns: .9fr 1.1fr;
    }
    .reason-grid {
      grid-template-columns: repeat(2, minmax(0,1fr));
    }
  }

  @media (max-width: 991.98px) {
    .contact-hero::before {
      width: 100%;
      background:
        linear-gradient(90deg, rgba(6,25,42,.98) 0%, rgba(6,25,42,.9) 42%, rgba(6,25,42,.58) 68%, rgba(6,25,42,.28) 100%),
        url('site-assets/contact/contact-hero.png') 68% center / cover no-repeat;
    }
    .contact-layout {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767.98px) {
    .contact-hero {
      min-height: auto;
    }
    .contact-hero-copy {
      padding: 54px 0 48px;
    }
    .contact-hero h1 {
      font-size: 2.55rem;
    }
    .contact-main {
      padding: 52px 0 58px;
    }
    .contact-info-card,
    .contact-form-card {
      padding: 22px 18px;
    }
    .contact-form-grid {
      grid-template-columns: 1fr;
    }
    .contact-field.full {
      grid-column: auto;
    }
    .contact-submit-wrap {
      flex-direction: column;
      align-items: stretch;
    }
    .contact-submit-wrap .btn {
      width: 100%;
    }
    .reason-grid {
      grid-template-columns: 1fr;
    }
    .contact-cta-box {
      flex-direction: column;
      align-items: flex-start;
      padding: 28px 22px;
    }
    .contact-cta-actions {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
    }
    .contact-cta-actions .btn {
      width: 100%;
    }
  }
</style>

<main class="contact-page">

  <section class="contact-hero">
    <div class="container-fluid site-container">
      <div class="contact-hero-copy">
        <div class="contact-kicker">Contact FieldPlx</div>
        <h1>Let's Talk About <span>Your Business</span></h1>
        <p>Have questions about FieldPlx, need product assistance, or want to schedule a demonstration? Our team is ready to help.</p>
      </div>
    </div>
  </section>

  <section class="contact-main">
    <div class="container-fluid site-container">
      <div class="contact-layout">

        <aside class="contact-info-card">
          <span class="mini">Contact Details</span>
          <h2>We're Here to Help</h2>
          <p>Reach out to the FieldPlx team for product questions, demos, support, partnerships, or general enquiries.</p>

          <div class="contact-detail">
            <div class="contact-detail-icon">✉</div>
            <div>
              <strong>Email</strong>
              <span>support@fieldplx.com</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">↗</div>
            <div>
              <strong>Sales</strong>
              <span>sales@fieldplx.com</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">☎</div>
            <div>
              <strong>Phone</strong>
              <span>[Business phone number]</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">◷</div>
            <div>
              <strong>Business Hours</strong>
              <span>[Days, hours, and time zone]</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">●</div>
            <div>
              <strong>Address</strong>
              <span>[CorePLX business address]</span>
            </div>
          </div>

          <div class="contact-placeholder-note">
            Before publishing, replace the bracketed contact placeholders above with the official CorePLX contact information.
          </div>

          <div class="contact-product-note">FieldPlx - a product of CorePLX</div>
        </aside>

        <section class="contact-form-card">
          <span class="mini">Send Us a Message</span>
          <h2>Tell Us How We Can Help</h2>
          <p>Complete the form below and the FieldPlx team can follow up about your request.</p>

          <form id="fieldplxContactForm" method="post" action="#">
            <div class="contact-form-grid">

              <div class="contact-field">
                <label for="first_name">First Name *</label>
                <input class="form-control" type="text" id="first_name" name="first_name" maxlength="100" required>
              </div>

              <div class="contact-field">
                <label for="last_name">Last Name *</label>
                <input class="form-control" type="text" id="last_name" name="last_name" maxlength="100" required>
              </div>

              <div class="contact-field">
                <label for="business_name">Business Name</label>
                <input class="form-control" type="text" id="business_name" name="business_name" maxlength="190">
              </div>

              <div class="contact-field">
                <label for="email">Email Address *</label>
                <input class="form-control" type="email" id="email" name="email" maxlength="190" required>
              </div>

              <div class="contact-field">
                <label for="phone">Phone Number</label>
                <input class="form-control" type="tel" id="phone" name="phone" maxlength="50">
              </div>

              <div class="contact-field">
                <label for="industry">Industry</label>
                <input class="form-control" type="text" id="industry" name="industry" maxlength="120"
                       placeholder="HVAC, Plumbing, Electrical...">
              </div>

              <div class="contact-field">
                <label for="employees">Number of Employees</label>
                <select class="form-select" id="employees" name="employees">
                  <option value="">Select team size</option>
                  <option value="1">1</option>
                  <option value="2-5">2-5</option>
                  <option value="6-10">6-10</option>
                  <option value="11-25">11-25</option>
                  <option value="26-50">26-50</option>
                  <option value="51-100">51-100</option>
                  <option value="100+">100+</option>
                </select>
              </div>

              <div class="contact-field">
                <label for="reason">Reason for Contacting Us *</label>
                <select class="form-select" id="reason" name="reason" required>
                  <option value="">Select a reason</option>
                  <option value="book_demo">Book a demonstration</option>
                  <option value="start_trial">Start a free trial</option>
                  <option value="product_pricing">Product or pricing question</option>
                  <option value="technical_support">Technical support</option>
                  <option value="partnership">Partnership opportunity</option>
                  <option value="media">Media inquiry</option>
                  <option value="general">General question</option>
                </select>
              </div>

              <div class="contact-field full">
                <label for="message">Message *</label>
                <textarea class="form-control" id="message" name="message" required
                          placeholder="Tell us how we can help..."></textarea>
              </div>

            </div>

            <div class="contact-submit-wrap">
              <p class="contact-submit-note">
                By submitting this form, you are requesting that the FieldPlx team contact you regarding your enquiry.
              </p>
              <button type="submit" class="btn btn-brand btn-lg">Send Message</button>
            </div>
          </form>
        </section>

      </div>
    </div>
  </section>

  <section class="contact-reasons">
    <div class="container-fluid site-container">

      <div class="contact-section-head">
        <span class="mini">How Can We Help?</span>
        <h2>Choose the Right Conversation</h2>
        <p>Whether you're evaluating FieldPlx, need help with the product, or want to discuss an opportunity, our team is ready to connect.</p>
      </div>

      <div class="reason-grid">
        <article class="reason-card">
          <div class="reason-icon">▶</div>
          <h3>Book a Demonstration</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">✓</div>
          <h3>Start a Free Trial</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">$</div>
          <h3>Product or Pricing Question</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">⚙</div>
          <h3>Technical Support</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">◇</div>
          <h3>Partnership Opportunity</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">◎</div>
          <h3>Media Inquiry</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">?</div>
          <h3>General Question</h3>
        </article>
      </div>

    </div>
  </section>

  <section class="contact-cta">
    <div class="container-fluid site-container">
      <div class="contact-cta-box">
        <div>
          <h2>Want to See FieldPlx in Action?</h2>
          <p>Book a personalized demonstration or start your free trial to explore how FieldPlx can support your field-service operation.</p>
        </div>

        <div class="contact-cta-actions">
          <a href="index.php?modal=demo"
             class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal"
             data-modal-name="demo">Book a Demo</a>

          <a href="index.php?modal=trial"
             class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
             data-open-modal="trialModal"
             data-modal-name="trial">Start Free Trial</a>
        </div>
      </div>
    </div>
  </section>

</main>

<script>
(function () {
  var form = document.getElementById('fieldplxContactForm');
  if (!form) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    var reason = document.getElementById('reason').value;

    if (reason === 'book_demo') {
      window.location.href = 'index.php?modal=demo';
      return;
    }

    if (reason === 'start_trial') {
      window.location.href = 'index.php?modal=trial';
      return;
    }

    /*
      The complete contact form contains fields that are broader than the
      current homepage website_leads structure. Connect this form to the
      contact backend once those additional fields are added server-side.
    */
    alert('Thank you. Your contact form is ready for backend integration.');
  });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
