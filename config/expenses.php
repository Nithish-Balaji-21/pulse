<?php

return [
    // Pulse consumes the Alam Cocos read API; company selection is fixed server-side.
    'url' => env('EXPENSES_APP_URL'),
    'api_key' => env('EXPENSES_API_KEY', ''),
];
