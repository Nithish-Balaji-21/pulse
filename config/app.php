<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'CoiioT'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'https://portal.coiiot.in'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | | Date and Time Formats |
    |--------------------------------------------------------------------------
     */

    'date_format' => env('DATE_FORMAT', 'd/m/Y'),
    'date_format_js' => env('DATE_FORMAT_JS', 'DD/MM/YYYY'),
    'time_format' => env('TIME_FORMAT', 'h:i a'),
    'date_time_format' => env('DATE_TIME_FORMAT', 'd/m/Y h:i a'),
    'time_seconds_format' => env('TIME_FORMAT_SECONDS', 'h:i:s'),
    'day_date_only_format' => env('DAY_DATE_ONLY_FORMAT', 'D, M j'),
    'day_only_format' => env('DAY_ONLY_FORMAT', 'D'),

    // When the nightly devicedata:* commands dispatch, as `H:i`. `system:check_errors`
    // derives its own earliest useful slot from this, so the two cannot drift apart and
    // leave the checker reporting commands as missed before they were ever due.
    'daily_device_cron_at' => env('DAILY_DEVICE_CRON_AT', '00:10'),

    // Slack allowed for the scheduler to fire those commands and for them to record the
    // dispatch before a missing `cron_runs` row counts as a missed run.
    'daily_device_cron_start_grace_mins' => (int) env('DAILY_DEVICE_CRON_START_GRACE_MINS', 15),

    'daily_pith_cron_interval_mins' => (int) env('DAILY_PITH_CRON_INTERVAL_MINS', 15),
    'daily_fibre_cron_interval_mins' => (int) env('DAILY_FIBRE_CRON_INTERVAL_MINS', 15),
    'daily_sub_modules_cron_interval_mins' => (int) env('DAILY_SUB_MODULES_CRON_INTERVAL_MINS', 15),

    'daily_husks_cron_interval_mins' => (int) env('DAILY_HUSKS_CRON_INTERVAL_MINS', 35),

    // Gap between one team's dispatch and the next within a single device-data command,
    // so teams sharing a shift anchor don't process concurrently and deadlock each other.
    // Jobs finish in single-digit seconds, so this only has to clear one job.
    'team_dispatch_stagger_secs' => (int) env('TEAM_DISPATCH_STAGGER_SECS', 30),

    // How far past its buffer the last team may be pushed. Offsets wrap at this point
    // instead of growing with the team count, so no factory waits on a long queue.
    'team_dispatch_window_mins' => (int) env('TEAM_DISPATCH_WINDOW_MINS', 30),

    // for weather
    'tomorrow_io' => [
        'api_key' => env('TOMORROW_IO_API_KEY'),
    ],

    // for google maps coords
    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    // for water consumption
    'water_consumption' => [
        'total' => env('WATER_TOTAL_FIELD_ID', 'd27'),
        'd16' => env('WATER_D16_FIELD_ID', 'd16'),
        'daily_consumption_limit' => env('WATER_METER_DAILY_LIMIT', 100000),
        'hourly_consumption_limit' => env('WATER_METER_HOURLY_LIMIT', 6000),
    ],

    // for pith blocks production
    'pith_blocks' => [
        'daily_production_limit' => env('PITH_BLOCKS_DAILY_LIMIT', 5000),
        'hourly_production_limit' => env('PITH_BLOCKS_HOURLY_LIMIT', 250),
    ],

    // for mini bale production (pith module submodule)
    'minibales' => [
        'daily_production_limit' => env('MINIBALES_DAILY_LIMIT', 1000),
        'hourly_production_limit' => env('MINIBALES_HOURLY_LIMIT', 50),
    ],

    // for grow bags production
    'growbags' => [
        'daily_production_limit' => env('GROWBAGS_DAILY_LIMIT', 10000),
        'hourly_production_limit' => env('GROWBAGS_HOURLY_LIMIT', 1000),
        'daily_cron_interval_mins' => (int) env('DAILY_GROWBAGS_CRON_INTERVAL_MINS', 15),
    ],

    // for water tank levels
    'water_tank' => [
        'oht' => env('WATER_TANK_FIELD_ID_OHT', 'd30'),
        'ug' => env('WATER_TANK_FIELD_ID_UG', 'd29'),
    ],

];
