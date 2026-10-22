<?php

namespace App\Support;

/**
 * Turns a raw permission name (e.g. "View customer", "Approve measurement")
 * into a friendly action chip + a plain-English description, so the role editor
 * can explain what each permission actually allows. Works for every permission
 * with no database changes; specific names can be given an exact description in
 * the OVERRIDES map below.
 */
class PermissionMeta
{
    /** Verb => [chip label, sentence template]. {x} = the rest of the permission name. */
    protected const VERBS = [
        'View'     => ['View',    'See {x} — read-only, no changes.'],
        'Show'     => ['View',    'See {x}.'],
        'Preview'  => ['Preview', 'Preview {x} before it is finalised.'],
        'Create'   => ['Create',  'Add new {x}.'],
        'Add'      => ['Add',     'Add {x}.'],
        'Update'   => ['Edit',    'Edit existing {x}.'],
        'Edit'     => ['Edit',    'Edit existing {x}.'],
        'Manage'   => ['Manage',  'Full control of {x} — add, edit and remove.'],
        'Delete'   => ['Delete',  'Permanently delete {x}. This cannot be undone.'],
        'Remove'   => ['Remove',  'Remove {x}.'],
        'Approve'  => ['Approve', 'Approve {x}.'],
        'Submit'   => ['Submit',  'Submit {x} for approval.'],
        'Release'  => ['Release', 'Release {x} (money leaves the account).'],
        'Pay'      => ['Pay',     'Pay {x}.'],
        'Post'     => ['Post',    'Post {x} to the records / ledger.'],
        'Close'    => ['Close',   'Close {x}.'],
        'Use'      => ['Access',  'Use {x}.'],
        'Download' => ['Download','Download {x}.'],
        'Print'    => ['Print',   'Print {x}.'],
        'Generate' => ['Generate','Generate {x}.'],
        'Export'   => ['Export',  'Export {x}.'],
        'Import'   => ['Import',  'Import {x}.'],
        'Send'     => ['Send',    'Send {x}.'],
        'Assign'   => ['Assign',  'Assign {x}.'],
        'Record'   => ['Create',  'Record {x}.'],
        'Cancel'   => ['Delete',  'Cancel {x} (reservations and balances are handled by policy).'],
        'Merge'    => ['Manage',  'Merge {x}.'],
    ];

