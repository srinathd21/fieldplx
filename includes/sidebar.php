<?php
/**
 * FieldPlx Shared Sidebar
 *
 * Requirements:
 * - auth.php
 * - permissions.php
 * - functions.php
 *
 * Expected variables:
 * - $activePage
 * - $basePath
 *
 * Compatible with PHP 7.2.
 */

if (!defined('FIELDPLX_SIDEBAR_LOADED')) {
    define('FIELDPLX_SIDEBAR_LOADED', true);
}

$activePage = isset($activePage)
    ? trim((string) $activePage)
    : '';

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$companyName = !empty($_SESSION['company_name'])
    ? $_SESSION['company_name']
    : 'FieldPlx';

$companyLogo = !empty($_SESSION['company_logo'])
    ? $basePath . ltrim($_SESSION['company_logo'], '/')
    : '';

$currentUser = function_exists('authUser')
    ? authUser()
    : array();

$userName = !empty($currentUser['name'])
    ? $currentUser['name']
    : 'User';

$userRole = !empty($currentUser['role_name'])
    ? $currentUser['role_name']
    : 'User';

$isWorker = function_exists('isFieldWorker')
    ? (bool) isFieldWorker()
    : false;

/*
|--------------------------------------------------------------------------
| Sidebar helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('sidebarIsActive')) {
    function sidebarIsActive($activePage, $pages)
    {
        if (!is_array($pages)) {
            $pages = array($pages);
        }

        return in_array($activePage, $pages, true);
    }
}

if (!function_exists('sidebarActiveClass')) {
    function sidebarActiveClass($activePage, $pages)
    {
        return sidebarIsActive($activePage, $pages)
            ? 'active'
            : '';
    }
}

if (!function_exists('sidebarMenuOpenClass')) {
    function sidebarMenuOpenClass($activePage, $pages)
    {
        return sidebarIsActive($activePage, $pages)
            ? 'menu-open'
            : '';
    }
}

if (!function_exists('sidebarHasAnyPermission')) {
    function sidebarHasAnyPermission($permissions)
    {
        if (!function_exists('hasPermission')) {
            return true;
        }

        if (!is_array($permissions)) {
            return hasPermission($permissions);
        }

        foreach ($permissions as $permission) {
            if (hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}


if (!function_exists('sidebarHasModule')) {
    function sidebarHasModule($moduleCode)
    {
        if (function_exists('tenantHasModule')) {
            return tenantHasModule($moduleCode);
        }

        if (function_exists('hasModuleAccess')) {
            return hasModuleAccess($moduleCode);
        }

        /*
         * Do not hide the complete sidebar when the
         * tenant module helper is unavailable.
         */
        return true;
    }
}

if (!function_exists('sidebarHasFeature')) {
    function sidebarHasFeature($moduleCode, $featureCode)
    {
        if (function_exists('tenantHasFeature')) {
            return tenantHasFeature(
                $moduleCode,
                $featureCode
            );
        }

        if (function_exists('hasFeatureAccess')) {
            return hasFeatureAccess(
                $moduleCode,
                $featureCode
            );
        }

        /*
         * Do not hide menu items when the feature
         * helper is unavailable.
         */
        return true;
    }
}

if (!function_exists('sidebarCanAccess')) {
    function sidebarCanAccess(
        $moduleCode,
        $permissions,
        $featureCode = ''
    ) {
        if (!sidebarHasModule($moduleCode)) {
            return false;
        }

        if (
            $featureCode !== '' &&
            !sidebarHasFeature(
                $moduleCode,
                $featureCode
            )
        ) {
            return false;
        }

        return sidebarHasAnyPermission(
            $permissions
        );
    }
}

/*
|--------------------------------------------------------------------------
| Menu access groups
|--------------------------------------------------------------------------
*/

$canViewDashboard = sidebarCanAccess(
    'dashboard',
    'dashboard.view'
);

