<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if(file_exists(__DIR__ . '/../includes/audit.php')){
    require_once __DIR__ . '/../includes/audit.php';
}

function ivRes($status,$success,$message,$extra=array()){
    while(ob_get_level()>0){@ob_end_clean();}
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success'=>(bool)$success,
        'message'=>(string)$message
    ),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function ivPost($key,$default=''){
    return isset($_POST[$key])?$_POST[$key]:$default;
}

function ivTable(PDO $pdo,$table){
    static $cache=array();
    if(array_key_exists($table,$cache))return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table));
    $cache[$table]=(int)$s->fetchColumn()>0;
    return $cache[$table];
}

function ivCol(PDO $pdo,$table,$column){
    static $cache=array();
    $key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t'=>$table,':c'=>$column));
    $cache[$key]=(int)$s->fetchColumn()>0;
    return $cache[$key];
}

function ivCurrency(PDO $pdo,$tenantId,$branchId){
    $s=$pdo->prepare("
        SELECT
            c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,
            c.decimal_places,c.decimal_separator,c.thousand_separator
        FROM tenants t
        LEFT JOIN branches b
          ON b.id=:branch_id
         AND b.tenant_id=t.id
        INNER JOIN currencies c
          ON c.id=COALESCE(b.currency_id,t.currency_id)
        WHERE t.id=:tenant_id
        LIMIT 1
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':branch_id'=>$branchId>0?$branchId:-1
    ));
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?$r:array(
        'id'=>null,'currency_code'=>'','currency_name'=>'','symbol'=>'',
        'symbol_position'=>'before','decimal_places'=>2,
        'decimal_separator'=>'.','thousand_separator'=>','
    );
}

function ivNextDocumentNumber(PDO $pdo,$tenantId,$branchId,$documentType,$fallbackPrefix){
    $separatorColumn=ivCol($pdo,'document_sequences','number_separator')?'number_separator':'separator';
    $branch=$branchId>0?$branchId:0;

    $s=$pdo->prepare("
        SELECT ds.*,b.branch_code
        FROM document_sequences ds
        LEFT JOIN branches b
          ON b.id=ds.branch_id
         AND b.tenant_id=ds.tenant_id
        WHERE ds.tenant_id=:tenant_id
          AND ds.document_type=:document_type
          AND ds.is_active=1
          AND (ds.branch_id=:branch_id OR ds.branch_id IS NULL)
        ORDER BY
          CASE WHEN ds.branch_id=:branch_id2 THEN 0 ELSE 1 END,
          ds.id
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':document_type'=>$documentType,
        ':branch_id'=>$branch,
        ':branch_id2'=>$branch
    ));
    $row=$s->fetch(PDO::FETCH_ASSOC);

    if(!$row){
        if($documentType==='invoice'){
            throw new RuntimeException('Invoice number format is not configured for this tenant. Configure it in Master Control first.');
        }

        $max=$pdo->prepare("SELECT COALESCE(MAX(id),0) FROM payments WHERE tenant_id=:tenant_id FOR UPDATE");
        $max->execute(array(':tenant_id'=>$tenantId));
        return $fallbackPrefix.'-'.str_pad((string)((int)$max->fetchColumn()+1),6,'0',STR_PAD_LEFT);
    }

    $now=new DateTime('now');
    $year=$now->format('Y');
    $month=$now->format('m');
    $fyStart=max(1,min(12,(int)$row['financial_year_start_month']));
    $fyYear=(int)$now->format('n')>=$fyStart?(int)$year:(int)$year-1;
    $financialYear=$fyYear.'-'.substr((string)($fyYear+1),-2);

    $resetKey='never';
    if($row['reset_period']==='monthly')$resetKey=$year.$month;
    elseif($row['reset_period']==='yearly')$resetKey=$year;
    elseif($row['reset_period']==='financial_year')$resetKey=$financialYear;

    $current=(int)$row['current_number'];
    if($row['reset_period']!=='never'&&(string)$row['last_reset_key']!==(string)$resetKey)$current=0;
    $next=$current+1;

    $middle='';
    if($row['middle_format']==='year')$middle=$year;
    elseif($row['middle_format']==='year_month')$middle=$year.$month;
    elseif($row['middle_format']==='financial_year')$middle=$financialYear;
    elseif($row['middle_format']==='branch_year')$middle=(!empty($row['branch_code'])?$row['branch_code']:'BR').$year;

    $parts=array();
    if(!empty($row['prefix']))$parts[]=$row['prefix'];
    if($middle!=='')$parts[]=$middle;
    $parts[]=str_pad((string)$next,max(1,(int)$row['number_length']),'0',STR_PAD_LEFT);
    if(!empty($row['suffix']))$parts[]=$row['suffix'];

    $separator=isset($row[$separatorColumn])?(string)$row[$separatorColumn]:'-';
    $number=implode($separator,$parts);

    $u=$pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $u->execute(array(':n'=>$next,':k'=>$resetKey,':id'=>$row['id']));

    return $number;
}

