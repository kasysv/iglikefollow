<?php

return [
    'instagram_followers' => [
        'name' => 'Instagram 粉絲服務',
        'mock_only' => true,
        'plans' => [
            'followers-500' => [
                'label' => '500 位粉絲',
                'quantity' => 500,
                'price' => 320,
                'currency' => 'TWD',
            ],
            'followers-1000' => [
                'label' => '1,000 位粉絲',
                'quantity' => 1000,
                'price' => 590,
                'currency' => 'TWD',
                'featured' => true,
            ],
            'followers-2500' => [
                'label' => '2,500 位粉絲',
                'quantity' => 2500,
                'price' => 1290,
                'currency' => 'TWD',
            ],
        ],
    ],
];
