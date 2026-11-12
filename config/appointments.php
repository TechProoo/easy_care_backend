<?php

return [
    'timezone' => env('APPOINTMENT_TIMEZONE', 'Africa/Lagos'),
    'booking_channels' => ['Voice Call', 'Social Media', 'Email', 'Referral', 'In-House', 'Walk-In'],
    'visit_types' => ['General Consultation', 'Surgical Consultation', 'Follow-Up Appointment',
        'Emergency Care Visit', 'Routine Check-Up', 'Specialist Referral', 'Telehealth Session',
        'Antenatal Care', 'Post Natal Care', 'Immunization', 'Family Planning', 'Child Birth'],
];
