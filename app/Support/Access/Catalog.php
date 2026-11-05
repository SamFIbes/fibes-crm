<?php

namespace App\Support\Access;

/**
 * Seed data for roles, teams, and the default permission grants.
 * A missing cell means deny. Scopes are own, team, or all.
 */
class Catalog
{
    /**
     * @return array<string, array{name_en: string, name_ar: string, locked?: bool}>
     */
    public static function roles(): array
    {
        return [
            'super_admin' => ['name_en' => 'Super Admin', 'name_ar' => 'مدير النظام', 'locked' => true],
            'management' => ['name_en' => 'Management', 'name_ar' => 'الإدارة'],
            'sales_manager' => ['name_en' => 'Sales Manager', 'name_ar' => 'مدير المبيعات'],
            'sales_agent' => ['name_en' => 'Sales Agent', 'name_ar' => 'مندوب مبيعات'],
            'cc_supervisor' => ['name_en' => 'Call Center Supervisor', 'name_ar' => 'مشرف مركز الاتصال'],
            'cc_agent' => ['name_en' => 'Call Center Agent', 'name_ar' => 'موظف مركز الاتصال'],
            'ops_manager' => ['name_en' => 'Operations Manager', 'name_ar' => 'مدير العمليات'],
            'noc_engineer' => ['name_en' => 'NOC Engineer', 'name_ar' => 'مهندس الشبكة'],
            'field_technician' => ['name_en' => 'Field Technician', 'name_ar' => 'فني ميداني'],
            'installer' => ['name_en' => 'Installer', 'name_ar' => 'فني تركيب'],
            'warehouse' => ['name_en' => 'Warehouse', 'name_ar' => 'المستودع'],
            'finance' => ['name_en' => 'Finance', 'name_ar' => 'المالية'],
            'support_agent' => ['name_en' => 'Support Agent', 'name_ar' => 'الدعم الفني'],
            'auditor' => ['name_en' => 'Auditor', 'name_ar' => 'مدقق'],
        ];
    }

    /**
     * @return array<string, array{name_en: string, name_ar: string}>
     */
    public static function teams(): array
    {
        return [
            'call_center' => ['name_en' => 'Call center', 'name_ar' => 'مركز الاتصال'],
            'sales' => ['name_en' => 'Sales', 'name_ar' => 'المبيعات'],
            'support_l1' => ['name_en' => 'Support L1', 'name_ar' => 'الدعم المستوى الأول'],
            'noc_l2' => ['name_en' => 'NOC L2', 'name_ar' => 'هندسة الشبكة'],
            'field_l3' => ['name_en' => 'Field L3', 'name_ar' => 'الميدان'],
            'installers' => ['name_en' => 'Installers', 'name_ar' => 'التركيب'],
            'warehouse' => ['name_en' => 'Warehouse', 'name_ar' => 'المستودع'],
            'finance' => ['name_en' => 'Finance', 'name_ar' => 'المالية'],
            'management' => ['name_en' => 'Management', 'name_ar' => 'الإدارة'],
        ];
    }

