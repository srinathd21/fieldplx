<?php
/**
 * FieldPlx - Account Activity
 * File: business/account-activity.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Account Activity · FieldPlx';
$pageDescription = 'Review permission, administrator access, and team account changes';
$settingsActivePage = 'account-activity';

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

$activityRows = array();
$activityError = '';

/**
 * URL pagination.
 * Examples:
 *   account-activity.php?page=1
 *   account-activity.php?page=2
 */
$activityPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($activityPage < 1) $activityPage = 1;

$perPage = 20;
$totalActivities = 0;
$totalPages = 1;
$offset = 0;

/**
 * Compact display helpers.
 */
function aa_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function aa_initials($name)
{
    $name = trim((string)$name);
    if ($name === '') return 'SY';

    $parts = preg_split('/\s+/', $name);
    $first = isset($parts[0][0]) ? $parts[0][0] : '';
    $last = count($parts) > 1 && isset($parts[count($parts)-1][0])
        ? $parts[count($parts)-1][0]
        : '';

    $initials = strtoupper($first . $last);
    return $initials !== '' ? $initials : 'US';
}

function aa_format_date($value)
{
    if (!$value) return '';

    $time = strtotime($value);
    if (!$time) return (string)$value;

    return date('M j, g:i A', $time);
}

function aa_human_action($row)
{
    $action = isset($row['action']) ? strtoupper((string)$row['action']) : '';
    $actor = trim(isset($row['user_name']) ? (string)$row['user_name'] : '');
    $record = trim(isset($row['record_no']) ? (string)$row['record_no'] : '');
    $type = trim(isset($row['object_type']) ? (string)$row['object_type'] : '');

    if ($actor === '') $actor = 'System';

    $map = array(
        'TEAM_MEMBER_INVITED' => $actor . ' invited a team member',
        'TEAM_MEMBER_UPDATED' => $actor . ' updated a team member',
        'TEAM_MEMBER_SESSION_REVOKED' => $actor . ' signed out a team member session',
        'TEAM_MEMBER_SESSIONS_REVOKED' => $actor . ' signed out all team member sessions',
        'ROLE_CREATED' => $actor . ' created a role',
        'ROLE_UPDATED' => $actor . ' updated a role',
        'ROLE_STATUS_CHANGED' => $actor . ' changed a role status',
        'PERMISSION_UPDATED' => $actor . ' updated permissions',
        'USER_PERMISSION_UPDATED' => $actor . ' updated user permissions',
        'ADMIN_ACCESS_GRANTED' => $actor . ' granted administrator access',
        'ADMIN_ACCESS_REMOVED' => $actor . ' removed administrator access',
        'EMPLOYEE_CREATED' => $actor . ' added a team member',
        'EMPLOYEE_UPDATED' => $actor . ' updated a team member'
    );

    if (isset($map[$action])) {
        return $record !== '' ? $map[$action] . ' · ' . $record : $map[$action];
    }

    if ($action !== '') {
        $label = strtolower(str_replace('_', ' ', $action));
        $label = ucfirst($label);
        return $actor . ' · ' . $label;
    }

    return $actor . ($type !== '' ? ' changed ' . $type : ' made an account change');
}

/**
 * Load only account/team/permission related audit activity.
 * This page intentionally stays read-only.
 */
if ($tenantId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $whereSql = "
            tenant_id = :tenant_id
            AND (
                module = 'Administration'
                OR object_type IN ('user','role','permission','tenant_user')
                OR action LIKE 'TEAM_MEMBER_%'
                OR action LIKE 'ROLE_%'
                OR action LIKE '%PERMISSION%'
                OR action LIKE '%ADMIN_ACCESS%'
                OR action LIKE 'EMPLOYEE_%'
            )
        ";

        // Count first so URL pagination can be calculated correctly.
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE " . $whereSql);
        $countStmt->execute(array(':tenant_id' => $tenantId));
        $totalActivities = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalActivities / $perPage));

        // If an invalid/high page is passed in the URL, use the last valid page.
        if ($activityPage > $totalPages) {
            $activityPage = $totalPages;
        }

        $offset = ($activityPage - 1) * $perPage;

        $sql = "
            SELECT
                id,
                user_id,
                user_name,
                audit_category,
                module,
                action,
                object_type,
                object_id,
                record_no,
                created_at
            FROM audit_logs
            WHERE " . $whereSql . "
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $activityRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('FieldPlx account activity load error: ' . $e->getMessage());
        $activityError = 'Unable to load account activity right now.';
    }
}

require __DIR__ . '/includes/header.php';

if (file_exists(__DIR__ . '/includes/toast.php')) {
    require_once __DIR__ . '/includes/toast.php';
}
?>

