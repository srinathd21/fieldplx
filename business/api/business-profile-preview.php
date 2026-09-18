<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../includes/auth.php';
if(session_status()===PHP_SESSION_NONE) session_start();

function bpp_table_exists(PDO $pdo,$table){$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");$s->execute(array(':t'=>$table));return (int)$s->fetchColumn()>0;}
function bpp_post($key,$default=''){return isset($_POST[$key])&&!is_array($_POST[$key])?trim((string)$_POST[$key]):$default;}
function bpp_bool_value($value){return in_array((string)$value,array('1','true','yes','on'),true)?1:0;}
function bpp_hex($hex,$fallback){$hex=strtoupper(trim((string)$hex));return preg_match('/^#[0-9A-F]{6}$/',$hex)?$hex:$fallback;}
function bpp_rgb($hex){$hex=ltrim($hex,'#');return array(hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2)));}
function bpp_clean($v){return trim((string)($v===null?'':$v));}
function bpp_fail($code,$message){while(ob_get_level()>0)@ob_end_clean();http_response_code((int)$code);header('Content-Type:text/plain; charset=utf-8');echo $message;exit;}
function bpp_fpdf(){
    if(class_exists('FPDF',false)) return true;
    foreach(array(dirname(__DIR__,2).'/vendor/autoload.php',dirname(__DIR__).'/vendor/autoload.php',__DIR__.'/../../vendor/autoload.php') as $f){if(is_file($f)){require_once $f;if(class_exists('FPDF',false))return true;}}
    foreach(array(dirname(__DIR__).'/includes/fpdf/fpdf.php',dirname(__DIR__).'/lib/fpdf/fpdf.php',dirname(__DIR__).'/fpdf/fpdf.php',dirname(__DIR__,2).'/vendor/setasign/fpdf/fpdf.php') as $f){if(is_file($f)){require_once $f;if(class_exists('FPDF',false))return true;}}
    return false;
}
function bpp_settings_defaults($type){
    $c=array('quote_label'=>'Quote','show_qty'=>1,'show_unit_price'=>1,'show_line_total'=>1,'show_totals_tax_footer'=>1,'show_client_signature_line'=>0,'contract_disclaimer'=>'','deposit_language'=>'','include_return_payment_stub'=>0,'show_late_stamp'=>1,'show_account_balance'=>1,'show_paid_date'=>1,'header_layout'=>'basic','header_style'=>'modern','logo_size'=>'medium','theme_color'=>'default','footer_font_size'=>9,'show_company_name'=>1,'show_company_phone'=>1,'show_company_email'=>1,'show_company_website'=>1,'show_client_phone'=>0,'selected_fields_json'=>'[]');
    if($type==='quote'){$c['contract_disclaimer']='This quote is valid for the next 30 days, after which values may be subject to change.';$c['deposit_language']='A deposit of {{DEPOSIT_AMOUNT}} will be required to begin.';}
    if($type==='job'){$c['show_client_signature_line']=1;$c['contract_disclaimer']='We can be called for touch-ups and small changes for the next 3 days. After that all work is final.';}
    if($type==='invoice'){$c['contract_disclaimer']='Thank you for your business. Please contact us with any questions regarding this invoice.';}
    return $c;
}
function bpp_apply_post(&$doc,&$style){
    foreach(array('quote_label','show_qty','show_unit_price','show_line_total','show_totals_tax_footer','show_client_signature_line','contract_disclaimer','deposit_language','include_return_payment_stub','show_late_stamp','show_account_balance','show_paid_date') as $f){$k='doc_'.$f;if(array_key_exists($k,$_POST))$doc[$f]=in_array($f,array('show_qty','show_unit_price','show_line_total','show_totals_tax_footer','show_client_signature_line','include_return_payment_stub','show_late_stamp','show_account_balance','show_paid_date'),true)?bpp_bool_value($_POST[$k]):(string)$_POST[$k];}
    if(isset($_POST['doc_selected_fields'])&&is_array($_POST['doc_selected_fields']))$doc['selected_fields_json']=json_encode(array_values(array_filter(array_map('strval',$_POST['doc_selected_fields']))),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    foreach(array('header_layout','header_style','logo_size','theme_color','footer_font_size','show_company_name','show_company_phone','show_company_email','show_company_website','show_client_phone') as $f){$k='style_'.$f;if(array_key_exists($k,$_POST))$style[$f]=in_array($f,array('show_company_name','show_company_phone','show_company_email','show_company_website','show_client_phone'),true)?bpp_bool_value($_POST[$k]):(string)$_POST[$k];}
}
function bpp_selected_field_names(PDO $pdo,$tenantId,$keys){$out=array();foreach($keys as $key){$parts=explode(':',(string)$key,2);if(count($parts)!==2)continue;$source=$parts[0];$id=(int)$parts[1];if($id<=0)continue;if($source==='workflow'&&bpp_table_exists($pdo,'workflow_custom_field_definitions')){$s=$pdo->prepare("SELECT field_name FROM workflow_custom_field_definitions WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$n=$s->fetchColumn();if($n)$out[]=(string)$n;}elseif($source==='client'&&bpp_table_exists($pdo,'client_custom_field_definitions')){$s=$pdo->prepare("SELECT field_name FROM client_custom_field_definitions WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$n=$s->fetchColumn();if($n)$out[]=(string)$n;}}return $out;}
function bpp_text(FPDF $pdf,$x,$y,$w,$text,$size=9,$bold=false,$color=array(31,54,70),$h=4.6,$align='L'){$pdf->SetXY($x,$y);$pdf->SetFont('Arial',$bold?'B':'',$size);$pdf->SetTextColor($color[0],$color[1],$color[2]);$pdf->MultiCell($w,$h,(string)$text,0,$align,false);return $pdf->GetY();}
function bpp_line(FPDF $pdf,$x1,$y,$x2,$rgb,$width=.25){$pdf->SetDrawColor($rgb[0],$rgb[1],$rgb[2]);$pdf->SetLineWidth($width);$pdf->Line($x1,$y,$x2,$y);}
function bpp_dashed_line(FPDF $pdf,$x1,$y,$x2,$rgb,$width=.18,$dash=2.2,$gap=1.8){$pdf->SetDrawColor($rgb[0],$rgb[1],$rgb[2]);$pdf->SetLineWidth($width);for($x=$x1;$x<$x2;$x+=$dash+$gap){$pdf->Line($x,$y,min($x+$dash,$x2),$y);}}
function bpp_field(FPDF $pdf,$x,$y,$label,$value,$w=80,$labelColor=array(45,56,65),$valueBold=true){
    $pdf->SetXY($x,$y);$pdf->SetFont('Arial','B',7.2);$pdf->SetTextColor($labelColor[0],$labelColor[1],$labelColor[2]);$pdf->Cell($w,4.2,strtoupper($label),0,1);
    $pdf->SetX($x);$pdf->SetFont('Arial',$valueBold?'B':'',9.0);$pdf->SetTextColor(14,27,36);$pdf->MultiCell($w,4.7,(string)$value,0,'L');
    return $pdf->GetY();
}
function bpp_logo_abs($logoPath){
    $logoPath=trim((string)$logoPath);if($logoPath==='')return '';
    if(preg_match('~^https?://~i',$logoPath)){$u=parse_url($logoPath,PHP_URL_PATH);if(is_string($u)&&$u!=='')$logoPath=$u;}
    $logoPath=str_replace('\\','/',$logoPath);
    $root=dirname(__DIR__); // business.v1
    $parent=dirname($root);
    $clean=ltrim($logoPath,'/');
    if(strpos($clean,'business.v1/')===0)$clean=substr($clean,strlen('business.v1/'));
    $candidates=array(
        $root.'/'.$clean,
        $root.'/'.$logoPath,
        $parent.'/'.$clean,
        $parent.'/'.$logoPath,
        $root.'/uploads/'.basename($clean)
    );
    foreach($candidates as $candidate){$real=@realpath($candidate);if($real&&is_file($real))return $real;if(is_file($candidate))return $candidate;}
    return '';
}
function bpp_logo_box($logoSize){
    if($logoSize==='large') return array('w'=>50.0,'h'=>25.0);
    if($logoSize==='small') return array('w'=>32.0,'h'=>15.0);
    return array('w'=>41.0,'h'=>20.0);
}
function bpp_draw_logo(FPDF $pdf,$logoAbs,$x,$y,$boxW,$boxH,$align='L',$valign='M'){
    if($logoAbs==='')return false;
    $ext=strtolower(pathinfo($logoAbs,PATHINFO_EXTENSION));
    if(!in_array($ext,array('png','jpg','jpeg'),true))return false;
    $info=@getimagesize($logoAbs);
    if(!$info||empty($info[0])||empty($info[1]))return false;
    $iw=(float)$info[0];$ih=(float)$info[1];
    $scale=min((float)$boxW/$iw,(float)$boxH/$ih);
    if($scale<=0)return false;
    $drawW=max(.5,$iw*$scale);$drawH=max(.5,$ih*$scale);
    $drawX=(float)$x;$drawY=(float)$y;
    if($align==='C')$drawX+=(($boxW-$drawW)/2);elseif($align==='R')$drawX+=($boxW-$drawW);
    if($valign==='M')$drawY+=(($boxH-$drawH)/2);elseif($valign==='B')$drawY+=($boxH-$drawH);
    try{$pdf->Image($logoAbs,$drawX,$drawY,$drawW,$drawH);return true;}catch(Throwable $e){error_log('Business profile preview logo: '.$e->getMessage());return false;}
}
function bpp_theme_hex($choice,$profile){
    switch((string)$choice){
        case 'blue': return '#2B77B9';
        case 'red': return '#D6453D';
        case 'green': return '#74B824';
        case 'orange': return '#E77600';
        case 'purple': return '#7A3DB8';
        case 'brand': return bpp_hex($profile['accent_color']??'#74B824','#74B824');
        default: return '#5A5A5A';
    }
}
function bpp_footer_pt($setting){$n=max(6,min(10,(int)$setting));return array(6=>6.0,7=>6.5,8=>7.0,9=>7.5,10=>8.0)[$n];}
function bpp_draw_contact_meta(FPDF $pdf,$x,$y,$w,$style,$companyPhone,$companyEmail,$companyWebsite,$muted){
    $lines=array();
    if(!empty($style['show_company_phone'])&&$companyPhone!=='')$lines[]=$companyPhone;
    if(!empty($style['show_company_email'])&&$companyEmail!=='')$lines[]=$companyEmail;
    if(!empty($style['show_company_website'])&&$companyWebsite!=='')$lines[]=$companyWebsite;
    if($lines)bpp_text($pdf,$x,$y,$w,implode("\n",$lines),6.8,false,$muted,3.9);
}
function bpp_draw_doc_meta(FPDF $pdf,$x,$y,$w,$type,$docLabel,$accent,$ink,$muted,$filled=false){
    if($filled){
        $pdf->SetFillColor($accent[0],$accent[1],$accent[2]);$pdf->Rect($x,$y,$w,29,'F');
        $pdf->SetTextColor(255,255,255);$pdf->SetFont('Arial','B',12.0);$pdf->SetXY($x+4,$y+3);$pdf->Cell($w-8,6,ucfirst(strtolower($docLabel)),0,1,'L');
        $pdf->SetFont('Arial','',7.0);$pdf->SetXY($x+4,$y+11);$pdf->Cell(($w-8)/2,4,$type==='invoice'?'Issued':'Sent on',0,0);$pdf->Cell(($w-8)/2,4,$type==='invoice'?'Due':'',0,1,'R');
        $pdf->SetDrawColor(225,225,225);$pdf->Line($x+4,$y+17,$x+$w-4,$y+17);
        $pdf->SetFont('Arial','B',8.5);$pdf->SetXY($x+4,$y+20);$pdf->Cell(($w-8)/2,5,'Total',0,0);$pdf->Cell(($w-8)/2,5,'Rs.150.00',0,1,'R');
        return;
    }
    $pdf->SetTextColor($ink[0],$ink[1],$ink[2]);$pdf->SetFont('Arial','B',12.5);$pdf->SetXY($x,$y);$pdf->Cell($w,6,$docLabel,0,1,'L');
    bpp_line($pdf,$x,$y+9,$x+$w,$accent,.35);
    $pdf->SetFont('Arial','B',6.5);$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);$pdf->SetXY($x,$y+12);$pdf->Cell($w*.47,4,$type==='invoice'?'ISSUED:':'SENT ON:',0,0);$pdf->Cell($w*.53,4,$type==='invoice'?'DUE:':'',0,1,'R');
    $pdf->SetFont('Arial','',7.0);$pdf->SetXY($x,$y+16);$pdf->Cell($w*.47,4,$type==='invoice'?'Not sent yet':'',0,0);$pdf->Cell($w*.53,4,$type==='invoice'?'Due upon receipt':'',0,1,'R');
}
function bpp_draw_return_stub(FPDF $pdf,$company,$ink,$muted,$showAccountBalance){
    $sepY=224;bpp_dashed_line($pdf,16,$sepY,194,array(150,156,161),.18,2.4,1.7);
    $leftX=22;$rightX=112;$top=$sepY+10;
    $pdf->SetTextColor($ink[0],$ink[1],$ink[2]);
    $pdf->SetFont('Arial','B',7.2);$pdf->SetXY($leftX,$top);$pdf->Cell(70,4,'Bob Guy',0,1);
    $pdf->SetFont('Arial','',6.8);$pdf->SetX($leftX);$pdf->Cell(70,3.8,'#123 Main St.',0,1);$pdf->SetX($leftX);$pdf->Cell(70,3.8,'Springfield, California 90210',0,1);
    $pdf->SetFont('Arial','B',7.2);$pdf->SetXY($rightX,$top);$pdf->Cell(70,4,'For Services Rendered',0,1);
    $pdf->SetFont('Arial','',6.8);$rows=array(array('Invoice #','2'),array('Due date','upon receipt'),array('Amount due','Rs.150.00'));
    $yy=$top+5;foreach($rows as $row){$pdf->SetXY($rightX,$yy);$pdf->Cell(28,4,$row[0].':',0,0);$pdf->Cell(38,4,$row[1],0,1,'L');$yy+=4.5;}
    if($showAccountBalance){$pdf->SetXY($rightX,$yy+1);$pdf->Cell(28,4,'Account enclosed:',0,0);bpp_line($pdf,$rightX+31,$yy+4,$rightX+63,array(120,128,134),.18);}
    $pdf->SetFont('Arial','',6.3);$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);$pdf->SetXY($leftX,263);$pdf->Cell(70,4,'Mail to:',0,1);$pdf->SetFont('Arial','B',7.2);$pdf->SetTextColor($ink[0],$ink[1],$ink[2]);$pdf->SetX($leftX);$pdf->Cell(70,4,$company,0,1);
}

