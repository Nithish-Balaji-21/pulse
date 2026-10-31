<?php

/**
 * Role permission catalog — source of truth for labels, columns, visibility, and defaults.
 *
 * visible_when: OR of AND-groups, e.g. [['fibre.active'], ['pith.active', 'pith.submodules.bales']]
 */
$crudFull = [
    'reader' => ['view' => true],
    'staff' => ['view' => true, 'create' => true],
    'supervisor' => ['view' => true, 'create' => true, 'edit' => true],
    'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
    'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
];

$productionCrudSupervisor = [
    'reader' => ['view' => true],
    'staff' => ['view' => true],
    'supervisor' => ['view' => true, 'create' => true, 'edit' => true],
    'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
    'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
];

$viewOnlyOwner = [
    'reader' => ['view' => false],
    'staff' => ['view' => false],
    'supervisor' => ['view' => false],
    'admin' => ['view' => false],
    'owner' => ['view' => true],
];

$ownerOnlyWrites = [
    'reader' => ['view' => true],
    'staff' => ['view' => true],
    'supervisor' => ['view' => true],
    'admin' => ['view' => true],
    'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
];

$ownerOnlyCustomization = [
    'reader' => ['view' => false],
    'staff' => ['view' => false],
    'supervisor' => ['view' => false],
    'admin' => ['view' => false],
    'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
];

// Factory Setup modules: Owner on by default; Admin off until Owner enables.
$ownerOnlyCustomization = [
    'reader' => ['view' => false],
    'staff' => ['view' => false],
    'supervisor' => ['view' => false],
    'admin' => ['view' => false],
    'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
];

