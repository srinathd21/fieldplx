<?php
/* FieldPlx Quotation Response - Version 1.1.0 - 2026-08-27 */

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/business/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function qrH($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function qrTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t' => $table));
    $cache[$table] = ((int)$s->fetchColumn() > 0);
    return $cache[$table];
}

function qrCol(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$s->fetchColumn() > 0);
    return $cache[$key];
}

function qrMoney(array $quote, $amount)
{
    $dp = isset($quote['decimal_places']) ? max(0, (int)$quote['decimal_places']) : 2;
    $dec = isset($quote['decimal_separator']) && $quote['decimal_separator'] !== '' ? (string)$quote['decimal_separator'] : '.';
    $th = isset($quote['thousand_separator']) ? (string)$quote['thousand_separator'] : ',';
    $formatted = number_format((float)$amount, $dp, $dec, $th);
    $symbol = isset($quote['symbol']) ? trim((string)$quote['symbol']) : '';
    if ($symbol === '') {
        return $formatted;
    }
    return (isset($quote['symbol_position']) && $quote['symbol_position'] === 'after')
        ? $formatted . ' ' . $symbol
        : $symbol . ' ' . $formatted;
}

function qrLog(PDO $pdo, array $quote, $eventType, $title, array $details)
{
    try {
        if (!qrTable($pdo, 'activity_events')) {
            return;
        }
        $s = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,NULL,'client',:e,'quote',:rid,:cid,:title,:d,1)");
        $s->execute(array(
            ':t' => (int)$quote['tenant_id'],
            ':b' => !empty($quote['branch_id']) ? (int)$quote['branch_id'] : null,
            ':e' => (string)$eventType,
            ':rid' => (int)$quote['quote_id'],
            ':cid' => (int)$quote['client_id'],
            ':title' => substr((string)$title, 0, 255),
            ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    } catch (Throwable $e) {
        error_log('quotation response activity: ' . $e->getMessage());
    }
}

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$state = 'invalid';
$message = 'Invalid quotation response link.';
$quote = null;
$items = array();

if ($token !== '' && qrTable($pdo, 'quotation_action_tokens')) {
    $hash = hash('sha256', $token);

    $responseSelect = qrCol($pdo, 'quotation_action_tokens', 'response') ? ',qat.response' : ",NULL AS response";
    $responseNoteSelect = qrCol($pdo, 'quotation_action_tokens', 'response_note') ? ',qat.response_note' : ",NULL AS response_note";

    $sql = "SELECT
                qat.id AS token_id,
                qat.tenant_id,
                qat.quote_id,
                qat.client_id,
                qat.expires_at,
                qat.used_at
                {$responseSelect}
                {$responseNoteSelect},
                q.branch_id,
                q.quote_no,
                q.title,
                q.introduction,
                q.status AS quote_status,
                q.subtotal,
                q.discount_total,
                q.tax_total,
                q.total,
                q.valid_until,
                q.created_at,
                c.display_name,
                c.email,
                cur.symbol,
                cur.symbol_position,
                cur.decimal_places,
                cur.decimal_separator,
                cur.thousand_separator
            FROM quotation_action_tokens qat
            INNER JOIN quotes q
                ON q.id=qat.quote_id
               AND q.tenant_id=qat.tenant_id
            INNER JOIN clients c
                ON c.id=qat.client_id
               AND c.tenant_id=qat.tenant_id
            INNER JOIN tenants t
                ON t.id=qat.tenant_id
            LEFT JOIN currencies cur
                ON cur.id=t.currency_id
            WHERE qat.token_hash=:h
            LIMIT 1";

    $s = $pdo->prepare($sql);
    $s->execute(array(':h' => $hash));
    $quote = $s->fetch(PDO::FETCH_ASSOC);

    if ($quote) {
        if (!empty($quote['used_at'])) {
            $recorded = isset($quote['response']) ? (string)$quote['response'] : '';
            if ($recorded === 'approved') {
                $state = 'approved';
                $message = 'This quotation has already been approved.';
            } elseif ($recorded === 'rejected') {
                $state = 'rejected';
                $message = 'This quotation has already been rejected.';
            } else {
                $state = 'used';
                $message = 'This quotation response link has already been used.';
            }
        } elseif (empty($quote['expires_at']) || strtotime((string)$quote['expires_at']) < time()) {
            $state = 'expired';
            $message = 'This quotation response link has expired. Please contact the company for a new quotation email.';
        } elseif (in_array((string)$quote['quote_status'], array('approved', 'rejected', 'converted', 'archived'), true)) {
            $state = (string)$quote['quote_status'];
            if ($state === 'approved') {
                $message = 'This quotation has already been approved.';
            } elseif ($state === 'rejected') {
                $message = 'This quotation has already been rejected.';
            } else {
                $message = 'This quotation can no longer receive a response.';
            }
        } else {
            $state = 'ready';

            $i = $pdo->prepare("SELECT item_name,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
            $i->execute(array(':q' => (int)$quote['quote_id']));
            $items = $i->fetchAll(PDO::FETCH_ASSOC);

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array((string)$quote['quote_status'], array('sent'), true)) {
                try {
                    $set = "status='viewed'";
                    if (qrCol($pdo, 'quotes', 'viewed_at')) {
                        $set .= ",viewed_at=COALESCE(viewed_at,NOW())";
                    }
                    $u = $pdo->prepare("UPDATE quotes SET {$set} WHERE id=:q AND tenant_id=:t AND status='sent'");
                    $u->execute(array(':q' => (int)$quote['quote_id'], ':t' => (int)$quote['tenant_id']));
                    $quote['quote_status'] = 'viewed';
                } catch (Throwable $e) {
                    error_log('quotation mark viewed: ' . $e->getMessage());
                }
            }
        }
    }
}

if ($quote && empty($_SESSION['quotation_response_csrf'])) {
    $_SESSION['quotation_response_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $quote && $state === 'ready') {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $sessionCsrf = isset($_SESSION['quotation_response_csrf']) ? (string)$_SESSION['quotation_response_csrf'] : '';

    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $state = 'error';
        $message = 'Your response session expired. Refresh this page and try again.';
    } else {
        $response = isset($_POST['response']) ? trim((string)$_POST['response']) : '';
        $note = isset($_POST['response_note']) ? trim((string)$_POST['response_note']) : '';

        if (!in_array($response, array('approved', 'rejected'), true)) {
            $state = 'error';
            $message = 'Choose Approve Quotation or Reject Quotation.';
        } else {
            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare("SELECT id,used_at,expires_at FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q FOR UPDATE");
                $lock->execute(array(
                    ':id' => (int)$quote['token_id'],
                    ':t' => (int)$quote['tenant_id'],
                    ':q' => (int)$quote['quote_id']
                ));
                $current = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$current || !empty($current['used_at']) || empty($current['expires_at']) || strtotime((string)$current['expires_at']) < time()) {
                    throw new RuntimeException('This response link is no longer available.');
                }

                $tokenSet = array('used_at=NOW()');
                $tokenParams = array(':id' => (int)$quote['token_id']);

                if (qrCol($pdo, 'quotation_action_tokens', 'response')) {
                    $tokenSet[] = 'response=:response';
                    $tokenParams[':response'] = $response;
                }
                if (qrCol($pdo, 'quotation_action_tokens', 'response_note')) {
                    $tokenSet[] = 'response_note=:note';
                    $tokenParams[':note'] = $note !== '' ? $note : null;
                }
                if (qrCol($pdo, 'quotation_action_tokens', 'response_ip')) {
                    $tokenSet[] = 'response_ip=:ip';
                    $tokenParams[':ip'] = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : null;
                }

                $ut = $pdo->prepare("UPDATE quotation_action_tokens SET " . implode(',', $tokenSet) . " WHERE id=:id AND used_at IS NULL");
                $ut->execute($tokenParams);
                if ($ut->rowCount() !== 1) {
                    throw new RuntimeException('This response link has already been used.');
                }

                $quoteSet = array('status=:status');
                $quoteParams = array(
                    ':status' => $response,
                    ':q' => (int)$quote['quote_id'],
                    ':t' => (int)$quote['tenant_id']
                );

                if ($response === 'approved' && qrCol($pdo, 'quotes', 'approved_at')) {
                    $quoteSet[] = 'approved_at=NOW()';
                }

                $uq = $pdo->prepare("UPDATE quotes SET " . implode(',', $quoteSet) . " WHERE id=:q AND tenant_id=:t AND status NOT IN('converted','archived')");
                $uq->execute($quoteParams);

                $pdo->commit();

                $state = $response;
                $message = $response === 'approved'
                    ? 'Thank you. The quotation has been approved successfully.'
                    : 'Your rejection response has been recorded successfully.';

                qrLog(
                    $pdo,
                    $quote,
                    $response === 'approved' ? 'quote_approved_by_client' : 'quote_rejected_by_client',
                    ($response === 'approved' ? 'Quotation approved by customer: ' : 'Quotation rejected by customer: ') . $quote['quote_no'],
                    array('response' => $response, 'response_note' => $note !== '' ? $note : null)
                );

                $quote['quote_status'] = $response;
                $quote['response'] = $response;
                $quote['response_note'] = $note;
                $quote['used_at'] = date('Y-m-d H:i:s');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('quotation response submit: ' . $e->getMessage());
                $state = 'error';
                $message = $e->getMessage();
            }
        }
    }
}

