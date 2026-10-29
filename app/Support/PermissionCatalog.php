<?php

namespace App\Support;

/**
 * Every permission in the platform, grouped by module, plus the default
 * roles each new fashion house starts with (SRS §9 permission matrix).
 *
 * Permissions are global; roles are per tenant. Tenant owners can edit
 * their roles but cannot invent new permission names — new permissions
 * are added here (and synced by PermissionSeeder) as modules are built.
 *
 * Naming: "<Verb> <subject>" — PermissionMeta turns it into plain English.
 */
class PermissionCatalog
{
    public const OWNER_ROLE = 'Owner';

    public const CUSTOMER_ROLE = 'Customer';

    /** module title => permission names */
    public const GROUPS = [
        'Dashboard' => [
            'View dashboard',
        ],
        'Users & Access' => [
            'View user', 'Create user', 'Update user', 'Delete user',
            'View role', 'Create role', 'Update role', 'Delete role', 'Update user-role',
            'View permission',
            'View audit log',
        ],
        'Fashion House Settings' => [
            'Manage tenant settings',
            'View branch', 'Manage branch',
        ],
        'Customers' => [
            'View customer', 'Create customer', 'Update customer', 'Delete customer',
            'Merge customer', 'View customer sensitive notes', 'Export customer',
        ],
        'Measurements' => [
            'View measurement', 'Capture measurement', 'Approve measurement',
            'Manage measurement templates', 'Send measurement request',
        ],
        'Styles & Catalogue' => [
            'View style', 'Manage style', 'Publish style', 'View product', 'Manage product', 'View costing',
        ],
        'Inventory & Procurement' => [
            'View inventory', 'Manage inventory', 'Adjust stock', 'Approve stock adjustment',
            'Issue material', 'Manage supplier', 'Manage purchase order', 'Approve purchase order',
        ],
        'Orders' => [
            'View order', 'Create order', 'Update order', 'Cancel order', 'Manage quotation',
        ],
        'POS' => [
            'Use POS', 'Open cash shift', 'Close cash shift', 'Void sale', 'Override price',
        ],
        'Finance' => [
            'View payment', 'Record payment', 'Approve refund', 'Manage invoice',
            'Manage expense', 'View financial reports',
        ],
        'Production' => [
            'View production', 'Update production', 'Manage production workflow',
            'Record quality check', 'Manage fitting', 'Manage delivery',
        ],
        'Projects' => [
            'View project', 'Manage project', 'Publish project update',
        ],
        'Online Store' => [
            'View online orders', 'Manage online orders', 'Confirm online payment',
            'Manage store products', 'Manage online store', 'Moderate reviews',
        ],
        'Appointments & Messaging' => [
            'Manage appointment', 'Manage notification templates', 'Send campaign',
        ],
        'Reports' => [
            'View reports', 'Export reports', 'View all branches',
        ],
        'Attendance' => [
            'View attendance', 'Manage attendance',
        ],
        'Customer materials' => [
            'View customer materials', 'Manage customer materials',
        ],
    ];

