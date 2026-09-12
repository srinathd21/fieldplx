<?php
require_once __DIR__ . '/includes/auth.php';

/* Embedded AJAX endpoint. Keeping the endpoint in this file avoids web-server 404/HTML responses when business/api is not deployed. */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    ob_start();
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    function clx_out($status,$success,$message,$extra=array()){
        while(ob_get_level()>0) @ob_end_clean();
        http_response_code((int)$status);
        echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    function clx_post($k,$d=''){return isset($_POST[$k])&&!is_array($_POST[$k])?trim((string)$_POST[$k]):$d;}
    function clx_col(PDO $pdo,$table,$col){$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");$s->execute(array(':t'=>$table,':c'=>$col));return (int)$s->fetchColumn()>0;}
    function clx_csrf(){if(empty($_SESSION['checklists_csrf'])||!hash_equals((string)$_SESSION['checklists_csrf'],clx_post('csrf_token')))clx_out(419,false,'Your form session expired. Refresh and try again.');}

    $tenantId=isset($currentTenantId)?(int)$currentTenantId:(isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0);
    $userId=isset($currentTenantUserId)?(int)$currentTenantUserId:(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:(isset($_SESSION['id'])?(int)$_SESSION['id']:0));
    if($tenantId<=0||$userId<=0) clx_out(401,false,'Tenant session is not available.');
    $action=clx_post('action');

    try{
        if($action==='list'){
            $search=clx_post('search');
            $where="tenant_id=:t AND status='active'";$p=array(':t'=>$tenantId);
            if($search!==''){$where.=" AND name LIKE :s";$p[':s']='%'.$search.'%';}
            $cols="ct.id,ct.name,ct.status,ct.created_at,ct.updated_at";
            $cols.=clx_col($pdo,'checklist_templates','auto_attach_jobs')?",ct.auto_attach_jobs":",0 AS auto_attach_jobs";
            $cols.=clx_col($pdo,'checklist_templates','auto_attach_assessments')?",ct.auto_attach_assessments":",0 AS auto_attach_assessments";
            $where=str_replace("tenant_id", "ct.tenant_id", $where);
            $where=str_replace("name LIKE", "ct.name LIKE", $where);
            $s=$pdo->prepare("
                SELECT $cols, COUNT(cti.id) AS question_count
                FROM checklist_templates ct
                LEFT JOIN checklist_template_items cti ON cti.checklist_template_id=ct.id
                WHERE $where
                GROUP BY ct.id
                ORDER BY ct.updated_at DESC, ct.created_at DESC, ct.id DESC
            ");$s->execute($p);
            clx_out(200,true,'Checklists loaded.',array('items'=>$s->fetchAll(PDO::FETCH_ASSOC)));
        }
        if($action==='get'){
            $id=(int)clx_post('id','0');if($id<=0)clx_out(422,false,'Invalid checklist.');
            $cols="id,name,description,status";
            $cols.=clx_col($pdo,'checklist_templates','auto_attach_jobs')?",auto_attach_jobs":",0 AS auto_attach_jobs";
            $cols.=clx_col($pdo,'checklist_templates','auto_attach_assessments')?",auto_attach_assessments":",0 AS auto_attach_assessments";
            $s=$pdo->prepare("SELECT $cols FROM checklist_templates WHERE id=:id AND tenant_id=:t LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)clx_out(404,false,'Checklist not found.');
            $s=$pdo->prepare("SELECT id,section_title,title,description,question_type,options_json,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:id ORDER BY sort_order,id");$s->execute(array(':id'=>$id));$items=$s->fetchAll(PDO::FETCH_ASSOC);
            foreach($items as &$x){$x['options']=array();if(!empty($x['options_json'])){$o=json_decode($x['options_json'],true);if(is_array($o))$x['options']=$o;}if((empty($x['section_title'])||$x['question_type']==='checkbox')&&!empty($x['description'])&&substr(trim($x['description']),0,1)==='{'){$m=json_decode($x['description'],true);if(is_array($m)){if(empty($x['section_title'])&&!empty($m['section_title']))$x['section_title']=$m['section_title'];if(!empty($m['question_type']))$x['question_type']=$m['question_type'];if(empty($x['options'])&&!empty($m['options'])&&is_array($m['options']))$x['options']=$m['options'];}}} unset($x);
            $c['items']=$items;clx_out(200,true,'Checklist loaded.',array('checklist'=>$c));
        }
        if($action==='save'){
            clx_csrf();$id=(int)clx_post('id','0');$name=substr(clx_post('name'),0,190);if($name==='')clx_out(422,false,'Checklist title is required.');
            $items=json_decode(clx_post('items_json','[]'),true);if(!is_array($items)||!count($items))clx_out(422,false,'Add at least one checklist question.');
            $autoJobs=clx_post('auto_attach_jobs')==='1'?1:0;$autoAssess=clx_post('auto_attach_assessments')==='1'?1:0;
            $hasJobs=clx_col($pdo,'checklist_templates','auto_attach_jobs');$hasAssess=clx_col($pdo,'checklist_templates','auto_attach_assessments');
            $pdo->beginTransaction();
            if($id>0){
                $set="name=:n,updated_at=NOW()";$pa=array(':n'=>$name,':id'=>$id,':t'=>$tenantId);if($hasJobs){$set.=",auto_attach_jobs=:aj";$pa[':aj']=$autoJobs;}if($hasAssess){$set.=",auto_attach_assessments=:aa";$pa[':aa']=$autoAssess;}
                $s=$pdo->prepare("UPDATE checklist_templates SET $set WHERE id=:id AND tenant_id=:t");$s->execute($pa);$c=$pdo->prepare("SELECT id FROM checklist_templates WHERE id=:id AND tenant_id=:t");$c->execute(array(':id'=>$id,':t'=>$tenantId));if(!$c->fetchColumn())throw new RuntimeException('Checklist not found.');
                $pdo->prepare("DELETE FROM checklist_template_items WHERE checklist_template_id=:id")->execute(array(':id'=>$id));
            }else{
                $cols="tenant_id,name,description,status,created_by";$vals=":t,:n,NULL,'active',:u";$pa=array(':t'=>$tenantId,':n'=>$name,':u'=>$userId);if($hasJobs){$cols.=",auto_attach_jobs";$vals.=",:aj";$pa[':aj']=$autoJobs;}if($hasAssess){$cols.=",auto_attach_assessments";$vals.=",:aa";$pa[':aa']=$autoAssess;}
                $s=$pdo->prepare("INSERT INTO checklist_templates($cols) VALUES($vals)");$s->execute($pa);$id=(int)$pdo->lastInsertId();
            }
            $ins=$pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,section_title,title,description,question_type,options_json,is_required,sort_order) VALUES(:ct,:section,:title,NULL,:type,:opts,:req,:ord)");
            $ord=0;foreach($items as $x){$title=substr(trim((string)(isset($x['title'])?$x['title']:'')),0,255);if($title==='')continue;$type=isset($x['question_type'])?(string)$x['question_type']:'short_answer';if(!in_array($type,array('short_answer','long_answer','dropdown','checkbox','number','image','date','signature'),true))$type='short_answer';$opts=isset($x['options'])&&is_array($x['options'])?array_values(array_filter(array_map('strval',$x['options']),function($v){return trim($v)!=='';})):array();$ord++;$ins->execute(array(':ct'=>$id,':section'=>substr(trim((string)(isset($x['section_title'])?$x['section_title']:'Section 1')),0,190),':title'=>$title,':type'=>$type,':opts'=>count($opts)?json_encode($opts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':req'=>!empty($x['is_required'])?1:0,':ord'=>$ord));}
            if($ord===0)throw new RuntimeException('Add at least one valid checklist question.');$pdo->commit();clx_out(200,true,'Checklist saved successfully.',array('id'=>$id));
        }
        if($action==='duplicate'){
            $id=(int)clx_post('id','0');if($id<=0)clx_out(422,false,'Invalid checklist.');$s=$pdo->prepare("SELECT * FROM checklist_templates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)clx_out(404,false,'Checklist not found.');
            $pdo->beginTransaction();$cols=array('tenant_id','name','description','status','created_by');$vals=array(':t',':n',':d',"'active'",':u');$pa=array(':t'=>$tenantId,':n'=>substr($c['name'].' copy',0,190),':d'=>$c['description'],':u'=>$userId);foreach(array('auto_attach_jobs','auto_attach_assessments') as $col)if(clx_col($pdo,'checklist_templates',$col)){$cols[]=$col;$vals[]=':'.$col;$pa[':'.$col]=0;}
            $q=$pdo->prepare("INSERT INTO checklist_templates(".implode(',',$cols).") VALUES(".implode(',',$vals).")");$q->execute($pa);$newId=(int)$pdo->lastInsertId();$q=$pdo->prepare("SELECT section_title,title,description,question_type,options_json,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:id ORDER BY sort_order,id");$q->execute(array(':id'=>$id));$ins=$pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,section_title,title,description,question_type,options_json,is_required,sort_order) VALUES(:ct,:s,:t,:d,:q,:o,:r,:so)");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $x)$ins->execute(array(':ct'=>$newId,':s'=>$x['section_title'],':t'=>$x['title'],':d'=>$x['description'],':q'=>$x['question_type'],':o'=>$x['options_json'],':r'=>$x['is_required'],':so'=>$x['sort_order']));$pdo->commit();clx_out(200,true,'Checklist duplicated successfully.');
        }
        if($action==='delete'){$id=(int)clx_post('id','0');if($id<=0)clx_out(422,false,'Invalid checklist.');$s=$pdo->prepare("UPDATE checklist_templates SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t");$s->execute(array(':id'=>$id,':t'=>$tenantId));clx_out(200,true,'Checklist deleted successfully.');}
        clx_out(400,false,'Invalid checklist action.');
    }catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('Checklist endpoint: '.$e->getMessage());clx_out(500,false,$e instanceof RuntimeException?$e->getMessage():'Unable to process checklist request.');}
}

$pageTitle = 'Checklists · FieldPlx';
$pageDescription = 'Create and manage reusable checklists for jobs and assessments';
$settingsActivePage = 'checklists';

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

require __DIR__ . '/includes/header.php';
if (file_exists(__DIR__ . '/includes/toast.php')) require_once __DIR__ . '/includes/toast.php';
?>
<style>
.cl-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;max-width:1240px;margin:0 auto;padding:0 0 46px;align-items:start}.cl-main{padding-top:2px}
.cl-main{min-width:0}
.cl-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:16px}
.cl-title-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cl-head h1{margin:0;color:#0b1933;font-size:30px;line-height:1.15;font-weight:700}
.cl-feature{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:#eaf5ff;color:#3978a8;font-size:9px}
.cl-feature:before{content:"";width:6px;height:6px;border-radius:50%;background:#41a7e8}
.cl-head p{margin:14px 0 0;color:#40586a;font-size:12px;line-height:1.5}
.cl-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 13px;border:1px solid #d8e1e7;border-radius:7px;background:#fff;color:#244657;font:inherit;font-size:11px;font-weight:700;text-decoration:none;cursor:pointer}
.cl-btn.primary{background:#2f8c25;border-color:#2f8c25;color:#fff}
.cl-search{width:220px;height:42px;display:flex;align-items:center;gap:9px;padding:0 12px;border:1px solid #d8e1e7;border-radius:7px;background:#fff;margin-bottom:14px}
.cl-search svg{width:16px;height:16px;color:#66808e}
.cl-search input{border:0;outline:0;width:100%;font:inherit;font-size:11px;color:#173745}
.cl-table{width:100%;border-collapse:collapse}
.cl-table th,.cl-table td{padding:14px 7px;border-bottom:1px solid #dce3e8;text-align:left;color:#234452;font-size:10px}
.cl-table th{font-weight:500}
.cl-table td{font-size:11px}
.cl-name{font-weight:700;color:#0b2f40;text-decoration:none}
.cl-name:hover{color:#2f8c25}
.cl-row{cursor:pointer;transition:background .15s ease}
.cl-row:hover{background:#f8fbf6}
.cl-row:hover .cl-name{color:#2f8c25}
.cl-meta{display:block;margin-top:3px;color:#7a8a94;font-size:9px;font-weight:400}
.cl-actions{position:relative;text-align:right;cursor:default}
.cl-menu-btn{width:30px;height:28px;border:0;border-radius:6px;background:transparent;color:#264858;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.cl-menu-btn svg{width:17px;height:17px}
.cl-menu-btn:hover{background:#f4f6f7}
.cl-menu{position:absolute;right:4px;top:32px;z-index:20;width:145px;background:#fff;border:1px solid #dfe5ea;border-radius:8px;box-shadow:0 8px 24px rgba(11,25,51,.12);padding:6px;display:none}
.cl-menu.open{display:block}
.cl-menu button{width:100%;height:34px;border:0;background:#fff;border-radius:6px;text-align:left;padding:0 9px;color:#294958;font:inherit;font-size:10px;cursor:pointer;display:flex;align-items:center}
.cl-menu button:hover{background:#f5f7f8}
.cl-menu .danger{color:#c64242}
.cl-empty{text-align:center;padding:42px 15px;color:#6c7f8a;font-size:11px}
@media(max-width:980px){.cl-layout{grid-template-columns:1fr;padding:0 14px 30px}.cl-layout>.fieldplx-settings-nav{display:none}}
@media(max-width:640px){.cl-head{flex-direction:column}.cl-search{width:100%}}
</style>

<div class="cl-layout">
<?php require __DIR__ . '/includes/settings-nav.php'; ?>
<main class="cl-main">
  <div class="cl-head">
    <div>
      <div class="cl-title-row"><h1>Checklists</h1><span class="cl-feature">FieldPlx feature</span></div>
      <p>Checklists can be used on assessments when attached to them, and on scheduled visits when attached to a job.</p>
    </div>
    <a class="cl-btn primary" href="checklist-create.php">New Checklist</a>
  </div>

  <div class="cl-search">
    <i data-lucide="search"></i>
    <input type="search" id="checklistSearch" placeholder="Search checklists..." autocomplete="off">
  </div>

  <table class="cl-table">
    <thead><tr><th>Name ↕</th><th style="width:120px">Questions</th><th style="width:28%">Auto attach</th><th style="width:52px"></th></tr></thead>
    <tbody id="checklistRows"><tr><td colspan="4" class="cl-empty">Loading checklists...</td></tr></tbody>
  </table>
</main>
</div>

<script>
(function(){
'use strict';
var api='checklists.php?ajax=1', rows=document.getElementById('checklistRows'), search=document.getElementById('checklistSearch'), timer=null;

function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function toast(t,m){if(window.fieldplxToast)window.fieldplxToast(t,m);else if(t==='error')alert(m)}
function closeMenus(){document.querySelectorAll('.cl-menu.open').forEach(function(x){x.classList.remove('open')})}

function load(){
 var fd=new FormData();fd.append('action','list');fd.append('search',search.value||'');
 fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
 .then(function(r){return r.text().then(function(raw){var j;try{j=raw?JSON.parse(raw):{}}catch(e){throw new Error('Checklist endpoint returned HTML instead of JSON. Replace checklists.php with the fixed file.')}if(!r.ok||!j.success)throw new Error(j.message||'Unable to load checklists.');return j})})
 .then(function(j){
   if(!j.items.length){rows.innerHTML='<tr><td colspan="4" class="cl-empty">No checklists found.</td></tr>';return}
   rows.innerHTML=j.items.map(function(x){
     var auto=[];
     if(Number(x.auto_attach_jobs||0))auto.push('Jobs');
     if(Number(x.auto_attach_assessments||0))auto.push('Assessments');
     var created=x.created_at?String(x.created_at).replace('T',' ').slice(0,16):'';
     return '<tr class="cl-row" data-open-checklist="'+Number(x.id)+'" tabindex="0" role="link" aria-label="Open '+esc(x.name)+'">'+
       '<td><span class="cl-name">'+esc(x.name)+'</span>'+(created?'<span class="cl-meta">Created '+esc(created)+'</span>':'')+'</td>'+
       '<td>'+Number(x.question_count||0)+'</td>'+
       '<td>'+esc(auto.length?auto.join(', '):'—')+'</td>'+
       '<td class="cl-actions"><button class="cl-menu-btn" type="button" data-menu="'+Number(x.id)+'" aria-label="Checklist actions"><i data-lucide="more-horizontal"></i></button>'+
       '<div class="cl-menu" id="menu'+Number(x.id)+'"><button type="button" data-duplicate="'+Number(x.id)+'"><i data-lucide="copy" style="width:14px;height:14px;margin-right:7px"></i>Duplicate</button><button type="button" class="danger" data-delete="'+Number(x.id)+'"><i data-lucide="trash-2" style="width:14px;height:14px;margin-right:7px"></i>Delete</button></div></td>'+
     '</tr>';
   }).join('');
   rows.querySelectorAll('[data-open-checklist]').forEach(function(row){
      row.setAttribute('title','Open checklist to edit or preview');
   });
   if(window.lucide&&window.lucide.createIcons)window.lucide.createIcons();
 })
 .catch(function(e){rows.innerHTML='<tr><td colspan="4" class="cl-empty">Unable to load checklists.</td></tr>';toast('error',e.message)});
}
search.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(load,220)});
document.addEventListener('click',function(e){
 var row=e.target.closest('[data-open-checklist]');
 if(row && !e.target.closest('.cl-actions')){
   window.location.href='checklist-edit.php?id='+encodeURIComponent(row.getAttribute('data-open-checklist'));
   return;
 }

 var mb=e.target.closest('[data-menu]');
 if(mb){e.stopPropagation();var m=document.getElementById('menu'+mb.dataset.menu),was=m.classList.contains('open');closeMenus();if(!was)m.classList.add('open');return}
 var d=e.target.closest('[data-duplicate]');
 if(d){closeMenus();act('duplicate',d.dataset.duplicate);return}
 var del=e.target.closest('[data-delete]');
 if(del){closeMenus();if(confirm('Delete this checklist?'))act('delete',del.dataset.delete);return}
 closeMenus();
});

document.addEventListener('keydown',function(e){
  if((e.key==='Enter'||e.key===' ') && e.target.matches('[data-open-checklist]')){
    e.preventDefault();
    window.location.href='checklist-edit.php?id='+encodeURIComponent(e.target.getAttribute('data-open-checklist'));
  }
});

function act(action,id){
 var fd=new FormData();fd.append('action',action);fd.append('id',id);
 fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
 .then(function(r){return r.text().then(function(raw){var j;try{j=raw?JSON.parse(raw):{}}catch(e){throw new Error('Checklist endpoint returned an invalid response.')}if(!r.ok||!j.success)throw new Error(j.message||'Action failed.');return j})})
 .then(function(j){toast('success',j.message);load()}).catch(function(e){toast('error',e.message)});
}
load();
if(window.lucide)window.lucide.createIcons();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
