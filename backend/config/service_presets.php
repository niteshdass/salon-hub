<?php

/**
 * Starter menus offered on the second wizard screen, one per salon type.
 *
 * Durations only. Price varies by city and currency, is the one value the
 * system cannot guess, and is therefore the only field the owner is
 * required to type on that screen.
 *
 * Config rather than a frontend constant so the wording and durations can
 * change without rebuilding the SPA.
 */
return [
    [
        'key' => 'hair',
        'label' => 'Hair salon',
        'services' => [
            ['name' => 'Hair cut', 'duration' => 30],
            ['name' => 'Hair wash & blow dry', 'duration' => 30],
            ['name' => 'Hair colour', 'duration' => 90],
            ['name' => 'Highlights', 'duration' => 120],
            ['name' => 'Hair spa', 'duration' => 60],
            ['name' => 'Straightening', 'duration' => 120],
            ['name' => 'Trim', 'duration' => 20],
            ['name' => 'Kids cut', 'duration' => 20],
        ],
    ],
    [
        'key' => 'beauty',
        'label' => 'Beauty parlour',
        'services' => [
            ['name' => 'Facial', 'duration' => 60],
            ['name' => 'Threading', 'duration' => 15],
            ['name' => 'Waxing (full arms)', 'duration' => 30],
            ['name' => 'Waxing (full legs)', 'duration' => 45],
            ['name' => 'Manicure', 'duration' => 45],
            ['name' => 'Pedicure', 'duration' => 45],
            ['name' => 'Bridal makeup', 'duration' => 120],
            ['name' => 'Party makeup', 'duration' => 60],
        ],
    ],
    [
        'key' => 'barber',
        'label' => 'Barber',
        'services' => [
            ['name' => 'Hair cut', 'duration' => 30],
            ['name' => 'Beard trim', 'duration' => 15],
            ['name' => 'Shave', 'duration' => 20],
            ['name' => 'Hair cut & beard', 'duration' => 45],
            ['name' => 'Head massage', 'duration' => 20],
            ['name' => 'Hair colour', 'duration' => 45],
            ['name' => 'Kids cut', 'duration' => 20],
            ['name' => 'Face cleanup', 'duration' => 30],
        ],
    ],
    [
        'key' => 'spa',
        'label' => 'Spa',
        'services' => [
            ['name' => 'Full body massage', 'duration' => 60],
            ['name' => 'Head & shoulder massage', 'duration' => 30],
            ['name' => 'Aroma therapy', 'duration' => 90],
            ['name' => 'Body scrub', 'duration' => 60],
            ['name' => 'Foot massage', 'duration' => 30],
            ['name' => 'Steam & sauna', 'duration' => 45],
            ['name' => 'Couple massage', 'duration' => 90],
            ['name' => 'Back massage', 'duration' => 30],
        ],
    ],
    [
        'key' => 'nails',
        'label' => 'Nails',
        'services' => [
            ['name' => 'Manicure', 'duration' => 45],
            ['name' => 'Pedicure', 'duration' => 45],
            ['name' => 'Gel polish', 'duration' => 60],
            ['name' => 'Nail extension', 'duration' => 90],
            ['name' => 'Nail art', 'duration' => 45],
            ['name' => 'Polish change', 'duration' => 20],
            ['name' => 'Nail repair', 'duration' => 30],
            ['name' => 'French manicure', 'duration' => 60],
        ],
    ],
];
