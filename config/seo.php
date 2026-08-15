<?php

return [
    'allow_indexing' => filter_var(env('ALLOW_INDEXING', false), FILTER_VALIDATE_BOOL),
    'indexable_host' => env('INDEXABLE_HOST', 'www.iglikefollow.com'),
    'site_name' => 'IGLIKEFOLLOW',
];
