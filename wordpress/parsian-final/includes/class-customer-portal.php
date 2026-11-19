<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_Customer_Portal {
    public function register_routes(): void {
        $permission = static function (): bool {
            $user = wp_get_current_user();
            return is_user_logged_in() && current_user_can('parsian_customer_portal_read')
                && in_array('parsian_customer', (array) $user->roles, true);
        };
        register_rest_route('parsian-final/v1', '/portal/overview', ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'overview'],'permission_callback'=>$permission]);
        register_rest_route('parsian-final/v1', '/portal/invoices', ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'invoices'],'permission_callback'=>$permission]);
        register_rest_route('parsian-final/v1', '/portal/proformas', ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'proformas'],'permission_callback'=>$permission]);
        register_rest_route('parsian-final/v1', '/portal/payments', ['methods'=>WP_REST_Server::READABLE,'callback'=>[$this,'payments'],'permission_callback'=>$permission]);
    }
    private function customer_id(): int {
        $id=absint(get_user_meta(get_current_user_id(),'parsian_customer_id',true));
        if($id<1)return 0;
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}parsian_customers WHERE id=%d AND status='active'",$id));
    }
    private function denied(): WP_REST_Response {
        return new WP_REST_Response(['code'=>'customer_account_not_linked','message'=>'حساب کاربری شما هنوز به پرونده مشتری متصل نشده است. با واحد فروش تماس بگیرید.'],403);
    }
    public function overview(WP_REST_Request $request): WP_REST_Response {
        $customerId=$this->customer_id(); if(!$customerId)return $this->denied();
        global $wpdb;
        $invoices=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS document_count,COALESCE(SUM(total_toman),0) AS total_toman FROM {$wpdb->prefix}parsian_invoices WHERE customer_id=%d AND status IN ('posted','fulfilled')",$customerId),ARRAY_A);
        $payments=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS payment_count,COALESCE(SUM(amount_toman),0) AS received_toman FROM {$wpdb->prefix}parsian_payments WHERE party_type='customer' AND party_id=%d AND direction='in' AND status='posted'",$customerId),ARRAY_A);
        $proformas=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}parsian_proformas WHERE customer_id=%d AND status IN ('draft','approved')",$customerId));
        return new WP_REST_Response(['customer_id'=>$customerId,'invoice_count'=>(int)($invoices['document_count']??0),'invoice_total_toman'=>(int)($invoices['total_toman']??0),'posted_payment_count'=>(int)($payments['payment_count']??0),'posted_received_toman'=>(int)($payments['received_toman']??0),'proforma_count'=>$proformas,'note'=>'جمع دریافت‌ها لزوماً معادل پرداختی تخصیص‌یافته به فاکتورها نیست.'],200);
    }
    public function invoices(WP_REST_Request $request): WP_REST_Response {
        $customerId=$this->customer_id(); if(!$customerId)return $this->denied();
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,invoice_number,total_toman,status,due_date,issued_at,COALESCE(updated_at,issued_at) AS updated_at,journal_entry_id,proforma_id,customer_id,issued_by FROM {$wpdb->prefix}parsian_invoices WHERE customer_id=%d ORDER BY issued_at DESC,id DESC LIMIT 100",$customerId),ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['revision'] = hash('sha256', wp_json_encode([
                'id'=>(int)$row['id'],'invoice_number'=>(string)$row['invoice_number'],
                'total_toman'=>(int)$row['total_toman'],'status'=>(string)$row['status'],
                'due_date'=>(string)($row['due_date']??''),'issued_at'=>(string)$row['issued_at'],
                'updated_at'=>(string)($row['updated_at']??''),'journal_entry_id'=>(int)$row['journal_entry_id'],'proforma_id'=>(int)$row['proforma_id'],'customer_id'=>(int)$row['customer_id'],'issued_by'=>(int)$row['issued_by']
            ], JSON_UNESCAPED_UNICODE));
            unset($row['proforma_id'], $row['customer_id'], $row['issued_by']);
        }
        unset($row);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function proformas(WP_REST_Request $request): WP_REST_Response {
        $customerId=$this->customer_id(); if(!$customerId)return $this->denied();
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,document_number,total_toman,status,due_date,created_at,updated_at,customer_id,notes,invoice_id,created_by FROM {$wpdb->prefix}parsian_proformas WHERE customer_id=%d ORDER BY created_at DESC,id DESC LIMIT 100",$customerId),ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['revision'] = hash('sha256', wp_json_encode([
                'id'=>(int)$row['id'],'document_number'=>(string)$row['document_number'],
                'total_toman'=>(int)$row['total_toman'],'status'=>(string)$row['status'],
                'due_date'=>(string)($row['due_date']??''),'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)($row['updated_at']??''),'customer_id'=>(int)$row['customer_id'],
                'notes'=>(string)($row['notes']??''),'invoice_id'=>(int)$row['invoice_id'],'created_by'=>(int)$row['created_by'],
                'items_revision'=>hash('sha256', wp_json_encode($wpdb->get_results($wpdb->prepare(
                    "SELECT product_id,description,quantity,unit_price_toman,line_total_toman FROM {$wpdb->prefix}parsian_proforma_items WHERE proforma_id=%d ORDER BY id",
                    (int)$row['id']
                ), ARRAY_A) ?: [], JSON_UNESCAPED_UNICODE))
            ], JSON_UNESCAPED_UNICODE));
            unset($row['customer_id'], $row['notes'], $row['invoice_id'], $row['created_by']);
        }
        unset($row);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
    public function payments(WP_REST_Request $request): WP_REST_Response {
        $customerId=$this->customer_id(); if(!$customerId)return $this->denied();
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,amount_toman,method,reference,status,created_at,COALESCE(updated_at,created_at) AS updated_at,direction,party_type,party_id,created_by,journal_entry_id FROM {$wpdb->prefix}parsian_payments WHERE party_type='customer' AND party_id=%d AND direction='in' ORDER BY created_at DESC,id DESC LIMIT 100",$customerId),ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['revision'] = hash('sha256', wp_json_encode([
                'id'=>(int)$row['id'],'amount_toman'=>(int)$row['amount_toman'],
                'method'=>(string)$row['method'],'reference'=>(string)$row['reference'],
                'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)($row['updated_at']??''),'direction'=>(string)$row['direction'],
                'party_type'=>(string)$row['party_type'],'party_id'=>(int)$row['party_id'],
                'created_by'=>(int)$row['created_by'],'journal_entry_id'=>(int)$row['journal_entry_id']
            ], JSON_UNESCAPED_UNICODE));
            unset($row['direction'], $row['party_type'], $row['party_id'], $row['created_by'], $row['journal_entry_id']);
        }
        unset($row);
        return new WP_REST_Response(['items'=>$rows?:[]],200);
    }
}
