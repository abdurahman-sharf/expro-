<?php

return [
    'finding_ai_backdated' => 'تاريخ القيد {number} يسبق ترحيله بـ {days_back} يومًا، في فترة أُقفلت بعده بقليل حتى {lock_until}.',
    'finding_ai_balance_jump' => 'ارتفع رصيد {customer} من {prior} إلى {balance} (بزيادة {increase}) في آخر {days} يومًا.',
    'finding_ai_control_manual' => 'في القيد اليدوي {number} عدد {accounts} من الأسطر على حسابات يملكها العملاء أو الموردون أو مواقع المخزون، مثل {account}.',
    'finding_ai_duplicate' => 'القيد {number} يكرر القيد {earlier}: المبالغ نفسها على الحسابات نفسها، بفارق {days} يوم، بمبلغ {amount}.',
    'finding_ai_offhours' => 'رُحِّل القيد {number} في {posted_at} خارج ساعات العمل، في فرع لم يُرحَّل فيه خارجها إلا {share}% من آخر {history} قيدًا يدويًا.',
    'finding_ai_reversal_chain' => 'عُكست قيود المستند {source} رقم {document} {reversals} مرات.',
    'finding_ai_round' => 'القيد {number} بمبلغ مستدير هو {amount}، من مضاعفات {unit}.',
    'journal_reclassify' => 'إعادة تصنيف اقترحها الوكيل: {text}، من {from} إلى {to} (القيد {number})',
    'journal_recurring' => 'قيد متكرر اقترحه الوكيل: {text}',
    'label_recurring' => 'مصروف متكرر {account}',
    'rationale_reclassify' => 'القيد اليدوي {number} وضع {amount} على {from} بالنص «{text}». ذهب النص نفسه إلى {to} {uses} مرة من {total} قبل ذلك ({share}%). يقترح الوكيل نقل المبلغ إلى {to}. لا يُرحَّل شيء حتى يعتمده شخص.',
    'rationale_recurring' => 'رُحِّل على {account} ({text}) في كل شهر من الأشهر {months} الأخيرة بمبلغ متقارب، ولم يُرحَّل شيء عن شهر {month}. يقترح الوكيل تكرار قيد الشهر الماضي {number} بتاريخ اليوم. لا يُرحَّل شيء حتى يعتمده شخص.',
    'title_reclassify' => 'نقل {amount} من {from} إلى {to}',
    'title_recurring' => 'تكرار {amount} على {account} عن شهر {month}',
];
