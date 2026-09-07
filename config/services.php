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
    | - Zoom: meeting links are pasted by hand in this release. No OAuth app,
    |   no API credentials, no meeting lifecycle. The `zoom_url` column on
    |   `sessions` is the whole integration.
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
