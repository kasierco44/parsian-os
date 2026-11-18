<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Role-aware WordPress dashboard shell for the first PARSIAN FINAL milestone.
 * Operational cards are intentionally marked as planned until their modules ship.
 */
final class Parsian_Final_Admin
{
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    public static function register_menu()
    {
        $capability = null;
        if (current_user_can('parsian_manage_system')) {
            $capability = 'parsian_manage_system';
        } elseif (current_user_can('parsian_view_finance')) {
            $capability = 'parsian_view_finance';
        } elseif (current_user_can('parsian_use_crm')) {
            $capability = 'parsian_use_crm';
        }

        if (!$capability) {
            return;
        }

        add_menu_page(
            'PARSIAN FINAL',
            'پارسیان فاینال',
            $capability,
            'parsian-final',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-chart-area',
            3
        );
    }

    public static function enqueue_styles($hook)
    {
        if ('toplevel_page_parsian-final' !== $hook) {
            return;
        }

        wp_register_style('parsian-final-admin', false, array(), PARSian_FINAL_VERSION);
        wp_enqueue_style('parsian-final-admin');
        wp_add_inline_style('parsian-final-admin', '
            .parsian-final-wrap{max-width:1180px;margin:24px 20px 0 0;direction:rtl}
            .parsian-final-hero{background:#171717;color:#fff;border-right:6px solid #d9272e;padding:26px 30px;border-radius:12px}
            .parsian-final-hero h1{color:#fff;font-size:27px;margin:0 0 8px}
            .parsian-final-hero p{color:#ddd;margin:0;font-size:14px}
            .parsian-final-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:20px}
            .parsian-final-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.035)}
            .parsian-final-card h2{font-size:16px;margin:0 0 10px;color:#222}
            .parsian-final-card p{color:#666;line-height:1.9;margin:0}
            .parsian-final-state{display:inline-block;margin-top:14px;padding:4px 10px;border-radius:20px;background:#f1f1f1;color:#555;font-size:12px}
            .parsian-final-note{margin-top:18px;padding:14px 18px;background:#fff8e8;border:1px solid #f1dfb5;border-radius:8px;color:#654d16}
        ');
    }

    public static function render_dashboard()
    {
        if (!current_user_can('parsian_manage_system') &&
            !current_user_can('parsian_view_finance') &&
            !current_user_can('parsian_use_crm')) {
            wp_die(esc_html__('شما مجوز مشاهده این بخش را ندارید.', 'parsian-final'));
        }

        $user = wp_get_current_user();
        $modules = self::modules_for_user();
        ?>
        <div class="wrap parsian-final-wrap">
            <section class="parsian-final-hero">
                <h1>PARSIAN FINAL</h1>
                <p>سامانه یکپارچه مدیریت پارسیان قطعه | خوش آمدید، <?php echo esc_html($user->display_name); ?></p>
            </section>

            <div class="parsian-final-grid">
                <?php foreach ($modules as $module) : ?>
                    <article class="parsian-final-card">
                        <h2><?php echo esc_html($module['title']); ?></h2>
                        <p><?php echo esc_html($module['description']); ?></p>
                        <span class="parsian-final-state"><?php echo esc_html($module['state']); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="parsian-final-note">
                این صفحه پوسته اولیه پنل است. ماژول‌هایی که «در حال توسعه» هستند هنوز عملیات واقعی انجام نمی‌دهند.
                اطلاعات نمونه یا ساختگی در این داشبورد نمایش داده نمی‌شود.
            </div>
        </div>
        <?php
    }

    private static function modules_for_user()
    {
        if (current_user_can('parsian_manage_system')) {
            return array(
                array('title' => 'داشبورد مدیریت', 'description' => 'نمای کلی سامانه، اهداف فروش و کنترل دسترسی‌ها.', 'state' => 'هسته اولیه فعال'),
                array('title' => 'فروش و CRM', 'description' => 'مشتریان، سرنخ‌ها، پیگیری‌ها و پیش‌فاکتورهای چندقلمی.', 'state' => 'در حال توسعه'),
                array('title' => 'حسابداری و خزانه', 'description' => 'دریافت‌ها، پرداخت‌ها، چک‌ها و مانده تأمین‌کنندگان.', 'state' => 'در حال توسعه'),
                array('title' => 'انبار و کالا', 'description' => 'کالاها، گردش موجودی و ارتباط با اسناد فروش.', 'state' => 'در حال توسعه'),
                array('title' => 'گزارش و ممیزی', 'description' => 'گزارش عملکرد، تاریخچه رویدادها و سوابق تغییرات.', 'state' => 'زیرساخت اولیه'),
                array('title' => 'همگام‌سازی آفلاین', 'description' => 'صف عملیات دستگاه‌ها و ثبت وضعیت پذیرش سرور.', 'state' => 'زیرساخت اولیه'),
            );
        }

        if (current_user_can('parsian_view_finance')) {
            return array(
                array('title' => 'حسابداری', 'description' => 'دریافت‌ها، پرداخت‌ها، چک‌ها و دفاتر مالی.', 'state' => 'در حال توسعه'),
                array('title' => 'انبار', 'description' => 'کالاها و گردش موجودی با دسترسی حسابدار.', 'state' => 'در حال توسعه'),
                array('title' => 'تأمین‌کنندگان', 'description' => 'مانده حساب و سررسیدهای پرداخت.', 'state' => 'در حال توسعه'),
            );
        }

        return array(
            array('title' => 'CRM فروش', 'description' => 'مشتریان تخصیص‌یافته، سرنخ‌ها و پیگیری‌های فروش.', 'state' => 'در حال توسعه'),
            array('title' => 'پیش‌فاکتورها', 'description' => 'ثبت پیش‌فاکتور و ارسال برای تأیید مدیر.', 'state' => 'در حال توسعه'),
            array('title' => 'کارهای روزانه', 'description' => 'چک‌لیست تماس‌ها و پیگیری‌های فروش.', 'state' => 'در حال توسعه'),
        );
    }
}
