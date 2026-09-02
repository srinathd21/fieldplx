<?php
/**
 * FieldPlx Dynamic Invoice PDF
 * Version 1.1.1 - 2026-08-28
 *
 * Data priority for company details:
 * 1) Branch invoice_settings (invoice branch, or logged-in branch when invoice has no branch)
 * 2) Business-default invoice_settings (branch_id IS NULL)
 * 3) Branch master data
 * 4) Tenant/business master data
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/libs/fpdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fpInvoiceDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function fpInvoiceTableExists(PDO $pdo, $table)
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $st->execute(array(':table_name' => $table));
    return ((int)$st->fetchColumn() > 0);
}

function fpInvoiceValue($row, $key)
{
    if (!is_array($row) || !array_key_exists($key, $row)) return '';
    return trim((string)$row[$key]);
}

function fpInvoiceFirstValue()
{
    $args = func_get_args();
    foreach ($args as $value) {
        if ($value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return '';
}

function fpInvoiceDate($value, $format)
{
    if (!$value) return '';
    $time = strtotime((string)$value);
    if ($time === false) return (string)$value;
    return date($format ?: 'd-m-Y', $time);
}

function fpInvoiceSafeFilename($value)
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'invoice';
}

function fpInvoiceLocalImage($path)
{
    $path = trim((string)$path);
    if ($path === '' || preg_match('~^https?://~i', $path)) return '';

    $candidate = $path;
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $candidate)) {
        $candidate = __DIR__ . '/' . ltrim($candidate, '/\\');
    }

    if (!is_file($candidate)) return '';

    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    if (in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return $candidate;
    }

    // FPDF core does not reliably support WEBP. Convert to PNG when GD is available.
    if ($ext === 'webp' && function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
        $img = @imagecreatefromwebp($candidate);
        if ($img) {
            $tmp = sys_get_temp_dir() . '/fieldplx-invoice-' . md5($candidate . filemtime($candidate)) . '.png';
            if (!is_file($tmp)) @imagepng($img, $tmp);
            imagedestroy($img);
            if (is_file($tmp)) return $tmp;
        }
    }

    return '';
}

function fpInvoiceLoadSetting(PDO $pdo, $tenantId, $branchId)
{
    if (!fpInvoiceTableExists($pdo, 'invoice_settings')) return null;

    if ($branchId > 0) {
        $st = $pdo->prepare("SELECT * FROM invoice_settings WHERE tenant_id = :tenant_id AND branch_id = :branch_id ORDER BY id DESC LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId));
    } else {
        $st = $pdo->prepare("SELECT * FROM invoice_settings WHERE tenant_id = :tenant_id AND branch_id IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId));
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

class InvoicePDF extends FPDF
{
    private $darkGray = array(86, 86, 86);
    private $lightGray = array(242, 242, 242);
    private $borderGray = array(185, 185, 185);
    private $textGray = array(55, 55, 55);
    private $footerText = 'Thank you for your business.';

    public function setFooterText($text)
    {
        $text = trim((string)$text);
        if ($text !== '') $this->footerText = $text;
    }

    public function Header()
    {
        // Invoice content starts in DrawInvoice().
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor(210, 210, 210);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, $this->cleanText($this->footerText), 0, 0, 'C');
    }

    public function DrawInvoice(array $invoice)
    {
        $this->SetMargins(15, 14, 15);
        $this->SetAutoPageBreak(true, 22);
        $this->SetTextColor(0, 0, 0);

        $company = $invoice['company'];
        $recipient = $invoice['recipient'];
        $items = $invoice['items'];

        // Company block / logo
        $companyTextX = 15;
        $logo = !empty($company['invoice_logo']) ? $company['invoice_logo'] : $company['logo'];
        if ($logo && is_file($logo)) {
            try {
                $this->Image($logo, 15, 16, 28, 18);
                $companyTextX = 47;
            } catch (Exception $e) {
                $companyTextX = 15;
            }
        }

        $this->SetXY($companyTextX, 18);
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(62, 8, $this->cleanText($company['name']), 0, 1, 'L');

        $this->SetX($companyTextX);
        $this->SetFont('Arial', '', 8.5);
        $companyLine = $this->joinNonEmpty(array($company['phone'], $company['email'], $company['website']), '  |  ');
        if ($companyLine !== '') {
            $this->Cell(62, 5, $this->cleanText($companyLine), 0, 1, 'L');
        }

        $addressLine = $this->joinNonEmpty($company['address_lines'], ', ');
        if ($addressLine !== '') {
            $this->SetX($companyTextX);
            $this->SetFont('Arial', '', 8.2);
            $this->MultiCell(62, 4.2, $this->cleanText($addressLine), 0, 'L');
        }

        $taxBits = array();
        if (!empty($company['registration_number'])) $taxBits[] = 'Reg: ' . $company['registration_number'];
        if (!empty($company['tax_number'])) $taxBits[] = 'Tax: ' . $company['tax_number'];
        if ($taxBits) {
            $this->SetX($companyTextX);
            $this->SetFont('Arial', '', 7.8);
            $this->Cell(62, 4.5, $this->cleanText(implode('  |  ', $taxBits)), 0, 1, 'L');
        }

        // Recipient
        $recipientY = 55;
        $this->SetXY(15, $recipientY);
        $this->SetFont('Arial', 'B', 9.5);
        $this->Cell(70, 5, 'RECIPIENT:', 0, 1, 'L');
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(90, 7, $this->cleanText($recipient['name']), 0, 1, 'L');

        $this->SetFont('Arial', '', 9.5);
        foreach ($recipient['address_lines'] as $line) {
            if (trim((string)$line) === '') continue;
            $this->SetX(15);
            $this->Cell(90, 5, $this->cleanText($line), 0, 1, 'L');
        }
        if (!empty($recipient['contact'])) {
            $this->SetX(15);
            $this->SetTextColor(85, 85, 85);
            $this->Cell(90, 5, $this->cleanText($recipient['contact']), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
        }

        // Invoice summary
        $boxX = 112;
        $boxY = 40;
        $boxW = 83;
        $labelW = 30;
        $valueW = $boxW - $labelW;

        $this->SetXY($boxX, $boxY);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 14);
        $heading = !empty($invoice['invoice_title']) ? $invoice['invoice_title'] : 'Invoice';
        $this->Cell($boxW, 14, $this->cleanText($heading . ' #' . $invoice['invoice_no']), 0, 1, 'L', true);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 9.5);
        $this->SetXY($boxX, $boxY + 14);
        $this->setFill($this->lightGray);
        $this->Cell($labelW, 9, 'Issued', 0, 0, 'L', true);
        $this->Cell($valueW, 9, $this->cleanText($invoice['issued']), 0, 1, 'R', true);

        $this->SetXY($boxX, $boxY + 23);
        $this->Cell($labelW, 9, 'Due', 0, 0, 'L', true);
        $this->Cell($valueW, 9, $this->cleanText($invoice['due']), 0, 1, 'R', true);

        $this->SetXY($boxX, $boxY + 32);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell($labelW, 10, 'Total', 0, 0, 'L', true);
        $this->Cell($valueW, 10, $this->money($invoice['total'], $invoice['currency']), 0, 1, 'R', true);

        $this->SetXY($boxX, $boxY + 42);
        $this->setFill($this->lightGray);
        $this->SetTextColor(70, 70, 70);
        $this->SetFont('Arial', '', 8.5);
        $this->Cell($labelW + 12, 8, 'Account Balance', 0, 0, 'L', true);
        $this->Cell($valueW - 12, 8, $this->money($invoice['balance'], $invoice['currency']), 0, 1, 'R', true);

        // Items
        $tableY = 108;
        $this->SetXY(15, $tableY);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 9, 'For Services Rendered', 0, 1, 'L');

        $x = 15;
        $wProduct = 44;
        $wDescription = 80;
        $wQty = 15;
        $wUnit = 23;
        $wTotal = 23;
        $headerH = 10;

        $this->SetX($x);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9.5);
        $this->Cell($wProduct, $headerH, 'Product/Service', 0, 0, 'L', true);
        $this->Cell($wDescription, $headerH, 'Description', 0, 0, 'L', true);
        $this->Cell($wQty, $headerH, 'Qty.', 0, 0, 'C', true);
        $this->Cell($wUnit, $headerH, 'Unit Price', 0, 0, 'R', true);
        $this->Cell($wTotal, $headerH, 'Total', 0, 1, 'R', true);

        $this->SetTextColor(0, 0, 0);
        foreach ($items as $item) {
            if ($this->GetY() > 245) {
                $this->AddPage();
                $this->SetY(22);
            }

            $rowStartY = $this->GetY();
            $description = $this->cleanText($item['description']);
            $name = $this->cleanText($item['name']);

            // Keep every value in the same visual row. MultiCell moves Y by itself,
            // therefore calculate the row height first and explicitly position each
            // column at one common top baseline.
            $lineH = 5.2;
            $topPad = 2.2;
            $bottomPad = 2.2;
            $descLines = max(1, $this->countWrappedLines($wDescription - 4, $description));
            $nameLines = max(1, $this->countWrappedLines($wProduct - 4, $name));
            $contentLines = max($descLines, $nameLines, 1);
            $rowH = max(12.5, ($contentLines * $lineH) + $topPad + $bottomPad);
            $textY = $rowStartY + $topPad;

            $this->SetFont('Arial', '', 9.5);

            // Product / Service - top aligned.
            $this->SetXY($x, $textY);
            $this->MultiCell($wProduct, $lineH, $name, 0, 'L');

            // Description - same top baseline as Product / Service.
            $this->SetXY($x + $wProduct, $textY);
            $this->MultiCell($wDescription, $lineH, $description, 0, 'L');

            // Numeric columns previously used $rowH as their Cell height, which
            // pushed their text toward the bottom/centre of the row. Use the same
            // line height and Y baseline as the text columns instead.
            $this->SetXY($x + $wProduct + $wDescription, $textY);
            $this->Cell($wQty, $lineH, $this->formatQty($item['qty']), 0, 0, 'C');
            $this->Cell($wUnit, $lineH, $this->money($item['unit_price'], $invoice['currency']), 0, 0, 'R');
            $this->Cell($wTotal, $lineH, $this->money($item['total'], $invoice['currency']), 0, 0, 'R');

            // Move once to the calculated bottom of the row so the next item starts
            // on a clean, consistent baseline.
            $this->SetY($rowStartY + $rowH);
        }

        $this->setDraw($this->borderGray);
        $this->SetLineWidth(0.65);
        $this->Line(15, $this->GetY() + 2, 195, $this->GetY() + 2);
        $this->Ln(8);

        // Totals
        $totalsX = 128;
        $labelWidth = 37;
        $amountWidth = 30;
        $this->SetFont('Arial', '', 9.5);
        $this->setText($this->textGray);

        $this->SetX($totalsX);
        $this->Cell($labelWidth, 7, 'Subtotal', 0, 0, 'R');
        $this->Cell($amountWidth, 7, $this->money($invoice['subtotal'], $invoice['currency']), 0, 1, 'R');

        if ((float)$invoice['tax'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Tax', 0, 0, 'R');
            $this->Cell($amountWidth, 7, $this->money($invoice['tax'], $invoice['currency']), 0, 1, 'R');
        }

        if ((float)$invoice['discount'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Discount', 0, 0, 'R');
            $this->Cell($amountWidth, 7, '-' . $this->money($invoice['discount'], $invoice['currency']), 0, 1, 'R');
        }

        $this->SetX($totalsX);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($labelWidth, 8, 'Total', 0, 0, 'R');
        $this->Cell($amountWidth, 8, $this->money($invoice['total'], $invoice['currency']), 0, 1, 'R');

        if ((float)$invoice['amount_paid'] > 0) {
            $this->SetX($totalsX);
            $this->SetFont('Arial', '', 9.5);
            $this->Cell($labelWidth, 7, 'Paid', 0, 0, 'R');
            $this->Cell($amountWidth, 7, '-' . $this->money($invoice['amount_paid'], $invoice['currency']), 0, 1, 'R');
        }

        $this->SetX($totalsX);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell($labelWidth, 7, 'Balance Due', 0, 0, 'R');
        $this->Cell($amountWidth, 7, $this->money($invoice['balance'], $invoice['currency']), 0, 1, 'R');

        //Terms / signature


        if (!empty($invoice['terms'])) {
            $this->Ln(4);
            $this->SetX(15);
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 5, 'Terms & Conditions', 0, 1, 'L');
            $this->SetX(15);
            $this->SetFont('Arial', '', 7.8);
            $this->SetTextColor(95, 95, 95);
            $this->MultiCell(110, 4.2, $this->cleanText($invoice['terms']), 0, 'L');
        }

        if (!empty($company['signature']) && is_file($company['signature'])) {
            $sigY = max($this->GetY() + 4, 225);
            if ($sigY > 250) {
                $this->AddPage();
                $sigY = 35;
            }
            try {
                $this->Image($company['signature'], 150, $sigY, 35, 15);
            } catch (Exception $e) {
                // Keep invoice printable even if an uploaded image is unreadable.
            }
            $this->SetXY(135, $sigY + 16);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(90, 90, 90);
            $this->Cell(60, 5, $this->cleanText($company['signatory_name']), 0, 1, 'R');
            $this->SetX(135);
            $this->Cell(60, 5, 'Authorized Signature', 0, 1, 'R');
        } elseif (!empty($company['signatory_name'])) {
            $this->Ln(7);
            $this->SetX(135);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(90, 90, 90);
            $this->Cell(60, 5, $this->cleanText($company['signatory_name']), 0, 1, 'R');
            $this->SetX(135);
            $this->Cell(60, 5, 'Authorized Signatory', 0, 1, 'R');
        }
    }

    private function setFill($rgb)
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function setDraw($rgb)
    {
        $this->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function setText($rgb)
    {
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    private function joinNonEmpty($values, $separator)
    {
        $out = array();
        foreach ($values as $value) {
            if (trim((string)$value) !== '') $out[] = trim((string)$value);
        }
        return implode($separator, $out);
    }

    private function cleanText($text)
    {
        $text = (string)$text;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) return $converted;
        }
        return preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text);
    }

    private function money($amount, $currency)
    {
        $number = number_format(
            (float)$amount,
            isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2,
            isset($currency['decimal_separator']) ? $currency['decimal_separator'] : '.',
            isset($currency['thousand_separator']) ? $currency['thousand_separator'] : ','
        );

        $symbol = isset($currency['print_symbol']) ? $currency['print_symbol'] : '';
        $position = isset($currency['symbol_position']) ? $currency['symbol_position'] : 'before';
        return $position === 'after' ? $number . ' ' . $symbol : $symbol . $number;
    }

    private function formatQty($qty)
    {
        $qty = (float)$qty;
        return floor($qty) == $qty ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    private function countWrappedLines($width, $text)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($width == 0) $width = $this->w - $this->rMargin - $this->x;
        $wmax = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$text);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue;
            }
            if ($c === ' ') $sep = $i;
            $l += isset($cw[$c]) ? $cw[$c] : 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) $i++;
                } else {
                    $i = $sep + 1;
                }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

try {
    $pdo = fpInvoiceDb();

    $tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
    $loggedBranchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
    $invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($tenantId <= 0) throw new RuntimeException('Your login session is not valid.');
    if ($invoiceId <= 0) throw new RuntimeException('Invalid invoice.');

    $st = $pdo->prepare(
        "SELECT i.*,
                c.display_name AS client_name,
                c.company_name AS client_company_name,
                c.email AS client_email,
                c.phone AS client_phone,
                l.name AS location_name,
                l.address_line1 AS location_address1,
                l.address_line2 AS location_address2,
                l.city AS location_city,
                l.state AS location_state,
                l.postal_code AS location_postal_code
         FROM invoices i
         INNER JOIN clients c
                 ON c.id = i.client_id
                AND c.tenant_id = i.tenant_id
         LEFT JOIN client_locations l
                ON l.id = i.location_id
               AND l.tenant_id = i.tenant_id
               AND l.client_id = i.client_id
         WHERE i.id = :invoice_id
           AND i.tenant_id = :tenant_id
         LIMIT 1"
    );
    $st->execute(array(':invoice_id' => $invoiceId, ':tenant_id' => $tenantId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Invoice not found or you do not have access to it.');

    $invoiceBranchId = !empty($row['branch_id']) ? (int)$row['branch_id'] : 0;
    $effectiveBranchId = $invoiceBranchId > 0 ? $invoiceBranchId : $loggedBranchId;

    $tenantSt = $pdo->prepare(
        "SELECT t.*,
                c.currency_code,
                c.symbol AS currency_symbol,
                c.symbol_position,
                c.decimal_places,
                c.decimal_separator,
                c.thousand_separator
         FROM tenants t
         LEFT JOIN currencies c ON c.id = t.currency_id
         WHERE t.id = :tenant_id
           AND t.deleted_at IS NULL
         LIMIT 1"
    );
    $tenantSt->execute(array(':tenant_id' => $tenantId));
    $tenant = $tenantSt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) throw new RuntimeException('Business details are not available.');

    $branch = null;
    if ($effectiveBranchId > 0) {
        $branchSt = $pdo->prepare(
            "SELECT b.*,
                    c.currency_code,
                    c.symbol AS currency_symbol,
                    c.symbol_position,
                    c.decimal_places,
                    c.decimal_separator,
                    c.thousand_separator
             FROM branches b
             LEFT JOIN currencies c ON c.id = b.currency_id
             WHERE b.id = :branch_id
               AND b.tenant_id = :tenant_id
               AND b.status <> 'archived'
             LIMIT 1"
        );
        $branchSt->execute(array(':branch_id' => $effectiveBranchId, ':tenant_id' => $tenantId));
        $branch = $branchSt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $branchSetting = $effectiveBranchId > 0 ? fpInvoiceLoadSetting($pdo, $tenantId, $effectiveBranchId) : null;
    $businessSetting = fpInvoiceLoadSetting($pdo, $tenantId, 0);

    // Per-field fallback: branch setting -> business setting -> branch master -> tenant master.
    $companyName = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'company_name'),
        fpInvoiceValue($businessSetting, 'company_name'),
        fpInvoiceValue($branch, 'name'),
        fpInvoiceValue($tenant, 'display_name'),
        fpInvoiceValue($tenant, 'legal_name')
    );

    $legalName = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'legal_name'),
        fpInvoiceValue($businessSetting, 'legal_name'),
        fpInvoiceValue($tenant, 'legal_name')
    );

    $companyEmail = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'email'),
        fpInvoiceValue($businessSetting, 'email'),
        fpInvoiceValue($branch, 'email'),
        fpInvoiceValue($tenant, 'email')
    );

    $companyWebsite = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'website_url'),
        fpInvoiceValue($businessSetting, 'website_url'),
        fpInvoiceValue($tenant, 'website_url')
    );

    $companyPhone = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'phone'),
        fpInvoiceValue($businessSetting, 'phone'),
        fpInvoiceValue($branch, 'phone'),
        fpInvoiceValue($tenant, 'phone')
    );

    $registrationNumber = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'registration_number'),
        fpInvoiceValue($businessSetting, 'registration_number'),
        fpInvoiceValue($tenant, 'registration_number')
    );

    $taxNumber = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'tax_number'),
        fpInvoiceValue($businessSetting, 'tax_number'),
        fpInvoiceValue($tenant, 'tax_number')
    );

    $address1 = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'address_line1'),
        fpInvoiceValue($businessSetting, 'address_line1'),
        fpInvoiceValue($branch, 'address_line1'),
        fpInvoiceValue($tenant, 'address_line1')
    );
    $address2 = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'address_line2'),
        fpInvoiceValue($businessSetting, 'address_line2'),
        fpInvoiceValue($branch, 'address_line2'),
        fpInvoiceValue($tenant, 'address_line2')
    );
    $city = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'city'),
        fpInvoiceValue($businessSetting, 'city'),
        fpInvoiceValue($branch, 'city'),
        fpInvoiceValue($tenant, 'city')
    );
    $state = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'state'),
        fpInvoiceValue($businessSetting, 'state'),
        fpInvoiceValue($branch, 'state'),
        fpInvoiceValue($tenant, 'state')
    );
    $postalCode = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'postal_code'),
        fpInvoiceValue($businessSetting, 'postal_code'),
        fpInvoiceValue($branch, 'postal_code'),
        fpInvoiceValue($tenant, 'postal_code')
    );

    $logoPath = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'logo_path'),
        fpInvoiceValue($businessSetting, 'logo_path'),
        fpInvoiceValue($branch, 'logo_path'),
        fpInvoiceValue($tenant, 'logo_path')
    );
    $invoiceLogoPath = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'invoice_logo_path'),
        fpInvoiceValue($businessSetting, 'invoice_logo_path'),
        fpInvoiceValue($branch, 'invoice_logo_path'),
        fpInvoiceValue($tenant, 'invoice_logo_path'),
        $logoPath
    );
    $signaturePath = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'signature_path'),
        fpInvoiceValue($businessSetting, 'signature_path')
    );
    $signatoryName = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'authorized_signatory_name'),
        fpInvoiceValue($businessSetting, 'authorized_signatory_name')
    );
    $invoiceTitle = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'invoice_title'),
        fpInvoiceValue($businessSetting, 'invoice_title'),
        'Invoice'
    );
    $footerNote = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'footer_note'),
        fpInvoiceValue($businessSetting, 'footer_note'),
        'Thank you for your business.'
    );
    $terms = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'terms_and_conditions'),
        fpInvoiceValue($businessSetting, 'terms_and_conditions')
    );

    $itemSt = $pdo->prepare(
        "SELECT item_name, description, quantity, unit_price, discount_amount, tax_percent, tax_amount, line_total
         FROM invoice_line_items
         WHERE invoice_id = :invoice_id
         ORDER BY sort_order ASC, id ASC"
    );
    $itemSt->execute(array(':invoice_id' => $invoiceId));
    $itemRows = $itemSt->fetchAll(PDO::FETCH_ASSOC);

    $items = array();
    foreach ($itemRows as $itemRow) {
        $items[] = array(
            'name' => $itemRow['item_name'],
            'description' => $itemRow['description'] ?: '',
            'qty' => (float)$itemRow['quantity'],
            'unit_price' => (float)$itemRow['unit_price'],
            'total' => (float)$itemRow['line_total']
        );
    }

    if (!$items) {
        $items[] = array(
            'name' => 'Invoice Item',
            'description' => 'No invoice line items were found.',
            'qty' => 1,
            'unit_price' => (float)$row['subtotal'],
            'total' => (float)$row['total']
        );
    }

    $currencySource = ($branch && !empty($branch['currency_code'])) ? $branch : $tenant;
    $currencySymbol = isset($currencySource['currency_symbol']) ? (string)$currencySource['currency_symbol'] : '';
    $currencyCode = isset($currencySource['currency_code']) ? (string)$currencySource['currency_code'] : '';

    // Core FPDF fonts are not Unicode. Use a safe printable fallback for non-ASCII symbols such as INR.
    $printSymbol = $currencySymbol;
    if ($printSymbol === '' || preg_match('/[^\x20-\x7E]/', $printSymbol)) {
        $printSymbol = $currencyCode !== '' ? $currencyCode . ' ' : '';
    }

    $currency = array(
        'print_symbol' => $printSymbol,
        'symbol_position' => isset($currencySource['symbol_position']) ? $currencySource['symbol_position'] : 'before',
        'decimal_places' => isset($currencySource['decimal_places']) ? (int)$currencySource['decimal_places'] : 2,
        'decimal_separator' => isset($currencySource['decimal_separator']) ? $currencySource['decimal_separator'] : '.',
        'thousand_separator' => isset($currencySource['thousand_separator']) ? $currencySource['thousand_separator'] : ','
    );

    $dateFormat = !empty($tenant['date_format']) ? $tenant['date_format'] : 'd-m-Y';
    $recipientAddress = array();
    if (!empty($row['location_address1'])) $recipientAddress[] = $row['location_address1'];
    if (!empty($row['location_address2'])) $recipientAddress[] = $row['location_address2'];
    $recipientCityLine = implode(', ', array_filter(array($row['location_city'], $row['location_state'])));
    if (!empty($row['location_postal_code'])) $recipientCityLine .= ($recipientCityLine !== '' ? ' ' : '') . $row['location_postal_code'];
    if ($recipientCityLine !== '') $recipientAddress[] = $recipientCityLine;

    $recipientContact = implode('  |  ', array_filter(array($row['client_phone'], $row['client_email'])));

    $invoice = array(
        'invoice_no' => $row['invoice_no'],
        'invoice_title' => $invoiceTitle,
        'issued' => $row['issue_date'] ? fpInvoiceDate($row['issue_date'], $dateFormat) : fpInvoiceDate($row['created_at'], $dateFormat),
        'due' => $row['due_date'] ? fpInvoiceDate($row['due_date'], $dateFormat) : ($row['payment_terms'] ?: 'Due on receipt'),
        'currency' => $currency,
        'subtotal' => (float)$row['subtotal'],
        'tax' => (float)$row['tax_total'],
        'discount' => (float)$row['discount_total'],
        'total' => (float)$row['total'],
        'amount_paid' => (float)$row['amount_paid'],
        'balance' => (float)$row['balance_due'],
        'notes' => $row['notes'] ?: '',
        'terms' => $terms,
        'company' => array(
            'name' => $companyName,
            'legal_name' => $legalName,
            'phone' => $companyPhone,
            'email' => $companyEmail,
            'website' => $companyWebsite,
            'registration_number' => $registrationNumber,
            'tax_number' => $taxNumber,
            'address_lines' => array_filter(array($address1, $address2, implode(', ', array_filter(array($city, $state))), $postalCode)),
            'logo' => fpInvoiceLocalImage($logoPath),
            'invoice_logo' => fpInvoiceLocalImage($invoiceLogoPath),
            'signature' => fpInvoiceLocalImage($signaturePath),
            'signatory_name' => $signatoryName
        ),
        'recipient' => array(
            'name' => $row['client_company_name'] ?: $row['client_name'],
            'address_lines' => $recipientAddress,
            'contact' => $recipientContact
        ),
        'items' => $items
    );

    $pdf = new InvoicePDF('P', 'mm', 'A4');
    $pdf->SetTitle('Invoice #' . $invoice['invoice_no']);
    $pdf->SetAuthor($invoice['company']['name']);
    $pdf->setFooterText($footerNote);
    $pdf->AddPage();
    $pdf->DrawInvoice($invoice);
    $pdf->Output('I', 'Invoice-' . fpInvoiceSafeFilename($invoice['invoice_no']) . '.pdf');
    exit;

} catch (Throwable $e) {
    error_log('FieldPlx dynamic invoice PDF: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate invoice: ' . $e->getMessage();
    exit;
}