return [
    'roles' => [
        'owner' => [
            'label' => 'Owner',
            'description' => 'The factory owner — runs everything, sets up the factory, decides who gets what access, sees all reports, manages all records.',
            'sort' => 1,
        ],
        'admin' => [
            'label' => 'Admin',
            'description' => 'Manager / accountant — day-to-day management, records production, manages staff, tracks inventory, handles attendance.',
            'sort' => 2,
        ],
        'supervisor' => [
            'label' => 'Supervisor',
            'description' => 'Floor supervisor / shift in-charge — oversees production on the floor, records what was made, marks attendance, closes stock at end of shift.',
            'sort' => 3,
        ],
        'staff' => [
            'label' => 'Staff',
            'description' => 'Workers / data entry operators — enter what they produced, add production entries and stock movements, cannot delete or approve anything.',
            'sort' => 4,
        ],
        'reader' => [
            'label' => 'Reader',
            'description' => 'Visitors / auditors / silent partners — can only see reports and records, cannot change anything.',
            'sort' => 5,
        ],
    ],

    /*
     | Hierarchical section caps — a role may only STORE / SHOW modules in these sections.
     | null = all sections (Owner). Admin skips device_configuration (section 9) but keeps assets (10).
     */
    'role_section_caps' => [
        'reader' => ['production', 'inventory', 'pith_setup', 'attendance_staff'],
        'staff' => ['production', 'inventory', 'pith_setup', 'attendance_staff'],
        'supervisor' => ['production', 'inventory', 'pith_setup', 'attendance_staff', 'user_management'],
        'admin' => [
            'production',
            'inventory',
            'pith_setup',
            'attendance_staff',
            'user_management',
            'factory_customization',
            'notifications',
            'assets',
        ],
        'owner' => null,
    ],

    'sections' => [
        'production' => [
            'label' => 'Production',
            'modules' => ['pith_production', 'fibre_bales', 'pith_blocks', 'growbag_production'],
        ],
        'inventory' => [
            'label' => 'Inventory & Stock',
            'modules' => ['pith_inventory', 'pith_inventory_inward', 'pith_inventory_outward', 'pith_watering', 'husk_inventory', 'close_location_stock'],
        ],
        'pith_setup' => [
            'label' => 'Pith Inventory Setup',
            'modules' => ['pith_location_groups', 'pith_locations'],
        ],
        'attendance_staff' => [
            'label' => 'Attendance & Staff',
            'modules' => ['attendance', 'manage_staff'],
        ],
        'user_management' => [
            'label' => 'User Management',
            'modules' => ['manage_users'],
        ],
        'factory_setup' => [
            'label' => 'Factory Setup',
            'modules' => ['factory_configuration'],
        ],
        'factory_customization' => [
            'label' => 'Factory Customization',
            'modules' => ['shift_settings', 'asset_categories', 'factory_customizations', 'geofence_locations'],
        ],
        'notifications' => [
            'label' => 'Notification Preferences',
            'modules' => ['notification_preferences'],
        ],
        'device_configuration' => [
            'label' => 'Device Configuration',
            'modules' => ['iot_devices', 'water_meters', 'water_tanks', 'fibre_bale_machines', 'growbag_machines'],
        ],
        'assets' => [
            'label' => 'Assets & Equipment',
            'modules' => ['asset_tracking'],
        ],
    ],

    'modules' => [
        'pith_production' => [
            'label' => 'Pith Production',
            'visible_when' => [['pith.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            // Folded into View / Edit — not shown under More.
            'column_companions' => [
                'view' => ['view_device_records'],
                'edit' => ['assign_to_inventory'],
            ],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true, 'view_device_records' => true],
                'staff' => ['view' => true, 'create' => true, 'view_device_records' => true],
                'supervisor' => ['view' => true, 'create' => true, 'edit' => true, 'view_device_records' => true, 'assign_to_inventory' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'view_device_records' => true, 'assign_to_inventory' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'view_device_records' => true, 'assign_to_inventory' => true],
            ],
        ],

        'fibre_bales' => [
            'label' => 'Fibre Bales',
            'visible_when' => [['pith.active', 'pith.submodules.bales']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => $productionCrudSupervisor,
        ],

        'pith_blocks' => [
            'label' => 'Pith Blocks',
            'visible_when' => [['pith.active', 'pith.submodules.blocks']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => $productionCrudSupervisor,
        ],

        'growbag_production' => [
            'label' => 'Growbag Production',
            'visible_when' => [['growbags.active']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true],
                'admin' => ['view' => true],
                'owner' => ['view' => true],
            ],
        ],

        'pith_inventory' => [
            'label' => 'Pith Inventory',
            'visible_when' => [['pith.active']],
            'columns' => ['view'],
            'others' => [
                'inventory_report' => ['label' => 'Reports', 'hint' => 'Inventory summary reports'],
            ],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true, 'inventory_report' => true],
                'admin' => ['view' => true, 'inventory_report' => true],
                'owner' => ['view' => true, 'inventory_report' => true],
            ],
        ],

        'pith_inventory_inward' => [
            'label' => 'Pith Inventory — Inward',
            'visible_when' => [['pith.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true, 'create' => true],
                'supervisor' => ['view' => true, 'create' => true, 'edit' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        'pith_inventory_outward' => [
            'label' => 'Pith Inventory — Outward',
            'visible_when' => [['pith.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true, 'create' => true],
                'supervisor' => ['view' => true, 'create' => true, 'edit' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        /*
         * Watering cycles logged against a pith location. There is no view column — seeing
         * waterings rides on `pith_inventory:view`, the same as the location's inward/outward.
         */
        'pith_watering' => [
            'label' => 'Pith Inventory — Watering',
            'visible_when' => [['pith.active']],
            'columns' => ['create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                'reader' => [],
                'staff' => ['create' => true],
                'supervisor' => ['create' => true, 'edit' => true],
                'admin' => ['create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        'husk_inventory' => [
            'label' => 'Husk Inventory',
            'visible_when' => [['pith.active', 'husks_stock.active']],
            'columns' => ['view', 'update'],
            'column_labels' => ['update' => 'Update'],
            /*
             * `daily_reminder` gates the mobile app's daily husk entry popup only — it is not a
             * write capability. Being able to update husk stock and being the person nagged each
             * morning to record yesterday's intake are different things: the staff on the yard
             * floor who actually weigh the intake get the prompt, while supervisors, admins and
             * owners keep update rights without being interrupted. An owner can move the prompt to
             * another role from the Roles page. The prompt is paired with `update` wherever it is
             * granted — a reminder to file an entry the role cannot post is nothing but noise.
             */
            'others' => [
                'daily_reminder' => ['label' => 'Daily Entry Reminder', 'hint' => 'Mobile app popup'],
                'inventory_report' => ['label' => 'Reports', 'hint' => 'Inventory summary reports'],
            ],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true, 'update' => true, 'daily_reminder' => true],
                'supervisor' => ['view' => true, 'update' => true, 'inventory_report' => true],
                'admin' => ['view' => true, 'update' => true, 'inventory_report' => true],
                'owner' => ['view' => true, 'update' => true, 'inventory_report' => true],
            ],
        ],

        'close_location_stock' => [
            'label' => 'Close Location Stock',
            'visible_when' => [['pith.active']],
            'columns' => [],
            'others' => [
                'close' => ['label' => 'Close Stock', 'hint' => 'One-shot operation'],
            ],
            'defaults' => [
                'reader' => [],
                'staff' => [],
                'supervisor' => ['close' => true],
                'admin' => ['close' => true],
                'owner' => ['close' => true],
            ],
        ],

        'pith_location_groups' => [
            'label' => 'Pith Location Groups',
            'visible_when' => [['pith.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        'pith_locations' => [
            'label' => 'Pith Locations',
            'visible_when' => [['pith.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => true],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        /*
         * Labour is a pith-factory concern. A device-only factory — fibre and/or growbags with no
         * pith — is a production line whose output the device already counts, so there is no
         * per-head attendance to record against it; hence `not.device_only` alongside the module
         * flag, the same pairing `iot_devices` uses below with its own condition.
         */
        'attendance' => [
            'label' => 'Attendance',
            'visible_when' => [['attendance.active', 'not.device_only']],
            'columns' => ['view', 'create', 'edit'],
            'others' => [
                'salary_report' => ['label' => 'Salary / Payroll Report', 'hint' => 'Generate action'],
            ],
            'defaults' => [
                // Reader: view only by default; Owner may turn off to hide Labour.
                'reader' => ['view' => true],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true, 'create' => true, 'edit' => true, 'salary_report' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'salary_report' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'salary_report' => true],
            ],
        ],

        'manage_staff' => [
            'label' => 'Manage Staff',
            'visible_when' => [['always']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                // Hidden from Reader catalog (attendance view only).
                'reader' => [],
                'staff' => ['view' => true],
                'supervisor' => ['view' => true],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        'manage_users' => [
            'label' => 'Manage Users',
            'visible_when' => [['always']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'column_labels' => [
                'view' => 'View Users',
                'create' => 'Add / Invite User',
                'edit' => 'Edit / Update User Role',
                'delete' => 'Delete / Remove User',
            ],
            'others' => [],
            'defaults' => [
                // Staff/Reader caps exclude user_management — never seed these keys.
                'reader' => [],
                'staff' => [],
                'supervisor' => ['view' => true],
                'admin' => ['view' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],

        'factory_configuration' => [
            'label' => 'Factory Configuration',
            'visible_when' => [['always']],
            'columns' => ['edit'],
            'column_labels' => ['edit' => 'Edit Team Name'],
            'others' => [],
            'defaults' => [
                'reader' => [],
                'staff' => [],
                'supervisor' => [],
                'admin' => [],
                'owner' => ['edit' => true],
            ],
        ],

        'geofence_locations' => [
            'label' => 'Geofence Locations',
            'visible_when' => [['geofencing.active']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => $ownerOnlyCustomization,
        ],

        'shift_settings' => [
            'label' => 'Shift Settings',
            'visible_when' => [['always']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => $ownerOnlyCustomization,
        ],

        'asset_categories' => [
            'label' => 'Asset Categories',
            'visible_when' => [['always']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => $ownerOnlyCustomization,
        ],

        'factory_customizations' => [
            'label' => 'Factory Customizations',
            'visible_when' => [['always']],
            'columns' => ['view', 'edit'],
            'others' => [],
            'defaults' => [
                'reader' => ['view' => false],
                'staff' => ['view' => false],
                'supervisor' => ['view' => false],
                'admin' => ['view' => false],
                'owner' => ['view' => true, 'edit' => true],
            ],
        ],

        'notification_preferences' => [
            'label' => 'Notification Preferences',
            'visible_when' => [['always']],
            'columns' => ['edit'],
            'column_labels' => ['edit' => 'Edit Notification Preferences'],
            'others' => [],
            // Only Admin/Owner have section 8 in their caps; Supervisor/Staff/Reader must not store this module.
            'defaults' => [
                'reader' => [],
                'staff' => [],
                'supervisor' => [],
                'admin' => ['edit' => true],
                'owner' => ['edit' => true],
            ],
        ],

        // Device write actions remain a superadmin concern outside the factory role matrix.
        'iot_devices' => [
            'label' => 'IoT Devices',
            'visible_when' => [['not.fibre_only']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => $viewOnlyOwner,
        ],

        'water_meters' => [
            'label' => 'Water Meters',
            'visible_when' => [['water_meter.active']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => $viewOnlyOwner,
        ],

        'water_tanks' => [
            'label' => 'Water Tanks',
            'visible_when' => [['water_meter.active']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => $viewOnlyOwner,
        ],

        'fibre_bale_machines' => [
            'label' => 'Fibre Bale Machines',
            'visible_when' => [['pith.active', 'pith.submodules.bales']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => $viewOnlyOwner,
        ],

        'growbag_machines' => [
            'label' => 'Growbag Machines',
            'visible_when' => [['growbags.active']],
            'columns' => ['view'],
            'others' => [],
            'defaults' => $viewOnlyOwner,
        ],

        'asset_tracking' => [
            'label' => 'Asset Tracking',
            'visible_when' => [['always']],
            'columns' => ['view', 'create', 'edit', 'delete'],
            'others' => [],
            'defaults' => [
                // Only Admin (cap includes assets) and Owner store this module.
                'reader' => [],
                'staff' => [],
                'supervisor' => [],
                'admin' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
                'owner' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            ],
        ],
    ],
];