function ivEnsureInvoice(PDO $pdo,$tenantId,$jobId,$createdBy){
    $existing=$pdo->prepare("
        SELECT id
        FROM invoices
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
        ORDER BY id
        LIMIT 1
        FOR UPDATE
    ");
    $existing->execute(array(':tenant_id'=>$tenantId,':job_id'=>$jobId));
    $id=(int)$existing->fetchColumn();
    if($id>0)return $id;

    $s=$pdo->prepare("
        SELECT j.*,q.discount_total AS quote_discount_total
        FROM jobs j
        LEFT JOIN quotes q
          ON q.id=j.quote_id
         AND q.tenant_id=j.tenant_id
        WHERE j.id=:job_id
          AND j.tenant_id=:tenant_id
          AND j.deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute(array(':job_id'=>$jobId,':tenant_id'=>$tenantId));
    $job=$s->fetch(PDO::FETCH_ASSOC);

    if(!$job)throw new RuntimeException('Job not found.');
    if(!in_array($job['status'],array('completed','ready_to_invoice','invoiced','closed'),true)){
        throw new RuntimeException('Invoice is available only after the job is completed.');
    }

    $branchId=!empty($job['branch_id'])?(int)$job['branch_id']:0;
    $invoiceNo=ivNextDocumentNumber($pdo,$tenantId,$branchId,'invoice','INV');
    $issueDate=!empty($job['completed_at'])?date('Y-m-d',strtotime($job['completed_at'])):date('Y-m-d');
    $discount=!empty($job['quote_discount_total'])?(float)$job['quote_discount_total']:0.0;

    $insert=$pdo->prepare("
        INSERT INTO invoices(
            tenant_id,branch_id,invoice_no,client_id,location_id,job_id,visit_id,quote_id,
            status,issue_date,due_date,subtotal,discount_total,tax_total,total,
            amount_paid,balance_due,payment_terms,notes,created_by
        ) VALUES(
            :tenant_id,:branch_id,:invoice_no,:client_id,:location_id,:job_id,NULL,:quote_id,
            'draft',:issue_date,:due_date,:subtotal,:discount_total,:tax_total,:total,
            0,:balance_due,'Due on receipt',:notes,:created_by
        )
    ");
    $insert->execute(array(
        ':tenant_id'=>$tenantId,
        ':branch_id'=>$branchId>0?$branchId:null,
        ':invoice_no'=>$invoiceNo,
        ':client_id'=>$job['client_id'],
        ':location_id'=>!empty($job['location_id'])?(int)$job['location_id']:null,
        ':job_id'=>$jobId,
        ':quote_id'=>!empty($job['quote_id'])?(int)$job['quote_id']:null,
        ':issue_date'=>$issueDate,
        ':due_date'=>$issueDate,
        ':subtotal'=>(float)$job['subtotal'],
        ':discount_total'=>$discount,
        ':tax_total'=>(float)$job['tax_total'],
        ':total'=>(float)$job['total'],
        ':balance_due'=>(float)$job['total'],
        ':notes'=>'Automatically generated from completed job '.$job['job_no'].'.',
        ':created_by'=>$createdBy
    ));
    $invoiceId=(int)$pdo->lastInsertId();

    $count=0;
    if(!empty($job['quote_id'])&&ivTable($pdo,'quote_line_items')){
        $items=$pdo->prepare("
            SELECT product_service_id,item_name,description,quantity,unit_cost,unit_price,
                   discount_amount,tax_percent,tax_amount,line_total,sort_order
            FROM quote_line_items
            WHERE quote_id=:quote_id
            ORDER BY sort_order,id
        ");
        $items->execute(array(':quote_id'=>$job['quote_id']));
        $li=$pdo->prepare("
            INSERT INTO invoice_line_items(
                invoice_id,product_service_id,item_name,description,quantity,unit_cost,
                unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order
            ) VALUES(
                :invoice_id,:product_service_id,:item_name,:description,:quantity,:unit_cost,
                :unit_price,:discount_amount,:tax_percent,:tax_amount,:line_total,:sort_order
            )
        ");
        foreach($items->fetchAll(PDO::FETCH_ASSOC) as $item){
            $li->execute(array(
                ':invoice_id'=>$invoiceId,
                ':product_service_id'=>!empty($item['product_service_id'])?(int)$item['product_service_id']:null,
                ':item_name'=>$item['item_name'],
                ':description'=>$item['description'],
                ':quantity'=>$item['quantity'],
                ':unit_cost'=>$item['unit_cost'],
                ':unit_price'=>$item['unit_price'],
                ':discount_amount'=>$item['discount_amount'],
                ':tax_percent'=>$item['tax_percent'],
                ':tax_amount'=>$item['tax_amount'],
                ':line_total'=>$item['line_total'],
                ':sort_order'=>$item['sort_order']
            ));
            $count++;
        }
    }

    if($count===0){
        $itemName=!empty($job['title'])?$job['title']:'Completed Job Service';
        if(!empty($job['product_service_id'])){
            $ps=$pdo->prepare("SELECT name FROM product_services WHERE id=:id AND tenant_id=:tenant_id LIMIT 1");
            $ps->execute(array(':id'=>$job['product_service_id'],':tenant_id'=>$tenantId));
            $n=$ps->fetchColumn();
            if($n)$itemName=$n;
        }
        $taxPercent=(float)$job['subtotal']>0?((float)$job['tax_total']/(float)$job['subtotal'])*100:0;
        $li=$pdo->prepare("
            INSERT INTO invoice_line_items(
                invoice_id,product_service_id,item_name,description,quantity,unit_cost,
                unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order
            ) VALUES(
                :invoice_id,:product_service_id,:item_name,:description,1,0,
                :unit_price,0,:tax_percent,:tax_amount,:line_total,0
            )
        ");
        $li->execute(array(
            ':invoice_id'=>$invoiceId,
            ':product_service_id'=>!empty($job['product_service_id'])?(int)$job['product_service_id']:null,
            ':item_name'=>$itemName,
            ':description'=>'Completed job '.$job['job_no'],
            ':unit_price'=>(float)$job['subtotal'],
            ':tax_percent'=>$taxPercent,
            ':tax_amount'=>(float)$job['tax_total'],
            ':line_total'=>(float)$job['total']
        ));
    }

    return $invoiceId;
}

function ivInvoice(PDO $pdo,$tenantId,$invoiceId){
    $s=$pdo->prepare("
        SELECT
            i.*,
            c.display_name AS client_name,
            c.company_name AS client_company,
            c.email AS client_email,
            c.phone AS client_phone,
            cl.name AS location_name,
            cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,
            j.job_no,j.title AS job_title,j.completed_at AS job_completed_at,
            q.quote_no,
            b.name AS branch_name,
            b.email AS branch_email,
            b.phone AS branch_phone,
            b.address_line1 AS branch_address_line1,
            b.address_line2 AS branch_address_line2,
            b.city AS branch_city,
            b.state AS branch_state,
            b.postal_code AS branch_postal_code,
            t.display_name AS tenant_name,
            t.legal_name AS tenant_legal_name,
            t.tax_number AS tenant_tax_number,
            t.email AS tenant_email,
            t.phone AS tenant_phone,
            t.address_line1 AS tenant_address_line1,
            t.address_line2 AS tenant_address_line2,
            t.city AS tenant_city,
            t.state AS tenant_state,
            t.postal_code AS tenant_postal_code,
            COALESCE(b.invoice_logo_path,t.invoice_logo_path,b.logo_path,t.logo_path) AS invoice_logo
        FROM invoices i
        INNER JOIN tenants t ON t.id=i.tenant_id
        INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id
        LEFT JOIN client_locations cl ON cl.id=i.location_id AND cl.tenant_id=i.tenant_id
        LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id
        LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id
        LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id
        WHERE i.id=:invoice_id
          AND i.tenant_id=:tenant_id
        LIMIT 1
    ");
    $s->execute(array(':invoice_id'=>$invoiceId,':tenant_id'=>$tenantId));
    $r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)ivRes(404,false,'Invoice not found.');
    return $r;
}

function ivSyncInvoicePayment(PDO $pdo,$tenantId,$invoiceId){
    $s=$pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM payments
        WHERE tenant_id=:tenant_id
          AND invoice_id=:invoice_id
          AND status='succeeded'
    ");
    $s->execute(array(':tenant_id'=>$tenantId,':invoice_id'=>$invoiceId));
    $paid=(float)$s->fetchColumn();

    $q=$pdo->prepare("SELECT total,status FROM invoices WHERE id=:invoice_id AND tenant_id=:tenant_id LIMIT 1 FOR UPDATE");
    $q->execute(array(':invoice_id'=>$invoiceId,':tenant_id'=>$tenantId));
    $invoice=$q->fetch(PDO::FETCH_ASSOC);
    if(!$invoice)throw new RuntimeException('Invoice not found.');

    $total=(float)$invoice['total'];
    $balance=max(0,$total-$paid);
    $status=$invoice['status'];
    $paidAt=null;

    if($paid>0&&$balance<=0.005){
        $status='paid';
        $balance=0;
        $paidAt=date('Y-m-d H:i:s');
    }elseif($paid>0){
        $status='partially_paid';
    }

    $u=$pdo->prepare("
        UPDATE invoices
        SET amount_paid=:amount_paid,
            balance_due=:balance_due,
            status=:status,
            paid_at=CASE WHEN :is_paid=1 THEN COALESCE(paid_at,NOW()) ELSE paid_at END
        WHERE id=:invoice_id
          AND tenant_id=:tenant_id
    ");
    $u->execute(array(
        ':amount_paid'=>$paid,
        ':balance_due'=>$balance,
        ':status'=>$status,
        ':is_paid'=>$status==='paid'?1:0,
        ':invoice_id'=>$invoiceId,
        ':tenant_id'=>$tenantId
    ));

    return array('amount_paid'=>$paid,'balance_due'=>$balance,'status'=>$status);
}

$tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;
$sessionBranch=isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;

if($tenantId<=0||$userId<=0)ivRes(401,false,'Authentication required.');

$csrf=(string)ivPost('csrf_token','');
$sessionCsrf=isset($_SESSION['invoices_csrf_token'])?(string)$_SESSION['invoices_csrf_token']:'';
if($csrf===''||$sessionCsrf===''||!hash_equals($sessionCsrf,$csrf)){
    ivRes(419,false,'Your invoice session expired. Refresh the page and try again.');
}

if(!ivTable($pdo,'invoices')||!ivTable($pdo,'invoice_line_items')||!ivTable($pdo,'payments')){
    ivRes(500,false,'Invoice or payment tables are missing.');
}

$action=trim((string)ivPost('action',''));

try{
    if($action==='list'){
        $page=max(1,(int)ivPost('page',1));
        $perPage=(int)ivPost('per_page',10);
        if(!in_array($perPage,array(10,25,50),true))$perPage=10;

        $search=trim((string)ivPost('search',''));
        $status=trim((string)ivPost('status',''));
        $paymentStatus=trim((string)ivPost('payment_status',''));
        $branchId=(int)ivPost('branch_id',0);
        $dateType=trim((string)ivPost('date_type','issue_date'));
        $fromDate=trim((string)ivPost('from_date',''));
        $toDate=trim((string)ivPost('to_date',''));

        $allowedStatuses=array(
            'draft','sent','viewed','partially_paid','paid',
            'overdue','written_off','cancelled','archived'
        );
        if($status!==''&&!in_array($status,$allowedStatuses,true)){
            ivRes(422,false,'Invalid invoice status filter.');
        }

        $allowedPayment=array('outstanding','unpaid','partial','paid','overdue');
        if($paymentStatus!==''&&!in_array($paymentStatus,$allowedPayment,true)){
            ivRes(422,false,'Invalid payment status filter.');
        }

        if(!in_array($dateType,array('issue_date','due_date'),true)){
            $dateType='issue_date';
        }

        foreach(array('from_date'=>$fromDate,'to_date'=>$toDate) as $label=>$value){
            if($value!==''){
                $dt=DateTime::createFromFormat('Y-m-d',$value);
                if(!$dt||$dt->format('Y-m-d')!==$value){
                    ivRes(422,false,'Invalid '.str_replace('_',' ',$label).'.');
                }
            }
        }

        $where=array('i.tenant_id=:tenant_id');
        $params=array(':tenant_id'=>$tenantId);

        if($search!==''){
            $like='%'.$search.'%';
            $where[]="(
                i.invoice_no LIKE :s1
                OR c.display_name LIKE :s2
                OR c.email LIKE :s3
                OR c.phone LIKE :s4
                OR j.job_no LIKE :s5
                OR j.title LIKE :s6
                OR q.quote_no LIKE :s7
            )";
            $params[':s1']=$like;
            $params[':s2']=$like;
            $params[':s3']=$like;
            $params[':s4']=$like;
            $params[':s5']=$like;
            $params[':s6']=$like;
            $params[':s7']=$like;
        }

        if($status!==''){
            $where[]='i.status=:status';
            $params[':status']=$status;
        }

        if($branchId>0){
            $where[]='i.branch_id=:branch_id';
            $params[':branch_id']=$branchId;
        }

        if($fromDate!==''){
            $where[]='i.'.$dateType.'>=:from_date';
            $params[':from_date']=$fromDate;
        }

        if($toDate!==''){
            $where[]='i.'.$dateType.'<=:to_date';
            $params[':to_date']=$toDate;
        }

        if($paymentStatus==='outstanding'){
            $where[]="i.balance_due>0.005 AND i.status NOT IN('cancelled','archived','written_off')";
        }elseif($paymentStatus==='unpaid'){
            $where[]="i.amount_paid<=0.005 AND i.balance_due>0.005 AND i.status NOT IN('cancelled','archived','written_off')";
        }elseif($paymentStatus==='partial'){
            $where[]="i.amount_paid>0.005 AND i.balance_due>0.005 AND i.status NOT IN('cancelled','archived','written_off')";
        }elseif($paymentStatus==='paid'){
            $where[]="(i.balance_due<=0.005 OR i.status='paid')";
        }elseif($paymentStatus==='overdue'){
            $where[]="i.balance_due>0.005 AND i.due_date IS NOT NULL AND i.due_date<CURDATE() AND i.status NOT IN('cancelled','archived','written_off','paid')";
        }

        $whereSql=implode(' AND ',$where);

        $fromSql="
            FROM invoices i
            INNER JOIN clients c
              ON c.id=i.client_id
             AND c.tenant_id=i.tenant_id
            LEFT JOIN jobs j
              ON j.id=i.job_id
             AND j.tenant_id=i.tenant_id
            LEFT JOIN quotes q
              ON q.id=i.quote_id
             AND q.tenant_id=i.tenant_id
            LEFT JOIN branches b
              ON b.id=i.branch_id
             AND b.tenant_id=i.tenant_id
        ";

        $count=$pdo->prepare("SELECT COUNT(*) ".$fromSql." WHERE ".$whereSql);
        $count->execute($params);
        $totalRows=(int)$count->fetchColumn();

        $pages=max(1,(int)ceil($totalRows/$perPage));
        if($page>$pages)$page=$pages;
        $offset=($page-1)*$perPage;

        $sql="
            SELECT
                i.id,i.invoice_no,i.status,i.issue_date,i.due_date,
                i.subtotal,i.discount_total,i.tax_total,i.total,
                i.amount_paid,i.balance_due,i.payment_terms,
                i.sent_at,i.viewed_at,i.paid_at,i.created_at,
                i.branch_id,i.client_id,i.job_id,i.quote_id,
                c.display_name AS client_name,
                c.company_name AS client_company,
                c.email AS client_email,
                c.phone AS client_phone,
                j.job_no,
                j.title AS job_title,
                q.quote_no,
                b.name AS branch_name,
                CASE
                    WHEN i.status IN('cancelled','archived','written_off') THEN i.status
                    WHEN i.balance_due<=0.005 OR i.status='paid' THEN 'paid'
                    WHEN i.balance_due>0.005
                         AND i.due_date IS NOT NULL
                         AND i.due_date<CURDATE() THEN 'overdue'
                    WHEN i.amount_paid>0.005 AND i.balance_due>0.005 THEN 'partial'
                    ELSE 'unpaid'
                END AS payment_state
            ".$fromSql."
            WHERE ".$whereSql."
            ORDER BY COALESCE(i.issue_date,DATE(i.created_at)) DESC,i.id DESC
            LIMIT ".$perPage." OFFSET ".$offset;

        $stmt=$pdo->prepare($sql);
        $stmt->execute($params);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $summaryStmt=$pdo->prepare("
            SELECT
                COUNT(*) AS total_invoices,
                COALESCE(SUM(CASE WHEN status NOT IN('cancelled','archived','written_off') THEN total ELSE 0 END),0) AS total_billed,
                COALESCE(SUM(CASE WHEN status NOT IN('cancelled','archived','written_off') THEN amount_paid ELSE 0 END),0) AS total_collected,
                COALESCE(SUM(CASE WHEN status NOT IN('cancelled','archived','written_off') THEN balance_due ELSE 0 END),0) AS total_outstanding,
                COALESCE(SUM(
                    CASE
                        WHEN balance_due>0.005
                         AND due_date IS NOT NULL
                         AND due_date<CURDATE()
                         AND status NOT IN('cancelled','archived','written_off','paid')
                        THEN 1 ELSE 0
                    END
                ),0) AS overdue_invoices
            FROM invoices
            WHERE tenant_id=:tenant_id
        ");
        $summaryStmt->execute(array(':tenant_id'=>$tenantId));
        $summary=$summaryStmt->fetch(PDO::FETCH_ASSOC);

        $branchStmt=$pdo->prepare("
            SELECT id,name,branch_code
            FROM branches
            WHERE tenant_id=:tenant_id
              AND status='active'
            ORDER BY is_head_office DESC,name
        ");
        $branchStmt->execute(array(':tenant_id'=>$tenantId));

        ivRes(200,true,'Invoices loaded.',array(
            'rows'=>$rows,
            'summary'=>$summary,
            'branches'=>$branchStmt->fetchAll(PDO::FETCH_ASSOC),
            'currency'=>ivCurrency($pdo,$tenantId,$sessionBranch),
            'pagination'=>array(
                'page'=>$page,
                'per_page'=>$perPage,
                'total'=>$totalRows,
                'pages'=>$pages,
                'from'=>$totalRows>0?$offset+1:0,
                'to'=>$totalRows>0?min($offset+$perPage,$totalRows):0
            )
        ));
    }

    if($action==='load'){
        $invoiceId=(int)ivPost('invoice_id',0);
        $jobId=(int)ivPost('job_id',0);

        if($invoiceId<=0&&$jobId<=0)ivRes(422,false,'Invoice or completed job is required.');

        if($invoiceId<=0&&$jobId>0){
            $find=$pdo->prepare("SELECT id FROM invoices WHERE tenant_id=:tenant_id AND job_id=:job_id ORDER BY id LIMIT 1");
            $find->execute(array(':tenant_id'=>$tenantId,':job_id'=>$jobId));
            $invoiceId=(int)$find->fetchColumn();

            if($invoiceId<=0){
                $pdo->beginTransaction();
                try{
                    $invoiceId=ivEnsureInvoice($pdo,$tenantId,$jobId,$userId);
                    $pdo->commit();
                }catch(Throwable $e){
                    if($pdo->inTransaction())$pdo->rollBack();
                    throw $e;
                }
            }
        }

        $invoice=ivInvoice($pdo,$tenantId,$invoiceId);
        $currency=ivCurrency($pdo,$tenantId,!empty($invoice['branch_id'])?(int)$invoice['branch_id']:$sessionBranch);

        $items=$pdo->prepare("
            SELECT *
            FROM invoice_line_items
            WHERE invoice_id=:invoice_id
            ORDER BY sort_order,id
        ");
        $items->execute(array(':invoice_id'=>$invoiceId));

        $payments=$pdo->prepare("
            SELECT id,payment_no,payment_method,payment_channel,status,amount,provider,
                   provider_payment_id,transaction_fee,received_at,notes,created_at
            FROM payments
            WHERE tenant_id=:tenant_id
              AND invoice_id=:invoice_id
            ORDER BY COALESCE(received_at,created_at) DESC,id DESC
        ");
        $payments->execute(array(':tenant_id'=>$tenantId,':invoice_id'=>$invoiceId));

        ivRes(200,true,'Invoice loaded.',array(
            'invoice'=>$invoice,
            'items'=>$items->fetchAll(PDO::FETCH_ASSOC),
            'payments'=>$payments->fetchAll(PDO::FETCH_ASSOC),
            'currency'=>$currency
        ));
    }

    if($action==='collect_payment'){
        $invoiceId=(int)ivPost('invoice_id',0);
        $amount=(float)ivPost('amount',0);
        $method=trim((string)ivPost('payment_method','cash'));
        $reference=trim((string)ivPost('reference',''));
        $notes=trim((string)ivPost('notes',''));
        $receivedAt=trim((string)ivPost('received_at',''));

        $allowedMethods=array('cash','card','bank','upi','cheque','wallet','other');
        if($invoiceId<=0)ivRes(422,false,'Invoice is required.');
        if($amount<=0)ivRes(422,false,'Enter a payment amount greater than zero.');
        if(!in_array($method,$allowedMethods,true))ivRes(422,false,'Select a valid payment method.');

        if($receivedAt===''){
            $receivedAt=date('Y-m-d H:i:s');
        }else{
            $receivedAt=str_replace('T',' ',$receivedAt);
            if(strlen($receivedAt)===16)$receivedAt.=':00';
            $dt=DateTime::createFromFormat('Y-m-d H:i:s',$receivedAt);
            if(!$dt||$dt->format('Y-m-d H:i:s')!==$receivedAt){
                ivRes(422,false,'Enter a valid payment received date and time.');
            }
        }

        $pdo->beginTransaction();
        try{
            $lock=$pdo->prepare("
                SELECT *
                FROM invoices
                WHERE id=:invoice_id
                  AND tenant_id=:tenant_id
                LIMIT 1
                FOR UPDATE
            ");
            $lock->execute(array(':invoice_id'=>$invoiceId,':tenant_id'=>$tenantId));
            $invoice=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$invoice)throw new RuntimeException('Invoice not found.');

            if(in_array($invoice['status'],array('cancelled','archived','written_off'),true)){
                throw new RuntimeException('Payment cannot be collected for this invoice status.');
            }

            $sync=ivSyncInvoicePayment($pdo,$tenantId,$invoiceId);
            $balance=(float)$sync['balance_due'];
            if($balance<=0.005)throw new RuntimeException('This invoice is already fully paid.');
            if($amount>$balance+0.005){
                throw new RuntimeException('Payment amount cannot be greater than the outstanding balance.');
            }

            $branchId=!empty($invoice['branch_id'])?(int)$invoice['branch_id']:$sessionBranch;
            $currency=ivCurrency($pdo,$tenantId,$branchId);
            if(empty($currency['id']))throw new RuntimeException('Invoice currency is not configured.');

            $paymentNo=ivNextDocumentNumber($pdo,$tenantId,$branchId,'payment','PAY');

            $insert=$pdo->prepare("
                INSERT INTO payments(
                    tenant_id,branch_id,payment_no,client_id,invoice_id,quote_id,
                    payment_method,payment_channel,status,amount,currency_id,
                    provider,provider_payment_id,transaction_fee,received_at,notes,created_by
                ) VALUES(
                    :tenant_id,:branch_id,:payment_no,:client_id,:invoice_id,:quote_id,
                    :payment_method,'manual','succeeded',:amount,:currency_id,
                    'manual_collection',:reference,0,:received_at,:notes,:created_by
                )
            ");
            $insert->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId>0?$branchId:null,
                ':payment_no'=>$paymentNo,
                ':client_id'=>$invoice['client_id'],
                ':invoice_id'=>$invoiceId,
                ':quote_id'=>!empty($invoice['quote_id'])?(int)$invoice['quote_id']:null,
                ':payment_method'=>$method,
                ':amount'=>$amount,
                ':currency_id'=>$currency['id'],
                ':reference'=>$reference!==''?$reference:null,
                ':received_at'=>$receivedAt,
                ':notes'=>$notes!==''?$notes:null,
                ':created_by'=>$userId
            ));
            $paymentId=(int)$pdo->lastInsertId();

            $sync=ivSyncInvoicePayment($pdo,$tenantId,$invoiceId);

            try{
                $log=$pdo->prepare("
                    INSERT INTO activity_events(
                        tenant_id,branch_id,actor_user_id,actor_type,event_type,
                        related_type,related_id,client_id,title,details_json,visible_to_client
                    ) VALUES(
                        :tenant_id,:branch_id,:user_id,'user','payment_received',
                        'invoice',:invoice_id,:client_id,:title,:details,1
                    )
                ");
                $log->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':branch_id'=>$branchId>0?$branchId:null,
                    ':user_id'=>$userId,
                    ':invoice_id'=>$invoiceId,
                    ':client_id'=>$invoice['client_id'],
                    ':title'=>'Payment received: '.$paymentNo,
                    ':details'=>json_encode(array(
                        'payment_id'=>$paymentId,
                        'payment_no'=>$paymentNo,
                        'amount'=>$amount,
                        'payment_method'=>$method,
                        'invoice_status'=>$sync['status'],
                        'balance_due'=>$sync['balance_due']
                    ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                ));
            }catch(Throwable $logError){
                error_log('Invoice payment activity: '.$logError->getMessage());
            }

            if(function_exists('tenantAuditLog')){
                try{
                    tenantAuditLog(
                        $pdo,
                        'PAYMENT_RECEIVED',
                        $tenantId,
                        $branchId>0?$branchId:null,
                        $userId,
                        'payment',
                        $paymentId,
                        null,
                        array(
                            'payment_no'=>$paymentNo,
                            'invoice_id'=>$invoiceId,
                            'amount'=>$amount,
                            'payment_method'=>$method,
                            'reference'=>$reference,
                            'received_at'=>$receivedAt
                        )
                    );
                }catch(Throwable $auditError){
                    error_log('Invoice payment audit: '.$auditError->getMessage());
                }
            }

            $pdo->commit();

            ivRes(200,true,'Payment '.$paymentNo.' collected successfully.',array(
                'payment'=>array(
                    'id'=>$paymentId,
                    'payment_no'=>$paymentNo,
                    'amount'=>$amount,
                    'payment_method'=>$method,
                    'received_at'=>$receivedAt
                ),
                'invoice_status'=>$sync['status'],
                'amount_paid'=>$sync['amount_paid'],
                'balance_due'=>$sync['balance_due']
            ));

        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    ivRes(400,false,'Unsupported invoice action.');

}catch(PDOException $e){
    error_log('FieldPlx invoice PDO: '.$e->getMessage());
    if($e->getCode()==='23000')ivRes(409,false,'This invoice or payment number already exists. Please try again.');
    ivRes(500,false,'Unable to process the invoice.');
}catch(Throwable $e){
    error_log('FieldPlx invoice: '.$e->getMessage());
    ivRes(422,false,$e->getMessage());
}
