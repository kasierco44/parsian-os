<?php
if (!defined('ABSPATH')) { exit; }
final class Parsian_Final_CRM {
    public function register_routes(): void {
        register_rest_route('parsian-final/v1', '/customers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'customers'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_read'); },
        ]);
        register_rest_route('parsian-final/v1', '/customers/(?P<id>\\d+)/assign-seller', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'assign_seller'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_manage_users'); },
        ]);
        register_rest_route('parsian-final/v1', '/customers/(?P<id>\\d+)/link-user', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'link_customer_user'],
            'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_manage_users'); },
        ]);
        register_rest_route('parsian-final/v1', '/leads', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'leads'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_read'); },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_lead'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_write'); },
                'args' => [
                    'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'phone' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'province' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'notes' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ],
        ]);
        register_rest_route('parsian-final/v1', '/tasks', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'tasks'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_crm_read'); },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_task'],
                'permission_callback' => static function (): bool { return is_user_logged_in() && current_user_can('parsian_manage_users'); },
            ],
        ]);

    }
    public function tasks(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_tasks';
        $limit = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        if (current_user_can('parsian_manage_users')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,title,description,assigned_user_id,status,due_at,completed_at,created_by,created_at
                 FROM {$table} ORDER BY id DESC LIMIT %d", $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,title,description,assigned_user_id,status,due_at,completed_at,created_by,created_at
                 FROM {$table} WHERE assigned_user_id=%d ORDER BY id DESC LIMIT %d",
                get_current_user_id(), $limit
            ), ARRAY_A);
        }
        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }
    public function create_task(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $title = trim(sanitize_text_field((string) $request->get_param('title')));
        $description = sanitize_textarea_field((string) $request->get_param('description'));
        $assigneeId = absint($request->get_param('assigned_user_id'));
        $assignee = $assigneeId ? get_user_by('id', $assigneeId) : false;
        $dueAt = $request->get_param('due_at');
        if ($title === '' || strlen($title) > 190 || !$assignee || !in_array('parsian_seller', (array) $assignee->roles, true)) {
            return new WP_REST_Response(['code' => 'invalid_task'], 400);
        }
        if ($dueAt !== null && $dueAt !== '') {
            $timestamp = is_string($dueAt) ? strtotime($dueAt) : false;
            if ($timestamp === false) return new WP_REST_Response(['code' => 'invalid_task_due_at'], 400);
            $dueAt = gmdate('Y-m-d H:i:s', $timestamp);
        } else {
            $dueAt = null;
        }
        $ok = $wpdb->insert($wpdb->prefix . 'parsian_tasks', [
            'title' => $title,
            'description' => $description,
            'assigned_user_id' => $assigneeId,
            'status' => 'open',
            'due_at' => $dueAt,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ], ['%s','%s','%d','%s','%s','%d','%s']);
        if (!$ok) return new WP_REST_Response(['code' => 'task_create_failed'], 500);
        $id = (int) $wpdb->insert_id;
        $eventOk = $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid' => wp_generate_uuid4(),
            'actor_user_id' => get_current_user_id(),
            'event_type' => 'crm.task.assigned',
            'aggregate_type' => 'task',
            'aggregate_id' => $id,
            'payload' => wp_json_encode(['task_id' => $id, 'assigned_user_id' => $assigneeId]),
            'created_at' => current_time('mysql', true),
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        if (!$eventOk) {
            $wpdb->delete($wpdb->prefix . 'parsian_tasks', ['id' => $id], ['%d']);
            return new WP_REST_Response(['code' => 'task_event_write_failed'], 500);
        }
        return new WP_REST_Response(['id' => $id, 'status' => 'open'], 201);
    }
    public function customers(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_customers';
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $offset = ($page - 1) * $per_page;
        if (current_user_can('parsian_manage_users') || current_user_can('parsian_finance_read')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, phone, province, city, address, credit_limit_toman, notes, status, created_at, COALESCE(updated_at,created_at) AS updated_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A);
        } else {
            // Enforce assignment in SQL before pagination; never fetch unassigned customers.
            $user_id = get_current_user_id();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, c.display_name, c.phone, c.province, c.city, c.address, c.credit_limit_toman, c.notes, c.status, c.created_at, COALESCE(c.updated_at,c.created_at) AS updated_at
                 FROM {$table} c
                 INNER JOIN {$wpdb->usermeta} um
                   ON um.user_id = %d
                  AND um.meta_key = CONCAT('parsian_customer_', c.id)
                  AND um.meta_value = '1'
                 ORDER BY c.id DESC LIMIT %d OFFSET %d",
                $user_id, $per_page, $offset
            ), ARRAY_A);
        }
        foreach ((array) $rows as &$row) {
            $revisionData = [
                'id'=>(int)$row['id'], 'display_name'=>(string)$row['display_name'],
                'phone'=>(string)$row['phone'], 'province'=>(string)$row['province'],
                'city'=>(string)$row['city'], 'address'=>(string)$row['address'],
                'credit_limit_toman'=>(int)$row['credit_limit_toman'],
                'notes'=>(string)$row['notes'], 'status'=>(string)$row['status'],
                'created_at'=>(string)$row['created_at'], 'updated_at'=>(string)($row['updated_at']??'')
            ];
            $row['revision'] = hash('sha256', wp_json_encode($revisionData, JSON_UNESCAPED_UNICODE));
            unset($row['phone'], $row['city'], $row['address'], $row['credit_limit_toman'], $row['notes'], $row['created_at']);
        }
        unset($row);
        return new WP_REST_Response(['items' => $rows ?: [], 'page' => $page, 'per_page' => $per_page], 200);
    }
    public function leads(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_leads';
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $offset = ($page - 1) * $per_page;
        if (current_user_can('parsian_manage_users')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, phone, province, notes, owner_user_id, status, created_at, updated_at
                 FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, display_name, phone, province, notes, owner_user_id, status, created_at, updated_at
                 FROM {$table} WHERE owner_user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                get_current_user_id(), $per_page, $offset
            ), ARRAY_A);
        }
        foreach ((array) $rows as &$row) {
            $row['revision'] = hash('sha256', wp_json_encode([
                'id' => (int) $row['id'],
                'display_name' => (string) ($row['display_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'province' => (string) ($row['province'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ], JSON_UNESCAPED_UNICODE));
        }
        unset($row);
        return new WP_REST_Response(['items' => $rows ?: [], 'page' => $page, 'per_page' => $per_page], 200);
    }
    public function create_lead(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'parsian_leads';
        $name = trim((string) $request->get_param('name'));
        $phone = trim((string) $request->get_param('phone'));
        if ($name === '' || strlen($name) > 190 || strlen($phone) > 40) {
            return new WP_REST_Response(['code' => 'invalid_lead'], 400);
        }
        $ok = $wpdb->insert($table, [
            'display_name' => $name,
            'phone' => $phone,
            'province' => (string) $request->get_param('province'),
            'notes' => (string) $request->get_param('notes'),
            'owner_user_id' => get_current_user_id(),
            'status' => 'new',
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { return new WP_REST_Response(['code'=>'lead_create_failed'], 500); }
        $id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid' => wp_generate_uuid4(),
            'actor_user_id' => get_current_user_id(),
            'event_type' => 'crm.lead.created',
            'aggregate_type' => 'lead',
            'aggregate_id' => $id,
            'payload' => wp_json_encode(['lead_id' => $id, 'owner_user_id' => get_current_user_id()]),
            'created_at' => current_time('mysql', true),
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['id' => $id, 'status' => 'new'], 201);
    }

    public function assign_seller(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $customerId = absint($request['id']);
        $sellerId = absint($request->get_param('seller_user_id'));
        $customer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}parsian_customers WHERE id=%d",
            $customerId
        ));
        $seller = $sellerId ? get_user_by('id', $sellerId) : false;
        if (!$customer || !$seller || !in_array('parsian_seller', (array) $seller->roles, true)) {
            return new WP_REST_Response(['code' => 'customer_or_seller_not_found'], 404);
        }
        $metaKey = 'parsian_customer_' . $customerId;
        $assign = rest_sanitize_boolean($request->get_param('assigned') ?? true);
        if ($assign) {
            update_user_meta($sellerId, $metaKey, '1');
        } else {
            delete_user_meta($sellerId, $metaKey);
        }
        $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid' => wp_generate_uuid4(),
            'actor_user_id' => get_current_user_id(),
            'event_type' => $assign ? 'crm.customer.seller_assigned' : 'crm.customer.seller_unassigned',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customerId,
            'payload' => wp_json_encode(['seller_user_id' => $sellerId]),
            'created_at' => current_time('mysql', true),
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['customer_id' => $customerId, 'seller_user_id' => $sellerId, 'assigned' => $assign], 200);
    }

    public function link_customer_user(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $customerId = absint($request['id']);
        $customerUserId = absint($request->get_param('customer_user_id'));
        $customer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}parsian_customers WHERE id=%d",
            $customerId
        ));
        $customerUser = $customerUserId ? get_user_by('id', $customerUserId) : false;
        if (!$customer || !$customerUser || !in_array('parsian_customer', (array) $customerUser->roles, true)) {
            return new WP_REST_Response(['code' => 'customer_or_user_not_found'], 404);
        }
        $existing = absint(get_user_meta($customerUserId, 'parsian_customer_id', true));
        if ($existing && $existing !== $customerId) {
            return new WP_REST_Response(['code' => 'customer_user_already_linked'], 409);
        }
        update_user_meta($customerUserId, 'parsian_customer_id', $customerId);
        $wpdb->insert($wpdb->prefix . 'parsian_events', [
            'event_uuid' => wp_generate_uuid4(),
            'actor_user_id' => get_current_user_id(),
            'event_type' => 'crm.customer.portal_linked',
            'aggregate_type' => 'customer',
            'aggregate_id' => $customerId,
            'payload' => wp_json_encode(['customer_user_id' => $customerUserId]),
            'created_at' => current_time('mysql', true),
        ], ['%s','%d','%s','%s','%d','%s','%s']);
        return new WP_REST_Response(['customer_id' => $customerId, 'customer_user_id' => $customerUserId, 'linked' => true], 200);
    }

    public function create_proforma(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['items']) || !is_array($body['items'])) {
            return new WP_REST_Response(['code'=>'invalid_items','message'=>'At least one line item is required.'], 400);
        }
        if (count($body['items']) > 100) { return new WP_REST_Response(['code'=>'too_many_items'], 400); }
        $clean = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || empty($item['product_id']) || !isset($item['quantity']) || !isset($item['unit_price'])) {
                return new WP_REST_Response(['code'=>'invalid_line_item'], 400);
            }
            $qty = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            $price = filter_var($item['unit_price'], FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1 || $qty > 1000000 || $price === false || $price < 0 || $price > 1000000000000) {
                return new WP_REST_Response(['code'=>'invalid_amount'], 400);
            }
            $clean[] = ['product_id'=>(int)$item['product_id'],'quantity'=>$qty,'unit_price'=>$price];
        }
        return new WP_REST_Response([
            'status'=>'draft_validation_passed',
            'approval_required'=>true,
            'items'=>$clean,
            'message'=>'Validated draft only; no accounting or inventory posting has occurred.'
        ], 202);
    }
}