$noticeClass = in_array($state, array('approved'), true) ? 'success' : (in_array($state, array('ready'), true) ? 'info' : 'danger');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Quotation Response - FieldPlx</title>
    <style>
        :root{--navy:#001131;--green:#74b824;--green-dark:#5d971b;--bg:#f6f8fb;--border:#e5eaf1;--text:#0b1933;--muted:#6f7b90;--red:#e45b66}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:14px}.page{max-width:900px;margin:34px auto;padding:0 16px 34px}.card{overflow:hidden;border:1px solid var(--border);border-radius:12px;background:#fff;box-shadow:0 10px 35px rgba(0,17,49,.08)}.head{padding:24px 26px;background:linear-gradient(135deg,#071f49,#001131);color:#fff}.brand{margin-bottom:8px;color:#9fda55;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.head h1{margin:0;font-size:24px;line-height:1.25}.head p{margin:7px 0 0;color:rgba(255,255,255,.72);font-size:12px}.body{padding:24px 26px}.notice{margin-bottom:18px;padding:13px 14px;border:1px solid transparent;border-radius:9px;font-size:13px;line-height:1.5}.notice.success{border-color:#d6e9bd;background:#f0f8e5;color:#4f8515}.notice.info{border-color:#d7e3ef;background:#edf2f7;color:#123d70}.notice.danger{border-color:#ffd6da;background:#fff0f1;color:#b9444d}.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;margin-bottom:18px}.meta-item{padding:12px 13px;border:1px solid var(--border);border-radius:9px;background:#fbfcfd}.meta-item small,.meta-item strong{display:block}.meta-item small{margin-bottom:5px;color:var(--muted);font-size:10px}.meta-item strong{color:var(--text);font-size:13px}.intro{margin:0 0 18px;color:#52627a;font-size:13px;line-height:1.65}.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:9px}.quote-table{width:100%;min-width:670px;border-collapse:collapse}.quote-table th,.quote-table td{padding:11px 12px;border-bottom:1px solid #eef2f5;text-align:left;font-size:11px}.quote-table th{background:#f8fafc;color:#65738a;font-size:9px;text-transform:uppercase}.quote-table tbody tr:last-child td{border-bottom:0}.quote-table .right{text-align:right}.item-desc{display:block;margin-top:3px;color:#8a96a7;font-size:9px;line-height:1.4}.optional{display:inline-block;margin-left:5px;padding:2px 5px;border-radius:4px;background:#edf2f7;color:#123d70;font-size:8px}.totals{width:min(360px,100%);margin:18px 0 0 auto}.total-row{display:flex;justify-content:space-between;gap:16px;padding:7px 0;color:#5e6d82;font-size:12px}.total-row.grand{margin-top:4px;padding-top:12px;border-top:1px solid var(--border);color:var(--text);font-size:18px;font-weight:700}.response-box{margin-top:24px;padding-top:20px;border-top:1px solid var(--border)}.response-title{margin:0 0 5px;font-size:15px}.response-help{margin:0 0 12px;color:var(--muted);font-size:11px;line-height:1.5}.response-box textarea{width:100%;min-height:96px;padding:11px 12px;border:1px solid #dfe5ec;border-radius:8px;outline:0;resize:vertical;font:inherit;color:var(--text)}.response-box textarea:focus{border-color:#a9cf75;box-shadow:0 0 0 3px rgba(116,184,36,.11)}.actions{display:flex;justify-content:flex-end;gap:9px;margin-top:12px}.btn{min-height:42px;padding:0 18px;border:0;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}.btn.reject{border:1px solid #ffd5d9;background:#fff;color:#b9444d}.btn.reject:hover{background:#fff0f1}.btn.approve{background:linear-gradient(90deg,#7fc92d,#68aa1d);color:#fff}.btn.approve:hover{background:linear-gradient(90deg,#74b824,#5d971b)}.footer{padding:15px 26px;border-top:1px solid var(--border);background:#fbfcfd;color:#8a96a7;font-size:10px;text-align:center}@media(max-width:640px){.page{margin-top:18px;padding:0 12px 24px}.head,.body{padding:20px 16px}.head h1{font-size:20px}.meta{grid-template-columns:1fr}.actions{flex-direction:column-reverse}.btn{width:100%}.footer{padding:14px 16px}}
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="head">
            <div class="brand">FieldPlx</div>
            <h1>Quotation Review</h1>
            <p>Review your quotation and submit your approval or rejection securely.</p>
        </div>

        <div class="body">
            <?php if (!$quote): ?>
                <div class="notice danger">Invalid quotation response link.</div>
            <?php else: ?>
                <div class="notice <?php echo qrH($noticeClass); ?>"><?php echo qrH($message); ?></div>

                <div class="meta">
                    <div class="meta-item"><small>Quotation Number</small><strong><?php echo qrH($quote['quote_no']); ?></strong></div>
                    <div class="meta-item"><small>Customer</small><strong><?php echo qrH($quote['display_name']); ?></strong></div>
                    <div class="meta-item"><small>Title</small><strong><?php echo qrH($quote['title']); ?></strong></div>
                    <div class="meta-item"><small>Valid Until</small><strong><?php echo !empty($quote['valid_until']) ? qrH(date('d M Y', strtotime($quote['valid_until']))) : 'Not specified'; ?></strong></div>
                </div>

                <?php if (!empty($quote['introduction'])): ?>
                    <p class="intro"><?php echo nl2br(qrH($quote['introduction'])); ?></p>
                <?php endif; ?>

                <?php if (!empty($items)): ?>
                    <div class="table-wrap">
                        <table class="quote-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="right">Qty</th>
                                    <th class="right">Unit Price</th>
                                    <th class="right">Discount</th>
                                    <th class="right">Tax</th>
                                    <th class="right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?php echo qrH($item['item_name']); ?>
                                        <?php if ((int)$item['is_optional'] === 1): ?><span class="optional">Optional</span><?php endif; ?>
                                        <?php if (!empty($item['description'])): ?><span class="item-desc"><?php echo qrH($item['description']); ?></span><?php endif; ?>
                                    </td>
                                    <td class="right"><?php echo qrH(rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.')); ?></td>
                                    <td class="right"><?php echo qrH(qrMoney($quote, $item['unit_price'])); ?></td>
                                    <td class="right"><?php echo qrH(qrMoney($quote, $item['discount_amount'])); ?></td>
                                    <td class="right"><?php echo qrH(qrMoney($quote, $item['tax_amount'])); ?></td>
                                    <td class="right"><?php echo qrH(qrMoney($quote, $item['line_total'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="totals">
                    <div class="total-row"><span>Subtotal</span><strong><?php echo qrH(qrMoney($quote, $quote['subtotal'])); ?></strong></div>
                    <div class="total-row"><span>Discount</span><strong><?php echo qrH(qrMoney($quote, $quote['discount_total'])); ?></strong></div>
                    <div class="total-row"><span>Tax</span><strong><?php echo qrH(qrMoney($quote, $quote['tax_total'])); ?></strong></div>
                    <div class="total-row grand"><span>Total</span><span><?php echo qrH(qrMoney($quote, $quote['total'])); ?></span></div>
                </div>

                <?php if ($state === 'ready'): ?>
                    <form method="post" class="response-box" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo qrH($_SESSION['quotation_response_csrf']); ?>">
                        <h2 class="response-title">Your Response</h2>
                        <p class="response-help">You can add a comment. If rejecting the quotation, adding the reason will help the team follow up correctly.</p>
                        <textarea name="response_note" maxlength="2000" placeholder="Comment / rejection reason (optional)"></textarea>
                        <div class="actions">
                            <button class="btn reject" type="submit" name="response" value="rejected">Reject Quotation</button>
                            <button class="btn approve" type="submit" name="response" value="approved">Approve Quotation</button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="footer">Secure quotation response powered by FieldPlx</div>
    </div>
</div>
</body>
</html>
