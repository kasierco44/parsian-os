<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bank account master, statement batch intake and allocation-based reconciliation.
 * All amounts are integer toman. Imported rows are normalized before persistence.
 */
final class Parsian_Final_Bank {
    public function register_routes(): void {
        $write = static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_write'); };
        $read = static function (): bool { return is_user_logged_in() && current_user_can('parsian_finance_read'); };
        register_rest_route('parsian-final/v1','/bank/accounts',[
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'list_accounts'],'permission_callback'=>$read],
            ['methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'save_account'],'permission_callback'=>$write],
        ]);
        register_rest_route('parsian-final/v1','/bank/accounts/(?P<id>\\d+)',[
            ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'get_account'],'permission_callback'=>$read],
            ['methods'=>['POST','PUT','PATCH'],'callback'=>[$this,'save_account'],'permission_callback'=>$write],
        ]);
        register_rest_route('parsian-final/v1','/bank/import',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'import_statement'],'permission_callback'=>$write,
        ]);
        register_rest_route('parsian-final/v1','/bank/import-batches/(?P<id>\\d+)',[
            'methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'get_batch'],'permission_callback'=>$read,
        ]);
        register_rest_route('parsian-final/v1','/bank/reconcile',[
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>[$this,'reconcile'],'permission_callback'=>$write,
        ]);
    }

    private function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix.'parsian_'.$name;
    }
    private function error(string $code,int $status,array $extra=[]): WP_REST_Response {
        return new WP_REST_Response(array_merge(['code'=>$code],$extra),$status);
    }
    private function valid_date($value): bool {
        if(!is_string($value)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$value)) return false;
        return checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4));
    }
    public function list_accounts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT b.id,b.account_ref,b.bank_name,b.account_title,b.account_number_masked,b.ledger_account_id,b.currency,b.opening_balance_toman,b.opening_balance_date,b.is_active,a.account_code,a.account_name FROM {$this->table('bank_accounts')} b LEFT JOIN {$this->table('ledger_accounts')} a ON a.id=b.ledger_account_id ORDER BY b.id DESC",ARRAY_A);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function get_account(WP_REST_Request $request): WP_REST_Response {
        global $wpdb; $id=absint($request['id']);
        $row=$wpdb->get_row($wpdb->prepare("SELECT b.*,a.account_code,a.account_name FROM {$this->table('bank_accounts')} b LEFT JOIN {$this->table('ledger_accounts')} a ON a.id=b.ledger_account_id WHERE b.id=%d",$id),ARRAY_A);
        return $row?new WP_REST_Response($row,200):$this->error('bank_account_not_found',404);
    }
    public function save_account(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $p=$request->get_json_params(); if(!is_array($p)) return $this->error('invalid_payload',400);
        $id=absint($request['id']??0);
        $ref=sanitize_text_field($p['account_ref']??'');
        $bank=sanitize_text_field($p['bank_name']??'');
        $title=sanitize_text_field($p['account_title']??'');
        $masked=sanitize_text_field($p['account_number_masked']??'');
        $ledger=absint($p['ledger_account_id']??0);
        $currency=sanitize_text_field($p['currency']??'IRR');
        $opening=filter_var($p['opening_balance_toman']??0,FILTER_VALIDATE_INT);
        $date=$p['opening_balance_date']??null;
        if($ref===''||strlen($ref)>80||$bank===''||$title===''||$ledger<1||!in_array($currency,['IRR','IRT'],true)||
            $opening===false||abs((int)$opening)>100000000000000||($date!==null&&$date!==''&&!$this->valid_date($date)))
            return $this->error('invalid_bank_account',400);
        $account=$wpdb->get_row($wpdb->prepare("SELECT id,account_code FROM {$this->table('ledger_accounts')} WHERE id=%d AND is_active=1",$ledger),ARRAY_A);
        if(!$account||!in_array((string)$account['account_code'],['1210'],true))
            return $this->error('active_bank_ledger_account_1210_required',422);
        $duplicate=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('bank_accounts')} WHERE account_ref=%s AND id<>%d",$ref,$id));
        if($duplicate) return $this->error('bank_account_ref_exists',409);
        $data=['account_ref'=>$ref,'bank_name'=>$bank,'account_title'=>$title,'account_number_masked'=>$masked,
            'ledger_account_id'=>$ledger,'currency'=>$currency,'opening_balance_toman'=>(int)$opening,
            'opening_balance_date'=>($date?:null),'is_active'=>array_key_exists('is_active',$p)?(int)(bool)$p['is_active']:1];
        if($id){
            $ok=$wpdb->update($this->table('bank_accounts'),$data,['id'=>$id]);
            if($ok===false)return $this->error('bank_account_update_failed',500);
            return new WP_REST_Response(['id'=>$id,'updated'=>true],200);
        }
        $data['created_by']=get_current_user_id(); $data['created_at']=current_time('mysql',true);
        if(!$wpdb->insert($this->table('bank_accounts'),$data)) return $this->error('bank_account_create_failed',500);
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'created'=>true],201);
    }

    private function parse_csv(string $path): array {
        $fh=fopen($path,'rb'); if(!$fh) return [[],[['row'=>0,'error'=>'file_unreadable']]];
        $first=fgets($fh); if($first===false){fclose($fh);return [[],[['row'=>0,'error'=>'empty_file']]];}
        $delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
        rewind($fh); $headers=fgetcsv($fh,0,$delimiter); $rows=[]; $errors=[]; $line=1;
        if(!is_array($headers)){fclose($fh);return [[],[['row'=>1,'error'=>'header_missing']]];}
        $headers=array_map(static fn($v)=>strtolower(trim((string)$v)), $headers);
        while(($values=fgetcsv($fh,0,$delimiter))!==false){$line++; if(count($values)!==count($headers)){$errors[]=['row'=>$line,'error'=>'column_count_mismatch'];continue;}
            $rows[]=array_combine($headers,$values);
        }
        fclose($fh); return [$rows,$errors];
    }
    private function parse_xlsx(string $path): array {
        if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string')) return [[],[['row'=>0,'error'=>'xlsx_runtime_support_missing']]];
        $zip=new ZipArchive(); if($zip->open($path)!==true) return [[],[['row'=>0,'error'=>'invalid_xlsx']]];
        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml'); $shared=$zip->getFromName('xl/sharedStrings.xml');
        if($sheet===false){$zip->close();return [[],[['row'=>0,'error'=>'xlsx_sheet_missing']]];}
        $strings=[];
        if($shared!==false){$sx=@simplexml_load_string($shared); if($sx){foreach($sx->si as $si){$strings[]=(string)($si->t??implode('',array_map('strval',(array)$si->r)));}}}
        $xml=@simplexml_load_string($sheet); $zip->close();
        if(!$xml)return [[],[['row'=>0,'error'=>'xlsx_xml_invalid']]];
        $matrix=[];
        foreach($xml->sheetData->row as $row){$line=[];foreach($row->c as $cell){
            $ref=(string)$cell['r'];preg_match('/^[A-Z]+/',$ref,$m);$letters=$m[0]??'A';$col=0;
            foreach(str_split($letters) as $ch)$col=$col*26+ord($ch)-64;$col--;
            $value=(string)$cell->v;
            if((string)$cell['t']==='s')$value=$strings[(int)$value]??'';
            elseif((string)$cell['t']==='inlineStr')$value=(string)($cell->is->t??'');
            $line[$col]=$value;
        } if($line){ksort($line);$matrix[]=array_values($line);}}
        if(!$matrix)return [[],[['row'=>0,'error'=>'xlsx_no_rows']]];
        $headers=array_map(static fn($v)=>strtolower(trim((string)$v)),array_shift($matrix));$rows=[];
        foreach($matrix as $i=>$values){if(count($values)!==count($headers))continue;$rows[]=array_combine($headers,$values);}
        return [$rows,[]];
    }
    private function normalize_row(array $r): array {
        $date=trim((string)($r['transaction_date']??$r['date']??''));
        $direction=strtolower(trim((string)($r['direction']??'')));
        $amount=filter_var(str_replace([',',' '],'',(string)($r['amount_toman']??$r['amount']??'')),FILTER_VALIDATE_INT);
        $external=trim((string)($r['external_line_id']??$r['transaction_id']??$r['id']??''));
        if(!$this->valid_date($date)||!in_array($direction,['in','out'],true)||$amount===false||$amount<1||$amount>1000000000000||$external==='')
            return ['error'=>'required_fields_invalid'];
        return ['external_line_id'=>substr(sanitize_text_field($external),0,120),'transaction_date'=>$date,'direction'=>$direction,
            'amount_toman'=>$amount,'reference'=>sanitize_text_field((string)($r['reference']??'')),
            'description'=>sanitize_text_field((string)($r['description']??''))];
    }
    public function import_statement(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $accountId=absint($request->get_param('bank_account_id')); $account=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('bank_accounts')} WHERE id=%d AND is_active=1",$accountId),ARRAY_A);
        if(!$account)return $this->error('active_bank_account_required',422);
        $files=$request->get_file_params(); $file=$files['file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))return $this->error('uploaded_file_required',400);
        $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION)); if(!in_array($ext,['csv','xlsx'],true))return $this->error('csv_or_xlsx_required',415);
        if((int)$file['size']>10*1024*1024)return $this->error('file_too_large',413);
        $hash=hash_file('sha256',$file['tmp_name']);
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('bank_import_batches')} WHERE bank_account_id=%d AND file_sha256=%s",$accountId,$hash));
        if($existing)return $this->error('file_already_imported',409,['batch_id'=>$existing]);
        [$rows,$parseErrors]=$ext==='csv'?$this->parse_csv($file['tmp_name']):$this->parse_xlsx($file['tmp_name']);
        $wpdb->insert($this->table('bank_import_batches'),['bank_account_id'=>$accountId,'source_name'=>sanitize_file_name($file['name']),
            'file_sha256'=>$hash,'row_count'=>count($rows)+count($parseErrors),'status'=>'processing','imported_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)]);
        $batchId=(int)$wpdb->insert_id; $accepted=0;$duplicates=0;$errors=$parseErrors;
        foreach($rows as $i=>$raw){$row=$this->normalize_row($raw);$rowNumber=$i+2;
            if(isset($row['error'])){$errors[]=['row'=>$rowNumber,'error'=>$row['error']];continue;}
            $dupe=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table('bank_statement_lines')} WHERE bank_account_id=%d AND external_line_id=%s",$accountId,$row['external_line_id']));
            if($dupe){$duplicates++;continue;}
            $ok=$wpdb->insert($this->table('bank_statement_lines'),array_merge($row,['bank_account_ref'=>$account['account_ref'],'bank_account_id'=>$accountId,
                'import_batch_id'=>$batchId,'match_status'=>'unmatched','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)]));
            if($ok)$accepted++;else $errors[]=['row'=>$rowNumber,'error'=>'database_insert_failed'];
        }
        $status=$errors?'completed_with_errors':'completed';
        $wpdb->update($this->table('bank_import_batches'),['row_count'=>count($rows)+count($parseErrors),'accepted_count'=>$accepted,
            'duplicate_count'=>$duplicates,'rejected_count'=>count($errors),'status'=>$status],['id'=>$batchId]);
        return new WP_REST_Response(['batch_id'=>$batchId,'status'=>$status,'row_count'=>count($rows)+count($parseErrors),
            'accepted_count'=>$accepted,'duplicate_count'=>$duplicates,'rejected_count'=>count($errors),'errors'=>$errors],201);
    }
    public function get_batch(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;$id=absint($request['id']);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('bank_import_batches')} WHERE id=%d",$id),ARRAY_A);
        return $row?new WP_REST_Response($row,200):$this->error('import_batch_not_found',404);
    }

    public function reconcile(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $p=$request->get_json_params();$allocations=$p['allocations']??null;
        if(!is_array($allocations)||count($allocations)<2||count($allocations)>200)return $this->error('allocations_array_required',400);
        $group=wp_generate_uuid4();$actor=get_current_user_id();$now=current_time('mysql',true);
        $wpdb->query('START TRANSACTION');
        $lineIds=[];$journalIds=[];$lineSums=[];$journalSums=[];
        foreach($allocations as $a){$line=absint($a['statement_line_id']??0);$journal=absint($a['journal_entry_id']??0);
            $amount=filter_var($a['amount_toman']??null,FILTER_VALIDATE_INT);
            if(!$line||!$journal||$amount===false||$amount<1){$wpdb->query('ROLLBACK');return $this->error('invalid_allocation',400);}
            $lineIds[$line]=$line;$journalIds[$journal]=$journal;$lineSums[$line]=($lineSums[$line]??0)+(int)$amount;
            $journalSums[$journal]=($journalSums[$journal]??0)+(int)$amount;
        }
        sort($lineIds);sort($journalIds);
        $lines=[];
        foreach($lineIds as $id){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('bank_statement_lines')} WHERE id=%d FOR UPDATE",$id),ARRAY_A);
            if(!$row){$wpdb->query('ROLLBACK');return $this->error('statement_line_not_found',404);}
            $already=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(matched_amount_toman),0) FROM {$this->table('bank_reconciliation_matches')} WHERE statement_line_id=%d",$id));
            if($already+$lineSums[$id]>(int)$row['amount_toman']){$wpdb->query('ROLLBACK');return $this->error('statement_line_overallocated',409,['statement_line_id'=>$id,'remaining_toman'=>(int)$row['amount_toman']-$already]);}
            $lines[$id]=$row;
        }
        foreach($journalIds as $id){$entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('journal_entries')} WHERE id=%d AND status='posted' FOR UPDATE",$id),ARRAY_A);
            if(!$entry){$wpdb->query('ROLLBACK');return $this->error('posted_journal_not_found',404);}
            $bankLines=$wpdb->get_results($wpdb->prepare("SELECT l.account_id,l.debit_toman,l.credit_toman,a.account_code FROM {$this->table('journal_lines')} l JOIN {$this->table('ledger_accounts')} a ON a.id=l.account_id WHERE l.journal_entry_id=%d AND a.account_code='1210'",$id),ARRAY_A);
            $capacity=0;$entryDirection='';
            foreach($bankLines as $bl){if((int)$bl['debit_toman']>0){$capacity+=(int)$bl['debit_toman'];$entryDirection='in';}if((int)$bl['credit_toman']>0){$capacity+=(int)$bl['credit_toman'];$entryDirection='out';}}
            $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(matched_amount_toman),0) FROM {$this->table('bank_reconciliation_matches')} WHERE journal_entry_id=%d",$id));
            if(!$capacity||$used+$journalSums[$id]>$capacity){$wpdb->query('ROLLBACK');return $this->error('journal_overallocated_or_not_bank_entry',409,['journal_entry_id'=>$id,'remaining_toman'=>$capacity-$used]);}
            foreach($lines as $line){if($line['direction']!==$entryDirection){$wpdb->query('ROLLBACK');return $this->error('transaction_direction_mismatch',409,['journal_entry_id'=>$id,'statement_line_id'=>(int)$line['id']]);}}
        }
        foreach($allocations as $a){$ok=$wpdb->insert($this->table('bank_reconciliation_matches'),[
            'statement_line_id'=>absint($a['statement_line_id']),'journal_entry_id'=>absint($a['journal_entry_id']),
            'matched_amount_toman'=>(int)$a['amount_toman'],'match_group_uuid'=>$group,'matched_by'=>$actor,'matched_at'=>$now
        ]);
            if(!$ok){$wpdb->query('ROLLBACK');return $this->error('duplicate_or_failed_allocation',409);}
        }
        foreach($lineIds as $id){$row=$lines[$id];$total=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(matched_amount_toman),0) FROM {$this->table('bank_reconciliation_matches')} WHERE statement_line_id=%d",$id));
            $status=$total===(int)$row['amount_toman']?'matched':'partial';
            $wpdb->update($this->table('bank_statement_lines'),['match_status'=>$status,'matched_by'=>$actor,'matched_at'=>$now,
                'matched_journal_entry_id'=>count($journalIds)===1?(int)$journalIds[0]:0],['id'=>$id]);
        }
        $wpdb->query('COMMIT');
        return new WP_REST_Response(['match_group_uuid'=>$group,'allocation_count'=>count($allocations),'statement_lines'=>$lineIds,'journal_entries'=>$journalIds,'status'=>'saved'],201);
    }
}