$tenantId=isset($currentTenantId)?(int)$currentTenantId:(isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0);
if($tenantId<=0)bpp_fail(401,'Authentication required.');
if($_SERVER['REQUEST_METHOD']==='POST'){$csrf=bpp_post('csrf_token');if(empty($_SESSION['business_profile_csrf'])||!is_string($_SESSION['business_profile_csrf'])||$csrf===''||!hash_equals($_SESSION['business_profile_csrf'],$csrf))bpp_fail(419,'Your form session expired. Refresh the page and try again.');}
$type=strtolower($_SERVER['REQUEST_METHOD']==='POST'?bpp_post('preview_type','invoice'):(isset($_GET['type'])?(string)$_GET['type']:'invoice'));
if(!in_array($type,array('quote','job','invoice'),true))$type='invoice';
if(!bpp_fpdf())bpp_fail(500,'FPDF could not be loaded. Place FPDF at includes/fpdf/fpdf.php or install setasign/fpdf with Composer.');

$q=$pdo->prepare("SELECT * FROM tenants WHERE id=:t AND deleted_at IS NULL LIMIT 1");$q->execute(array(':t'=>$tenantId));$tenant=$q->fetch(PDO::FETCH_ASSOC);if(!$tenant)bpp_fail(404,'Business not found.');
$profile=array();if(bpp_table_exists($pdo,'tenant_business_profiles')){$q=$pdo->prepare("SELECT * FROM tenant_business_profiles WHERE tenant_id=:t LIMIT 1");$q->execute(array(':t'=>$tenantId));$profile=$q->fetch(PDO::FETCH_ASSOC)?:array();}
$doc=bpp_settings_defaults($type);$style=bpp_settings_defaults('style');
if(bpp_table_exists($pdo,'tenant_document_settings')){$q=$pdo->prepare("SELECT * FROM tenant_document_settings WHERE tenant_id=:t AND document_type IN (:doc,:style)");$q->execute(array(':t'=>$tenantId,':doc'=>$type,':style'=>'style'));foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){if((string)$row['document_type']===$type)$doc=array_merge($doc,$row);elseif((string)$row['document_type']==='style')$style=array_merge($style,$row);}}
if($_SERVER['REQUEST_METHOD']==='POST')bpp_apply_post($doc,$style);

