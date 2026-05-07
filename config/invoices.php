<?php

return [
    
    'duplicate_prevention_enabled' => env('INVOICE_DUPLICATE_PREVENTION', true),

   
    'duplicate_check_fields' => [
        'user_id',
        'service_provider_id',
        'title',
        'total_amount',
    ],

   
    'duplicate_check_time_window' => env('INVOICE_DUPLICATE_TIME_WINDOW', 30),

    
    'allow_duplicate_if_cancelled' => true,

    
    'allow_duplicate_if_paid' => false,
];

