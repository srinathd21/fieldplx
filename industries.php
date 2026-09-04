<?php
/* Industries page v2 - corrected card image framing and mapping */
$pageTitle = 'Industries - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
  .industries-page {
    background: #fff;
    color: #071c2f;
  }

  .industries-page .site-container {
    max-width: 1460px;
  }

  /* HERO - aligned with the index.php visual language */
  .industries-hero {
    position: relative;
    min-height: 560px;
    overflow: hidden;
    background: #031321;
    color: #fff;
    display: flex;
    align-items: center;
  }

  .industries-hero::before {
    content: '';
    position: absolute;
    inset: 0 0 0 auto;
    width: 66%;
    background:
      url('site-assets/industry/industry-01.png') 62% center / cover no-repeat;
    z-index: 0;
  }

  .industries-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(circle at 16% 22%, rgba(55, 190, 16, .14), transparent 30%),
      linear-gradient(90deg,
        #031321 0%,
        rgba(3,19,33,.99) 34%,
        rgba(3,19,33,.92) 46%,
        rgba(3,19,33,.62) 61%,
        rgba(3,19,33,.20) 79%,
        rgba(3,19,33,0) 100%);
    z-index: 1;
  }

  .industries-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .industries-hero-copy {
    width: min(720px, 100%);
    padding: 68px 0 64px;
  }

  .industries-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    padding: 7px 12px;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 999px;
    background: rgba(255,255,255,.07);
    color: #fff;
    font-size: .74rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .industries-hero h1 {
    margin: 0 0 18px;
    max-width: 720px;
    color: #fff;
    font-size: clamp(2.8rem, 5.3vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
  }

  .industries-hero h1 span {
    color: #38b813;
  }

  .industries-hero-lead {
    max-width: 680px;
    margin: 0;
    color: #edf3f6;
    font-size: 1.05rem;
    line-height: 1.72;
  }

  .industries-hero-points {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 11px 22px;
    max-width: 650px;
    margin-top: 26px;
  }

  .industries-hero-point {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    font-size: .84rem;
    font-weight: 700;
  }

  .industries-check {
    color: #38b813;
    font-size: 1.12rem;
    font-weight: 900;
  }

  .industries-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 28px;
  }

  .industries-hero-actions .btn {
    min-width: 190px;
    font-weight: 800;
  }

  .industries-hero-actions .btn-outline-light {
    border-color: #38b813;
    color: #fff;
  }

  .industries-hero-actions .btn-outline-light:hover {
    background: #38b813;
    color: #fff;
    border-color: #38b813;
  }

  .industries-offer {
    display: inline-flex;
    align-items: center;
    gap: 16px;
    margin-top: 22px;
    padding: 15px 20px;
    min-width: min(500px, 100%);
    border: 1px solid rgba(255,255,255,.38);
    border-radius: 8px;
    background: rgba(2,18,31,.48);
    backdrop-filter: blur(3px);
  }

  .industries-offer-icon {
    font-size: 2rem;
    line-height: 1;
  }

  .industries-offer-kicker {
    color: #65d735;
    font-size: .72rem;
    font-weight: 900;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .industries-offer-title {
    margin-top: 1px;
    color: #fff;
    font-size: 1.35rem;
    line-height: 1.1;
    font-weight: 900;
  }

  .industries-offer-sub {
    margin-top: 3px;
    color: #e9eff3;
    font-size: .78rem;
  }

  /* INDUSTRY GRID */
  .industries-section {
    padding: 64px 0 72px;
  }

  .industries-section.alt {
    background: #f7f9fa;
  }

  .industries-head {
    max-width: 850px;
    margin: 0 auto 34px;
    text-align: center;
  }

  .industries-head .mini {
    color: #2f9d17;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .industries-head h2 {
    margin: 8px 0 10px;
    color: #071c2f;
    font-size: clamp(2rem, 3.2vw, 3rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .industries-head h2 span {
    color: #2f9d17;
  }

  .industries-head p {
    margin: 0;
    color: #61707b;
    font-size: .98rem;
    line-height: 1.65;
  }

  .industries-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }

  .industry-service-card {
    position: relative;
    overflow: hidden;
    border: 1px solid #e3e7ea;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(7,28,47,.045);
    transition: transform .22s ease, box-shadow .22s ease;
    display: flex;
    flex-direction: column;
    min-height: 360px;
  }

  .industry-service-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 34px rgba(7,28,47,.10);
  }

  /* v2: fixed image stage so every card image lines up evenly */
  .industry-service-image {
    position: relative;
    width: 100%;
    height: 205px;
    overflow: hidden;
    background: #edf2f4;
    flex: 0 0 205px;
  }

  .industry-service-image img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
    transform: scale(1.001);
    transition: transform .3s ease;
  }

  /* Per-image focus points keep faces, hands and equipment inside the frame. */
  .industry-service-image.focus-hvac img { object-position: 50% 34%; }
  .industry-service-image.focus-plumbing img { object-position: 50% 24%; }
  .industry-service-image.focus-electrical img { object-position: 50% 22%; }
  .industry-service-image.focus-landscaping img { object-position: 50% 26%; }
  .industry-service-image.focus-cleaning img { object-position: 50% 18%; }
  .industry-service-image.focus-construction img { object-position: 50% 24%; }
  .industry-service-image.focus-property img { object-position: 50% 22%; }
  .industry-service-image.focus-appliance img { object-position: 50% 26%; }
  .industry-service-image.focus-pest img { object-position: 50% 18%; }
  .industry-service-image.focus-roofing img { object-position: 50% 20%; }
  .industry-service-image.focus-painting img { object-position: 50% 18%; }
  .industry-service-image.focus-handyman img { object-position: 50% 22%; }
  .industry-service-image.focus-security img { object-position: 50% 22%; }
  .industry-service-image.focus-other img { object-position: 50% 24%; }

  .industry-service-card:hover .industry-service-image img {
    transform: scale(1.025);
  }

  .industry-service-body {
    padding: 18px 20px 20px;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
  }

  .industry-service-body h3 {
    margin: 0 0 8px;
    color: #071c2f;
    font-size: 1rem;
    font-weight: 900;
    line-height: 1.3;
  }

  .industry-service-body p {
    margin: 0;
    color: #687680;
    font-size: .82rem;
    line-height: 1.56;
  }

  /* VALUE STRIP */
  .industry-value-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    overflow: hidden;
    border: 1px solid #e3e7ea;
    border-radius: 10px;
    background: #fff;
  }

  .industry-value-item {
    display: flex;
    gap: 13px;
    padding: 22px;
    border-right: 1px solid #e8ebed;
  }

  .industry-value-item:last-child {
    border-right: 0;
  }

  .industry-value-icon {
    color: #2f9d17;
    font-size: 1.55rem;
    line-height: 1;
  }

  .industry-value-item strong {
    display: block;
    margin-bottom: 5px;
    color: #071c2f;
    font-size: .9rem;
  }

  .industry-value-item p {
    margin: 0;
    color: #697680;
    font-size: .76rem;
    line-height: 1.5;
  }

  /* DON'T SEE YOUR INDUSTRY */
  .industry-flex-box {
    display: grid;
    grid-template-columns: 1.05fr .95fr;
    gap: 34px;
    align-items: center;
    padding: 36px;
    border: 1px solid #dce8dc;
    border-radius: 14px;
    background: linear-gradient(135deg, #f3faf1, #fbfdfb);
  }

  .industry-flex-box h2 {
    margin: 0 0 10px;
    color: #071c2f;
    font-size: clamp(1.8rem, 3vw, 2.55rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .industry-flex-box p {
    margin: 0;
    color: #62706f;
    font-size: .94rem;
    line-height: 1.65;
  }

  .industry-flex-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .industry-flex-list div {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 12px 13px;
    border: 1px solid #dce8dc;
    border-radius: 9px;
    background: #fff;
    color: #263a45;
    font-size: .82rem;
    font-weight: 800;
  }

  .industry-flex-list span {
    color: #2f9d17;
    font-size: 1rem;
    font-weight: 900;
  }

  /* CTA */
  .industries-cta {
    padding: 0 0 70px;
  }

  .industries-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 26px;
    padding: 34px 38px;
    border-radius: 12px;
    background: #071c2f;
    color: #fff;
  }

  .industries-cta-box h2 {
    margin: 0 0 7px;
    color: #fff;
    font-size: 1.8rem;
    font-weight: 900;
  }

  .industries-cta-box p {
    margin: 0;
    max-width: 720px;
    color: #d5dfe5;
    font-size: .9rem;
    line-height: 1.55;
  }

  .industries-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media (max-width: 1199.98px) {
    .industries-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .industry-value-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .industry-value-item:nth-child(2) { border-right: 0; }
    .industry-value-item:nth-child(-n+2) { border-bottom: 1px solid #e8ebed; }
  }

  @media (max-width: 991.98px) {
    .industries-hero { min-height: auto; }
    .industries-hero::before { width: 100%; opacity: .54; }
    .industries-hero::after {
      background: linear-gradient(90deg, rgba(3,19,33,.98), rgba(3,19,33,.87));
    }
    .industries-hero-copy { width: 100%; }
    .industries-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .industry-flex-box { grid-template-columns: 1fr; }
  }

  @media (max-width: 767.98px) {
    .industries-hero-copy { padding: 48px 0 44px; }
    .industries-hero h1 { font-size: 2.5rem; }
    .industries-hero-points { grid-template-columns: 1fr; }
    .industries-hero-actions { display: grid; grid-template-columns: 1fr; }
    .industries-hero-actions .btn { width: 100%; }
    .industries-offer { width: 100%; min-width: 0; }
    .industries-section { padding: 50px 0 56px; }
    .industries-grid { grid-template-columns: 1fr; }
    .industry-service-image { height: 220px; flex-basis: 220px; }
    .industry-service-image img { object-position: center top; }
    .industry-value-strip { grid-template-columns: 1fr; }
    .industry-value-item { border-right: 0 !important; border-bottom: 1px solid #e8ebed !important; }
    .industry-value-item:last-child { border-bottom: 0 !important; }
    .industry-flex-box { padding: 24px 18px; }
    .industry-flex-list { grid-template-columns: 1fr; }
    .industries-cta-box { flex-direction: column; align-items: flex-start; padding: 28px 22px; }
    .industries-cta-actions { width: 100%; display: grid; grid-template-columns: 1fr; }
    .industries-cta-actions .btn { width: 100%; }
  }
</style>

<main class="industries-page">
  <section class="industries-hero">
    <div class="container-fluid site-container">
      <div class="industries-hero-copy">
        <div class="industries-kicker">FieldPlx Industries</div>
        <h1>Built for Businesses That <span>Work in the Field</span></h1>
        <p class="industries-hero-lead">FieldPlx is designed for small and mid-sized service businesses that need a simpler way to coordinate employees, customers, jobs, and payments.</p>

        <div class="industries-hero-points">
          <div class="industries-hero-point"><span class="industries-check">✓</span>Coordinate employees and schedules</div>
          <div class="industries-hero-point"><span class="industries-check">✓</span>Manage customers and service jobs</div>
          <div class="industries-hero-point"><span class="industries-check">✓</span>Keep field teams connected</div>
          <div class="industries-hero-point"><span class="industries-check">✓</span>Track payments and daily operations</div>
        </div>

        <div class="industries-hero-actions">
          <a href="index.php?modal=trial" class="btn btn-brand btn-lg js-fieldplx-modal-trigger" data-open-modal="trialModal" data-modal-name="trial">Start Your Free Trial</a>
          <a href="index.php?modal=demo" class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger" data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a>
        </div>

        <div class="industries-offer">
          <div class="industries-offer-icon">🎁</div>
          <div>
            <div class="industries-offer-kicker">Limited Time Offer</div>
            <div class="industries-offer-title">60 Days Free Trial!</div>
            <div class="industries-offer-sub">No credit card required. Cancel anytime.</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="industries-section">
    <div class="container-fluid site-container">
      <div class="industries-head">
        <div class="mini">Industries Served</div>
        <h2>Built for <span>Field Service Businesses</span></h2>
        <p>Whether your team repairs, installs, maintains, cleans, builds, or services customer locations, FieldPlx helps keep the work organized.</p>
      </div>

      <div class="industries-grid">
        <article class="industry-service-card">
          <div class="industry-service-image focus-hvac"><img src="site-assets/industry/industry-01.png" alt="HVAC technician servicing equipment"></div>
          <div class="industry-service-body"><h3>HVAC</h3><p>Coordinate technicians, appointments, service jobs, customers, and payments from one place.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-plumbing"><img src="site-assets/industry/industry-03.png" alt="Plumbing field service"></div>
          <div class="industry-service-body"><h3>Plumbing</h3><p>Organize service calls, customer details, job updates, and field team assignments.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-electrical"><img src="site-assets/industry/industry-02.png" alt="Electrical field service"></div>
          <div class="industry-service-body"><h3>Electrical Services</h3><p>Manage electrical service requests, job scheduling, employees, and customer records.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-landscaping"><img src="site-assets/industry/industry-04.png" alt="Landscaping and lawn care"></div>
          <div class="industry-service-body"><h3>Landscaping and Lawn Care</h3><p>Plan recurring work, coordinate crews, and keep property-service information organized.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-cleaning"><img src="site-assets/industry/industry-05.png" alt="Cleaning services"></div>
          <div class="industry-service-body"><h3>Cleaning Services</h3><p>Schedule teams, manage customer locations, and keep recurring cleaning jobs on track.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-construction"><img src="site-assets/industry/industry-12.png" alt="Construction and contracting field work"></div>
          <div class="industry-service-body"><h3>Construction and Contracting</h3><p>Coordinate field work, employees, customer communication, documents, and job progress.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-property"><img src="site-assets/industry/industry-07.png" alt="Property maintenance service"></div>
          <div class="industry-service-body"><h3>Property Maintenance</h3><p>Keep maintenance requests, scheduled work, customer properties, and service history together.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-appliance"><img src="site-assets/industry/industry-11.png" alt="Appliance repair service"></div>
          <div class="industry-service-body"><h3>Appliance Repair</h3><p>Track service calls, repair visits, customer information, technician assignments, and billing.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-pest"><img src="site-assets/industry/industry-06.png" alt="Pest control service"></div>
          <div class="industry-service-body"><h3>Pest Control</h3><p>Organize customer visits, recurring treatments, service notes, and field employee schedules.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-roofing"><img src="site-assets/industry/industry-08.png" alt="Roofing service"></div>
          <div class="industry-service-body"><h3>Roofing</h3><p>Manage estimates, customer jobs, field teams, documents, and project-related information.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-painting"><img src="site-assets/industry/industry-09.png" alt="Painting service"></div>
          <div class="industry-service-body"><h3>Painting</h3><p>Schedule crews, manage customer jobs, organize site information, and monitor work progress.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-handyman"><img src="site-assets/industry/industry-07.png" alt="Handyman services"></div>
          <div class="industry-service-body"><h3>Handyman Services</h3><p>Manage multiple service requests, customer appointments, job details, and payments efficiently.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-security"><img src="site-assets/industry/industry-02.png" alt="Security and installation services"></div>
          <div class="industry-service-body"><h3>Security and Installation Services</h3><p>Coordinate installation jobs, field technicians, customer locations, records, and follow-up work.</p></div>
        </article>

        <article class="industry-service-card">
          <div class="industry-service-image focus-other"><img src="site-assets/industry/industry-10.png" alt="Other mobile and field-service businesses"></div>
          <div class="industry-service-body"><h3>Other Mobile and Field-Service Businesses</h3><p>Use FieldPlx wherever employees travel to customers and perform scheduled work in the field.</p></div>
        </article>
      </div>
    </div>
  </section>

  <section class="industries-section alt">
    <div class="container-fluid site-container">
      <div class="industries-head">
        <div class="mini">Designed Around Daily Work</div>
        <h2>One Platform Across Your <span>Service Operations</span></h2>
        <p>FieldPlx gives growing service businesses a simpler way to connect people, customers, jobs, and business information.</p>
      </div>

      <div class="industry-value-strip">
        <div class="industry-value-item">
          <div class="industry-value-icon">⚙</div>
          <div><strong>Schedule the Work</strong><p>Organize appointments and field assignments before your team heads out.</p></div>
        </div>
        <div class="industry-value-item">
          <div class="industry-value-icon">👥</div>
          <div><strong>Coordinate Your Team</strong><p>Keep employees aligned with the jobs and customer information they need.</p></div>
        </div>
        <div class="industry-value-item">
          <div class="industry-value-icon">▣</div>
          <div><strong>Work From Anywhere</strong><p>Give field employees access to job details and updates wherever they work.</p></div>
        </div>
        <div class="industry-value-item">
          <div class="industry-value-icon">$</div>
          <div><strong>Stay on Top of Payments</strong><p>Connect estimates, invoices, and payment status with your daily operations.</p></div>
        </div>
      </div>
    </div>
  </section>

  <section class="industries-section">
    <div class="container-fluid site-container">
      <div class="industry-flex-box">
        <div>
          <div class="industries-kicker" style="color:#2f9d17;border-color:#dce8dc;background:#fff;">Flexible for Your Business</div>
          <h2>Don't See Your Industry?</h2>
          <p>FieldPlx can support many businesses that schedule employees, visit customer locations, manage service jobs, or perform work in the field.</p>
        </div>
        <div class="industry-flex-list">
          <div><span>✓</span>Schedule employees</div>
          <div><span>✓</span>Visit customer locations</div>
          <div><span>✓</span>Manage service jobs</div>
          <div><span>✓</span>Perform work in the field</div>
        </div>
      </div>
    </div>
  </section>

  <section class="industries-cta">
    <div class="container-fluid site-container">
      <div class="industries-cta-box">
        <div>
          <h2>See How FieldPlx Fits Your Business</h2>
          <p>Book a personalized demo to see how FieldPlx can support your employees, customers, service jobs, and daily field operations.</p>
        </div>
        <div class="industries-cta-actions">
          <a href="index.php?modal=demo" class="btn btn-brand btn-lg js-fieldplx-modal-trigger" data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a>
          <a href="index.php?modal=trial" class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger" data-open-modal="trialModal" data-modal-name="trial">Start Free Trial</a>
        </div>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