    /**
     * @return array<string, array{module: string, label_en: string, label_ar: string}>
     */
    public static function permissions(): array
    {
        return [
            'leads.view' => ['module' => 'leads', 'label_en' => 'View leads', 'label_ar' => 'عرض العملاء المحتملين'],
            'leads.create' => ['module' => 'leads', 'label_en' => 'Create leads', 'label_ar' => 'إنشاء عملاء محتملين'],
            'leads.edit' => ['module' => 'leads', 'label_en' => 'Edit leads', 'label_ar' => 'تعديل العملاء المحتملين'],
            'leads.assign' => ['module' => 'leads', 'label_en' => 'Assign leads', 'label_ar' => 'إسناد العملاء المحتملين'],
            'leads.claim' => ['module' => 'leads', 'label_en' => 'Claim a lead from the queue', 'label_ar' => 'أخذ عميل محتمل من قائمة الانتظار'],
            'opportunities.view' => ['module' => 'opportunities', 'label_en' => 'View opportunities', 'label_ar' => 'عرض الفرص'],
            'opportunities.view_won' => ['module' => 'opportunities', 'label_en' => 'View won opportunities', 'label_ar' => 'عرض الفرص الرابحة'],
            'opportunities.edit' => ['module' => 'opportunities', 'label_en' => 'Edit opportunities', 'label_ar' => 'تعديل الفرص'],
            'opportunities.close' => ['module' => 'opportunities', 'label_en' => 'Mark won or lost', 'label_ar' => 'تحديد ربح أو خسارة'],
            'coverage.check' => ['module' => 'coverage', 'label_en' => 'Run a coverage check', 'label_ar' => 'فحص التغطية'],
            'coverage.view' => ['module' => 'coverage', 'label_en' => 'View coverage results', 'label_ar' => 'عرض نتائج التغطية'],
            'coverage.edit' => ['module' => 'coverage', 'label_en' => 'Import and edit coverage', 'label_ar' => 'استيراد التغطية وتعديلها'],
            'quotes.create' => ['module' => 'quotes', 'label_en' => 'Create quotes', 'label_ar' => 'إنشاء عروض أسعار'],
            'quotes.discount' => ['module' => 'quotes', 'label_en' => 'Discount within own limit', 'label_ar' => 'خصم ضمن الحد المسموح'],
            'quotes.approve' => ['module' => 'quotes', 'label_en' => 'Approve discounts', 'label_ar' => 'الموافقة على الخصومات'],
            'contracts.manage' => ['module' => 'quotes', 'label_en' => 'Generate and sign contracts', 'label_ar' => 'إنشاء العقود وتوقيعها'],
            'contracts.view' => ['module' => 'quotes', 'label_en' => 'View contracts', 'label_ar' => 'عرض العقود'],
            'customers.view' => ['module' => 'customers', 'label_en' => 'View customers', 'label_ar' => 'عرض العملاء'],
            'customers.view_pii' => ['module' => 'customers', 'label_en' => 'View phone, email, and address', 'label_ar' => 'عرض الهاتف والبريد والعنوان'],
            'customers.create' => ['module' => 'customers', 'label_en' => 'Create customers', 'label_ar' => 'إنشاء عملاء'],
            'customers.edit' => ['module' => 'customers', 'label_en' => 'Edit customers', 'label_ar' => 'تعديل العملاء'],
            'customers.edit_address' => ['module' => 'customers', 'label_en' => 'Edit customer address', 'label_ar' => 'تعديل عنوان العميل'],
            'services.technical.edit' => ['module' => 'services', 'label_en' => 'Edit technical service fields', 'label_ar' => 'تعديل الحقول التقنية للخدمة'],
            'services.technical.view' => ['module' => 'services', 'label_en' => 'View technical service fields', 'label_ar' => 'عرض الحقول التقنية للخدمة'],
            'services.radius_fields.edit' => ['module' => 'services', 'label_en' => 'Edit RADIUS fields', 'label_ar' => 'تعديل حقول RADIUS'],
            'services.radius_fields.view' => ['module' => 'services', 'label_en' => 'View RADIUS fields', 'label_ar' => 'عرض حقول RADIUS'],
            'services.radius_password.reveal' => ['module' => 'services', 'label_en' => 'Reveal a RADIUS password', 'label_ar' => 'إظهار كلمة مرور RADIUS'],
            'services.radius_status.edit' => ['module' => 'services', 'label_en' => 'Edit RADIUS expiry and status', 'label_ar' => 'تعديل صلاحية RADIUS وحالتها'],
            'orders.create' => ['module' => 'orders', 'label_en' => 'Create orders', 'label_ar' => 'إنشاء الطلبات'],
            'orders.view' => ['module' => 'orders', 'label_en' => 'View orders', 'label_ar' => 'عرض الطلبات'],
            'orders.activate' => ['module' => 'orders', 'label_en' => 'Activate a service', 'label_ar' => 'تفعيل خدمة'],
            'purchasing.create' => ['module' => 'orders', 'label_en' => 'Create a purchase order', 'label_ar' => 'إنشاء أمر شراء'],
            'purchasing.receive' => ['module' => 'orders', 'label_en' => 'Receive a purchase', 'label_ar' => 'استلام مشتريات'],
            'jobs.view' => ['module' => 'jobs', 'label_en' => 'View jobs', 'label_ar' => 'عرض المهام'],
            'jobs.schedule' => ['module' => 'jobs', 'label_en' => 'Schedule and dispatch jobs', 'label_ar' => 'جدولة المهام وإرسالها'],
            'jobs.request' => ['module' => 'jobs', 'label_en' => 'Request a job', 'label_ar' => 'طلب مهمة'],
            'jobs.complete' => ['module' => 'jobs', 'label_en' => 'Complete a job', 'label_ar' => 'إكمال مهمة'],
            'tasks.view' => ['module' => 'tasks', 'label_en' => 'View tasks', 'label_ar' => 'عرض المهام'],
            'tasks.create' => ['module' => 'tasks', 'label_en' => 'Create tasks', 'label_ar' => 'إنشاء مهام'],
            'tasks.edit' => ['module' => 'tasks', 'label_en' => 'Update a task', 'label_ar' => 'تعديل مهمة'],
            'tasks.assign' => ['module' => 'tasks', 'label_en' => 'Assign a task', 'label_ar' => 'إسناد مهمة'],
            'inventory.view' => ['module' => 'inventory', 'label_en' => 'View inventory', 'label_ar' => 'عرض المخزون'],
            'inventory.move' => ['module' => 'inventory', 'label_en' => 'Receive and transfer stock', 'label_ar' => 'استلام المخزون ونقله'],
            'inventory.adjust' => ['module' => 'inventory', 'label_en' => 'Approve a stock adjustment', 'label_ar' => 'الموافقة على تسوية المخزون'],
            'inventory.reserve' => ['module' => 'inventory', 'label_en' => 'Reserve equipment', 'label_ar' => 'حجز المعدات'],
            'tickets.view' => ['module' => 'tickets', 'label_en' => 'View tickets', 'label_ar' => 'عرض التذاكر'],
            'tickets.create' => ['module' => 'tickets', 'label_en' => 'Create tickets', 'label_ar' => 'إنشاء تذاكر'],
            'tickets.assign' => ['module' => 'tickets', 'label_en' => 'Assign tickets', 'label_ar' => 'إسناد التذاكر'],
            'tickets.claim' => ['module' => 'tickets', 'label_en' => 'Claim a ticket', 'label_ar' => 'أخذ تذكرة'],
            'tickets.escalate' => ['module' => 'tickets', 'label_en' => 'Escalate a ticket', 'label_ar' => 'تصعيد تذكرة'],
            'tickets.override_sla' => ['module' => 'tickets', 'label_en' => 'Override SLA or priority', 'label_ar' => 'تجاوز اتفاقية الخدمة أو الأولوية'],
            'tickets.request_sla' => ['module' => 'tickets', 'label_en' => 'Request an SLA change', 'label_ar' => 'طلب تغيير اتفاقية الخدمة'],
            'incidents.manage' => ['module' => 'tickets', 'label_en' => 'Open or close a mass incident', 'label_ar' => 'فتح عطل جماعي أو إغلاقه'],
            'calls.log' => ['module' => 'calls', 'label_en' => 'Log calls and dispositions', 'label_ar' => 'تسجيل المكالمات ونتائجها'],
            'calls.view' => ['module' => 'calls', 'label_en' => 'View call logs', 'label_ar' => 'عرض سجل المكالمات'],
            'qa.score' => ['module' => 'calls', 'label_en' => 'Score calls', 'label_ar' => 'تقييم المكالمات'],
            'qa.view' => ['module' => 'calls', 'label_en' => 'View call scores', 'label_ar' => 'عرض تقييم المكالمات'],
            'invoices.view' => ['module' => 'billing', 'label_en' => 'View invoices', 'label_ar' => 'عرض الفواتير'],
            'invoices.view_balance' => ['module' => 'billing', 'label_en' => 'View a balance only', 'label_ar' => 'عرض الرصيد فقط'],
            'invoices.issue' => ['module' => 'billing', 'label_en' => 'Issue invoices and credit notes', 'label_ar' => 'إصدار الفواتير والإشعارات الدائنة'],
            'payments.record' => ['module' => 'billing', 'label_en' => 'Record a payment', 'label_ar' => 'تسجيل دفعة'],
            'payments.collect' => ['module' => 'billing', 'label_en' => 'Collect cash on a job', 'label_ar' => 'تحصيل نقدي في المهمة'],
            'payments.request' => ['module' => 'billing', 'label_en' => 'Request a payment recording', 'label_ar' => 'طلب تسجيل دفعة'],
            'payments.reverse' => ['module' => 'billing', 'label_en' => 'Reverse or refund a payment', 'label_ar' => 'عكس دفعة أو ردها'],
            'payments.write_off' => ['module' => 'billing', 'label_en' => 'Write off a balance', 'label_ar' => 'شطب رصيد'],
            'billing.view_amounts' => ['module' => 'billing', 'label_en' => 'See money amounts', 'label_ar' => 'رؤية المبالغ'],
            'billing.view_cost' => ['module' => 'billing', 'label_en' => 'See cost and margin', 'label_ar' => 'رؤية التكلفة والهامش'],
            'catalog.view' => ['module' => 'catalog', 'label_en' => 'View plans and list prices', 'label_ar' => 'عرض الباقات وأسعار اللائحة'],
            'catalog.edit' => ['module' => 'catalog', 'label_en' => 'Edit the catalog', 'label_ar' => 'تعديل دليل الأسعار'],
            'catalog.prices.request' => ['module' => 'catalog', 'label_en' => 'Request a price change', 'label_ar' => 'طلب تغيير سعر'],
            'catalog.prices.edit' => ['module' => 'catalog', 'label_en' => 'Publish a price', 'label_ar' => 'نشر سعر'],
            'catalog.promotions.edit' => ['module' => 'catalog', 'label_en' => 'Edit promotions', 'label_ar' => 'تعديل العروض'],
            'approvals.view' => ['module' => 'approvals', 'label_en' => 'View the approval inbox', 'label_ar' => 'عرض صندوق الموافقات'],
            'approvals.request' => ['module' => 'approvals', 'label_en' => 'Ask for an approval', 'label_ar' => 'طلب موافقة'],
            'approvals.decide' => ['module' => 'approvals', 'label_en' => 'Approve or reject', 'label_ar' => 'الموافقة أو الرفض'],
            'approvals.manage' => ['module' => 'approvals', 'label_en' => 'Edit approval rules', 'label_ar' => 'تعديل قواعد الموافقة'],
            'services.suspend' => ['module' => 'services', 'label_en' => 'Suspend or reactivate a service', 'label_ar' => 'إيقاف خدمة أو إعادة تفعيلها'],
            'services.request_suspend' => ['module' => 'services', 'label_en' => 'Request a suspension', 'label_ar' => 'طلب إيقاف خدمة'],
            'retention.offer' => ['module' => 'billing', 'label_en' => 'Give a retention offer', 'label_ar' => 'تقديم عرض إبقاء'],
            'retention.request' => ['module' => 'billing', 'label_en' => 'Request a retention offer', 'label_ar' => 'طلب عرض إبقاء'],
            'commissions.view' => ['module' => 'performance', 'label_en' => 'View commissions', 'label_ar' => 'عرض العمولات'],
            'targets.set' => ['module' => 'performance', 'label_en' => 'Set targets', 'label_ar' => 'تحديد الأهداف'],
            'reports.view' => ['module' => 'reports', 'label_en' => 'View reports', 'label_ar' => 'عرض التقارير'],
            'automations.view' => ['module' => 'settings', 'label_en' => 'View automations', 'label_ar' => 'عرض القواعد'],
            'automations.manage' => ['module' => 'settings', 'label_en' => 'Manage automations', 'label_ar' => 'إدارة القواعد'],
            'imports.manage' => ['module' => 'settings', 'label_en' => 'Import records', 'label_ar' => 'استيراد السجلات'],
            'backups.manage' => ['module' => 'settings', 'label_en' => 'Run backups', 'label_ar' => 'تشغيل النسخ الاحتياطي'],
            'reports.export' => ['module' => 'reports', 'label_en' => 'Export data', 'label_ar' => 'تصدير البيانات'],
            'audit.view' => ['module' => 'audit', 'label_en' => 'View the audit log', 'label_ar' => 'عرض سجل التدقيق'],
            'audit.export' => ['module' => 'audit', 'label_en' => 'Export the audit log', 'label_ar' => 'تصدير سجل التدقيق'],
            'settings.view' => ['module' => 'settings', 'label_en' => 'View settings', 'label_ar' => 'عرض الإعدادات'],
            'settings.manage' => ['module' => 'settings', 'label_en' => 'Change settings', 'label_ar' => 'تعديل الإعدادات'],
            'integrations.view' => ['module' => 'settings', 'label_en' => 'View integrations', 'label_ar' => 'عرض التكاملات'],
            'integrations.manage' => ['module' => 'settings', 'label_en' => 'Change integrations', 'label_ar' => 'تعديل التكاملات'],
            'geography.view' => ['module' => 'geography', 'label_en' => 'View areas', 'label_ar' => 'عرض المناطق'],
            'geography.manage' => ['module' => 'geography', 'label_en' => 'Manage areas', 'label_ar' => 'إدارة المناطق'],
            'rates.view' => ['module' => 'billing', 'label_en' => 'View exchange rates', 'label_ar' => 'عرض أسعار الصرف'],
            'rates.manage' => ['module' => 'billing', 'label_en' => 'Record an exchange rate', 'label_ar' => 'تسجيل سعر صرف'],
            'attachments.view' => ['module' => 'files', 'label_en' => 'View files', 'label_ar' => 'عرض الملفات'],
            'attachments.upload' => ['module' => 'files', 'label_en' => 'Upload files', 'label_ar' => 'رفع الملفات'],
            'attachments.delete' => ['module' => 'files', 'label_en' => 'Delete files', 'label_ar' => 'حذف الملفات'],
            'users.view' => ['module' => 'users', 'label_en' => 'View users and roles', 'label_ar' => 'عرض المستخدمين والأدوار'],
            'users.manage' => ['module' => 'users', 'label_en' => 'Manage users and roles', 'label_ar' => 'إدارة المستخدمين والأدوار'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function grants(): array
    {
        $all = static fn (string ...$roles): array => array_fill_keys($roles, 'all');
        $own = static fn (string ...$roles): array => array_fill_keys($roles, 'own');
        $team = static fn (string ...$roles): array => array_fill_keys($roles, 'team');

        return [
            'leads.view' => [
                ...$all('super_admin', 'management', 'sales_manager', 'cc_supervisor', 'support_agent', 'auditor'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'leads.create' => [
                ...$all('super_admin', 'sales_manager', 'cc_supervisor', 'support_agent'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'leads.edit' => [
                ...$all('super_admin', 'sales_manager', 'cc_supervisor'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'leads.assign' => $all('super_admin', 'sales_manager', 'cc_supervisor'),
            'leads.claim' => $own('sales_agent', 'cc_agent'),
            'opportunities.view' => [
                ...$all('super_admin', 'management', 'sales_manager', 'auditor'),
                ...$team('cc_supervisor'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'opportunities.view_won' => $all('ops_manager', 'finance'),
            'opportunities.edit' => [
                ...$all('super_admin', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'opportunities.close' => [
                ...$all('super_admin', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'coverage.check' => $all('super_admin', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'support_agent'),
            'coverage.view' => $all('management', 'field_technician', 'installer', 'auditor'),
            'coverage.edit' => $all('super_admin', 'ops_manager', 'noc_engineer'),
            'quotes.create' => [
                ...$all('super_admin', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'quotes.discount' => [
                ...$all('super_admin', 'management', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'quotes.approve' => $all('super_admin', 'management', 'sales_manager'),
            'contracts.manage' => [
                ...$all('super_admin', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'contracts.view' => $all('auditor'),
            'customers.view' => [
                ...$all('super_admin', 'management', 'sales_manager', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'finance', 'support_agent'),
                ...$own('sales_agent', 'field_technician', 'installer'),
            ],
            'customers.view_pii' => [
                ...$all('super_admin', 'management', 'sales_manager', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'finance', 'support_agent'),
                ...$own('sales_agent', 'field_technician', 'installer'),
            ],
            'customers.create' => $all('super_admin', 'sales_manager', 'cc_supervisor', 'cc_agent', 'support_agent'),
            'customers.edit' => [
                ...$all('super_admin', 'sales_manager', 'cc_supervisor', 'cc_agent', 'support_agent'),
                ...$own('sales_agent'),
            ],
            'customers.edit_address' => $all('ops_manager'),
            'services.technical.edit' => $all('super_admin', 'ops_manager', 'noc_engineer'),
            'services.technical.view' => [
                ...$all('cc_supervisor', 'cc_agent', 'finance', 'support_agent', 'auditor'),
                ...$own('field_technician', 'installer'),
            ],
            'services.radius_fields.edit' => [
                ...$all('super_admin', 'noc_engineer'),
                ...$own('field_technician', 'installer'),
            ],
            'services.radius_fields.view' => $all('cc_supervisor', 'cc_agent', 'ops_manager', 'support_agent', 'auditor'),
            'services.radius_password.reveal' => $all('super_admin', 'noc_engineer'),
            'services.radius_status.edit' => $all('finance'),
            'orders.create' => [
                ...$all('super_admin', 'sales_manager'),
                ...$own('sales_agent'),
            ],
            'orders.view' => [
                ...$all('super_admin', 'sales_manager', 'ops_manager', 'management', 'noc_engineer', 'auditor'),
                ...$own('sales_agent'),
            ],
            'orders.activate' => [
                ...$all('super_admin', 'noc_engineer', 'ops_manager'),
                ...$own('installer', 'field_technician'),
            ],
            'purchasing.create' => $all('super_admin', 'ops_manager', 'warehouse'),
            'purchasing.receive' => $all('super_admin', 'warehouse'),
            'jobs.view' => [
                ...$all('super_admin', 'management', 'auditor'),
                ...$own('field_technician', 'installer'),
            ],
            'jobs.schedule' => $all('super_admin', 'ops_manager'),
            'jobs.request' => $all('sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'noc_engineer', 'support_agent'),
            'jobs.complete' => $own('field_technician', 'installer'),
            'tasks.view' => [
                ...$all('super_admin', 'management', 'cc_supervisor', 'ops_manager', 'auditor'),
                ...$team('sales_manager', 'support_agent', 'noc_engineer'),
                ...$own('cc_agent', 'sales_agent', 'field_technician', 'installer', 'finance'),
            ],
            'tasks.create' => [
                ...$all('super_admin', 'cc_supervisor', 'ops_manager'),
                ...$team('sales_manager', 'support_agent'),
                ...$own('cc_agent', 'sales_agent', 'noc_engineer', 'field_technician', 'installer'),
            ],
            'tasks.edit' => [
                ...$all('super_admin', 'cc_supervisor', 'ops_manager'),
                ...$team('sales_manager', 'support_agent'),
                ...$own('cc_agent', 'sales_agent', 'field_technician', 'installer', 'noc_engineer'),
            ],
            'tasks.assign' => $all('super_admin', 'cc_supervisor', 'ops_manager', 'sales_manager'),
            'inventory.view' => [
                ...$all('super_admin', 'ops_manager', 'warehouse', 'auditor'),
                ...$own('field_technician', 'installer'),
            ],
            'inventory.move' => $all('super_admin', 'warehouse'),
            'inventory.adjust' => $all('ops_manager', 'finance'),
            'inventory.reserve' => $all('super_admin', 'ops_manager', 'warehouse'),
            'tickets.view' => [
                ...$all('super_admin', 'management', 'cc_supervisor', 'ops_manager', 'noc_engineer', 'auditor'),
                ...$team('cc_agent', 'support_agent'),
                ...$own('sales_manager', 'sales_agent', 'field_technician', 'installer', 'finance'),
            ],
            'tickets.create' => [
                ...$all('super_admin', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'support_agent'),
                ...$own('field_technician', 'installer', 'finance'),
            ],
            'tickets.assign' => $all('super_admin', 'cc_supervisor', 'ops_manager'),
            'tickets.claim' => [
                ...$team('cc_agent', 'support_agent'),
                ...$own('noc_engineer', 'finance'),
            ],
            'tickets.escalate' => [
                ...$all('super_admin', 'cc_supervisor'),
                ...$team('cc_agent', 'support_agent', 'noc_engineer'),
                ...$own('field_technician', 'installer'),
            ],
            'tickets.override_sla' => $all('super_admin', 'cc_supervisor', 'ops_manager'),
            'tickets.request_sla' => $all('cc_agent', 'noc_engineer', 'support_agent'),
            'incidents.manage' => $all('super_admin', 'cc_supervisor', 'ops_manager', 'noc_engineer'),
            'calls.log' => [
                ...$all('super_admin', 'cc_supervisor'),
                ...$own('cc_agent', 'sales_agent', 'noc_engineer', 'finance', 'support_agent'),
            ],
            'calls.view' => [
                ...$all('super_admin', 'management', 'sales_manager', 'cc_supervisor', 'ops_manager', 'auditor'),
                ...$own('cc_agent'),
            ],
            'qa.score' => $all('super_admin', 'sales_manager', 'cc_supervisor'),
            'qa.view' => [
                ...$all('management', 'auditor'),
                ...$own('cc_agent', 'support_agent'),
            ],
            'invoices.view' => [
                ...$all('super_admin', 'management', 'finance', 'auditor'),
                ...$own('sales_manager', 'sales_agent'),
            ],
            'invoices.view_balance' => $all('cc_supervisor', 'cc_agent', 'support_agent'),
            'invoices.issue' => $all('super_admin', 'finance'),
            'payments.record' => $all('super_admin', 'finance'),
            'payments.collect' => [
                ...$all('super_admin'),
                ...$own('field_technician', 'installer'),
            ],
            'payments.request' => $all('cc_agent'),
            'payments.reverse' => $all('super_admin', 'management', 'finance'),
            'payments.write_off' => $all('super_admin', 'management', 'finance'),
            'billing.view_amounts' => $all('super_admin', 'management', 'finance', 'sales_manager', 'cc_supervisor', 'auditor'),
            'billing.view_cost' => $all('super_admin', 'management', 'finance'),
            'catalog.view' => $all('super_admin', 'management', 'auditor', 'finance', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent'),
            'catalog.edit' => $all('super_admin'),
            'catalog.prices.request' => $all('finance'),
            'catalog.prices.edit' => $all('super_admin'),
            'catalog.promotions.edit' => $all('super_admin'),
            'approvals.view' => [
                ...$all('super_admin', 'management', 'auditor', 'sales_manager', 'finance', 'ops_manager', 'cc_supervisor', 'noc_engineer', 'warehouse'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'approvals.request' => $all('super_admin', 'management', 'sales_manager', 'sales_agent', 'finance', 'ops_manager', 'cc_supervisor', 'cc_agent', 'noc_engineer', 'warehouse'),
            'approvals.decide' => $all('super_admin', 'management', 'sales_manager', 'finance', 'ops_manager', 'cc_supervisor', 'noc_engineer', 'warehouse'),
            'approvals.manage' => $all('super_admin', 'management'),
            'services.suspend' => $all('super_admin', 'noc_engineer', 'finance'),
            'services.request_suspend' => $all('cc_supervisor', 'cc_agent', 'support_agent'),
            'retention.offer' => $all('super_admin', 'management', 'sales_manager', 'cc_supervisor'),
            'retention.request' => $all('sales_agent', 'cc_agent'),
            'commissions.view' => [
                ...$all('super_admin', 'management', 'sales_manager', 'finance', 'auditor'),
                ...$team('cc_supervisor'),
                ...$own('sales_agent', 'cc_agent'),
            ],
            'targets.set' => [
                ...$all('super_admin', 'management'),
                ...$team('sales_manager', 'cc_supervisor'),
            ],
            'reports.view' => [
                ...$all('super_admin', 'management', 'auditor', 'finance'),
                ...$team('sales_manager', 'cc_supervisor', 'ops_manager', 'noc_engineer', 'warehouse', 'support_agent'),
                ...$own('sales_agent', 'cc_agent', 'field_technician', 'installer'),
            ],
            'reports.export' => [
                ...$all('super_admin', 'management', 'finance', 'auditor'),
                ...$team('sales_manager', 'cc_supervisor', 'ops_manager', 'warehouse'),
            ],
            'automations.view' => $all('super_admin', 'management', 'auditor'),
            'automations.manage' => $all('super_admin'),
            'imports.manage' => $all('super_admin'),
            'backups.manage' => $all('super_admin'),
            'audit.view' => [
                ...$all('super_admin', 'management', 'auditor'),
                ...$team('cc_supervisor'),
            ],
            'audit.export' => $all('auditor'),
            'settings.view' => $all('management', 'sales_manager', 'noc_engineer', 'auditor'),
            'settings.manage' => $all('super_admin', 'cc_supervisor', 'ops_manager', 'warehouse', 'finance'),
            'integrations.view' => $all('super_admin'),
            'integrations.manage' => $all('super_admin'),
            'geography.view' => $all('super_admin', 'management', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'field_technician', 'installer', 'finance', 'support_agent', 'auditor'),
            'geography.manage' => $all('super_admin', 'ops_manager'),
            'rates.view' => $all('super_admin', 'management', 'finance', 'sales_manager', 'auditor'),
            'rates.manage' => $all('super_admin', 'finance'),
            'attachments.view' => $all('super_admin', 'management', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'field_technician', 'installer', 'warehouse', 'finance', 'support_agent', 'auditor'),
            'attachments.upload' => $all('super_admin', 'sales_manager', 'sales_agent', 'cc_supervisor', 'cc_agent', 'ops_manager', 'noc_engineer', 'field_technician', 'installer', 'warehouse', 'finance', 'support_agent'),
            'attachments.delete' => $all('super_admin', 'ops_manager'),
            'users.view' => $all('auditor'),
            'users.manage' => $all('super_admin'),
        ];
    }
}
