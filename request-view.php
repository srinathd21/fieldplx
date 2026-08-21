<?php
/**
 * FieldPlx - Request View
 *
 * Upload as:
 * /public_html/request-view.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'request-view.php?id=' .
            (
                isset($_GET['id'])
                    ? (int) $_GET['id']
                    : 0
            )
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'requests.view',
        'You do not have permission to view requests.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Request Details - FieldPlx';
$activePage = 'requests';
$searchPlaceholder = 'Search requests...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];

$requestId = 0;

if (
    isset($_GET['id']) &&
    (int) $_GET['id'] > 0
) {
    $requestId = (int) $_GET['id'];
} elseif (
    isset($_GET['request_id']) &&
    (int) $_GET['request_id'] > 0
) {
    $requestId = (int) $_GET['request_id'];
}

if ($requestId <= 0) {
    $_SESSION['flash_error'] =
        'Please select a request to view.';

    header('Location: requests.php');
    exit;
}

$canManage = function_exists('hasPermission')
    ? hasPermission('requests.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('requestViewFetchAssoc')) {
    function requestViewFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('requestViewFetchAll')) {
    function requestViewFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('requestViewDate')) {
    function requestViewDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('requestViewDateTime')) {
    function requestViewDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('requestViewLabel')) {
    function requestViewLabel($value)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('requestViewClass')) {
    function requestViewClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load request
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        r.id,
        r.request_no,
        r.client_id,
        r.property_id,
        r.title,
        r.description,
        r.source,
        r.status,
        r.requested_date,
        r.assigned_user_id,
        r.priority,
        r.converted_quote_id,
        r.converted_job_id,
        r.created_by,
        r.created_at,
        r.updated_at,
        r.archived_at,

        c.display_name AS client_name,
        c.company_name AS client_company,
        c.email AS client_email,
        c.phone AS client_phone,
        c.alternate_phone AS client_alternate_phone,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,
        p.country AS property_country,
        p.is_primary AS property_is_primary,
        p.status AS property_status,

        CONCAT(
            COALESCE(au.first_name, ''),
            CASE
                WHEN au.last_name IS NOT NULL
                 AND au.last_name <> ''
                THEN CONCAT(' ', au.last_name)
                ELSE ''
            END
        ) AS assigned_user_name,
        au.email AS assigned_user_email,
        au.phone AS assigned_user_phone,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN CONCAT(' ', cu.last_name)
                ELSE ''
            END
        ) AS created_by_name,

        q.quote_no AS converted_quote_no,
        q.title AS converted_quote_title,
        q.status AS converted_quote_status,
        q.total AS converted_quote_total,

        j.job_no AS converted_job_no,
        j.title AS converted_job_title,
        j.status AS converted_job_status,
        j.total AS converted_job_total

    FROM requests r

    LEFT JOIN clients c
        ON c.id = r.client_id
       AND c.tenant_id = r.tenant_id

    LEFT JOIN properties p
        ON p.id = r.property_id
       AND p.tenant_id = r.tenant_id

    LEFT JOIN users au
        ON au.id = r.assigned_user_id
       AND au.tenant_id = r.tenant_id
       AND au.deleted_at IS NULL

    LEFT JOIN users cu
        ON cu.id = r.created_by
       AND cu.tenant_id = r.tenant_id
       AND cu.deleted_at IS NULL

    LEFT JOIN quotes q
        ON q.id = r.converted_quote_id
       AND q.tenant_id = r.tenant_id

    LEFT JOIN jobs j
        ON j.id = r.converted_job_id
       AND j.tenant_id = r.tenant_id
       AND j.deleted_at IS NULL

    WHERE r.id = ?
      AND r.tenant_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    exit(
        'Unable to prepare request details: ' .
        e($conn->error)
    );
}

$stmt->bind_param(
    'ii',
    $requestId,
    $tenantId
);

$stmt->execute();
$request = requestViewFetchAssoc($stmt);
$stmt->close();

if (!$request) {
    http_response_code(404);
    exit('Request not found.');
}

/*
|--------------------------------------------------------------------------
| Related activity
|--------------------------------------------------------------------------
*/

$activities = array();

