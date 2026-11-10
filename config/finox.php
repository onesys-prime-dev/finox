<?php

return [
    // Full path to mysqldump if it is not on the system PATH (used by finox:backup).
    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),
];