<style>
.account-activity-page{
    display:grid;
    grid-template-columns:250px minmax(0,1fr);
    gap:28px;
    max-width:1240px;
    margin:0 auto;
    padding:0 0 40px;
    align-items:start;
}
.account-activity-nav{
    min-width:0;
}
.account-activity-main{
    min-width:0;
}
.account-activity-head{
    margin:0 0 18px;
}
.account-activity-head h1{
    margin:0;
    color:var(--fieldplx-text,#0b1933);
    font-size:30px;
    line-height:1.16;
    font-weight:700;
    letter-spacing:-.01em;
}
.account-activity-head p{
    margin:12px 0 0;
    color:var(--fieldplx-muted,#6f7b90);
    font-size:13px;
    line-height:1.55;
    max-width:720px;
}
.account-activity-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:0 0 12px;
}
.account-activity-summary{
    color:#6f7b90;
    font-size:11px;
}
.account-activity-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:10px;
    overflow:hidden;
}
.account-activity-row{
    display:flex;
    align-items:flex-start;
    gap:12px;
    min-height:62px;
    padding:13px 15px;
    border-bottom:1px solid #edf0f2;
    transition:background .16s ease;
}
.account-activity-row:last-child{
    border-bottom:0;
}
.account-activity-row:hover{
    background:#fbfcfd;
}
.account-activity-avatar{
    width:30px;
    height:30px;
    flex:0 0 30px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#153e4c;
    color:#fff;
    font-size:9px;
    font-weight:700;
    letter-spacing:.02em;
    margin-top:1px;
}
.account-activity-avatar.system{
    background:#234c59;
}
.account-activity-copy{
    min-width:0;
    flex:1 1 auto;
    padding-top:1px;
}
.account-activity-title{
    margin:0;
    color:#14394a;
    font-size:12px;
    line-height:1.35;
    font-weight:600;
}
.account-activity-meta{
    display:flex;
    align-items:center;
    gap:7px;
    flex-wrap:wrap;
    margin-top:3px;
    color:#80909b;
    font-size:10px;
    line-height:1.35;
}
.account-activity-dot{
    width:3px;
    height:3px;
    border-radius:50%;
    background:#b2bcc4;
}
.account-activity-badge{
    display:inline-flex;
    align-items:center;
    min-height:20px;
    padding:0 8px;
    border-radius:999px;
    background:#f1f5ed;
    color:#5d8e28;
    font-size:9px;
    font-weight:600;
    white-space:nowrap;
}
.account-activity-empty,
.account-activity-error{
    padding:36px 20px;
    text-align:center;
}
.account-activity-empty-icon{
    width:46px;
    height:46px;
    margin:0 auto 11px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#f3f5f6;
    color:#667d89;
}
.account-activity-empty-icon svg{
    width:20px;
    height:20px;
}
.account-activity-empty strong,
.account-activity-error strong{
    display:block;
    color:#0b1933;
    font-size:12px;
    margin-bottom:5px;
}
.account-activity-empty p,
.account-activity-error p{
    margin:0;
    color:#6f7b90;
    font-size:11px;
    line-height:1.5;
}
.account-activity-error{
    color:#a13e3e;
}

.account-activity-pagination{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    margin-top:14px;
}
.account-activity-page-info{
    color:#6f7b90;
    font-size:10px;
    line-height:1.35;
}
.account-activity-pager{
    display:flex;
    align-items:center;
    gap:6px;
}
.account-activity-page-btn{
    min-width:32px;
    height:32px;
    padding:0 9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #dce3eb;
    border-radius:8px;
    background:#fff;
    color:#294858;
    font-size:10px;
    font-weight:600;
    line-height:1;
    text-decoration:none;
    transition:background .16s ease,border-color .16s ease,color .16s ease;
}
.account-activity-page-btn:hover{
    border-color:#74b824;
    color:#5d971b;
}
.account-activity-page-btn.active{
    background:#2d8d24;
    border-color:#2d8d24;
    color:#fff;
}
.account-activity-page-btn.disabled{
    opacity:.45;
    pointer-events:none;
}
.account-activity-page-btn svg{
    width:13px;
    height:13px;
}
@media(max-width:980px){
    .account-activity-page{
        grid-template-columns:1fr;
        padding:0 14px 30px;
    }
    .account-activity-nav{
        display:none;
    }
}
@media(max-width:640px){
    .account-activity-head h1{
        font-size:25px;
    }
    .account-activity-row{
        padding:12px;
    }
    .account-activity-pagination{
        align-items:flex-start;
        flex-direction:column;
    }
    .account-activity-pager{
        width:100%;
        justify-content:flex-end;
        flex-wrap:wrap;
    }
}
</style>

