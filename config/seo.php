<?php

return [
    'indexing_enabled' => env('APP_ENV') === 'production'
        && filter_var(env('ALLOW_INDEXING', false), FILTER_VALIDATE_BOOL),
    'site_name' => 'IGLIKEFOLLOW',
];
