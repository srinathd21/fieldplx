<?php
/**
 * FieldPlx - Job View
 *
 * Upload as:
 * /public_html/job-view.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and access
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'job-view.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.view',
        'You do not have permission to view jobs.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Job Details - FieldPlx';
$activePage = 'jobs';
$searchPlaceholder = 'Search jobs...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$jobId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($jobId <= 0) {
    http_response_code(400);
    exit('A valid job ID is required.');
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('jobViewFetchAssoc')) {
    function jobViewFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('jobViewFetchAll')) {
    function jobViewFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('jobViewMoney')) {
    function jobViewMoney($value)
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ','
        );
    }
}

if (!function_exists('jobViewDate')) {
    function jobViewDate($value)
    {
        if (
            $value === null ||
            $value === '' ||
            $value === '0000-00-00'
        ) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('jobViewDateTime')) {
    function jobViewDateTime($value)
    {
        if (
            $value === null ||
            $value === ''
        ) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('jobViewStatusLabel')) {
    function jobViewStatusLabel($status)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $status
            )
        );
    }
}

if (!function_exists('jobViewStatusClass')) {
    function jobViewStatusClass($status)
    {
        $status = strtolower((string) $status);

        if (
            in_array(
                $status,
                array(
                    'active',
                    'scheduled',
                    'upcoming',
                    'today',
                    'in_progress'
                ),
                true
            )
        ) {
            return 'info';
        }

        if (
            in_array(
                $status,
                array(
                    'completed',
                    'closed',
                    'invoiced',
                    'ready_to_invoice'
                ),
                true
            )
        ) {
            return 'success';
        }

        if (
            in_array(
                $status,
                array(
                    'late',
                    'action_required',
                    'needs_review',
                    'requires_invoicing'
                ),
                true
            )
        ) {
            return 'warning';
        }

        if (
            in_array(
                $status,
                array(
                    'cancelled',
                    'archived'
                ),
                true
            )
        ) {
            return 'danger';
        }

        return 'neutral';
    }
}

/*
|--------------------------------------------------------------------------
| Load job
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        j.id,
        j.job_no,
        j.client_id,
        j.property_id,
        j.quote_id,
        j.request_id,
        j.title,
        j.description,
        j.job_type,
        j.status,
        j.assigned_user_id,
        j.start_date,
        j.end_date,
        j.recurrence_rule,
        j.invoicing_preference,
        j.subtotal,
        j.tax_total,
        j.total,
        j.created_by,
        j.completed_at,
        j.closed_at,
        j.created_at,
        j.updated_at,

        c.display_name AS client_name,
        c.phone AS client_phone,
        c.email AS client_email,

        p.name AS property_name,
        p.address_line1,
        p.address_line2,
        p.city,
        p.state,
        p.postal_code,

        r.request_no,
        r.title AS request_title,

        q.quote_no,
        q.title AS quote_title,

        CONCAT(
            COALESCE(au.first_name, ''),
            CASE
                WHEN au.first_name IS NOT NULL
                 AND au.first_name <> ''
                 AND au.last_name IS NOT NULL
                 AND au.last_name <> ''
                THEN ' '
                ELSE ''
            END,
            COALESCE(au.last_name, '')
        ) AS assigned_user_name,
        au.email AS assigned_user_email,
        au.phone AS assigned_user_phone,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.first_name IS NOT NULL
                 AND cu.first_name <> ''
                 AND cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN ' '
                ELSE ''
            END,
            COALESCE(cu.last_name, '')
        ) AS created_by_name
    FROM jobs j
    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
       AND c.deleted_at IS NULL
    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
       AND p.deleted_at IS NULL
    LEFT JOIN requests r
        ON r.id = j.request_id
       AND r.tenant_id = j.tenant_id
    LEFT JOIN quotes q
        ON q.id = j.quote_id
       AND q.tenant_id = j.tenant_id
    LEFT JOIN users au
        ON au.id = j.assigned_user_id
       AND au.tenant_id = j.tenant_id
       AND au.deleted_at IS NULL
    LEFT JOIN users cu
        ON cu.id = j.created_by
       AND cu.tenant_id = j.tenant_id
       AND cu.deleted_at IS NULL
    WHERE j.id = ?
      AND j.tenant_id = ?
      AND j.deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit(
        'Unable to prepare the job view query: ' .
        $conn->error
    );
}

$stmt->bind_param(
    'ii',
    $jobId,
    $tenantId
);

$stmt->execute();
$job = jobViewFetchAssoc($stmt);
$stmt->close();

if (!$job) {
    http_response_code(404);
    exit('Job not found.');
}

/*
|--------------------------------------------------------------------------
| Load job line items
|--------------------------------------------------------------------------
*/

