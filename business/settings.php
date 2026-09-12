<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Company Settings · FieldPlx';
$pageDescription = 'Manage company details, business hours, tax and regional settings';
$settingsActivePage = 'company';

$tenantId = (int)$currentTenantId;

if (empty($_SESSION['company_settings_csrf'])) {
    $_SESSION['company_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['company_settings_csrf'];

function fs_h($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}
function fs_table_exists(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t'=>$table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}
function fs_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key=$table.'.'.$column;
    if (array_key_exists($key,$cache)) return $cache[$key];
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t'=>$table,':c'=>$column));
    $cache[$key]=((int)$stmt->fetchColumn()>0);
    return $cache[$key];
}

$requiredColumns=array('tax_id_name','time_format','first_day_of_week','show_business_hours','allow_public_discovery','address_private');
$missingColumns=array();
foreach($requiredColumns as $col){if(!fs_column_exists($pdo,'tenants',$col))$missingColumns[]=$col;}
$hoursTableReady=fs_table_exists($pdo,'tenant_business_hours');
$schemaReady=empty($missingColumns) && $hoursTableReady;

$tenantStmt=$pdo->prepare("SELECT * FROM tenants WHERE id=:tenant_id AND deleted_at IS NULL LIMIT 1");
$tenantStmt->execute(array(':tenant_id'=>$tenantId));
$tenant=$tenantStmt->fetch(PDO::FETCH_ASSOC);
if(!$tenant){http_response_code(404);exit('Business not found.');}

$countries=array();
try{$countries=$pdo->query("SELECT id,name,iso2,default_timezone,date_format,tax_label FROM countries WHERE is_active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$countries=array();}

$hours=array(
    'sunday'=>array('closed'=>1,'open'=>'09:00','close'=>'17:00'),
    'monday'=>array('closed'=>0,'open'=>'09:00','close'=>'17:00'),
    'tuesday'=>array('closed'=>0,'open'=>'09:00','close'=>'17:00'),
    'wednesday'=>array('closed'=>0,'open'=>'09:00','close'=>'17:00'),
    'thursday'=>array('closed'=>0,'open'=>'09:00','close'=>'17:00'),
    'friday'=>array('closed'=>0,'open'=>'09:00','close'=>'17:00'),
    'saturday'=>array('closed'=>1,'open'=>'09:00','close'=>'17:00')
);
if($hoursTableReady){
    $h=$pdo->prepare("SELECT day_of_week,is_closed,open_time,close_time FROM tenant_business_hours WHERE tenant_id=:tenant_id");
    $h->execute(array(':tenant_id'=>$tenantId));
    foreach($h->fetchAll(PDO::FETCH_ASSOC) as $row){
        $day=(string)$row['day_of_week'];
        $hours[$day]=array('closed'=>(int)$row['is_closed'],'open'=>$row['open_time']?substr((string)$row['open_time'],0,5):'09:00','close'=>$row['close_time']?substr((string)$row['close_time'],0,5):'17:00');
    }
}

$taxRates=array();
try{
    $t=$pdo->prepare("SELECT id,tax_name,rate_percent,description,is_default FROM product_tax_rates WHERE tenant_id=:tenant_id AND status='active' ORDER BY is_default DESC,tax_name ASC");
    $t->execute(array(':tenant_id'=>$tenantId));
    $taxRates=$t->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){$taxRates=array();}

$timezoneValue=!empty($tenant['timezone'])?(string)$tenant['timezone']:'UTC';
$dateFormatValue=!empty($tenant['date_format'])?(string)$tenant['date_format']:'d-m-Y';
$timeFormatValue=!empty($tenant['time_format'])?(string)$tenant['time_format']:'12';
$firstDayValue=!empty($tenant['first_day_of_week'])?(string)$tenant['first_day_of_week']:'sunday';

require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/toast.php';
?>
<style>
.company-settings-page{display:grid;grid-template-columns:220px minmax(0,1fr);gap:28px;align-items:start;max-width:1240px;margin:0 auto;padding:10px 0 72px}
.settings-main{min-width:0}.settings-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:0 0 20px}.settings-title-row h1{margin:0;color:var(--text,#0b1933);font-size:30px;line-height:1.15}.settings-title-row p{margin:7px 0 0;color:var(--muted,#6f7b90);font-size:13px}
.settings-card{margin-bottom:18px;padding:20px;background:var(--card-bg,#fff);border:1px solid var(--card-border,#e5eaf1);border-radius:14px;box-shadow:var(--card-shadow,0 2px 8px rgba(0,17,49,.03))}.settings-card h2{margin:0 0 18px;color:var(--text,#0b1933);font-size:20px}.settings-divider{height:1px;margin:18px 0;background:var(--card-border,#e5eaf1)}
.settings-field{position:relative;margin-bottom:12px}.settings-field label{display:block;margin:0 0 6px;color:var(--text,#0b1933);font-size:11px;font-weight:600}.settings-input,.settings-select{width:100%;height:42px;padding:0 13px;border:1px solid var(--card-border,#d9e0e8);border-radius:8px;background:#fff;color:var(--text,#0b1933);font:inherit;font-size:12px;outline:none}.settings-input:focus,.settings-select:focus{border-color:var(--primary,#74b824);box-shadow:0 0 0 2px rgba(116,184,36,.12)}
.settings-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.settings-address-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--card-border,#d9e0e8);border-radius:9px;overflow:hidden;margin-bottom:12px}.settings-address-grid .settings-input,.settings-address-grid .settings-select{border:0;border-radius:0;border-bottom:1px solid var(--card-border,#d9e0e8)}.settings-address-grid .wide{grid-column:1/-1}.settings-address-grid>*:nth-last-child(-n+2){border-bottom:0}
.settings-toggle-row{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:14px 0;border-top:1px solid var(--card-border,#e5eaf1)}.settings-toggle-copy strong{display:block;color:var(--text,#0b1933);font-size:13px}.settings-toggle-copy span{display:block;margin-top:4px;color:var(--muted,#6f7b90);font-size:11px;line-height:1.45}.toggle{position:relative;width:42px;height:23px;flex:0 0 auto}.toggle input{position:absolute;opacity:0}.toggle-track{position:absolute;inset:0;border-radius:999px;background:#cfd7df;transition:.18s}.toggle-track:after{content:"";position:absolute;width:17px;height:17px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.18s}.toggle input:checked+.toggle-track{background:var(--primary,#74b824)}.toggle input:checked+.toggle-track:after{transform:translateX(19px)}
.hours-row{display:grid;grid-template-columns:120px 105px 1fr auto;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--card-border,#e5eaf1)}.hours-row:last-child{border-bottom:0}.hours-day{color:var(--text,#0b1933);font-size:12px;text-transform:capitalize}.hours-times{display:flex;align-items:center;gap:8px}.hours-times input{width:115px}.closed-note{color:var(--muted,#6f7b90);font-size:11px}
.tax-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}.tax-head h2{margin:0}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:0 13px;border:1px solid var(--button-border,#dce3eb);border-radius:9px;background:var(--button-bg,#fff);color:var(--text,#0b1933);font-size:11px;font-weight:700;text-decoration:none;cursor:pointer}.btn:hover{border-color:var(--primary,#74b824)}.btn-primary{background:var(--primary,#74b824);border-color:var(--primary,#74b824);color:#fff}.btn-danger{color:#dc3545}.btn-small{min-height:32px;padding:0 10px;font-size:10px}.btn[disabled]{opacity:.55;cursor:not-allowed}
.tax-identity{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--card-border,#d9e0e8);border-radius:9px;overflow:hidden}.tax-identity input{border:0;border-radius:0}.tax-identity input:first-child{border-right:1px solid var(--card-border,#d9e0e8)}.field-help{margin:6px 0 0;color:var(--muted,#6f7b90);font-size:10px}.tax-empty{display:flex;align-items:center;gap:15px;padding:24px 0 8px}.tax-empty-icon{width:50px;height:50px;display:grid;place-items:center;border-radius:50%;background:#f3f1ed;color:var(--text,#0b1933);font-size:18px}.tax-empty strong{display:block;font-size:13px}.tax-empty p{margin:3px 0 8px;color:var(--muted,#6f7b90);font-size:11px}.tax-list{margin-top:18px;border-top:1px solid var(--card-border,#e5eaf1)}.tax-row{display:grid;grid-template-columns:32px minmax(160px,1fr) 110px minmax(180px,1.4fr) auto;gap:12px;align-items:center;padding:11px 0;border-bottom:1px solid var(--card-border,#e5eaf1)}.tax-name strong{display:block;font-size:12px}.tax-name small{color:var(--muted,#6f7b90);font-size:10px}.tax-rate{font-size:12px}.tax-desc{color:var(--muted,#6f7b90);font-size:11px}.tax-actions{display:flex;gap:7px}
.schema-warning{margin-bottom:16px;padding:12px 14px;border:1px solid #f2d28d;border-radius:9px;background:#fff8e6;color:#7b5b08;font-size:11px;line-height:1.45}
.settings-fixed-actions{position:fixed;left:var(--settings-content-left,0px);right:0;bottom:0;z-index:5000;display:flex;align-items:center;justify-content:flex-end;gap:8px;height:50px;min-height:50px;padding:7px 20px;background:rgba(255,255,255,.98);border-top:1px solid #e5eaf1;box-shadow:0 -5px 18px rgba(0,17,49,.045);backdrop-filter:blur(8px);transition:left .2s ease}.settings-save-state{margin-right:auto;color:#6f7b90;font-size:10px;line-height:1.3}.settings-fixed-actions .btn-primary{min-width:104px;min-height:34px;height:34px;padding:0 12px;border-radius:8px;font-size:10px}
.modal-backdrop{position:fixed;inset:0;z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,17,49,.42)}.modal-backdrop.open{display:flex}.settings-modal{width:min(560px,100%);background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(0,17,49,.2);overflow:hidden}.settings-modal-head{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;border-bottom:1px solid #e5eaf1}.settings-modal-head h3{margin:0;font-size:19px}.modal-close{border:0;background:transparent;font-size:24px;cursor:pointer;color:#314158}.settings-modal-body{padding:22px}.settings-modal-foot{display:flex;justify-content:flex-end;gap:9px;padding:16px 22px;border-top:1px solid #e5eaf1}
@media(max-width:980px){.company-settings-page{grid-template-columns:1fr}.settings-title-row{margin-top:0}}
@media(max-width:680px){.settings-grid-2,.settings-address-grid{grid-template-columns:1fr}.settings-address-grid .wide{grid-column:auto}.settings-address-grid>*{border-bottom:1px solid var(--card-border,#d9e0e8)!important}.settings-address-grid>*:last-child{border-bottom:0!important}.hours-row{grid-template-columns:1fr auto}.hours-times{grid-column:1/-1}.tax-row{grid-template-columns:28px 1fr auto}.tax-rate,.tax-desc{grid-column:2/-1}.settings-card{padding:16px}.settings-fixed-actions{height:48px;min-height:48px;padding:6px 12px}.settings-save-state{display:none}}
</style>

<div class="company-settings-page">
    <?php require __DIR__ . '/includes/settings-nav.php'; ?>

    <main class="settings-main">
        <div class="settings-title-row">
            <div><h1>Company settings</h1><p>Manage your business details, hours, taxes and regional preferences.</p></div>
        </div>

        <?php if(!$schemaReady): ?>
            <div class="schema-warning">The company settings database fields are not fully installed. Run the supplied migration first, then reload this page.</div>
        <?php endif; ?>

        <form id="companySettingsForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= fs_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_company_settings">

            <section class="settings-card">
                <h2>Company details</h2>
                <div class="settings-field"><label for="display_name">Company name</label><input class="settings-input" id="display_name" name="display_name" value="<?= fs_h($tenant['display_name']) ?>" required></div>
                <div class="settings-field"><label for="phone">Phone number</label><input class="settings-input" id="phone" name="phone" value="<?= fs_h($tenant['phone']) ?>"></div>
                <div class="settings-grid-2">
                    <div class="settings-field"><label for="website_url">Website URL</label><input class="settings-input" id="website_url" name="website_url" value="<?= fs_h($tenant['website_url']) ?>" placeholder="https://example.com"></div>
                    <div class="settings-field"><label for="email">Email address</label><input class="settings-input" type="email" id="email" name="email" value="<?= fs_h($tenant['email']) ?>"></div>
                </div>
                <div class="settings-address-grid">
                    <input class="settings-input wide" name="address_line1" value="<?= fs_h($tenant['address_line1']) ?>" placeholder="Street 1">
                    <input class="settings-input wide" name="address_line2" value="<?= fs_h($tenant['address_line2']) ?>" placeholder="Street 2">
                    <input class="settings-input" name="city" value="<?= fs_h($tenant['city']) ?>" placeholder="City">
                    <input class="settings-input" name="state" value="<?= fs_h($tenant['state']) ?>" placeholder="State / Province">
                    <input class="settings-input" name="postal_code" value="<?= fs_h($tenant['postal_code']) ?>" placeholder="Postal / ZIP code">
                    <select class="settings-select company-country-select" name="country_id">
                        <?php foreach($countries as $country): ?><option value="<?= (int)$country['id'] ?>" <?= (int)$country['id']===(int)$tenant['country_id']?'selected':'' ?>><?= fs_h($country['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label style="display:flex;align-items:flex-start;gap:9px;font-size:11px;color:var(--text,#0b1933)"><input type="checkbox" name="address_private" value="1" <?= !empty($tenant['address_private'])?'checked':'' ?>><span>Keep address private<br><small style="color:var(--muted,#6f7b90)">Your address will not appear in public customer-facing locations.</small></span></label>

                <div class="settings-divider"></div>
                <h3 style="margin:0 0 12px;font-size:14px;color:var(--text,#0b1933)">Business hours</h3>
                <p style="margin:-4px 0 10px;color:var(--muted,#6f7b90);font-size:11px">Business hours set your default availability for online booking, team members and request forms.</p>
                <div class="hours-list">
                    <?php foreach($hours as $day=>$hour): ?>
                    <div class="hours-row" data-day-row="<?= fs_h($day) ?>">
                        <div class="hours-day"><?= fs_h($day) ?></div>
                        <label style="display:flex;align-items:center;gap:7px;font-size:11px"><input class="day-closed" type="checkbox" name="hours[<?= fs_h($day) ?>][closed]" value="1" <?= !empty($hour['closed'])?'checked':'' ?>> Closed</label>
                        <div class="hours-times"><input class="settings-input hour-time" type="time" name="hours[<?= fs_h($day) ?>][open]" value="<?= fs_h($hour['open']) ?>" <?= !empty($hour['closed'])?'disabled':'' ?>><span class="closed-note">to</span><input class="settings-input hour-time" type="time" name="hours[<?= fs_h($day) ?>][close]" value="<?= fs_h($hour['close']) ?>" <?= !empty($hour['closed'])?'disabled':'' ?>></div>
                        <span class="closed-note day-status"><?= !empty($hour['closed'])?'Closed':'' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="settings-toggle-row"><div class="settings-toggle-copy"><strong>Show business hours</strong><span>Display your business hours in customer-facing areas.</span></div><label class="toggle"><input type="checkbox" name="show_business_hours" value="1" <?= !isset($tenant['show_business_hours']) || (int)$tenant['show_business_hours']===1?'checked':'' ?>><span class="toggle-track"></span></label></div>
                <div class="settings-toggle-row"><div class="settings-toggle-copy"><strong>Help customers find my business</strong><span>Allow public business information such as name, services and contact details to be used in FieldPlx discovery features.</span></div><label class="toggle"><input type="checkbox" name="allow_public_discovery" value="1" <?= !empty($tenant['allow_public_discovery'])?'checked':'' ?>><span class="toggle-track"></span></label></div>
            </section>

            <section class="settings-card">
                <div class="tax-head"><h2>Tax settings</h2><button class="btn" type="button" id="openTaxModal">+ Create Tax Rate</button></div>
                <div class="tax-identity"><input class="settings-input" name="tax_id_name" value="<?= fs_h(isset($tenant['tax_id_name'])?$tenant['tax_id_name']:'') ?>" placeholder="Tax ID name (ex: GST)"><input class="settings-input" name="tax_number" value="<?= fs_h($tenant['tax_number']) ?>" placeholder="Tax ID number"></div>
                <p class="field-help">Tax ID name and number can appear on invoices and other customer documents.</p>
                <?php if(empty($taxRates)): ?>
                <div class="settings-divider"></div><div class="tax-empty"><div class="tax-empty-icon">%</div><div><strong>No tax rates</strong><p>Create one or more tax rates to apply them to quotes, jobs and invoices.</p><button class="btn btn-primary btn-small" type="button" data-open-tax>+ Create Tax Rate</button></div></div>
                <?php else: ?>
                <div class="tax-list">
                    <?php foreach($taxRates as $tax): ?>
                    <div class="tax-row"><div><input class="tax-radio" type="radio" disabled <?= !empty($tax['is_default'])?'checked':'' ?>></div><div class="tax-name"><strong><?= fs_h($tax['tax_name']) ?></strong><small><?= !empty($tax['is_default'])?'Default':'Active' ?></small></div><div class="tax-rate"><?= fs_h(rtrim(rtrim(number_format((float)$tax['rate_percent'],4,'.',''),'0'),'.')) ?>%</div><div class="tax-desc"><?= fs_h($tax['description']) ?></div><div class="tax-actions"><button type="button" class="btn btn-small edit-tax" data-id="<?= (int)$tax['id'] ?>" data-name="<?= fs_h($tax['tax_name']) ?>" data-rate="<?= fs_h($tax['rate_percent']) ?>" data-description="<?= fs_h($tax['description']) ?>" data-default="<?= !empty($tax['is_default'])?'1':'0' ?>">Edit</button><button type="button" class="btn btn-small btn-danger delete-tax" data-id="<?= (int)$tax['id'] ?>">Remove</button></div></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <section class="settings-card">
                <h2>Regional settings</h2>
                <div class="settings-field"><label>Country</label><select class="settings-select" name="regional_country_id" id="countrySelectRegional"><?php foreach($countries as $country): ?><option value="<?= (int)$country['id'] ?>" <?= (int)$country['id']===(int)$tenant['country_id']?'selected':'' ?>><?= fs_h($country['name']) ?></option><?php endforeach; ?></select></div>
                <div class="settings-field"><label>Timezone</label><select class="settings-select" name="timezone"><?php foreach(timezone_identifiers_list() as $tz): ?><option value="<?= fs_h($tz) ?>" <?= $tz===$timezoneValue?'selected':'' ?>><?= fs_h($tz) ?></option><?php endforeach; ?></select></div>
                <div class="settings-field"><label>Date format</label><select class="settings-select" name="date_format"><option value="d-m-Y" <?= $dateFormatValue==='d-m-Y'?'selected':'' ?>>31-01-2026</option><option value="m-d-Y" <?= $dateFormatValue==='m-d-Y'?'selected':'' ?>>01-31-2026</option><option value="Y-m-d" <?= $dateFormatValue==='Y-m-d'?'selected':'' ?>>2026-01-31</option><option value="d/m/Y" <?= $dateFormatValue==='d/m/Y'?'selected':'' ?>>31/01/2026</option><option value="m/d/Y" <?= $dateFormatValue==='m/d/Y'?'selected':'' ?>>01/31/2026</option><option value="M j, Y" <?= $dateFormatValue==='M j, Y'?'selected':'' ?>>Jan 31, 2026</option></select></div>
                <div class="settings-field"><label>Time format</label><select class="settings-select" name="time_format"><option value="12" <?= $timeFormatValue==='12'?'selected':'' ?>>12 Hour (1:30 PM)</option><option value="24" <?= $timeFormatValue==='24'?'selected':'' ?>>24 Hour (13:30)</option></select></div>
                <div class="settings-field" style="margin-bottom:0"><label>First day of the week</label><select class="settings-select" name="first_day_of_week"><option value="sunday" <?= $firstDayValue==='sunday'?'selected':'' ?>>Sunday</option><option value="monday" <?= $firstDayValue==='monday'?'selected':'' ?>>Monday</option><option value="saturday" <?= $firstDayValue==='saturday'?'selected':'' ?>>Saturday</option></select></div>
            </section>
        </form>
    </main>
</div>

<div class="settings-fixed-actions">
    <div class="settings-save-state" id="settingsSaveState">Changes are saved only when you click Update Settings.</div>
    <button class="btn btn-primary" id="updateSettingsButton" type="button" <?= !$schemaReady?'disabled':'' ?>>Update Settings</button>
</div>

<div class="modal-backdrop" id="taxModal" aria-hidden="true"><div class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="taxModalTitle"><form id="taxRateForm"><input type="hidden" name="csrf_token" value="<?= fs_h($csrfToken) ?>"><input type="hidden" name="action" value="save_tax_rate"><input type="hidden" name="tax_rate_id" id="tax_rate_id" value="0"><div class="settings-modal-head"><h3 id="taxModalTitle">Create tax rate</h3><button type="button" class="modal-close" id="closeTaxModal">&times;</button></div><div class="settings-modal-body"><div class="settings-field"><label>Tax name</label><input class="settings-input" name="tax_name" id="tax_name" placeholder="GST" required></div><div class="settings-field"><label>Tax rate (%)</label><input class="settings-input" type="number" min="0" max="100" step="0.0001" name="rate_percent" id="rate_percent" placeholder="18" required></div><div class="settings-field"><label>Internal tax description</label><input class="settings-input" name="tax_description" id="tax_description" placeholder="Optional description"></div><label style="display:flex;align-items:center;gap:8px;font-size:11px"><input type="checkbox" name="is_default" id="is_default" value="1"> Set as default tax rate</label></div><div class="settings-modal-foot"><button class="btn" type="button" id="cancelTaxModal">Cancel</button><button class="btn btn-primary" type="submit" id="saveTaxButton">Save Tax Rate</button></div></form></div></div>

<script>
(function(){
'use strict';

var apiUrl='api/settings.php';
var form=document.getElementById('companySettingsForm');
var updateButton=document.getElementById('updateSettingsButton');
var saveState=document.getElementById('settingsSaveState');
var initialSerialized='';

function serializeForm(f){return new URLSearchParams(new FormData(f)).toString();}
function setDirty(){if(!form)return;var dirty=serializeForm(form)!==initialSerialized; if(saveState)saveState.textContent=dirty?'You have unsaved changes.':'All changes are up to date.';}
function request(fd){
    return fetch(apiUrl,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
        .then(function(r){return r.text().then(function(t){var d;try{d=JSON.parse(t);}catch(e){throw new Error('Invalid server response.');}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d;});});
}

var companyCountry=document.querySelector('.company-country-select');
var regionalCountry=document.getElementById('countrySelectRegional');
if(companyCountry&&regionalCountry){companyCountry.addEventListener('change',function(){regionalCountry.value=companyCountry.value;setDirty();});regionalCountry.addEventListener('change',function(){companyCountry.value=regionalCountry.value;setDirty();});}

document.querySelectorAll('.day-closed').forEach(function(box){box.addEventListener('change',function(){var row=box.closest('[data-day-row]');if(!row)return;row.querySelectorAll('.hour-time').forEach(function(input){input.disabled=box.checked;});var note=row.querySelector('.day-status');if(note)note.textContent=box.checked?'Closed':'';setDirty();});});
if(form){form.addEventListener('input',setDirty);form.addEventListener('change',setDirty);initialSerialized=serializeForm(form);}

if(updateButton){updateButton.addEventListener('click',function(){
    if(!form||updateButton.disabled)return;
    if(!form.reportValidity())return;
    var fd=new FormData(form);
    if(companyCountry)fd.set('country_id',companyCountry.value);
    updateButton.disabled=true;updateButton.textContent='Updating...';if(saveState)saveState.textContent='Saving changes...';
    request(fd).then(function(data){initialSerialized=serializeForm(form);setDirty();fieldplxToast('success',data.message||'Company settings updated.');}).catch(function(err){fieldplxToast('error',err.message);if(saveState)saveState.textContent='Unable to save changes.';}).then(function(){updateButton.disabled=false;updateButton.textContent='Update Settings';});
});}

var modal=document.getElementById('taxModal'),title=document.getElementById('taxModalTitle'),idInput=document.getElementById('tax_rate_id'),nameInput=document.getElementById('tax_name'),rateInput=document.getElementById('rate_percent'),descInput=document.getElementById('tax_description'),defaultInput=document.getElementById('is_default');
function openTax(data){data=data||{};idInput.value=data.id||'0';nameInput.value=data.name||'';rateInput.value=data.rate||'';descInput.value=data.description||'';defaultInput.checked=data.isDefault==='1';title.textContent=data.id?'Edit tax rate':'Create tax rate';modal.classList.add('open');modal.setAttribute('aria-hidden','false');setTimeout(function(){nameInput.focus();},50);}
function closeTax(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');}
var openButton=document.getElementById('openTaxModal');if(openButton)openButton.addEventListener('click',function(){openTax();});
document.querySelectorAll('[data-open-tax]').forEach(function(b){b.addEventListener('click',function(){openTax();});});
document.getElementById('closeTaxModal').addEventListener('click',closeTax);document.getElementById('cancelTaxModal').addEventListener('click',closeTax);modal.addEventListener('click',function(e){if(e.target===modal)closeTax();});document.addEventListener('keydown',function(e){if(e.key==='Escape')closeTax();});
document.querySelectorAll('.edit-tax').forEach(function(b){b.addEventListener('click',function(){openTax({id:b.dataset.id,name:b.dataset.name,rate:b.dataset.rate,description:b.dataset.description,isDefault:b.dataset.default});});});

document.getElementById('taxRateForm').addEventListener('submit',function(e){e.preventDefault();var taxForm=e.currentTarget;if(!taxForm.reportValidity())return;var save=document.getElementById('saveTaxButton');save.disabled=true;save.textContent='Saving...';request(new FormData(taxForm)).then(function(data){fieldplxToast('success',data.message);closeTax();setTimeout(function(){window.location.reload();},550);}).catch(function(err){fieldplxToast('error',err.message);save.disabled=false;save.textContent='Save Tax Rate';});});

document.querySelectorAll('.delete-tax').forEach(function(b){b.addEventListener('click',function(){if(!window.confirm('Remove this tax rate? Existing records will not be changed.'))return;var fd=new FormData();fd.append('csrf_token','<?= fs_h($csrfToken) ?>');fd.append('action','delete_tax_rate');fd.append('tax_rate_id',b.dataset.id);request(fd).then(function(data){fieldplxToast('success',data.message);setTimeout(function(){window.location.reload();},500);}).catch(function(err){fieldplxToast('error',err.message);});});});

window.addEventListener('beforeunload',function(e){if(form&&serializeForm(form)!==initialSerialized){e.preventDefault();e.returnValue='';}});
function syncFixedSettingsBar(){
    var main=document.querySelector('.settings-main');
    if(!main)return;
    var rect=main.getBoundingClientRect();
    var left=Math.max(0,Math.round(rect.left));
    document.documentElement.style.setProperty('--settings-content-left',left+'px');
}

syncFixedSettingsBar();
window.addEventListener('resize',syncFixedSettingsBar);
window.addEventListener('load',syncFixedSettingsBar);

if(window.ResizeObserver){
    var settingsBarResizeObserver=new ResizeObserver(function(){
        window.requestAnimationFrame(syncFixedSettingsBar);
    });
    var watched=document.querySelectorAll('.company-settings-page,.settings-nav,.settings-main,.fieldplx-sidebar,#fieldplxSidebar,.sidebar,#sidebar,.app-sidebar,.main-sidebar');
    watched.forEach(function(el){settingsBarResizeObserver.observe(el);});
}

if(window.MutationObserver){
    var settingsBarClassObserver=new MutationObserver(function(){
        window.requestAnimationFrame(syncFixedSettingsBar);
        window.setTimeout(syncFixedSettingsBar,220);
    });
    settingsBarClassObserver.observe(document.body,{attributes:true,attributeFilter:['class','style']});
}

document.addEventListener('click',function(e){
    var t=e.target.closest('[data-sidebar-toggle],.sidebar-toggle,#sidebarToggle,.fieldplx-sidebar-toggle,[aria-label*="sidebar" i]');
    if(t){
        window.requestAnimationFrame(syncFixedSettingsBar);
        window.setTimeout(syncFixedSettingsBar,220);
    }
});

if(window.lucide&&typeof window.lucide.createIcons==='function')window.lucide.createIcons();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>