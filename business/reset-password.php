<?php
require_once __DIR__ . '/includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['tenant_password_reset_csrf'])) {
    $_SESSION['tenant_password_reset_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['tenant_password_reset_csrf'];
$rawToken = isset($_GET['token']) ? trim((string)$_GET['token']) : (isset($_POST['token']) ? trim((string)$_POST['token']) : '');
$errorMessage = '';
$success = false;
$member = null;

function rp_table(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t'=>$table));
    return ((int)$q->fetchColumn() > 0);
}

function rp_find_token(PDO $pdo, $rawToken)
{
    if ($rawToken === '' || !rp_table($pdo, 'tenant_password_reset_tokens')) return false;
    $hash = hash('sha256', $rawToken);
    $q = $pdo->prepare("SELECT pr.id,pr.tenant_id,pr.user_id,pr.expires_at,pr.used_at,
                               u.first_name,u.last_name,u.email,u.status,u.deleted_at,
                               COALESCE(t.display_name,t.legal_name,'FieldPlx Business') AS business_name
                        FROM tenant_password_reset_tokens pr
                        INNER JOIN users u ON u.id=pr.user_id AND u.tenant_id=pr.tenant_id
                        LEFT JOIN tenants t ON t.id=pr.tenant_id
                        WHERE pr.token_hash=:h
                        LIMIT 1");
    $q->execute(array(':h'=>$hash));
    return $q->fetch(PDO::FETCH_ASSOC);
}

if (!rp_table($pdo, 'tenant_password_reset_tokens')) {
    $errorMessage = 'Password reset is not configured yet. Contact your administrator.';
} else {
    $member = rp_find_token($pdo, $rawToken);
    if (!$member) {
        $errorMessage = 'This password reset link is invalid.';
    } elseif (!empty($member['used_at'])) {
        $errorMessage = 'This password reset link has already been used.';
    } elseif (strtotime((string)$member['expires_at'] . ' UTC') < time()) {
        $errorMessage = 'This password reset link has expired. Ask your administrator to send a new one.';
    } elseif (!empty($member['deleted_at']) || (string)$member['status'] !== 'active') {
        $errorMessage = 'This account is not currently available for password reset.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        $errorMessage = 'Your form session expired. Refresh the page and try again.';
    } else {
        $newPassword = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

        if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            $errorMessage = 'Password must be at least 8 characters and include a letter and a number.';
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $errorMessage = 'New password and confirmation do not match.';
        } else {
            $pdo->beginTransaction();
            try {
                // Lock and re-check the one-time token inside the transaction.
                $hash = hash('sha256', $rawToken);
                $q = $pdo->prepare("SELECT id,tenant_id,user_id,expires_at,used_at
                                    FROM tenant_password_reset_tokens
                                    WHERE token_hash=:h
                                    FOR UPDATE");
                $q->execute(array(':h'=>$hash));
                $tokenRow = $q->fetch(PDO::FETCH_ASSOC);

                if (!$tokenRow || !empty($tokenRow['used_at']) || strtotime((string)$tokenRow['expires_at'] . ' UTC') < time()) {
                    throw new RuntimeException('This password reset link is no longer valid.');
                }

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $q = $pdo->prepare("UPDATE users
                                    SET password_hash=:p,updated_at=NOW()
                                    WHERE id=:u AND tenant_id=:t AND deleted_at IS NULL AND status='active'");
                $q->execute(array(':p'=>$passwordHash, ':u'=>(int)$tokenRow['user_id'], ':t'=>(int)$tokenRow['tenant_id']));
                if ($q->rowCount() < 1) {
                    throw new RuntimeException('This account is no longer available for password reset.');
                }

                $q = $pdo->prepare("UPDATE tenant_password_reset_tokens
                                    SET used_at=NOW()
                                    WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>(int)$tokenRow['tenant_id'], ':u'=>(int)$tokenRow['user_id']));

                if (rp_table($pdo, 'user_devices')) {
                    $q = $pdo->prepare("UPDATE user_devices
                                        SET status='revoked',updated_at=NOW()
                                        WHERE tenant_id=:t AND user_id=:u AND status='active'");
                    $q->execute(array(':t'=>(int)$tokenRow['tenant_id'], ':u'=>(int)$tokenRow['user_id']));
                }

                $pdo->commit();
                $success = true;
                $_SESSION['tenant_password_reset_csrf'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('FieldPlx tenant password reset error: ' . $e->getMessage());
                $errorMessage = $e->getMessage();
            }
        }
    }
}

$displayName = 'Team member';
$businessName = 'FieldPlx';
if (is_array($member)) {
    $displayName = trim((string)$member['first_name'] . ' ' . (string)$member['last_name']);
    if ($displayName === '') $displayName = (string)$member['email'];
    if (!empty($member['business_name'])) $businessName = (string)$member['business_name'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset Password · FieldPlx</title>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:linear-gradient(135deg,#eef4f1,#f7f9fb);font-family:Arial,Helvetica,sans-serif;color:#0b3142}.rp-card{width:min(460px,100%);background:#fff;border:1px solid #d9e2e7;border-radius:14px;box-shadow:0 24px 70px rgba(8,35,47,.12);overflow:hidden}.rp-head{padding:28px 28px 18px}.rp-brand{display:flex;align-items:center;gap:10px;margin-bottom:20px;color:#2f8c25;font-weight:800;font-size:18px}.rp-brand svg{width:24px;height:24px}.rp-head h1{margin:0;font-size:28px;line-height:1.15}.rp-head p{margin:9px 0 0;color:#667984;font-size:13px;line-height:1.55}.rp-body{padding:0 28px 28px}.rp-field{margin-top:14px}.rp-field label{display:block;margin-bottom:7px;font-size:12px;font-weight:700}.rp-input-wrap{position:relative}.rp-input{width:100%;height:50px;padding:0 46px 0 14px;border:1px solid #d5dfe5;border-radius:8px;font:inherit;font-size:14px;outline:none}.rp-input:focus{border-color:#2f8c25;box-shadow:0 0 0 3px rgba(47,140,37,.1)}.rp-eye{position:absolute;right:8px;top:8px;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:#47616d;display:grid;place-items:center;cursor:pointer}.rp-eye svg{width:18px;height:18px}.rp-button{width:100%;height:46px;margin-top:20px;border:0;border-radius:8px;background:#2f8c25;color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer}.rp-button:hover{background:#26751f}.rp-alert{margin-bottom:16px;padding:12px 14px;border-radius:8px;font-size:12px;line-height:1.5}.rp-alert.error{background:#fff3f1;border:1px solid #f0c8c2;color:#b63f32}.rp-success{text-align:center;padding:6px 0 2px}.rp-success-icon{width:58px;height:58px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;background:#eaf5e8;color:#2f8c25}.rp-success-icon svg{width:30px;height:30px}.rp-success h2{margin:0;font-size:24px}.rp-success p{margin:9px 0 0;color:#667984;font-size:13px;line-height:1.55}.rp-link{display:inline-flex;align-items:center;justify-content:center;gap:7px;width:100%;height:46px;margin-top:20px;border-radius:8px;background:#2f8c25;color:#fff;text-decoration:none;font-size:14px;font-weight:700}.rp-foot{padding:16px 28px;border-top:1px solid #e2e8ec;text-align:center;color:#83929a;font-size:11px}.rp-back{display:inline-flex;align-items:center;gap:5px;margin-top:16px;color:#2f8c25;text-decoration:none;font-size:12px;font-weight:700}.rp-back svg{width:15px;height:15px}
    </style>
</head>
<body>
<div class="rp-card">
    <div class="rp-head">
        <div class="rp-brand"><i data-lucide="briefcase-business"></i><span>FieldPlx</span></div>
        <?php if ($success): ?>
            <div class="rp-success">
                <div class="rp-success-icon"><i data-lucide="check"></i></div>
                <h2>Password updated</h2>
                <p>Your password has been changed successfully. All previous active sessions were signed out.</p>
                <a class="rp-link" href="login.php"><i data-lucide="log-in"></i><span>Sign in to FieldPlx</span></a>
            </div>
        <?php else: ?>
            <h1>Reset your password</h1>
            <p><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$success): ?>
    <div class="rp-body">
        <?php if ($errorMessage !== ''): ?>
            <div class="rp-alert error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($errorMessage === '' || ($_SERVER['REQUEST_METHOD'] === 'POST' && $member && empty($member['used_at']))): ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="rp-field">
                <label for="newPassword">New password</label>
                <div class="rp-input-wrap">
                    <input class="rp-input" type="password" id="newPassword" name="new_password" minlength="8" required autocomplete="new-password">
                    <button class="rp-eye" type="button" data-toggle="newPassword" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
            </div>

            <div class="rp-field">
                <label for="confirmPassword">Confirm password</label>
                <div class="rp-input-wrap">
                    <input class="rp-input" type="password" id="confirmPassword" name="confirm_password" minlength="8" required autocomplete="new-password">
                    <button class="rp-eye" type="button" data-toggle="confirmPassword" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
            </div>

            <button class="rp-button" type="submit">Reset password</button>
        </form>
        <?php else: ?>
            <a class="rp-back" href="login.php"><i data-lucide="arrow-left"></i><span>Back to sign in</span></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="rp-foot">Secure password reset · FieldPlx</div>
</div>
<script>
(function(){
    function icons(){if(window.lucide)window.lucide.createIcons({attrs:{'stroke-width':1.9}});}
    document.querySelectorAll('[data-toggle]').forEach(function(button){
        button.addEventListener('click',function(){
            var input=document.getElementById(button.getAttribute('data-toggle'));
            if(!input)return;
            var show=input.type==='password';
            input.type=show?'text':'password';
            button.innerHTML='<i data-lucide="'+(show?'eye-off':'eye')+'"></i>';
            button.setAttribute('aria-label',show?'Hide password':'Show password');
            icons();
        });
    });
    icons();
})();
</script>
</body>
</html>
