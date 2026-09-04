<?php
$pageTitle = 'About Us - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
  .about-page {
    background: #fff;
    color: #071c2f;
  }

  .about-page .site-container {
    max-width: 1460px;
  }

  .about-hero {
    position: relative;
    overflow: hidden;
    min-height: 620px;
    display: flex;
    align-items: center;
    background: #06192a;
    color: #fff;
  }

  .about-hero::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 63%;
    background:
      linear-gradient(90deg, rgba(6,25,42,1) 0%, rgba(6,25,42,.96) 18%, rgba(6,25,42,.62) 46%, rgba(6,25,42,.18) 74%, rgba(6,25,42,0) 100%),
      url('site-assets/about/about-hero.png') 68% center / cover no-repeat;
  }

  .about-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
      radial-gradient(circle at 10% 20%, rgba(53,174,25,.18), transparent 25%),
      radial-gradient(circle at 82% 78%, rgba(38,121,176,.12), transparent 26%);
  }

  .about-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .about-hero-copy {
    max-width: 700px;
    padding: 74px 0;
  }

  .about-kicker {
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

  .about-hero h1 {
    margin: 0 0 18px;
    max-width: 760px;
    font-size: clamp(2.8rem, 5vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
    color: #fff;
  }

  .about-hero h1 span {
    color: #3eb51f;
  }

  .about-hero p {
    max-width: 710px;
    margin: 0;
    color: #e2ebf1;
    font-size: 1.05rem;
    line-height: 1.72;
  }

  .about-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 28px;
  }

  .about-hero-actions .btn {
    min-width: 185px;
    font-weight: 800;
  }

  .about-hero-points {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 12px;
    margin-top: 28px;
    max-width: 660px;
  }

  .about-point {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #fff;
    font-size: .82rem;
    font-weight: 700;
  }

  .about-point i {
    color: #3eb51f;
  }

  .about-story {
    padding: 70px 0;
  }

  .about-story-grid {
    display: grid;
    grid-template-columns: .95fr 1.05fr;
    gap: 48px;
    align-items: center;
  }

  .about-story-image {
    overflow: hidden;
    border-radius: 16px;
    border: 1px solid #e3e8eb;
    box-shadow: 0 18px 42px rgba(5,31,50,.08);
  }

  .about-story-image img {
    display: block;
    width: 100%;
    height: 460px;
    object-fit: cover;
    object-position: center;
  }

  .section-mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .about-story h2,
  .about-mission h2,
  .about-vision h2,
  .about-values h2 {
    margin: 0 0 14px;
    color: #071c2f;
    font-size: clamp(2rem, 3vw, 3rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .about-story p {
    margin: 0 0 16px;
    color: #65737d;
    font-size: .96rem;
    line-height: 1.72;
  }

  .about-brand-note {
    margin-top: 24px;
    padding: 18px 20px;
    border: 1px solid #dfe8df;
    border-radius: 12px;
    background: #f4faf2;
  }

  .about-brand-note strong {
    display: block;
    margin-bottom: 4px;
    color: #071c2f;
    font-size: .95rem;
  }

  .about-brand-note span {
    color: #60706a;
    font-size: .84rem;
    line-height: 1.5;
  }

  .about-purpose {
    padding: 68px 0;
    background: #f7f9fa;
  }

  .purpose-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 20px;
  }

  .purpose-card {
    position: relative;
    min-height: 280px;
    padding: 30px;
    border: 1px solid #e2e7ea;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 10px 26px rgba(5,31,50,.05);
  }

  .purpose-card::after {
    content: '';
    position: absolute;
    right: 22px;
    top: 22px;
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: #eef8eb;
  }

  .purpose-icon {
    position: relative;
    z-index: 2;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    border-radius: 11px;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.25rem;
    font-weight: 900;
  }

  .purpose-card h3 {
    margin: 0 0 10px;
    color: #071c2f;
    font-size: 1.45rem;
    font-weight: 900;
  }

  .purpose-card p {
    margin: 0;
    color: #65737d;
    font-size: .91rem;
    line-height: 1.65;
  }

  .about-values {
    padding: 70px 0 76px;
  }

  .values-head {
    max-width: 800px;
    margin: 0 auto 34px;
    text-align: center;
  }

  .values-head p {
    margin: 0;
    color: #66747f;
    font-size: .96rem;
    line-height: 1.65;
  }

  .values-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0,1fr));
    gap: 16px;
  }

  .value-card {
    min-height: 190px;
    padding: 24px 20px;
    border: 1px solid #e2e8ec;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(5,31,50,.045);
    text-align: center;
  }

  .value-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.2rem;
    font-weight: 900;
  }

  .value-card h3 {
    margin: 0;
    color: #071c2f;
    font-size: .98rem;
    font-weight: 900;
    line-height: 1.35;
  }

  .about-cta {
    padding: 0 0 76px;
  }

  .about-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 28px;
    padding: 36px 38px;
    border-radius: 14px;
    background: linear-gradient(105deg, #071c2f, #0b3653);
    color: #fff;
  }

  .about-cta-box h2 {
    margin: 0 0 8px;
    color: #fff;
    font-size: 1.9rem;
    font-weight: 900;
  }

  .about-cta-box p {
    margin: 0;
    max-width: 760px;
    color: #d6e0e6;
    font-size: .91rem;
    line-height: 1.58;
  }

  .about-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media(max-width:1199.98px) {
    .about-story-grid {
      gap: 30px;
    }
    .values-grid {
      grid-template-columns: repeat(3, minmax(0,1fr));
    }
  }

  @media(max-width:991.98px) {
    .about-hero::before {
      width: 100%;
      background:
        linear-gradient(90deg, rgba(6,25,42,.98) 0%, rgba(6,25,42,.9) 42%, rgba(6,25,42,.58) 68%, rgba(6,25,42,.28) 100%),
        url('site-assets/about/about-hero.png') 68% center / cover no-repeat;
    }
    .about-story-grid {
      grid-template-columns: 1fr;
    }
    .about-story-image img {
      height: 390px;
    }
  }

  @media(max-width:767.98px) {
    .about-hero {
      min-height: auto;
    }
    .about-hero-copy {
      padding: 54px 0 48px;
    }
    .about-hero h1 {
      font-size: 2.55rem;
    }
    .about-hero-actions {
      display: grid;
      grid-template-columns: 1fr;
    }
    .about-hero-actions .btn {
      width: 100%;
    }
    .about-hero-points {
      grid-template-columns: 1fr;
    }
    .about-story {
      padding: 52px 0;
    }
    .about-story-image img {
      height: 300px;
    }
    .purpose-grid {
      grid-template-columns: 1fr;
    }
    .values-grid {
      grid-template-columns: 1fr;
    }
    .about-cta-box {
      flex-direction: column;
      align-items: flex-start;
      padding: 28px 22px;
    }
    .about-cta-actions {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
    }
    .about-cta-actions .btn {
      width: 100%;
    }
  }
