<?php

/**
 * Password broker statuses. Laravel resolves these keys by name, so they must
 * exist here verbatim. `sent` and `user` are deliberately identical: the reply
 * to a reset request never reveals whether the address is registered (BR-30).
 *
 * @see BR-30 · PRD §9.3.3
 */

return [

    'reset' => 'تم تغيير كلمة المرور. سجّل الدخول بكلمتك الجديدة.',
    'sent' => 'إذا كان هذا البريد مسجّلًا لدينا فستصلك رسالة خلال دقائق.',
    'throttled' => 'أرسلنا رابطًا قريبًا. انتظر قليلًا قبل طلب رابط جديد.',
    'token' => 'رابط الاستعادة غير صالح أو انتهت مدته. اطلب رابطًا جديدًا.',
    'token_invalid' => 'رابط الاستعادة غير صالح أو انتهت مدته. اطلب رابطًا جديدًا.',
    'user' => 'إذا كان هذا البريد مسجّلًا لدينا فستصلك رسالة خلال دقائق.',

];
