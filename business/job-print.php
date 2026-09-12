<?php
/**
 * FieldPlx Job PDF / Work Order Print
 * Version 1.0.0 - 2026-09-11
 *
 * Jobber-style job print layout based on the supplied reference image.
 *
 * Features:
 * - Tenant-scoped job loading.
 * - Business / branch branding fallback from invoice_settings.
 * - Recipient billing address + service/property address.
 * - Job number + first scheduled date summary.
 * - Product / Service table with Description and Qty only.
 * - Completion note + blank Date / Client Signature lines.
 * - Quote/Request conversion source stays in the database but is not printed.
 * - Internal notes, unit cost, unit price, discounts and taxes are intentionally not printed.
 * - Optional in-memory PDF capture for future email attachments.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/libs/fpdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fpJobDb()
{
    global $pdo, $db;

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($db) && $db instanceof PDO) {
        return $db;
    }

    throw new RuntimeException('PDO database connection is not available.');
}

function fpJobTableExists(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name"
    );

    $st->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);

    return $cache[$table];
}

function fpJobColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );

    $st->execute(
        array(
            ':table_name' => $table,
            ':column_name' => $column
        )
    );

    $cache[$key] = ((int)$st->fetchColumn() > 0);

    return $cache[$key];
}

function fpJobValue($row, $key)
{
    if (!is_array($row) || !array_key_exists($key, $row)) {
        return '';
    }

    return trim((string)$row[$key]);
}

function fpJobFirstValue()
{
    $args = func_get_args();

    foreach ($args as $value) {
        if ($value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    return '';
}

function fpJobDate($value, $format)
{
    if (!$value) {
        return '';
    }

    $time = strtotime((string)$value);

    if ($time === false) {
        return (string)$value;
    }

    return date($format ?: 'd-m-Y', $time);
}

function fpJobSafeFilename($value)
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$value);
    $value = trim($value, '-');

    return $value !== '' ? $value : 'job';
}

function fpJobLoadBrandSetting(PDO $pdo, $tenantId, $branchId)
{
    if (!fpJobTableExists($pdo, 'invoice_settings')) {
        return null;
    }

    if ($branchId > 0) {
        $st = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id = :branch_id
             ORDER BY id DESC
             LIMIT 1"
        );

        $st->execute(
            array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId
            )
        );
    } else {
        $st = $pdo->prepare(
            "SELECT *
             FROM invoice_settings
             WHERE tenant_id = :tenant_id
               AND branch_id IS NULL
             ORDER BY id DESC
             LIMIT 1"
        );

        $st->execute(array(':tenant_id' => $tenantId));
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fpJobAddressLines($row, $prefix)
{
    $lines = array();

    $a1 = fpJobValue($row, $prefix . 'address_line1');
    $a2 = fpJobValue($row, $prefix . 'address_line2');
    $city = fpJobValue($row, $prefix . 'city');
    $state = fpJobValue($row, $prefix . 'state');
    $postal = fpJobValue($row, $prefix . 'postal_code');

    if ($a1 !== '') {
        $lines[] = $a1;
    }

    if ($a2 !== '') {
        $lines[] = $a2;
    }

    $cityState = implode(', ', array_filter(array($city, $state)));

    if ($postal !== '') {
        $cityState .= ($cityState !== '' ? ' ' : '') . $postal;
    }

    if ($cityState !== '') {
        $lines[] = $cityState;
    }

    return $lines;
}

function fpJobLoadItems(PDO $pdo, $tenantId, $job)
{
    $items = array();

    if (fpJobTableExists($pdo, 'job_line_items')) {
        $nameColumn = fpJobColumnExists($pdo, 'job_line_items', 'item_name')
            ? 'item_name'
            : (fpJobColumnExists($pdo, 'job_line_items', 'name') ? 'name' : null);

        $descriptionColumn = fpJobColumnExists($pdo, 'job_line_items', 'description')
            ? 'description'
            : null;

        $quantityColumn = fpJobColumnExists($pdo, 'job_line_items', 'quantity')
            ? 'quantity'
            : null;

        if ($nameColumn !== null && $quantityColumn !== null) {
            $descriptionSql = $descriptionColumn !== null
                ? ',`' . $descriptionColumn . '` AS item_description'
                : ",'' AS item_description";

            $sortSql = fpJobColumnExists($pdo, 'job_line_items', 'sort_order')
                ? 'sort_order ASC,id ASC'
                : 'id ASC';

            $st = $pdo->prepare(
                "SELECT
                    `" . $nameColumn . "` AS item_name,
                    `" . $quantityColumn . "` AS quantity
                    " . $descriptionSql . "
                 FROM job_line_items
                 WHERE tenant_id = :tenant_id
                   AND job_id = :job_id
                 ORDER BY " . $sortSql
            );

            $st->execute(
                array(
                    ':tenant_id' => $tenantId,
                    ':job_id' => (int)$job['id']
                )
            );

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)$row['item_name']);

                if ($name === '') {
                    continue;
                }

                $items[] = array(
                    'name' => $name,
                    'description' => isset($row['item_description']) ? trim((string)$row['item_description']) : '',
                    'qty' => isset($row['quantity']) ? (float)$row['quantity'] : 1.0
                );
            }
        }
    }

    if ($items) {
        return $items;
    }

    $productServiceId = !empty($job['product_service_id'])
        ? (int)$job['product_service_id']
        : 0;

    if ($productServiceId > 0 && fpJobTableExists($pdo, 'product_services')) {
        $st = $pdo->prepare(
            "SELECT name,description
             FROM product_services
             WHERE id = :id
               AND tenant_id = :tenant_id
             LIMIT 1"
        );

        $st->execute(
            array(
                ':id' => $productServiceId,
                ':tenant_id' => $tenantId
            )
        );

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $items[] = array(
                'name' => trim((string)$row['name']),
                'description' => !empty($row['description']) ? trim((string)$row['description']) : '',
                'qty' => 1.0
            );
        }
    }

    if (!$items) {
        $items[] = array(
            'name' => fpJobFirstValue(fpJobValue($job, 'title'), 'Service Job'),
            'description' => fpJobValue($job, 'description'),
            'qty' => 1.0
        );
    }

    return $items;
}

function fpJobFirstScheduledDate(PDO $pdo, $tenantId, $job)
{
    if (fpJobTableExists($pdo, 'visits') && fpJobColumnExists($pdo, 'visits', 'scheduled_start')) {
        $st = $pdo->prepare(
            "SELECT scheduled_start
             FROM visits
             WHERE tenant_id = :tenant_id
               AND job_id = :job_id
               AND scheduled_start IS NOT NULL
               AND status <> 'cancelled'
             ORDER BY scheduled_start ASC,id ASC
             LIMIT 1"
        );

        $st->execute(
            array(
                ':tenant_id' => $tenantId,
                ':job_id' => (int)$job['id']
            )
        );

        $value = $st->fetchColumn();

        if ($value) {
            return $value;
        }
    }

    if (!empty($job['start_date'])) {
        return $job['start_date'];
    }

    return !empty($job['created_at']) ? $job['created_at'] : '';
}

class JobPDF extends FPDF
{
    private $darkGray = array(85, 85, 85);
    private $lightGray = array(245, 245, 245);
    private $lineGray = array(190, 190, 190);
    private $textGray = array(70, 70, 70);
    private $completionNote = 'We can be called for touch-ups and small changes for the next 3 days. After that all work is final.';

    public function setCompletionNote($text)
    {
        $text = trim((string)$text);

        if ($text !== '') {
            $this->completionNote = $text;
        }
    }

    public function Header()
    {
        // The full page is positioned explicitly in DrawJob().
    }

    public function Footer()
    {
        // Intentionally empty to match the supplied Jobber reference.
    }

    public function DrawJob(array $job)
    {
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 24);
        $this->SetTextColor(0, 0, 0);

        $this->drawCompany($job['company']);
        $bodyBottom = $this->drawRecipientAndJobSummary($job);

        $this->SetY($bodyBottom + 5);
        $this->drawItems($job['items']);
        $this->drawCompletionArea();
    }

    private function drawCompany($company)
    {
        $this->SetXY(10, 11);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(92, 6, $this->cleanText($company['name']), 0, 1, 'L');
    }

    private function drawRecipientAndJobSummary($job)
    {
        $recipient = $job['recipient'];

        $leftX = 10;
        $leftW = 100;
        $recipientY = 25;

        $this->SetXY($leftX, $recipientY);
        $this->SetFont('Arial', 'B', 5.8);
        $this->SetTextColor(65, 65, 65);
        $this->Cell($leftW, 4, 'RECIPIENT:', 0, 1, 'L');

        $this->SetX($leftX);
        $this->SetFont('Arial', 'B', 8.8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($leftW, 5, $this->cleanText($recipient['name']), 0, 1, 'L');

        foreach ($recipient['billing_address'] as $line) {
            $this->SetX($leftX);
            $this->SetFont('Arial', '', 6.8);
            $this->Cell($leftW, 3.8, $this->cleanText($line), 0, 1, 'L');
        }

        if (!empty($recipient['contact'])) {
            $this->SetX($leftX);
            $this->SetFont('Arial', '', 6.5);
            $this->SetTextColor(60, 60, 60);
            $this->MultiCell($leftW, 3.6, $this->cleanText($recipient['contact']), 0, 'L');
        }

        if (!empty($recipient['service_address'])) {
            $this->Ln(2);
            $this->SetX($leftX);
            $this->SetFont('Arial', 'B', 5.8);
            $this->SetTextColor(65, 65, 65);
            $this->Cell($leftW, 4, 'SERVICE ADDRESS', 0, 1, 'L');

            foreach ($recipient['service_address'] as $line) {
                $this->SetX($leftX);
                $this->SetFont('Arial', '', 6.8);
                $this->SetTextColor(0, 0, 0);
                $this->Cell($leftW, 3.8, $this->cleanText($line), 0, 1, 'L');
            }
        }

        $leftBottom = $this->GetY();

        $boxX = 112;
        $boxY = 22;
        $boxW = 83;
        $headerH = 11;
        $dateH = 7;

        $this->SetXY($boxX, $boxY);
        $this->SetFillColor($this->darkGray[0], $this->darkGray[1], $this->darkGray[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8.8);
        $this->Cell($boxW, $headerH, $this->cleanText('Job #' . $job['job_no']), 0, 1, 'L', true);

        $this->SetXY($boxX, $boxY + $headerH);
        $this->SetFillColor($this->lightGray[0], $this->lightGray[1], $this->lightGray[2]);
        $this->SetTextColor(45, 45, 45);
        $this->SetFont('Arial', '', 6.3);
        $this->Cell(20, $dateH, 'Date', 0, 0, 'L', true);
        $this->SetFont('Arial', '', 6.8);
        $this->Cell($boxW - 20, $dateH, $this->cleanText($job['date']), 0, 1, 'R', true);

        $this->SetTextColor(0, 0, 0);

        return max($leftBottom, $boxY + $headerH + $dateH);
    }

    private function drawItems($items)
    {
        $x = 10;
        $tableW = 185;
        $nameW = 45;
        $qtyW = 17;
        $descW = $tableW - $nameW - $qtyW;
        $headerH = 7;

        $this->ensureTableSpace(20);

        $this->SetX($x);
        $this->SetFillColor($this->darkGray[0], $this->darkGray[1], $this->darkGray[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 6.4);
        $this->Cell($nameW, $headerH, 'Product/Service', 0, 0, 'L', true);
        $this->Cell($descW, $headerH, 'Description', 0, 0, 'L', true);
        $this->Cell($qtyW, $headerH, 'Qty.', 0, 1, 'R', true);

        $this->SetTextColor(0, 0, 0);

        foreach ($items as $item) {
            $name = $this->cleanText(trim((string)$item['name']));
            $description = $this->cleanText(trim((string)$item['description']));
            $qty = $this->formatQty($item['qty']);

            $lineH = 4;
            $topPad = 1.7;
            $bottomPad = 1.7;
            $nameLines = max(1, $this->countWrappedLines($nameW - 3, $name));
            $descLines = max(1, $this->countWrappedLines($descW - 3, $description));
            $rowH = max(9, max($nameLines, $descLines) * $lineH + $topPad + $bottomPad);

            if ($this->GetY() + $rowH > 230) {
                $this->AddPage();
                $this->SetY(18);

                $this->SetX($x);
                $this->SetFillColor($this->darkGray[0], $this->darkGray[1], $this->darkGray[2]);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 6.4);
                $this->Cell($nameW, $headerH, 'Product/Service', 0, 0, 'L', true);
                $this->Cell($descW, $headerH, 'Description', 0, 0, 'L', true);
                $this->Cell($qtyW, $headerH, 'Qty.', 0, 1, 'R', true);
                $this->SetTextColor(0, 0, 0);
            }

            $rowY = $this->GetY();
            $textY = $rowY + $topPad;

            $this->SetFont('Arial', '', 6.8);
            $this->SetXY($x, $textY);
            $this->MultiCell($nameW, $lineH, $name, 0, 'L');

            $this->SetXY($x + $nameW, $textY);
            $this->MultiCell($descW, $lineH, $description, 0, 'L');

            $this->SetXY($x + $nameW + $descW, $textY);
            $this->Cell($qtyW, $lineH, $this->cleanText($qty), 0, 0, 'R');

            $this->SetDrawColor($this->lineGray[0], $this->lineGray[1], $this->lineGray[2]);
            $this->Line($x, $rowY + $rowH, $x + $tableW, $rowY + $rowH);
            $this->SetY($rowY + $rowH);
        }
    }

    private function drawCompletionArea()
    {
        if ($this->GetY() > 222) {
            $this->AddPage();
        }

        $noteX = 10;
        $noteW = 105;
        $signatureX = 125;
        $signatureW = 70;
        $lineY = 258;

        $this->SetXY($noteX, 252);
        $this->SetFont('Arial', '', 5.8);
        $this->SetTextColor(25, 25, 25);
        $this->MultiCell($noteW, 3.3, $this->cleanText($this->completionNote), 0, 'L');

        $this->SetDrawColor(180, 180, 180);
        $this->Line($signatureX, $lineY, $signatureX + $signatureW, $lineY);

        $this->SetXY($signatureX, $lineY + 1.5);
        $this->SetFont('Arial', '', 5.5);
        $this->SetTextColor(45, 45, 45);
        $this->Cell(27, 3.5, 'Date', 0, 0, 'L');
        $this->Cell($signatureW - 27, 3.5, 'Client Signature', 0, 0, 'L');
    }

    private function ensureTableSpace($height)
    {
        if ($this->GetY() + (float)$height > 230) {
            $this->AddPage();
            $this->SetY(18);
        }
    }

    private function cleanText($text)
    {
        $text = (string)$text;

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

            if ($converted !== false) {
                return $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text);
    }

    private function formatQty($qty)
    {
        $qty = (float)$qty;

        if (floor($qty) == $qty) {
            return (string)(int)$qty;
        }

        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    private function countWrappedLines($width, $text)
    {
        $cw = &$this->CurrentFont['cw'];

        if ($width == 0) {
            $width = $this->w - $this->rMargin - $this->x;
        }

        $wmax = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$text);
        $nb = strlen($s);

        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }

        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;

        while ($i < $nb) {
            $c = $s[$i];

            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }

            if ($c === ' ') {
                $sep = $i;
            }

            $l += isset($cw[$c]) ? $cw[$c] : 0;

            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }

        return $nl;
    }
}

try {
    $pdo = fpJobDb();

    $tenantId = !empty($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);

    $loggedBranchId = !empty($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : 0;

    $jobId = isset($_GET['job_id'])
        ? (int)$_GET['job_id']
        : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($tenantId <= 0) {
        throw new RuntimeException('Your login session is not valid.');
    }

    if ($jobId <= 0) {
        throw new RuntimeException('Invalid job.');
    }

    $st = $pdo->prepare(
        "SELECT
            j.*,
            c.display_name AS client_name,
            c.company_name AS client_company_name,
            c.email AS client_email,
            c.phone AS client_phone,
            l.name AS location_name,
            l.address_line1 AS location_address_line1,
            l.address_line2 AS location_address_line2,
            l.city AS location_city,
            l.state AS location_state,
            l.postal_code AS location_postal_code
         FROM jobs j
         INNER JOIN clients c
            ON c.id = j.client_id
           AND c.tenant_id = j.tenant_id
         LEFT JOIN client_locations l
            ON l.id = j.location_id
           AND l.tenant_id = j.tenant_id
           AND l.client_id = j.client_id
         WHERE j.id = :job_id
           AND j.tenant_id = :tenant_id
           AND j.deleted_at IS NULL
         LIMIT 1"
    );

    $st->execute(
        array(
            ':job_id' => $jobId,
            ':tenant_id' => $tenantId
        )
    );

    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Job not found or you do not have access to it.');
    }

    $jobBranchId = !empty($row['branch_id']) ? (int)$row['branch_id'] : 0;
    $effectiveBranchId = $jobBranchId > 0 ? $jobBranchId : $loggedBranchId;

    $tenantSt = $pdo->prepare(
        "SELECT *
         FROM tenants
         WHERE id = :tenant_id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $tenantSt->execute(array(':tenant_id' => $tenantId));
    $tenant = $tenantSt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        throw new RuntimeException('Business details are not available.');
    }

    $branch = null;

    if ($effectiveBranchId > 0 && fpJobTableExists($pdo, 'branches')) {
        $branchSt = $pdo->prepare(
            "SELECT *
             FROM branches
             WHERE id = :branch_id
               AND tenant_id = :tenant_id
               AND status <> 'archived'
             LIMIT 1"
        );

        $branchSt->execute(
            array(
                ':branch_id' => $effectiveBranchId,
                ':tenant_id' => $tenantId
            )
        );

        $branch = $branchSt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $branchSetting = $effectiveBranchId > 0
        ? fpJobLoadBrandSetting($pdo, $tenantId, $effectiveBranchId)
        : null;

    $businessSetting = fpJobLoadBrandSetting($pdo, $tenantId, 0);

    $companyName = fpJobFirstValue(
        fpJobValue($branchSetting, 'company_name'),
        fpJobValue($businessSetting, 'company_name'),
        fpJobValue($branch, 'name'),
        fpJobValue($tenant, 'display_name'),
        fpJobValue($tenant, 'legal_name'),
        'FieldPlx'
    );

    $completionNote = fpJobFirstValue(
        fpJobValue($branchSetting, 'job_footer_note'),
        fpJobValue($businessSetting, 'job_footer_note'),
        fpJobValue($branchSetting, 'work_order_footer_note'),
        fpJobValue($businessSetting, 'work_order_footer_note'),
        fpJobValue($branchSetting, 'footer_note'),
        fpJobValue($businessSetting, 'footer_note'),
        'We can be called for touch-ups and small changes for the next 3 days. After that all work is final.'
    );

    $billingAddress = array();

    if (fpJobTableExists($pdo, 'client_locations')) {
        $billingSt = $pdo->prepare(
            "SELECT
                address_line1,
                address_line2,
                city,
                state,
                postal_code
             FROM client_locations
             WHERE tenant_id = :tenant_id
               AND client_id = :client_id
               AND deleted_at IS NULL
               AND status = 'active'
             ORDER BY
                (location_type = 'billing') DESC,
                is_primary DESC,
                id ASC
             LIMIT 1"
        );

        $billingSt->execute(
            array(
                ':tenant_id' => $tenantId,
                ':client_id' => (int)$row['client_id']
            )
        );

        $billingRow = $billingSt->fetch(PDO::FETCH_ASSOC);

        if ($billingRow) {
            $billingAddress = fpJobAddressLines(
                array(
                    'billing_address_line1' => $billingRow['address_line1'],
                    'billing_address_line2' => $billingRow['address_line2'],
                    'billing_city' => $billingRow['city'],
                    'billing_state' => $billingRow['state'],
                    'billing_postal_code' => $billingRow['postal_code']
                ),
                'billing_'
            );
        }
    }

    $serviceAddress = fpJobAddressLines($row, 'location_');

    if (!$serviceAddress && $billingAddress) {
        $serviceAddress = $billingAddress;
    }

    $dateFormat = !empty($tenant['date_format'])
        ? $tenant['date_format']
        : 'd-m-Y';

    $scheduledDateRaw = fpJobFirstScheduledDate($pdo, $tenantId, $row);
    $jobDate = fpJobDate($scheduledDateRaw, $dateFormat);

    $recipientName = fpJobFirstValue(
        fpJobValue($row, 'client_name'),
        fpJobValue($row, 'client_company_name'),
        'Customer'
    );

    $recipientContact = implode(
        '  |  ',
        array_filter(
            array(
                fpJobValue($row, 'client_phone'),
                fpJobValue($row, 'client_email')
            )
        )
    );

    $job = array(
        'job_no' => fpJobValue($row, 'job_no'),
        'date' => $jobDate,
        'company' => array(
            'name' => $companyName
        ),
        'recipient' => array(
            'name' => $recipientName,
            'billing_address' => $billingAddress,
            'service_address' => $serviceAddress,
            'contact' => $recipientContact
        ),
        'items' => fpJobLoadItems($pdo, $tenantId, $row)
    );

    $pdf = new JobPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Job #' . $job['job_no']);
    $pdf->SetAuthor($job['company']['name']);
    $pdf->setCompletionNote($completionNote);
    $pdf->AddPage();
    $pdf->DrawJob($job);

    $outputName = 'Job-' . fpJobSafeFilename($job['job_no']) . '.pdf';

    /*
     * Optional capture mode for email attachments:
     * define('FIELDPLX_JOB_PDF_CAPTURE', true);
     * include __DIR__ . '/job-print.php';
     */
    if (defined('FIELDPLX_JOB_PDF_CAPTURE') && FIELDPLX_JOB_PDF_CAPTURE) {
        $bytes = $pdf->Output('S', $outputName);

        if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== '%PDF') {
            throw new RuntimeException('Job PDF capture did not return a valid PDF document.');
        }

        $GLOBALS['fieldplx_job_pdf_bytes'] = $bytes;
        $GLOBALS['fieldplx_job_pdf_name'] = $outputName;
        return;
    }

    $pdf->Output('I', $outputName);
    exit;

} catch (Throwable $e) {
    error_log('FieldPlx job PDF: ' . $e->getMessage());

    if (defined('FIELDPLX_JOB_PDF_CAPTURE') && FIELDPLX_JOB_PDF_CAPTURE) {
        throw $e;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate job PDF: ' . $e->getMessage();
    exit;
}
