<?php

return [
    'ai' => [
        'key' => env('ACCOMPLISHMENT_AI_API_KEY'),
        'model' => env('ACCOMPLISHMENT_AI_MODEL', 'gpt-4.1-mini'),
        'endpoint' => env('ACCOMPLISHMENT_AI_ENDPOINT', 'https://api.openai.com/v1'),
        'temperature' => env('ACCOMPLISHMENT_AI_TEMPERATURE', 0.2),
    ],
];
