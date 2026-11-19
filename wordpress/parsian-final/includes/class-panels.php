<?php
if (!defined('ABSPATH')) { exit; }

final class Parsian_Final_Panels {
    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void {
        add_menu_page(
            'PARSIAN OS',
            'پارسیان OS',
            'parsian_access_core',
            'parsian-final',
            [$this, 'render_page'],
            'dashicons-chart-area',
            3
        );
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_parsian-final') { return; }
        wp_enqueue_style(
            'parsian-final-panels',
            PARSIAN_FINAL_URL . 'assets/panels/styles.css',
            [],
            PARSIAN_FINAL_VERSION
        );
        wp_enqueue_script(
            'parsian-final-panels',
            PARSIAN_FINAL_URL . 'assets/panels/app.js',
            [],
            PARSIAN_FINAL_VERSION,
            true
        );
        wp_localize_script('parsian-final-panels', 'PARSIAN_CONFIG', [
            'apiRoot' => esc_url_raw(rest_url('parsian-final/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render_page(): void {
        if (!current_user_can('parsian_access_core')) {
            wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.', 'parsian-final'));
        }
        echo '<div class="wrap parsian-final-admin">';
        echo '<div class="shell">';
        echo '<aside><h1>پارسیان OS</h1><nav id="navigation" class="navigation"></nav><small id="api-status">در حال اتصال به هسته مرکزی...</small></aside>';
        echo '<main><header><span id="role-chip">در حال شناسایی نقش</span><h2 id="page-title">داشبورد</h2><p id="user-name">کاربر</p></header>';
        echo '<section class="hero"><h2 id="welcome-title">خوش آمدید</h2><p id="welcome-copy">اطلاعات پس از احراز هویت نمایش داده می‌شوند.</p></section>';
        echo '<p id="notice" class="notice" role="status" aria-live="polite">در حال بررسی سامانه...</p>';
        echo '<section id="module-grid" class="grid"></section><section id="workspace-view" class="workspace-view" aria-live="polite"></section>';
        echo '<p id="health-detail" class="muted">در انتظار پاسخ هسته مرکزی</p>';
        echo '</main></div></div>';
    }
}