$layout=in_array((string)$style['header_layout'],array('basic','compact','envelope_dual','envelope_single'),true)?(string)$style['header_layout']:'basic';
$headerStyle=in_array((string)$style['header_style'],array('modern','clean'),true)?(string)$style['header_style']:'modern';
$logoSize=in_array((string)$style['logo_size'],array('small','medium','large'),true)?(string)$style['logo_size']:'medium';
$footerSetting=(int)$style['footer_font_size'];if($footerSetting<6||$footerSetting>10)$footerSetting=9;$footerPt=bpp_footer_pt($footerSetting);
$brand=bpp_theme_hex((string)$style['theme_color'],$profile);$accent=bpp_rgb($brand);
$company=bpp_clean($tenant['display_name']??($tenant['legal_name']??'FieldPlx Business'));if($company==='')$company='FieldPlx Business';
$companyPhone=bpp_clean($tenant['phone']??($profile['phone']??''));$companyEmail=bpp_clean($tenant['email']??'');$companyWebsite=bpp_clean($tenant['website']??'');
$docLabel=$type==='quote'?(strtolower((string)$doc['quote_label'])==='estimate'?'ESTIMATE #4':'QUOTE #4'):($type==='job'?'JOB #2':'INVOICE #2');
$selected=json_decode((string)$doc['selected_fields_json'],true);if(!is_array($selected))$selected=array();$selectedNames=bpp_selected_field_names($pdo,$tenantId,$selected);
$logoAbs=bpp_logo_abs(bpp_clean($profile['logo_path']??''));

