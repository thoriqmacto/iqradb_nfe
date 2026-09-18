<?php

return [

    // API-only backend: Next.js renders every pixel, so Laravel has no views
    // of its own. An empty path list is what keeps `view:cache` (and the
    // `optimize` command that calls it) from failing with
    // "The .../resources/views directory does not exist".
    //
    // Mail still renders: the password-reset and email-verification templates
    // resolve through the `mail::` namespace hints, not through these paths.
    //
    // Need a Blade view later? Create resources/views and put the path back:
    //     'paths' => [resource_path('views')],
    'paths' => [],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