$lineItems = array();

$stmt = $conn->prepare("
    SELECT
        jli.id,
        jli.product_service_id,
        jli.item_name,
        jli.description,
        jli.quantity,
        jli.unit_cost,
        jli.unit_price,
        jli.tax_rate_id,
        jli.tax_amount,
        jli.line_total,
        jli.sort_order,
        ps.item_type,
        ps.unit_name,
        tr.name AS tax_name,
        tr.rate AS tax_rate
    FROM job_line_items jli
    LEFT JOIN product_services ps
        ON ps.id = jli.product_service_id
       AND ps.tenant_id = jli.tenant_id
    LEFT JOIN tax_rates tr
        ON tr.id = jli.tax_rate_id
       AND tr.tenant_id = jli.tenant_id
    WHERE jli.job_id = ?
      AND jli.tenant_id = ?
    ORDER BY
        jli.sort_order ASC,
        jli.id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $jobId,
        $tenantId
    );

    $stmt->execute();
    $lineItems = jobViewFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load assignments
|--------------------------------------------------------------------------
*/

$assignments = array();

$stmt = $conn->prepare("
    SELECT
        ja.id,
        ja.assignment_role,
        ja.status,
        ja.assigned_at,
        ja.accepted_at,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.first_name IS NOT NULL
                 AND u.first_name <> ''
                 AND u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN ' '
                ELSE ''
            END,
            COALESCE(u.last_name, '')
        ) AS user_name,
        u.email,
        u.phone,
        u.is_field_worker
    FROM job_assignments ja
    INNER JOIN users u
        ON u.id = ja.user_id
       AND u.tenant_id = ja.tenant_id
       AND u.deleted_at IS NULL
    WHERE ja.job_id = ?
      AND ja.tenant_id = ?
      AND ja.removed_at IS NULL
    ORDER BY
        CASE
            WHEN ja.assignment_role = 'primary' THEN 0
            ELSE 1
        END,
        ja.assigned_at ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $jobId,
        $tenantId
    );

    $stmt->execute();
    $assignments = jobViewFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Display values
|--------------------------------------------------------------------------
*/

$propertyAddressParts = array();

foreach (
    array(
        $job['address_line1'],
        $job['address_line2'],
        $job['city'],
        $job['state'],
        $job['postal_code']
    ) as $addressPart
) {
    $addressPart = trim((string) $addressPart);

    if ($addressPart !== '') {
        $propertyAddressParts[] = $addressPart;
    }
}

$propertyAddress = !empty($propertyAddressParts)
    ? implode(', ', $propertyAddressParts)
    : '—';

$assignedUserName =
    trim((string) $job['assigned_user_name']);

$createdByName =
    trim((string) $job['created_by_name']);

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.job-view-page {
    --jv-primary: #6d28d9;
    --jv-primary-dark: #4c1d95;
    --jv-soft: #f5f3ff;
    --jv-text: #111827;
    --jv-muted: #6b7280;
    --jv-border: #e5e7eb;
}

.jv-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.jv-title-wrap {
    display: flex;
    align-items: flex-start;
    gap: 11px;
}

