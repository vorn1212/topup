<?php

/*
| Sign in with Google. All three values come from .env only.
| The button appears on the login and register pages once the client id and secret are filled in.
*/
return [

    'client_id' => env('GOOGLE_CLIENT_ID'),

    'client_secret' => env('GOOGLE_CLIENT_SECRET'),

    // Must be exactly the "Authorized redirect URI" you entered at Google, for example
    // http://127.0.0.1:8000/auth/google/callback  (leave empty to use the site's own callback address).
    'redirect' => env('GOOGLE_REDIRECT_URI'),

];