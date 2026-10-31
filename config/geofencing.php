<?php

return [
    /*
    | Default and ceiling for factory geofence boundary counts.
    | Per-factory max_boundaries is stored on team_pulse.modules — never hard-code 5 in controllers.
    */
    'default_max_boundaries' => (int) env('GEOFENCING_DEFAULT_MAX_BOUNDARIES', 5),
    'absolute_max_boundaries' => (int) env('GEOFENCING_ABSOLUTE_MAX_BOUNDARIES', 20),
];
