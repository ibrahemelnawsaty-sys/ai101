<?php

/**
 * The complete Laravel validation rule set in Arabic, plus per-field overrides
 * quoted verbatim from PRD §9.2.1 and the display name of every form field.
 * Messages say what is wrong and what to do — never who is to blame.
 *
 * @see BR-12, BR-13, BR-30 · PRD §9.2.1, §9.11.2, §9.15.4, §11
 */

return [

    'accepted' => 'يجب قبول :attribute للمتابعة.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخ :date أو بعده.',
    'alpha' => 'يجب أن يحتوي :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'any_of' => ':attribute غير صحيح.',
    'array' => 'يجب أن يكون :attribute قائمة عناصر.',
    'ascii' => 'يجب أن يحتوي :attribute على حروف وأرقام ورموز لاتينية فقط.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخ :date أو قبله.',

    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد بين :min و :max من العناصر.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفًا.',
    ],

    'boolean' => 'يجب أن تكون قيمة :attribute نعم أو لا.',
    'can' => ':attribute يحتوي على قيمة غير مصرّح بها.',
    'confirmed' => 'حقل تأكيد :attribute غير مطابق.',
    'contains' => ':attribute ينقصه قيمة مطلوبة.',
    'current_password' => 'كلمة المرور الحالية غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute مساويًا لتاريخ :date.',
    'date_format' => 'صيغة :attribute لا تطابق :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits رقمًا.',
    'digits_between' => 'يجب أن يتكون :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد صورة :attribute غير مقبولة.',
    'distinct' => ':attribute مكرر في القائمة.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأحد التالي: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأحد التالي: :values.',
    'email' => 'صيغة البريد الإلكتروني غير صحيحة.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'enum' => 'القيمة المختارة في :attribute غير مقبولة.',
    'exists' => 'القيمة المختارة في :attribute غير موجودة.',
    'extensions' => 'يجب أن تكون صيغة :attribute إحدى التالي: :values.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'حقل :attribute لا يمكن أن يكون فارغًا.',

    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفًا.',
    ],

    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصرًا على الأقل.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت على الأقل.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :value.',
        'string' => 'يجب ألا يقل طول :attribute عن :value حرفًا.',
    ],

    'hex_color' => 'يجب أن يكون :attribute لونًا بصيغة ست عشرية صحيحة.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير مقبولة.',
    'in_array' => ':attribute غير موجود ضمن :other.',
    'integer' => 'يجب أن يكون :attribute رقمًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute نصًّا بصيغة JSON صحيحة.',
    'lowercase' => 'يجب أن يكون :attribute بأحرف صغيرة.',

    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفًا.',
    ],

    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :value كيلوبايت.',
        'numeric' => 'يجب ألا تتجاوز قيمة :attribute :value.',
        'string' => 'يجب ألا يتجاوز طول :attribute :value حرفًا.',
    ],

    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحًا.',

    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصرًا.',
        'file' => 'حجم :attribute يتجاوز الحد المسموح وهو :max كيلوبايت.',
        'numeric' => 'يجب ألا تتجاوز قيمة :attribute :max.',
        'string' => 'يجب ألا يتجاوز طول :attribute :max حرفًا.',
    ],

    'max_digits' => 'يجب ألا يتجاوز عدد أرقام :attribute :max رقمًا.',
    'mimes' => 'صيغة هذا الملف غير مسموح بها لأسباب أمنية.',
    'mimetypes' => 'صيغة هذا الملف غير مسموح بها لأسباب أمنية.',

    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصرًا على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفًا.',
    ],

    'min_digits' => 'يجب ألا يقل عدد أرقام :attribute عن :min رقمًا.',
    'missing' => 'يجب ألا يُرسل حقل :attribute.',
    'missing_if' => 'يجب ألا يُرسل حقل :attribute عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألا يُرسل حقل :attribute ما لم يكن :other هو :value.',
    'missing_with' => 'يجب ألا يُرسل حقل :attribute مع :values.',
    'missing_with_all' => 'يجب ألا يُرسل حقل :attribute مع :values.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير مقبولة.',
    'not_regex' => 'صيغة :attribute غير مقبولة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',

    'password' => [
        'letters' => 'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي كلمة المرور على حرف كبير وحرف صغير.',
        'numbers' => 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل.',
        'uncompromised' => 'كلمة المرور هذه شائعة جدًا. اختر كلمة أخرى أصعب على التخمين.',
    ],

    'present' => 'يجب إرسال حقل :attribute.',
    'present_if' => 'يجب إرسال حقل :attribute عندما يكون :other هو :value.',
    'present_unless' => 'يجب إرسال حقل :attribute ما لم يكن :other هو :value.',
    'present_with' => 'يجب إرسال حقل :attribute مع :values.',
    'present_with_all' => 'يجب إرسال حقل :attribute مع :values.',
    'prohibited' => 'حقل :attribute غير مسموح به هنا.',
    'prohibited_if' => 'حقل :attribute غير مسموح به عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute غير مسموح به عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute غير مسموح به عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute غير مسموح به ما لم يكن :other ضمن :values.',
    'prohibits' => 'حقل :attribute يمنع إرسال :other.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على المفاتيح: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب مع :values.',
    'required_with_all' => 'حقل :attribute مطلوب مع :values.',
    'required_without' => 'حقل :attribute مطلوب عند غياب :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند غياب :values جميعًا.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',

    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصرًا بالضبط.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفًا.',
    ],

    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصًّا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحًا.',
    'unique' => 'هذه القيمة مستخدمة مسبقًا في :attribute.',
    'uploaded' => 'تعذّر رفع :attribute. تحقق من حجم الملف ثم أعد المحاولة.',
    'uppercase' => 'يجب أن يكون :attribute بأحرف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحًا.',

    /*
    |--------------------------------------------------------------------------
    | Per-field overrides — PRD §9.2.1 wording is quoted verbatim
    |--------------------------------------------------------------------------
    */

    'custom' => [

        /*
         * Direct lookups used by FormRequest::messages(). They are addressed by
         * name — __('validation.custom.names.arabic') — not by attribute+rule,
         * so one sentence serves every field of that shape.
         */

        'names' => [
            'arabic' => 'اكتب :field بحروف عربية فقط، بلا أرقام ولا رموز.',
            'latin' => 'اكتب :field بحروف لاتينية فقط، بلا أرقام ولا رموز.',
            'length' => 'كل جزء من الاسم من حرفين إلى عشرين حرفًا.',
        ],

        'first_name_ar' => [
            'required' => 'الرجاء إدخال الاسم الأول بالعربية',
            'regex' => 'الرجاء إدخال الاسم الأول بالعربية',
            'min' => 'الرجاء إدخال الاسم الأول بالعربية',
            'max' => 'الرجاء إدخال الاسم الأول بالعربية',
        ],
        'father_name_ar' => [
            'required' => 'الرجاء إدخال اسم الأب بالعربية',
            'regex' => 'الرجاء إدخال اسم الأب بالعربية',
            'min' => 'الرجاء إدخال اسم الأب بالعربية',
            'max' => 'الرجاء إدخال اسم الأب بالعربية',
        ],
        'grandfather_name_ar' => [
            'required' => 'الرجاء إدخال اسم الجد بالعربية',
            'regex' => 'الرجاء إدخال اسم الجد بالعربية',
            'min' => 'الرجاء إدخال اسم الجد بالعربية',
            'max' => 'الرجاء إدخال اسم الجد بالعربية',
        ],
        'family_name_ar' => [
            'required' => 'الرجاء إدخال اسم العائلة بالعربية',
            'regex' => 'الرجاء إدخال اسم العائلة بالعربية',
            'min' => 'الرجاء إدخال اسم العائلة بالعربية',
            'max' => 'الرجاء إدخال اسم العائلة بالعربية',
        ],
        'first_name_en' => [
            'required' => 'الرجاء إدخال الاسم الأول بالإنجليزية',
            'regex' => 'الرجاء إدخال الاسم الأول بالإنجليزية',
            'min' => 'الرجاء إدخال الاسم الأول بالإنجليزية',
            'max' => 'الرجاء إدخال الاسم الأول بالإنجليزية',
        ],
        'father_name_en' => [
            'required' => 'الرجاء إدخال اسم الأب بالإنجليزية',
            'regex' => 'الرجاء إدخال اسم الأب بالإنجليزية',
            'min' => 'الرجاء إدخال اسم الأب بالإنجليزية',
            'max' => 'الرجاء إدخال اسم الأب بالإنجليزية',
        ],
        'grandfather_name_en' => [
            'required' => 'الرجاء إدخال اسم الجد بالإنجليزية',
            'regex' => 'الرجاء إدخال اسم الجد بالإنجليزية',
            'min' => 'الرجاء إدخال اسم الجد بالإنجليزية',
            'max' => 'الرجاء إدخال اسم الجد بالإنجليزية',
        ],
        'family_name_en' => [
            'required' => 'الرجاء إدخال اسم العائلة بالإنجليزية',
            'regex' => 'الرجاء إدخال اسم العائلة بالإنجليزية',
            'min' => 'الرجاء إدخال اسم العائلة بالإنجليزية',
            'max' => 'الرجاء إدخال اسم العائلة بالإنجليزية',
        ],

        'phone' => [
            'required' => 'رقم الجوال غير صحيح. مثال: 0512345678',
            'regex' => 'رقم الجوال غير صحيح. مثال: 0512345678',
            'unique' => 'هذا الرقم مسجّل مسبقًا لحساب آخر.',
            'format' => 'رقم الجوال غير صحيح. مثال: 0512345678',
            'taken' => 'هذا الرقم مسجّل مسبقًا لحساب آخر.',
        ],

        'email' => [
            'required' => 'صيغة البريد الإلكتروني غير صحيحة',
            'email' => 'صيغة البريد الإلكتروني غير صحيحة',
            'unique' => 'هذا البريد مسجّل مسبقًا. هل تريد تسجيل الدخول؟',
            'format' => 'صيغة البريد الإلكتروني غير صحيحة',
            'taken' => 'هذا البريد مسجّل مسبقًا. هل تريد تسجيل الدخول؟',
            'mismatch' => 'البريد الإلكتروني غير متطابق',
        ],

        'email_confirmation' => [
            'required' => 'البريد الإلكتروني غير متطابق',
            'same' => 'البريد الإلكتروني غير متطابق',
        ],

        'gender' => [
            'required' => 'الرجاء اختيار الجنس',
            'in' => 'الرجاء اختيار الجنس',
            'enum' => 'الرجاء اختيار الجنس',
        ],

        'password' => [
            'required' => 'كلمة المرور لا تستوفي الشروط المطلوبة',
            'min' => 'كلمة المرور لا تستوفي الشروط المطلوبة',
            'regex' => 'كلمة المرور لا تستوفي الشروط المطلوبة',
            'confirmed' => 'كلمتا المرور غير متطابقتين',
            'mismatch' => 'كلمتا المرور غير متطابقتين',
            'common' => 'كلمة المرور هذه شائعة جدًا ويسهل تخمينها. اختر كلمة أخرى.',
            'current_wrong' => 'كلمة المرور الحالية غير صحيحة.',
            'reused' => 'اختر كلمة مرور تختلف عن كلمتك الحالية.',
        ],

        'password_confirmation' => [
            'required' => 'كلمتا المرور غير متطابقتين',
            'same' => 'كلمتا المرور غير متطابقتين',
        ],

        'terms' => [
            'accepted' => 'يجب الموافقة على الشروط وسياسة الخصوصية',
            'required' => 'يجب الموافقة على الشروط وسياسة الخصوصية',
        ],

        'terms_accepted' => [
            'accepted' => 'يجب الموافقة على الشروط وسياسة الخصوصية',
            'required' => 'يجب الموافقة على الشروط وسياسة الخصوصية',
        ],

        'github_url' => [
            'starts_with' => 'رابط GitHub يجب أن يبدأ بـ https://github.com/',
            'url' => 'رابط GitHub غير صحيح. تأكد من نسخه كاملًا.',
        ],

        'files' => [
            'max' => 'عدد الملفات يتجاوز الحد المسموح وهو :max ملفات.',
            'required_without' => 'أرفق ملفًا واحدًا على الأقل أو أضف رابط GitHub.',
        ],

        'files.*' => [
            'max' => 'حجم الملف يتجاوز الحد المسموح وهو :max كيلوبايت.',
            'mimes' => 'صيغة هذا الملف غير مسموح بها لأسباب أمنية.',
            'mimetypes' => 'صيغة هذا الملف غير مسموح بها لأسباب أمنية.',
        ],

        'avatar' => [
            'image' => 'الصورة الشخصية يجب أن تكون صورة بصيغة JPG أو PNG أو WebP.',
            'mimes' => 'الصورة الشخصية يجب أن تكون بصيغة JPG أو PNG أو WebP.',
            'max' => 'حجم الصورة يتجاوز الحد المسموح وهو :max كيلوبايت.',
        ],

        'score' => [
            'required' => 'اكتب الدرجة قبل الحفظ.',
            'numeric' => 'الدرجة يجب أن تكون رقمًا، ويمكن أن تحتوي منزلة عشرية.',
            'min' => 'الدرجة لا تقل عن صفر.',
            'max' => 'الدرجة لا تتجاوز الدرجة القصوى للبند وهي :max.',
        ],

        'feedback' => [
            'required' => 'الملاحظة إلزامية — لا يمكن رصد درجة بدونها.',
            'min' => 'الملاحظة لا تقل عن :min أحرف. اشرح للمتدرب ما أحسنه وما يحتاج تحسينه.',
        ],

        'revision_reason' => [
            'required' => 'اكتب سبب تعديل الدرجة. يُسجَّل السبب في سجل التدقيق.',
            'min' => 'سبب التعديل لا يقل عن :min أحرف.',
        ],

        'edit_reason' => [
            'required' => 'اكتب سبب تعديل سجل الحضور. يُسجَّل السبب في سجل التدقيق.',
            'min' => 'سبب التعديل لا يقل عن :min أحرف.',
        ],

        'cancel_reason' => [
            'required' => 'اكتب سبب إلغاء الجلسة. يظهر السبب للمتدربين مع الإشعار.',
            'min' => 'سبب الإلغاء لا يقل عن :min أحرف.',
        ],

        'override_reason' => [
            'required' => 'اكتب سبب تجاوز شروط الشهادة. لا يمكن الإصدار بدونه.',
            'min' => 'سبب التجاوز لا يقل عن :min أحرف.',
        ],

        'reject_reason' => [
            'required' => 'اكتب سبب رفض الطلب. يصل السبب إلى مقدّم الطلب.',
            'min' => 'سبب الرفض لا يقل عن :min أحرف.',
        ],

        'current_password' => [
            'required' => 'أدخل كلمتك الحالية للتأكد أنك صاحب الحساب.',
            'current_password' => 'كلمة المرور الحالية غير صحيحة.',
        ],

        'end_time' => [
            'after' => 'وقت نهاية الجلسة يجب أن يكون بعد وقت بدايتها.',
        ],

        'ends_at' => [
            'after' => 'تاريخ نهاية الدفعة يجب أن يكون بعد تاريخ بدايتها.',
        ],

        'pass_score' => [
            'min' => 'درجة النجاح لا تقل عن صفر.',
            'max' => 'درجة النجاح لا تتجاوز :max.',
        ],

        'min_attendance_rate' => [
            'min' => 'نسبة الحضور الدنيا لا تقل عن صفر.',
            'max' => 'نسبة الحضور الدنيا لا تتجاوز 100.',
        ],

        'capacity' => [
            'min' => 'سعة الدفعة لا تقل عن مقعد واحد.',
        ],

        'body' => [
            'required' => 'اكتب نصًّا أو أرفق ملفًا قبل الإرسال.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display names for every form field
    |--------------------------------------------------------------------------
    */

    'attributes' => [

        // Identity
        'first_name_ar' => 'الاسم الأول بالعربي',
        'father_name_ar' => 'اسم الأب بالعربي',
        'grandfather_name_ar' => 'اسم الجد بالعربي',
        'family_name_ar' => 'اسم العائلة بالعربي',
        'first_name_en' => 'الاسم الأول بالإنجليزي',
        'father_name_en' => 'اسم الأب بالإنجليزي',
        'grandfather_name_en' => 'اسم الجد بالإنجليزي',
        'family_name_en' => 'اسم العائلة بالإنجليزي',
        'gender' => 'الجنس',
        'birth_date' => 'تاريخ الميلاد',
        'city' => 'المدينة',
        'education_level' => 'المستوى التعليمي',
        'bio' => 'النبذة',
        'avatar' => 'الصورة الشخصية',
        'locale' => 'لغة الواجهة',

        // Contact and credentials
        'email' => 'البريد الإلكتروني',
        'email_confirmation' => 'تأكيد البريد الإلكتروني',
        'new_email' => 'البريد الإلكتروني الجديد',
        'phone' => 'رقم الجوال',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'remember' => 'تذكّرني',
        'terms' => 'الموافقة على الشروط وسياسة الخصوصية',
        'token' => 'رمز التحقق',

        // Programs and cohorts
        'name' => 'الاسم',
        'slug' => 'المعرّف في الرابط',
        'summary' => 'النبذة المختصرة',
        'objectives' => 'الأهداف',
        'target_audience' => 'الفئات المستهدفة',
        'certificates' => 'الشهادات الممنوحة',
        'hours' => 'عدد الساعات التدريبية',
        'status' => 'الحالة',
        'starts_at' => 'تاريخ البداية',
        'ends_at' => 'تاريخ النهاية',
        'capacity' => 'السعة',
        'registration_closes_at' => 'موعد إغلاق التسجيل',
        'pass_score' => 'درجة النجاح',
        'min_attendance_rate' => 'الحد الأدنى لنسبة الحضور',
        'requires_approval' => 'اشتراط موافقة الإدارة',
        'trainers' => 'المدربون',
        'cohort_id' => 'الدفعة',
        'program_id' => 'البرنامج',

        // Weeks and sessions
        'week_id' => 'الأسبوع',
        'index' => 'الترتيب',
        'title' => 'العنوان',
        'topic' => 'الموضوع',
        'type' => 'النوع',
        'date' => 'التاريخ',
        'start_time' => 'وقت البداية',
        'end_time' => 'وقت النهاية',
        'trainer_id' => 'المدرب',
        'zoom_url' => 'رابط الاجتماع',
        'zoom_password' => 'كلمة مرور الاجتماع',
        'recording_url' => 'رابط التسجيل',
        'cancel_reason' => 'سبب الإلغاء',
        'replacement_at' => 'الموعد البديل',

        // Attendance
        'session_id' => 'الجلسة',
        'attendance_status' => 'حالة الحضور',
        'checked_in_at' => 'وقت تسجيل الحضور',
        'checked_out_at' => 'وقت تسجيل الانصراف',
        'edit_reason' => 'سبب التعديل',
        'note' => 'الملاحظة',

        // Assignments and submissions
        'description' => 'الوصف',
        'requirements' => 'المتطلبات',
        'max_score' => 'الدرجة القصوى',
        'due_at' => 'الموعد النهائي',
        'is_mandatory' => 'مهمة إجبارية',
        'allow_late' => 'السماح بالتسليم المتأخر',
        'show_github' => 'إظهار حقل رابط GitHub',
        'max_files' => 'أقصى عدد ملفات',
        'max_file_size' => 'أقصى حجم للملف',
        'files' => 'الملفات',
        'files.*' => 'الملف',
        'attachments' => 'المرفقات',
        'github_url' => 'رابط GitHub',
        'assignment_id' => 'المهمة',

        // Evaluation
        'score' => 'الدرجة',
        'feedback' => 'ملاحظة المدرب',
        'revision_reason' => 'سبب تعديل الدرجة',

        // Resources
        'resource_type' => 'نوع المورد',
        'url' => 'الرابط',
        'file' => 'الملف',

        // Messaging
        'body' => 'نص الرسالة',
        'attachment' => 'المرفق',
        'thread_id' => 'المحادثة',
        'report_reason' => 'سبب الإبلاغ',

        // Certificates
        'serial_number' => 'الرقم التسلسلي',
        'verify_code' => 'رمز التحقق',
        'override_reason' => 'سبب تجاوز الشروط',
        'revoke_reason' => 'سبب السحب',

        // Admin
        'role' => 'الدور',
        'user_id' => 'المستخدم',
        'reject_reason' => 'سبب الرفض',
        'seats_remaining' => 'المقاعد المتبقية',
        'is_registration_open' => 'حالة التسجيل',
        'faq' => 'الأسئلة الشائعة',
        'question' => 'السؤال',
        'answer' => 'الجواب',
        'timezone' => 'المنطقة الزمنية',

        // Assignment authoring
        'terms_accepted' => 'الموافقة على الشروط وسياسة الخصوصية',
        'show_github_field' => 'إظهار حقل رابط GitHub',
        'max_file_kilobytes' => 'أقصى حجم للملف',
        'reason' => 'السبب',

        // Notification preferences
        'session_reminders' => 'تذكير الجلسات',
        'assignment_reminders' => 'تذكير المهام',
        'grade_updates' => 'تحديثات الدرجات',
        'new_messages' => 'الرسائل الجديدة',
        'new_resources' => 'الموارد الجديدة',
        'in_app_enabled' => 'الإشعار داخل المنصة',
        'email_enabled' => 'الإشعار بالبريد',

        // Rejected client-sent instants (BR-07) — named so the refusal reads well
        'at' => 'الوقت المرسل',
        'time' => 'الوقت المرسل',
        'timestamp' => 'الوقت المرسل',
        'client_time' => 'وقت جهازك',
    ],

];