    /** Exact-name overrides for permissions the generic rule can't phrase well. */
    protected const OVERRIDES = [
        'View dashboard'                => ['View',    'Open the fashion house dashboard.'],
        'View online orders'            => ['View',    'See orders placed on the online shop or through WhatsApp, with the customer\'s details.'],
        'Manage online orders'          => ['Manage',  'Accept, edit, pack, ship, deliver and cancel online and WhatsApp orders, and take new WhatsApp orders.'],
        'Confirm online payment'        => ['Approve', 'Confirm bank transfers and other payments for online orders (records the payment).'],
        'Manage store products'         => ['Manage',  'Upload product pictures and edit online prices, sale prices, descriptions and visibility in the shop.'],
        'Manage online store'           => ['Manage',  'Shop settings: payment methods and keys, bank details, WhatsApp, delivery zones, coupons and banners.'],
        'Moderate reviews'              => ['Approve', 'Approve, hide and reply to product reviews.'],
        'View reports'                  => ['View',    'Open reports; each report also needs the permission for what it shows (money, orders, stock…).'],
        'Export reports'                => ['Export',  'Download reports as PDF, Excel or CSV — exactly what the person can see on screen.'],
        'View all branches'             => ['View',    'See reports and dashboard figures for every branch, not just the person\'s own branch.'],
        'View financial reports'        => ['View',    'See money reports: sales, profit and loss, expenses and money owed.'],
        'Manage expense'                => ['Manage',  'Record house running costs (rent, salaries, power…), correct them the same day and void them with a reason.'],
        'Update user-role'              => ['Assign',  'Add or remove staff in a role.'],
        'Manage tenant settings'        => ['Manage',  'Change the fashion house profile, branding, currency and timezone.'],
        'View customer sensitive notes' => ['View',    'See private customer notes and other sensitive details.'],
        'Merge customer'                => ['Manage',  'Merge duplicate customer records into one.'],
        'Approve measurement'           => ['Approve', 'Verify and approve measurement sessions (approved sessions cannot be edited).'],
        'Capture measurement'           => ['Create',  'Take measurements and save or submit a session for review.'],
        'Send measurement request'      => ['Send',    'Send a customer a secure link to submit their own measurements.'],
        'Manage style'                  => ['Manage',  'Create styles and edit their drafts (descriptions, pictures, prices, measurement templates).'],
        'Publish style'                 => ['Approve', 'Publish a style version so it can be ordered and shown in the catalogue.'],
        'View costing'                  => ['View',    'See estimated costs and cost prices of styles and products.'],
        'Adjust stock'                  => ['Edit',    'Request stock count adjustments and corrections.'],
        'Approve stock adjustment'      => ['Approve', 'Approve stock adjustments above the configured threshold.'],
        'Issue material'                => ['Issue',   'Issue fabric and accessories from stock to production.'],
        'Use POS'                       => ['Access',  'Make ready-made sales at the point of sale.'],
        'Open cash shift'               => ['Open',    'Open a cashier shift with an opening float.'],
        'Close cash shift'              => ['Close',   'Close a cashier shift and record the counted cash.'],
        'Void sale'                     => ['Delete',  'Void a POS sale (requires a reason; audited).'],
        'Override price'                => ['Edit',    'Change a price or apply a discount above the allowed limit.'],
        'Approve refund'                => ['Approve', 'Approve and process refunds (audited).'],
        'Publish project update'        => ['Approve', 'Publish a project milestone or update to the client.'],
        'Send campaign'                 => ['Send',    'Send promotional messages to customers who have consented.'],
    ];

    /** ['action' => chip word, 'label' => readable name, 'desc' => sentence] */
    public static function for(string $name): array
    {
        $label = ucfirst(trim($name));

        if (isset(self::OVERRIDES[$name])) {
            [$action, $desc] = self::OVERRIDES[$name];
            return ['action' => $action, 'label' => $label, 'desc' => $desc];
        }

        $parts = explode(' ', trim($name), 2);
        $verb = ucfirst(strtolower($parts[0]));
        $rest = isset($parts[1]) ? self::pretty($parts[1]) : '';

        if (isset(self::VERBS[$verb]) && $rest !== '') {
            [$chip, $tpl] = self::VERBS[$verb];
            return ['action' => $chip, 'label' => $label, 'desc' => str_replace('{x}', $rest, $tpl)];
        }

        return ['action' => 'Access', 'label' => $label, 'desc' => 'Grants the “' . $label . '” permission.'];
    }

    public static function action(string $name): string
    {
        return self::for($name)['action'];
    }

    /** "user-role" / "purchase_order" => "user role" / "purchase order" */
    protected static function pretty(string $s): string
    {
        return trim(strtolower(str_replace(['-', '_'], ' ', $s)));
    }

    /** Chip colour class by action word. */
    public static function chipClass(string $action): string
    {
        return [
            'View' => 'pm-chip-view', 'Preview' => 'pm-chip-view',
            'Create' => 'pm-chip-create', 'Add' => 'pm-chip-create',
            'Edit' => 'pm-chip-edit', 'Assign' => 'pm-chip-edit',
            'Manage' => 'pm-chip-manage', 'Access' => 'pm-chip-manage',
            'Issue' => 'pm-chip-edit', 'Open' => 'pm-chip-create',
            'Delete' => 'pm-chip-delete', 'Remove' => 'pm-chip-delete', 'Close' => 'pm-chip-delete',
            'Approve' => 'pm-chip-approve', 'Submit' => 'pm-chip-approve', 'Post' => 'pm-chip-approve',
            'Release' => 'pm-chip-money', 'Pay' => 'pm-chip-money',
        ][$action] ?? 'pm-chip-other';
    }
}
