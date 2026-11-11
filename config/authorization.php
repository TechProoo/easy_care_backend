<?php

return [
    'baseline' => 'staff-access-v1',
    'management_baseline' => 'staff-administration-v1',
    'reset_minutes' => 15,
    'permissions' => [
        'reference-data.view' => 'View reference data',
        'staff.view_self' => 'View own staff directory entry',
        'staff.view_department' => 'View staff in own department',
        'staff.view_any' => 'View the complete staff directory',
        'authorization.view' => 'View roles and permission grants',
        'staff.manage' => 'Create and manage staff accounts',
        'staff.reset_password' => 'Issue staff password reset codes',
    ],
    'role_defaults' => [
        'admin' => ['reference-data.view', 'staff.view_self', 'staff.view_any', 'authorization.view'],
        'frontdesk' => ['reference-data.view', 'staff.view_self'],
        'doctor' => ['reference-data.view', 'staff.view_self'],
        'nurse' => ['reference-data.view', 'staff.view_self'],
        'pharmacy' => ['reference-data.view', 'staff.view_self'],
        'hmo' => ['reference-data.view', 'staff.view_self'],
        'diagnostics-and-radiologist' => ['reference-data.view', 'staff.view_self'],
    ],
    'management_role_defaults' => [
        'admin' => ['staff.manage', 'staff.reset_password'],
    ],
];
