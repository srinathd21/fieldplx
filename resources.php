<?php
$pageTitle = 'Resources - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
  .resources-page {
    background: #fff;
    color: #071c2f;
  }

  .resources-page .site-container {
    max-width: 1460px;
  }

  .resources-hero {
    position: relative;
    overflow: hidden;
    min-height: 620px;
    display: flex;
    align-items: center;
    background: #06192a;
    color: #fff;
  }

  .resources-hero::before {
    content: '';
    position: absolute;
    inset: 0 0 0 auto;
    width: 62%;
    background:
      linear-gradient(90deg, rgba(6,25,42,1) 0%, rgba(6,25,42,.94) 22%, rgba(6,25,42,.48) 52%, rgba(6,25,42,.10) 76%, rgba(6,25,42,0) 100%),
      url('site-assets/resources/resources-hero.png') 65% center / cover no-repeat;
  }

  .resources-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(circle at 12% 18%, rgba(57, 181, 31, .18), transparent 26%),
      radial-gradient(circle at 80% 74%, rgba(34, 111, 166, .14), transparent 26%);
    pointer-events: none;
  }

  .resources-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .resources-hero-copy {
    max-width: 690px;
    padding: 72px 0;
  }

  .resources-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    padding: 7px 12px;
    border: 1px solid rgba(255,255,255,.22);
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    color: #fff;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .resources-hero h1 {
    margin: 0 0 18px;
    font-size: clamp(2.7rem, 5vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
    color: #fff;
  }

  .resources-hero h1 span {
    color: #3eb51f;
  }

  .resources-hero p {
    margin: 0;
    max-width: 650px;
    color: #e2ebf1;
    font-size: 1.05rem;
    line-height: 1.7;
  }

  .resources-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
  }

  .resources-hero-actions .btn {
    min-width: 175px;
    font-weight: 800;
  }

  .resources-hero-points {
    margin-top: 28px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    max-width: 640px;
  }

  .resources-point {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #fff;
    font-size: .82rem;
    font-weight: 700;
  }

  .resources-point i {
    color: #3eb51f;
    font-size: 1rem;
  }

  .resources-main {
    padding: 66px 0 74px;
    background: #fff;
  }

  .section-head {
    max-width: 820px;
    margin: 0 auto 34px;
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

  .resource-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
  }

  .resource-card {
    overflow: hidden;
    border: 1px solid #e4e9ed;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(5, 31, 50, .05);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
  }

  .resource-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 38px rgba(5, 31, 50, .09);
    border-color: #cfd8dd;
  }

  .resource-image {
    height: 190px;
    background: #eef4f7;
    overflow: hidden;
  }

  .resource-image img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    transition: transform .28s ease;
  }

  .resource-card:hover .resource-image img {
    transform: scale(1.025);
  }

  .resource-card-body {
    padding: 20px 20px 22px;
  }

  .resource-icon {
    width: 42px;
    height: 42px;
    margin-bottom: 14px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.1rem;
    font-weight: 900;
  }

  .resource-card h3 {
    margin: 0 0 8px;
    color: #071c2f;
    font-size: 1.04rem;
    font-weight: 900;
    line-height: 1.3;
  }

  .resource-card p {
    margin: 0;
    color: #66747f;
    font-size: .84rem;
    line-height: 1.58;
  }

  .resource-card .resource-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 15px;
    color: #2f9d17;
    text-decoration: none;
    font-size: .8rem;
    font-weight: 800;
  }

  .resources-band {
    padding: 0 0 72px;
  }

  .resources-band-box {
    display: grid;
    grid-template-columns: 1.05fr .95fr;
    gap: 34px;
    align-items: center;
    padding: 34px;
    border: 1px solid #e0e8df;
    border-radius: 14px;
    background: linear-gradient(135deg, #f5fbf3, #fbfdfb);
  }

  .resources-band-box h2 {
    margin: 0 0 10px;
    color: #071c2f;
    font-size: clamp(1.8rem, 3vw, 2.6rem);
    font-weight: 900;
  }

  .resources-band-box p {
    margin: 0;
    color: #65736d;
    line-height: 1.65;
    font-size: .94rem;
  }

  .resources-checklist {
    display: grid;
    gap: 12px;
  }

  .resources-checkitem {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 14px;
    border: 1px solid #dce8dc;
    border-radius: 9px;
    background: #fff;
    color: #263a43;
    font-size: .86rem;
    font-weight: 800;
  }

  .resources-checkitem i {
    color: #2f9d17;
  }

  .resources-cta {
    padding: 0 0 72px;
  }

  .resources-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 26px;
    padding: 34px 38px;
    border-radius: 12px;
    background: #071c2f;
    color: #fff;
  }

  .resources-cta-box h2 {
    margin: 0 0 7px;
    color: #fff;
    font-size: 1.8rem;
    font-weight: 900;
  }

  .resources-cta-box p {
    margin: 0;
    max-width: 700px;
    color: #d5dfe5;
    font-size: .9rem;
    line-height: 1.55;
  }

  .resources-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media (max-width: 1199.98px) {
    .resource-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .resources-hero::before {
      width: 66%;
    }
  }

  @media (max-width: 991.98px) {
    .resources-hero {
      min-height: 560px;
    }
    .resources-hero::before {
      width: 100%;
      background:
        linear-gradient(90deg, rgba(6,25,42,.98) 0%, rgba(6,25,42,.88) 42%, rgba(6,25,42,.52) 68%, rgba(6,25,42,.28) 100%),
        url('site-assets/resources/resources-hero.png') 68% center / cover no-repeat;
    }
    .resources-hero-copy {
      max-width: 640px;
    }
    .resources-band-box {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767.98px) {
    .resources-hero {
      min-height: auto;
    }
    .resources-hero-copy {
      padding: 54px 0 48px;
    }
    .resources-hero h1 {
      font-size: 2.5rem;
    }
    .resources-hero-actions {
      display: grid;
      grid-template-columns: 1fr;
    }
    .resources-hero-actions .btn {
      width: 100%;
    }
    .resources-hero-points {
      grid-template-columns: 1fr;
    }
    .resource-grid {
      grid-template-columns: 1fr;
    }
    .resource-image {
      height: 210px;
    }
    .resources-band-box {
      padding: 24px 18px;
    }
    .resources-cta-box {
      flex-direction: column;
      align-items: flex-start;
      padding: 28px 22px;
    }
    .resources-cta-actions {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
    }
    .resources-cta-actions .btn {
      width: 100%;
    }
  }
</style>

<main class="resources-page">
  <section class="resources-hero">
    <div class="container-fluid site-container">
      <div class="resources-hero-copy">
        <div class="resources-kicker">FieldPlx Resources</div>
        <h1>Resources to Help Your <span>Business Succeed</span></h1>
        <p>Explore practical information to help your team use FieldPlx effectively, improve daily operations, and grow your business.</p>

        <div class="resources-hero-points">
          <div class="resources-point"><i class="bi bi-check-circle-fill"></i><span>Easy setup guidance</span></div>
          <div class="resources-point"><i class="bi bi-check-circle-fill"></i><span>Practical video tutorials</span></div>
          <div class="resources-point"><i class="bi bi-check-circle-fill"></i><span>Helpful product updates</span></div>
        </div>

        <div class="resources-hero-actions">
          <a href="index.php?modal=trial" class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
             data-open-modal="trialModal" data-modal-name="trial">Start Your Free Trial</a>
          <a href="index.php?modal=demo" class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a>
        </div>
      </div>
    </div>
  </section>

  <section class="resources-main">
    <div class="container-fluid site-container">
      <div class="section-head">
        <span class="mini">Resource Library</span>
        <h2>Everything You Need to Get More from FieldPlx</h2>
        <p>Use these resources to get started faster, learn key workflows, find answers, and stay informed about product improvements.</p>
      </div>

      <div class="resource-grid">
        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-01.png" alt="FieldPlx Help Center"></div>
          <div class="resource-card-body">
            <div class="resource-icon">?</div>
            <h3>Help Center</h3>
            <p>Step-by-step instructions for setting up and using FieldPlx.</p>
            <a href="#" class="resource-link">Explore Help Center <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-02.png" alt="FieldPlx Getting Started Guide"></div>
          <div class="resource-card-body">
            <div class="resource-icon">✓</div>
            <h3>Getting Started Guide</h3>
            <p>Learn how to configure your account, add employees and customers, and create your first job.</p>
            <a href="#" class="resource-link">Start the Guide <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-03.png" alt="FieldPlx Video Tutorials"></div>
          <div class="resource-card-body">
            <div class="resource-icon">▶</div>
            <h3>Video Tutorials</h3>
            <p>Short demonstrations of key FieldPlx features and workflows.</p>
            <a href="#" class="resource-link">Watch Tutorials <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-04.png" alt="Frequently Asked Questions"></div>
          <div class="resource-card-body">
            <div class="resource-icon">FAQ</div>
            <h3>Frequently Asked Questions</h3>
            <p>Answers to common questions about accounts, features, billing, security, and support.</p>
            <a href="#" class="resource-link">View FAQs <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-05.png" alt="FieldPlx Blog and Business Tips"></div>
          <div class="resource-card-body">
            <div class="resource-icon">✎</div>
            <h3>Blog and Business Tips</h3>
            <p>Articles about field-service management, customer service, productivity, and business growth.</p>
            <a href="#" class="resource-link">Read Articles <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-06.png" alt="FieldPlx Product Updates"></div>
          <div class="resource-card-body">
            <div class="resource-icon">↗</div>
            <h3>Product Updates</h3>
            <p>Information about new features, improvements, and upcoming releases.</p>
            <a href="#" class="resource-link">See Updates <span>→</span></a>
          </div>
        </article>

        <article class="resource-card">
          <div class="resource-image"><img src="site-assets/resources/resource-07.png" alt="FieldPlx Contact Support"></div>
          <div class="resource-card-body">
            <div class="resource-icon">☎</div>
            <h3>Contact Support</h3>
            <p>Get assistance from the FieldPlx support team.</p>
            <a href="contact.php" class="resource-link">Contact Support <span>→</span></a>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="resources-band">
    <div class="container-fluid site-container">
      <div class="resources-band-box">
        <div>
          <div class="resources-kicker" style="color:#2f9d17;background:#eef8eb;border-color:#dce8dc;">Learn. Improve. Grow.</div>
          <h2>Practical Help for Every Stage of Your FieldPlx Journey</h2>
          <p>Whether you're setting up your account, training your team, learning a new workflow, or looking for support, FieldPlx resources are designed to help you move forward with confidence.</p>
        </div>
        <div class="resources-checklist">
          <div class="resources-checkitem"><i class="bi bi-check-circle-fill"></i> Set up your account with confidence</div>
          <div class="resources-checkitem"><i class="bi bi-check-circle-fill"></i> Train employees on essential workflows</div>
          <div class="resources-checkitem"><i class="bi bi-check-circle-fill"></i> Find answers to common questions</div>
          <div class="resources-checkitem"><i class="bi bi-check-circle-fill"></i> Stay informed about product improvements</div>
        </div>
      </div>
    </div>
  </section>

  <section class="resources-cta">
    <div class="container-fluid site-container">
      <div class="resources-cta-box">
        <div>
          <h2>Need Help from the FieldPlx Team?</h2>
          <p>Our support team is here to help with product questions, setup guidance, and getting the most from FieldPlx.</p>
        </div>
        <div class="resources-cta-actions">
          <a href="contact.php" class="btn btn-brand btn-lg">Contact Support</a>
          <a href="index.php?modal=demo" class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a>
        </div>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
