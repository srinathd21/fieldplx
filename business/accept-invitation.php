<?php
require_once __DIR__ . '/includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['team_invite_csrf'])) {
    $_SESSION['team_invite_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['team_invite_csrf'];

function ti_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ti_base_url()
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') return '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $basePath = $script !== '' ? dirname($script) : '';
    $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
    return $scheme . '://' . $host . $basePath;
}

function ti_load_invitation(PDO $pdo, $rawToken, $forUpdate = false)
{
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/i', $rawToken)) return false;
    $sql = "SELECT at.id AS token_id,at.tenant_id,at.user_id,at.expires_at,at.used_at,
                   u.first_name,u.last_name,u.email,u.status,u.is_tenant_admin,
                   t.display_name,t.legal_name,t.logo_path
            FROM tenant_activation_tokens at
            INNER JOIN users u ON u.id=at.user_id AND u.tenant_id=at.tenant_id AND u.deleted_at IS NULL
            INNER JOIN tenants t ON t.id=at.tenant_id AND t.deleted_at IS NULL
            WHERE at.token_hash=:h
              AND at.used_at IS NULL
              AND at.expires_at>NOW()
              AND u.status='invited'
            LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $q = $pdo->prepare($sql);
    $q->execute(array(':h'=>hash('sha256', $rawToken)));
    return $q->fetch(PDO::FETCH_ASSOC);
}