$canViewSales =
    sidebarCanAccess('clients', 'clients.view') ||
    sidebarCanAccess('clients', 'properties.view') ||
    sidebarCanAccess('quotes', 'quotes.view');

$canViewOperations =
    sidebarCanAccess('requests', 'requests.view') ||
    sidebarCanAccess('jobs', 'jobs.view') ||
    sidebarCanAccess(
        'product_services',
        'product_services.view',
        'view'
    ) ||
    sidebarCanAccess('work_orders', 'jobs.view') ||
    sidebarCanAccess('visits', 'jobs.view') ||
    sidebarCanAccess('scheduling', 'schedule.view') ||
    sidebarCanAccess('routes', 'schedule.view') ||
    sidebarCanAccess('tasks', 'jobs.view');

$canViewFinance =
    sidebarCanAccess('invoices', 'invoices.view') ||
    sidebarCanAccess('payments', 'payments.view') ||
    (
        sidebarHasModule('payments') &&
        sidebarHasAnyPermission(
            array(
                'expenses.view',
                'job_costing.view'
            )
        )
    );

$canViewTeam =
    sidebarCanAccess('workers', 'team.view') ||
    (
        sidebarHasModule('workers') &&
        sidebarHasAnyPermission('timesheets.view')
    );

$canViewCommunication =
    sidebarCanAccess('messages', 'messages.view');

$canViewReports =
    sidebarCanAccess(
        'reports',
        array(
            'reports.view',
            'reports.financial'
        )
    );

$canViewAdministration =
    sidebarHasAnyPermission(
        array(
            'settings.view',
            'team.manage',
            'integrations.view',
            'api.manage',
            'ai_receptionist.manage'
        )
    );

$canViewProfile = true;
?>

