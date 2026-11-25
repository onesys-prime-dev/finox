<?php

return [
    // Full path to mysqldump if it is not on the system PATH (used by finox:backup).
    // An empty MYSQLDUMP_PATH= (as in .env.example) falls back to mysqldump on PATH.
    'mysqldump_path' => env('MYSQLDUMP_PATH') ?: 'mysqldump',
];
