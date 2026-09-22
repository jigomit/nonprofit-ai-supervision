<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Open registration
    |--------------------------------------------------------------------------
    |
    | Closed unless a host asks for it. An account here can spend the
    | organization's own API key and read work waiting for a decision, so
    | pilot organizations are invited to a team rather than signing themselves
    | up. The route stays registered either way, so the frontend bundle does
    | not differ between environments — EnsureRegistrationIsOpen is what
    | actually refuses.
    |
    */

    'registration' => (bool) env('ALLOW_REGISTRATION', false),

];