$pdf=new FPDF('P','mm','A4');$pdf->SetTitle($company.' '.$docLabel);$pdf->SetMargins(12,10,12);$pdf->SetAutoPageBreak(false,12);$pdf->AddPage();$pdf->SetFillColor(255,255,255);$pdf->Rect(0,0,210,297,'F');
$ink=array(18,28,34);$muted=array(76,88,96);$light=array(214,219,223);$defaultTheme=((string)$style['theme_color']==='default');
$clientAddress="Bob Guy\n#123 Main St.\nSpringfield, California 90210";$serviceAddress="#8142 2nd St.\nAnytown, California 123456";

// Header/layout section. Each layout reserves a fixed logo box, text columns and document-meta box.
// This prevents portrait/square logos from colliding with Recipient, Sender or Service Address text.
$logoBox=bpp_logo_box($logoSize);
$contentTop=86;
if($layout==='basic'){
    // Basic: logo left, company block center, document summary right, recipient/sender beneath.
    $logoDrawn=bpp_draw_logo($pdf,$logoAbs,16,13,$logoBox['w'],$logoBox['h'],'L','M');
    if(!empty($style['show_company_name'])){
        bpp_text($pdf,70,14,38,$company,10.2,true,$ink,4.8);
        bpp_draw_contact_meta($pdf,70,21,38,$style,$companyPhone,$companyEmail,$companyWebsite,$muted);
    }elseif(!$logoDrawn){
        bpp_text($pdf,16,16,72,$company,10.2,true,$ink,4.8);
    }
    bpp_draw_doc_meta($pdf,116,13,78,$type,$docLabel,$defaultTheme?array(90,90,90):$accent,$ink,$muted,$defaultTheme);
    bpp_line($pdf,16,45,97,$accent,.30);bpp_line($pdf,103,45,194,$accent,.30);
    bpp_field($pdf,16,49,'Recipient',$clientAddress,78,$muted,true);
    $senderValue=!empty($style['show_company_name'])?$company:'Company';
    bpp_field($pdf,103,49,'Sender',$senderValue,91,$muted,true);
    if(!empty($style['show_client_phone']))bpp_text($pdf,16,67,78,'Phone: (780) 555-4827',6.7,false,$muted,3.7);
    $contentTop=84;
}elseif($layout==='compact'){
    // Compact: company text upper-left, document meta upper-right, recipient left, logo in a bounded right-side box.
    if(!empty($style['show_company_name'])){
        bpp_text($pdf,16,14,74,$company,9.8,true,$ink,4.7);
        bpp_draw_contact_meta($pdf,16,21,74,$style,$companyPhone,$companyEmail,$companyWebsite,$muted);
    }
    bpp_draw_doc_meta($pdf,116,13,78,$type,$docLabel,$accent,$ink,$muted,false);
    bpp_line($pdf,16,45,97,$accent,.30);bpp_line($pdf,103,45,194,$accent,.30);
    bpp_field($pdf,16,49,'Recipient',$clientAddress,78,$muted,true);
    if(!empty($style['show_client_phone']))bpp_text($pdf,16,67,78,'Phone: (780) 555-4827',6.7,false,$muted,3.7);
    bpp_draw_logo($pdf,$logoAbs,103,48,91,25,'C','M');
    $contentTop=84;
}elseif($layout==='envelope_dual'){
    // Dual window: company upper-left and logo centered in the right address window.
    if(!empty($style['show_company_name'])){
        bpp_text($pdf,16,14,74,$company,9.5,true,$ink,4.6);
        bpp_draw_contact_meta($pdf,16,21,74,$style,$companyPhone,$companyEmail,$companyWebsite,$muted);
    }
    bpp_draw_doc_meta($pdf,116,13,78,$type,$docLabel,$accent,$ink,$muted,false);
    bpp_line($pdf,16,45,97,$accent,.30);bpp_line($pdf,103,45,194,$accent,.30);
    bpp_field($pdf,16,49,'Recipient',$clientAddress,78,$muted,true);
    if(!empty($style['show_client_phone']))bpp_text($pdf,16,67,78,'Phone: (780) 555-4827',6.7,false,$muted,3.7);
    bpp_draw_logo($pdf,$logoAbs,103,48,91,25,'C','M');
    $contentTop=84;
}else{ // envelope_single
    // Single window: logo in the upper-left header, document meta right, recipient/sender below.
    $logoDrawn=bpp_draw_logo($pdf,$logoAbs,16,13,78,25,'L','M');
    if(!$logoDrawn&&!empty($style['show_company_name']))bpp_text($pdf,16,16,78,$company,10.2,true,$ink,4.8);
    bpp_draw_doc_meta($pdf,116,13,78,$type,$docLabel,$accent,$ink,$muted,false);
    bpp_line($pdf,16,45,97,$accent,.30);bpp_line($pdf,103,45,194,$accent,.30);
    bpp_field($pdf,16,49,'Recipient',$clientAddress,78,$muted,true);
    $senderValue=!empty($style['show_company_name'])?$company:'Company';
    bpp_field($pdf,103,49,'Sender',$senderValue,91,$muted,true);
    if(!empty($style['show_client_phone']))bpp_text($pdf,16,67,78,'Phone: (780) 555-4827',6.7,false,$muted,3.7);
    bpp_draw_contact_meta($pdf,103,62,91,$style,$companyPhone,$companyEmail,$companyWebsite,$muted);
    $contentTop=84;
}

