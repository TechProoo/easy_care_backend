<?php

return [
    'baseline' => 'staff-access-v1',
    'permissions' => [
        'reference-data.view' => 'View reference data',
        'staff.view_self' => 'View own staff directory entry',
        'staff.view_department' => 'View staff in own department',
        'staff.view_any' => 'View the complete staff directory',
        'authorization.view' => 'View roles and permission grants',
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
];
