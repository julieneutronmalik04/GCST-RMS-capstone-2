<?php

// Paste your key between the empty quotes below, or set the server environment variable.
// This file is shared by Apache and the CLI checker; never expose the key in JavaScript.
if (!defined('SEMAPHORE_API_KEY')) define('SEMAPHORE_API_KEY', getenv('SEMAPHORE_API_KEY') ?: '');
$apiKey = SEMAPHORE_API_KEY;
return [
    'daily_penalty' => 10.00, // School policy: PHP 10 per started overdue day.
    'sms_driver' => getenv('SMS_DRIVER') ?: 'semaphore', // Explicit log mode is for isolated tests.
    'semaphore_api_key' => $apiKey,
    'semaphore_sender_name' => getenv('GCST-RMS') ?: '',
];