// Service address begins on its own aligned row under the header columns.
bpp_line($pdf,16,$contentTop,194,$accent,.30);
bpp_field($pdf,16,$contentTop+4,'Service Address',$serviceAddress,92,$muted,true);
$tableTop=$contentTop+28;
if($type==='invoice'){bpp_text($pdf,16,$contentTop+20,112,'For Services Rendered',9.3,true,$ink,4.5);$tableTop=$contentTop+31;}

// Fixed table geometry. Inner columns are exactly 176mm wide, preventing the Total column from being clipped.
$tableX=16;$tableW=178;$innerX=18;$innerW=174;
$widths=array('name'=>39,'qty'=>15,'unit'=>25,'total'=>29);$fixed=$widths['name'];if(!empty($doc['show_qty']))$fixed+=$widths['qty'];if(!empty($doc['show_unit_price']))$fixed+=$widths['unit'];if(!empty($doc['show_line_total']))$fixed+=$widths['total'];$widths['desc']=$innerW-$fixed;
$headerFill=$headerStyle==='clean'&&$defaultTheme?array(90,90,90):$accent;
$pdf->SetFillColor($headerFill[0],$headerFill[1],$headerFill[2]);$pdf->Rect($tableX,$tableTop,$tableW,8,'F');
$pdf->SetTextColor(255,255,255);$pdf->SetFont('Arial','B',7.3);$pdf->SetXY($innerX,$tableTop+2.0);
$pdf->Cell($widths['name'],4,'Product/Service',0,0,'L');$pdf->Cell($widths['desc'],4,'Description',0,0,'L');
if(!empty($doc['show_qty']))$pdf->Cell($widths['qty'],4,'Qty.',0,0,'R');
if(!empty($doc['show_unit_price']))$pdf->Cell($widths['unit'],4,'Unit Price',0,0,'R');
if(!empty($doc['show_line_total']))$pdf->Cell($widths['total'],4,'Total',0,1,'R');else $pdf->Ln(4);
$rows=array(array('Clean Pool','Go and do lots of good stuff','1','0.00','50.00'),array('Mow Lawn','More good stuff','2','0.00','100.00'));
$y=$tableTop+10;$pdf->SetTextColor($ink[0],$ink[1],$ink[2]);$pdf->SetFont('Arial','',7.7);
foreach($rows as $r){
    $pdf->SetXY($innerX,$y);$pdf->Cell($widths['name'],6,$r[0],0,0,'L');$pdf->Cell($widths['desc'],6,$r[1],0,0,'L');
    if(!empty($doc['show_qty']))$pdf->Cell($widths['qty'],6,$r[2],0,0,'R');
    if(!empty($doc['show_unit_price']))$pdf->Cell($widths['unit'],6,'Rs.'.$r[3],0,0,'R');
    if(!empty($doc['show_line_total']))$pdf->Cell($widths['total'],6,'Rs.'.$r[4],0,1,'R');
    $y+=7;bpp_line($pdf,$tableX,$y-1,$tableX+$tableW,$light,.15);
}
if($selectedNames){$y+=3;$pdf->SetFont('Arial','B',7.0);$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);foreach($selectedNames as $name){$pdf->SetXY($innerX,$y);$pdf->Cell(110,4.8,$name.': Sample value',0,1);$y+=5;}}

