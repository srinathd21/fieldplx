<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function activationEscape($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Activation Token
|--------------------------------------------------------------------------
*/

$token = isset($_GET['token'])
    ? trim((string)$_GET['token'])
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['activation_token'])
        ? trim((string)$_POST['activation_token'])
        : '';
}

/*
|--------------------------------------------------------------------------
| Activation CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['activation_csrf'])) {
    $_SESSION['activation_csrf'] = bin2hex(
        random_bytes(32)
    );
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$error = '';
$success = '';

$userId = 0;
$tenantId = 0;

$firstName = '';
$email = '';
$businessName = '';

/*
|--------------------------------------------------------------------------
| Validate Activation Token
|--------------------------------------------------------------------------
*/

if ($token === '') {

    $error = 'This activation link is invalid.';

} else {

    $tokenHash = hash('sha256', $token);

    $stmt = $conn->prepare(
        "SELECT
            at.user_id,
            at.tenant_id,
            u.first_name,
            u.email,
            t.display_name
         FROM tenant_activation_tokens at
         INNER JOIN users u
            ON u.id = at.user_id
           AND u.tenant_id = at.tenant_id
         INNER JOIN tenants t
            ON t.id = at.tenant_id
         WHERE at.token_hash = ?
           AND at.used_at IS NULL
           AND at.expires_at > NOW()
           AND u.deleted_at IS NULL
           AND t.deleted_at IS NULL
         LIMIT 1"
    );

    if (!$stmt) {

        $error =
            'Unable to validate this activation link.';

    } else {

        $stmt->bind_param(
            's',
            $tokenHash
        );

        $stmt->execute();

        $stmt->bind_result(
            $userId,
            $tenantId,
            $firstName,
            $email,
            $businessName
        );

        if (!$stmt->fetch()) {

            $error =
                'This activation link is invalid, expired, or already used.';
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Activate Account
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['activation_action']) &&
    $error === ''
) {

    $postedCsrf = isset($_POST['activation_csrf'])
        ? (string)$_POST['activation_csrf']
        : '';

    if (
        $postedCsrf === '' ||
        !hash_equals(
            (string)$_SESSION['activation_csrf'],
            $postedCsrf
        )
    ) {

        $error =
            'Your activation session expired. Refresh the page and try again.';

    } else {

        $password = isset($_POST['password'])
            ? (string)$_POST['password']
            : '';

        $confirmPassword =
            isset($_POST['confirm_password'])
                ? (string)$_POST['confirm_password']
                : '';

        /*
        |--------------------------------------------------------------------------
        | Password Validation
        |--------------------------------------------------------------------------
        */

        if (strlen($password) < 8) {

            $error =
                'Password must be at least 8 characters.';

        } elseif ($password !== $confirmPassword) {

            $error =
                'Passwords do not match.';

        } else {

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $conn->begin_transaction();

            try {

                /*
                |--------------------------------------------------------------------------
                | Activate User
                |--------------------------------------------------------------------------
                */

                $userStmt = $conn->prepare(
                    "UPDATE users
                     SET
                        password_hash = ?,
                        status = 'active',
                        updated_at = NOW()
                     WHERE id = ?
                       AND tenant_id = ?
                       AND deleted_at IS NULL"
                );

                if (!$userStmt) {
                    throw new Exception(
                        'Unable to prepare user activation.'
                    );
                }

                $userStmt->bind_param(
                    'sii',
                    $passwordHash,
                    $userId,
                    $tenantId
                );

                if (
                    !$userStmt->execute() ||
                    $userStmt->affected_rows < 1
                ) {
                    throw new Exception(
                        'User activation failed.'
                    );
                }

                $userStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Activate Tenant Trial
                |--------------------------------------------------------------------------
                */

                $tenantStmt = $conn->prepare(
                    "UPDATE tenants
                     SET
                        status = 'trial',
                        updated_at = NOW()
                     WHERE id = ?
                       AND deleted_at IS NULL"
                );

                if ($tenantStmt) {

                    $tenantStmt->bind_param(
                        'i',
                        $tenantId
                    );

                    $tenantStmt->execute();
                    $tenantStmt->close();
                }

                /*
                |--------------------------------------------------------------------------
                | Mark Token Used
                |--------------------------------------------------------------------------
                */

                $tokenStmt = $conn->prepare(
                    "UPDATE tenant_activation_tokens
                     SET used_at = NOW()
                     WHERE token_hash = ?
                       AND used_at IS NULL"
                );

                if (!$tokenStmt) {
                    throw new Exception(
                        'Unable to update activation token.'
                    );
                }

                $tokenStmt->bind_param(
                    's',
                    $tokenHash
                );

                if (
                    !$tokenStmt->execute() ||
                    $tokenStmt->affected_rows < 1
                ) {
                    throw new Exception(
                        'Activation token update failed.'
                    );
                }

                $tokenStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Complete Transaction
                |--------------------------------------------------------------------------
                */

                $conn->commit();

                $success =
                    'Your FieldPlx account has been activated successfully. You can now sign in to your workspace.';

                unset(
                    $_SESSION['activation_csrf']
                );

            } catch (Throwable $e) {

                $conn->rollback();

                error_log(
                    'FieldPlx activation error: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to activate your account right now. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Activate Account - FieldPlx</title>

<?php
/*
 * Same common CSS / Bootstrap Icons used by login page.
 */
require_once __DIR__ . '/business/includes/links.php';
?>

<style>

:root{
    --fd-navy:#001131;
    --fd-navy-light:#071f49;

    --fd-green:#74b824;
    --fd-green-dark:#5d971b;
    --fd-green-soft:#f0f8e5;

    --fd-bg:#f6f8fb;
    --fd-text:#0b1933;
    --fd-muted:#6f7b90;
    --fd-border:#e5eaf1;

    --fd-danger:#dc2626;
}

*{
    box-sizing:border-box;
}

html,
body{
    min-height:100%;
}

body{
    margin:0;
    min-height:100vh;

    display:grid;
    place-items:center;

    padding:22px;

    overflow-x:hidden;

    background:
        radial-gradient(
            circle at top right,
            rgba(116,184,36,.11),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #f8fafc 0%,
            #f3f6fa 55%,
            #eef3f8 100%
        );

    color:var(--fd-text);

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size:14px;
}

a{
    text-decoration:none;
}

button,
input{
    font:inherit;
}

/*
|--------------------------------------------------------------------------
| Main Shell
|--------------------------------------------------------------------------
*/

.activation-shell{
    width:min(960px,100%);
    min-height:570px;

    display:grid;

    grid-template-columns:
        minmax(0,1fr)
        440px;

    overflow:hidden;

    border:1px solid #dfe6ef;
    border-radius:20px;

    background:#fff;

    box-shadow:
        0 28px 70px rgba(0,17,49,.11);
}

/*
|--------------------------------------------------------------------------
| Left Branding
|--------------------------------------------------------------------------
*/

.activation-brand-panel{
    position:relative;

    min-height:570px;

    padding:46px;

    overflow:hidden;

    display:flex;
    flex-direction:column;
    justify-content:space-between;

    color:#fff;

    background:
        linear-gradient(
            150deg,
            var(--fd-navy-light),
            var(--fd-navy)
        );
}

.activation-brand-panel:before,
.activation-brand-panel:after{
    position:absolute;

    content:"";

    border-radius:50%;

    pointer-events:none;
}

.activation-brand-panel:before{
    width:320px;
    height:320px;

    right:-160px;
    top:-100px;

    border:
        55px solid rgba(116,184,36,.10);
}

.activation-brand-panel:after{
    width:210px;
    height:210px;

    left:-105px;
    bottom:-95px;

    background:
        rgba(116,184,36,.08);
}

.activation-brand-content,
.activation-brand-footer{
    position:relative;
    z-index:2;
}

/*
|--------------------------------------------------------------------------
| Logo
|--------------------------------------------------------------------------
*/

.activation-logo{
    width:54px;
    height:54px;

    display:grid;
    place-items:center;

    border-radius:14px;

    color:#fff;

    background:
        linear-gradient(
            135deg,
            #8fd236,
            #68aa1d
        );

    box-shadow:
        0 12px 28px rgba(0,0,0,.18);

    font-size:24px;
    font-weight:800;
}

.activation-brand-title{
    max-width:420px;

    margin:
        30px 0 10px;

    font-size:32px;
    line-height:1.15;

    font-weight:700;

    letter-spacing:-.5px;
}

.activation-brand-description{
    max-width:430px;

    margin:0;

    color:
        rgba(255,255,255,.72);

    font-size:13px;
    line-height:1.75;
}

/*
|--------------------------------------------------------------------------
| Feature List
|--------------------------------------------------------------------------
*/

.activation-feature-list{
    margin-top:31px;

    display:grid;
    gap:13px;
}

.activation-feature{
    display:flex;

    align-items:center;

    gap:10px;

    color:
        rgba(255,255,255,.88);

    font-size:11px;
}

.activation-feature-icon{
    width:28px;
    height:28px;

    flex:0 0 28px;

    display:grid;
    place-items:center;

    border-radius:8px;

    color:#a7dc61;

    background:
        rgba(255,255,255,.08);

    font-size:13px;
}

.activation-brand-footer{
    color:
        rgba(255,255,255,.48);

    font-size:9px;
}

/*
|--------------------------------------------------------------------------
| Right Panel
|--------------------------------------------------------------------------
*/

.activation-form-panel{
    min-height:570px;

    padding:45px 42px;

    display:flex;
    flex-direction:column;
    justify-content:center;

    background:#fff;
}

.activation-mobile-logo{
    display:none;
}

.activation-title{
    margin:0;

    color:var(--fd-navy);

    font-size:25px;
    font-weight:700;
}

.activation-subtitle{
    margin:
        8px 0 22px;

    color:var(--fd-muted);

    font-size:11px;
    line-height:1.55;
}

/*
|--------------------------------------------------------------------------
| Alerts
|--------------------------------------------------------------------------
*/

.activation-alert{
    margin-bottom:17px;

    padding:11px 12px;

    display:flex;

    align-items:flex-start;

    gap:9px;

    border:
        1px solid #fecaca;

    border-radius:9px;

    color:#b91c1c;

    background:#fff7f7;

    font-size:10px;
    line-height:1.5;
}

.activation-alert i{
    margin-top:1px;

    font-size:13px;
}

.activation-alert.success{
    border-color:#cce9a9;

    color:#4d7d16;

    background:#f4faec;
}

/*
|--------------------------------------------------------------------------
| Account Information
|--------------------------------------------------------------------------
*/

.activation-account{
    margin-bottom:20px;

    padding:13px 14px;

    display:flex;

    align-items:center;

    gap:11px;

    border:
        1px solid var(--fd-border);

    border-radius:10px;

    background:#f8fafc;
}

.activation-account-icon{
    width:36px;
    height:36px;

    flex:0 0 36px;

    display:grid;
    place-items:center;

    border-radius:9px;

    color:var(--fd-green-dark);

    background:
        var(--fd-green-soft);

    font-size:15px;
}

.activation-account-content{
    min-width:0;
}

.activation-business{
    margin-bottom:3px;

    overflow:hidden;

    color:var(--fd-navy);

    font-size:11px;
    font-weight:700;

    text-overflow:ellipsis;
    white-space:nowrap;
}

.activation-email{
    overflow:hidden;

    color:var(--fd-muted);

    font-size:9.5px;

    text-overflow:ellipsis;
    white-space:nowrap;
}

/*
|--------------------------------------------------------------------------
| Form Fields
|--------------------------------------------------------------------------
*/

.activation-field{
    margin-bottom:16px;
}

.activation-field label{
    margin-bottom:7px;

    display:block;

    color:#384762;

    font-size:10px;
    font-weight:700;
}

.activation-input-wrap{
    position:relative;
}

.activation-input-icon{
    position:absolute;

    left:13px;
    top:50%;

    transform:
        translateY(-50%);

    color:#97a4b5;

    font-size:15px;

    pointer-events:none;
}

.activation-input{
    width:100%;
    height:46px;

    padding:
        9px 42px
        9px 39px;

    border:
        1px solid #dbe2eb;

    border-radius:9px;

    outline:0;

    color:var(--fd-navy);

    background:#fff;

    font-size:12px;

    transition:
        border-color .18s ease,
        box-shadow .18s ease;
}

.activation-input::placeholder{
    color:#a3adba;
}

.activation-input:focus{
    border-color:#a6cb72;

    box-shadow:
        0 0 0 3px
        rgba(116,184,36,.12);
}

/*
|--------------------------------------------------------------------------
| Password Toggle
|--------------------------------------------------------------------------
*/

.activation-password-toggle{
    width:36px;
    height:36px;

    position:absolute;

    right:5px;
    top:50%;

    transform:
        translateY(-50%);

    display:grid;
    place-items:center;

    border:0;
    border-radius:7px;

    color:#8390a3;

    background:transparent;

    cursor:pointer;

    font-size:14px;
}

.activation-password-toggle:hover{
    color:
        var(--fd-green-dark);

    background:
        var(--fd-green-soft);
}

.activation-hint{
    margin-top:6px;

    display:flex;

    align-items:center;

    gap:5px;

    color:#96a0af;

    font-size:8.5px;
    line-height:1.4;
}

.activation-hint i{
    color:
        var(--fd-green-dark);
}

/*
|--------------------------------------------------------------------------
| Submit Button
|--------------------------------------------------------------------------
*/

.activation-submit{
    width:100%;
    height:47px;

    margin-top:3px;

    display:inline-flex;

    align-items:center;
    justify-content:center;

    gap:8px;

    border:0;
    border-radius:9px;

    color:#fff;

    background:
        linear-gradient(
            90deg,
            #7fc92d,
            #68aa1d
        );

    box-shadow:
        0 9px 24px
        rgba(104,170,29,.22);

    cursor:pointer;

    font-size:11px;
    font-weight:700;
}

.activation-submit:hover{
    background:
        linear-gradient(
            90deg,
            #74b824,
            #5d971b
        );
}

.activation-submit:disabled{
    opacity:.68;

    cursor:not-allowed;
}

.activation-loader{
    width:14px;
    height:14px;

    display:none;

    border:
        2px dotted
        rgba(255,255,255,.95);

    border-radius:50%;

    animation:
        activationSpin
        .75s linear infinite;
}

.activation-submit.loading
.activation-loader{
    display:inline-block;
}

.activation-submit.loading
.activation-submit-icon{
    display:none;
}

@keyframes activationSpin{

    to{
        transform:
            rotate(360deg);
    }
}

/*
|--------------------------------------------------------------------------
| Login Button After Activation
|--------------------------------------------------------------------------
*/

.activation-login-button{
    width:100%;
    height:47px;

    display:inline-flex;

    align-items:center;
    justify-content:center;

    gap:8px;

    border:0;
    border-radius:9px;

    color:#fff;

    background:
        linear-gradient(
            90deg,
            #7fc92d,
            #68aa1d
        );

    box-shadow:
        0 9px 24px
        rgba(104,170,29,.22);

    font-size:11px;
    font-weight:700;
}

.activation-login-button:hover{
    color:#fff;

    background:
        linear-gradient(
            90deg,
            #74b824,
            #5d971b
        );
}

/*
|--------------------------------------------------------------------------
| Security Footer
|--------------------------------------------------------------------------
*/

.activation-security{
    margin-top:19px;

    display:flex;

    align-items:center;
    justify-content:center;

    gap:6px;

    color:#96a0af;

    font-size:8.5px;
}

.activation-security i{
    color:
        var(--fd-green-dark);

    font-size:11px;
}

/*
|--------------------------------------------------------------------------
| Invalid Activation Link
|--------------------------------------------------------------------------
*/

.activation-invalid-icon{
    width:58px;
    height:58px;

    margin:
        0 auto 17px;

    display:grid;
    place-items:center;

    border-radius:50%;

    color:#b91c1c;

    background:#fff1f2;

    font-size:25px;
}

.activation-invalid-title{
    margin:
        0 0 7px;

    text-align:center;

    color:
        var(--fd-navy);

    font-size:18px;
    font-weight:700;
}

.activation-invalid-text{
    margin:
        0 0 20px;

    text-align:center;

    color:
        var(--fd-muted);

    font-size:10px;
    line-height:1.6;
}

/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media(max-width:820px){

    body{
        padding:0;

        background:#fff;
    }

    .activation-shell{
        width:100%;
        min-height:100vh;

        display:block;

        border:0;
        border-radius:0;

        box-shadow:none;
    }

    .activation-brand-panel{
        display:none;
    }

    .activation-form-panel{
        min-height:100vh;

        max-width:470px;

        margin:auto;

        padding:
            34px 27px;
    }

    .activation-mobile-logo{
        width:48px;
        height:48px;

        margin-bottom:28px;

        display:grid;
        place-items:center;

        border-radius:13px;

        color:#fff;

        background:
            linear-gradient(
                135deg,
                #8fd236,
                #68aa1d
            );

        font-size:20px;
        font-weight:800;
    }
}

@media(max-width:420px){

    .activation-form-panel{
        padding:
            28px 20px;
    }

    .activation-title{
        font-size:22px;
    }
}

</style>

</head>

<body>

<div class="activation-shell">

    <!--
    ================================================================
    LEFT BRANDING PANEL
    ================================================================
    -->

    <section class="activation-brand-panel">

        <div class="activation-brand-content">

            <div class="activation-logo">
                F
            </div>

            <h1 class="activation-brand-title">
                Your FieldPlx workspace is ready.
            </h1>

            <p class="activation-brand-description">
                Complete your account setup to start managing
                customers, jobs, teams, schedules, invoices,
                payments and field operations from one secure
                workspace.
            </p>

            <div class="activation-feature-list">

                <div class="activation-feature">

                    <span class="activation-feature-icon">
                        <i class="bi bi-person-check"></i>
                    </span>

                    Secure account activation

                </div>

                <div class="activation-feature">

                    <span class="activation-feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </span>

                    Tenant, role and branch scoped access

                </div>

                <div class="activation-feature">

                    <span class="activation-feature-icon">
                        <i class="bi bi-briefcase"></i>
                    </span>

                    One workspace for your field operations

                </div>

            </div>

        </div>

        <div class="activation-brand-footer">
            FieldPlx Business Workspace
        </div>

    </section>


    <!--
    ================================================================
    ACTIVATION FORM PANEL
    ================================================================
    -->

    <section class="activation-form-panel">

        <div class="activation-mobile-logo">
            F
        </div>

        <?php if ($success !== ''): ?>

            <!--
            ========================================================
            ACTIVATION SUCCESS
            ========================================================
            -->

            <h2 class="activation-title">
                Account activated
            </h2>

            <p class="activation-subtitle">
                Your account setup is complete.
                You can now sign in to FieldPlx.
            </p>

            <div class="activation-alert success">

                <i class="bi bi-check-circle"></i>

                <span>
                    <?= activationEscape($success) ?>
                </span>

            </div>

            <?php if ($businessName !== '' || $email !== ''): ?>

                <div class="activation-account">

                    <div class="activation-account-icon">
                        <i class="bi bi-building-check"></i>
                    </div>

                    <div class="activation-account-content">

                        <?php if ($businessName !== ''): ?>

                            <div class="activation-business">
                                <?= activationEscape($businessName) ?>
                            </div>

                        <?php endif; ?>

                        <?php if ($email !== ''): ?>

                            <div class="activation-email">
                                <?= activationEscape($email) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>

            <a
                class="activation-login-button"
                href="business/login.php"
            >
                <i class="bi bi-box-arrow-in-right"></i>

                Continue to Login
            </a>

            <div class="activation-security">

                <i class="bi bi-shield-lock"></i>

                Secure tenant-scoped authentication

            </div>

        <?php elseif ($error !== '' && $userId <= 0): ?>

            <!--
            ========================================================
            INVALID / EXPIRED LINK
            ========================================================
            -->

            <div class="activation-invalid-icon">
                <i class="bi bi-link-45deg"></i>
            </div>

            <h2 class="activation-invalid-title">
                Activation link unavailable
            </h2>

            <p class="activation-invalid-text">
                This activation link may be invalid,
                expired or already used.
            </p>

            <div class="activation-alert">

                <i class="bi bi-exclamation-circle"></i>

                <span>
                    <?= activationEscape($error) ?>
                </span>

            </div>

            <a
                class="activation-login-button"
                href="business/login.php"
            >
                <i class="bi bi-box-arrow-in-right"></i>

                Go to FieldPlx Login
            </a>

            <div class="activation-security">

                <i class="bi bi-shield-lock"></i>

                Secure tenant-scoped authentication

            </div>

        <?php else: ?>

            <!--
            ========================================================
            PASSWORD CREATION FORM
            ========================================================
            -->

            <h2 class="activation-title">
                Activate your account
            </h2>

            <p class="activation-subtitle">

                <?php if ($firstName !== ''): ?>

                    Hi <?= activationEscape($firstName) ?>,
                    create your password to activate your
                    FieldPlx workspace.

                <?php else: ?>

                    Create your password to activate your
                    FieldPlx workspace.

                <?php endif; ?>

            </p>

            <?php if ($error !== ''): ?>

                <div class="activation-alert">

                    <i class="bi bi-exclamation-circle"></i>

                    <span>
                        <?= activationEscape($error) ?>
                    </span>

                </div>

            <?php endif; ?>

            <div class="activation-account">

                <div class="activation-account-icon">
                    <i class="bi bi-building"></i>
                </div>

                <div class="activation-account-content">

                    <?php if ($businessName !== ''): ?>

                        <div class="activation-business">
                            <?= activationEscape($businessName) ?>
                        </div>

                    <?php endif; ?>

                    <div class="activation-email">
                        <?= activationEscape($email) ?>
                    </div>

                </div>

            </div>

            <form
                method="post"
                id="activationForm"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    name="activation_action"
                    value="set_password"
                >

                <input
                    type="hidden"
                    name="activation_token"
                    value="<?= activationEscape($token) ?>"
                >

                <input
                    type="hidden"
                    name="activation_csrf"
                    value="<?=
                        activationEscape(
                            $_SESSION['activation_csrf']
                            ?? ''
                        )
                    ?>"
                >


                <!-- New Password -->

                <div class="activation-field">

                    <label for="password">
                        New Password
                    </label>

                    <div class="activation-input-wrap">

                        <i
                            class="bi bi-lock activation-input-icon"
                        ></i>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            class="activation-input"
                            minlength="8"
                            required
                            autocomplete="new-password"
                            placeholder="Create your password"
                        >

                        <button
                            type="button"
                            class="activation-password-toggle"
                            id="passwordToggle"
                            aria-label="Show password"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                    <div class="activation-hint">

                        <i class="bi bi-shield-check"></i>

                        Use at least 8 characters.

                    </div>

                </div>


                <!-- Confirm Password -->

                <div class="activation-field">

                    <label for="confirm_password">
                        Confirm Password
                    </label>

                    <div class="activation-input-wrap">

                        <i
                            class="bi bi-lock-fill activation-input-icon"
                        ></i>

                        <input
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            class="activation-input"
                            minlength="8"
                            required
                            autocomplete="new-password"
                            placeholder="Re-enter your password"
                        >

                        <button
                            type="button"
                            class="activation-password-toggle"
                            id="confirmPasswordToggle"
                            aria-label="Show password"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>


                <!-- Submit -->

                <button
                    class="activation-submit"
                    type="submit"
                    id="activationButton"
                >

                    <span class="activation-loader"></span>

                    <i
                        class="bi bi-check-circle activation-submit-icon"
                    ></i>

                    <span id="activationButtonText">
                        Create Password & Activate
                    </span>

                </button>

            </form>

            <div class="activation-security">

                <i class="bi bi-shield-lock"></i>

                Secure account activation

            </div>

        <?php endif; ?>

    </section>

</div>

<script>

(function(){

'use strict';

/*
|--------------------------------------------------------------------------
| Password Toggle
|--------------------------------------------------------------------------
*/

function setupPasswordToggle(
    inputId,
    buttonId
){

    var input =
        document.getElementById(
            inputId
        );

    var button =
        document.getElementById(
            buttonId
        );

    if(!input || !button){
        return;
    }

    button.addEventListener(
        'click',
        function(){

            var show =
                input.type === 'password';

            input.type =
                show
                    ? 'text'
                    : 'password';

            button.innerHTML =
                show
                    ? '<i class="bi bi-eye-slash"></i>'
                    : '<i class="bi bi-eye"></i>';

            button.setAttribute(
                'aria-label',
                show
                    ? 'Hide password'
                    : 'Show password'
            );

        }
    );
}

setupPasswordToggle(
    'password',
    'passwordToggle'
);

setupPasswordToggle(
    'confirm_password',
    'confirmPasswordToggle'
);


/*
|--------------------------------------------------------------------------
| Form Validation / Loading
|--------------------------------------------------------------------------
*/

var form =
    document.getElementById(
        'activationForm'
    );

var button =
    document.getElementById(
        'activationButton'
    );

var buttonText =
    document.getElementById(
        'activationButtonText'
    );

var password =
    document.getElementById(
        'password'
    );

var confirmPassword =
    document.getElementById(
        'confirm_password'
    );

if(
    form &&
    button &&
    buttonText
){

    form.addEventListener(
        'submit',
        function(event){

            /*
             * Browser already validates minlength.
             * This provides immediate mismatch validation.
             */

            if(
                password &&
                confirmPassword &&
                password.value !==
                    confirmPassword.value
            ){

                event.preventDefault();

                confirmPassword.setCustomValidity(
                    'Passwords do not match.'
                );

                confirmPassword.reportValidity();

                return false;
            }

            if(confirmPassword){
                confirmPassword.setCustomValidity('');
            }

            button.disabled = true;

            button.classList.add(
                'loading'
            );

            buttonText.textContent =
                'Activating account...';

        }
    );

}


/*
|--------------------------------------------------------------------------
| Clear Password Mismatch
|--------------------------------------------------------------------------
*/

if(confirmPassword){

    confirmPassword.addEventListener(
        'input',
        function(){

            confirmPassword.setCustomValidity('');

        }
    );

}

if(password){

    password.addEventListener(
        'input',
        function(){

            if(confirmPassword){
                confirmPassword.setCustomValidity('');
            }

        }
    );

}

})();

</script>

</body>
</html>