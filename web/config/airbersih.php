<?php

return [
    'mode' => env('AIR_MODE', 'simulation'),
    'documents_disk' => env('AIR_DOCUMENTS_DISK', 'local'),
    'organization' => 'KSM Banyu Bening',
    'area' => 'RT 05 Taman Kedaung, Pamulang',
    'initial_admin_email' => env('AIR_INITIAL_ADMIN_EMAIL'),
    'ca_path' => dirname(base_path()).'/private/config/aiven-ca.pem',
    'http_ca' => dirname(base_path()).'/private/config/http-ca-bundle.pem',
    'sources' => dirname(base_path()).'/private/sources',
];