$rawToken = isset($_GET['token']) ? trim((string)$_GET['token']) : (isset($_POST['token']) ? trim((string)$_POST['token']) : '');
$error = '';
$success = false;
$invite = ti_load_invitation($pdo, $rawToken, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        $error = 'Your invitation form expired. Refresh the page and try again.';
    } else {
        $fullName = isset($_POST['full_name']) ? trim((string)$_POST['full_name']) : '';
        $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
        $confirm = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

        if ($fullName === '') {
            $error = 'Enter your full name.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must contain at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one letter and one number.';
        } elseif ($password !== $confirm) {
            $error = 'Password and confirmation do not match.';
        } else {
            try {
                $pdo->beginTransaction();
                $invite = ti_load_invitation($pdo, $rawToken, true);
                if (!$invite) {
                    throw new RuntimeException('This invitation is invalid, expired, or has already been accepted.');
                }

                $parts = preg_split('/\s+/', $fullName, 2);
                $firstName = isset($parts[0]) ? trim($parts[0]) : '';
                $lastName = isset($parts[1]) ? trim($parts[1]) : '';

                $q = $pdo->prepare("UPDATE users
                                    SET first_name=:fn,last_name=:ln,password_hash=:ph,status='active',updated_at=NOW()
                                    WHERE id=:u AND tenant_id=:t AND status='invited' AND deleted_at IS NULL");
                $q->execute(array(
                    ':fn'=>$firstName,
                    ':ln'=>$lastName !== '' ? $lastName : null,
                    ':ph'=>password_hash($password, PASSWORD_DEFAULT),
                    ':u'=>(int)$invite['user_id'],
                    ':t'=>(int)$invite['tenant_id']
                ));
                if ($q->rowCount() !== 1) throw new RuntimeException('Unable to activate this account.');

                /* Consume every outstanding invitation for this user so an older link cannot be reused. */
                $q = $pdo->prepare("UPDATE tenant_activation_tokens SET used_at=NOW() WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>(int)$invite['tenant_id'], ':u'=>(int)$invite['user_id']));

                $pdo->commit();
                $success = true;
                unset($_SESSION['team_invite_csrf']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to accept the invitation.';
            }
        }
    }
}

$businessName = $invite ? trim((string)$invite['display_name']) : 'FieldPlx';
if ($businessName === '' && $invite) $businessName = trim((string)$invite['legal_name']);
if ($businessName === '') $businessName = 'FieldPlx';
$fullNameValue = $invite ? trim((string)$invite['first_name'] . ' ' . (string)$invite['last_name']) : '';
$emailValue = $invite ? (string)$invite['email'] : '';
$loginUrl = 'login.php' . ($emailValue !== '' ? '?email=' . rawurlencode($emailValue) : '');
$playUrl = trim((string)getenv('FIELDPLX_GOOGLE_PLAY_URL'));
$appStoreUrl = trim((string)getenv('FIELDPLX_APP_STORE_URL'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $success ? 'Invitation accepted' : 'Accept invitation' ?> · FieldPlx</title>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<style>
*{box-sizing:border-box}html,body{min-height:100%;margin:0}body{font-family:Inter,Arial,Helvetica,sans-serif;color:#173744;background:radial-gradient(circle at 12% 12%,rgba(237,83,125,.58),transparent 28%),radial-gradient(circle at 86% 20%,rgba(255,197,100,.58),transparent 30%),radial-gradient(circle at 38% 86%,rgba(81,150,102,.62),transparent 33%),linear-gradient(135deg,#355d53,#d9a1a7 46%,#98b282);background-attachment:fixed}.invite-shell{min-height:100vh;padding:34px 16px;display:flex;flex-direction:column;align-items:center}.brand{width:min(520px,100%);display:flex;align-items:center;gap:10px;margin-bottom:18px;color:#fff;font-weight:900;font-size:30px;letter-spacing:2px;text-shadow:0 2px 10px rgba(0,0,0,.2)}.brand-mark{width:38px;height:38px;border:3px solid #fff;border-radius:10px;display:grid;place-items:center}.brand-mark:before{content:"F";font-size:21px}.invite-card{width:min(520px,100%);padding:22px;border:1px solid rgba(255,255,255,.56);border-radius:8px;background:rgba(255,255,255,.88);box-shadow:0 16px 45px rgba(16,38,43,.23);backdrop-filter:blur(12px)}h1{margin:0 0 9px;font-size:21px;line-height:1.2;color:#173744}.lead{margin:0 0 18px;font-size:13px;line-height:1.55;color:#536b76}.email-box{margin:0 0 14px;padding:12px;border-radius:6px;background:#f7fafb;border:1px solid #dbe4e8}.email-box small{display:block;color:#73868e;font-size:10px}.email-box strong{display:block;margin-top:3px;font-size:12px}.field{margin-top:11px}.field label{display:block;margin-bottom:5px;font-size:11px;color:#536b76}.input-wrap{position:relative}.field input{width:100%;height:44px;border:1px solid #cfdade;border-radius:6px;background:#fff;padding:0 12px;font:inherit;font-size:13px;color:#173744;outline:none}.field input:focus{border-color:#2f8c25;box-shadow:0 0 0 3px rgba(47,140,37,.12)}.password-toggle{position:absolute;right:7px;top:7px;width:30px;height:30px;border:0;background:transparent;color:#5f747d;cursor:pointer;display:grid;place-items:center}.password-toggle svg{width:17px;height:17px}.join-btn,.open-btn{width:100%;height:44px;margin-top:16px;border:0;border-radius:5px;background:#2f8c25;color:#fff;font:inherit;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px}.join-btn:hover,.open-btn:hover{background:#26751f}.legal{margin:15px 0 0;color:#697d85;font-size:10px;line-height:1.5}.legal a{color:#365f6e}.about{margin-top:18px;font-size:10px;line-height:1.5;color:#5f747d}.error{display:flex;gap:8px;align-items:flex-start;margin:0 0 14px;padding:11px 12px;border-radius:6px;background:#fff1ef;border:1px solid #f0c2bc;color:#a93c31;font-size:11px}.error svg{width:16px;height:16px;flex:0 0 16px}.success-icon{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;margin:0 auto 15px;background:#e6f4e3;color:#2f8c25}.success-icon svg{width:26px;height:26px}.success{text-align:left}.success h1{font-size:26px}.store-grid{display:grid;gap:10px;margin-top:18px}.store-btn{height:58px;border:2px solid #2f8c25;border-radius:12px;background:rgba(255,255,255,.82);display:flex;align-items:center;justify-content:center;color:#2f8c25;text-decoration:none;font-weight:800}.footer-links{margin-top:16px;text-align:center;font-size:10px;color:#fff}.footer-links a{color:#fff}@media(max-width:560px){.invite-shell{padding:24px 10px}.brand{font-size:25px}.invite-card{padding:17px}}
</style>
</head>
<body>
<div class="invite-shell">
    <div class="brand"><span class="brand-mark"></span><span>FIELDPLX</span></div>

    <section class="invite-card">
    <?php if ($success): ?>
        <div class="success">
            <div class="success-icon"><i data-lucide="check"></i></div>
            <h1>Success!</h1>
            <p class="lead">Your account is ready to go. Sign in to FieldPlx and start working with <?= ti_h($businessName) ?>.</p>
            <a class="open-btn" href="<?= ti_h($loginUrl) ?>"><i data-lucide="log-in"></i> Open FieldPlx</a>
            <?php if ($playUrl !== '' || $appStoreUrl !== ''): ?>
                <div class="store-grid">
                    <?php if ($playUrl !== ''): ?><a class="store-btn" href="<?= ti_h($playUrl) ?>" target="_blank" rel="noopener">Get it on Google Play</a><?php endif; ?>
                    <?php if ($appStoreUrl !== ''): ?><a class="store-btn" href="<?= ti_h($appStoreUrl) ?>" target="_blank" rel="noopener">Download on the App Store</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif (!$invite): ?>
        <div class="error"><i data-lucide="circle-alert"></i><span>This invitation is invalid, expired, or has already been accepted. Ask your FieldPlx administrator to send a new invitation.</span></div>
        <a class="open-btn" href="login.php"><i data-lucide="log-in"></i> Go to sign in</a>
    <?php else: ?>
        <h1>Hello <?= ti_h($invite['first_name']) ?>,</h1>
        <p class="lead">You have received an invitation to join the <strong><?= ti_h($businessName) ?></strong> team on FieldPlx.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><i data-lucide="circle-alert"></i><span><?= ti_h($error) ?></span></div>
        <?php endif; ?>

        <div class="email-box"><small>Your email address</small><strong><?= ti_h($emailValue) ?></strong></div>

        <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= ti_h($rawToken) ?>">
            <input type="hidden" name="csrf_token" value="<?= ti_h($csrfToken) ?>">

            <div class="field">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" maxlength="240" required value="<?= ti_h(isset($_POST['full_name']) ? $_POST['full_name'] : $fullNameValue) ?>">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
                    <button class="password-toggle" type="button" data-toggle-password="password" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm password</label>
                <div class="input-wrap">
                    <input id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password">
                    <button class="password-toggle" type="button" data-toggle-password="confirm_password" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
            </div>

            <button class="join-btn" type="submit"><i data-lucide="user-check"></i> Join Now</button>
            <p class="legal">By creating an account you agree to our <a href="terms.php" target="_blank">Terms of Service</a> and <a href="privacy-policy" target="_blank">Privacy Policy</a>.</p>
            <div class="about"><strong>Why am I getting this invite?</strong><br><?= ti_h($businessName) ?> uses FieldPlx to run its business, and you have been added to the team.</div>
        </form>
    <?php endif; ?>
    </section>

    <div class="footer-links">FieldPlx · <a href="privacy-policy">Privacy</a> · <a href="terms.php">Terms</a></div>
</div>
<script>
(function(){
    function icons(){if(window.lucide)window.lucide.createIcons({attrs:{'stroke-width':1.9}})}
    document.querySelectorAll('[data-toggle-password]').forEach(function(btn){
        btn.addEventListener('click',function(){
            var input=document.getElementById(btn.getAttribute('data-toggle-password'));
            if(!input)return;
            var show=input.type==='password';
            input.type=show?'text':'password';
            btn.innerHTML=show?'<i data-lucide="eye-off"></i>':'<i data-lucide="eye"></i>';
            btn.setAttribute('aria-label',show?'Hide password':'Show password');
            icons();
        });
    });
    icons();
})();
</script>
</body>
</html>