if($type==='quote'&&!empty($doc['deposit_language'])){$dep=str_replace('{{DEPOSIT_AMOUNT}}','Rs.75.00',(string)$doc['deposit_language']);$y+=5;bpp_text($pdf,16,$y,122,$dep,8.0,true,$muted,4.2);}
if(!empty($doc['show_totals_tax_footer'])||$type==='invoice'){
    $totY=max($y+8,145);$pdf->SetFont('Arial','B',8.5);$pdf->SetTextColor($ink[0],$ink[1],$ink[2]);$pdf->SetXY(139,$totY);$pdf->Cell(28,6,'Total',0,0,'R');$pdf->Cell(27,6,'Rs.150.00',1,1,'R');
    if($type==='invoice'&&!empty($doc['show_account_balance'])){$pdf->SetXY(139,$totY+7);$pdf->SetFont('Arial','',7.5);$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);$pdf->Cell(28,5.5,'Account balance',0,0,'R');$pdf->SetTextColor(190,35,35);$pdf->Cell(27,5.5,'Rs.350.00',0,1,'R');}
}

$stub=($type==='invoice'&&!empty($doc['include_return_payment_stub']));
$footerY=$stub?198:246;$footerText=bpp_clean($doc['contract_disclaimer']);if($footerText==='')$footerText='Thank you for your business.';
$pdf->SetFont('Arial','',$footerPt);$pdf->SetTextColor($ink[0],$ink[1],$ink[2]);$pdf->SetXY(16,$footerY);$pdf->MultiCell($stub?104:112,max(3.9,$footerPt/1.75),$footerText,0,'L');
if(($type==='job'||$type==='quote')&&!empty($doc['show_client_signature_line'])){bpp_line($pdf,124,258,194,array(145,151,156),.2);$pdf->SetXY(124,260);$pdf->SetFont('Arial','',6.5);$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);$pdf->Cell(29,4,'Date',0,0);$pdf->Cell(41,4,'Client Signature',0,1,'R');}
if($type==='invoice'){
    if(!empty($doc['show_late_stamp'])){$pdf->SetTextColor(190,35,35);$pdf->SetFont('Arial','B',8);$pdf->SetXY(151,$stub?202:235);$pdf->Cell(40,6,'OVERDUE',1,1,'C');}
    if(!empty($doc['show_paid_date'])){$pdf->SetTextColor($muted[0],$muted[1],$muted[2]);$pdf->SetFont('Arial','',6.8);$pdf->SetXY(151,$stub?209:243);$pdf->Cell(40,4,'Paid date: Not paid',0,1,'R');}
    if($stub)bpp_draw_return_stub($pdf,$company,$ink,$muted,!empty($doc['show_account_balance']));
}

while(ob_get_level()>0)@ob_end_clean();
$pdf->Output('I',strtolower(str_replace(' ','-',$docLabel)).'-preview.pdf');
exit;
