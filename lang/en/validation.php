<?php

/**
 * The Laravel validation rule set in English, plus the per-field overrides and
 * the display name of every form field. Mirrors lang/ar/validation.php key for
 * key; the Arabic file is the reference, and PRD §9.2.1 fixes its wording.
 *
 * @see BR-12, BR-13, BR-30 · PRD §9.2.1, §9.11.2, §9.15.4, §11
 */

return [

    'accepted' => 'The :attribute must be accepted to continue.',
    'accepted_if' => 'The :attribute must be accepted when :other is :value.',
    'active_url' => 'The :attribute is not a valid URL.',
    'after' => 'The :attribute must be a date after :date.',
    'after_or_equal' => 'The :attribute must be :date or later.',
    'alpha' => 'The :attribute may only contain letters.',
    'alpha_dash' => 'The :attribute may only contain letters, numbers and dashes.',
    'alpha_num' => 'The :attribute may only contain letters and numbers.',
    'any_of' => 'The :attribute is not valid.',
    'array' => 'The :attribute must be a list of items.',
    'ascii' => 'The :attribute may only contain Latin letters, numbers and symbols.',
    'before' => 'The :attribute must be a date before :date.',
    'before_or_equal' => 'The :attribute must be :date or earlier.',

    'between' => [
        'array' => 'The :attribute must have between :min and :max items.',
        'file' => 'The :attribute must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute must be between :min and :max.',
        'string' => 'The :attribute must be between :min and :max characters.',
    ],

    'boolean' => 'The :attribute must be yes or no.',
    'can' => 'The :attribute contains a value that is not allowed.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'contains' => 'The :attribute is missing a required value.',
    'current_password' => 'The current password is not correct.',
    'date' => 'The :attribute is not a valid date.',
    'date_equals' => 'The :attribute must be the date :date.',
    'date_format' => 'The :attribute does not match the format :format.',
    'decimal' => 'The :attribute must have :decimal decimal places.',
    'declined' => 'The :attribute must be declined.',
    'declined_if' => 'The :attribute must be declined when :other is :value.',
    'different' => 'The :attribute and :other must be different.',
    'digits' => 'The :attribute must be :digits digits.',
    'digits_between' => 'The :attribute must be between :min and :max digits.',
    'dimensions' => 'The :attribute has invalid image dimensions.',
    'distinct' => 'The :attribute has a duplicate value.',
    'doesnt_end_with' => 'The :attribute may not end with one of: :values.',
    'doesnt_start_with' => 'The :attribute may not start with one of: :values.',
    'email' => 'That email address is not in a valid format.',
    'ends_with' => 'The :attribute must end with one of: :values.',
    'enum' => 'The selected :attribute is not valid.',
    'exists' => 'The selected :attribute does not exist.',
    'extensions' => 'The :attribute must be one of these formats: :values.',
    'file' => 'The :attribute must be a file.',
    'filled' => 'The :attribute cannot be empty.',

    'gt' => [
        'array' => 'The :attribute must have more than :value items.',
        'file' => 'The :attribute must be larger than :value kilobytes.',
        'numeric' => 'The :attribute must be greater than :value.',
        'string' => 'The :attribute must be longer than :value characters.',
    ],

    'gte' => [
        'array' => 'The :attribute must have at least :value items.',
        'file' => 'The :attribute must be at least :value kilobytes.',
        'numeric' => 'The :attribute must be at least :value.',
        'string' => 'The :attribute must be at least :value characters.',
    ],

    'hex_color' => 'The :attribute must be a valid hexadecimal colour.',
    'image' => 'The :attribute must be an image.',
    'in' => 'The selected :attribute is not valid.',
    'in_array' => 'The :attribute does not exist in :other.',
    'integer' => 'The :attribute must be a whole number.',
    'ip' => 'The :attribute must be a valid IP address.',
    'ipv4' => 'The :attribute must be a valid IPv4 address.',
    'ipv6' => 'The :attribute must be a valid IPv6 address.',
    'json' => 'The :attribute must be valid JSON.',
    'lowercase' => 'The :attribute must be lowercase.',

    'lt' => [
        'array' => 'The :attribute must have fewer than :value items.',
        'file' => 'The :attribute must be smaller than :value kilobytes.',
        'numeric' => 'The :attribute must be less than :value.',
        'string' => 'The :attribute must be shorter than :value characters.',
    ],

    'lte' => [
        'array' => 'The :attribute may not have more than :value items.',
        'file' => 'The :attribute may not be larger than :value kilobytes.',
        'numeric' => 'The :attribute may not be greater than :value.',
        'string' => 'The :attribute may not be longer than :value characters.',
    ],

    'mac_address' => 'The :attribute must be a valid MAC address.',

    'max' => [
        'array' => 'The :attribute may not have more than :max items.',
        'file' => 'The :attribute is larger than the :max kilobyte limit.',
        'numeric' => 'The :attribute may not be greater than :max.',
        'string' => 'The :attribute may not be longer than :max characters.',
    ],

    'max_digits' => 'The :attribute may not have more than :max digits.',
    'mimes' => 'This file format is not allowed, for security reasons.',
    'mimetypes' => 'This file format is not allowed, for security reasons.',

    'min' => [
        'array' => 'The :attribute must have at least :min items.',
        'file' => 'The :attribute must be at least :min kilobytes.',
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must be at least :min characters.',
    ],

    'min_digits' => 'The :attribute must have at least :min digits.',
    'missing' => 'The :attribute field must not be sent.',
    'missing_if' => 'The :attribute field must not be sent when :other is :value.',
    'missing_unless' => 'The :attribute field must not be sent unless :other is :value.',
    'missing_with' => 'The :attribute field must not be sent with :values.',
    'missing_with_all' => 'The :attribute field must not be sent with :values.',
    'multiple_of' => 'The :attribute must be a multiple of :value.',
    'not_in' => 'The selected :attribute is not valid.',
    'not_regex' => 'The :attribute format is not valid.',
    'numeric' => 'The :attribute must be a number.',

    'password' => [
        'letters' => 'The password must contain at least one letter.',
        'mixed' => 'The password must contain an uppercase and a lowercase letter.',
        'numbers' => 'The password must contain at least one digit.',
        'symbols' => 'The password must contain at least one symbol.',
        'uncompromised' => 'That password is very common. Choose one that is harder to guess.',
    ],

    'present' => 'The :attribute field must be sent.',
    'present_if' => 'The :attribute field must be sent when :other is :value.',
    'present_unless' => 'The :attribute field must be sent unless :other is :value.',
    'present_with' => 'The :attribute field must be sent with :values.',
    'present_with_all' => 'The :attribute field must be sent with :values.',
    'prohibited' => 'The :attribute field is not allowed here.',
    'prohibited_if' => 'The :attribute field is not allowed when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is not allowed when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is not allowed when :other is declined.',
    'prohibited_unless' => 'The :attribute field is not allowed unless :other is in :values.',
    'prohibits' => 'The :attribute field prevents :other from being sent.',
    'regex' => 'The :attribute format is not valid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute must contain the keys: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required with :values.',
    'required_with_all' => 'The :attribute field is required with :values.',
    'required_without' => 'The :attribute field is required when :values is absent.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute and :other must match.',

    'size' => [
        'array' => 'The :attribute must contain exactly :size items.',
        'file' => 'The :attribute must be :size kilobytes.',
        'numeric' => 'The :attribute must be :size.',
        'string' => 'The :attribute must be :size characters.',
    ],

    'starts_with' => 'The :attribute must start with one of: :values.',
    'string' => 'The :attribute must be text.',
    'timezone' => 'The :attribute must be a valid time zone.',
    'ulid' => 'The :attribute must be a valid ULID.',
    'unique' => 'That :attribute is already in use.',
    'uploaded' => 'The :attribute could not be uploaded. Check the file size and try again.',
    'uppercase' => 'The :attribute must be uppercase.',
    'url' => 'The :attribute must be a valid URL.',
    'uuid' => 'The :attribute must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Per-field overrides
    |--------------------------------------------------------------------------
    */

    'custom' => [

        'names' => [
            'arabic' => 'Write the :field in Arabic letters only, with no digits or symbols.',
            'latin' => 'Write the :field in Latin letters only, with no digits or symbols.',
            'length' => 'Each part of the name is between two and twenty characters.',
        ],

        'first_name_ar' => [
            'required' => 'Please enter the first name in Arabic',
            'regex' => 'Please enter the first name in Arabic',
            'min' => 'Please enter the first name in Arabic',
            'max' => 'Please enter the first name in Arabic',
        ],
        'father_name_ar' => [
            'required' => 'Please enter the father name in Arabic',
            'regex' => 'Please enter the father name in Arabic',
            'min' => 'Please enter the father name in Arabic',
            'max' => 'Please enter the father name in Arabic',
        ],
        'grandfather_name_ar' => [
            'required' => 'Please enter the grandfather name in Arabic',
            'regex' => 'Please enter the grandfather name in Arabic',
            'min' => 'Please enter the grandfather name in Arabic',
            'max' => 'Please enter the grandfather name in Arabic',
        ],
        'family_name_ar' => [
            'required' => 'Please enter the family name in Arabic',
            'regex' => 'Please enter the family name in Arabic',
            'min' => 'Please enter the family name in Arabic',
            'max' => 'Please enter the family name in Arabic',
        ],
        'first_name_en' => [
            'required' => 'Please enter the first name in English',
            'regex' => 'Please enter the first name in English',
            'min' => 'Please enter the first name in English',
            'max' => 'Please enter the first name in English',
        ],
        'father_name_en' => [
            'required' => 'Please enter the father name in English',
            'regex' => 'Please enter the father name in English',
            'min' => 'Please enter the father name in English',
            'max' => 'Please enter the father name in English',
        ],
        'grandfather_name_en' => [
            'required' => 'Please enter the grandfather name in English',
            'regex' => 'Please enter the grandfather name in English',
            'min' => 'Please enter the grandfather name in English',
            'max' => 'Please enter the grandfather name in English',
        ],
        'family_name_en' => [
            'required' => 'Please enter the family name in English',
            'regex' => 'Please enter the family name in English',
            'min' => 'Please enter the family name in English',
            'max' => 'Please enter the family name in English',
        ],

        'phone' => [
            'required' => 'That mobile number is not valid. Example: 0512345678',
            'regex' => 'That mobile number is not valid. Example: 0512345678',
            'unique' => 'That number is already registered to another account.',
            'format' => 'That mobile number is not valid. Example: 0512345678',
            'taken' => 'That number is already registered to another account.',
        ],

        'email' => [
            'required' => 'That email address is not in a valid format',
            'email' => 'That email address is not in a valid format',
            'unique' => 'That email is already registered. Would you like to sign in?',
            'format' => 'That email address is not in a valid format',
            'taken' => 'That email is already registered. Would you like to sign in?',
            'mismatch' => 'The email addresses do not match',
        ],

        'email_confirmation' => [
            'required' => 'The email addresses do not match',
            'same' => 'The email addresses do not match',
        ],

        'gender' => [
            'required' => 'Please choose a gender',
            'in' => 'Please choose a gender',
            'enum' => 'Please choose a gender',
        ],

        'password' => [
            'required' => 'The password does not meet the requirements',
            'min' => 'The password does not meet the requirements',
            'regex' => 'The password does not meet the requirements',
            'confirmed' => 'The passwords do not match',
            'mismatch' => 'The passwords do not match',
            'common' => 'That password is very common and easy to guess. Choose another one.',
            'current_wrong' => 'The current password is not correct.',
            'reused' => 'Choose a password that differs from your current one.',
        ],

        'password_confirmation' => [
            'required' => 'The passwords do not match',
            'same' => 'The passwords do not match',
        ],

        'terms' => [
            'accepted' => 'You must agree to the Terms and the Privacy Policy',
            'required' => 'You must agree to the Terms and the Privacy Policy',
        ],

        'terms_accepted' => [
            'accepted' => 'You must agree to the Terms and the Privacy Policy',
            'required' => 'You must agree to the Terms and the Privacy Policy',
        ],

        'github_url' => [
            'starts_with' => 'A GitHub link must start with https://github.com/',
            'url' => 'That GitHub link is not valid. Make sure you copied it in full.',
        ],

        'files' => [
            'max' => 'That is more than the :max files allowed.',
            'required_without' => 'Attach at least one file, or add a GitHub link.',
        ],

        'files.*' => [
            'max' => 'The file is larger than the :max kilobyte limit.',
            'mimes' => 'This file format is not allowed, for security reasons.',
            'mimetypes' => 'This file format is not allowed, for security reasons.',
        ],

        'avatar' => [
            'image' => 'The profile photo must be an image in JPG, PNG or WebP.',
            'mimes' => 'The profile photo must be in JPG, PNG or WebP.',
            'max' => 'The photo is larger than the :max kilobyte limit.',
        ],

        'score' => [
            'required' => 'Enter the score before saving.',
            'numeric' => 'The score must be a number, and may have one decimal place.',
            'min' => 'The score cannot be below zero.',
            'max' => 'The score cannot exceed the maximum for this item, which is :max.',
        ],

        'feedback' => [
            'required' => 'Feedback is required — a score cannot be recorded without it.',
            'min' => 'Feedback must be at least :min characters. Say what went well and what needs work.',
        ],

        'revision_reason' => [
            'required' => 'Write why you are revising the score. The reason is written to the audit log.',
            'min' => 'The reason must be at least :min characters.',
        ],

        'edit_reason' => [
            'required' => 'Write why you are changing the attendance record. The reason is written to the audit log.',
            'min' => 'The reason must be at least :min characters.',
        ],

        'cancel_reason' => [
            'required' => 'Write why the session is cancelled. Participants see the reason with the notification.',
            'min' => 'The reason must be at least :min characters.',
        ],

        'override_reason' => [
            'required' => 'Write why you are overriding the certificate conditions. Issue is impossible without it.',
            'min' => 'The reason must be at least :min characters.',
        ],

        'reject_reason' => [
            'required' => 'Write why the request is declined. The applicant receives the reason.',
            'min' => 'The reason must be at least :min characters.',
        ],

        'current_password' => [
            'required' => 'Enter your current password so we know it is you.',
            'current_password' => 'The current password is not correct.',
        ],

        'end_time' => [
            'after' => 'The session end time must be after its start time.',
        ],

        'ends_at' => [
            'after' => 'The cohort end date must be after its start date.',
        ],

        'pass_score' => [
            'min' => 'The pass mark cannot be below zero.',
            'max' => 'The pass mark cannot exceed :max.',
        ],

        'min_attendance_rate' => [
            'min' => 'The minimum attendance rate cannot be below zero.',
            'max' => 'The minimum attendance rate cannot exceed 100.',
        ],

        'capacity' => [
            'min' => 'The cohort capacity cannot be below one seat.',
        ],

        'body' => [
            'required' => 'Write something or attach a file before sending.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display names for every form field
    |--------------------------------------------------------------------------
    */

    'attributes' => [

        // Identity
        'first_name_ar' => 'first name in Arabic',
        'father_name_ar' => 'father name in Arabic',
        'grandfather_name_ar' => 'grandfather name in Arabic',
        'family_name_ar' => 'family name in Arabic',
        'first_name_en' => 'first name in English',
        'father_name_en' => 'father name in English',
        'grandfather_name_en' => 'grandfather name in English',
        'family_name_en' => 'family name in English',
        'gender' => 'gender',
        'birth_date' => 'date of birth',
        'city' => 'city',
        'education_level' => 'education level',
        'bio' => 'bio',
        'avatar' => 'profile photo',
        'locale' => 'interface language',

        // Contact and credentials
        'email' => 'email address',
        'email_confirmation' => 'email confirmation',
        'new_email' => 'new email address',
        'phone' => 'mobile number',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'remember' => 'remember me',
        'terms' => 'agreement to the Terms and the Privacy Policy',
        'token' => 'verification token',

        // Programmes and cohorts
        'name' => 'name',
        'slug' => 'URL identifier',
        'summary' => 'summary',
        'objectives' => 'objectives',
        'target_audience' => 'target audience',
        'certificates' => 'certificates awarded',
        'hours' => 'training hours',
        'status' => 'status',
        'starts_at' => 'start date',
        'ends_at' => 'end date',
        'capacity' => 'capacity',
        'registration_closes_at' => 'registration closing date',
        'pass_score' => 'pass mark',
        'min_attendance_rate' => 'minimum attendance rate',
        'requires_approval' => 'approval requirement',
        'trainers' => 'trainers',
        'cohort_id' => 'cohort',
        'program_id' => 'programme',

        // Weeks and sessions
        'week_id' => 'week',
        'index' => 'order',
        'title' => 'title',
        'topic' => 'topic',
        'type' => 'type',
        'date' => 'date',
        'start_time' => 'start time',
        'end_time' => 'end time',
        'trainer_id' => 'trainer',
        'zoom_url' => 'meeting link',
        'zoom_password' => 'meeting passcode',
        'recording_url' => 'recording link',
        'cancel_reason' => 'reason for cancelling',
        'replacement_at' => 'replacement date',

        // Attendance
        'session_id' => 'session',
        'attendance_status' => 'attendance status',
        'checked_in_at' => 'check-in time',
        'checked_out_at' => 'check-out time',
        'edit_reason' => 'reason for the change',
        'note' => 'note',

        // Assignments and submissions
        'description' => 'description',
        'requirements' => 'requirements',
        'max_score' => 'maximum score',
        'due_at' => 'deadline',
        'is_mandatory' => 'mandatory assignment',
        'allow_late' => 'allow late submission',
        'show_github' => 'show the GitHub link field',
        'max_files' => 'maximum number of files',
        'max_file_size' => 'maximum file size',
        'files' => 'files',
        'files.*' => 'file',
        'attachments' => 'attachments',
        'github_url' => 'GitHub link',
        'assignment_id' => 'assignment',

        // Evaluation
        'score' => 'score',
        'feedback' => 'trainer feedback',
        'revision_reason' => 'reason for revising the score',

        // Resources
        'resource_type' => 'resource type',
        'url' => 'link',
        'file' => 'file',

        // Messaging
        'body' => 'message text',
        'attachment' => 'attachment',
        'thread_id' => 'conversation',
        'report_reason' => 'reason for reporting',

        // Certificates
        'serial_number' => 'serial number',
        'verify_code' => 'verification code',
        'override_reason' => 'reason for the override',
        'revoke_reason' => 'reason for revoking',

        // Admin
        'role' => 'role',
        'user_id' => 'user',
        'reject_reason' => 'reason for declining',
        'seats_remaining' => 'seats remaining',
        'is_registration_open' => 'registration state',
        'faq' => 'frequently asked questions',
        'question' => 'question',
        'answer' => 'answer',
        'timezone' => 'time zone',

        // Assignment authoring
        'terms_accepted' => 'agreement to the Terms and the Privacy Policy',
        'show_github_field' => 'show the GitHub link field',
        'max_file_kilobytes' => 'maximum file size',
        'reason' => 'reason',

        // Notification preferences
        'session_reminders' => 'session reminders',
        'assignment_reminders' => 'assignment reminders',
        'grade_updates' => 'grade updates',
        'new_messages' => 'new messages',
        'new_resources' => 'new resources',
        'in_app_enabled' => 'in-platform notification',
        'email_enabled' => 'email notification',

        // Rejected client-sent instants (BR-07)
        'at' => 'submitted time',
        'time' => 'submitted time',
        'timestamp' => 'submitted time',
        'client_time' => 'your device clock',
    ],

];
