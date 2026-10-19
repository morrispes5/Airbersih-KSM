<?php

return [
    // Short-lived parser/upload buffers are local; committed operational files always use PrivateFiles/cloud.
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => 'required|file|max:61440',
        'directory' => 'livewire-tmp',
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
