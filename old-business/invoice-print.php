<?php
/**
 * FieldPlx Dynamic Invoice PDF
 * Version 1.3.0 - 2026-09-08
 *
 * Updated for the current FieldPlx invoice flow:
 * - Uses invoice subject instead of a hard-coded heading.
 * - Respects Client View options for quantity, unit price, line totals,
 *   account balance and overdue/late stamp.
 * - Shows service dates, custom fields, client message, contract/disclaimer,
 *   latest collected client signature only.
 * - Shows billing and property/service addresses separately.
 * - Keeps internal notes private; they are intentionally not printed.
 * - Preserves branch/business invoice settings fallback behavior.
 * - Supports in-memory PDF capture for SMTP invoice attachments.
 *
 * PHP 7.2 compatible.
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
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name");
    $st->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);
    return $cache[$table];
}

function fpInvoiceColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
    $st->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$st->fetchColumn() > 0);
    return $cache[$key];
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
        if ($value !== null && trim((string)$value) !== '') return trim((string)$value);
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
    if (in_array($ext, array('jpg', 'jpeg', 'png'), true)) return $candidate;

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
        $st = $pdo->prepare("SELECT * FROM invoice_settings WHERE tenant_id=:tenant_id AND branch_id=:branch_id ORDER BY id DESC LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId, ':branch_id' => $branchId));
    } else {
        $st = $pdo->prepare("SELECT * FROM invoice_settings WHERE tenant_id=:tenant_id AND branch_id IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId));
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fpInvoiceDecodeClientView($value)
{
    $defaults = array(
        'quantities' => true,
        'unit_prices' => true,
        'line_item_totals' => true,
        'account_balance' => true,
        'late_stamp' => true
    );

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            foreach ($defaults as $key => $default) {
                if (array_key_exists($key, $decoded)) $defaults[$key] = (bool)$decoded[$key];
            }
        }
    }

    return $defaults;
}

function fpInvoiceAddressLines($row, $prefix)
{
    $lines = array();
    $a1 = fpInvoiceValue($row, $prefix . 'address_line1');
    $a2 = fpInvoiceValue($row, $prefix . 'address_line2');
    $city = fpInvoiceValue($row, $prefix . 'city');
    $state = fpInvoiceValue($row, $prefix . 'state');
    $postal = fpInvoiceValue($row, $prefix . 'postal_code');

    if ($a1 !== '') $lines[] = $a1;
    if ($a2 !== '') $lines[] = $a2;

    $cityLine = implode(', ', array_filter(array($city, $state)));
    if ($postal !== '') $cityLine .= ($cityLine !== '' ? ' ' : '') . $postal;
    if ($cityLine !== '') $lines[] = $cityLine;

    return $lines;
}

class InvoicePDF extends FPDF
{
    private $darkGray = array(70, 77, 83);
    private $lightGray = array(246, 247, 248);
    private $borderGray = array(205, 211, 216);
    private $textGray = array(65, 76, 83);
    private $green = array(47, 141, 37);
    private $red = array(194, 57, 52);
    private $footerText = 'Thank you for your business.';

    public function setFooterText($text)
    {
        $text = trim((string)$text);
        if ($text !== '') $this->footerText = $text;
    }

    public function Header()
    {
        // Content is drawn explicitly by DrawInvoice().
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
        $view = $invoice['client_view'];

        $this->drawCompanyHeader($company);
        $bodyStartY = $this->drawRecipientAndSummary($invoice, $recipient, $view);
        $this->SetY($bodyStartY + 8);

        if (!empty($invoice['custom_fields'])) {
            $this->drawCustomFields($invoice['custom_fields']);
        }

        $this->drawItems($invoice, $items, $view);
        $this->drawTotals($invoice, $view);


        if (!empty($invoice['terms'])) {
            $this->drawTextSection('Terms & Conditions', $invoice['terms']);
        }


        if (!empty($invoice['client_signature'])) {
            $this->drawClientSignature($invoice['client_signature']);
        }

        $this->drawCompanySignature($company);
    }

    private function drawCompanyHeader($company)
    {
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
        if ($companyLine !== '') $this->Cell(62, 5, $this->cleanText($companyLine), 0, 1, 'L');

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
    }

    private function drawRecipientAndSummary($invoice, $recipient, $view)
    {
        $recipientY = 55;
        $leftX = 15;
        $leftW = 90;

        $this->SetXY($leftX, $recipientY);
        $this->SetFont('Arial', 'B', 9.5);
        $this->Cell($leftW, 5, 'CLIENT', 0, 1, 'L');

        $this->SetX($leftX);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell($leftW, 7, $this->cleanText($recipient['name']), 0, 1, 'L');

        if (!empty($recipient['billing_address'])) {
            $this->SetX($leftX);
            $this->SetFont('Arial', 'B', 8.2);
            $this->SetTextColor(90, 90, 90);
            $this->Cell($leftW, 4.5, 'Billing Address', 0, 1, 'L');
            $this->SetFont('Arial', '', 8.8);
            foreach ($recipient['billing_address'] as $line) {
                $this->SetX($leftX);
                $this->Cell($leftW, 4.5, $this->cleanText($line), 0, 1, 'L');
            }
        }

        if (!empty($recipient['property_address'])) {
            $this->Ln(1);
            $this->SetX($leftX);
            $this->SetFont('Arial', 'B', 8.2);
            $this->SetTextColor(90, 90, 90);
            $this->Cell($leftW, 4.5, 'Property / Service Address', 0, 1, 'L');
            $this->SetFont('Arial', '', 8.8);
            foreach ($recipient['property_address'] as $line) {
                $this->SetX($leftX);
                $this->Cell($leftW, 4.5, $this->cleanText($line), 0, 1, 'L');
            }
        }

        if (!empty($recipient['contact'])) {
            $this->Ln(1);
            $this->SetX($leftX);
            $this->SetFont('Arial', '', 8.8);
            $this->SetTextColor(70, 70, 70);
            $this->MultiCell($leftW, 4.5, $this->cleanText($recipient['contact']), 0, 'L');
        }

        $leftBottom = $this->GetY();
        $this->SetTextColor(0, 0, 0);

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

        $y = $boxY + 14;
        $rows = array(
            array('Issued', $invoice['issued']),
            array('Due', $invoice['due']),
            array('Terms', $invoice['payment_terms'])
        );
        if (!empty($invoice['salesperson'])) $rows[] = array('Salesperson', $invoice['salesperson']);
        if (!empty($invoice['job_no'])) $rows[] = array('Job', $invoice['job_no']);
        elseif (!empty($invoice['source_label'])) $rows[] = array('Source', $invoice['source_label']);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(0, 0, 0);
        foreach ($rows as $idx => $row) {
            $this->SetXY($boxX, $y);
            $this->setFill(($idx % 2) === 0 ? $this->lightGray : array(255,255,255));
            $this->Cell($labelW, 8, $this->cleanText($row[0]), 0, 0, 'L', true);
            $this->Cell($valueW, 8, $this->cleanText($row[1]), 0, 1, 'R', true);
            $y += 8;
        }

        $this->SetXY($boxX, $y);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell($labelW, 10, 'Total', 0, 0, 'L', true);
        $this->Cell($valueW, 10, $this->money($invoice['total'], $invoice['currency']), 0, 1, 'R', true);
        $y += 10;

        if (!empty($view['account_balance'])) {
            $this->SetXY($boxX, $y);
            $this->setFill($this->lightGray);
            $this->SetTextColor(70, 70, 70);
            $this->SetFont('Arial', '', 8.5);
            $this->Cell($labelW + 12, 8, 'Account Balance', 0, 0, 'L', true);
            $this->Cell($valueW - 12, 8, $this->money($invoice['balance'], $invoice['currency']), 0, 1, 'R', true);
            $y += 8;
        }

        if (!empty($invoice['is_overdue']) && !empty($view['late_stamp'])) {
            $this->SetXY($boxX, $y + 3);
            $this->SetTextColor($this->red[0], $this->red[1], $this->red[2]);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($boxW, 7, 'OVERDUE', 1, 1, 'C');
            $y += 10;
        }

        $this->SetTextColor(0, 0, 0);
        return max($leftBottom, $y);
    }

    private function drawCustomFields($fields)
    {
        $this->ensureSpace(20);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(45, 45, 45);
        $this->Cell(180, 6, 'Invoice Details', 0, 1, 'L');

        $this->SetFont('Arial', '', 8.7);
        foreach ($fields as $field) {
            $label = trim((string)$field['label']);
            $value = trim((string)$field['value']);
            if ($label === '' && $value === '') continue;

            $this->ensureSpace(6);
            $this->SetX(15);
            $this->SetTextColor(95, 95, 95);
            $this->Cell(42, 5, $this->cleanText($label !== '' ? $label : 'Field'), 0, 0, 'L');
            $this->SetTextColor(30, 30, 30);
            $this->MultiCell(138, 5, $this->cleanText($value), 0, 'L');
        }
        $this->Ln(3);
    }

    private function drawItems($invoice, $items, $view)
    {
        $this->ensureSpace(28);
        $this->SetX(15);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 14);
        $sectionTitle = !empty($invoice['subject']) ? $invoice['subject'] : 'Product / Service';
        $this->Cell(180, 9, $this->cleanText($sectionTitle), 0, 1, 'L');

        $showQty = !empty($view['quantities']);
        $showUnit = !empty($view['unit_prices']);
        $showLineTotal = !empty($view['line_item_totals']);

        $numericWidth = 0;
        if ($showQty) $numericWidth += 18;
        if ($showUnit) $numericWidth += 28;
        if ($showLineTotal) $numericWidth += 28;

        $nameW = 48;
        $descriptionW = 180 - $nameW - $numericWidth;
        if ($descriptionW < 45) {
            $nameW = 42;
            $descriptionW = 180 - $nameW - $numericWidth;
        }

        $x = 15;
        $headerH = 10;
        $this->SetX($x);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Arial', 'B', 9.2);
        $this->Cell($nameW, $headerH, 'Product / Service', 0, 0, 'L', true);
        $this->Cell($descriptionW, $headerH, 'Description', 0, 0, 'L', true);
        if ($showQty) $this->Cell(18, $headerH, 'Qty', 0, 0, 'C', true);
        if ($showUnit) $this->Cell(28, $headerH, 'Unit Price', 0, 0, 'R', true);
        if ($showLineTotal) $this->Cell(28, $headerH, 'Total', 0, 0, 'R', true);
        $this->Ln($headerH);

        $this->SetTextColor(0,0,0);
        foreach ($items as $item) {
            $description = trim((string)$item['description']);
            if (!empty($item['service_date'])) {
                $description .= ($description !== '' ? "\n" : '') . 'Service date: ' . $item['service_date'];
            }

            $name = $this->cleanText($item['name']);
            $description = $this->cleanText($description);
            $lineH = 5.0;
            $topPad = 2.0;
            $bottomPad = 2.0;
            $nameLines = max(1, $this->countWrappedLines($nameW - 4, $name));
            $descLines = max(1, $this->countWrappedLines($descriptionW - 4, $description));
            $rowH = max(12.5, max($nameLines, $descLines) * $lineH + $topPad + $bottomPad);

            if ($this->GetY() + $rowH > 266) {
                $this->AddPage();
                $this->SetY(20);
                $this->SetX($x);
                $this->setFill($this->darkGray);
                $this->SetTextColor(255,255,255);
                $this->SetFont('Arial', 'B', 9.2);
                $this->Cell($nameW, $headerH, 'Product / Service', 0, 0, 'L', true);
                $this->Cell($descriptionW, $headerH, 'Description', 0, 0, 'L', true);
                if ($showQty) $this->Cell(18, $headerH, 'Qty', 0, 0, 'C', true);
                if ($showUnit) $this->Cell(28, $headerH, 'Unit Price', 0, 0, 'R', true);
                if ($showLineTotal) $this->Cell(28, $headerH, 'Total', 0, 0, 'R', true);
                $this->Ln($headerH);
                $this->SetTextColor(0,0,0);
            }

            $rowY = $this->GetY();
            $textY = $rowY + $topPad;
            $this->SetFont('Arial', '', 9.2);
            $this->SetXY($x, $textY);
            $this->MultiCell($nameW, $lineH, $name, 0, 'L');

            $this->SetXY($x + $nameW, $textY);
            $this->MultiCell($descriptionW, $lineH, $description, 0, 'L');

            $numericX = $x + $nameW + $descriptionW;
            $this->SetXY($numericX, $textY);
            if ($showQty) $this->Cell(18, $lineH, $this->formatQty($item['qty']), 0, 0, 'C');
            if ($showUnit) $this->Cell(28, $lineH, $this->money($item['unit_price'], $invoice['currency']), 0, 0, 'R');
            if ($showLineTotal) $this->Cell(28, $lineH, $this->money($item['total'], $invoice['currency']), 0, 0, 'R');

            $this->SetDrawColor(228, 232, 235);
            $this->Line($x, $rowY + $rowH, 195, $rowY + $rowH);
            $this->SetY($rowY + $rowH);
        }

        $this->Ln(5);
    }

    private function drawTotals($invoice, $view)
    {
        $this->ensureSpace(45);
        $totalsX = 128;
        $labelWidth = 37;
        $amountWidth = 30;
        $this->SetFont('Arial', '', 9.5);
        $this->setText($this->textGray);

        $this->SetX($totalsX);
        $this->Cell($labelWidth, 7, 'Subtotal', 0, 0, 'R');
        $this->Cell($amountWidth, 7, $this->money($invoice['subtotal'], $invoice['currency']), 0, 1, 'R');

        if ((float)$invoice['discount'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Discount', 0, 0, 'R');
            $this->Cell($amountWidth, 7, '-' . $this->money($invoice['discount'], $invoice['currency']), 0, 1, 'R');
        }

        if ((float)$invoice['tax'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Tax', 0, 0, 'R');
            $this->Cell($amountWidth, 7, $this->money($invoice['tax'], $invoice['currency']), 0, 1, 'R');
        }

        $this->SetX($totalsX);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0,0,0);
        $this->Cell($labelWidth, 8, 'Total', 0, 0, 'R');
        $this->Cell($amountWidth, 8, $this->money($invoice['total'], $invoice['currency']), 0, 1, 'R');

        if ((float)$invoice['amount_paid'] > 0) {
            $this->SetX($totalsX);
            $this->SetFont('Arial', '', 9.5);
            $this->Cell($labelWidth, 7, 'Paid', 0, 0, 'R');
            $this->Cell($amountWidth, 7, '-' . $this->money($invoice['amount_paid'], $invoice['currency']), 0, 1, 'R');
        }

        if (!empty($view['account_balance'])) {
            $this->SetX($totalsX);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($labelWidth, 7, 'Balance Due', 0, 0, 'R');
            $this->Cell($amountWidth, 7, $this->money($invoice['balance'], $invoice['currency']), 0, 1, 'R');
        }

        $this->Ln(3);
    }

    private function drawTextSection($title, $text)
    {
        $text = trim((string)$text);
        if ($text === '') return;

        $this->ensureSpace(22);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35,35,35);
        $this->Cell(180, 6, $this->cleanText($title), 0, 1, 'L');
        $this->SetX(15);
        $this->SetFont('Arial', '', 8.4);
        $this->SetTextColor(85,85,85);
        $this->MultiCell(180, 4.5, $this->cleanText($text), 0, 'L');
        $this->Ln(3);
    }

    private function drawImageSection($images)
    {
        $valid = array();
        foreach ($images as $image) {
            $path = isset($image['path']) ? $image['path'] : '';
            if ($path && is_file($path)) $valid[] = $image;
        }
        if (!$valid) return;

        $this->ensureSpace(42);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35,35,35);
        $this->Cell(180, 6, 'Images', 0, 1, 'L');
        $this->Ln(2);

        $thumbW = 54;
        $thumbH = 38;
        $gap = 9;
        $col = 0;
        $x0 = 15;
        $y = $this->GetY();

        foreach ($valid as $image) {
            if ($col === 0 && $y + $thumbH + 8 > 265) {
                $this->AddPage();
                $y = 20;
            }
            $x = $x0 + $col * ($thumbW + $gap);
            try {
                $this->Image($image['path'], $x, $y, $thumbW, $thumbH);
            } catch (Exception $e) {
                // Skip unreadable image.
            }
            $col++;
            if ($col >= 3) {
                $col = 0;
                $y += $thumbH + 8;
            }
        }
        if ($col > 0) $y += $thumbH + 8;
        $this->SetY($y);
    }

    private function drawAttachmentList($attachments)
    {
        if (!$attachments) return;
        $this->ensureSpace(18);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35,35,35);
        $this->Cell(180, 6, 'Attachments', 0, 1, 'L');
        $this->SetFont('Arial', '', 8.3);
        $this->SetTextColor(85,85,85);
        foreach ($attachments as $attachment) {
            $name = trim((string)$attachment['name']);
            if ($name === '') continue;
            $this->ensureSpace(5);
            $this->SetX(18);
            $this->Cell(177, 5, $this->cleanText('- ' . $name), 0, 1, 'L');
        }
        $this->Ln(3);
    }

    private function drawClientSignature($signature)
    {
        if (empty($signature['path']) || !is_file($signature['path'])) return;
        $this->ensureSpace(32);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35,35,35);
        $this->Cell(90, 6, 'Client Signature', 0, 1, 'L');
        $y = $this->GetY() + 2;
        try {
            $this->Image($signature['path'], 15, $y, 45, 18);
        } catch (Exception $e) {
            return;
        }
        $this->SetY($y + 21);
        if (!empty($signature['created_at'])) {
            $this->SetX(15);
            $this->SetFont('Arial', '', 7.8);
            $this->SetTextColor(95,95,95);
            $this->Cell(90, 4.5, $this->cleanText('Collected: ' . $signature['created_at']), 0, 1, 'L');
        }
        $this->Ln(2);
    }

    private function drawCompanySignature($company)
    {
        if (!empty($company['signature']) && is_file($company['signature'])) {
            $this->ensureSpace(30);
            $sigY = $this->GetY() + 3;
            try {
                $this->Image($company['signature'], 150, $sigY, 35, 15);
            } catch (Exception $e) {
                return;
            }
            $this->SetXY(135, $sigY + 16);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(90,90,90);
            $this->Cell(60, 5, $this->cleanText($company['signatory_name']), 0, 1, 'R');
            $this->SetX(135);
            $this->Cell(60, 5, 'Authorized Signature', 0, 1, 'R');
        } elseif (!empty($company['signatory_name'])) {
            $this->ensureSpace(16);
            $this->Ln(5);
            $this->SetX(135);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(90,90,90);
            $this->Cell(60, 5, $this->cleanText($company['signatory_name']), 0, 1, 'R');
            $this->SetX(135);
            $this->Cell(60, 5, 'Authorized Signatory', 0, 1, 'R');
        }
    }

    private function ensureSpace($height)
    {
        if ($this->GetY() + (float)$height > 266) {
            $this->AddPage();
            $this->SetY(20);
        }
    }

    private function setFill($rgb)
    {
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
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

    $hasUsers = fpInvoiceTableExists($pdo, 'users');
    $hasJobs = fpInvoiceTableExists($pdo, 'jobs');
    $hasQuotes = fpInvoiceTableExists($pdo, 'quotes');

    $salespersonSelect = $hasUsers
        ? ",TRIM(CONCAT(COALESCE(u.first_name,''),CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END)) AS salesperson_name"
        : ",'' AS salesperson_name";
    $salespersonJoin = $hasUsers ? " LEFT JOIN users u ON u.id=i.created_by AND u.tenant_id=i.tenant_id " : '';
    $jobSelect = $hasJobs ? ",j.job_no" : ",NULL AS job_no";
    $jobJoin = $hasJobs ? " LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id " : '';
    $quoteSelect = $hasQuotes ? ",q.quote_no" : ",NULL AS quote_no";
    $quoteJoin = $hasQuotes ? " LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id " : '';

    $st = $pdo->prepare(
        "SELECT i.*,
                c.display_name AS client_name,
                c.company_name AS client_company_name,
                c.email AS client_email,
                c.phone AS client_phone,
                l.name AS location_name,
                l.address_line1 AS location_address_line1,
                l.address_line2 AS location_address_line2,
                l.city AS location_city,
                l.state AS location_state,
                l.postal_code AS location_postal_code" .
                $salespersonSelect . $jobSelect . $quoteSelect . "
         FROM invoices i
         INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id
         LEFT JOIN client_locations l ON l.id=i.location_id AND l.tenant_id=i.tenant_id AND l.client_id=i.client_id" .
         $salespersonJoin . $jobJoin . $quoteJoin . "
         WHERE i.id=:invoice_id AND i.tenant_id=:tenant_id
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
         LEFT JOIN currencies c ON c.id=t.currency_id
         WHERE t.id=:tenant_id AND t.deleted_at IS NULL
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
             LEFT JOIN currencies c ON c.id=b.currency_id
             WHERE b.id=:branch_id AND b.tenant_id=:tenant_id AND b.status<>'archived'
             LIMIT 1"
        );
        $branchSt->execute(array(':branch_id' => $effectiveBranchId, ':tenant_id' => $tenantId));
        $branch = $branchSt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $branchSetting = $effectiveBranchId > 0 ? fpInvoiceLoadSetting($pdo, $tenantId, $effectiveBranchId) : null;
    $businessSetting = fpInvoiceLoadSetting($pdo, $tenantId, 0);

    $companyName = fpInvoiceFirstValue(
        fpInvoiceValue($branchSetting, 'company_name'),
        fpInvoiceValue($businessSetting, 'company_name'),
        fpInvoiceValue($branch, 'name'),
        fpInvoiceValue($tenant, 'display_name'),
        fpInvoiceValue($tenant, 'legal_name')
    );
    $legalName = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'legal_name'), fpInvoiceValue($businessSetting, 'legal_name'), fpInvoiceValue($tenant, 'legal_name'));
    $companyEmail = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'email'), fpInvoiceValue($businessSetting, 'email'), fpInvoiceValue($branch, 'email'), fpInvoiceValue($tenant, 'email'));
    $companyWebsite = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'website_url'), fpInvoiceValue($businessSetting, 'website_url'), fpInvoiceValue($tenant, 'website_url'));
    $companyPhone = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'phone'), fpInvoiceValue($businessSetting, 'phone'), fpInvoiceValue($branch, 'phone'), fpInvoiceValue($tenant, 'phone'));
    $registrationNumber = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'registration_number'), fpInvoiceValue($businessSetting, 'registration_number'), fpInvoiceValue($tenant, 'registration_number'));
    $taxNumber = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'tax_number'), fpInvoiceValue($businessSetting, 'tax_number'), fpInvoiceValue($tenant, 'tax_number'));
    $address1 = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'address_line1'), fpInvoiceValue($businessSetting, 'address_line1'), fpInvoiceValue($branch, 'address_line1'), fpInvoiceValue($tenant, 'address_line1'));
    $address2 = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'address_line2'), fpInvoiceValue($businessSetting, 'address_line2'), fpInvoiceValue($branch, 'address_line2'), fpInvoiceValue($tenant, 'address_line2'));
    $city = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'city'), fpInvoiceValue($businessSetting, 'city'), fpInvoiceValue($branch, 'city'), fpInvoiceValue($tenant, 'city'));
    $state = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'state'), fpInvoiceValue($businessSetting, 'state'), fpInvoiceValue($branch, 'state'), fpInvoiceValue($tenant, 'state'));
    $postalCode = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'postal_code'), fpInvoiceValue($businessSetting, 'postal_code'), fpInvoiceValue($branch, 'postal_code'), fpInvoiceValue($tenant, 'postal_code'));
    $logoPath = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'logo_path'), fpInvoiceValue($businessSetting, 'logo_path'), fpInvoiceValue($branch, 'logo_path'), fpInvoiceValue($tenant, 'logo_path'));
    $invoiceLogoPath = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'invoice_logo_path'), fpInvoiceValue($businessSetting, 'invoice_logo_path'), fpInvoiceValue($branch, 'invoice_logo_path'), fpInvoiceValue($tenant, 'invoice_logo_path'), $logoPath);
    $signaturePath = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'signature_path'), fpInvoiceValue($businessSetting, 'signature_path'));
    $signatoryName = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'authorized_signatory_name'), fpInvoiceValue($businessSetting, 'authorized_signatory_name'));
    $invoiceTitle = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'invoice_title'), fpInvoiceValue($businessSetting, 'invoice_title'), 'Invoice');
    $footerNote = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'footer_note'), fpInvoiceValue($businessSetting, 'footer_note'), 'Thank you for your business.');
    $terms = fpInvoiceFirstValue(fpInvoiceValue($branchSetting, 'terms_and_conditions'), fpInvoiceValue($businessSetting, 'terms_and_conditions'));

    $serviceDateSelect = fpInvoiceColumnExists($pdo, 'invoice_line_items', 'service_date') ? ',service_date' : ',NULL AS service_date';
    $itemSt = $pdo->prepare(
        "SELECT item_name,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total" . $serviceDateSelect . "
         FROM invoice_line_items
         WHERE invoice_id=:invoice_id
         ORDER BY sort_order ASC,id ASC"
    );
    $itemSt->execute(array(':invoice_id' => $invoiceId));
    $itemRows = $itemSt->fetchAll(PDO::FETCH_ASSOC);

    $dateFormat = !empty($tenant['date_format']) ? $tenant['date_format'] : 'd-m-Y';
    $items = array();
    foreach ($itemRows as $itemRow) {
        $items[] = array(
            'name' => $itemRow['item_name'],
            'description' => $itemRow['description'] ?: '',
            'qty' => (float)$itemRow['quantity'],
            'unit_price' => (float)$itemRow['unit_price'],
            'total' => (float)$itemRow['line_total'],
            'service_date' => !empty($itemRow['service_date']) ? fpInvoiceDate($itemRow['service_date'], $dateFormat) : ''
        );
    }

    if (!$items) {
        $items[] = array(
            'name' => 'Invoice Item',
            'description' => 'No invoice line items were found.',
            'qty' => 1,
            'unit_price' => (float)$row['subtotal'],
            'total' => (float)$row['total'],
            'service_date' => ''
        );
    }

    $customFields = array();
    if (fpInvoiceTableExists($pdo, 'invoice_custom_fields')) {
        $cf = $pdo->prepare("SELECT field_label,field_value FROM invoice_custom_fields WHERE tenant_id=:tenant_id AND invoice_id=:invoice_id ORDER BY sort_order,id");
        $cf->execute(array(':tenant_id' => $tenantId, ':invoice_id' => $invoiceId));
        foreach ($cf->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $customFields[] = array(
                'label' => isset($field['field_label']) ? $field['field_label'] : '',
                'value' => isset($field['field_value']) ? $field['field_value'] : ''
            );
        }
    }

    // Only the collected invoice signature is allowed on the PDF.
    // Client Message, Contract / Disclaimer, invoice images and all other
    // attachments are intentionally excluded from printed/PDF output.
    $clientSignature = null;
    if (fpInvoiceTableExists($pdo, 'attachments')) {
        $at = $pdo->prepare("SELECT file_path,created_at FROM attachments WHERE tenant_id=:tenant_id AND related_type='invoice' AND related_id=:invoice_id AND attachment_type='signature' ORDER BY id DESC LIMIT 1");
        $at->execute(array(':tenant_id' => $tenantId, ':invoice_id' => $invoiceId));
        $attachment = $at->fetch(PDO::FETCH_ASSOC);
        if ($attachment) {
            $localPath = fpInvoiceLocalImage($attachment['file_path']);
            if ($localPath !== '') {
                $clientSignature = array(
                    'path' => $localPath,
                    'created_at' => !empty($attachment['created_at']) ? fpInvoiceDate($attachment['created_at'], 'd M Y, h:i A') : ''
                );
            }
        }
    }

    $billingAddress = array();
    if (fpInvoiceTableExists($pdo, 'client_locations')) {
        $billingSt = $pdo->prepare("SELECT address_line1,address_line2,city,state,postal_code FROM client_locations WHERE tenant_id=:tenant_id AND client_id=:client_id AND deleted_at IS NULL AND status='active' ORDER BY (location_type='billing') DESC,is_primary DESC,id ASC LIMIT 1");
        $billingSt->execute(array(':tenant_id' => $tenantId, ':client_id' => (int)$row['client_id']));
        $billingRow = $billingSt->fetch(PDO::FETCH_ASSOC);
        if ($billingRow) {
            $billingAddress = fpInvoiceAddressLines(array(
                'billing_address_line1' => $billingRow['address_line1'],
                'billing_address_line2' => $billingRow['address_line2'],
                'billing_city' => $billingRow['city'],
                'billing_state' => $billingRow['state'],
                'billing_postal_code' => $billingRow['postal_code']
            ), 'billing_');
        }
    }

    $propertyAddress = fpInvoiceAddressLines($row, 'location_');
    if (!$propertyAddress && $billingAddress) $propertyAddress = $billingAddress;

    $currencySource = ($branch && !empty($branch['currency_code'])) ? $branch : $tenant;
    $currencySymbol = isset($currencySource['currency_symbol']) ? (string)$currencySource['currency_symbol'] : '';
    $currencyCode = isset($currencySource['currency_code']) ? (string)$currencySource['currency_code'] : '';
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

    $clientView = fpInvoiceDecodeClientView(isset($row['client_view_options']) ? $row['client_view_options'] : '');
    $balance = isset($row['balance_due']) ? (float)$row['balance_due'] : 0.0;
    $isOverdue = $balance > 0.005 && !empty($row['due_date']) && substr((string)$row['due_date'], 0, 10) < date('Y-m-d');

    $recipientContact = implode('  |  ', array_filter(array($row['client_phone'], $row['client_email'])));
    $sourceLabel = !empty($row['job_id']) ? 'From Job Card' : 'Direct Invoice';

    $invoice = array(
        'invoice_no' => $row['invoice_no'],
        'invoice_title' => $invoiceTitle,
        'subject' => !empty($row['subject']) ? $row['subject'] : 'Product / Service',
        'issued' => $row['issue_date'] ? fpInvoiceDate($row['issue_date'], $dateFormat) : fpInvoiceDate($row['created_at'], $dateFormat),
        'due' => $row['due_date'] ? fpInvoiceDate($row['due_date'], $dateFormat) : ($row['payment_terms'] ?: 'Due on receipt'),
        'payment_terms' => !empty($row['payment_terms']) ? $row['payment_terms'] : 'Due upon receipt',
        'salesperson' => !empty($row['salesperson_name']) ? $row['salesperson_name'] : '',
        'job_no' => !empty($row['job_no']) ? $row['job_no'] : '',
        'quote_no' => !empty($row['quote_no']) ? $row['quote_no'] : '',
        'source_label' => $sourceLabel,
        'currency' => $currency,
        'subtotal' => (float)$row['subtotal'],
        'tax' => (float)$row['tax_total'],
        'discount' => (float)$row['discount_total'],
        'total' => (float)$row['total'],
        'amount_paid' => (float)$row['amount_paid'],
        'balance' => $balance,
        'is_overdue' => $isOverdue,
        'client_view' => $clientView,
        'terms' => $terms,
        'custom_fields' => $customFields,
        'client_signature' => $clientSignature,
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
            'billing_address' => $billingAddress,
            'property_address' => $propertyAddress,
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

    $outputName = 'Invoice-' . fpInvoiceSafeFilename($invoice['invoice_no']) . '.pdf';

    /*
     * Capture mode is used by invoice-form.php / invoice-view.php when the
     * invoice must be attached to an SMTP email. Normal browser printing is
     * unchanged when capture mode is not enabled.
     */
    if (defined('FIELDPLX_INVOICE_PDF_CAPTURE') && FIELDPLX_INVOICE_PDF_CAPTURE) {
        $bytes = $pdf->Output('S', $outputName);
        if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== '%PDF') {
            throw new RuntimeException('Invoice PDF capture did not return a valid PDF document.');
        }
        $GLOBALS['fieldplx_invoice_pdf_bytes'] = $bytes;
        $GLOBALS['fieldplx_invoice_pdf_name'] = $outputName;
        return;
    }

    $pdf->Output('I', $outputName);
    exit;

} catch (Throwable $e) {
    error_log('FieldPlx dynamic invoice PDF: ' . $e->getMessage());

    if (defined('FIELDPLX_INVOICE_PDF_CAPTURE') && FIELDPLX_INVOICE_PDF_CAPTURE) {
        throw $e;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate invoice: ' . $e->getMessage();
    exit;
}
