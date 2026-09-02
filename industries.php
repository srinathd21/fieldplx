<?php
$pageTitle = 'Industries - FieldPlx';
include __DIR__ . '/topbar.php';
?>

<style>
:root{
  --fp-navy:#071c2f;
  --fp-navy-2:#0d2b45;
  --fp-green:#43a914;
  --fp-green-dark:#318f08;
  --fp-text:#1c2c38;
  --fp-muted:#66727c;
  --fp-light:#f5f8fa;
  --fp-border:#e2e8ec;
}

.fp-industries-page{background:#fff;color:var(--fp-text)}
.fp-industries-hero{
  position:relative;
  overflow:hidden;
  padding:88px 0 76px;
  background:
    radial-gradient(circle at 88% 18%,rgba(67,169,20,.24),transparent 27%),
    linear-gradient(135deg,#061a2c 0%,#0a2a45 58%,#0f3b59 100%);
  color:#fff;
}
.fp-industries-hero:after{
  content:"";
  position:absolute;
  inset:auto -120px -180px auto;
  width:440px;
  height:440px;
  border-radius:50%;
  border:70px solid rgba(255,255,255,.035);
}
.fp-industries-hero .container{position:relative;z-index:2}
.fp-eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-bottom:16px;
  padding:7px 12px;
  border-radius:999px;
  background:rgba(67,169,20,.14);
  border:1px solid rgba(109,204,61,.35);
  color:#9ee071;
  font-size:.82rem;
  font-weight:800;
  letter-spacing:.06em;
  text-transform:uppercase;
}
.fp-industries-hero h1{
  max-width:760px;
  margin:0 0 18px;
  font-size:clamp(2.5rem,5.2vw,4.7rem);
  font-weight:850;
  line-height:1.02;
  letter-spacing:-.045em;
}
.fp-industries-hero h1 span{color:#76cf3d}
.fp-industries-hero p{
  max-width:720px;
  margin:0;
  font-size:1.08rem;
  line-height:1.75;
  color:rgba(255,255,255,.78);
}
.fp-hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:28px}
.fp-hero-actions .btn{border-radius:8px;font-weight:800;padding:12px 20px}

.fp-industries-main{padding:72px 0 84px;background:#f7f9fb}
.fp-section-head{text-align:center;max-width:760px;margin:0 auto 34px}
.fp-section-head .tag{color:var(--fp-green-dark);font-weight:800;text-transform:uppercase;font-size:.78rem;letter-spacing:.08em}
.fp-section-head h2{margin:7px 0 10px;color:var(--fp-navy);font-size:clamp(2rem,3vw,2.9rem);font-weight:850;letter-spacing:-.035em}
.fp-section-head p{margin:0;color:var(--fp-muted);line-height:1.7}

.fp-industry-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}
.fp-industry-card{
  overflow:hidden;
  background:#fff;
  border:1px solid var(--fp-border);
  border-radius:18px;
  box-shadow:0 10px 32px rgba(7,28,47,.07);
  transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;
}
.fp-industry-card:hover{transform:translateY(-5px);box-shadow:0 18px 42px rgba(7,28,47,.12);border-color:#cfe0d0}
.fp-industry-image{height:205px;overflow:hidden;background:#eaf0f3}
.fp-industry-image img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}
.fp-industry-card:hover .fp-industry-image img{transform:scale(1.035)}
.fp-industry-body{padding:22px 22px 24px}
.fp-industry-title{display:flex;align-items:center;gap:11px;margin-bottom:10px}
.fp-industry-icon{width:42px;height:42px;flex:0 0 42px;border-radius:11px;display:grid;place-items:center;background:#edf8e8;color:var(--fp-green-dark);font-size:1.22rem}
.fp-industry-title h3{margin:0;color:var(--fp-navy);font-weight:820;font-size:1.18rem}
.fp-industry-body p{margin:0;color:var(--fp-muted);font-size:.93rem;line-height:1.65}
.fp-points{display:flex;flex-wrap:wrap;gap:7px;margin-top:16px}
.fp-points span{display:inline-flex;align-items:center;gap:5px;padding:6px 8px;border-radius:8px;background:#f5f8f9;color:#42525e;font-size:.76rem;font-weight:700}
.fp-points i{color:var(--fp-green-dark)}

.fp-why{padding:76px 0;background:#fff}
.fp-why-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
.fp-why-copy h2{margin:0 0 16px;color:var(--fp-navy);font-size:clamp(2rem,3vw,3rem);font-weight:850;letter-spacing:-.035em}
.fp-why-copy>p{color:var(--fp-muted);line-height:1.72;margin-bottom:24px}
.fp-check-list{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.fp-check{display:flex;gap:10px;align-items:flex-start;padding:13px 14px;border:1px solid var(--fp-border);border-radius:12px;background:#fbfcfd}
.fp-check i{color:var(--fp-green);font-size:1.15rem;margin-top:1px}
.fp-check strong{display:block;color:var(--fp-navy);font-size:.91rem}
.fp-check span{display:block;color:var(--fp-muted);font-size:.8rem;margin-top:2px}
.fp-why-panel{padding:28px;border-radius:22px;background:linear-gradient(145deg,#071c2f,#0c3555);color:#fff;box-shadow:0 20px 50px rgba(7,28,47,.18)}
.fp-why-panel h3{font-weight:820;margin:0 0 8px}
.fp-why-panel>p{color:rgba(255,255,255,.72);margin-bottom:22px}
.fp-metric-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.fp-metric{padding:18px;border-radius:14px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1)}
.fp-metric strong{display:block;color:#8cdb5c;font-size:1.4rem}
.fp-metric span{font-size:.82rem;color:rgba(255,255,255,.73)}

.fp-cta{padding:72px 0;background:#eef6eb}
.fp-cta-card{display:flex;align-items:center;justify-content:space-between;gap:30px;padding:34px 38px;border-radius:22px;background:#fff;border:1px solid #dbe8d6;box-shadow:0 14px 38px rgba(7,28,47,.07)}
.fp-cta-card h2{margin:0 0 8px;color:var(--fp-navy);font-size:clamp(1.7rem,3vw,2.5rem);font-weight:850}
.fp-cta-card p{margin:0;color:var(--fp-muted)}
.fp-cta-card .btn{white-space:nowrap;border-radius:9px;padding:12px 22px;font-weight:800}

@media(max-width:991.98px){
  .fp-industry-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .fp-why-grid{grid-template-columns:1fr}
}
@media(max-width:767.98px){
  .fp-industries-hero{padding:62px 0 54px}
  .fp-industries-main,.fp-why,.fp-cta{padding:54px 0}
  .fp-industry-grid{grid-template-columns:1fr}
  .fp-industry-image{height:220px}
  .fp-check-list{grid-template-columns:1fr}
  .fp-cta-card{display:block;padding:28px 22px}
  .fp-cta-card .btn{margin-top:20px;width:100%}
}
</style>

<main class="fp-industries-page">
  <section class="fp-industries-hero">
    <div class="container">
      <div class="fp-eyebrow"><i class="bi bi-buildings"></i> Industries</div>
      <h1>Built for <span>Every</span> Field Service Business</h1>
      <p>From HVAC and plumbing to cleaning, landscaping, pest control, roofing and more, FieldPlx gives field service businesses one connected platform to organize customers, jobs, technicians, scheduling, work orders, billing and payments.</p>
      <div class="fp-hero-actions">
        <a href="index.php?modal=trial" class="btn btn-brand">Start Your 60-Day Free Trial <i class="bi bi-arrow-right ms-2"></i></a>
        <a href="contact.php" class="btn btn-outline-light">Talk to Our Team</a>
      </div>
    </div>
  </section>

  <section class="fp-industries-main">
    <div class="container">
      <div class="fp-section-head">
        <div class="tag">Field service industries</div>
        <h2>One platform. Built around the way you work.</h2>
        <p>Each service business has its own workflow, but the fundamentals are the same: respond faster, schedule better, keep teams informed and get paid without unnecessary admin work.</p>
      </div>

      <div class="fp-industry-grid">
        <?php
        $industries = [
          ['HVAC','industry-01.png','bi-thermometer-snow','Manage service calls, preventive maintenance, technician schedules and customer history from one place.',['Dispatch','Maintenance','Invoices']],
          ['Electrical','industry-02.png','bi-lightning-charge','Keep electrical service jobs, site visits, work orders, estimates and technician assignments organized.',['Work Orders','Quotes','Scheduling']],
          ['Plumbing','industry-03.png','bi-droplet','Handle urgent calls and planned jobs with clear dispatching, visit tracking, customer updates and payments.',['Dispatch','Customers','Payments']],
          ['Landscaping','industry-04.png','bi-tree','Coordinate recurring property visits, crews, routes, seasonal work, quotes and service records efficiently.',['Routes','Recurring Jobs','Crews']],
          ['Cleaning Services','industry-05.png','bi-stars','Manage residential and commercial cleaning schedules, teams, repeat visits, customer requests and billing.',['Schedules','Teams','Billing']],
          ['Pest Control','industry-06.png','bi-bug','Track recurring treatments, technicians, customer locations, service notes, reminders and collections.',['Recurring Visits','Notes','Collections']],
          ['Handyman','industry-07.png','bi-tools','Organize varied service requests, estimates, appointments, task lists and customer communication with ease.',['Requests','Tasks','Estimates']],
          ['Roofing','industry-08.png','bi-house','Manage inspections, quotes, work orders, crews, project progress and customer payment follow-ups.',['Inspections','Jobs','Payments']],
          ['Painting','industry-09.png','bi-brush','Keep site estimates, scheduled crews, customer approvals, work progress and invoices connected.',['Quotes','Crews','Invoices']],
          ['Pool Service','industry-10.png','bi-water','Run recurring pool maintenance visits, technician routes, service notes and customer billing.',['Routes','Service History','Billing']],
          ['Appliance Repair','industry-11.png','bi-wrench-adjustable','Manage repair appointments, technician availability, customer details, service progress and payment collection.',['Appointments','Technicians','Payments']],
          ['Junk Removal','industry-12.png','bi-truck','Coordinate pickup requests, crews, schedules, service locations, job completion and customer payments.',['Requests','Dispatch','Payments']],
        ];
        foreach ($industries as $item):
        ?>
          <article class="fp-industry-card">
            <div class="fp-industry-image">
              <img src="site-assets/industry/<?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="fp-industry-body">
              <div class="fp-industry-title">
                <div class="fp-industry-icon"><i class="bi <?= htmlspecialchars($item[2], ENT_QUOTES, 'UTF-8') ?>"></i></div>
                <h3><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></h3>
              </div>
              <p><?= htmlspecialchars($item[3], ENT_QUOTES, 'UTF-8') ?></p>
              <div class="fp-points">
                <?php foreach ($item[4] as $point): ?>
                  <span><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="fp-why">
    <div class="container">
      <div class="fp-why-grid">
        <div class="fp-why-copy">
          <div class="fp-eyebrow" style="background:#edf8e8;border-color:#d7ebce;color:#318f08"><i class="bi bi-grid-1x2"></i> One connected workspace</div>
          <h2>Everything your field service team needs to stay organized.</h2>
          <p>FieldPlx helps office teams and field workers stay aligned throughout the entire service lifecycle — from the first customer request to job completion and payment.</p>
          <div class="fp-check-list">
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Customer Management</strong><span>Keep service history and customer details together.</span></div></div>
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Smart Scheduling</strong><span>Assign the right worker at the right time.</span></div></div>
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Work Orders</strong><span>Track jobs from request through completion.</span></div></div>
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Quotes & Invoices</strong><span>Move faster from estimate to payment.</span></div></div>
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Mobile Access</strong><span>Give field teams access wherever they work.</span></div></div>
            <div class="fp-check"><i class="bi bi-check-circle-fill"></i><div><strong>Reports</strong><span>Understand jobs, revenue and operations clearly.</span></div></div>
          </div>
        </div>
        <aside class="fp-why-panel">
          <h3>Designed for growing service businesses</h3>
          <p>Start simple and keep the same system as your customer base, team and service operations expand.</p>
          <div class="fp-metric-grid">
            <div class="fp-metric"><strong>12+</strong><span>Field service industries supported</span></div>
            <div class="fp-metric"><strong>1</strong><span>Connected business platform</span></div>
            <div class="fp-metric"><strong>60 Days</strong><span>Free trial to explore FieldPlx</span></div>
            <div class="fp-metric"><strong>Anywhere</strong><span>Office and field team access</span></div>
          </div>
        </aside>
      </div>
    </div>
  </section>

  <section class="fp-cta">
    <div class="container">
      <div class="fp-cta-card">
        <div>
          <h2>Don't see your exact industry?</h2>
          <p>If your business has customers, field workers, service jobs and payments, FieldPlx can likely fit your workflow.</p>
        </div>
        <a href="index.php?modal=trial" class="btn btn-brand">Start Free Trial <i class="bi bi-arrow-right ms-2"></i></a>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
