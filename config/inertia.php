<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Die Seiten dieses Projekts liegen nicht unter dem Standardpfad
    | resources/js/pages, sondern im Grundgerüst unter resources/js/shell/pages.
    | `ensure_pages_exist` lässt einen falsch geschriebenen Seitennamen sofort
    | auffallen — beim Rendern wie in assertInertia().
    |
    */

    'pages' => [

        'ensure_pages_exist' => true,

        'paths' => [

            resource_path('js/shell/pages'),

        ],

        'extensions' => [

            'jsx',

        ],

    ],
];
