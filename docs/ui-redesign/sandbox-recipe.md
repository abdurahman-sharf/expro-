# وصفة البناء في بيئة التطوير المقيدة

الخطوات التي احتجتها لتشغيل نسخة كاملة من التطبيق داخل بيئة سحابية مؤقتة (Ubuntu 24.04، مخرج الشبكة عبر وكيل لا يصل إلى GitHub). تُنفَّذ كلها على **نسخة معزولة** خارج المستودع (`git archive HEAD`)، حتى لا يعدّل gulp ملفات القوالب المتتبَّعة.

تنتهي الحاجة إلى البنود الخاصة بـgulp بعد P2 (الأصول تُضمَّن في Git).

## المتطلبات

| المكوّن | التثبيت |
|---|---|
| PHP 8.3 مع `intl` و`gd` و`mbstring` و`mysqli` و`zlib` | موجودة |
| `bcmath` | `apt-get install php8.3-bcmath` (بدونها يرجع التطبيق 500 عند الإقلاع) |
| MariaDB 10.11 | `apt-get install mariadb-server` ثم `mysqld_safe` |
| Node 22 وChromium | موجودان (Playwright في `/opt/pw-browsers`) |

## الخطوات

1. **نسخة معزولة:** `git archive HEAD | tar -x -C <dir>`.
2. **حزم PHP:** `composer install` (للتشغيل فقط يكفي `--no-dev`). لا تشغّل `composer test` كمستخدم root، فاستعمل `vendor/bin/phpunit` مباشرة.
3. **حزم Node:** `npm install` يفشل لحزمتين على GitHub (`bootstrap3-dialog` و`elegant-circles`) ولأن `xlsx` يُحمَّل من `cdn.sheetjs.com`. في النسخة المعزولة فقط: يُستبدل `bootstrap3-dialog` بالإصدار `1.35.4` من npm، وتُحذف `elegant-circles` (أيقونات القائمة تُولَّد بدائل بسيطة)، ويُستعمل `xlsx@0.18.5`، وتُحذف `overrides` و`package-lock.json`.
4. **بناء الأصول:** `npx gulp clean copy-bootswatch copy-bootswatch5 copy-bootstrap debug-js prod-js debug-login-js prod-login-js debug-config-js prod-config-js debug-css prod-css copy-fonts` (بدون `update-licenses` و`copy-menubar`). وسم الحقن في `header.php` يُصحَّح إلى `<!-- inject:prod:css -->` (بمسافة) في النسخة المعزولة، وإلا يبقى رابط CSS قديمًا ويرجع 404.
5. **قاعدة البيانات:** إنشاء القاعدة بترميز `utf8mb4` ومستخدم بكلمة مرور، ثم `cp .env.example .env` وضبط `app.allowedHostnames='localhost,127.0.0.1'` و`database.default.hostname='127.0.0.1'` واسم القاعدة، ثم `php spark env:provision`.
6. **التشغيل:** `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:80 -t public`. المنفذ **80** لأن `baseURL` يُشتق من `SCRIPT_NAME` دون المنفذ، فأي منفذ آخر يكسر روابط الأصول.
7. **التهيئة:** `tools/ui-smoke/setup.sh` ينشئ الجداول عبر صفحة الدخول ويعبئ بيانات الفحص.

## اختبارات PHPUnit كما في CI

```sh
cp .env.example .env            # ثم وضع throttle.key عشوائيًا
export ENCRYPTION_KEY=$(openssl rand -hex 32) CI_ENVIRONMENT=testing MYSQL_HOST_NAME=127.0.0.1
vendor/bin/phpunit --no-coverage --log-junit test-results/junit.xml
```

تحتاج قاعدة `ospos_test` ومستخدمًا (`admin` / `pointofsale`) بصلاحيات كاملة عليها.

## مطبات

- عميل `mysql` يستعمل ترميز `latin1` افتراضيًا، فتُخزَّن الأسماء العربية مضاعفة الترميز. استعمل دائمًا `--default-character-set=utf8mb4`.
- الصنف الذي يُدخَل بـSQL بلا سجل في `ospos_inventory` لا يظهر في قائمة الأصناف.
- `pkill -f` بنمط `php -S` قد يقتل صدفة الأمر نفسها. استعمل نمطًا مثبتًا على بداية الأمر: `pkill -f "^php -S"`.
- الاختبارات الأربعة المتخطاة في `security_helperTest` تعتمد على صلاحيات الملفات، وتُتخطى عند التشغيل كـroot.