</style>

<main class="about-page">

  <section class="about-hero">
    <div class="container-fluid site-container">
      <div class="about-hero-copy">
        <div class="about-kicker">About FieldPlx</div>
        <h1>Smarter Tools for <span>Growing Service Businesses</span></h1>
        <p>FieldPlx is an all-in-one field-service management platform created to help small and mid-sized businesses simplify their operations, serve customers more effectively, and grow with confidence.</p>

        <div class="about-hero-points">
          <div class="about-point"><i class="bi bi-check-circle-fill"></i><span>Simplify daily operations</span></div>
          <div class="about-point"><i class="bi bi-check-circle-fill"></i><span>Serve customers better</span></div>
          <div class="about-point"><i class="bi bi-check-circle-fill"></i><span>Grow with confidence</span></div>
        </div>

        <div class="about-hero-actions">
          <a href="index.php?modal=trial"
             class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
             data-open-modal="trialModal"
             data-modal-name="trial">Start Your Free Trial</a>

          <a href="index.php?modal=demo"
             class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal"
             data-modal-name="demo">Book a Demo</a>
        </div>
      </div>
    </div>
  </section>

  <section class="about-story">
    <div class="container-fluid site-container">
      <div class="about-story-grid">

        <div class="about-story-image">
          <img src="site-assets/about/about-story.png" alt="FieldPlx supporting modern field-service businesses">
        </div>

        <div>
          <span class="section-mini">Who We Are</span>
          <h2>Built to Make Field Operations Simpler</h2>

          <p>FieldPlx brings scheduling, job management, customer information, employee coordination, invoicing, and reporting together in one convenient platform.</p>

          <p>Our goal is to reduce administrative work and give business owners greater visibility and control over their operations.</p>

          <div class="about-brand-note">
            <strong>FieldPlx is a product of CorePLX.</strong>
            <span>CorePLX is a technology company focused on building practical digital solutions for modern businesses.</span>
          </div>
        </div>

      </div>
    </div>
  </section>

  <section class="about-purpose">
    <div class="container-fluid site-container">
      <div class="purpose-grid">

        <article class="purpose-card about-mission">
          <div class="purpose-icon">◎</div>
          <span class="section-mini">Our Mission</span>
          <h3>Help Field-Service Businesses Work Smarter</h3>
          <p>To give field-service businesses simple, powerful, and accessible tools that help them work smarter, improve customer service, and build stronger businesses.</p>
        </article>

        <article class="purpose-card about-vision">
          <div class="purpose-icon">↗</div>
          <span class="section-mini">Our Vision</span>
          <h3>Become a Trusted Platform for Growing Service Teams</h3>
          <p>To become a trusted business-management platform for local service providers and growing field-service organizations.</p>
        </article>

      </div>
    </div>
  </section>

  <section class="about-values">
    <div class="container-fluid site-container">

      <div class="values-head">
        <span class="section-mini">Our Values</span>
        <h2>What Guides FieldPlx</h2>
        <p>Our values shape how we build the platform and how we support the service businesses that rely on it.</p>
      </div>

      <div class="values-grid">

        <article class="value-card">
          <div class="value-icon">✓</div>
          <h3>Simplicity</h3>
        </article>

        <article class="value-card">
          <div class="value-icon">◆</div>
          <h3>Reliability</h3>
        </article>

        <article class="value-card">
          <div class="value-icon">◎</div>
          <h3>Customer-Focused Innovation</h3>
        </article>

        <article class="value-card">
          <div class="value-icon">↗</div>
          <h3>Continuous Improvement</h3>
        </article>

        <article class="value-card">
          <div class="value-icon">▥</div>
          <h3>Small-Business Growth</h3>
        </article>

      </div>

    </div>
  </section>

  <section class="about-cta">
    <div class="container-fluid site-container">
      <div class="about-cta-box">

        <div>
          <h2>See How FieldPlx Can Support Your Business</h2>
          <p>Explore the platform, see how it fits your daily operations, and learn how FieldPlx can help your service business work smarter and grow stronger.</p>
        </div>

        <div class="about-cta-actions">
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

<?php include __DIR__ . '/footer.php'; ?>