<aside class="fieldplx-sidebar" id="fieldplxSidebar">

    <div class="fieldplx-sidebar-header">
        <a
            href="<?= e($basePath); ?>dashboard.php"
            class="fieldplx-sidebar-brand"
        >
            <?php if ($companyLogo !== ''): ?>
                <img
                    src="<?= e($companyLogo); ?>"
                    alt="<?= e($companyName); ?>"
                    class="fieldplx-sidebar-logo"
                >
            <?php else: ?>
                <span class="fieldplx-sidebar-logo-placeholder">
                    <?= e(strtoupper(substr($companyName, 0, 1))); ?>
                </span>
            <?php endif; ?>

            <span class="fieldplx-sidebar-brand-text">
                <span class="fieldplx-sidebar-company-name">
                    <?= e($companyName); ?>
                </span>

                <span class="fieldplx-sidebar-product-name">
                    FieldPlx
                </span>
            </span>
        </a>

        <button
            type="button"
            class="fieldplx-sidebar-close"
            id="sidebarClose"
            aria-label="Close sidebar"
        >
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="fieldplx-sidebar-body">

        <?php if ($isWorker): ?>

            <div class="fieldplx-sidebar-section-label">
                Field Work
            </div>

            <nav class="fieldplx-sidebar-nav">

                <?php if ($canViewDashboard): ?>
                    <a
                        href="<?= e($basePath); ?>my-dashboard.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array('my-dashboard', 'dashboard')
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-grid"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            Dashboard
                        </span>
                    </a>
                <?php endif; ?>

                <?php if (sidebarCanAccess('scheduling', 'schedule.view')): ?>
                    <a
                        href="<?= e($basePath); ?>my-schedule.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array('my-schedule', 'schedule')
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-calendar3"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            My Schedule
                        </span>
                    </a>
                <?php endif; ?>

                <?php if (sidebarCanAccess('jobs', 'jobs.view')): ?>
                    <a
                        href="<?= e($basePath); ?>my-jobs.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array(
                                'my-jobs',
                                'my-job-view',
                                'job-view'
                            )
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-briefcase"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            My Jobs
                        </span>
                    </a>
                <?php endif; ?>

                <?php if (sidebarCanAccess('jobs', 'jobs.view')): ?>
                    <a
                        href="<?= e($basePath); ?>my-visits.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array(
                                'my-visits',
                                'visit-view',
                                'check-in',
                                'start-work',
                                'complete-visit'
                            )
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-geo-alt"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            My Visits
                        </span>
                    </a>
                <?php endif; ?>

                <?php if (sidebarCanAccess('tasks', 'jobs.view')): ?>
                    <a
                        href="<?= e($basePath); ?>my-tasks.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array('my-tasks', 'task-view')
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-check2-square"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            My Tasks
                        </span>
                    </a>
                <?php endif; ?>

                <a
                    href="<?= e($basePath); ?>profile.php"
                    class="fieldplx-sidebar-link <?= sidebarActiveClass(
                        $activePage,
                        'profile'
                    ); ?>"
                >
                    <span class="fieldplx-sidebar-link-icon">
                        <i class="bi bi-person"></i>
                    </span>

                    <span class="fieldplx-sidebar-link-text">
                        My Profile
                    </span>
                </a>

            </nav>

        <?php else: ?>

            <div class="fieldplx-sidebar-section-label">
                Main
            </div>

            <nav class="fieldplx-sidebar-nav">

                <?php if ($canViewDashboard): ?>
                    <a
                        href="<?= e($basePath); ?>dashboard.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            'dashboard'
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-grid"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            Dashboard
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($canViewSales): ?>
                    <div
                        data-module-code="clients"
                        class="fieldplx-sidebar-menu <?= sidebarMenuOpenClass(
                            $activePage,
                            array(
                                'clients',
                                'client-add',
                                'client-edit',
                                'client-view',
                                'properties',
                                'property-add',
                                'property-edit',
                                'property-view',
                                'quotes',
                                'quote-add',
                                'quote-edit',
                                'quote-view'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="fieldplx-sidebar-link fieldplx-sidebar-menu-toggle <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'clients',
                                    'client-add',
                                    'client-edit',
                                    'client-view',
                                    'properties',
                                    'property-add',
                                    'property-edit',
                                    'property-view',
                                    'quotes',
                                    'quote-add',
                                    'quote-edit',
                                    'quote-view'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-people"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Customers & Sales
                            </span>

                            <span class="fieldplx-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="fieldplx-sidebar-submenu">

                            <?php if (sidebarCanAccess('clients', 'clients.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>clients.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'clients',
                                            'client-add',
                                            'client-edit',
                                            'client-view'
                                        )
                                    ); ?>"
                                >
                                    Clients
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('clients', 'properties.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>properties.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'properties',
                                            'property-add',
                                            'property-edit',
                                            'property-view'
                                        )
                                    ); ?>"
                                >
                                    Properties
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('quotes', 'quotes.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>quotes.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'quotes',
                                            'quote-add',
                                            'quote-edit',
                                            'quote-view'
                                        )
                                    ); ?>"
                                >
                                    Quotes
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canViewOperations): ?>
                    <div
                        data-module-code="jobs"
                        class="fieldplx-sidebar-menu <?= sidebarMenuOpenClass(
                            $activePage,
                            array(
                                'requests',
                                'request-add',
                                'request-edit',
                                'request-view',
                                'bookings',
                                'booking-add',
                                'booking-edit',
                                'booking-view',
                                'jobs',
                                'job-add',
                                'job-edit',
                                'job-view',
                                'product-services',
                                'product-service-add',
                                'product-service-edit',
                                'product-service-view',
                                'work-orders',
                                'work-order-add',
                                'work-order-edit',
                                'work-order-view',
                                'visits',
                                'visit-add',
                                'visit-edit',
                                'visit-view',
                                'schedule',
                                'routes',
                                'route-add',
                                'route-view',
                                'tasks',
                                'task-add',
                                'task-view'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="fieldplx-sidebar-link fieldplx-sidebar-menu-toggle <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'requests',
                                    'request-add',
                                    'request-edit',
                                    'request-view',
                                    'bookings',
                                    'booking-add',
                                    'booking-edit',
                                    'booking-view',
                                    'jobs',
                                    'job-add',
                                    'job-edit',
                                    'job-view',
                                    'work-orders',
                                    'work-order-add',
                                    'work-order-edit',
                                    'work-order-view',
                                    'visits',
                                    'visit-add',
                                    'visit-edit',
                                    'visit-view',
                                    'schedule',
                                    'routes',
                                    'route-add',
                                    'route-view',
                                    'tasks',
                                    'task-add',
                                    'task-view'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-tools"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Operations
                            </span>

                            <span class="fieldplx-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="fieldplx-sidebar-submenu">

                            <?php if (sidebarCanAccess('requests', 'requests.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>requests.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'requests',
                                            'request-add',
                                            'request-edit',
                                            'request-view'
                                        )
                                    ); ?>"
                                >
                                    Requests
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('requests', 'requests.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>bookings.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'bookings',
                                            'booking-add',
                                            'booking-edit',
                                            'booking-view'
                                        )
                                    ); ?>"
                                >
                                    Bookings
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('jobs', 'jobs.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>jobs.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'jobs',
                                            'job-add',
                                            'job-edit',
                                            'job-view'
                                        )
                                    ); ?>"
                                >
                                    Jobs
                                </a>
                            <?php endif; ?>

                            <?php if (
                                sidebarCanAccess(
                                    'product_services',
                                    'product_services.view',
                                    'view'
                                )
                            ): ?>
                                <a
                                    href="<?= e($basePath); ?>product-services.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'product-services',
                                            'product-service-add',
                                            'product-service-edit',
                                            'product-service-view'
                                        )
                                    ); ?>"
                                >
                                    Products &amp; Services
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('work_orders', 'jobs.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>work-orders.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'work-orders',
                                            'work-order-add',
                                            'work-order-edit',
                                            'work-order-view'
                                        )
                                    ); ?>"
                                >
                                    Work Orders
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('visits', 'jobs.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>visits.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'visits',
                                            'visit-add',
                                            'visit-edit',
                                            'visit-view'
                                        )
                                    ); ?>"
                                >
                                    Visits
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('scheduling', 'schedule.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>scheduling.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array('schedule', 'scheduling')
                                    ); ?>"
                                >
                                    Scheduling
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('routes', 'schedule.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>routes.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'routes',
                                            'route-add',
                                            'route-view'
                                        )
                                    ); ?>"
                                >
                                    Routes
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('tasks', 'jobs.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>tasks.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'tasks',
                                            'task-add',
                                            'task-view'
                                        )
                                    ); ?>"
                                >
                                    Tasks
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canViewFinance): ?>
                    <div
                        data-module-code="invoices"
                        class="fieldplx-sidebar-menu <?= sidebarMenuOpenClass(
                            $activePage,
                            array(
                                'invoices',
                                'invoice-add',
                                'invoice-edit',
                                'invoice-view',
                                'payments',
                                'payment-add',
                                'payment-view',
                                'expenses',
                                'expense-add',
                                'expense-view',
                                'job-costing'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="fieldplx-sidebar-link fieldplx-sidebar-menu-toggle <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'invoices',
                                    'invoice-add',
                                    'invoice-edit',
                                    'invoice-view',
                                    'payments',
                                    'payment-add',
                                    'payment-view',
                                    'expenses',
                                    'expense-add',
                                    'expense-view',
                                    'job-costing'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-receipt"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Finance
                            </span>

                            <span class="fieldplx-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="fieldplx-sidebar-submenu">

                            <?php if (sidebarCanAccess('invoices', 'invoices.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>invoices.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'invoices',
                                            'invoice-add',
                                            'invoice-edit',
                                            'invoice-view'
                                        )
                                    ); ?>"
                                >
                                    Invoices
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarCanAccess('payments', 'payments.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>payments.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'payments',
                                            'payment-add',
                                            'payment-view'
                                        )
                                    ); ?>"
                                >
                                    Payments
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarHasModule('payments') && sidebarHasAnyPermission('expenses.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>expenses.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'expenses',
                                            'expense-add',
                                            'expense-view'
                                        )
                                    ); ?>"
                                >
                                    Expenses
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarHasModule('jobs') && sidebarHasAnyPermission('job_costing.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>job-costing.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        'job-costing'
                                    ); ?>"
                                >
                                    Job Costing
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canViewTeam): ?>
                    <div
                        data-module-code="workers"
                        class="fieldplx-sidebar-menu <?= sidebarMenuOpenClass(
                            $activePage,
                            array(
                                'team',
                                'users',
                                'user-add',
                                'user-edit',
                                'user-view',
                                'timesheets',
                                'timesheet-view'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="fieldplx-sidebar-link fieldplx-sidebar-menu-toggle <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'team',
                                    'users',
                                    'user-add',
                                    'user-edit',
                                    'user-view',
                                    'timesheets',
                                    'timesheet-view'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-person-workspace"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Team
                            </span>

                            <span class="fieldplx-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="fieldplx-sidebar-submenu">

                            <?php if (sidebarCanAccess('workers', 'team.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>users.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'team',
                                            'users',
                                            'user-add',
                                            'user-edit',
                                            'user-view'
                                        )
                                    ); ?>"
                                >
                                    Team Members
                                </a>
                            <?php endif; ?>

                            <?php if (sidebarHasModule('workers') && sidebarHasAnyPermission('timesheets.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>timesheets.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'timesheets',
                                            'timesheet-view'
                                        )
                                    ); ?>"
                                >
                                    Timesheets
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canViewCommunication): ?>
                    <div
                        data-module-code="messages"
                        class="fieldplx-sidebar-menu <?= sidebarMenuOpenClass(
                            $activePage,
                            array(
                                'messages',
                                'message-thread',
                                'notifications'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="fieldplx-sidebar-link fieldplx-sidebar-menu-toggle <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'messages',
                                    'message-thread',
                                    'notifications'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-chat-dots"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Communication
                            </span>

                            <span class="fieldplx-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="fieldplx-sidebar-submenu">

                            <?php if (sidebarCanAccess('messages', 'messages.view')): ?>
                                <a
                                    href="<?= e($basePath); ?>messages.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        array(
                                            'messages',
                                            'message-thread'
                                        )
                                    ); ?>"
                                >
                                    Messages
                                </a>
                            <?php endif; ?>

                            <?php if (
                                sidebarCanAccess('messages', 'messages.view')
                            ): ?>
                                <a
                                    href="<?= e($basePath); ?>notifications.php"
                                    class="fieldplx-sidebar-sublink <?= sidebarActiveClass(
                                        $activePage,
                                        'notifications'
                                    ); ?>"
                                >
                                    Notifications
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($canViewReports): ?>
                    <a
                        href="<?= e($basePath); ?>reports.php"
                        class="fieldplx-sidebar-link <?= sidebarActiveClass(
                            $activePage,
                            array('reports', 'report-view')
                        ); ?>"
                    >
                        <span class="fieldplx-sidebar-link-icon">
                            <i class="bi bi-bar-chart"></i>
                        </span>

                        <span class="fieldplx-sidebar-link-text">
                            Reports
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($canViewAdministration): ?>
                    <div class="fieldplx-sidebar-section-label mt-3">
                        Administration
                    </div>

                    <?php if (sidebarHasAnyPermission('team.manage')): ?>
                        <a
                            href="<?= e($basePath); ?>roles.php"
                            class="fieldplx-sidebar-link <?= sidebarActiveClass(
                                $activePage,
                                array(
                                    'roles',
                                    'role-add',
                                    'role-edit',
                                    'permissions'
                                )
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-shield-check"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Roles & Permissions
                            </span>
                        </a>
                    <?php endif; ?>

                    <?php if (sidebarHasAnyPermission('settings.view')): ?>
                        <a
                            href="<?= e($basePath); ?>settings.php"
                            class="fieldplx-sidebar-link <?= sidebarActiveClass(
                                $activePage,
                                'settings'
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-gear"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Settings
                            </span>
                        </a>
                    <?php endif; ?>

                    <?php if (sidebarHasAnyPermission('integrations.view')): ?>
                        <a
                            href="<?= e($basePath); ?>integrations.php"
                            class="fieldplx-sidebar-link <?= sidebarActiveClass(
                                $activePage,
                                'integrations'
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-plug"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                Integrations
                            </span>
                        </a>
                    <?php endif; ?>

                    <?php if (sidebarHasAnyPermission('ai_receptionist.manage')): ?>
                        <a
                            href="<?= e($basePath); ?>ai-receptionist.php"
                            class="fieldplx-sidebar-link <?= sidebarActiveClass(
                                $activePage,
                                'ai-receptionist'
                            ); ?>"
                        >
                            <span class="fieldplx-sidebar-link-icon">
                                <i class="bi bi-robot"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                AI Receptionist
                            </span>
                        </a>
                    <?php endif; ?>

                <?php endif; ?>

            </nav>

        <?php endif; ?>

    </div>

        <?php if (
            !$isWorker &&
            !$canViewDashboard &&
            !$canViewSales &&
            !$canViewOperations &&
            !$canViewFinance &&
            !$canViewTeam &&
            !$canViewCommunication &&
            !$canViewReports &&
            !$canViewAdministration
        ): ?>
            <div class="fieldplx-sidebar-empty">
                <i class="bi bi-lock"></i>
                <span>No modules are available for this account.</span>
            </div>
        <?php endif; ?>


    <div class="fieldplx-sidebar-footer">
        <div class="fieldplx-sidebar-user">
            <span class="fieldplx-sidebar-user-avatar">
                <?= e(strtoupper(substr($userName, 0, 1))); ?>
            </span>

            <span class="fieldplx-sidebar-user-details">
                <span class="fieldplx-sidebar-user-name">
                    <?= e($userName); ?>
                </span>

                <span class="fieldplx-sidebar-user-role">
                    <?= e($userRole); ?>
                </span>
            </span>

            <a
                href="<?= e($basePath); ?>logout.php"
                class="fieldplx-sidebar-logout"
                title="Logout"
                aria-label="Logout"
            >
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

