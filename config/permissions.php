<?php

/*
 | Permission catalogue and the default role matrix (Function Plan §3).
 | Seeded by PermissionSeeder; administrators can change role assignments in the UI.
 */
return [
    'permissions' => [
        'Cheques' => [
            'cheques.view' => 'View cheques (own records only, unless "view all")',
            'cheques.view_all' => 'View all cheques',
            'cheques.create' => 'Create cheques',
            'cheques.edit' => 'Edit draft cheques',
            'cheques.prepare' => 'Prepare cheques',
            'cheques.release' => 'Record cheque release',
            'cheques.clear' => 'Update clearing status / mark stale',
            'cheques.cancel' => 'Cancel or void cheques',
        ],
        'Receipts' => [
            'receipts.view' => 'View receipts (own records only, unless "view all")',
            'receipts.view_all' => 'View all receipts',
            'receipts.create' => 'Create / upload receipts',
            'receipts.edit' => 'Edit receipts',
            'receipts.verify' => 'Verify, process and archive receipts',
            'receipts.cancel' => 'Cancel receipts',
            'receipts.override_duplicate' => 'Override duplicate receipt block',
        ],
        'Documents' => [
            'documents.view' => 'View and download documents',
            'documents.upload' => 'Upload and replace documents',
        ],
        'Printing' => [
            'print.print' => 'Print cheques and receipts',
            'print.reprint' => 'Reprint',
            'print.calibrate' => 'Run calibration test prints',
        ],
        'Reference data' => [
            'payees.create' => 'Add payees',
            'payees.manage' => 'Manage payees',
            'banks.manage' => 'Manage banks and accounts',
            'templates.manage' => 'Manage print templates',
        ],
        'Reports & logs' => [
            'register.view' => 'View transaction register',
            'reports.view' => 'View all reports',
            'reports.printing' => 'View printing reports only',
            'activity.view' => 'View activity log',
        ],
        'Administration' => [
            'users.manage' => 'Manage users',
            'roles.manage' => 'Manage roles and permissions',
            'settings.manage' => 'Manage system settings',
        ],
    ],

    'roles' => [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Full access to every module.',
            'permissions' => '*',
        ],
        'finance' => [
            'label' => 'Finance Staff',
            'description' => 'Prepares cheques, verifies receipts, records release and clearing.',
            'permissions' => [
                'cheques.view_all', 'cheques.create', 'cheques.edit', 'cheques.prepare', 'cheques.release',
                'cheques.clear', 'cheques.cancel',
                'receipts.view_all', 'receipts.create', 'receipts.edit', 'receipts.verify', 'receipts.cancel',
                'documents.view', 'documents.upload',
                'print.print', 'print.reprint',
                'payees.create', 'payees.manage', 'register.view', 'reports.view',
            ],
        ],
        'encoder' => [
            'label' => 'Encoder',
            'description' => 'Encodes cheques and receipts; sees only their own records.',
            'permissions' => [
                'cheques.view', 'cheques.create', 'cheques.edit',
                'receipts.view', 'receipts.create', 'receipts.edit',
                'documents.view', 'documents.upload', 'payees.create',
            ],
        ],
        'printing' => [
            'label' => 'Printing Staff',
            'description' => 'Runs the print queue, confirms prints and reprints.',
            'permissions' => [
                'cheques.view_all', 'receipts.view_all', 'documents.view',
                'print.print', 'print.reprint', 'print.calibrate', 'reports.printing',
            ],
        ],
        'management' => [
            'label' => 'Management',
            'description' => 'Read-only oversight: dashboard, register, reports and activity.',
            'permissions' => [
                'cheques.view_all', 'receipts.view_all', 'documents.view', 'register.view',
                'reports.view', 'activity.view',
            ],
        ],
    ],
];
