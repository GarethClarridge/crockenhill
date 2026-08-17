<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API project. This is used optionally in
    | situations where you are using a legacy user API key and need association
    | with a project. This is not required for the newer API keys.
    */
    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Base URL
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API base URL used to make requests. This
    | is needed if using a custom API endpoint. Defaults to: api.openai.com/v1
    */
    'base_uri' => env('OPENAI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Processing Tier
    |--------------------------------------------------------------------------
    |
    | Flex is appropriate for these asynchronous media-processing requests: it
    | trades response time and occasional unavailability for Batch API pricing.
    |
    */

    'service_tier' => env('OPENAI_SERVICE_TIER', 'flex'),

    /*
    |--------------------------------------------------------------------------
    | Evaluation Arm
    |--------------------------------------------------------------------------
    |
    | Free-text label stamped on every usage log line. Null in normal operation;
    | set only for an isolated model/effort comparison, where it is the sole way
    | to attribute spend to an arm — two arms of the same model that differ only
    | in reasoning effort are otherwise identical in the log.
    |
    */

    'evaluation_arm' => env('OPENAI_EVALUATION_ARM'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. Flex requests are allowed the recommended 15 minutes.
    */

    // Cast to int: env() returns the raw string (e.g. "900"), and the OpenAI client passes
    // this straight to Guzzle's "timeout" request option, which deprecates non-int|float values.
    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 900),
];
