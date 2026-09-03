<?php
/* FieldPlx Inventory API - Version 2.3.0 - 2026-09-03 - All Active Products + Stock Add Remove + Bulk + Export */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function invOut($status,$success,$message,$extra=array())
{
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function invDb()
{
    global $pdo,$db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}
function invTable(PDO $pdo,$table)
{
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:n');
    $q->execute(array(':n'=>$table));
    return (int)$q->fetchColumn()>0;
}
function invTenantId()
{
    if (!empty($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
    if (!empty($_SESSION['business_id'])) return (int)$_SESSION['business_id'];
    return 0;
}
function invUserId()
{
    if (!empty($_SESSION['tenant_user_id'])) return (int)$_SESSION['tenant_user_id'];
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    return 0;
}

try {
    $pdo=invDb();
    $tenantId=invTenantId();
    $userId=invUserId();
    if ($tenantId<=0 || $userId<=0) invOut(401,false,'Your login session is not valid.');
    if ($_SERVER['REQUEST_METHOD']!=='POST') invOut(405,false,'Method not allowed.');

    $csrf=isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:'';
    $sessionCsrf=isset($_SESSION['inventory_csrf_token'])?(string)$_SESSION['inventory_csrf_token']:'';
    if ($csrf==='' || $sessionCsrf==='' || !hash_equals($sessionCsrf,$csrf)) invOut(419,false,'Your form session expired. Refresh the page and try again.');

    if (!invTable($pdo,'products') || !invTable($pdo,'product_inventory')) {
        invOut(500,false,'Inventory tables are not installed. Run the Product Master migration first.');
    }

    $action=isset($_POST['action'])?trim((string)$_POST['action']):'';

    if ($action==='adjust_meta') {
        $branchesStmt=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
        $branchesStmt->execute(array(':t'=>$tenantId));
        $branches=$branchesStmt->fetchAll(PDO::FETCH_ASSOC);

        $productsStmt=$pdo->prepare("SELECT id,name,sku,unit_name,track_inventory FROM products WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY name");
        $productsStmt->execute(array(':t'=>$tenantId));
        $products=$productsStmt->fetchAll(PDO::FETCH_ASSOC);

        $invStmt=$pdo->prepare("SELECT branch_id,product_id,quantity_on_hand,quantity_reserved,reorder_level,minimum_stock FROM product_inventory WHERE tenant_id=:t AND status='active' ORDER BY product_id,branch_id");
        $invStmt->execute(array(':t'=>$tenantId));
        $inventoryByProduct=array();
        foreach($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $pid=(int)$row['product_id'];
            if(!isset($inventoryByProduct[$pid])) $inventoryByProduct[$pid]=array();
            $inventoryByProduct[$pid][]=array(
                'branch_id'=>(int)$row['branch_id'],
                'quantity_on_hand'=>(float)$row['quantity_on_hand'],
                'quantity_reserved'=>(float)$row['quantity_reserved'],
                'reorder_level'=>(float)$row['reorder_level'],
                'minimum_stock'=>(float)$row['minimum_stock']
            );
        }
        foreach($products as &$p){ $p['inventories']=isset($inventoryByProduct[(int)$p['id']])?$inventoryByProduct[(int)$p['id']]:array(); }
        unset($p);
        invOut(200,true,'Stock adjustment data loaded.',array('products'=>$products,'branches'=>$branches));
    }

    if ($action==='adjust_stock') {
        if (!invTable($pdo,'product_inventory_movements')) invOut(500,false,'Inventory movement table is not installed. Run the Product Master migration first.');
        $productId=max(0,(int)(isset($_POST['product_id'])?$_POST['product_id']:0));
        $branchId=max(0,(int)(isset($_POST['branch_id'])?$_POST['branch_id']:0));
        $adjustmentType=trim((string)(isset($_POST['adjustment_type'])?$_POST['adjustment_type']:''));
        $quantity=(float)(isset($_POST['quantity'])?$_POST['quantity']:0);
        $notes=trim((string)(isset($_POST['notes'])?$_POST['notes']:''));
        if($productId<=0 || $branchId<=0) invOut(422,false,'Select a product and branch.');
        if(!in_array($adjustmentType,array('add','remove'),true)) invOut(422,false,'Select Add Stock or Remove Stock.');
        if($quantity<=0) invOut(422,false,'Enter a quantity greater than zero.');

        $productCheck=$pdo->prepare("SELECT id,name,track_inventory FROM products WHERE id=:p AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");
        $productCheck->execute(array(':p'=>$productId,':t'=>$tenantId));
        $product=$productCheck->fetch(PDO::FETCH_ASSOC);
        if(!$product) invOut(404,false,'Active product not found.');
        $branchCheck=$pdo->prepare("SELECT id,name FROM branches WHERE id=:b AND tenant_id=:t AND status='active' LIMIT 1");
        $branchCheck->execute(array(':b'=>$branchId,':t'=>$tenantId));
        $branch=$branchCheck->fetch(PDO::FETCH_ASSOC);
        if(!$branch) invOut(404,false,'Branch not found.');

        $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare("SELECT id,quantity_on_hand,quantity_reserved FROM product_inventory WHERE tenant_id=:t AND product_id=:p AND branch_id=:b AND status='active' LIMIT 1 FOR UPDATE");
            $lock->execute(array(':t'=>$tenantId,':p'=>$productId,':b'=>$branchId));
            $inv=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$inv){
                $ins=$pdo->prepare("INSERT INTO product_inventory(tenant_id,branch_id,product_id,quantity_on_hand,quantity_reserved,reorder_level,minimum_stock,status,created_at) VALUES(:t,:b,:p,0,0,0,0,'active',NOW())");
                $ins->execute(array(':t'=>$tenantId,':b'=>$branchId,':p'=>$productId));
                $inventoryId=(int)$pdo->lastInsertId();
                $current=0.0; $reserved=0.0;
            } else {
                $inventoryId=(int)$inv['id'];
                $current=(float)$inv['quantity_on_hand'];
                $reserved=(float)$inv['quantity_reserved'];
            }
            if($adjustmentType==='add') $newQty=$current+$quantity;
            else $newQty=$current-$quantity;
            if($newQty<0) throw new RuntimeException('Stock on hand cannot be negative.');
            if($newQty<$reserved) throw new RuntimeException('Stock on hand cannot be lower than the reserved quantity of '.number_format($reserved,3,'.','').'.');
            $change=$newQty-$current;
            if(abs($change)<0.0005) throw new RuntimeException('The adjustment does not change the current stock.');
            $up=$pdo->prepare("UPDATE product_inventory SET quantity_on_hand=:q,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
            $up->execute(array(':q'=>number_format($newQty,3,'.',''),':id'=>$inventoryId,':t'=>$tenantId));
            if($adjustmentType==='add' && (int)$product['track_inventory']!==1){
                $enableTracking=$pdo->prepare("UPDATE products SET track_inventory=1,updated_at=NOW() WHERE id=:p AND tenant_id=:t");
                $enableTracking->execute(array(':p'=>$productId,':t'=>$tenantId));
            }
            $moveNotes='Manual stock adjustment - '.strtoupper($adjustmentType);
            if($notes!=='') $moveNotes.=' - '.$notes;
            $mv=$pdo->prepare("INSERT INTO product_inventory_movements(tenant_id,branch_id,product_id,movement_type,quantity_change,balance_after,reference_type,notes,created_by,created_at) VALUES(:t,:b,:p,'adjustment',:change,:balance,'inventory_adjustment',:notes,:u,NOW())");
            $mv->execute(array(':t'=>$tenantId,':b'=>$branchId,':p'=>$productId,':change'=>number_format($change,3,'.',''),':balance'=>number_format($newQty,3,'.',''),':notes'=>$moveNotes,':u'=>$userId));
            $pdo->commit();
            invOut(200,true,'Stock adjusted successfully.',array('quantity_on_hand'=>$newQty,'quantity_change'=>$change));
        } catch(Throwable $txe) {
            if($pdo->inTransaction()) $pdo->rollBack();
            if($txe instanceof RuntimeException) invOut(422,false,$txe->getMessage());
            throw $txe;
        }
    }


    if ($action==='bulk_adjust_stock') {
        if (!invTable($pdo,'product_inventory_movements')) invOut(500,false,'Inventory movement table is not installed. Run the Product Master migration first.');
        $adjustmentType=trim((string)(isset($_POST['adjustment_type'])?$_POST['adjustment_type']:''));
        if(!in_array($adjustmentType,array('add','remove'),true)) invOut(422,false,'Select Bulk Add Stock or Bulk Remove Stock.');
        $raw=isset($_POST['rows_json'])?(string)$_POST['rows_json']:'';
        $rows=json_decode($raw,true);
        if(!is_array($rows)) invOut(422,false,'Bulk stock rows are invalid.');
        if(count($rows)<1) invOut(422,false,'Enter at least one stock row.');
        if(count($rows)>200) invOut(422,false,'A maximum of 200 stock rows can be processed at one time.');

        $validated=array();
        $seen=array();
        $productCheck=$pdo->prepare("SELECT id,name,track_inventory FROM products WHERE id=:p AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");
        $branchCheck=$pdo->prepare("SELECT id,name FROM branches WHERE id=:b AND tenant_id=:t AND status='active' LIMIT 1");
        foreach($rows as $index=>$row){
            $rowNo=$index+1;
            $productId=max(0,(int)(isset($row['product_id'])?$row['product_id']:0));
            $branchId=max(0,(int)(isset($row['branch_id'])?$row['branch_id']:0));
            $quantity=(float)(isset($row['quantity'])?$row['quantity']:0);
            $notes=trim((string)(isset($row['notes'])?$row['notes']:''));
            if($productId<=0 || $branchId<=0 || $quantity<=0) invOut(422,false,'Row '.$rowNo.': select Product, Branch and enter a quantity greater than zero.');
            $key=$productId.':'.$branchId;
            if(isset($seen[$key])) invOut(422,false,'Row '.$rowNo.': duplicate Product / Branch combination.');
            $seen[$key]=true;
            $productCheck->execute(array(':p'=>$productId,':t'=>$tenantId));
            $product=$productCheck->fetch(PDO::FETCH_ASSOC);
            if(!$product) invOut(422,false,'Row '.$rowNo.': active product not found.');
            $branchCheck->execute(array(':b'=>$branchId,':t'=>$tenantId));
            $branch=$branchCheck->fetch(PDO::FETCH_ASSOC);
            if(!$branch) invOut(422,false,'Row '.$rowNo.': branch not found.');
            $validated[]=array(
                'row_no'=>$rowNo,
                'product_id'=>$productId,
                'product_name'=>(string)$product['name'],
                'track_inventory'=>(int)$product['track_inventory'],
                'branch_id'=>$branchId,
                'branch_name'=>(string)$branch['name'],
                'quantity'=>$quantity,
                'notes'=>$notes
            );
        }

        usort($validated,function($a,$b){
            if($a['branch_id']===$b['branch_id']) return $a['product_id']-$b['product_id'];
            return $a['branch_id']-$b['branch_id'];
        });

        $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare("SELECT id,quantity_on_hand,quantity_reserved FROM product_inventory WHERE tenant_id=:t AND product_id=:p AND branch_id=:b AND status='active' LIMIT 1 FOR UPDATE");
            $insertInventory=$pdo->prepare("INSERT INTO product_inventory(tenant_id,branch_id,product_id,quantity_on_hand,quantity_reserved,reorder_level,minimum_stock,status,created_at) VALUES(:t,:b,:p,0,0,0,0,'active',NOW())");
            $updateInventory=$pdo->prepare("UPDATE product_inventory SET quantity_on_hand=:q,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
            $enableTracking=$pdo->prepare("UPDATE products SET track_inventory=1,updated_at=NOW() WHERE id=:p AND tenant_id=:t AND track_inventory<>1");
            $insertMovement=$pdo->prepare("INSERT INTO product_inventory_movements(tenant_id,branch_id,product_id,movement_type,quantity_change,balance_after,reference_type,notes,created_by,created_at) VALUES(:t,:b,:p,'adjustment',:change,:balance,'inventory_adjustment',:notes,:u,NOW())");
            $results=array();
            foreach($validated as $item){
                $lock->execute(array(':t'=>$tenantId,':p'=>$item['product_id'],':b'=>$item['branch_id']));
                $inv=$lock->fetch(PDO::FETCH_ASSOC);
                if(!$inv){
                    if($adjustmentType==='remove'){
                        throw new RuntimeException($item['product_name'].' / '.$item['branch_name'].': no stock is available to remove.');
                    }
                    $insertInventory->execute(array(':t'=>$tenantId,':b'=>$item['branch_id'],':p'=>$item['product_id']));
                    $inventoryId=(int)$pdo->lastInsertId();
                    $current=0.0;
                    $reserved=0.0;
                } else {
                    $inventoryId=(int)$inv['id'];
                    $current=(float)$inv['quantity_on_hand'];
                    $reserved=(float)$inv['quantity_reserved'];
                }
                $available=max(0,$current-$reserved);
                if($adjustmentType==='remove' && $item['quantity']>$available+0.0000001){
                    throw new RuntimeException($item['product_name'].' / '.$item['branch_name'].': only '.number_format($available,3,'.','').' available quantity can be removed because '.number_format($reserved,3,'.','').' is reserved.');
                }
                $newQty=$adjustmentType==='add'?$current+$item['quantity']:$current-$item['quantity'];
                if($newQty<0) throw new RuntimeException($item['product_name'].' / '.$item['branch_name'].': stock on hand cannot be negative.');
                if($newQty<$reserved) throw new RuntimeException($item['product_name'].' / '.$item['branch_name'].': stock on hand cannot be lower than reserved quantity.');
                $change=$newQty-$current;
                $updateInventory->execute(array(':q'=>number_format($newQty,3,'.',''),':id'=>$inventoryId,':t'=>$tenantId));
                if($adjustmentType==='add' && (int)$item['track_inventory']!==1){
                    $enableTracking->execute(array(':p'=>$item['product_id'],':t'=>$tenantId));
                }
                $moveNotes='Bulk stock adjustment - '.strtoupper($adjustmentType);
                if($item['notes']!=='') $moveNotes.=' - '.$item['notes'];
                $insertMovement->execute(array(':t'=>$tenantId,':b'=>$item['branch_id'],':p'=>$item['product_id'],':change'=>number_format($change,3,'.',''),':balance'=>number_format($newQty,3,'.',''),':notes'=>$moveNotes,':u'=>$userId));
                $results[]=array('product_id'=>$item['product_id'],'branch_id'=>$item['branch_id'],'quantity_change'=>$change,'quantity_on_hand'=>$newQty);
            }
            $pdo->commit();
            invOut(200,true,count($results).' stock row(s) adjusted successfully.',array('adjusted'=>count($results),'results'=>$results));
        } catch(Throwable $txe) {
            if($pdo->inTransaction()) $pdo->rollBack();
            if($txe instanceof RuntimeException) invOut(422,false,$txe->getMessage());
            throw $txe;
        }
    }

    if ($action==='export') {
        $search=trim((string)(isset($_POST['search'])?$_POST['search']:''));
        $branchId=max(0,(int)(isset($_POST['branch_id'])?$_POST['branch_id']:0));
        $stockStatus=trim((string)(isset($_POST['stock_status'])?$_POST['stock_status']:''));
        if ($stockStatus!=='' && !in_array($stockStatus,array('in_stock','low_stock','out_stock'),true)) invOut(422,false,'Invalid stock filter.');

        if ($branchId>0) {
            $branchCheck=$pdo->prepare("SELECT id FROM branches WHERE id=:b AND tenant_id=:t AND status='active' LIMIT 1");
            $branchCheck->execute(array(':b'=>$branchId,':t'=>$tenantId));
            if (!$branchCheck->fetchColumn()) invOut(422,false,'Selected branch is not valid.');
        }

        $where="p.tenant_id=:tenant_id AND p.deleted_at IS NULL AND p.status='active' AND p.track_inventory=1 AND pi.status='active'";
        $params=array(':tenant_id'=>$tenantId);
        if ($branchId>0) { $where.=' AND pi.branch_id=:branch_id'; $params[':branch_id']=$branchId; }
        if ($search!=='') {
            $where.=' AND (p.name LIKE :search_name OR p.sku LIKE :search_sku)';
            $like='%'.$search.'%';
            $params[':search_name']=$like;
            $params[':search_sku']=$like;
        }
        if ($stockStatus==='out_stock') $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)<=0';
        elseif ($stockStatus==='low_stock') $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)>0 AND (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level';
        elseif ($stockStatus==='in_stock') $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)>pi.reorder_level';

        $st=$pdo->prepare("SELECT pi.id inventory_id,pi.branch_id,pi.product_id,pi.quantity_on_hand,pi.quantity_reserved,pi.reorder_level,pi.minimum_stock,
                                  p.name,p.sku,p.unit_name,p.base_unit_price,p.selling_price,
                                  b.branch_code,b.name branch_name,
                                  (pi.quantity_on_hand-pi.quantity_reserved) available_quantity,
                                  (GREATEST(pi.quantity_on_hand,0)*p.base_unit_price) inventory_value,
                                  CASE WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=0 THEN 'out_stock'
                                       WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level THEN 'low_stock'
                                       ELSE 'in_stock' END stock_status
                           FROM product_inventory pi
                           INNER JOIN products p ON p.id=pi.product_id AND p.tenant_id=pi.tenant_id
                           LEFT JOIN branches b ON b.id=pi.branch_id AND b.tenant_id=pi.tenant_id
                           WHERE ".$where."
                           ORDER BY p.name,b.name");
        $st->execute($params);
        invOut(200,true,'Inventory export loaded.',array('inventory'=>$st->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action!=='list') invOut(400,false,'Unknown inventory action.');

    $page=max(1,(int)(isset($_POST['page'])?$_POST['page']:1));
    $perPage=(int)(isset($_POST['per_page'])?$_POST['per_page']:10);
    if (!in_array($perPage,array(10,25,50,100),true)) $perPage=10;
    $search=trim((string)(isset($_POST['search'])?$_POST['search']:''));
    $branchId=max(0,(int)(isset($_POST['branch_id'])?$_POST['branch_id']:0));
    $stockStatus=trim((string)(isset($_POST['stock_status'])?$_POST['stock_status']:''));
    if ($stockStatus!=='' && !in_array($stockStatus,array('in_stock','low_stock','out_stock'),true)) invOut(422,false,'Invalid stock filter.');

    $branchesStmt=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
    $branchesStmt->execute(array(':t'=>$tenantId));
    $branches=$branchesStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($branchId>0) {
        $branchCheck=$pdo->prepare("SELECT id FROM branches WHERE id=:b AND tenant_id=:t AND status='active' LIMIT 1");
        $branchCheck->execute(array(':b'=>$branchId,':t'=>$tenantId));
        if (!$branchCheck->fetchColumn()) invOut(422,false,'Selected branch is not valid.');
    }

    $currencyStmt=$pdo->prepare('SELECT c.symbol,c.symbol_position,c.decimal_places,c.currency_code FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1');
    $currencyStmt->execute(array(':t'=>$tenantId));
    $currency=$currencyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currency) $currency=array('symbol'=>'','symbol_position'=>'before','decimal_places'=>2,'currency_code'=>'');

    $baseWhere="p.tenant_id=:tenant_id AND p.deleted_at IS NULL AND p.status='active' AND p.track_inventory=1 AND pi.status='active'";
    $params=array(':tenant_id'=>$tenantId);
    if ($branchId>0) {
        $baseWhere.=' AND pi.branch_id=:branch_id';
        $params[':branch_id']=$branchId;
    }

    $summarySql="SELECT
        COUNT(DISTINCT p.id) total_products,
        COUNT(DISTINCT CASE WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=0 THEN p.id END) out_of_stock,
        COUNT(DISTINCT CASE WHEN (pi.quantity_on_hand-pi.quantity_reserved)>0 AND (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level THEN p.id END) low_stock,
        COALESCE(SUM(GREATEST(pi.quantity_on_hand,0)*p.base_unit_price),0) inventory_value
      FROM product_inventory pi
      INNER JOIN products p ON p.id=pi.product_id AND p.tenant_id=pi.tenant_id
      WHERE ".$baseWhere;
    $summaryStmt=$pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary=$summaryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$summary) $summary=array('total_products'=>0,'out_of_stock'=>0,'low_stock'=>0,'inventory_value'=>0);

    $where=$baseWhere;
    $listParams=$params;
    if ($search!=='') {
        $where.=' AND (p.name LIKE :search_name OR p.sku LIKE :search_sku)';
        $like='%'.$search.'%';
        $listParams[':search_name']=$like;
        $listParams[':search_sku']=$like;
    }
    if ($stockStatus==='out_stock') {
        $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)<=0';
    } elseif ($stockStatus==='low_stock') {
        $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)>0 AND (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level';
    } elseif ($stockStatus==='in_stock') {
        $where.=' AND (pi.quantity_on_hand-pi.quantity_reserved)>pi.reorder_level';
    }

    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM product_inventory pi INNER JOIN products p ON p.id=pi.product_id AND p.tenant_id=pi.tenant_id WHERE '.$where);
    $countStmt->execute($listParams);
    $total=(int)$countStmt->fetchColumn();
    $pages=max(1,(int)ceil($total/$perPage));
    if ($page>$pages) $page=$pages;
    $offset=($page-1)*$perPage;

    $sql="SELECT pi.id inventory_id,pi.branch_id,pi.product_id,pi.quantity_on_hand,pi.quantity_reserved,pi.reorder_level,pi.minimum_stock,
                 p.name,p.sku,p.image_path,p.unit_name,p.base_unit_price,p.selling_price,
                 b.name branch_name,
                 (pi.quantity_on_hand-pi.quantity_reserved) available_quantity,
                 (GREATEST(pi.quantity_on_hand,0)*p.base_unit_price) inventory_value,
                 CASE
                   WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=0 THEN 'out_stock'
                   WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level THEN 'low_stock'
                   ELSE 'in_stock'
                 END stock_status
          FROM product_inventory pi
          INNER JOIN products p ON p.id=pi.product_id AND p.tenant_id=pi.tenant_id
          LEFT JOIN branches b ON b.id=pi.branch_id AND b.tenant_id=pi.tenant_id
          WHERE ".$where."
          ORDER BY CASE WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=0 THEN 1 WHEN (pi.quantity_on_hand-pi.quantity_reserved)<=pi.reorder_level THEN 2 ELSE 3 END,
                   p.name,b.name
          LIMIT ".(int)$perPage.' OFFSET '.(int)$offset;
    $stmt=$pdo->prepare($sql);
    $stmt->execute($listParams);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $from=$total>0?$offset+1:0;
    $to=min($offset+$perPage,$total);

    invOut(200,true,'Inventory loaded.',array(
        'inventory'=>$rows,
        'summary'=>array(
            'total_products'=>(int)$summary['total_products'],
            'low_stock'=>(int)$summary['low_stock'],
            'out_of_stock'=>(int)$summary['out_of_stock'],
            'inventory_value'=>(float)$summary['inventory_value']
        ),
        'branches'=>$branches,
        'currency'=>array(
            'symbol'=>(string)(isset($currency['symbol'])?$currency['symbol']:''),
            'position'=>(string)(isset($currency['symbol_position'])?$currency['symbol_position']:'before'),
            'decimals'=>(int)(isset($currency['decimal_places'])?$currency['decimal_places']:2),
            'code'=>(string)(isset($currency['currency_code'])?$currency['currency_code']:'')
        ),
        'pagination'=>array('page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$from,'to'=>$to)
    ));
} catch (Throwable $e) {
    error_log('FieldPlx inventory API: '.$e->getMessage());
    invOut(500,false,'Unable to load inventory.');
}
