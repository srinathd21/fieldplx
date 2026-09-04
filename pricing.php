<?php
$pageTitle = 'Pricing - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
  .pricing-page {
    background: #fff;
    color: #071c2f;
  }

  .pricing-page .site-container {
    max-width: 1460px;
  }

  .pricing-hero {
    position: relative;
    overflow: hidden;
    min-height: 610px;
    display: flex;
    align-items: center;
    background:
      radial-gradient(circle at 79% 30%, rgba(48, 172, 29, .13), transparent 26%),
      linear-gradient(135deg, #041522 0%, #072139 58%, #0a2e46 100%);
    color: #fff;
  }

  .pricing-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: .22;
    background-image:
      linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: linear-gradient(90deg, transparent 0%, #000 45%, #000 100%);
    pointer-events: none;
  }

  .pricing-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .pricing-hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(430px, .95fr);
    gap: 52px;
    align-items: center;
  }

  .pricing-hero-copy {
    padding: 74px 0;
  }

  .pricing-kicker {
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

  .pricing-hero h1 {
    margin: 0 0 18px;
    max-width: 760px;
    font-size: clamp(2.8rem, 5vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
    color: #fff;
  }

  .pricing-hero h1 span {
    color: #3eb51f;
  }

  .pricing-hero p {
    max-width: 720px;
    margin: 0;
    color: #e1eaf0;
    font-size: 1.05rem;
    line-height: 1.72;
  }

  .pricing-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
  }

  .pricing-hero-actions .btn {
    min-width: 210px;
    font-weight: 800;
  }

  .pricing-promo {
    margin-top: 26px;
    max-width: 630px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 18px;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 12px;
    background: rgba(255,255,255,.07);
    backdrop-filter: blur(8px);
  }

  .pricing-promo-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #35ae19;
    color: #fff;
    font-size: 1.1rem;
  }

  .pricing-promo strong {
    display: block;
    color: #fff;
    font-size: .93rem;
    margin-bottom: 3px;
  }

  .pricing-promo span {
    display: block;
    color: #dbe6ec;
    font-size: .8rem;
    line-height: 1.45;
  }

  .pricing-visual {
    position: relative;
    padding: 26px;
  }

  .pricing-panel {
    position: relative;
    z-index: 2;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 20px;
    background: rgba(255,255,255,.97);
    color: #071c2f;
    box-shadow: 0 28px 70px rgba(0,0,0,.28);
  }

  .pricing-panel-head {
    padding: 23px 24px 18px;
    border-bottom: 1px solid #e9edef;
  }

  .pricing-panel-head small {
    display: block;
    margin-bottom: 5px;
    color: #2f9d17;
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .pricing-panel-head h3 {
    margin: 0;
    color: #071c2f;
    font-size: 1.45rem;
    font-weight: 900;
  }

  .pricing-panel-body {
    padding: 22px 24px 24px;
  }

  .pricing-fit-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 0;
    border-bottom: 1px solid #edf0f2;
  }

  .pricing-fit-item:last-child {
    border-bottom: 0;
  }

  .pricing-fit-icon {
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: #edf8ea;
    color: #2f9d17;
    font-weight: 900;
  }

  .pricing-fit-item strong {
    display: block;
    margin-bottom: 3px;
    color: #142634;
    font-size: .88rem;
  }

  .pricing-fit-item span {
    display: block;
    color: #6b7881;
    font-size: .76rem;
    line-height: 1.42;
  }

  .pricing-section {
    padding: 68px 0;
  }

  .pricing-section.alt {
    background: #f7f9fa;
  }

  .section-head {
    max-width: 820px;
    margin: 0 auto 36px;
    text-align: center;
  }

  .section-head .mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .section-head h2 {
    margin: 0 0 12px;
    color: #071c2f;
    font-size: clamp(2rem, 3vw, 3rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .section-head p {
    margin: 0;
    color: #66747f;
    font-size: .98rem;
    line-height: 1.65;
  }

  .pricing-process {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
  }

  .pricing-step {
    position: relative;
    min-height: 250px;
    padding: 24px 22px;
    border: 1px solid #e2e8ec;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(5,31,50,.05);
  }

  .pricing-step-no {
    position: absolute;
    right: 18px;
    top: 17px;
    color: #c8d0d5;
    font-size: .72rem;
    font-weight: 900;
    letter-spacing: .05em;
  }

  .pricing-step-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    border-radius: 11px;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.25rem;
    font-weight: 900;
  }

  .pricing-step h3 {
    margin: 0 0 9px;
    color: #071c2f;
    font-size: 1.02rem;
    font-weight: 900;
    line-height: 1.35;
  }

  .pricing-step p {
    margin: 0;
    color: #65737d;
    font-size: .84rem;
    line-height: 1.58;
  }

  .pricing-note-wrap {
    padding: 0 0 72px;
  }

  .pricing-note {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 28px;
    align-items: center;
    padding: 34px 36px;
    border: 1px solid #dfe8df;
    border-radius: 14px;
    background: linear-gradient(135deg, #f3faf1, #fbfdfb);
  }

  .pricing-note h2 {
    margin: 0 0 8px;
    color: #071c2f;
    font-size: clamp(1.8rem, 3vw, 2.5rem);
    font-weight: 900;
  }

  .pricing-note p {
    margin: 0;
    max-width: 850px;
    color: #62706f;
    line-height: 1.62;
    font-size: .94rem;
  }

  .pricing-note-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 14px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #d7e5d3;
    color: #2f9d17;
    font-size: .78rem;
    font-weight: 900;
    white-space: nowrap;
  }

  .pricing-cta {
    padding: 0 0 74px;
  }

  .pricing-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 26px;
    padding: 36px 38px;
    border-radius: 13px;
    background: linear-gradient(105deg, #071c2f, #0b3653);
    color: #fff;
  }

  .pricing-cta-box h2 {
    margin: 0 0 8px;
    color: #fff;
    font-size: 1.9rem;
    font-weight: 900;
  }

  .pricing-cta-box p {
    margin: 0;
    max-width: 760px;
    color: #d5dfe5;
    font-size: .91rem;
    line-height: 1.58;
  }

  .pricing-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media(max-width:1199.98px) {
    .pricing-hero-grid {
      grid-template-columns: 1fr 1fr;
      gap: 30px;
    }
    .pricing-process {
      grid-template-columns: repeat(2, minmax(0,1fr));
    }
  }

  @media(max-width:991.98px) {
    .pricing-hero-grid {
      grid-template-columns: 1fr;
    }
    .pricing-visual {
      max-width: 720px;
      padding: 0 0 54px;
    }
    .pricing-hero-copy {
      padding-bottom: 28px;
    }
  }

  @media(max-width:767.98px) {
    .pricing-hero {
      min-height: auto;
    }
    .pricing-hero-copy {
      padding: 54px 0 24px;
    }
    .pricing-hero h1 {
      font-size: 2.55rem;
    }
    .pricing-hero-actions {
      display: grid;
      grid-template-columns: 1fr;
    }
    .pricing-hero-actions .btn {
      width: 100%;
    }
    .pricing-visual {
      padding-bottom: 42px;
    }
    .pricing-process {
      grid-template-columns: 1fr;
    }
    .pricing-note {
      grid-template-columns: 1fr;
      padding: 24px 20px;
    }
    .pricing-note-badge {
      justify-self: start;
    }
    .pricing-cta-box {
      flex-direction: column;
      align-items: flex-start;
      padding: 28px 22px;
    }
    .pricing-cta-actions {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
    }
    .pricing-cta-actions .btn {
      width: 100%;
    }
  }
</style>

<main class="pricing-page">

  <section class="pricing-hero">
    <div class="container-fluid site-container">
      <div class="pricing-hero-grid">

        <div class="pricing-hero-copy">
          <div class="pricing-kicker">FieldPlx Pricing</div>
          <h1>Flexible Pricing for <span>Your Business</span></h1>
          <p>Every business has different operational needs. Book a personalized FieldPlx demo to explore the platform, discuss the features that are most important to your team, and review the pricing options available for your business.</p>

          <div class="pricing-hero-actions">
            <a href="index.php?modal=demo"
               class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
               data-open-modal="demoModal"
               data-modal-name="demo">Book a Demo</a>

            <a href="index.php?modal=trial"
               class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
               data-open-modal="trialModal"
               data-modal-name="trial">Start Free Trial</a>
          </div>

          <div class="pricing-promo">
            <div class="pricing-promo-icon">%</div>
            <div>
              <strong>Current promotions available</strong>
              <span>Ask our team about promotional pricing and introductory offers during your demo.</span>
            </div>
          </div>
        </div>

        <div class="pricing-visual">
          <div class="pricing-panel">
            <div class="pricing-panel-head">
              <small>Personalized for your operation</small>
              <h3>Find the Right Fit for Your Business</h3>
            </div>
            <div class="pricing-panel-body">
              <div class="pricing-fit-item">
                <div class="pricing-fit-icon">01</div>
                <div>
                  <strong>Your operational needs</strong>
                  <span>Tell us how your team currently manages jobs, customers, schedules, and payments.</span>
                </div>
              </div>
              <div class="pricing-fit-item">
                <div class="pricing-fit-icon">02</div>
                <div>
                  <strong>Your most important features</strong>
                  <span>Review the FieldPlx capabilities that matter most to your daily workflow.</span>
                </div>
              </div>
              <div class="pricing-fit-item">
                <div class="pricing-fit-icon">03</div>
                <div>
                  <strong>Your available pricing options</strong>
                  <span>Discuss the pricing approach and current promotions available for your organization.</span>
                </div>
              </div>
              <div class="pricing-fit-item">
                <div class="pricing-fit-icon">04</div>
                <div>
                  <strong>Your questions answered</strong>
                  <span>Get clear answers about the product, onboarding, and implementation.</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <section class="pricing-section">
    <div class="container-fluid site-container">
      <div class="section-head">
        <span class="mini">Book a Demo and Discuss Pricing</span>
        <h2>A Pricing Conversation Built Around Your Business</h2>
        <p>Schedule a conversation with the FieldPlx team to receive a guided product demonstration and discuss the right pricing approach for your organization.</p>
      </div>

      <div class="pricing-process">
        <article class="pricing-step">
          <span class="pricing-step-no">01</span>
          <div class="pricing-step-icon">✓</div>
          <h3>See How FieldPlx Supports Daily Operations</h3>
          <p>Walk through the platform and see how FieldPlx can help connect jobs, customers, employees, schedules, and payments.</p>
        </article>

        <article class="pricing-step">
          <span class="pricing-step-no">02</span>
          <div class="pricing-step-icon">⚙</div>
          <h3>Review the Features That Fit Your Needs</h3>
          <p>Focus the conversation on the capabilities that are most relevant to your team and service workflow.</p>
        </article>

        <article class="pricing-step">
          <span class="pricing-step-no">03</span>
          <div class="pricing-step-icon">$</div>
          <h3>Discuss Pricing Options and Promotions</h3>
          <p>Review available pricing options and ask about any current promotional or introductory offers.</p>
        </article>

        <article class="pricing-step">
          <span class="pricing-step-no">04</span>
          <div class="pricing-step-icon">?</div>
          <h3>Get Product and Implementation Answers</h3>
          <p>Use the demo to ask questions about setup, implementation, workflows, support, and the overall FieldPlx experience.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="pricing-note-wrap">
    <div class="container-fluid site-container">
      <div class="pricing-note">
        <div>
          <h2>Pricing Designed Around Real Business Needs</h2>
          <p>FieldPlx does not present one fixed approach for every organization. Your demo gives our team the opportunity to understand your operation and discuss the pricing options currently available for your business.</p>
        </div>
        <div class="pricing-note-badge">Current Promotions Available</div>
      </div>
    </div>
  </section>

  <section class="pricing-cta">
    <div class="container-fluid site-container">
      <div class="pricing-cta-box">
        <div>
          <h2>Book a Demo to Discuss Pricing</h2>
          <p>See FieldPlx in action, review the features that fit your business, and discuss available pricing options with our team.</p>
        </div>

        <div class="pricing-cta-actions">
          <a href="index.php?modal=demo"
             class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal"
             data-modal-name="demo">Book a Demo</a>

          <a href="contact.php" class="btn btn-outline-light btn-lg">Contact Us</a>
        </div>
      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>
