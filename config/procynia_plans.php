<?php

return [

    'free' => [
        'name' => 'Gratis',
        'monthly_price_nok' => 0,
        'yearly_price_nok' => 0,
        'included_users' => 1,
        'included_ai_credits' => 0,
        'features' => ['anbudssok', 'email_varsel'],
        'stripe_monthly' => null,
        'stripe_yearly' => null,
        'trial_days' => 0,
    ],

    'pro' => [
        'name' => 'Pro',
        'monthly_price_nok' => 990,
        'yearly_price_nok' => 7921,
        'included_users' => 1,
        'included_ai_credits' => 3,
        'features' => ['anbudssok', 'email_varsel', 'arbeidsomrade', 'rettighetsstyring', 'slack_teams'],
        'stripe_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
        'stripe_yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
        'trial_days' => 14,
    ],

    'max' => [
        'name' => 'Max',
        'monthly_price_nok' => 2990,
        'yearly_price_nok' => 23921,
        'included_users' => 5,
        'included_ai_credits' => 20,
        'features' => ['anbudssok', 'email_varsel', 'arbeidsomrade', 'rettighetsstyring',
            'slack_teams', 'flowcase', 'oppstartsmoete'],
        'stripe_monthly' => env('STRIPE_PRICE_MAX_MONTHLY'),
        'stripe_yearly' => env('STRIPE_PRICE_MAX_YEARLY'),
        'trial_days' => 14,
    ],

    'ultra' => [
        'name' => 'Ultra',
        'monthly_price_nok' => 6490,
        'yearly_price_nok' => 51921,
        'included_users' => 15,
        'included_ai_credits' => 60,
        'features' => ['anbudssok', 'email_varsel', 'arbeidsomrade', 'rettighetsstyring',
            'slack_teams', 'flowcase', 'oppstartsmoete', 'markedsinnsikt', 'prioritert_support'],
        'stripe_monthly' => env('STRIPE_PRICE_ULTRA_MONTHLY'),
        'stripe_yearly' => env('STRIPE_PRICE_ULTRA_YEARLY'),
        'trial_days' => 14,
    ],

    'enterprise' => [
        'name' => 'Enterprise',
        'monthly_price_nok' => null,
        'yearly_price_nok' => null,
        'included_users' => null,
        'included_ai_credits' => null,
        'features' => ['alt'],
        'stripe_monthly' => null,
        'stripe_yearly' => null,
        'trial_days' => 0,
    ],

];