$stmt = $conn->prepare("
    SELECT
        ae.id,
        ae.event_type,
        ae.title,
        ae.created_at,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS actor_name
    FROM activity_events ae

    LEFT JOIN users u
        ON u.id = ae.actor_user_id
       AND u.tenant_id = ae.tenant_id
       AND u.deleted_at IS NULL

    WHERE ae.tenant_id = ?
      AND ae.related_type = 'request'
      AND ae.related_id = ?

    ORDER BY ae.created_at DESC, ae.id DESC
    LIMIT 20
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $requestId
    );

    $stmt->execute();
    $activities = requestViewFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Derived values
|--------------------------------------------------------------------------
*/

$propertyTitle = '—';
$propertyAddress = '—';

if (!empty($request['property_id'])) {
    $propertyTitle =
        trim((string) $request['property_name']) !== ''
            ? (string) $request['property_name']
            : (string) $request['property_address_line1'];

    $propertyAddressParts = array_filter(
        array(
            $request['property_address_line1'],
            $request['property_address_line2'],
            $request['property_city'],
            $request['property_state'],
            $request['property_postal_code'],
            $request['property_country']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    );

    $propertyAddress =
        !empty($propertyAddressParts)
            ? implode(', ', $propertyAddressParts)
            : '—';
}

$clientContactParts = array_filter(
    array(
        $request['client_phone'],
        $request['client_alternate_phone'],
        $request['client_email']
    ),
    function ($value) {
        return trim((string) $value) !== '';
    }
);

$clientContact =
    !empty($clientContactParts)
        ? implode(' · ', $clientContactParts)
        : '—';

$assignedContactParts = array_filter(
    array(
        $request['assigned_user_phone'],
        $request['assigned_user_email']
    ),
    function ($value) {
        return trim((string) $value) !== '';
    }
);

$assignedContact =
    !empty($assignedContactParts)
        ? implode(' · ', $assignedContactParts)
        : '—';

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.request-view-page {
    --rv-primary: #6d28d9;
    --rv-primary-soft: #f5f3ff;
    --rv-text: #111827;
    --rv-muted: #6b7280;
    --rv-border: #e5e7eb;
}

.rv-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.rv-heading-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.rv-heading h1 {
    margin: 0;
    color: var(--rv-text);
    font-size: 21px;
    font-weight: 700;
}

.rv-heading p {
    margin: 5px 0 0;
    color: var(--rv-muted);
    font-size: 11px;
}

.rv-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.rv-btn {
    min-height: 35px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid var(--rv-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.rv-btn.primary {
    border-color: var(--rv-primary);
    background: var(--rv-primary);
    color: #fff;
}

.rv-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.rv-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.rv-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.rv-badge {
    padding: 5px 9px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
    text-transform: capitalize;
}

.rv-badge.new,
.rv-badge.assessment_completed,
.rv-badge.converted,
.rv-badge.closed {
    background: #ecfdf5;
    color: #047857;
}

.rv-badge.needs_review,
.rv-badge.assessment_required,
.rv-badge.unscheduled,
.rv-badge.quote_required {
    background: #eff6ff;
    color: #1d4ed8;
}

.rv-badge.overdue,
.rv-badge.rejected,
.rv-badge.urgent {
    background: #fef2f2;
    color: #b91c1c;
}

.rv-badge.high {
    background: #fff7ed;
    color: #c2410c;
}

.rv-badge.low {
    background: #f0fdf4;
    color: #15803d;
}

.rv-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.55fr)
        minmax(300px,.72fr);
    gap: 13px;
    align-items: start;
}

.rv-card {
    overflow: hidden;
    border: 1px solid var(--rv-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.rv-card + .rv-card {
    margin-top: 13px;
}

.rv-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.rv-card-head h2 {
    margin: 0;
    color: var(--rv-text);
    font-size: 11px;
    font-weight: 700;
}

.rv-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.rv-card-body {
    padding: 14px;
}

.rv-detail-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 10px;
}

.rv-detail {
    min-width: 0;
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.rv-detail.full {
    grid-column: 1 / -1;
}

.rv-label {
    display: block;
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.rv-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.rv-value.normal {
    font-weight: 500;
    white-space: pre-wrap;
}

.rv-link {
    color: var(--rv-primary);
    text-decoration: none;
}

.rv-related {
    display: grid;
    gap: 10px;
}

.rv-related-card {
    padding: 12px;
    border: 1px solid #edf0f5;
    border-radius: 10px;
    background: #fafafa;
}

.rv-related-title {
    margin: 0;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
}

.rv-related-meta {
    margin-top: 4px;
    color: #6b7280;
    font-size: 9px;
    line-height: 1.5;
}

.rv-related-actions {
    margin-top: 9px;
}

.rv-timeline {
    display: grid;
    gap: 10px;
}

.rv-timeline-item {
    position: relative;
    padding-left: 18px;
}

.rv-timeline-item::before {
    content: "";
    position: absolute;
    top: 5px;
    left: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--rv-primary);
}

.rv-timeline-title {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.rv-timeline-meta {
    margin-top: 2px;
    color: #9ca3af;
    font-size: 8px;
}

.rv-empty {
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1080px) {
    .rv-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .rv-header {
        flex-direction: column;
    }

    .rv-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .rv-detail-grid {
        grid-template-columns: 1fr;
    }

    .rv-detail.full {
        grid-column: auto;
    }

    .rv-btn {
        flex: 1 1 auto;
    }
}
</style>

<div class="request-view-page">
    <div class="rv-header">
        <div class="rv-heading">
            <div class="rv-heading-row">
                <h1><?= e($request['request_no']); ?></h1>

                <span class="rv-badge <?= e(
                    requestViewClass(
                        $request['status']
                    )
                ); ?>">
                    <?= e(
                        requestViewLabel(
                            $request['status']
                        )
                    ); ?>
                </span>

                <span class="rv-badge <?= e(
                    requestViewClass(
                        $request['priority']
                    )
                ); ?>">
                    <?= e(
                        requestViewLabel(
                            $request['priority']
                        )
                    ); ?>
                </span>
            </div>

            <p><?= e($request['title']); ?></p>
        </div>

        <div class="rv-actions">
            <a href="requests.php" class="rv-btn">
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <?php if (
                $canManage &&
                empty($request['converted_quote_id']) &&
                !in_array(
                    $request['status'],
                    array(
                        'converted',
                        'closed',
                        'rejected',
                        'archived'
                    ),
                    true
                )
            ): ?>
                <a
                    href="quote-add.php?request_id=<?= (int) $requestId; ?>&client_id=<?= (int) $request['client_id']; ?>&property_id=<?= (int) $request['property_id']; ?>"
                    class="rv-btn"
                >
                    <i class="bi bi-file-earmark-text"></i>
                    Create Quote
                </a>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <a
                    href="request-edit.php?id=<?= (int) $requestId; ?>"
                    class="rv-btn primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Request
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="rv-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="rv-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="rv-layout">
        <main>
            <section class="rv-card">
                <div class="rv-card-head">
                    <h2>Request Information</h2>
                    <p>
                        Client, property, request source, status, and assignment.
                    </p>
                </div>

                <div class="rv-card-body">
                    <div class="rv-detail-grid">
                        <div class="rv-detail">
                            <span class="rv-label">Client</span>

                            <span class="rv-value">
                                <?php if (!empty($request['client_id'])): ?>
                                    <a
                                        href="client-view.php?id=<?= (int) $request['client_id']; ?>"
                                        class="rv-link"
                                    >
                                        <?= e(
                                            trim((string) $request['client_name']) !== ''
                                                ? $request['client_name']
                                                : 'Unnamed Client'
                                        ); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Client Contact</span>
                            <span class="rv-value normal">
                                <?= e($clientContact); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Property</span>

                            <span class="rv-value">
                                <?php if (!empty($request['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $request['property_id']; ?>"
                                        class="rv-link"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Property Address</span>
                            <span class="rv-value normal">
                                <?= e($propertyAddress); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Source</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewLabel(
                                        $request['source']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Requested Date</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewDate(
                                        $request['requested_date']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Assigned To</span>
                            <span class="rv-value">
                                <?= e(
                                    trim(
                                        (string) $request['assigned_user_name']
                                    ) !== ''
                                        ? $request['assigned_user_name']
                                        : 'Unassigned'
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Assigned User Contact</span>
                            <span class="rv-value normal">
                                <?= e($assignedContact); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Created By</span>
                            <span class="rv-value">
                                <?= e(
                                    trim(
                                        (string) $request['created_by_name']
                                    ) !== ''
                                        ? $request['created_by_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail">
                            <span class="rv-label">Created</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewDateTime(
                                        $request['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail full">
                            <span class="rv-label">Description</span>
                            <span class="rv-value normal"><?= e(
                                trim((string) $request['description']) !== ''
                                    ? $request['description']
                                    : 'No description provided.'
                            ); ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rv-card">
                <div class="rv-card-head">
                    <h2>Activity Timeline</h2>
                    <p>
                        Recent request-related activity.
                    </p>
                </div>

                <div class="rv-card-body">
                    <?php if (!empty($activities)): ?>
                        <div class="rv-timeline">
                            <?php foreach ($activities as $activity): ?>
                                <div class="rv-timeline-item">
                                    <div class="rv-timeline-title">
                                        <?= e($activity['title']); ?>
                                    </div>

                                    <div class="rv-timeline-meta">
                                        <?= e(
                                            requestViewDateTime(
                                                $activity['created_at']
                                            )
                                        ); ?>

                                        <?php if (
                                            trim(
                                                (string) $activity['actor_name']
                                            ) !== ''
                                        ): ?>
                                            · <?= e($activity['actor_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="rv-empty">
                            No request activity found.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <aside>
            <section class="rv-card">
                <div class="rv-card-head">
                    <h2>Request Summary</h2>
                    <p>
                        Current request state and key dates.
                    </p>
                </div>

                <div class="rv-card-body">
                    <div class="rv-detail-grid">
                        <div class="rv-detail full">
                            <span class="rv-label">Status</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewLabel(
                                        $request['status']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail full">
                            <span class="rv-label">Priority</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewLabel(
                                        $request['priority']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail full">
                            <span class="rv-label">Last Updated</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewDateTime(
                                        $request['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="rv-detail full">
                            <span class="rv-label">Archived At</span>
                            <span class="rv-value">
                                <?= e(
                                    requestViewDateTime(
                                        $request['archived_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (
                !empty($request['converted_quote_id']) ||
                !empty($request['converted_job_id'])
            ): ?>
                <section class="rv-card">
                    <div class="rv-card-head">
                        <h2>Converted Records</h2>
                        <p>
                            Quote or job created from this request.
                        </p>
                    </div>

                    <div class="rv-card-body">
                        <div class="rv-related">
                            <?php if (
                                !empty($request['converted_quote_id'])
                            ): ?>
                                <div class="rv-related-card">
                                    <h3 class="rv-related-title">
                                        <?= e($request['converted_quote_no']); ?>
                                    </h3>

                                    <div class="rv-related-meta">
                                        <?= e($request['converted_quote_title']); ?>
                                        ·
                                        <?= e(
                                            requestViewLabel(
                                                $request['converted_quote_status']
                                            )
                                        ); ?>
                                    </div>

                                    <div class="rv-related-actions">
                                        <a
                                            href="quote-view.php?id=<?= (int) $request['converted_quote_id']; ?>"
                                            class="rv-btn primary"
                                            style="width:100%;"
                                        >
                                            <i class="bi bi-receipt"></i>
                                            View Quote
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                !empty($request['converted_job_id'])
                            ): ?>
                                <div class="rv-related-card">
                                    <h3 class="rv-related-title">
                                        <?= e($request['converted_job_no']); ?>
                                    </h3>

                                    <div class="rv-related-meta">
                                        <?= e($request['converted_job_title']); ?>
                                        ·
                                        <?= e(
                                            requestViewLabel(
                                                $request['converted_job_status']
                                            )
                                        ); ?>
                                    </div>

                                    <div class="rv-related-actions">
                                        <a
                                            href="job-view.php?id=<?= (int) $request['converted_job_id']; ?>"
                                            class="rv-btn primary"
                                            style="width:100%;"
                                        >
                                            <i class="bi bi-briefcase"></i>
                                            View Job
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
