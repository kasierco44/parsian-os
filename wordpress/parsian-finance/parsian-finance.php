<?php
/**
 * Plugin Name: PARSIAN OS Finance
 * Description: پنل مالی تأمین‌کنندگان پارسیان قطعه
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class Parsian_OS_Finance {
    const VIEW = 'parsian_view_finance';
    const EDIT = 'parsian_manage_finance';
    const OPEN = 'parsian_manage_opening_balance';
    private $t;
    function __construct() {
        global $wpdb;
        $p=$wpdb->prefix;
        $this->t=array('s'=>$p.'parsian_suppliers','b'=>$p.'parsian_supplier_bills','p'=>$p.'parsian_supplier_payments','a'=>$p.'parsian_finance_audit');
        add_action('rest_api_init',array($this,'routes'));
        add_action('admin_menu',array($this,'menu'));
        add_action('admin_enqueue_scripts',array($this,'assets'));
        add_shortcode('parsian_finance_panel',array($this,'shortcode'));
    }
    static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate(); $p=$wpdb->prefix;
        dbDelta("CREATE TABLE {$p}parsian_suppliers (id bigint unsigned NOT NULL AUTO_INCREMENT,name varchar(190) NOT NULL,phone varchar(40) NOT NULL DEFAULT '',note text NULL,created_by bigint unsigned NOT NULL,created_at datetime NOT NULL,PRIMARY KEY  (id),KEY name (name)) $c;");
        dbDelta("CREATE TABLE {$p}parsian_supplier_bills (id bigint unsigned NOT NULL AUTO_INCREMENT,supplier_id bigint unsigned NOT NULL,bill_type varchar(20) NOT NULL DEFAULT 'bill',description varchar(255) NOT NULL,amount bigint unsigned NOT NULL,due_date date NULL,document_no varchar(100) NOT NULL DEFAULT '',created_by bigint unsigned NOT NULL,created_at datetime NOT NULL,PRIMARY KEY  (id),KEY supplier_id (supplier_id),KEY due_date (due_date)) $c;");
        dbDelta("CREATE TABLE {$p}parsian_supplier_payments (id bigint unsigned NOT NULL AUTO_INCREMENT,supplier_id bigint unsigned NOT NULL,method varchar(20) NOT NULL,amount bigint unsigned NOT NULL,paid_at date NOT NULL,due_date date NULL,reference_no varchar(100) NOT NULL DEFAULT '',status varchar(20) NOT NULL DEFAULT 'paid',note text NULL,created_by bigint unsigned NOT NULL,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY  (id),KEY supplier_id (supplier_id),KEY due_date (due_date),KEY status (status)) $c;");
        dbDelta("CREATE TABLE {$p}parsian_finance_audit (id bigint unsigned NOT NULL AUTO_INCREMENT,user_id bigint unsigned NOT NULL,action varchar(80) NOT NULL,object_type varchar(40) NOT NULL,object_id bigint unsigned NOT NULL DEFAULT 0,details longtext NULL,created_at datetime NOT NULL,PRIMARY KEY  (id),KEY user_id (user_id)) $c;");
        $r=get_role('administrator'); if($r){$r->add_cap(self::VIEW);$r->add_cap(self::EDIT);$r->add_cap(self::OPEN);}
        add_role('parsian_manager','مدیر مالی پارسیان',array('read'=>true,self::VIEW=>true,self::EDIT=>true,self::OPEN=>true));
        add_role('parsian_accountant','حسابدار پارسیان',array('read'=>true,self::VIEW=>true,self::EDIT=>true));
    }
    function menu(){add_menu_page('مالی پارسیان','مالی پارسیان',self::VIEW,'parsian-finance',array($this,'page'),'dashicons-chart-area',27);}
    function page(){if(!current_user_can(self::VIEW))wp_die('دسترسی غیرمجاز');echo '<div class="wrap"><div id="parsian-finance"></div></div>';}
    function shortcode(){if(!current_user_can(self::VIEW))return 'برای مشاهده پنل وارد حساب مجاز شوید.';$this->enqueue_assets();return '<div id="parsian-finance"></div>';}
    function assets($hook){if($hook==='toplevel_page_parsian-finance')$this->enqueue_assets();}
    private function enqueue_assets(){wp_register_style('parsian-finance',false);wp_enqueue_style('parsian-finance');wp_add_inline_style('parsian-finance',$this->css());wp_register_script('parsian-finance',false,array(), '0.1.0',true);wp_enqueue_script('parsian-finance');wp_add_inline_script('parsian-finance','window.PF='.wp_json_encode(array('root'=>rest_url('parsian-finance/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'opening'=>current_user_can(self::OPEN))).';'.$this->js());}
    function ok(){return current_user_can(self::VIEW);}
    function edit(){return current_user_can(self::EDIT);}
    function opening(){return current_user_can(self::OPEN);}
    function routes(){
        register_rest_route('parsian-finance/v1','/suppliers',array(array('methods'=>'GET','callback'=>array($this,'suppliers'),'permission_callback'=>array($this,'ok')),array('methods'=>'POST','callback'=>array($this,'add_supplier'),'permission_callback'=>array($this,'edit'))));
        register_rest_route('parsian-finance/v1','/bills',array(array('methods'=>'GET','callback'=>array($this,'bills'),'permission_callback'=>array($this,'ok')),array('methods'=>'POST','callback'=>array($this,'add_bill'),'permission_callback'=>array($this,'edit'))));
        register_rest_route('parsian-finance/v1','/payments',array(array('methods'=>'GET','callback'=>array($this,'payments'),'permission_callback'=>array($this,'ok')),array('methods'=>'POST','callback'=>array($this,'add_payment'),'permission_callback'=>array($this,'edit'))));
        register_rest_route('parsian-finance/v1','/payments/(?P<id>\d+)/status',array('methods'=>'POST','callback'=>array($this,'payment_status'),'permission_callback'=>array($this,'edit')));
        register_rest_route('parsian-finance/v1','/summary',array('methods'=>'GET','callback'=>array($this,'summary'),'permission_callback'=>array($this,'ok')));
    }
    function audit($action,$type,$id,$details=array()){global $wpdb;$wpdb->insert($this->t['a'],array('user_id'=>get_current_user_id(),'action'=>$action,'object_type'=>$type,'object_id'=>$id,'details'=>wp_json_encode($details),'created_at'=>current_time('mysql',true)));}
    function amount($v){if(!is_scalar($v)||!preg_match('/^\d{1,15}$/',(string)$v)||(int)$v<1)return new WP_Error('invalid_amount','مبلغ باید عدد صحیح مثبت به ریال باشد.',array('status'=>400));return (int)$v;}
    function suppliers(){global $wpdb;$q=$wpdb->get_results("SELECT s.*,(SELECT COALESCE(SUM(amount),0) FROM {$this->t['b']} b WHERE b.supplier_id=s.id) bills,(SELECT COALESCE(SUM(amount),0) FROM {$this->t['p']} p WHERE p.supplier_id=s.id AND p.status='paid') paid FROM {$this->t['s']} s ORDER BY id DESC",ARRAY_A);foreach($q as &$x)$x['balance']=max(0,(int)$x['bills']-(int)$x['paid']);return rest_ensure_response($q);}
    function add_supplier($r){global $wpdb;$n=sanitize_text_field($r['name']);if(!$n)return new WP_Error('name','نام تأمین‌کننده الزامی است.',array('status'=>400));$wpdb->insert($this->t['s'],array('name'=>$n,'phone'=>sanitize_text_field($r['phone']),'note'=>sanitize_textarea_field($r['note']),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)));if(!$wpdb->insert_id)return new WP_Error('db','ثبت انجام نشد.',array('status'=>500));$id=(int)$wpdb->insert_id;$this->audit('create','supplier',$id,array('name'=>$n));return new WP_REST_Response(array('id'=>$id),201);}
    function add_bill($r){global $wpdb;$sid=absint($r['supplier_id']);$type=sanitize_key($r['bill_type']?:'bill');if(!in_array($type,array('bill','opening'),true))return new WP_Error('type','نوع سند نامعتبر است.',array('status'=>400));if($type==='opening'&&!$this->opening())return new WP_Error('forbidden','مانده افتتاحیه فقط برای مدیر مجاز است.',array('status'=>403));$a=$this->amount($r['amount']);if(is_wp_error($a))return $a;if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->t['s']} WHERE id=%d",$sid)))return new WP_Error('supplier','تأمین‌کننده یافت نشد.',array('status'=>404));$d=sanitize_text_field($r['due_date']);if($d&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))return new WP_Error('date','تاریخ نامعتبر است.',array('status'=>400));$wpdb->insert($this->t['b'],array('supplier_id'=>$sid,'bill_type'=>$type,'description'=>sanitize_text_field($r['description']),'amount'=>$a,'due_date'=>$d?:null,'document_no'=>sanitize_text_field($r['document_no']),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)));$id=(int)$wpdb->insert_id;if(!$id)return new WP_Error('db','ثبت بدهی ناموفق بود.',array('status'=>500));$this->audit('create','bill',$id,array('supplier_id'=>$sid,'amount'=>$a));return new WP_REST_Response(array('id'=>$id),201);}
    function add_payment($r){global $wpdb;$sid=absint($r['supplier_id']);$a=$this->amount($r['amount']);if(is_wp_error($a))return $a;$m=sanitize_key($r['method']);if(!in_array($m,array('cash','bank','cheque'),true))return new WP_Error('method','روش پرداخت نامعتبر است.',array('status'=>400));if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->t['s']} WHERE id=%d",$sid)))return new WP_Error('supplier','تأمین‌کننده یافت نشد.',array('status'=>404));$date=sanitize_text_field($r['paid_at']?:current_time('Y-m-d'));$due=sanitize_text_field($r['due_date']);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||($due&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))||($m==='cheque'&&!$due))return new WP_Error('date','تاریخ نامعتبر است؛ سررسید چک الزامی است.',array('status'=>400));$now=current_time('mysql',true);$wpdb->insert($this->t['p'],array('supplier_id'=>$sid,'method'=>$m,'amount'=>$a,'paid_at'=>$date,'due_date'=>$due?:null,'reference_no'=>sanitize_text_field($r['reference_no']),'status'=>$m==='cheque'?'issued':'paid','note'=>sanitize_textarea_field($r['note']),'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now));$id=(int)$wpdb->insert_id;if(!$id)return new WP_Error('db','ثبت پرداخت ناموفق بود.',array('status'=>500));$this->audit('create','payment',$id,array('supplier_id'=>$sid,'amount'=>$a,'method'=>$m));return new WP_REST_Response(array('id'=>$id),201);}
    function payment_status($r){global $wpdb;$id=absint($r['id']);$status=sanitize_key($r['status']);if(!in_array($status,array('paid','bounced'),true))return new WP_Error('status','وضعیت نامعتبر است.',array('status'=>400));$p=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->t['p']} WHERE id=%d",$id),ARRAY_A);if(!$p||$p['method']!=='cheque'||$p['status']!=='issued')return new WP_Error('transition','فقط چک صادرشده قابل تغییر است.',array('status'=>409));$wpdb->update($this->t['p'],array('status'=>$status,'updated_at'=>current_time('mysql',true)),array('id'=>$id));$this->audit('cheque_'.$status,'payment',$id);return rest_ensure_response(array('status'=>$status));}
    function bills(){global $wpdb;return rest_ensure_response($wpdb->get_results("SELECT b.*,s.name supplier_name FROM {$this->t['b']} b JOIN {$this->t['s']} s ON s.id=b.supplier_id ORDER BY b.id DESC LIMIT 500",ARRAY_A));}
    function payments(){global $wpdb;return rest_ensure_response($wpdb->get_results("SELECT p.*,s.name supplier_name FROM {$this->t['p']} p JOIN {$this->t['s']} s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 500",ARRAY_A));}
    function summary(){global $wpdb;$b=(int)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$this->t['b']}");$p=(int)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$this->t['p']} WHERE status='paid'");$c=(int)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$this->t['p']} WHERE status='issued'");return rest_ensure_response(array('bills'=>$b,'paid'=>$p,'balance'=>max(0,$b-$p),'cheques'=>$c));}
    function css(){return '#parsian-finance{direction:rtl;font:14px Tahoma,Arial;max-width:1100px;color:#20242b}.pfgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.pfcard{background:#fff;border:1px solid #ddd;border-radius:12px;padding:15px;margin:12px 0}.pfbtn{background:#991b1b;color:#fff;border:0;border-radius:8px;padding:12px 16px;min-height:44px;cursor:pointer}.pfbtn.alt{background:#eee;color:#222}.pfnav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0}.pfform{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.pfform label{display:flex;flex-direction:column;gap:5px}.pfform input,.pfform select{min-height:42px;border:1px solid #ccc;border-radius:7px;padding:8px}.pftable{width:100%;border-collapse:collapse;min-width:600px}.pftable td,.pftable th{padding:10px;border-bottom:1px solid #ddd;text-align:right}.pfscroll{overflow:auto}.pfmsg{padding:12px;background:#fff4e5;margin:10px 0}';}
    function js(){return <<<'JS'
(function(){
var el=document.getElementById('parsian-finance');if(!el||!window.PF)return;var tab='home',data={s:[],b:[],p:[]},msg='';
function esc(x){return String(x==null?'':x).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function money(x){return new Intl.NumberFormat('fa-IR').format(Number(x||0))+' ریال';}
function api(path,method,body){return fetch(PF.root+path,{method:method||'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':PF.nonce},body:body?JSON.stringify(body):undefined}).then(function(r){return r.json().then(function(d){if(!r.ok)throw Error(d.message||'خطا');return d;});});}
function form(id,html){return '<form class="pfform" id="'+id+'">'+html+'<button class="pfbtn">ثبت</button></form>';}
function sel(label,name,arr){return '<label>'+label+'<select name="'+name+'">'+arr.map(function(x){return '<option value="'+x[0]+'">'+esc(x[1])+'</option>';}).join('')+'</select></label>';}
function inp(label,name,type,req){return '<label>'+label+'<input name="'+name+'" type="'+(type||'text')+'" '+(req?'required':'')+'></label>';}
function table(head,rows){return '<div class="pfscroll"><table class="pftable"><thead><tr>'+head.map(function(x){return '<th>'+x+'</th>';}).join('')+'</tr></thead><tbody>'+(rows||'<tr><td colspan="'+head.length+'">موردی ثبت نشده است.</td></tr>')+'</tbody></table></div>';}
function load(){return Promise.all([api('suppliers'),api('bills'),api('payments')]).then(function(v){data.s=v[0];data.b=v[1];data.p=v[2];render();});}
function render(){var nav=[['home','داشبورد'],['sup','تأمین‌کنندگان'],['bill','ثبت بدهی'],['pay','پرداخت و چک']];var h='<h2>پارسیان OS | پنل مالی</h2><p>مدیریت بدهی و پرداخت تأمین‌کنندگان</p>'+(msg?'<div class="pfmsg">'+esc(msg)+'</div>':'')+'<div class="pfnav">'+nav.map(function(n){return '<button class="pfbtn '+(tab===n[0]?'':'alt')+'" data-tab="'+n[0]+'">'+n[1]+'</button>';}).join('')+'</div>';
if(tab==='home')h+='<div class="pfgrid" id="pfkpi"></div>';
if(tab==='sup')h+='<div class="pfcard"><b>ثبت تأمین‌کننده</b>'+form('fs',inp('نام','name','text',true)+inp('تلفن','phone'))+'</div><div class="pfcard"><b>تأمین‌کنندگان</b>'+table(['نام','تلفن','جمع بدهی','پرداخت قطعی','مانده'],data.s.map(function(x){return '<tr><td>'+esc(x.name)+'</td><td>'+esc(x.phone)+'</td><td>'+money(x.bills)+'</td><td>'+money(x.paid)+'</td><td>'+money(x.balance)+'</td></tr>';}).join(''))+'</div>';
if(tab==='bill')h+='<div class="pfcard"><b>ثبت بدهی یا مانده افتتاحیه</b>'+form('fb',sel('تأمین‌کننده','supplier_id',data.s.map(function(x){return [x.id,x.name];}))+inp('شرح','description','text',true)+inp('مبلغ به ریال','amount','number',true)+inp('شماره سند','document_no')+inp('سررسید','due_date','date')+(PF.opening?sel('نوع','bill_type',[['bill','بدهی جدید'],['opening','مانده افتتاحیه']]):''))+'</div><div class="pfcard">'+table(['تأمین‌کننده','شرح','مبلغ','سررسید'],data.b.map(function(x){return '<tr><td>'+esc(x.supplier_name)+'</td><td>'+esc(x.description)+'</td><td>'+money(x.amount)+'</td><td>'+esc(x.due_date)+'</td></tr>';}).join(''))+'</div>';
if(tab==='pay')h+='<div class="pfcard"><b>ثبت پرداخت</b>'+form('fp',sel('تأمین‌کننده','supplier_id',data.s.map(function(x){return [x.id,x.name];}))+sel('روش','method',[['cash','نقدی'],['bank','بانکی'],['cheque','چک']])+inp('مبلغ به ریال','amount','number',true)+inp('تاریخ','paid_at','date',true)+inp('سررسید چک','due_date','date')+inp('شماره پیگیری / چک','reference_no'))+'</div><div class="pfcard">'+table(['تأمین‌کننده','روش','مبلغ','سررسید','وضعیت','عملیات'],data.p.map(function(x){return '<tr><td>'+esc(x.supplier_name)+'</td><td>'+esc(x.method)+'</td><td>'+money(x.amount)+'</td><td>'+esc(x.due_date)+'</td><td>'+esc(x.status)+'</td><td>'+(x.status==='issued'?'<button class="pfbtn" data-status="paid" data-id="'+x.id+'">پاس شد</button> <button class="pfbtn alt" data-status="bounced" data-id="'+x.id+'">برگشت</button>':'—')+'</td></tr>';}).join(''))+'</div>';
el.innerHTML=h;el.querySelectorAll('[data-tab]').forEach(function(b){b.onclick=function(){tab=b.dataset.tab;msg='';render();};});
if(tab==='home')api('summary').then(function(s){var k=document.getElementById('pfkpi');if(k)k.innerHTML=[['جمع بدهی',s.bills],['پرداخت قطعی',s.paid],['مانده',s.balance],['چک صادرشده',s.cheques]].map(function(x){return '<div class="pfcard"><small>'+x[0]+'</small><h2>'+money(x[1])+'</h2></div>';}).join('');});
function submit(id,path){var f=document.getElementById(id);if(!f)return;f.onsubmit=function(e){e.preventDefault();var o={};new FormData(f).forEach(function(v,k){o[k]=v;});api(path,'POST',o).then(function(){msg='ثبت با موفقیت انجام شد.';return load();}).catch(function(x){msg='خطا: '+x.message;render();});};}
submit('fs','suppliers');submit('fb','bills');submit('fp','payments');
el.querySelectorAll('[data-status]').forEach(function(b){b.onclick=function(){if(!confirm('وضعیت چک تغییر کند؟'))return;api('payments/'+b.dataset.id+'/status','POST',{status:b.dataset.status}).then(function(){msg='وضعیت چک ثبت شد.';return load();}).catch(function(x){msg='خطا: '+x.message;render();});};});
}
load().catch(function(e){el.innerHTML='<div class="pfmsg">خطا در دریافت اطلاعات: '+esc(e.message)+'</div>';});
})();
JS;}
}
register_activation_hook(__FILE__,array('Parsian_OS_Finance','activate'));
new Parsian_OS_Finance();
