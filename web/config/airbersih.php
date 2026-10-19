<?php

return [
    'mode' => env('AIR_MODE', 'simulation'),
    'documents_disk' => env('AIR_DOCUMENTS_DISK', 'local'),
    'organization' => 'KSM Banyu Bening',
    'area' => 'RT 05 Taman Kedaung, Pamulang',
    'initial_admin_email' => env('AIR_INITIAL_ADMIN_EMAIL'),
    'database_profile' => env('AIR_DATABASE_PROFILE', 'aiven'),
    'wowsql_project' => env('AIR_WOWSQL_PROJECT'),
    'ca_path' => env('AIR_DB_CA_PATH', dirname(base_path()).'/private/config/aiven-ca.pem'),
    'http_ca' => env('AIR_HTTP_CA_PATH', dirname(base_path()).'/private/config/http-ca-bundle.pem'),
    'private_path' => env('AIR_PRIVATE_PATH', dirname(base_path()).'/private'),
    'sources' => dirname(base_path()).'/private/sources',
];
