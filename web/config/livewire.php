<?php

return [
    // Short-lived parser/upload buffers are local; committed operational files always use PrivateFiles/cloud.
    'temporary_file_upload' => [
        'disk' => env('AIR_UPLOAD_DISK', 'local'),
        'rules' => 'required|file|max:'.env('AIR_UPLOAD_MAX_KB', 61440),
        'directory' => 'livewire-tmp',
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