    /**
     * Default roles for a new fashion house. '*' = every permission.
     * Starting template only — owners can change them per house.
     */
    public const DEFAULT_ROLES = [
        self::OWNER_ROLE => [
            'badge'       => '#405189',
            'description' => 'Owns the fashion house account. Full access, including settings and roles.',
            'permissions' => ['*'],
        ],
        'Manager' => [
            'badge'       => '#0a9396',
            'description' => 'Runs a branch: orders, stock, staff workload, approvals and branch reports.',
            'permissions' => [
                'View dashboard', 'View user', 'Create user', 'Update user', 'View role', 'View audit log',
                'View branch',
                'View customer', 'Create customer', 'Update customer', 'Merge customer', 'View customer sensitive notes', 'Export customer',
                'View measurement', 'Capture measurement', 'Approve measurement', 'Send measurement request',
                'View style', 'Manage style', 'Publish style', 'View product', 'Manage product', 'View costing',
                'View inventory', 'Manage inventory', 'Adjust stock', 'Approve stock adjustment', 'Issue material',
                'Manage supplier', 'Manage purchase order', 'Approve purchase order',
                'View order', 'Create order', 'Update order', 'Cancel order', 'Manage quotation',
                'Use POS', 'Open cash shift', 'Close cash shift', 'Void sale', 'Override price',
                'View payment', 'Record payment', 'Approve refund', 'Manage invoice',
                'View production', 'Update production', 'Manage production workflow', 'Record quality check', 'Manage fitting', 'Manage delivery',
                'View project', 'Manage project', 'Publish project update',
                'Manage appointment', 'View reports', 'Export reports',
                'View online orders', 'Manage online orders', 'Confirm online payment', 'Manage store products', 'Manage online store', 'Moderate reviews',
                'View attendance', 'Manage attendance',
                'Manage notification templates', 'Send campaign',
                'View customer materials', 'Manage customer materials',
            ],
        ],
        'Online Sales Rep' => [
            'badge'       => '#25d366',
            'description' => 'Takes online and WhatsApp orders: confirms them, records payments, updates delivery.',
            'permissions' => [
                'View dashboard',
                'View customer', 'Create customer',
                'View product', 'View inventory', 'View order',
                'View payment', 'Record payment',
                'View online orders', 'Manage online orders', 'Confirm online payment',
                'View customer materials',
            ],
        ],
        'Designer' => [
            'badge'       => '#6a0572',
            'description' => 'Designer / measurement officer: styles, measurements, fittings.',
            'permissions' => [
                'View dashboard',
                'View customer', 'Create customer', 'Update customer',
                'View measurement', 'Capture measurement', 'Approve measurement', 'Manage measurement templates', 'Send measurement request',
                'View style', 'Manage style', 'View product',
                'View inventory',
                'View order', 'Create order', 'Update order', 'Manage quotation',
                'View production', 'Manage fitting',
                'View project', 'Manage appointment',
                'View customer materials', 'Manage customer materials',
            ],
        ],
        'Tailor' => [
            'badge'       => '#e76f51',
            'description' => 'Production staff: assigned work, stage updates, material use, quality issues.',
            'permissions' => [
                'View dashboard',
                'View customer', 'View measurement', 'Capture measurement', 'View style',
                'View order', 'View production', 'Update production', 'Record quality check',
                'View project',
                'View customer materials',
            ],
        ],
        'Storekeeper' => [
            'badge'       => '#2a9d8f',
            'description' => 'Stock, suppliers, purchase receipts, transfers, counts and material issues.',
            'permissions' => [
                'View dashboard',
                'View style', 'View product',
                'View inventory', 'Manage inventory', 'Adjust stock', 'Issue material',
                'Manage supplier', 'Manage purchase order',
                'View order', 'View project',
                'View online orders', 'Manage online orders',
                'View customer materials', 'Manage customer materials',
            ],
        ],
        'Cashier' => [
            'badge'       => '#e9c46a',
            'description' => 'POS sales, payments and receipts; reconciles own cash shift.',
            'permissions' => [
                'View dashboard',
                'View customer', 'Create customer',
                'View product', 'View inventory',
                'View order', 'Create order',
                'Use POS', 'Open cash shift', 'Close cash shift',
                'View payment', 'Record payment',
                'View online orders', 'Confirm online payment',
                'View customer materials', 'Manage customer materials',
                'Manage appointment',
            ],
        ],
        'Accountant' => [
            'badge'       => '#10b981',
            'description' => 'Payments, refunds, expenses, receivables and financial reports.',
            'permissions' => [
                'View dashboard', 'View audit log',
                'View customer', 'View order', 'View inventory', 'View project', 'View style', 'View product', 'View costing',
                'View payment', 'Record payment', 'Approve refund', 'Manage invoice', 'Manage expense', 'View financial reports',
                'View reports', 'Export reports', 'View all branches',
                'View online orders', 'Confirm online payment',
                'View attendance',
            ],
        ],
        self::CUSTOMER_ROLE => [
            'badge'       => '#6c757d',
            'description' => 'Customer portal / app account. Sees only their own orders, projects and payments.',
            'permissions' => [],
        ],
    ];

    /** Flat list of every permission name. */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** Module title for a permission name. */
    public static function groupOf(string $permission): ?string
    {
        foreach (self::GROUPS as $group => $names) {
            if (in_array($permission, $names, true)) {
                return $group;
            }
        }

        return null;
    }

    /** Permission names for a default role ('*' expanded). */
    public static function permissionsFor(string $role): array
    {
        $perms = self::DEFAULT_ROLES[$role]['permissions'] ?? [];

        return $perms === ['*'] ? self::all() : $perms;
    }
}
