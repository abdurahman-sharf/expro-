# TAX2 — تقرير المرحلة T5: التحقق بالـ SDK الرسمي (معطّل) والفحوص الداخلية

| البند | القيمة |
|---|---|
| التاريخ | 2026-11-21 |
| الفرع | `claude/tender-rubin-r5z9a4`. **لم يُدمج في `master` ولم يُنشر.** |
| القرار | D-09: تنزيل الـ SDK الرسمي وتشغيله محليًا دون شبكة (معتمد) |
| **الحالة** | **معطّل على ملف الـ SDK.** كل ما لا يحتاج الملف نُفّذ |

## 1. سبب التعطيل

رابط تنزيل الـ SDK في الصفحة الرسمية لبوابة المطورين يطلب تسجيل دخول بحساب Microsoft. لا يُستعمل مصدر غير رسمي (شرط D-09)، ولا تُنشأ حسابات باسم المنشأة. **المطلوب من المالك:** تنزيل ملف الـ SDK الرسمي ورفعه إلى الجلسة أو المستودع (خارج `public/`)، مع بصمة SHA-256 من الصفحة الرسمية إن وُجدت.

## 2. ما نُفّذ

| البند | التنفيذ |
|---|---|
| مصفوفة العينات (§5.3) | `tests/Support/EInvoiceMatrixTrait.php`: المبسطة S-01 إلى S-09 (عدا S-05، النوع الخاص الذي لا يدعمه النظام)، والقياسية B-01 إلى B-12، والسلسلة C-01، والعينات المكسورة عمدًا C-02 (ضريبة الفئة، وصافي السطر) |
| التوقيع الداخلي | `DocumentStamperTest::testSigned_StampsEverySampleOfTheMatrixSoThatItVerifies`: كل عينة تُوقَّع بمفتاح اختبار مولّد، ويُتحقق من التوقيع والـ hash وQR داخليًا، وتُكتب في `writable/zatca-matrix/` (مستثناة من git) |
| المدقق الرسمي | `DocumentStamperTest::testSigned_PassesTheOfficialValidatorForEverySampleOfTheMatrix` (مجموعة `zatca-sdk`): يُتخطى ما لم يُضبط `ZATCA_SDK_HOME`. كل عينة يجب أن تنجح، والمكسورة يجب أن تفشل |
| السكربت | `tools/zatca/validate.sh`: يشغّل `fatoora -validate` على كل ملف ويحفظ المخرج بجانبه. **لا اتصال بالهيئة**: التحقق محلي |

## 3. خطوات التشغيل حين يتوفر الملف

```bash
vendor/bin/phpunit --no-coverage --filter testSigned_StampsEverySampleOfTheMatrixSoThatItVerifies tests/Libraries/EInvoicing/DocumentStamperTest.php
```

```bash
ZATCA_SDK_HOME=/path/to/sdk tools/zatca/validate.sh writable/zatca-matrix
```

القبول (بطاقة T5): كل العينات PASSED أو بتحذيرات موثقة مقبولة، وhash Expro = hash الـ SDK. **ولا يُعد هذا اعتمادًا من الهيئة.**

## 4. ما بعد T5

يتكرر التحقق بعد T4 وT6. **لم تُضف بعد إلى المصفوفة** أنواع T4 وT6: الشحن رسمًا، والعملة الأجنبية، وفاتورة الدفعة المقدمة (386) وسطر خصمها في الفاتورة النهائية، والإشعار لأصل مطبوع، وإشعار رد الدفعة. اختباراتها الحالية داخلية (`UblInvoiceXmlTest` و`EInvoiceBuilderTest` و`EInvoiceServiceTest`)، وتُضاف عينات لها مع أول تشغيل للـ SDK.
