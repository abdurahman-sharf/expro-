<?php

return [
    'back_home'                        => 'العودة إلى لوحة التحكم',
    'go_back'                          => 'رجوع',
    'no_access_permission'             => 'الصلاحية: {0}',
    'no_access_text'                   => 'تحتاج صلاحية الوصول إلى «{0}». اطلب من مدير النظام أن يمنحها لك.',
    'no_permission_module'             => 'ليس لديك صلاحيات للوصول لهذا القسم',
    'not_found_text'                   => 'الصفحة التي طلبتها غير موجودة أو نُقلت.',
    'not_found_title'                  => 'الصفحة غير موجودة',
    'server_error_text'                => 'حدث خطأ من جهتنا. أعد المحاولة بعد قليل، وإن تكرر فتواصل مع الدعم الفني.',
    'server_error_title'               => 'حدث خطأ غير متوقع',
    'unable_to_create_env_file'        => 'تعذر إنشاء {filePath} لتوفير throttle.key',
    'unable_to_lock_file'              => 'تعذر قفل {filePath}: {reason}',
    'unable_to_open_lock_file'         => 'تعذر فتح {filePath}: {reason}',
    'unable_to_persist_throttle_key'   => 'تعذر حفظ throttle.key في {filePath}',
    'unable_to_persist_encryption_key' => 'تعذّر حفظ مفتاح التشفير في {filePath}',
    'encryption_key_not_provisioned'   => 'لم يُجهَّز مفتاح تشفير. شغّل `php spark env:provision` قبل المتابعة.',
    'throttle_key_not_provisioned'     => 'لم يُجهَّز مفتاح تقييد الطلبات. شغّل `php spark env:provision` قبل المتابعة.',
    'unable_to_read_env_file'          => 'تعذر قراءة {filePath} لتوفير throttle.key',
    'unknown'                          => 'غير معروف',
];
