<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Persistent media root
    |--------------------------------------------------------------------------
    |
    | Keep customer-uploaded media outside the deployable backend directory.
    | On cPanel set MEDIA_ROOT to an absolute persistent path, for example:
    | /home/CPANEL_USER/angelita_media
    |
    */
    'root' => env('MEDIA_ROOT', base_path('../angelita_media')),

    /*
    |--------------------------------------------------------------------------
    | Public API prefix
    |--------------------------------------------------------------------------
    */
    'url_prefix' => env('MEDIA_URL_PREFIX', '/api/media'),
];