.jv-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(
        135deg,
        var(--jv-primary),
        var(--jv-primary-dark)
    );
    color: #fff;
    font-size: 18px;
    box-shadow: 0 10px 22px rgba(109,40,217,.2);
}

.jv-header h1 {
    margin: 0;
    color: var(--jv-text);
    font-size: 21px;
    font-weight: 800;
}

.jv-header p {
    margin: 5px 0 0;
    color: var(--jv-muted);
    font-size: 10px;
}

.jv-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.jv-btn {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.jv-btn.secondary {
    border: 1px solid var(--jv-border);
    background: #fff;
    color: #374151;
}

.jv-btn.primary {
    border: 0;
    background: linear-gradient(
        135deg,
        var(--jv-primary),
        var(--jv-primary-dark)
    );
    color: #fff;
}

.jv-layout {
    display: block;
}

.jv-side-grid {
    margin-top: 13px;
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 13px;
    align-items: stretch;
}

.jv-side-grid .jv-card {
    height: 100%;
}

.jv-side-grid .jv-body {
    height: calc(100% - 58px);
}

.jv-card {
    overflow: hidden;
    border: 1px solid var(--jv-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(15,23,42,.04);
}

.jv-card + .jv-card {
    margin-top: 13px;
}

.jv-side-grid .jv-card + .jv-card {
    margin-top: 0;
}

.jv-card-head {
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #eef0f4;
}

.jv-card-head h2 {
    margin: 0;
    color: var(--jv-text);
    font-size: 11px;
    font-weight: 800;
}

.jv-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.jv-body {
    padding: 14px;
}

.jv-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 10px;
}

.jv-detail {
    min-width: 0;
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.jv-detail.full {
    grid-column: 1 / -1;
}

.jv-detail-label {
    display: block;
    margin-bottom: 4px;
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.jv-detail-value {
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.jv-side-grid .jv-grid {
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

.jv-side-grid .jv-detail.full {
    grid-column: 1 / -1;
}

.jv-description {
    margin: 0;
    color: #4b5563;
    font-size: 10px;
    line-height: 1.7;
    white-space: pre-wrap;
}

.jv-status {
    padding: 5px 9px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.jv-status.info {
    background: #eff6ff;
    color: #1d4ed8;
}

.jv-status.success {
    background: #ecfdf5;
    color: #047857;
}

.jv-status.warning {
    background: #fffbeb;
    color: #b45309;
}

.jv-status.danger {
    background: #fef2f2;
    color: #b91c1c;
}

.jv-status.neutral {
    background: #f3f4f6;
    color: #4b5563;
}

.jv-table-wrap {
    width: 100%;
    overflow: visible;
}

.jv-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.jv-table th,
.jv-table td {
    padding: 9px 7px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
    overflow-wrap: anywhere;
}

.jv-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.jv-table td {
    color: #374151;
    font-size: 9px;
}

.jv-table th:nth-child(1),
.jv-table td:nth-child(1) {
    width: 29%;
}

.jv-table th:nth-child(2),
.jv-table td:nth-child(2) {
    width: 10%;
}

.jv-table th:nth-child(3),
.jv-table td:nth-child(3),
.jv-table th:nth-child(4),
.jv-table td:nth-child(4) {
    width: 14%;
}

.jv-table th:nth-child(5),
.jv-table td:nth-child(5) {
    width: 15%;
}

.jv-table th:nth-child(6),
.jv-table td:nth-child(6) {
    width: 18%;
}

.jv-item-name {
    display: block;
    color: #111827;
    font-weight: 800;
}

.jv-item-note {
    display: block;
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.jv-summary {
    display: grid;
    gap: 9px;
}

.jv-summary-row {
    padding: 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #4b5563;
    font-size: 9px;
}

.jv-summary-row strong {
    color: #111827;
}

.jv-summary-row.total {
    border-color: #ddd6fe;
    background: var(--jv-soft);
    color: var(--jv-primary-dark);
    font-size: 11px;
    font-weight: 800;
}

.jv-person {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.jv-person + .jv-person {
    margin-top: 8px;
}

.jv-person-name {
    color: #111827;
    font-size: 9px;
    font-weight: 800;
}

.jv-person-meta {
    margin-top: 4px;
    color: #6b7280;
    font-size: 8px;
    line-height: 1.5;
}

.jv-empty {
    padding: 20px;
    color: #9ca3af;
    font-size: 9px;
    text-align: center;
}

@media (max-width: 820px) {
    .jv-side-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .jv-side-grid .jv-grid {
        grid-template-columns: 1fr;
    }

    .jv-side-grid .jv-detail.full {
        grid-column: auto;
    }

    .jv-header {
        flex-direction: column;
    }

    .jv-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .jv-grid {
        grid-template-columns: 1fr;
    }

    .jv-detail.full {
        grid-column: auto;
    }

    .jv-table,
    .jv-table tbody,
    .jv-table tr,
    .jv-table td {
        display: block;
        width: 100%;
    }

    .jv-table thead {
        display: none;
    }

    .jv-table tr {
        padding: 10px;
        border-bottom: 1px solid #e5e7eb;
    }

    .jv-table td {
        padding: 5px 0;
        border: 0;
    }

    .jv-table td::before {
        margin-bottom: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .jv-table td:nth-child(1)::before {
        content: 'Item';
    }

    .jv-table td:nth-child(2)::before {
        content: 'Qty';
    }

    .jv-table td:nth-child(3)::before {
        content: 'Unit Cost';
    }

    .jv-table td:nth-child(4)::before {
        content: 'Unit Price';
    }

    .jv-table td:nth-child(5)::before {
        content: 'Tax';
    }

    .jv-table td:nth-child(6)::before {
        content: 'Line Total';
    }
}
</style>

<div class="job-view-page">
    <div class="jv-header">
        <div class="jv-title-wrap">
            <div class="jv-icon">
                <i class="bi bi-briefcase"></i>
            </div>

            <div>
                <h1><?= e($job['job_no']); ?></h1>
                <p><?= e($job['title']); ?></p>
            </div>
        </div>

        <div class="jv-actions">
            <a
                href="jobs.php"
                class="jv-btn secondary"
            >
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <a
                href="job-edit.php?id=<?= (int) $jobId; ?>"
                class="jv-btn primary"
            >
                <i class="bi bi-pencil-square"></i>
                Edit Job
            </a>
        </div>
    </div>

    <div class="jv-layout">
        <main>
            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Job Information</h2>
                        <p>
                            Main job details and scheduling information.
                        </p>
                    </div>

                    <span class="jv-status <?= e(
                        jobViewStatusClass(
                            $job['status']
                        )
                    ); ?>">
                        <?= e(
                            jobViewStatusLabel(
                                $job['status']
                            )
                        ); ?>
                    </span>
                </div>

                <div class="jv-body">
                    <div class="jv-grid">
                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Job Type
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewStatusLabel(
                                        $job['job_type']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Invoicing Preference
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewStatusLabel(
                                        $job[
                                            'invoicing_preference'
                                        ]
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Start Date
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewDate(
                                        $job['start_date']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                End Date
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewDate(
                                        $job['end_date']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <?php if (
                            !empty(
                                $job['recurrence_rule']
                            )
                        ): ?>
                            <div class="jv-detail full">
                                <span class="jv-detail-label">
                                    Recurrence Rule
                                </span>
                                <span class="jv-detail-value">
                                    <?= e(
                                        $job['recurrence_rule']
                                    ); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Description
                            </span>

                            <p class="jv-description"><?= e(
                                trim(
                                    (string) $job['description']
                                ) !== ''
                                    ? $job['description']
                                    : 'No description added.'
                            ); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Job Items</h2>
                        <p>
                            Products, services, materials, and fees.
                        </p>
                    </div>
                </div>

                <?php if (!empty($lineItems)): ?>
                    <div class="jv-table-wrap">
                        <table class="jv-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Unit Price</th>
                                    <th>Tax</th>
                                    <th>Line Total</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($lineItems as $item): ?>
                                    <tr>
                                        <td>
                                            <span class="jv-item-name">
                                                <?= e(
                                                    $item[
                                                        'item_name'
                                                    ]
                                                ); ?>
                                            </span>

                                            <?php if (
                                                trim(
                                                    (string) $item[
                                                        'description'
                                                    ]
                                                ) !== ''
                                            ): ?>
                                                <span class="jv-item-note">
                                                    <?= e(
                                                        $item[
                                                            'description'
                                                        ]
                                                    ); ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (
                                                !empty(
                                                    $item[
                                                        'unit_name'
                                                    ]
                                                )
                                            ): ?>
                                                <span class="jv-item-note">
                                                    Unit:
                                                    <?= e(
                                                        $item[
                                                            'unit_name'
                                                        ]
                                                    ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                rtrim(
                                                    rtrim(
                                                        number_format(
                                                            (float) $item[
                                                                'quantity'
                                                            ],
                                                            3,
                                                            '.',
                                                            ''
                                                        ),
                                                        '0'
                                                    ),
                                                    '.'
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            ₹<?= e(
                                                jobViewMoney(
                                                    $item[
                                                        'unit_cost'
                                                    ]
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            ₹<?= e(
                                                jobViewMoney(
                                                    $item[
                                                        'unit_price'
                                                    ]
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                !empty(
                                                    $item[
                                                        'tax_rate_id'
                                                    ]
                                                )
                                            ): ?>
                                                <?= e(
                                                    !empty(
                                                        $item[
                                                            'tax_name'
                                                        ]
                                                    )
                                                        ? $item[
                                                            'tax_name'
                                                        ]
                                                        : 'Tax'
                                                ); ?>

                                                <?php if (
                                                    $item[
                                                        'tax_rate'
                                                    ] !== null
                                                ): ?>
                                                    ·
                                                    <?= e(
                                                        rtrim(
                                                            rtrim(
                                                                number_format(
                                                                    (float) $item[
                                                                        'tax_rate'
                                                                    ],
                                                                    3,
                                                                    '.',
                                                                    ''
                                                                ),
                                                                '0'
                                                            ),
                                                            '.'
                                                        )
                                                    ); ?>%
                                                <?php endif; ?>

                                                <span class="jv-item-note">
                                                    ₹<?= e(
                                                        jobViewMoney(
                                                            $item[
                                                                'tax_amount'
                                                            ]
                                                        )
                                                    ); ?>
                                                </span>
                                            <?php else: ?>
                                                No Tax
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong>
                                                ₹<?= e(
                                                    jobViewMoney(
                                                        $item[
                                                            'line_total'
                                                        ]
                                                    )
                                                ); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="jv-empty">
                        No job items were added.
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside class="jv-side-grid">
            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Job Summary</h2>
                        <p>
                            Current job amounts.
                        </p>
                    </div>
                </div>

                <div class="jv-body">
                    <div class="jv-summary">
                        <div class="jv-summary-row">
                            <span>Subtotal</span>
                            <strong>
                                ₹<?= e(
                                    jobViewMoney(
                                        $job['subtotal']
                                    )
                                ); ?>
                            </strong>
                        </div>

                        <div class="jv-summary-row">
                            <span>Tax</span>
                            <strong>
                                ₹<?= e(
                                    jobViewMoney(
                                        $job['tax_total']
                                    )
                                ); ?>
                            </strong>
                        </div>

                        <div class="jv-summary-row total">
                            <span>Total</span>
                            <strong>
                                ₹<?= e(
                                    jobViewMoney(
                                        $job['total']
                                    )
                                ); ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Client & Property</h2>
                        <p>
                            Customer and service location.
                        </p>
                    </div>
                </div>

                <div class="jv-body">
                    <div class="jv-grid">
                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Client
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    $job['client_name']
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Phone
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $job[
                                            'client_phone'
                                        ]
                                    ) !== ''
                                        ? $job[
                                            'client_phone'
                                        ]
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Email
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $job[
                                            'client_email'
                                        ]
                                    ) !== ''
                                        ? $job[
                                            'client_email'
                                        ]
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Property
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $job[
                                            'property_name'
                                        ]
                                    ) !== ''
                                        ? $job[
                                            'property_name'
                                        ]
                                        : 'No property selected'
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Address
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    $propertyAddress
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Assigned Workers</h2>
                        <p>
                            Current job assignments.
                        </p>
                    </div>
                </div>

                <div class="jv-body">
                    <?php if (!empty($assignments)): ?>
                        <?php foreach ($assignments as $assignment): ?>
                            <div class="jv-person">
                                <div class="jv-person-name">
                                    <?= e(
                                        trim(
                                            (string) $assignment[
                                                'user_name'
                                            ]
                                        ) !== ''
                                            ? $assignment[
                                                'user_name'
                                            ]
                                            : 'Unnamed User'
                                    ); ?>
                                </div>

                                <div class="jv-person-meta">
                                    <?= e(
                                        jobViewStatusLabel(
                                            $assignment[
                                                'assignment_role'
                                            ]
                                        )
                                    ); ?>
                                    ·
                                    <?= e(
                                        jobViewStatusLabel(
                                            $assignment[
                                                'status'
                                            ]
                                        )
                                    ); ?>

                                    <?php if (
                                        !empty(
                                            $assignment[
                                                'is_field_worker'
                                            ]
                                        )
                                    ): ?>
                                        · Field Worker
                                    <?php endif; ?>

                                    <br>

                                    Assigned:
                                    <?= e(
                                        jobViewDateTime(
                                            $assignment[
                                                'assigned_at'
                                            ]
                                        )
                                    ); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($assignedUserName !== ''): ?>
                        <div class="jv-person">
                            <div class="jv-person-name">
                                <?= e(
                                    $assignedUserName
                                ); ?>
                            </div>

                            <div class="jv-person-meta">
                                Primary Worker
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="jv-empty">
                            No worker is assigned.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="jv-card">
                <div class="jv-card-head">
                    <div>
                        <h2>Linked Records</h2>
                        <p>
                            Request, quote, and audit information.
                        </p>
                    </div>
                </div>

                <div class="jv-body">
                    <div class="jv-grid">
                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Request
                            </span>
                            <span class="jv-detail-value">
                                <?php if (
                                    !empty(
                                        $job['request_id']
                                    )
                                ): ?>
                                    <?= e(
                                        $job['request_no']
                                    ); ?>
                                    <?php if (
                                        !empty(
                                            $job[
                                                'request_title'
                                            ]
                                        )
                                    ): ?>
                                        ·
                                        <?= e(
                                            $job[
                                                'request_title'
                                            ]
                                        ); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Quote
                            </span>
                            <span class="jv-detail-value">
                                <?php if (
                                    !empty(
                                        $job['quote_id']
                                    )
                                ): ?>
                                    <?= e(
                                        $job['quote_no']
                                    ); ?>
                                    <?php if (
                                        !empty(
                                            $job[
                                                'quote_title'
                                            ]
                                        )
                                    ): ?>
                                        ·
                                        <?= e(
                                            $job[
                                                'quote_title'
                                            ]
                                        ); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="jv-detail full">
                            <span class="jv-detail-label">
                                Created By
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    $createdByName !== ''
                                        ? $createdByName
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Created
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewDateTime(
                                        $job['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="jv-detail">
                            <span class="jv-detail-label">
                                Last Updated
                            </span>
                            <span class="jv-detail-value">
                                <?= e(
                                    jobViewDateTime(
                                        $job['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
