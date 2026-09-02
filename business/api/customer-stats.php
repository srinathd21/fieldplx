<?php
/* FieldPlx Customer Statistics API - Version 1.0.0 - 2026-09-01 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csOut($status,$success,$message,$extra=array())
{
    while (ob_get_level()>0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function csPost($key,$default='')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

$tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;

if ($tenantId<=0 || $userId<=0) {
    csOut(401,false,'Authentication required.');
}

if ($_SERVER['REQUEST_METHOD']!=='POST') {
    csOut(405,false,'Method not allowed.');
}

$csrf=(string)csPost('csrf_token','');
$sessionCsrf=isset($_SESSION['clients_csrf_token'])?(string)$_SESSION['clients_csrf_token']:'';
if ($csrf==='' || $sessionCsrf==='' || !hash_equals($sessionCsrf,$csrf)) {
    csOut(419,false,'Your form session expired. Refresh the page and try again.');
}

$action=trim((string)csPost('action','summary'));
if ($action!=='summary') {
    csOut(400,false,'Unsupported customer statistics action.');
}

try {
    /*
     * Effective CRM conversion logic:
     * 1. A record explicitly stored as client is already a customer from clients.created_at.
     * 2. A lead becomes a customer when its first real job or invoice is created.
     * 3. Cancelled/archived jobs and cancelled/archived/written-off invoices do not qualify.
     * 4. Leads with no qualifying job/invoice remain leads.
     *
     * This intentionally derives conversion from operational data because older workflows
     * may have created jobs/invoices without updating clients.client_type from lead to client.
     */
    $sql="SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN effective_customer_at IS NULL
                        AND original_type='lead'
                        AND client_created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY)
                        AND client_created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)
                       THEN 1 ELSE 0 END) AS new_leads_30,
              SUM(CASE WHEN effective_customer_at IS NULL
                        AND original_type='lead'
                        AND client_created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY)
                        AND client_created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY)
                       THEN 1 ELSE 0 END) AS prior_leads_30,
              SUM(CASE WHEN effective_customer_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY)
                        AND effective_customer_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)
                       THEN 1 ELSE 0 END) AS new_customers_30,
              SUM(CASE WHEN effective_customer_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY)
                        AND effective_customer_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY)
                       THEN 1 ELSE 0 END) AS prior_customers_30
          FROM (
              SELECT
                  c.id,
                  c.client_type AS original_type,
                  c.created_at AS client_created_at,
                  CASE
                      WHEN c.client_type='client' THEN c.created_at
                      WHEN j.first_job_at IS NULL THEN i.first_invoice_at
                      WHEN i.first_invoice_at IS NULL THEN j.first_job_at
                      WHEN j.first_job_at<=i.first_invoice_at THEN j.first_job_at
                      ELSE i.first_invoice_at
                  END AS effective_customer_at
              FROM clients c
              LEFT JOIN (
                  SELECT tenant_id,client_id,MIN(created_at) AS first_job_at
                  FROM jobs
                  WHERE deleted_at IS NULL
                    AND status NOT IN ('draft','cancelled','archived')
                  GROUP BY tenant_id,client_id
              ) j ON j.tenant_id=c.tenant_id AND j.client_id=c.id
              LEFT JOIN (
                  SELECT tenant_id,client_id,MIN(created_at) AS first_invoice_at
                  FROM invoices
                  WHERE status NOT IN ('cancelled','archived','written_off')
                  GROUP BY tenant_id,client_id
              ) i ON i.tenant_id=c.tenant_id AND i.client_id=c.id
              WHERE c.tenant_id=:tenant_id
                AND c.deleted_at IS NULL
                AND c.client_type<>'archived'
          ) crm";

    $stmt=$pdo->prepare($sql);
    $stmt->execute(array(':tenant_id'=>$tenantId));
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row=array();

    $currentLeads=(int)($row['new_leads_30']?:0);
    $priorLeads=(int)($row['prior_leads_30']?:0);
    $currentCustomers=(int)($row['new_customers_30']?:0);
    $priorCustomers=(int)($row['prior_customers_30']?:0);

    $leadChange=$priorLeads>0 ? (($currentLeads-$priorLeads)/$priorLeads)*100 : ($currentLeads>0?100:0);
    $customerChange=$priorCustomers>0 ? (($currentCustomers-$priorCustomers)/$priorCustomers)*100 : ($currentCustomers>0?100:0);

    csOut(200,true,'Customer statistics loaded.',array('summary'=>array(
        'total'=>(int)($row['total']?:0),
        'new_leads_30'=>$currentLeads,
        'prior_leads_30'=>$priorLeads,
        'new_leads_change'=>(float)$leadChange,
        'new_customers_30'=>$currentCustomers,
        'prior_customers_30'=>$priorCustomers,
        'new_customers_change'=>(float)$customerChange,
        'current_period_label'=>date('M j',strtotime('-29 days')).' - '.date('M j'),
        'prior_period_label'=>date('M j',strtotime('-59 days')).' - '.date('M j',strtotime('-30 days')),
        'customer_definition'=>'First qualifying job or invoice, or explicitly stored client'
    )));
} catch (PDOException $e) {
    error_log('FieldPlx customer statistics PDO error: '.$e->getMessage());
    csOut(500,false,'Unable to load customer statistics.');
} catch (Throwable $e) {
    error_log('FieldPlx customer statistics error: '.$e->getMessage());
    csOut(500,false,'Unable to load customer statistics.');
}
