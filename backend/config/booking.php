<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Abandoned online-deposit TTL
    |--------------------------------------------------------------------------
    |
    | How long (in minutes) a booking may hold its slot with an unpaid online
    | deposit before bookings:release-abandoned cancels it. A gateway checkout
    | that is never completed would otherwise block the time indefinitely.
    |
    */

    'gateway_pending_ttl_minutes' => (int) env('GATEWAY_PENDING_TTL_MINUTES', 30),

];
