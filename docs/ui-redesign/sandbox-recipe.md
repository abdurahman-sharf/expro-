# وصفة البناء في بيئة التطوير المقيدة

الخطوات التي احتجتها لتشغيل نسخة كاملة من التطبيق داخل بيئة سحابية مؤقتة (Ubuntu 24.04، مخرج الشبكة عبر وكيل لا يصل إلى GitHub).

منذ P2 تُبنى الأصول بـ`npm run build` إلى `public/assets` وهي **مضمَّنة في Git**، فلا حاجة لنسخة معزولة ولا لتعديل القوالب، ويمكن التشغيل من المستودع نفسه.

## المتطلبات

| المكوّن | التثبيت |
|---|---|
| PHP 8.3 مع `intl` و`gd` و`mbstring` و`mysqli` و`zlib` | موجودة |
| `bcmath` | `apt-get install php8.3-bcmath` (بدونها يرجع التطبيق 500 عند الإقلاع) |
| MariaDB 10.11 | `apt-get install mariadb-server` ثم `mysqld_safe` |
| Node 22 وChromium | موجودان (Playwright في `/opt/pw-browsers`) |

## الخطوات

1. **حزم PHP:** `composer install` (للتشغيل فقط يكفي `--no-dev`). لا تشغّل `composer test` كمستخدم root، فاستعمل `vendor/bin/phpunit` مباشرة.
2. **حزم Node:** `npm install` (حزمة `xlsx` أُزيلت لأنها تُحمَّل من `cdn.sheetjs.com` المحجوب في هذه البيئة).
3. **بناء الأصول (عند تعديل `resources/assets` فقط):** `npm run build`. الناتج في `public/assets` ويُضمَّن في الالتزام، وفحص CI (`Assets`) يفشل إن تغيّر بعد إعادة البناء.
4. **قاعدة البيانات:** إنشاء القاعدة بترميز `utf8mb4` ومستخدم بكلمة مرور، ثم `cp .env.example .env` وضبط `app.allowedHostnames='localhost,127.0.0.1'` و`database.default.hostname='127.0.0.1'` واسم القاعدة، ثم `php spark env:provision`.
5. **التشغيل:** `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:80 -t public`. المنفذ **80** لأن `baseURL` يُشتق من `SCRIPT_NAME` دون المنفذ، فأي منفذ آخر يكسر روابط الأصول.
6. **التهيئة:** `tools/ui-smoke/setup.sh` ينشئ الجداول عبر صفحة الدخول ويعبئ بيانات الفحص.

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
