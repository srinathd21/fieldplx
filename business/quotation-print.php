<?php
/**
 * FieldPlx Dynamic Quotation PDF / Print
 * Version 1.0.0 - 2026-09-09
 *
 * Built from the current FieldPlx invoice-print.php PDF pattern and adapted
 * for the Jobber-style quotation workflow used by FieldPlx.
 *
 * Features:
 * - Tenant/branch scoped quotation loading.
 * - Same business/branch branding fallback used by the invoice PDF.
 * - Customer billing + property/service addresses.
 * - Quote number, created date, valid-until date, salesperson and total.
 * - Client View controls for quantity, unit price, line totals and totals.
 * - Introduction, custom text sections, client message and disclaimer.
 * - Deposit-only and payment-schedule summaries.
 * - Latest collected quotation signature.
 * - Internal notes, unit cost and markup are intentionally never printed.
 * - Optional in-memory PDF capture for SMTP attachments.
 *
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/libs/fpdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fpQuoteDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function fpQuoteTableExists(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name");
    $st->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);
    return $cache[$table];
}

function fpQuoteColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
    $st->execute(array(':table_name' => $table, ':column_name' => $column));
    $cache[$key] = ((int)$st->fetchColumn() > 0);
    return $cache[$key];
}

function fpQuoteValue($row, $key)
{
    if (!is_array($row) || !array_key_exists($key, $row)) return '';
    return trim((string)$row[$key]);
}

function fpQuoteFirstValue()
{
    $args = func_get_args();
    foreach ($args as $value) {
        if ($value !== null && trim((string)$value) !== '') return trim((string)$value);
    }
    return '';
}

function fpQuoteDate($value, $format)
{
    if (!$value) return '';
    $time = strtotime((string)$value);
    if ($time === false) return (string)$value;
    return date($format ?: 'd-m-Y', $time);
}

function fpQuoteSafeFilename($value)
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'quote';
}

function fpQuoteLocalImage($path)
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
            $tmp = sys_get_temp_dir() . '/fieldplx-quote-' . md5($candidate . filemtime($candidate)) . '.png';
            if (!is_file($tmp)) @imagepng($img, $tmp);
            imagedestroy($img);
            if (is_file($tmp)) return $tmp;
        }
    }

    return '';
}

function fpQuoteLoadBrandSetting(PDO $pdo, $tenantId, $branchId)
{
    // Reuse the same business/branch print branding source used by invoice-print.php.
    if (!fpQuoteTableExists($pdo, 'invoice_settings')) return null;

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

function fpQuoteDecodeClientView($value)
{
    $defaults = array(
        'quantities' => true,
        'unit_prices' => true,
        'line_item_totals' => true,
        'totals' => true
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

function fpQuoteAddressLines($row, $prefix)
{
    $lines = array();
    $a1 = fpQuoteValue($row, $prefix . 'address_line1');
    $a2 = fpQuoteValue($row, $prefix . 'address_line2');
    $city = fpQuoteValue($row, $prefix . 'city');
    $state = fpQuoteValue($row, $prefix . 'state');
    $postal = fpQuoteValue($row, $prefix . 'postal_code');

    if ($a1 !== '') $lines[] = $a1;
    if ($a2 !== '') $lines[] = $a2;

    $cityLine = implode(', ', array_filter(array($city, $state)));
    if ($postal !== '') $cityLine .= ($cityLine !== '' ? ' ' : '') . $postal;
    if ($cityLine !== '') $lines[] = $cityLine;

    return $lines;
}

class QuotePDF extends FPDF
{
    private $darkGray = array(82, 82, 82);
    private $lightGray = array(246, 247, 248);
    private $borderGray = array(210, 215, 219);
    private $textGray = array(65, 76, 83);
    private $green = array(47, 141, 37);
    private $red = array(212, 68, 62);
    private $footerText = 'Thank you for the opportunity to provide this quote.';

    public function setFooterText($text)
    {
        $text = trim((string)$text);
        if ($text !== '') $this->footerText = $text;
    }

    public function Header()
    {
        // Content is rendered explicitly by DrawQuote().
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor(215, 218, 221);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, $this->cleanText($this->footerText), 0, 0, 'C');
    }

    public function DrawQuote(array $quote)
    {
        $this->SetMargins(15, 14, 15);
        $this->SetAutoPageBreak(true, 22);
        $this->SetTextColor(0, 0, 0);

        $company = $quote['company'];
        $recipient = $quote['recipient'];
        $view = $quote['client_view'];

        $this->drawCompanyHeader($company);
        $bodyStartY = $this->drawRecipientAndSummary($quote, $recipient, $view);
        $this->SetY($bodyStartY + 8);

        if (!empty($quote['custom_fields'])) {
            $this->drawCustomFields($quote['custom_fields']);
        }

        if (!empty($quote['introduction_title']) || !empty($quote['introduction']) || !empty($quote['introduction_image'])) {
            $this->drawIntroduction($quote);
        }

        foreach ($quote['text_sections'] as $section) {
            $title = trim((string)$section['title']);
            $body = trim((string)$section['body']);
            if ($title === '' && $body === '') continue;
            $this->drawTextSection($title !== '' ? $title : 'Details', $body);
        }

        $this->drawItems($quote, $quote['items'], $view);

        if (!empty($view['totals'])) {
            $this->drawTotals($quote);
        }

        $this->drawPaymentRequirement($quote);

        if (!empty($quote['client_message'])) {
            $this->drawTextSection('Customer Message', $quote['client_message']);
        }

        if (!empty($quote['disclaimer'])) {
            $this->drawTextSection('Contract / Disclaimer', $quote['disclaimer']);
        }

        if (!empty($quote['client_signature'])) {
            $this->drawClientSignature($quote['client_signature']);
        }

        $this->drawCompanySignature($company);
    }

    private function drawCompanyHeader($company)
    {
        $companyTextX = 15;
        $logo = !empty($company['quote_logo']) ? $company['quote_logo'] : $company['logo'];

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

    private function drawRecipientAndSummary($quote, $recipient, $view)
    {
        $recipientY = 55;
        $leftX = 15;
        $leftW = 90;

        $this->SetXY($leftX, $recipientY);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(65, 65, 65);
        $this->Cell($leftW, 5, 'RECIPIENT', 0, 1, 'L');

        $this->SetX($leftX);
        $this->SetFont('Arial', 'B', 12.5);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($leftW, 7, $this->cleanText($recipient['name']), 0, 1, 'L');

        if (!empty($recipient['billing_address'])) {
            foreach ($recipient['billing_address'] as $line) {
                $this->SetX($leftX);
                $this->SetFont('Arial', '', 8.5);
                $this->Cell($leftW, 4.5, $this->cleanText($line), 0, 1, 'L');
            }
        }

        if (!empty($recipient['contact'])) {
            $this->Ln(1);
            $this->SetX($leftX);
            $this->SetFont('Arial', '', 8.3);
            $this->SetTextColor(70, 70, 70);
            $this->MultiCell($leftW, 4.4, $this->cleanText($recipient['contact']), 0, 'L');
        }

        if (!empty($recipient['property_address'])) {
            $this->Ln(3);
            $this->SetX($leftX);
            $this->SetFont('Arial', 'B', 8.3);
            $this->SetTextColor(65, 65, 65);
            $this->Cell($leftW, 4.5, 'SERVICE ADDRESS', 0, 1, 'L');
            $this->SetFont('Arial', '', 8.5);
            $this->SetTextColor(0, 0, 0);
            foreach ($recipient['property_address'] as $line) {
                $this->SetX($leftX);
                $this->Cell($leftW, 4.5, $this->cleanText($line), 0, 1, 'L');
            }
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
        $this->Cell($boxW, 14, $this->cleanText('Quote #' . $quote['quote_no']), 0, 1, 'L', true);

        $rows = array(
            array('Created', $quote['created'])
        );
        if (!empty($quote['valid_until'])) $rows[] = array('Valid until', $quote['valid_until']);
        if (!empty($quote['salesperson'])) $rows[] = array('Salesperson', $quote['salesperson']);
        if (!empty($quote['status_label'])) $rows[] = array('Status', $quote['status_label']);

        $y = $boxY + 14;
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(0, 0, 0);
        foreach ($rows as $idx => $row) {
            $this->SetXY($boxX, $y);
            $this->setFill(($idx % 2) === 0 ? $this->lightGray : array(255, 255, 255));
            $this->Cell($labelW, 8, $this->cleanText($row[0]), 0, 0, 'L', true);
            $this->Cell($valueW, 8, $this->cleanText($row[1]), 0, 1, 'R', true);
            $y += 8;
        }

        if (!empty($view['totals'])) {
            $this->SetXY($boxX, $y);
            $this->setFill($this->darkGray);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 12.5);
            $this->Cell($labelW, 10, 'Total', 0, 0, 'L', true);
            $this->Cell($valueW, 10, $this->money($quote['total'], $quote['currency']), 0, 1, 'R', true);
            $y += 10;
        }

        $this->SetTextColor(0, 0, 0);
        return max($leftBottom, $y);
    }

    private function drawCustomFields($fields)
    {
        $this->ensureSpace(18);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(45, 45, 45);
        $this->Cell(180, 6, 'Quote Details', 0, 1, 'L');

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

    private function drawIntroduction($quote)
    {
        $this->ensureSpace(26);
        $title = trim((string)$quote['introduction_title']);
        $body = trim((string)$quote['introduction']);
        $image = isset($quote['introduction_image']) ? $quote['introduction_image'] : '';

        $this->SetX(15);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(25, 25, 25);
        $this->Cell(180, 6, $this->cleanText($title !== '' ? $title : 'Introduction'), 0, 1, 'L');
        $this->Ln(1);

        if ($image && is_file($image)) {
            $this->ensureSpace(36);
            try {
                $this->Image($image, 15, $this->GetY(), 42, 30);
                $this->SetY($this->GetY() + 33);
            } catch (Exception $e) {
                // Skip an unreadable introduction image.
            }
        }

        if ($body !== '') {
            $this->SetX(15);
            $this->SetFont('Arial', '', 8.7);
            $this->SetTextColor(70, 70, 70);
            $this->MultiCell(180, 4.7, $this->cleanText($body), 0, 'L');
        }
        $this->Ln(3);
    }

    private function drawItems($quote, $items, $view)
    {
        $this->ensureSpace(28);

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
        $headerH = 9;
        $this->SetX($x);
        $this->setFill($this->darkGray);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8.8);
        $this->Cell($nameW, $headerH, 'Product / Service', 0, 0, 'L', true);
        $this->Cell($descriptionW, $headerH, 'Description', 0, 0, 'L', true);
        if ($showQty) $this->Cell(18, $headerH, 'Qty', 0, 0, 'C', true);
        if ($showUnit) $this->Cell(28, $headerH, 'Unit Price', 0, 0, 'R', true);
        if ($showLineTotal) $this->Cell(28, $headerH, 'Total', 0, 0, 'R', true);
        $this->Ln($headerH);

        $this->SetTextColor(0, 0, 0);
        foreach ($items as $item) {
            $name = trim((string)$item['name']);
            if (!empty($item['is_optional'])) $name .= ' (Optional)';
            $description = trim((string)$item['description']);

            $name = $this->cleanText($name);
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
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 8.8);
                $this->Cell($nameW, $headerH, 'Product / Service', 0, 0, 'L', true);
                $this->Cell($descriptionW, $headerH, 'Description', 0, 0, 'L', true);
                if ($showQty) $this->Cell(18, $headerH, 'Qty', 0, 0, 'C', true);
                if ($showUnit) $this->Cell(28, $headerH, 'Unit Price', 0, 0, 'R', true);
                if ($showLineTotal) $this->Cell(28, $headerH, 'Total', 0, 0, 'R', true);
                $this->Ln($headerH);
                $this->SetTextColor(0, 0, 0);
            }

            $rowY = $this->GetY();
            $textY = $rowY + $topPad;
            $this->SetFont('Arial', '', 8.8);
            $this->SetXY($x, $textY);
            $this->MultiCell($nameW, $lineH, $name, 0, 'L');

            $this->SetXY($x + $nameW, $textY);
            $this->MultiCell($descriptionW, $lineH, $description, 0, 'L');

            $numericX = $x + $nameW + $descriptionW;
            $this->SetXY($numericX, $textY);
            if ($showQty) $this->Cell(18, $lineH, $this->formatQty($item['qty']), 0, 0, 'C');
            if ($showUnit) $this->Cell(28, $lineH, $this->money($item['unit_price'], $quote['currency']), 0, 0, 'R');
            if ($showLineTotal) $this->Cell(28, $lineH, $this->money($item['total'], $quote['currency']), 0, 0, 'R');

            $this->SetDrawColor(228, 232, 235);
            $this->Line($x, $rowY + $rowH, 195, $rowY + $rowH);
            $this->SetY($rowY + $rowH);
        }

        $this->Ln(4);
    }

    private function drawTotals($quote)
    {
        $this->ensureSpace(38);
        $totalsX = 128;
        $labelWidth = 37;
        $amountWidth = 30;
        $this->SetFont('Arial', '', 9.5);
        $this->setText($this->textGray);

        $this->SetX($totalsX);
        $this->Cell($labelWidth, 7, 'Subtotal', 0, 0, 'R');
        $this->Cell($amountWidth, 7, $this->money($quote['subtotal'], $quote['currency']), 0, 1, 'R');

        if ((float)$quote['discount'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Discount', 0, 0, 'R');
            $this->Cell($amountWidth, 7, '-' . $this->money($quote['discount'], $quote['currency']), 0, 1, 'R');
        }

        if ((float)$quote['tax'] != 0.0) {
            $this->SetX($totalsX);
            $this->Cell($labelWidth, 7, 'Tax', 0, 0, 'R');
            $this->Cell($amountWidth, 7, $this->money($quote['tax'], $quote['currency']), 0, 1, 'R');
        }

        $this->SetDrawColor(205, 210, 214);
        $this->Line($totalsX, $this->GetY() + 1, 195, $this->GetY() + 1);
        $this->Ln(2);

        $this->SetX($totalsX);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($labelWidth, 8, 'Total', 0, 0, 'R');
        $this->Cell($amountWidth, 8, $this->money($quote['total'], $quote['currency']), 1, 1, 'R');
        $this->Ln(2);
    }

    private function drawPaymentRequirement($quote)
    {
        $mode = isset($quote['payment_plan_mode']) ? strtolower((string)$quote['payment_plan_mode']) : 'none';
        $deposit = isset($quote['deposit_amount']) ? (float)$quote['deposit_amount'] : 0.0;

        if ($deposit > 0.005) {
            $this->ensureSpace(14);
            $this->SetX(15);
            $this->SetFont('Arial', 'B', 9.2);
            $this->SetTextColor(45, 45, 45);
            $this->MultiCell(180, 5.2, $this->cleanText('A deposit of ' . $this->money($deposit, $quote['currency']) . ' will be required to begin.'), 0, 'L');
            $this->Ln(2);
        }

        if ($mode !== 'schedule' || empty($quote['payment_schedule'])) return;

        $this->ensureSpace(24);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35, 35, 35);
        $this->Cell(180, 6, 'Payment Schedule', 0, 1, 'L');

        $this->SetFont('Arial', '', 8.5);
        foreach ($quote['payment_schedule'] as $idx => $row) {
            $this->ensureSpace(7);
            $label = trim((string)$row['description']);
            if ($label === '') $label = 'Payment ' . ($idx + 1);
            if (!empty($row['required_quote_deposit'])) $label .= ' - Required quote deposit';
            $this->SetX(18);
            $this->SetTextColor(75, 75, 75);
            $this->Cell(120, 6, $this->cleanText($label), 0, 0, 'L');
            $this->SetTextColor(20, 20, 20);
            $this->Cell(57, 6, $this->money((float)$row['amount'], $quote['currency']), 0, 1, 'R');
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
        $this->SetTextColor(35, 35, 35);
        $this->Cell(180, 6, $this->cleanText($title), 0, 1, 'L');
        $this->SetX(15);
        $this->SetFont('Arial', '', 8.4);
        $this->SetTextColor(85, 85, 85);
        $this->MultiCell(180, 4.5, $this->cleanText($text), 0, 'L');
        $this->Ln(3);
    }

    private function drawClientSignature($signature)
    {
        if (empty($signature['path']) || !is_file($signature['path'])) return;
        $this->ensureSpace(32);
        $this->SetX(15);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(35, 35, 35);
        $this->Cell(90, 6, 'Customer Signature', 0, 1, 'L');
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
            $this->SetTextColor(95, 95, 95);
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
            $this->SetTextColor(90, 90, 90);
            $this->Cell(60, 5, $this->cleanText($company['signatory_name']), 0, 1, 'R');
            $this->SetX(135);
            $this->Cell(60, 5, 'Authorized Signature', 0, 1, 'R');
        } elseif (!empty($company['signatory_name'])) {
            $this->ensureSpace(16);
            $this->Ln(5);
            $this->SetX(135);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(90, 90, 90);
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
            if ($c === ' ') $sep = $i;
            $l += isset($cw[$c]) ? $cw[$c] : 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) $i++;
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
    $pdo = fpQuoteDb();

    $tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
    $loggedBranchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
    $quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($tenantId <= 0) throw new RuntimeException('Your login session is not valid.');
    if ($quoteId <= 0) throw new RuntimeException('Invalid quotation.');

    $hasUsers = fpQuoteTableExists($pdo, 'users');
    $salespersonSelect = $hasUsers
        ? ",TRIM(CONCAT(COALESCE(u.first_name,''),CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END)) AS salesperson_name"
        : ",'' AS salesperson_name";
    $salespersonJoin = $hasUsers ? " LEFT JOIN users u ON u.id=q.salesperson_id AND u.tenant_id=q.tenant_id " : '';

    $st = $pdo->prepare(
        "SELECT q.*,
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
                $salespersonSelect . "
         FROM quotes q
         INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
         LEFT JOIN client_locations l ON l.id=q.location_id AND l.tenant_id=q.tenant_id AND l.client_id=q.client_id" .
         $salespersonJoin . "
         WHERE q.id=:quote_id AND q.tenant_id=:tenant_id
         LIMIT 1"
    );
    $st->execute(array(':quote_id' => $quoteId, ':tenant_id' => $tenantId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Quotation not found or you do not have access to it.');

    $quoteBranchId = !empty($row['branch_id']) ? (int)$row['branch_id'] : 0;
    $effectiveBranchId = $quoteBranchId > 0 ? $quoteBranchId : $loggedBranchId;

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

    $branchSetting = $effectiveBranchId > 0 ? fpQuoteLoadBrandSetting($pdo, $tenantId, $effectiveBranchId) : null;
    $businessSetting = fpQuoteLoadBrandSetting($pdo, $tenantId, 0);

    $companyName = fpQuoteFirstValue(
        fpQuoteValue($branchSetting, 'company_name'),
        fpQuoteValue($businessSetting, 'company_name'),
        fpQuoteValue($branch, 'name'),
        fpQuoteValue($tenant, 'display_name'),
        fpQuoteValue($tenant, 'legal_name')
    );
    $legalName = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'legal_name'), fpQuoteValue($businessSetting, 'legal_name'), fpQuoteValue($tenant, 'legal_name'));
    $companyEmail = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'email'), fpQuoteValue($businessSetting, 'email'), fpQuoteValue($branch, 'email'), fpQuoteValue($tenant, 'email'));
    $companyWebsite = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'website_url'), fpQuoteValue($businessSetting, 'website_url'), fpQuoteValue($tenant, 'website_url'));
    $companyPhone = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'phone'), fpQuoteValue($businessSetting, 'phone'), fpQuoteValue($branch, 'phone'), fpQuoteValue($tenant, 'phone'));
    $registrationNumber = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'registration_number'), fpQuoteValue($businessSetting, 'registration_number'), fpQuoteValue($tenant, 'registration_number'));
    $taxNumber = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'tax_number'), fpQuoteValue($businessSetting, 'tax_number'), fpQuoteValue($tenant, 'tax_number'));
    $address1 = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'address_line1'), fpQuoteValue($businessSetting, 'address_line1'), fpQuoteValue($branch, 'address_line1'), fpQuoteValue($tenant, 'address_line1'));
    $address2 = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'address_line2'), fpQuoteValue($businessSetting, 'address_line2'), fpQuoteValue($branch, 'address_line2'), fpQuoteValue($tenant, 'address_line2'));
    $city = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'city'), fpQuoteValue($businessSetting, 'city'), fpQuoteValue($branch, 'city'), fpQuoteValue($tenant, 'city'));
    $state = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'state'), fpQuoteValue($businessSetting, 'state'), fpQuoteValue($branch, 'state'), fpQuoteValue($tenant, 'state'));
    $postalCode = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'postal_code'), fpQuoteValue($businessSetting, 'postal_code'), fpQuoteValue($branch, 'postal_code'), fpQuoteValue($tenant, 'postal_code'));
    $logoPath = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'logo_path'), fpQuoteValue($businessSetting, 'logo_path'), fpQuoteValue($branch, 'logo_path'), fpQuoteValue($tenant, 'logo_path'));
    $quoteLogoPath = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'invoice_logo_path'), fpQuoteValue($businessSetting, 'invoice_logo_path'), fpQuoteValue($branch, 'invoice_logo_path'), fpQuoteValue($tenant, 'invoice_logo_path'), $logoPath);
    $signaturePath = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'signature_path'), fpQuoteValue($businessSetting, 'signature_path'));
    $signatoryName = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'authorized_signatory_name'), fpQuoteValue($businessSetting, 'authorized_signatory_name'));
    $footerNote = fpQuoteFirstValue(fpQuoteValue($branchSetting, 'footer_note'), fpQuoteValue($businessSetting, 'footer_note'), 'Thank you for the opportunity to provide this quote.');

    $isOptionalSelect = fpQuoteColumnExists($pdo, 'quote_line_items', 'is_optional') ? ',is_optional' : ',0 AS is_optional';
    $itemSt = $pdo->prepare(
        "SELECT item_name,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total" . $isOptionalSelect . "
         FROM quote_line_items
         WHERE quote_id=:quote_id
         ORDER BY sort_order ASC,id ASC"
    );
    $itemSt->execute(array(':quote_id' => $quoteId));
    $itemRows = $itemSt->fetchAll(PDO::FETCH_ASSOC);

    $items = array();
    foreach ($itemRows as $itemRow) {
        $items[] = array(
            'name' => $itemRow['item_name'],
            'description' => $itemRow['description'] ?: '',
            'qty' => (float)$itemRow['quantity'],
            'unit_price' => (float)$itemRow['unit_price'],
            'total' => (float)$itemRow['line_total'],
            'is_optional' => !empty($itemRow['is_optional']) ? 1 : 0
        );
    }

    if (!$items) {
        $items[] = array(
            'name' => 'Quotation Item',
            'description' => 'No quotation line items were found.',
            'qty' => 1,
            'unit_price' => (float)$row['subtotal'],
            'total' => (float)$row['total'],
            'is_optional' => 0
        );
    }

    $customFields = array();
    $customJson = fpQuoteValue($row, 'custom_fields_json');
    if ($customJson !== '') {
        $decoded = json_decode($customJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $field) {
                if (!is_array($field)) continue;
                $label = isset($field['label']) ? trim((string)$field['label']) : '';
                $value = isset($field['value']) ? trim((string)$field['value']) : '';
                if ($label === '' && $value === '') continue;
                $customFields[] = array('label' => $label, 'value' => $value);
            }
        }
    }

    $textSections = array();
    if (fpQuoteTableExists($pdo, 'quote_sections')) {
        $secSt = $pdo->prepare("SELECT section_key,title,body,sort_order FROM quote_sections WHERE tenant_id=:tenant_id AND quote_id=:quote_id ORDER BY sort_order,id");
        $secSt->execute(array(':tenant_id' => $tenantId, ':quote_id' => $quoteId));
        foreach ($secSt->fetchAll(PDO::FETCH_ASSOC) as $sec) {
            $textSections[] = array(
                'section_key' => isset($sec['section_key']) ? $sec['section_key'] : 'text',
                'title' => isset($sec['title']) ? $sec['title'] : '',
                'body' => isset($sec['body']) ? $sec['body'] : ''
            );
        }
    }

    $introductionImage = '';
    if (fpQuoteTableExists($pdo, 'quote_files')) {
        $introSt = $pdo->prepare("SELECT file_path FROM quote_files WHERE tenant_id=:tenant_id AND quote_id=:quote_id AND file_category='introduction_image' ORDER BY sort_order,id LIMIT 1");
        $introSt->execute(array(':tenant_id' => $tenantId, ':quote_id' => $quoteId));
        $introFile = $introSt->fetch(PDO::FETCH_ASSOC);
        if ($introFile) $introductionImage = fpQuoteLocalImage($introFile['file_path']);
    }

    $paymentSchedule = array();
    if (fpQuoteTableExists($pdo, 'quote_payment_schedule_items')) {
        $paySt = $pdo->prepare("SELECT sort_order,split_type,split_value,amount,description,required_quote_deposit FROM quote_payment_schedule_items WHERE tenant_id=:tenant_id AND quote_id=:quote_id ORDER BY sort_order,id");
        $paySt->execute(array(':tenant_id' => $tenantId, ':quote_id' => $quoteId));
        $paymentSchedule = $paySt->fetchAll(PDO::FETCH_ASSOC);
    }

    $clientSignature = null;
    if (fpQuoteTableExists($pdo, 'attachments')) {
        $sigSt = $pdo->prepare("SELECT file_path,created_at FROM attachments WHERE tenant_id=:tenant_id AND related_type='quote' AND related_id=:quote_id AND attachment_type='signature' ORDER BY id DESC LIMIT 1");
        $sigSt->execute(array(':tenant_id' => $tenantId, ':quote_id' => $quoteId));
        $attachment = $sigSt->fetch(PDO::FETCH_ASSOC);
        if ($attachment) {
            $localPath = fpQuoteLocalImage($attachment['file_path']);
            if ($localPath !== '') {
                $clientSignature = array(
                    'path' => $localPath,
                    'created_at' => !empty($attachment['created_at']) ? fpQuoteDate($attachment['created_at'], 'd M Y, h:i A') : ''
                );
            }
        }
    }

    $billingAddress = array();
    if (fpQuoteTableExists($pdo, 'client_locations')) {
        $billingSt = $pdo->prepare("SELECT address_line1,address_line2,city,state,postal_code FROM client_locations WHERE tenant_id=:tenant_id AND client_id=:client_id AND deleted_at IS NULL AND status='active' ORDER BY (location_type='billing') DESC,is_primary DESC,id ASC LIMIT 1");
        $billingSt->execute(array(':tenant_id' => $tenantId, ':client_id' => (int)$row['client_id']));
        $billingRow = $billingSt->fetch(PDO::FETCH_ASSOC);
        if ($billingRow) {
            $billingAddress = fpQuoteAddressLines(array(
                'billing_address_line1' => $billingRow['address_line1'],
                'billing_address_line2' => $billingRow['address_line2'],
                'billing_city' => $billingRow['city'],
                'billing_state' => $billingRow['state'],
                'billing_postal_code' => $billingRow['postal_code']
            ), 'billing_');
        }
    }

    $propertyAddress = fpQuoteAddressLines($row, 'location_');
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

    $clientView = fpQuoteDecodeClientView(fpQuoteValue($row, 'client_view_options_json'));
    $dateFormat = !empty($tenant['date_format']) ? $tenant['date_format'] : 'd-m-Y';
    $recipientContact = implode('  |  ', array_filter(array($row['client_phone'], $row['client_email'])));

    $statusRaw = fpQuoteValue($row, 'status');
    $statusLabel = $statusRaw !== '' ? ucwords(str_replace('_', ' ', $statusRaw)) : '';

    $disclaimer = fpQuoteValue($row, 'disclaimer');
    if ($disclaimer === '' && fpQuoteTableExists($pdo, 'quote_settings')) {
        $qs = $pdo->prepare("SELECT default_disclaimer FROM quote_settings WHERE tenant_id=:tenant_id LIMIT 1");
        $qs->execute(array(':tenant_id' => $tenantId));
        $qr = $qs->fetch(PDO::FETCH_ASSOC);
        if ($qr && trim((string)$qr['default_disclaimer']) !== '') $disclaimer = trim((string)$qr['default_disclaimer']);
    }

    $quote = array(
        'quote_no' => $row['quote_no'],
        'title' => fpQuoteValue($row, 'title'),
        'created' => fpQuoteDate($row['created_at'], $dateFormat),
        'valid_until' => !empty($row['valid_until']) ? fpQuoteDate($row['valid_until'], $dateFormat) : '',
        'salesperson' => !empty($row['salesperson_name']) ? $row['salesperson_name'] : '',
        'status_label' => $statusLabel,
        'currency' => $currency,
        'subtotal' => isset($row['subtotal']) ? (float)$row['subtotal'] : 0.0,
        'tax' => isset($row['tax_total']) ? (float)$row['tax_total'] : 0.0,
        'discount' => isset($row['discount_total']) ? (float)$row['discount_total'] : 0.0,
        'total' => isset($row['total']) ? (float)$row['total'] : 0.0,
        'deposit_amount' => isset($row['deposit_amount']) ? (float)$row['deposit_amount'] : 0.0,
        'payment_plan_mode' => fpQuoteValue($row, 'payment_plan_mode'),
        'payment_schedule' => $paymentSchedule,
        'client_view' => $clientView,
        'introduction_title' => fpQuoteValue($row, 'introduction_title'),
        'introduction' => fpQuoteValue($row, 'introduction'),
        'introduction_image' => $introductionImage,
        'client_message' => fpQuoteValue($row, 'client_message'),
        'disclaimer' => $disclaimer,
        'custom_fields' => $customFields,
        'text_sections' => $textSections,
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
            'logo' => fpQuoteLocalImage($logoPath),
            'quote_logo' => fpQuoteLocalImage($quoteLogoPath),
            'signature' => fpQuoteLocalImage($signaturePath),
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

    $pdf = new QuotePDF('P', 'mm', 'A4');
    $pdf->SetTitle('Quote #' . $quote['quote_no']);
    $pdf->SetAuthor($quote['company']['name']);
    $pdf->setFooterText($footerNote);
    $pdf->AddPage();
    $pdf->DrawQuote($quote);

    $outputName = 'Quote-' . fpQuoteSafeFilename($quote['quote_no']) . '.pdf';

    /*
     * Capture mode can be used later by quotation email code:
     * define('FIELDPLX_QUOTE_PDF_CAPTURE', true);
     * include __DIR__ . '/quotation-print.php';
     */
    if (defined('FIELDPLX_QUOTE_PDF_CAPTURE') && FIELDPLX_QUOTE_PDF_CAPTURE) {
        $bytes = $pdf->Output('S', $outputName);
        if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== '%PDF') {
            throw new RuntimeException('Quotation PDF capture did not return a valid PDF document.');
        }
        $GLOBALS['fieldplx_quote_pdf_bytes'] = $bytes;
        $GLOBALS['fieldplx_quote_pdf_name'] = $outputName;
        return;
    }

    $pdf->Output('I', $outputName);
    exit;

} catch (Throwable $e) {
    error_log('FieldPlx dynamic quotation PDF: ' . $e->getMessage());

    if (defined('FIELDPLX_QUOTE_PDF_CAPTURE') && FIELDPLX_QUOTE_PDF_CAPTURE) {
        throw $e;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate quotation: ' . $e->getMessage();
    exit;
}
