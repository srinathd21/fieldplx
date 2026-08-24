<?php
/**
 * FieldPlx API - View Tenant
 * Endpoint: GET /platform/api/tenant-view.php?id=TENANT_ID
 * PHP 7.2+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenantViewApiResponse(int $status, bool $success, string $message, array $extra = array()): void
{
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tenantViewApiResponse(405, false, 'Method not allowed.');
}

$tenantId =
    isset($_GET['id']) && !is_array($_GET['id'])
        ? (int) $_GET['id']
        : 0;

if ($tenantId <= 0) {
    tenantViewApiResponse(422, false, 'Invalid tenant ID.');
}

$stmt = $pdo->prepare("
    SELECT
        t.*,

        c.name AS country_name,
        c.iso2 AS country_iso2,
        c.phone_code AS country_phone_code,

        cur.currency_code,
        cur.currency_name,
        cur.symbol AS currency_symbol,

        s.id AS subscription_id,
        s.plan_id AS subscription_plan_id,
        s.currency_id AS subscription_currency_id,
        s.amount AS subscription_amount,
        s.start_date AS subscription_start_date,
        s.expiry_date AS subscription_expiry_date,
        s.trial_end_date AS subscription_trial_end_date,
        s.auto_renew AS subscription_auto_renew,
        s.status AS subscription_status,
        s.created_at AS subscription_created_at,
        s.updated_at AS subscription_updated_at,

        p.name AS plan_name,
        p.code AS plan_code,
        p.billing_cycle AS plan_billing_cycle,
        p.trial_days AS plan_trial_days,
        p.max_users AS plan_max_users,
        p.max_branches AS plan_max_branches,
        p.max_customers AS plan_max_customers,
        p.storage_limit_mb AS plan_storage_limit_mb

    FROM tenants t

    LEFT JOIN countries c
        ON c.id = t.country_id

    LEFT JOIN currencies cur
        ON cur.id = t.currency_id

    LEFT JOIN (
        SELECT s1.*
        FROM subscriptions s1
        INNER JOIN (
            SELECT tenant_id, MAX(id) AS max_id
            FROM subscriptions
            WHERE deleted_at IS NULL
            GROUP BY tenant_id
        ) latest_subscription
            ON latest_subscription.max_id = s1.id
    ) s
        ON s.tenant_id = t.id

    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL

    WHERE t.id = :tenant_id
      AND t.deleted_at IS NULL

    LIMIT 1
");

$stmt->execute(array(':tenant_id' => $tenantId));
$row = $stmt->fetch();

if (!$row) {
    tenantViewApiResponse(404, false, 'Tenant not found.');
}

$tenant = array(
    'id' => (int) $row['id'],
    'tenant_code' => $row['tenant_code'],
    'legal_name' => $row['legal_name'],
    'display_name' => $row['display_name'],
    'business_type' => $row['business_type'],
    'registration_number' => $row['registration_number'],
    'tax_number' => $row['tax_number'],
    'email' => $row['email'],
    'phone' => $row['phone'],
    'alternate_phone' => $row['alternate_phone'],
    'website_url' => $row['website_url'],
    'country_id' => (int) $row['country_id'],
    'currency_id' => (int) $row['currency_id'],
    'timezone' => $row['timezone'],
    'date_format' => $row['date_format'],
    'logo_path' => $row['logo_path'],
    'invoice_logo_path' => $row['invoice_logo_path'],
    'address_line1' => $row['address_line1'],
    'address_line2' => $row['address_line2'],
    'city' => $row['city'],
    'state' => $row['state'],
    'postal_code' => $row['postal_code'],
    'status' => $row['status'],
    'country_name' => $row['country_name'],
    'country_iso2' => $row['country_iso2'],
    'country_phone_code' => $row['country_phone_code'],
    'currency_code' => $row['currency_code'],
    'currency_name' => $row['currency_name'],
    'currency_symbol' => $row['currency_symbol'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at']
);

$subscription = array(
    'id' => !empty($row['subscription_id']) ? (int) $row['subscription_id'] : null,
    'plan_id' => !empty($row['subscription_plan_id']) ? (int) $row['subscription_plan_id'] : null,
    'plan_name' => $row['plan_name'],
    'plan_code' => $row['plan_code'],
    'amount' => $row['subscription_amount'],
    'start_date' => $row['subscription_start_date'],
    'expiry_date' => $row['subscription_expiry_date'],
    'trial_end_date' => $row['subscription_trial_end_date'],
    'auto_renew' => $row['subscription_auto_renew'],
    'status' => $row['subscription_status'],
    'billing_cycle' => $row['plan_billing_cycle'],
    'trial_days' => $row['plan_trial_days'],
    'max_users' => $row['plan_max_users'],
    'max_branches' => $row['plan_max_branches'],
    'max_customers' => $row['plan_max_customers'],
    'storage_limit_mb' => $row['plan_storage_limit_mb'],
    'created_at' => $row['subscription_created_at'],
    'updated_at' => $row['subscription_updated_at']
);

$userCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users
    WHERE tenant_id = :tenant_id
      AND deleted_at IS NULL
");
$userCountStmt->execute(array(':tenant_id' => $tenantId));
$userCount = (int) $userCountStmt->fetchColumn();

$branchCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM branches
    WHERE tenant_id = :tenant_id
      AND status <> 'archived'
");
$branchCountStmt->execute(array(':tenant_id' => $tenantId));
$branchCount = (int) $branchCountStmt->fetchColumn();

$branchStmt = $pdo->prepare("
    SELECT
        id,
        branch_code,
        name,
        city,
        state,
        postal_code,
        is_head_office,
        status,
        created_at
    FROM branches
    WHERE tenant_id = :tenant_id
      AND status <> 'archived'
    ORDER BY is_head_office DESC, name ASC, id ASC
");
$branchStmt->execute(array(':tenant_id' => $tenantId));
$branches = $branchStmt->fetchAll();

tenantViewApiResponse(
    200,
    true,
    'Tenant loaded successfully.',
    array(
        'data' => array(
            'tenant' => $tenant,
            'subscription' => $subscription,
            'counts' => array(
                'users' => $userCount,
                'branches' => $branchCount
            ),
            'branches' => $branches
        )
    )
);