</aside>

<div
    class="fieldplx-sidebar-overlay"
    id="fieldplxSidebarOverlay"
></div>

<style>
    :root {
        --fieldplx-sidebar-width: 246px;
        --fieldplx-sidebar-collapsed-width: 72px;
    }

    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-width);
        min-width: var(--fieldplx-sidebar-width);
        height: calc(100vh - var(--fieldplx-topbar-height));
        position: sticky;
        top: var(--fieldplx-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-right: 1px solid var(--fieldplx-border);
        transition:
            width 0.22s ease,
            min-width 0.22s ease,
            transform 0.22s ease;
    }

    .fieldplx-sidebar-header {
        min-height: 64px;
        padding: 10px 13px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-brand {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #111827;
        text-decoration: none;
    }

    .fieldplx-sidebar-logo,
    .fieldplx-sidebar-logo-placeholder {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 10px;
    }

    .fieldplx-sidebar-logo {
        object-fit: contain;
        background: #f7f4ff;
    }

    .fieldplx-sidebar-logo-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 16px;
        font-weight: 700;
    }

    .fieldplx-sidebar-brand-text {
        min-width: 0;
        display: block;
    }

    .fieldplx-sidebar-company-name {
        max-width: 160px;
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 12px;
        font-weight: 700;
    }

    .fieldplx-sidebar-product-name {
        margin-top: 1px;
        display: block;
        color: #8b5cf6;
        font-size: 9px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-close {
        width: 32px;
        height: 32px;
        margin-left: auto;
        padding: 0;
        display: none;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #6b7280;
        font-size: 16px;
    }

    .fieldplx-sidebar-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 9px;
        scrollbar-width: thin;
        scrollbar-color: #d8d4e5 transparent;
    }

    .fieldplx-sidebar-section-label {
        margin: 4px 10px 7px;
        color: #9ca3af;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.65px;
        text-transform: uppercase;
    }

    .fieldplx-sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .fieldplx-sidebar-link {
        width: 100%;
        min-height: 39px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #4b5563;
        text-align: left;
        text-decoration: none;
        font-family: inherit;
        font-size: 11px;
        font-weight: 500;
        transition:
            color 0.16s ease,
            background 0.16s ease;
    }

    .fieldplx-sidebar-link:hover {
        background: #f8f6ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-link.active {
        background: #f0ebff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-link-icon {
        width: 20px;
        height: 20px;
        flex: 0 0 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }

    .fieldplx-sidebar-link-text {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-arrow {
        margin-left: auto;
        color: #9ca3af;
        font-size: 10px;
        transition: transform 0.2s ease;
    }

    .fieldplx-sidebar-menu.menu-open
    .fieldplx-sidebar-arrow {
        transform: rotate(180deg);
    }

    .fieldplx-sidebar-submenu {
        max-height: 0;
        overflow: hidden;
        padding-left: 39px;
        transition: max-height 0.25s ease;
    }

    .fieldplx-sidebar-menu.menu-open
    .fieldplx-sidebar-submenu {
        max-height: 520px;
        padding-top: 3px;
        padding-bottom: 3px;
    }

    .fieldplx-sidebar-sublink {
        min-height: 31px;
        padding: 7px 9px;
        position: relative;
        display: flex;
        align-items: center;
        border-radius: 7px;
        color: #6b7280;
        text-decoration: none;
        font-size: 10px;
        font-weight: 500;
    }

    .fieldplx-sidebar-sublink::before {
        width: 5px;
        height: 5px;
        margin-right: 9px;
        flex: 0 0 5px;
        content: "";
        border-radius: 50%;
        background: #d1d5db;
    }

    .fieldplx-sidebar-sublink:hover {
        background: #faf8ff;
        color: #6d28d9;
    }

    .fieldplx-sidebar-sublink.active {
        background: #f7f3ff;
        color: #6d28d9;
        font-weight: 700;
    }

    .fieldplx-sidebar-sublink.active::before {
        background: #7c3aed;
    }

    .fieldplx-sidebar-empty {
        margin: 8px 10px 14px;
        padding: 14px 12px;
        display: grid;
        justify-items: center;
        gap: 7px;
        border: 1px dashed #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
        color: #7c3aed;
        font-size: 9px;
        line-height: 1.5;
        text-align: center;
    }

    .fieldplx-sidebar-empty i {
        font-size: 17px;
    }

    .fieldplx-sidebar-footer {
        padding: 10px;
        border-top: 1px solid #f0f1f3;
    }

    .fieldplx-sidebar-user {
        padding: 8px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 10px;
        background: #fafafa;
    }

    .fieldplx-sidebar-user-avatar {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #7c3aed, #5b21b6);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-details {
        min-width: 0;
        flex: 1;
    }

    .fieldplx-sidebar-user-name,
    .fieldplx-sidebar-user-role {
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .fieldplx-sidebar-user-name {
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .fieldplx-sidebar-user-role {
        margin-top: 1px;
        color: #9ca3af;
        font-size: 8px;
    }

    .fieldplx-sidebar-logout {
        width: 29px;
        height: 29px;
        flex: 0 0 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #9ca3af;
        text-decoration: none;
        font-size: 14px;
    }

    .fieldplx-sidebar-logout:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    .fieldplx-sidebar-overlay {
        display: none;
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar {
        width: var(--fieldplx-sidebar-collapsed-width);
        min-width: var(--fieldplx-sidebar-collapsed-width);
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-brand-text,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-section-label,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-link-text,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-arrow,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-submenu,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-user-details,
    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-logout {
        display: none;
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-header {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-link {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-user {
        justify-content: center;
        padding-left: 5px;
        padding-right: 5px;
    }

    @media (max-width: 991.98px) {
        .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            transform: translateX(-100%);
            box-shadow: 16px 0 40px rgba(17, 24, 39, 0.14);
        }

        body.fieldplx-sidebar-mobile-open
        .fieldplx-sidebar {
            transform: translateX(0);
        }

        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar {
            width: 260px;
            min-width: 260px;
        }

        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-logout {
            display: block;
        }

        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-submenu {
            display: block;
        }

        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed
        .fieldplx-sidebar-user {
            justify-content: initial;
        }

        .fieldplx-sidebar-close {
            display: inline-flex;
        }

        .fieldplx-sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 1040;
            display: block;
            visibility: hidden;
            background: rgba(17, 24, 39, 0.42);
            opacity: 0;
            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;
        }

        body.fieldplx-sidebar-mobile-open
        .fieldplx-sidebar-overlay {
            visibility: visible;
            opacity: 1;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById(
        'fieldplxSidebarOverlay'
    );

    const mobileBreakpoint = 992;

    function isMobileSidebar() {
        return window.innerWidth < mobileBreakpoint;
    }

    function openMobileSidebar() {
        body.classList.add('fieldplx-sidebar-mobile-open');
    }

    function closeMobileSidebar() {
        body.classList.remove('fieldplx-sidebar-mobile-open');
    }

    function toggleDesktopSidebar() {
        body.classList.toggle('fieldplx-sidebar-collapsed');

        try {
            localStorage.setItem(
                'fieldplx_sidebar_collapsed',
                body.classList.contains(
                    'fieldplx-sidebar-collapsed'
                )
                    ? '1'
                    : '0'
            );
        } catch (error) {
            // Storage may be unavailable.
        }
    }

    if (!isMobileSidebar()) {
        let savedSidebarState = '0';

        try {
            savedSidebarState = localStorage.getItem(
                'fieldplx_sidebar_collapsed'
            ) || '0';
        } catch (error) {
            savedSidebarState = '0';
        }

        if (savedSidebarState === '1') {
            body.classList.add(
                'fieldplx-sidebar-collapsed'
            );
        }
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            if (isMobileSidebar()) {
                openMobileSidebar();
            } else {
                toggleDesktopSidebar();
            }
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function () {
            closeMobileSidebar();
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function () {
            closeMobileSidebar();
        });
    }

    const menuToggles = document.querySelectorAll(
        '.fieldplx-sidebar-menu-toggle'
    );

    menuToggles.forEach(function (menuToggle) {
        menuToggle.addEventListener('click', function () {
            const menu = menuToggle.closest(
                '.fieldplx-sidebar-menu'
            );

            if (!menu) {
                return;
            }

            if (
                body.classList.contains(
                    'fieldplx-sidebar-collapsed'
                ) &&
                !isMobileSidebar()
            ) {
                body.classList.remove(
                    'fieldplx-sidebar-collapsed'
                );

                try {
                    localStorage.setItem(
                        'fieldplx_sidebar_collapsed',
                        '0'
                    );
                } catch (error) {
                    // Storage may be unavailable.
                }
            }

            const willOpen = !menu.classList.contains(
                'menu-open'
            );

            document
                .querySelectorAll(
                    '.fieldplx-sidebar-menu.menu-open'
                )
                .forEach(function (openMenu) {
                    if (openMenu !== menu) {
                        openMenu.classList.remove('menu-open');
                    }
                });

            menu.classList.toggle('menu-open', willOpen);
        });
    });

    window.addEventListener('resize', function () {
        if (!isMobileSidebar()) {
            closeMobileSidebar();
        }
    });
});
</script>