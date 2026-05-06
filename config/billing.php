<?php

return [
    'plans' => [
        'monthly' => [
            'stripe_price_id' => env('STRIPE_PLAN_MONTHLY'),
            'label' => 'Månedlig',
            'interval' => 'month',
        ],
        'yearly' => [
            'stripe_price_id' => env('STRIPE_PLAN_YEARLY'),
            'label' => 'Årlig',
            'interval' => 'year',
        ],
    ],
];
