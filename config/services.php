<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-party services
    |--------------------------------------------------------------------------
    |
    | Almost empty, and that is the accurate state of the platform rather than
    | an omission.
    |
    | - Video meetings: links are pasted by hand in this release, whichever
    |   platform the admin or coordinator picks (Zoom, Google Meet, Teams). No
    |   OAuth app, no API credentials, no meeting lifecycle for any of them.
    |   The `meeting_url`/`platform` columns on `sessions` are the whole
    |   integration.
    | - Bot protection on the public registration form is still an open
    |   decision; no vendor is wired in, so no keys are read here.
    | - Error tracking is the Laravel log plus `audit_logs`. No external
    |   collector receives trainee data.
    |
    | Nothing is added to this file speculatively: an unused credential in a
    | production .env is a liability, not a convenience.
    |
    */

];
