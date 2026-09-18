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

<script>
(function () {
    'use strict';

    var checking = false;
    var stopped = false;

    function redirectToLogin(reason) {

        if (stopped) {
            return;
        }

        stopped = true;

        window.location.replace(
            'logout.php?reason=' +
            encodeURIComponent(reason || 'remote_logout')
        );
    }

    function checkFieldPlxSession() {

        if (checking || stopped) {
            return;
        }

        checking = true;

        fetch('api/session-status.php', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {

            return response.text().then(function (raw) {

                var data = null;

                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    /*
                     * Temporary invalid response should not
                     * force a logout.
                     */
                    return;
                }

                if (
                    response.status === 401 ||
                    data.authenticated === false
                ) {
                    redirectToLogin(
                        data.reason || 'session_expired'
                    );
                }
            });

        })
        .catch(function () {
            /*
             * Internet/network failure:
             * do nothing.
             *
             * Don't log the user out just because their
             * internet temporarily disconnected.
             */
        })
        .finally(function () {
            checking = false;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Check every 10 seconds
    |--------------------------------------------------------------------------
    */

    setInterval(
        checkFieldPlxSession,
        10000
    );

    /*
    |--------------------------------------------------------------------------
    | Initial check
    |--------------------------------------------------------------------------
    */

    setTimeout(
        checkFieldPlxSession,
        1000
    );

    /*
    |--------------------------------------------------------------------------
    | Check immediately when user returns to this tab/app
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'visibilitychange',
        function () {

            if (!document.hidden) {
                checkFieldPlxSession();
            }
        }
    );

    /*
     * Also useful on mobile when browser/app regains focus.
     */
    window.addEventListener(
        'focus',
        checkFieldPlxSession
    );

})();
</script>
</body>
</html>