<div class="account-activity-page">
    <div class="account-activity-nav">
        <?php
        if (file_exists(__DIR__ . '/includes/settings-nav.php')) {
            require __DIR__ . '/includes/settings-nav.php';
        }
        ?>
    </div>

    <main class="account-activity-main">
        <div class="account-activity-head">
            <h1>Account Activity</h1>
            <p>A record of permission, administrator access, and team changes made in your account.</p>
        </div>

        <div class="account-activity-toolbar">
            <div class="account-activity-summary">
                <?= (int)$totalActivities ?> <?= $totalActivities === 1 ? 'activity' : 'activities' ?>
            </div>
        </div>

        <section class="account-activity-card">
            <?php if ($activityError !== ''): ?>
                <div class="account-activity-error">
                    <strong>Unable to load account activity</strong>
                    <p><?= aa_e($activityError) ?></p>
                </div>
            <?php elseif (empty($activityRows)): ?>
                <div class="account-activity-empty">
                    <div class="account-activity-empty-icon">
                        <i data-lucide="history"></i>
                    </div>
                    <strong>No account activity yet</strong>
                    <p>Permission, administrator access, and team changes will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($activityRows as $row): ?>
                    <?php
                    $actor = trim(isset($row['user_name']) ? (string)$row['user_name'] : '');
                    $isSystem = $actor === '';
                    $displayActor = $isSystem ? 'System' : $actor;
                    $module = trim(isset($row['module']) ? (string)$row['module'] : '');
                    ?>
                    <article class="account-activity-row">
                        <div class="account-activity-avatar<?= $isSystem ? ' system' : '' ?>">
                            <?= aa_e($isSystem ? 'SY' : aa_initials($displayActor)) ?>
                        </div>

                        <div class="account-activity-copy">
                            <p class="account-activity-title">
                                <?= aa_e(aa_human_action($row)) ?>
                            </p>

                            <div class="account-activity-meta">
                                <span><?= aa_e(aa_format_date(isset($row['created_at']) ? $row['created_at'] : '')) ?></span>

                                <?php if ($module !== ''): ?>
                                    <span class="account-activity-dot"></span>
                                    <span><?= aa_e($module) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($row['object_type'])): ?>
                            <span class="account-activity-badge">
                                <?= aa_e(ucwords(str_replace('_', ' ', (string)$row['object_type']))) ?>
                            </span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <?php if ($activityError === '' && $totalActivities > 0): ?>
            <?php
            $fromItem = $offset + 1;
            $toItem = min($offset + $perPage, $totalActivities);

            // Keep any existing GET parameters and change only page.
            $pageUrl = function ($page) {
                $params = $_GET;
                $params['page'] = (int)$page;
                return 'account-activity.php?' . http_build_query($params);
            };

            // Compact page number window.
            $startPage = max(1, $activityPage - 2);
            $endPage = min($totalPages, $activityPage + 2);

            if (($endPage - $startPage) < 4) {
                if ($startPage === 1) {
                    $endPage = min($totalPages, 5);
                } elseif ($endPage === $totalPages) {
                    $startPage = max(1, $totalPages - 4);
                }
            }
            ?>

            <nav class="account-activity-pagination" aria-label="Account activity pagination">
                <div class="account-activity-page-info">
                    Showing <?= (int)$fromItem ?>-<?= (int)$toItem ?> of <?= (int)$totalActivities ?> activities
                </div>

                <div class="account-activity-pager">
                    <a
                        class="account-activity-page-btn<?= $activityPage <= 1 ? ' disabled' : '' ?>"
                        href="<?= $activityPage > 1 ? aa_e($pageUrl($activityPage - 1)) : '#' ?>"
                        aria-label="Previous page"
                        <?= $activityPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        <i data-lucide="chevron-left"></i>
                    </a>

                    <?php if ($startPage > 1): ?>
                        <a class="account-activity-page-btn" href="<?= aa_e($pageUrl(1)) ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="account-activity-page-info" aria-hidden="true">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                        <a
                            class="account-activity-page-btn<?= $pageNo === $activityPage ? ' active' : '' ?>"
                            href="<?= aa_e($pageUrl($pageNo)) ?>"
                            <?= $pageNo === $activityPage ? 'aria-current="page"' : '' ?>
                        >
                            <?= (int)$pageNo ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < ($totalPages - 1)): ?>
                            <span class="account-activity-page-info" aria-hidden="true">…</span>
                        <?php endif; ?>
                        <a class="account-activity-page-btn" href="<?= aa_e($pageUrl($totalPages)) ?>">
                            <?= (int)$totalPages ?>
                        </a>
                    <?php endif; ?>

                    <a
                        class="account-activity-page-btn<?= $activityPage >= $totalPages ? ' disabled' : '' ?>"
                        href="<?= $activityPage < $totalPages ? aa_e($pageUrl($activityPage + 1)) : '#' ?>"
                        aria-label="Next page"
                        <?= $activityPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                    >
                        <i data-lucide="chevron-right"></i>
                    </a>
                </div>
            </nav>
        <?php endif; ?>
    </main>
</div>

<script>
(function(){
    'use strict';

    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
