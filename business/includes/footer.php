    </section>

    <footer class="app-footer">
      <div class="footer-left">
        <span>© <span id="currentYear"></span> <strong>FieldPlx Services</strong>. All rights reserved.</span>
        <span class="footer-separator">•</span>
        <span>Business Management System</span>
      </div>

      <div class="footer-right">
        <a href="#" title="Privacy Policy">Privacy Policy</a>
        <span class="footer-dot"></span>
        <a href="#" title="Terms & Conditions">Terms</a>
        <span class="footer-dot"></span>
        <a href="#" title="Support">Support</a>
        <span class="footer-dot"></span>
        <span>Version 1.0.0</span>
      </div>
    </footer>

  </main>
</div>

<?php require __DIR__ . '/rightsidebar.php'; ?>

<div id="backdrop" class="backdrop"></div>
<div id="toast" class="toast"></div>

<script src="assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const year = document.getElementById('currentYear');
  if (year) {
    year.textContent = new Date().getFullYear();
  }
});
</script>
</body>
</html>
