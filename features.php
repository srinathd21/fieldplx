<?php
$pageTitle = 'Features - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
.features-page{background:#fff;color:#071c2f}.features-page .site-container{max-width:1460px}
.features-hero{position:relative;min-height:610px;display:flex;align-items:center;overflow:hidden;background:#061a2d;color:#fff}
.features-hero::before{content:"";position:absolute;top:0;right:0;bottom:0;width:64%;background:url('site-assets/features/img1 (1).png') 68% center/cover no-repeat;z-index:0}
.features-hero::after{content:"";position:absolute;inset:0;z-index:1;background:linear-gradient(90deg,#031321 0%,rgba(3,19,33,.99) 34%,rgba(3,19,33,.88) 46%,rgba(3,19,33,.48) 62%,rgba(3,19,33,.12) 78%,rgba(3,19,33,0) 100%),radial-gradient(circle at 8% 20%,rgba(52,168,24,.10),transparent 28%);pointer-events:none}
.features-hero .hero-copy{position:relative;z-index:2;max-width:650px;padding:66px 0}
.features-kicker{display:inline-flex;align-items:center;padding:6px 11px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:16px}
.features-hero h1{margin:0 0 16px;font-size:clamp(2.55rem,5vw,4.7rem);line-height:1.03;font-weight:900;letter-spacing:-.045em;color:#fff}.features-hero h1 span{color:#39b719}
.features-hero-lead{max-width:650px;margin:0;color:#edf3f6;font-size:1.06rem;line-height:1.62}
.features-hero-benefits{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:24px 0 22px}.hero-benefit{display:flex;align-items:center;gap:9px;color:#fff;font-size:.88rem;font-weight:700;line-height:1.2}.hero-benefit i{color:#39b719;font-size:1.1rem}
.features-hero-actions{display:flex;gap:14px;flex-wrap:wrap}.features-hero-actions .btn{min-width:180px;font-weight:800}.btn-outline-brand{border:2px solid #39b719;color:#fff;background:transparent}.btn-outline-brand:hover{background:#39b719;color:#fff}
.offer-card{margin-top:18px;display:inline-flex;align-items:center;gap:16px;min-width:430px;padding:14px 22px;border:1px solid rgba(255,255,255,.55);border-radius:6px;background:rgba(7,28,47,.45)}.offer-icon{font-size:2.1rem}.offer-kicker{color:#74df43;font-size:.72rem;font-weight:900;letter-spacing:.05em}.offer-title{font-size:1.45rem;font-weight:900;line-height:1.1}.offer-subtitle{font-size:.78rem;color:#fff;margin-top:2px}
.features-main{padding:62px 0 68px;background:#f7f9fb}.features-intro{max-width:820px;margin:0 auto 34px;text-align:center}.features-intro .eyebrow,.coming-soon-section .eyebrow{display:inline-block;margin-bottom:8px;color:#21a58f;font-size:.75rem;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.features-intro h2,.coming-soon-section h2,.features-cta h2{margin:0 0 10px;color:#071c2f;font-size:clamp(1.85rem,3vw,2.7rem);font-weight:900;letter-spacing:-.025em}.features-intro p{margin:0;color:#68757f;font-size:.92rem;line-height:1.6}
.feature-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.feature-card{position:relative;overflow:hidden;background:#fff;border:1px solid #e4e8eb;border-radius:10px;box-shadow:0 7px 22px rgba(5,31,50,.05);transition:.2s ease}.feature-card:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(5,31,50,.10)}.feature-card-image{height:170px;overflow:hidden;background:#eef2f4}.feature-card-image img{width:100%;height:100%;display:block;object-fit:cover;transition:transform .28s ease}.feature-card:hover .feature-card-image img{transform:scale(1.035)}.feature-card-body{position:relative;padding:18px 18px 20px;min-height:178px}.feature-number{position:absolute;top:16px;right:17px;color:#bcc7ce;font-size:.70rem;font-weight:900}.feature-icon{width:40px;height:40px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;border-radius:9px;background:#edf8eb;color:#35a818;font-size:1.05rem;font-weight:900}.feature-card h3{margin:0 34px 8px 0;color:#071c2f;font-size:1rem;font-weight:900;line-height:1.25}.feature-card p{margin:0;color:#65727c;font-size:.80rem;line-height:1.48}
.coming-soon-wrap{padding:0 0 66px;background:#f7f9fb}.coming-soon-section{padding:30px;border-radius:14px;background:linear-gradient(135deg,#eaf8f5,#f7fbfa);border:1px solid #d7ebe6}.coming-soon-head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:20px}.coming-soon-head p{max-width:720px;margin:0;color:#65717b;font-size:.9rem;line-height:1.6}.coming-badge{flex:0 0 auto;display:inline-flex;padding:7px 11px;border-radius:999px;background:#fff;border:1px solid #cfe4df;color:#0d8c79;font-size:.70rem;font-weight:800}.coming-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.coming-item{padding:16px;border-radius:9px;background:#fff;border:1px solid #deece8}.coming-item h3{margin:0 0 5px;color:#0b2133;font-size:.95rem;font-weight:900}.coming-item p{margin:0;color:#6b777f;font-size:.78rem;line-height:1.45}
.features-cta-wrap{padding:0 0 64px;background:#f7f9fb}.features-cta{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:30px 34px;border-radius:10px;background:#071c2f;color:#fff}.features-cta h2{color:#fff;margin-bottom:6px;font-size:1.65rem}.features-cta p{max-width:680px;margin:0;color:#d8e2e7;font-size:.88rem;line-height:1.55}.features-cta-actions{display:flex;gap:10px;flex:0 0 auto}
@media(max-width:1199.98px){.feature-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.features-hero-benefits{grid-template-columns:repeat(2,minmax(0,1fr));max-width:520px}}
@media(max-width:767.98px){.features-hero{min-height:auto}.features-hero::before{width:100%;background:url('site-assets/features/img1 (1).png') 58% center/cover no-repeat}.features-hero::after{background:linear-gradient(rgba(3,19,33,.84),rgba(3,19,33,.94))}.features-hero .hero-copy{padding:44px 0}.features-hero h1{font-size:2.35rem}.features-hero-actions{display:grid;grid-template-columns:1fr;width:100%}.features-hero-actions .btn{width:100%}.features-hero-benefits{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.offer-card{min-width:0;width:100%;padding:13px 15px}.feature-grid{grid-template-columns:1fr}.feature-card-image{height:210px}.coming-soon-section{padding:22px 16px}.coming-soon-head{flex-direction:column;gap:12px}.coming-grid{grid-template-columns:1fr}.features-cta{flex-direction:column;align-items:flex-start;padding:25px 20px}.features-cta-actions{display:grid;grid-template-columns:1fr;width:100%}.features-cta-actions .btn{width:100%}}
</style>

<main class="features-page">
  <section class="features-hero">
    <div class="container-fluid site-container">
      <div class="hero-copy">
        <div class="features-kicker">FieldPlx Features</div>
        <h1>Everything You Need to Run Your <span>Field Operations</span></h1>
        <p class="features-hero-lead">FieldPlx brings your essential business operations into one easy-to-use platform. Manage jobs, schedules, customers, employees, invoices, and business performance from the office or the field.</p>
        <div class="features-hero-benefits">
          <div class="hero-benefit"><i class="bi bi-check-circle-fill"></i><span>All-in-One<br>Platform</span></div>
          <div class="hero-benefit"><i class="bi bi-check-circle-fill"></i><span>Easy to<br>Use</span></div>
          <div class="hero-benefit"><i class="bi bi-check-circle-fill"></i><span>Works<br>Anywhere</span></div>
          <div class="hero-benefit"><i class="bi bi-check-circle-fill"></i><span>Built for<br>Growth</span></div>
        </div>
        <div class="features-hero-actions">
          <a href="index.php?modal=trial" class="btn btn-brand btn-lg js-fieldplx-modal-trigger" data-open-modal="trialModal" data-modal-name="trial">Start Your Free Trial <i class="bi bi-arrow-right ms-2"></i></a>
          <a href="index.php?modal=demo" class="btn btn-outline-brand btn-lg js-fieldplx-modal-trigger" data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a>
        </div>
        <div class="offer-card">
          <div class="offer-icon"><i class="bi bi-gift"></i></div>
          <div><div class="offer-kicker">LIMITED TIME OFFER</div><div class="offer-title">60 Days Free Trial!</div><div class="offer-subtitle">No credit card required. Cancel anytime.</div></div>
        </div>
      </div>
    </div>
  </section>

  <section class="features-main">
    <div class="container-fluid site-container">
      <div class="features-intro">
        <span class="eyebrow">Core Capabilities</span>
        <h2>One Platform for Your Daily Operations</h2>
        <p>Keep the essential parts of your field-service business connected, organized, and accessible to your team.</p>
      </div>
      <div class="feature-grid">
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (2).png" alt="Job Management"></div><div class="feature-card-body"><span class="feature-number">01</span><div class="feature-icon">✓</div><h3>Job Management</h3><p>Create, assign, track, and complete jobs from one central dashboard.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (3).png" alt="Scheduling and Dispatch"></div><div class="feature-card-body"><span class="feature-number">02</span><div class="feature-icon">⌚</div><h3>Scheduling and Dispatch</h3><p>Organize appointments, assign team members, and keep daily operations on schedule.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (4).png" alt="Customer Management"></div><div class="feature-card-body"><span class="feature-number">03</span><div class="feature-icon">◎</div><h3>Customer Management</h3><p>Store customer details, service history, job information, and communications in one place.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (5).png" alt="Employee and Team Management"></div><div class="feature-card-body"><span class="feature-number">04</span><div class="feature-icon">👥</div><h3>Employee and Team Management</h3><p>Manage employee profiles, assignments, responsibilities, and field activity.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (6).png" alt="Estimates and Invoicing"></div><div class="feature-card-body"><span class="feature-number">05</span><div class="feature-icon">$</div><h3>Estimates and Invoicing</h3><p>Prepare estimates, create professional invoices, and monitor payment status.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (7).png" alt="Mobile Field Access"></div><div class="feature-card-body"><span class="feature-number">06</span><div class="feature-icon">▣</div><h3>Mobile Field Access</h3><p>Give field employees access to job details, tasks, customer information, and updates wherever they work.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (8).png" alt="Reports and Business Insights"></div><div class="feature-card-body"><span class="feature-number">07</span><div class="feature-icon">↗</div><h3>Reports and Business Insights</h3><p>Track job progress, revenue, productivity, and other important business information.</p></div></article>
        <article class="feature-card"><div class="feature-card-image"><img src="site-assets/features/img1 (9).png" alt="Documents and Records"></div><div class="feature-card-body"><span class="feature-number">08</span><div class="feature-icon">▤</div><h3>Documents and Records</h3><p>Keep job-related notes, photos, documents, and service records organized.</p></div></article>
      </div>
    </div>
  </section>

  <section class="coming-soon-wrap"><div class="container-fluid site-container"><div class="coming-soon-section"><div class="coming-soon-head"><div><span class="eyebrow">Coming Soon</span><h2>More Capabilities Are Planned</h2><p>These items are proposed capabilities and are not currently presented as available FieldPlx features.</p></div><div class="coming-badge">Planned Features</div></div><div class="coming-grid"><div class="coming-item"><h3>Social Media Management</h3><p>Proposed tools to help businesses manage selected social-media activities.</p></div><div class="coming-item"><h3>Additional Payroll Capabilities</h3><p>Proposed enhancements for broader payroll-related workflows.</p></div><div class="coming-item"><h3>More Integrations and Automation Tools</h3><p>Planned options to connect more systems and streamline repetitive work.</p></div></div></div></div></section>

  <section class="features-cta-wrap"><div class="container-fluid site-container"><div class="features-cta"><div><h2>See FieldPlx in Action</h2><p>Explore how FieldPlx can bring your jobs, team, customers, invoicing, and business information together in one platform.</p></div><div class="features-cta-actions"><a href="index.php?modal=demo" class="btn btn-brand btn-lg js-fieldplx-modal-trigger" data-open-modal="demoModal" data-modal-name="demo">Book a Demo</a><a href="index.php?modal=trial" class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger" data-open-modal="trialModal" data-modal-name="trial">Start Free Trial</a></div></div></div></section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
