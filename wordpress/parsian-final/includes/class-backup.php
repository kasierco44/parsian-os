<?php
if (!defined('ABSPATH')) { exit; }

/** Manager-only database snapshot export and non-destructive validation. */
final class Parsian_Final_Backup {
    private const MAX_ROWS_PER_TABLE = 10000;
    private const MAX_BACKUP_BYTES = 20971520;
    private const MAX_BACKUP_ROWS_TOTAL = 100000;

    private function tables(): array {
        return ['devices','sync_ops','customers','suppliers','leads','tasks','products','stock_movements','stock_ledger','stock_reservations','proformas','proforma_items','invoices','invoice_items','payments','payment_allocations','cheques','cheque_events','ledger_accounts','journal_entries','journal_lines','bank_accounts','bank_statement_lines','bank_import_batches','bank_reconciliation_matches','events'];
    }

    public function register_routes(): void {
        $permission = static function (): bool { return is_user_logged_in() && current_user_can('parsian_manage_settings'); };
        register_rest_route('parsian-final/v1','/backup/export',['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'export_snapshot'],'permission_callback'=>$permission]);
        register_rest_route('parsian-final/v1','/backup/validate',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'validate_snapshot'],'permission_callback'=>$permission]);
    }

    public function export_snapshot(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $tables=[];
        // Read every table from one InnoDB snapshot so concurrent writes cannot
        // produce a backup assembled from different points in time.
        if($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')===false)
            return new WP_REST_Response(['code'=>'backup_snapshot_transaction_failed'],500);
        $totalRows=0;
        foreach($this->tables() as $suffix){
            $table=$wpdb->prefix.'parsian_'.$suffix;
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
            if($exists!==$table){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'backup_table_missing','table'=>$suffix],500);}
            $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            if($count>self::MAX_ROWS_PER_TABLE){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'backup_table_limit_exceeded','table'=>$suffix,'row_count'=>$count,'limit'=>self::MAX_ROWS_PER_TABLE,'message'=>'برای پایگاه‌های بزرگ از پشتیبان کامل هاست و دیتابیس استفاده کنید.'],413);}
            $totalRows+=$count;
            if($totalRows>self::MAX_BACKUP_ROWS_TOTAL){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'backup_total_row_limit_exceeded','row_count'=>$totalRows,'limit'=>self::MAX_BACKUP_ROWS_TOTAL],413);}
            $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY 1 ASC",ARRAY_A);
            if($wpdb->last_error){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'backup_table_read_failed','table'=>$suffix],500);}
            $tables[$suffix]=$rows?:[];
        }
        $assignments=$wpdb->get_results("SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key='parsian_customer_id' OR meta_key LIKE 'parsian_customer_%' ORDER BY user_id,meta_key",ARRAY_A);
        if($wpdb->last_error){$wpdb->query('ROLLBACK');return new WP_REST_Response(['code'=>'backup_assignments_read_failed'],500);}
        $wpdb->query('COMMIT');
        $assignments=$assignments?:[];
        $payload=['format'=>'PARSIAN_FINAL_BACKUP','format_version'=>1,'plugin_version'=>PARSIAN_FINAL_VERSION,'schema_version'=>get_option('parsian_final_schema_version','unknown'),'source_site'=>home_url('/'),'created_at_utc'=>gmdate('c'),'tables'=>$tables,'excluded_tables'=>['auth_tokens'=>['reason'=>'credential material is intentionally excluded; revoke and re-register devices after disaster recovery']],'user_customer_assignments'=>$assignments];
        $canonical=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($canonical)||strlen($canonical)>self::MAX_BACKUP_BYTES)return new WP_REST_Response(['code'=>'backup_size_limit_exceeded'],413);
        $payload['checksum_sha256']=hash('sha256',$canonical);
        $response=new WP_REST_Response($payload,200);
        $response->header('Cache-Control','no-store, private, max-age=0');
        $response->header('Pragma','no-cache');
        return $response;
    }

    public function validate_snapshot(WP_REST_Request $request): WP_REST_Response {
        $backup=$request->get_json_params();
        if(!is_array($backup)||($backup['format']??'')!=='PARSIAN_FINAL_BACKUP'||(int)($backup['format_version']??0)!==1||!is_array($backup['tables']??null)||!is_array($backup['excluded_tables']??null)||!is_string($backup['checksum_sha256']??null))
            return new WP_REST_Response(['valid'=>false,'code'=>'invalid_backup_format'],400);
        $checksum=strtolower($backup['checksum_sha256']);unset($backup['checksum_sha256']);
        $canonical=wp_json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($canonical)||strlen($canonical)>self::MAX_BACKUP_BYTES)return new WP_REST_Response(['valid'=>false,'code'=>'backup_size_limit_exceeded'],413);
        if(!hash_equals(hash('sha256',$canonical),$checksum))return new WP_REST_Response(['valid'=>false,'code'=>'backup_checksum_mismatch'],422);
        $expected=$this->tables();$provided=array_keys($backup['tables']);sort($expected);sort($provided);
        $expectedExcluded=['auth_tokens'=>['reason'=>'credential material is intentionally excluded; revoke and re-register devices after disaster recovery']];
        if($backup['excluded_tables']!==$expectedExcluded)return new WP_REST_Response(['valid'=>false,'code'=>'backup_exclusion_manifest_mismatch'],422);
        if($expected!==$provided)return new WP_REST_Response(['valid'=>false,'code'=>'backup_table_set_mismatch'],422);
        $totalRows=0;
        foreach($backup['tables'] as $name=>$rows){
            if(!is_array($rows)||count($rows)>self::MAX_ROWS_PER_TABLE)return new WP_REST_Response(['valid'=>false,'code'=>'backup_table_shape_invalid','table'=>$name],422);
            $totalRows+=count($rows);
            if($totalRows>self::MAX_BACKUP_ROWS_TOTAL)return new WP_REST_Response(['valid'=>false,'code'=>'backup_total_row_limit_exceeded','limit'=>self::MAX_BACKUP_ROWS_TOTAL],413);
            foreach($rows as $row)if(!is_array($row))return new WP_REST_Response(['valid'=>false,'code'=>'backup_row_invalid','table'=>$name],422);
        }
        return new WP_REST_Response(['valid'=>true,'restored'=>false,'format_version'=>1,'table_count'=>count($provided),'row_count'=>array_sum(array_map('count',$backup['tables'])),'message'=>'ساختار و checksum معتبر است؛ هیچ داده‌ای بازیابی یا تغییر داده نشد.'],200);
    }
}
