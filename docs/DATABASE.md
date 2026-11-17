# اصول طراحی پایگاه داده

## فناوری پیشنهادی
PostgreSQL به‌عنوان پایگاه داده رابطه‌ای اصلی. انتخاب نهایی نسخه و نحوه میزبانی در مرحله زیرساخت ثبت می‌شود.

## قواعد طراحی
- شناسه داخلی پایدار برای هر رکورد؛ شناسه‌های قابل نمایش کسب‌وکار جداگانه.
- زمان‌ها در UTC ذخیره و در رابط با منطقه زمانی مناسب نمایش داده شوند.
- تاریخ شمسی فقط لایه نمایش/ورودی است؛ مقدار استاندارد تاریخ در پایگاه داده ذخیره شود.
- مبلغ با نوع decimal/numeric و واحد صریح ذخیره شود؛ استفاده از float برای پول ممنوع.
- برای اسناد مالی و گردش موجودی، حذف فیزیکی ممنوع یا به‌شدت محدود؛ ابطال با ثبت وضعیت و رویداد معکوس.
- ستون‌های `created_at`, `updated_at`, `created_by` و در صورت نیاز `version` برای کنترل هم‌زمانی.
- قیود foreign key، unique، check و index بر اساس الگوی واقعی دسترسی.
- migrationها نسخه‌بندی و قابل بازبینی باشند.

## دامنه‌های داده پیشنهادی
- identity: users, roles, permissions, user_roles, role_permissions, sessions.
- crm: leads, customers, contacts, territories, assignments, interactions, tasks.
- sales: quotations, quotation_items, orders, invoices, invoice_items, price_lists.
- finance: accounts, receipts, payments, checks, settlements, ledger_entries.
- inventory: products, warehouses, stock_movements, reservations, stock_counts.
- production: bills_of_materials, production_orders, material_consumptions, quality_checks.
- workforce: employees, sales_targets, commission_rules, commission_entries.
- platform: audit_events, attachments, notifications, integration_connections.

این فهرست مفهومی است و تا مرحله تحلیل فرایندها schema اجرایی محسوب نمی‌شود.

## قواعد تراکنش
- ثبت فاکتور، دریافت وجه و گردش موجودی باید در تراکنش‌های اتمیک انجام شوند.
- درخواست‌های حساس باید idempotency key داشته باشند تا تکرار درخواست، ثبت دوگانه نسازد.
- محاسبه مانده از رویدادهای معتبر و قواعد تعریف‌شده انجام شود؛ مقادیر تجمیعی cache شده منبع حقیقت نیستند.
- تغییرات هم‌زمان با optimistic locking یا قفل مناسب کنترل شوند.

## حریم خصوصی
دسترسی ردیفی/سازمانی در صورت نیاز، رمزگذاری در انتقال و ذخیره، سیاست نگهداری اسناد و ثبت دسترسی به داده حساس باید در طراحی تفصیلی مشخص شوند.
