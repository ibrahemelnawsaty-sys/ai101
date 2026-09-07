<?php

/**
 * Password broker statuses. `sent` and `user` are deliberately identical: the
 * reply to a reset request never reveals whether the address is registered
 * (BR-30).
 *
 * @see BR-30 · PRD §9.3.3
 */

return [

    'reset' => 'Your password has been changed. Sign in with the new one.',
    'sent' => 'If that address is registered with us, a message will arrive within a few minutes.',
    'throttled' => 'A link was sent a moment ago. Wait a little before requesting another.',
    'token' => 'This reset link is no longer valid. Request a new one.',
    'token_invalid' => 'This reset link is no longer valid. Request a new one.',
    'user' => 'If that address is registered with us, a message will arrive within a few minutes.',

];
