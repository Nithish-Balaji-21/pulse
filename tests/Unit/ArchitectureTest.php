<?php

arch('models stay independent of the HTTP and Livewire layers')
    ->expect('App\Models')
    ->not->toUse(['App\Http\Controllers', 'Livewire']);

arch('IoT device-data processing jobs stay independent of the HTTP layer')
    ->expect('App\Jobs\Pulse')
    ->not->toUse('App\Http\Controllers');

arch('no debug statements are left in application code')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);
