<?php

declare(strict_types=1);

/*
 * Authentication copy: registration · sign-in · sign-out · password reset ·
 * email verification. Mirrors lang/ar/auth.php key for key.
 * No message here reveals whether an address is registered (BR-30).
 *
 * @see BR-29, BR-30 · PRD §9.2, §9.3
 */

return [

    /* Laravel standard keys */
    'failed' => 'That email address or password is not correct.',
    'password' => 'That email address or password is not correct.',
    'throttle' => 'Too many sign-in attempts. Try again in :seconds seconds.',

    'shared' => [
        'brand_alt' => 'Athar Training Centre',
        'tagline' => 'From here the impact begins',
        'back_home' => 'Back to the home page',
        'email' => 'Email address',
        'email_placeholder' => 'name@example.com',
        'password' => 'Password',
        'show_password' => 'Show the password',
        'hide_password' => 'Hide the password',
        'processing' => 'Sending…',
        'required_hint' => 'Fields marked with an asterisk are required',
        'error_summary_title' => 'Check the following fields',
        'loading' => 'Loading the form',
        'error_title' => 'The form could not be shown',
        'error_body' => 'A temporary problem got in the way. Refresh the page in a moment, and contact us if it keeps happening.',
        'error_action' => 'Refresh the page',
    ],

    /* The fourth state is real here: the service is paused for maintenance. */
    'states' => [
        'empty_login_title' => 'Sign-in is paused',
        'empty_login_body' => 'We paused sign-in for a few minutes of short maintenance. Your account and your data are safe. Try again shortly.',
        'empty_register_title' => 'Registration is paused',
        'empty_register_body' => 'We paused new registrations for a few minutes of short maintenance. Try again shortly, or follow the home page.',
        'empty_password_title' => 'Password reset is paused',
        'empty_password_body' => 'We paused reset links for a few minutes of short maintenance. Try again shortly, or contact us if it is urgent.',
        'empty_verify_title' => 'Activation emails are paused',
        'empty_verify_body' => 'We paused activation emails for a few minutes of short maintenance. Your account is safe, and the link will arrive once the service is back.',
        'empty_action' => 'Back to the home page',
    ],

    'login' => [
        // see also the top-level `failed`, which Laravel resolves by name
        'title' => 'Sign in',
        'subtitle' => 'Enter your email and password to reach your dashboard.',
        'remember' => 'Remember me on this device',
        'remember_hint' => 'Your session stays open for thirty days instead of one.',
        'forgot' => 'Forgot your password?',
        'submit' => 'Sign in to my account',
        'no_account' => 'Do not have an account yet?',
        'go_register' => 'Register now',
        'unverified_title' => 'Your email is not verified yet',
        'unverified_body' => 'We sent an activation link when you registered. Open it to activate your account, or ask for a new link if you cannot find it.',
        'unverified_action' => 'Resend the activation link',
        'suspended_title' => 'This account is suspended',
        'suspended_body' => 'You cannot sign in with this account right now. To reach us and understand why:',
        'locked_title' => 'The account is locked for a while',
        'locked_body' => 'After five unsuccessful attempts, sign-in is locked for fifteen minutes to protect your account.',
        'locked_remaining' => 'Sign-in reopens in',
        'logged_out' => 'You have been signed out.',
        'failed' => 'That email address or password is not correct.',
    ],

    'register' => [
        'title' => 'Create a new account',
        'subtitle' => 'Three short steps, under three minutes.',
        'closed_title' => 'Registration is closed',
        'closed_body' => 'Registration for this cohort has closed. Follow the home page, or leave your email there and we will write to you as soon as the next cohort opens.',
        'closed_action' => 'Back to the home page',

        'progress_label' => 'Form progress',
        'step_of' => 'Step :current of :total',
        'step_1' => 'Name',
        'step_2' => 'Contact details',
        'step_3' => 'Password and consent',
        'step_1_hint' => 'Write your name as it appears on your ID — it is printed on the certificate.',
        'step_2_hint' => 'We use your email for activation and notifications, and your mobile when we need to reach you.',
        'step_3_hint' => 'A strong password protects your training record and your certificate.',

        'next' => 'Next',
        'back' => 'Back',
        'submit' => 'Create my account',

        'ar_names' => 'Name in Arabic',
        'en_names' => 'Name in English',
        'first_name_ar' => 'First name',
        'father_name_ar' => 'Father name',
        'grandfather_name_ar' => 'Grandfather name',
        'family_name_ar' => 'Family name',
        'first_name_en' => 'First name',
        'father_name_en' => 'Father name',
        'grandfather_name_en' => 'Grandfather name',
        'family_name_en' => 'Family name',
        'ph_first_ar' => 'محمد',
        'ph_father_ar' => 'عبدالله',
        'ph_grandfather_ar' => 'سعد',
        'ph_family_ar' => 'القحطاني',
        'ph_first_en' => 'Mohammed',
        'ph_father_en' => 'Abdullah',
        'ph_grandfather_en' => 'Saad',
        'ph_family_en' => 'Alqahtani',

        'phone' => 'Mobile number',
        'phone_placeholder' => '05XXXXXXXX',
        'phone_hint' => 'Starts with 05 and is ten digits long.',
        'email_confirm' => 'Confirm the email address',
        'email_confirm_placeholder' => 'Type your email address again',
        'email_confirm_hint' => 'Pasting is disabled here on purpose, so a typo cannot be repeated in both fields.',
        'gender' => 'Gender',
        'gender_male' => 'Male',
        'gender_female' => 'Female',

        'password_confirm' => 'Confirm the password',
        'strength_label' => 'Password strength',
        'strength_0' => 'Weak',
        'strength_1' => 'Fair',
        'strength_2' => 'Good',
        'strength_3' => 'Strong',
        'rule_length' => 'At least eight characters',
        'rule_upper' => 'An uppercase letter',
        'rule_lower' => 'A lowercase letter',
        'rule_digit' => 'At least one digit',
        'rule_symbol' => 'At least one symbol',
        'rules_title' => 'Password requirements',
        'rule_met' => 'Met',
        'rule_unmet' => 'Not met',

        'terms_accept_before' => 'I agree to the',
        'terms_accept_and' => 'and the',
        'terms_link' => 'Terms and Conditions',
        'privacy_link' => 'Privacy Policy',

        'have_account' => 'Already have an account?',
        'go_login' => 'Sign in',

        'check_your_inbox' => 'We created your account and sent the activation link to :email. Open it to activate your account.',

        'restored_title' => 'We brought your answers back',
        'restored_body' => 'We refilled the earlier steps with what you had written before the page reloaded. Check them and carry on.',
        'restored_dismiss' => 'Dismiss this notice',

        'duplicate_email' => 'That email is already registered. Would you like to sign in?',
        'duplicate_phone' => 'That number is already registered to another account.',

        // Browser-side hints only. The binding check runs on the server in a
        // FormRequest (CONSTITUTION art. 5).
        'errors' => [
            'required' => 'This field is required.',
            'name_ar' => 'Write this name in Arabic letters only, between two and twenty characters.',
            'name_en' => 'Write this name in Latin letters only, between two and twenty characters.',
            'phone' => 'That mobile number is not valid. Example: 0512345678',
            'email' => 'That email address is not in a valid format.',
            'email_confirm' => 'The email addresses do not match.',
            'gender' => 'Please choose a gender.',
            'password' => 'The password does not meet the requirements.',
            'password_confirm' => 'The passwords do not match.',
            'terms' => 'You must agree to the Terms and the Privacy Policy.',
            'step' => 'Check the marked fields in this step before moving on.',
        ],
    ],

    'verify' => [
        'title' => 'Your account has been created',
        'subtitle' => 'We sent the activation link to your email',
        'sent_to' => 'Sent to',
        'body' => 'Open the message and click the activation link. It is valid for twenty-four hours and works once.',
        'spam_hint' => 'No message yet? Check your spam folder before asking for a new link.',
        'resend' => 'Resend the activation link',
        'resend_wait' => 'You can resend in :seconds seconds',
        'resent' => 'We sent a fresh link to your email.',
        'pending_approval_title' => 'Your request is under review',
        'pending_approval_body' => 'This cohort requires approval before enrolment. We will email you as soon as your request is approved.',
        'logout' => 'Sign out',
        'link_invalid' => 'This activation link is not valid, or its 24 hours have passed. Ask for a new one and it will arrive within minutes.',
        'welcome' => 'Your account is active. Welcome to the programme.',
    ],

    'logout' => [
        'done' => 'You have been signed out.',
    ],

    'forgot' => [
        'title' => 'Reset your password',
        'subtitle' => 'Enter your email and we will send you a link to set a new password.',
        'submit' => 'Send the reset link',
        'sent' => 'If that address is registered with us, a message will arrive within a few minutes.',
        'sent_hint' => 'The link is valid for thirty minutes and works once.',
        'remembered' => 'Remembered your password?',
        'go_login' => 'Back to sign in',
    ],

    'reset' => [
        'title' => 'Set a new password',
        'subtitle' => 'Choose a strong password. This ends every open session on your other devices.',
        'new_password' => 'New password',
        'submit' => 'Save the new password',
        'invalid_title' => 'This link is no longer valid',
        'invalid_body' => 'A reset link works once and expires after thirty minutes. Ask for a new one and it will arrive within minutes.',
        'invalid_action' => 'Request a new link',
        'sessions_note' => 'After saving, your sessions on every device are closed and you will need to sign in again.',
    ],

];
