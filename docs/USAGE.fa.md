# راهنمای استفاده از افزونه «تیسا OTP»

نسخه ۱.۱.۰ · وردپرس ۶.۱ یا جدیدتر · PHP ۷.۴ یا جدیدتر

دانلود بسته نصب: <https://github.com/ImTheAlireza/OTPforTisa/raw/refs/heads/arena/01a0b0f7-otpfortisa/tisa-otp.zip>

---

## شروع سریع (۵ دقیقه)

1. zip را نصب و فعال کنید.
2. **تیسا OTP ← تنظیمات ← سامانه‌های پیامکی**: سامانه را انتخاب و کلید API/الگو را وارد کنید.
3. **تیسا OTP ← ابزارها**: «ارسال کد آزمایشی» را با شماره خودتان بزنید.
4. شورت‌کد `[tisa_otp_form]` را در صفحه ورود بگذارید.

---

## ۱) نصب و فعال‌سازی

پیشخوان وردپرس ← **افزونه‌ها ← افزودن ← بارگذاری افزونه** ← انتخاب `tisa-otp.zip` ← **نصب** ← **فعال‌سازی**.

نصب دستی هم ممکن است: پوشه `tisa-otp` را در `wp-content/plugins/` بگذارید و فعالش کنید.

هنگام فعال‌سازی افزونه خودکار: جدول‌های اختصاصی (کدها، تلاش‌ها، رویدادها) را می‌سازد، زمان‌بند cron پاک‌سازی را ثبت می‌کند و منوی **تیسا OTP** را به پیشخوان اضافه می‌کند.

---

## ۲) اتصال سامانه پیامکی

مسیر: **تیسا OTP ← تنظیمات ← برگه «سامانه‌های پیامکی»**

سامانه فعال را از فهرست انتخاب کنید و فقط فیلدهای همان را پر کنید (کلیدهای تنظیمات داخل پرانتز):

| سامانه | شناسه | فیلدها |
|---|---|---|
| SMS.ir | `smsir` | `smsir_api_key` (کلید API) · `smsir_template_id` (شناسه الگوی Verify) · `smsir_sender` (اختیاری) |
| کاوه‌نگار | `kavenegar` | `kavenegar_api_key` · `kavenegar_template` (نام الگوی lookup) · `kavenegar_sender` |
| ملی پیامک | `meli` | `meli_username` · `meli_password` · `meli_from` (شماره خط) |
| IPPanel | `ippanel` | `ippanel_api_key` · `ippanel_pattern` (کد الگو) · `ippanel_sender` |
| فراز اس‌ام‌اس | `faraz` | `faraz_username` · `faraz_password` · `faraz_from` · `faraz_pattern` |

- **سامانه پشتیبان** (`sms_backup_gateway`) را هم انتخاب کنید؛ با روشن بودن «جابه‌جایی خودکار» (`failover_enabled`)، اگر سامانه اصلی خطا بدهد ارسال روی پشتیبان تکرار می‌شود.
- قالب متن پیامک در برگه «کد و کانال‌ها» است (`sms_template`) و از این جای‌نماها پشتیبانی می‌کند: `{code}`، `{phone}`، `{minutes}`، `{site}`، `{domain}`، `{webotp}`.
- **WebOTP (نسخه ۱.۰.۱ و بعد):** با روشن کردن `webotp_enabled`، خط شناسایی `@دامنه‌شما #کد` به انتهای پیامک‌های متنی اضافه می‌شود تا کروم/اندروید کد را به کاربر پیشنهاد دهند و فرم خودش پر شود. چون این خط چند نویسه به پیامک اضافه می‌کند، **پیش‌فرض آن خاموش است**. اگر سامانه شما الگودار (Pattern/Lookup) است، متن پیامک را خود سامانه می‌سازد؛ در آن حالت همین خط را داخل الگوی سامانه بگذارید یا از جای‌نمای `{webotp}` در متن استفاده کنید.
- اگر روی سرور `WP_HTTP_BLOCK_EXTERNAL` فعال است، دامنه سامانه را در `WP_ACCESSIBLE_HOSTS` (در `wp-config.php`) بگذارید؛ صفحه «ابزارها» همین مشکل را تشخیص می‌دهد و هشدار می‌دهد.

### کانال ایمیل

برگه **«کد و کانال‌ها»**: `channel` (کانال پیش‌فرض: `sms` یا `email`) و `channels_enabled` (کانال‌های فعال). با فعال بودن هر دو، اگر ارسال پیامک شکست بخورد یا کاربر شماره نداشته باشد کد ایمیل می‌شود. متن‌ها: `email_subject`، `email_body` (همان جای‌نماها)، `email_from`.

---

## ۳) تست

مسیر: **تیسا OTP ← ابزارها و وضعیت**

- **«ارسال کد آزمایشی»**: شماره را وارد کنید؛ یک کد واقعی ساخته و ارسال می‌شود و پاسخ سامانه (موفق/خطا + کد خطا) نمایش داده می‌شود.
- **بخش «وضعیت»**: نسخه PHP و وردپرس، سلامت جدول‌ها، فعال بودن زمان‌بند cron، تعداد حساب‌های دارای شماره، تعداد رویدادها و محدودیت‌های فعال.
- **«صفر کردن محدودیت‌ها»**: اگر حین تست محدود شدید، شمارنده‌ها را ریست می‌کند.

---

## ۴) نمایش فرم

### شورت‌کد

```text
[tisa_otp_form]
```

`[tisa_otp]` هم دقیقاً همان است؛ `[tisa_otp_hint]` یک راهنمای کوتاه چاپ می‌کند.

ویژگی‌های اختیاری (مقادیر تنظیمات عمومی را برای همان یک فرم بازنویسی می‌کنند):
`title` · `description` · `redirect` · `skin` · `accent` · `width` · `radius` · `align` · `code_input` · `show_brand` · `logo` · `custom_class`

```text
[tisa_otp_form title="ورود به حساب" skin="card" accent="#1d4ed8" width="460" code_input="single" redirect="https://example.com/dashboard/"]
```

مقادیر مجاز: `skin` = `line|card|glass|slate|pill` · `code_input` = `boxes|single` · `align` = `center|start|end`.

### ویجت المنتور

ویجت **«فرم ورود پیامکی تیسا»** (شناسه `tisa-otp-form`) در ویرایشگر المنتور، با همان ویژگی‌ها به‌صورت فیلد گرافیکی.

### جایگزینی صفحه ورود وردپرس

برگه **«عمومی» ← `replace_wp_login`** را روشن کنید؛ فرم کلاسیک `wp-login.php` پنهان و فرم OTP جای آن نمایش داده می‌شود.

### ووکامرس

برگه **«فروشگاه»**: `woo_account_form` (فرم OTP جای ورود/عضویت ووکامرس) · `woo_checkout_gate` (ورود اجباری پیش از تسویه حساب) · `woo_checkout_notice` (متن پیام) · `woo_checkout_page` (صفحه مقصد) · `sync_billing_phone` (همگام‌سازی شماره صورتحساب) · `link_guest_orders` (اتصال سفارش‌های مهمان به حساب تازه).

---

## ۵) تنظیمات پرکاربرد

### برگه «عمومی»

| کلید | مقدارها / توضیح |
|---|---|
| `enabled` | روشن/خاموش بودن کل افزونه |
| `auth_mode` | `smart` (هوشمند: موجود → ورود، جدید → عضویت) · `login_only` · `register_only` |
| `auto_register` | ساخت خودکار حساب برای شماره ناشناس |
| `default_role` | نقش کاربر تازه (پیش‌فرض `subscriber`) |
| `login_redirect` / `register_redirect` | مقصد پس از ورود/عضویت (خالی = همان صفحه) |
| `prevent_enumeration` | فرم لو نمی‌دهد شماره عضو هست یا نه |
| `cache_mode` | `auto` (پیش‌فرض): nonce هنگام باز شدن فرم از `/form-config` تازه می‌شود و در صورت رد شدن، درخواست یک بار تکرار می‌شود — مناسب سایت‌های دارای کش صفحه/CDN. `inline`: فقط nonce چاپ‌شده در HTML (بدون درخواست اضافه) |
| `guard_roles` + `guarded_roles` | نقش‌های حساس (پیش‌فرض `administrator,editor,shop_manager`) با OTP وارد نمی‌شوند و رمز عبور لازم دارند |

### برگه «کد و کانال‌ها»

`code_length` (پیش‌فرض ۵) · `code_ttl` (۱۲۰ ثانیه) · `verify_attempts` (۵ تلاش) · `resend_delay` (۶۰ ثانیه) · `code_store` = `database` (پیش‌فرض) یا `cache` (سبک‌تر ولی فقط با Redis/Memcached پایدار است).

نسخه ۱.۰.۱ این گزینه‌ها را هم به همین برگه اضافه کرده است:

| کلید | پیش‌فرض | توضیح |
|---|---|---|
| `auto_verify` | روشن | به‌محض کامل شدن کد (تایپ یا چسباندن، در هر دو حالت ورودی) کد بدون زدن دکمه بررسی می‌شود |
| `request_timeout` | ۱۵ ثانیه | مهلت هر درخواست REST؛ پس از آن درخواست لغو و پیام خطای شبکه نمایش داده می‌شود |
| `webotp_enabled` | خاموش | افزودن خط `@domain #code` به پیامک و خواندن خودکار کد در کروم/اندروید |

### برگه «امنیت و محدودیت»

`throttle_enabled` · `window_minutes` (۶۰) · `limit_per_phone` (۵) · `limit_per_ip` (۱۲) · `limit_per_ip_daily` (۶۰) · `limit_verify_per_ip` (۲۵).

`proxy_mode` برای سایت‌های پشت پروکسی: `none` (فقط `REMOTE_ADDR`) · `cloudflare` (`CF-Connecting-IP`) · `forwarded` (`X-Forwarded-For`) · `real_ip` (`X-Real-IP`)؛ در حالت‌های غیر از `none` می‌توانید `trusted_proxies` را وارد کنید. اگر این را تنظیم نکنید، همه کاربران با یک IP شمرده و زود محدود می‌شوند.

کپچا: `captcha_provider` = `none` · `recaptcha_v3` · `hcaptcha` · `arcaptcha`؛ کلیدها `captcha_site_key` و `captcha_secret_key`؛ آستانه امتیاز `captcha_score` (پیش‌فرض ۰٫۵، مخصوص reCAPTCHA v3)؛ `captcha_trigger` = `always` (هر درخواست) یا `after_limit` (پس از چند تلاش).

### برگه «فرم عضویت»

`registration_enabled` · `registration_flow` = `fields_then_code` (اول فرم، بعد کد؛ کمترین پیامک هدررفته) یا `code_then_fields` (اول کد، بعد فرم) · `field_preset` = `minimal` (نام و نام خانوادگی) · `identity` (نام، ایمیل، شهر) · `woocommerce` (فیلدهای صورتحساب) · `custom` (فیلدهای دستی در `fields`) · `username_from` = `phone` · `phone_prefixed` · `email` · `display_name_from` = `full_name` · `first_name` · `phone` · `email_mode` = `off` · `optional` · `required` · `send_welcome_email`.

برای هر فیلد دستی: برچسب، کلید، نوع، منبع (core/meta/wc)، تمام‌عرض یا نصف‌عرض، اجباری بودن و جای‌نمای نمونه.

### برگه «ظاهر فرم»

`skin` (`line|card|glass|slate|pill`) · `accent` · `surface` · `radius` · `width` · `align` · `show_brand` + `brand_logo` + `brand_width` · `code_input` (`boxes|single`) · متن دکمه‌ها (`label_send`, `label_verify`, `label_resend`, `label_edit_phone`) · عنوان و زیرعنوان فرم‌ها (`form_heading`, `form_subheading`, `register_heading`, `register_subheading`) · قوانین (`terms_enabled`, `terms_text`, `terms_url`) · `custom_css` و `custom_js` با `custom_code_scope` = `form_pages` یا `everywhere`.

### برگه «داده و رویدادها»

`logs_enabled` · `logs_keep_days` (۷) · `debug` · `phone_meta_key` (`tisa_phone`) · `lookup_meta_keys` (کلیدهایی که افزونه برای یافتن شماره‌های قدیمی می‌گردد: `billing_phone,digits_phone,digits_phone_no`) · `wipe_on_uninstall`.

### آنچه در ۱.۱.۰ به رابط کاربری اضافه شد

این موارد تنظیم تازه‌ای نمی‌خواهند و خودبه‌خود فعال‌اند:

| ویژگی | رفتار |
|---|---|
| نوار گام‌ها | فقط وقتی فرم عضویت روشن است (جریان سه‌گامی) دیده می‌شود. ترتیب آن از `registration_flow` می‌آید و با فیلتر `tisa_otp_form_steps` قابل تغییر است. |
| دکمهٔ اقدام در خطاها | بر پایهٔ کد خطای سرور ساخته می‌شود؛ متن دکمه‌ها قابل ترجمه است. در حالت «محدود شده» عمداً دکمه‌ای نشان داده نمی‌شود. |
| «پیامک نرسید؟» | ۳۰ ثانیه پس از ورود به گام کد ظاهر می‌شود. |
| تلاش باقی‌مانده | فقط وقتی سرور مقدار `attempts_left` بفرستد نمایش داده می‌شود. |
| نوار شمارش معکوس | یک انیمیشن CSS است؛ با `prefers-reduced-motion: reduce` حذف می‌شود و فقط عدد می‌ماند. |
| skip-link | نخستین توقف Tab داخل فرم؛ به گام جاری می‌برد. |

برای سفارشی‌سازی ظاهر، به‌جای بازنویسی انتخابگرها **توکن‌ها را عوض کنید** — فهرست کامل در بالای `assets/css/front.css` و در بخش ۳ سند [`UI-PLAN.fa.md`](UI-PLAN.fa.md) آمده است:

```css
.tisa-otp {
    --tisa-accent: #7c3aed;
    --tisa-input-line: #6b7280; /* باید دست‌کم ۳:۱ با پس‌زمینه کنتراست داشته باشد */
    --tisa-radius: 8px;
}
```

> حریم خصوصی: شماره خام در رویدادها ذخیره نمی‌شود — فقط ماسک‌شده (مانند `0912***4567`) به‌همراه اثر انگشت HMAC؛ کد یکبارمصرف هم فقط هَش‌شده نگهداری می‌شود.

---

## ۶) ابزارها و رویدادها

**تیسا OTP ← ابزارها و وضعیت**

- **واردسازی شماره‌های قدیمی**: شماره‌های ذخیره‌شده توسط ووکامرس (`woo_billing`)، افزونه Digits (`digits`) یا یک کلید متای دلخواه (`custom:کلید`) را به کلید اصلی تیسا منتقل می‌کند. اجرا دسته‌ای است (بدون تایم‌اوت روی سایت بزرگ)، حالت **«اجرای آزمایشی (بدون تغییر داده)»** دارد، برای تضاد بین **«بازنویسی»** و **«رد کردن»** انتخاب می‌کنید و دکمه **«بازگشت آخرین کار»** واگرد می‌کند.
- **نگهداری**: پاک‌سازی کدها و رویدادهای قدیمی.
- **بازسازی جدول‌ها**: وقتی در بخش وضعیت جدولی «نیاز به بازسازی» داشت.

**تیسا OTP ← رویدادها**: فهرست رویدادها با فیلتر و جست‌وجو و دکمه **خروجی CSV**.

---

## ۷) عیب‌یابی

| نشانه | علت / راه‌حل |
|---|---|
| کد ارسال نمی‌شود | اعتبارنامه و الگو درست است؟ «ارسال کد آزمایشی» در ابزارها چه خطایی می‌دهد؟ اگر `WP_HTTP_BLOCK_EXTERNAL` فعال است دامنه سامانه را در `WP_ACCESSIBLE_HOSTS` بگذارید. |
| «محدود شده‌اید» | شمارنده‌ها در برگه «امنیت»؛ برای تست از ابزارها «صفر کردن محدودیت‌ها». |
| همه کاربران با یک IP محدود می‌شوند | سایت پشت Cloudflare/پروکسی است → `proxy_mode` را تنظیم کنید. |
| فرم هست ولی دکمه کار نمی‌کند | کش JS قدیمی یا تداخل قالب؛ کش را خالی کنید، خطای کنسول را ببینید و مطمئن شوید `/wp-json/tisa-otp/v1/form-config` باز می‌شود. |
| خطای ۴۰۳ یا «nonce نامعتبر» روی سایت کش‌دار | `cache_mode` را روی `auto` بگذارید (پیش‌فرض) تا nonce در لحظهٔ تعامل تازه شود. اگر مسیر `/wp-json/` در کش یا CDN کش می‌شود، آن را استثنا کنید؛ پاسخ `/form-config` با `Cache-Control: no-store` فرستاده می‌شود. |
| دکمه تا ابد «در حال ارسال» می‌ماند | از ۱.۰.۱ هر درخواست مهلت دارد (`request_timeout`)؛ اگر سامانه پیامک کند است این عدد را بالاتر بگذارید و علت کندی سامانه را بررسی کنید. |
| کد در اندروید خودکار پر نمی‌شود | `webotp_enabled` خاموش است (پیش‌فرض)، یا سامانه الگودار است و خط `@domain #code` داخل الگو نیست، یا مرورگر پشتیبانی نمی‌کند (iOS/Safari پشتیبانی نمی‌کند). |
| کاربر عضو نمی‌شود | `auto_register` (عمومی) و `registration_enabled` (فرم عضویت) روشن باشند. |
| مدیر با OTP وارد نمی‌شود | عمدی است: `guard_roles` روشن و `administrator` در `guarded_roles` است. با رمز عبور وارد شوید یا نقش را بردارید. |
| کد زود باطل می‌شود | `code_store` روی `cache` است ولی Redis/Memcached ندارید → `database`. |
| شماره‌های قدیمی پیدا نمی‌شوند | `lookup_meta_keys` باید کلید افزونه قبلی را داشته باشد؛ سپس واردسازی را با حالت آزمایشی اجرا کنید. |

---

## ۸) توسعه‌دهنده‌ها

### REST (فضای نام `tisa-otp/v1`)

| مسیر | ورودی | خروجی |
|---|---|---|
| `POST /start` | `phone` | `register_form` یا گام `verify` یا خطا (cooldown/captcha) |
| `POST /code` | — | گام `verify` (ارسال مجدد) |
| `POST /verify` | `code` | `signed_in` · `register_form` · `invalid_code` |
| `POST /register` | فیلدها | `verify` · `invalid_fields` |
| `GET /form-config` | — | پیکربندی فرم برای JS |
| `POST /admin/test` · `/admin/throttle-reset` · `/admin/import/{start,step,undo}` · `GET /admin/summary` | — | ابزارهای مدیریتی (با nonce ادمین) |

قرارداد پاسخ: موفق = `{"success":true,"data":{...}}` · ناموفق = `{"success":false,"code":...,"message":...,"data":{...}}` (اغلب با HTTP 200).

### اکشن‌ها

`tisa_otp_booted` · `tisa_otp_activated` · `tisa_otp_deactivated` · `tisa_otp_code_sent` · `tisa_otp_signed_in` · `tisa_otp_user_created` · `tisa_otp_registration_failed` · `tisa_otp_phone_updated` · `tisa_otp_phone_changed` · `tisa_otp_phone_removed` · `tisa_otp_order_linked` · `tisa_otp_settings_saved` · `tisa_otp_log` · `tisa_otp_maintenance_done` · `tisa_otp_assets_enqueued`

### فیلترها

`tisa_otp_redirect` · `tisa_otp_registration_fields` · `tisa_otp_field_presets` · `tisa_otp_form_steps` · `tisa_otp_message_tokens` · `tisa_otp_gateway_credentials` · `tisa_otp_channels` · `tisa_otp_delivery_order` · `tisa_otp_guards` · `tisa_otp_guarded_roles` · `tisa_otp_allows_user` · `tisa_otp_default_role` · `tisa_otp_new_user_args` · `tisa_otp_code_length` · `tisa_otp_code_ttl` · `tisa_otp_client_ip` · `tisa_otp_http_timeout` · `tisa_otp_http_retry_delay` · `tisa_otp_lookup_meta_keys` · `tisa_otp_digits_meta_keys` · `tisa_otp_import_sources` · `tisa_otp_captcha_providers` · `tisa_otp_should_load_assets` · `tisa_otp_ambiguous_phone` · `tisa_otp_phone_valid` · `tisa_otp_selectable_roles` · `tisa_otp_faraz_pattern_key`

نمونه‌ها (امضاهای واقعی):

```php
// مقصد ورود را عوض کنید: ($url, $userId, $context)
add_filter( 'tisa_otp_redirect', function ( $url, $userId, $context ) {
    return 'login' === $context ? home_url( '/dashboard/' ) : $url;
}, 10, 3 );

// نقش پیش‌فرض کاربر تازه: ($role)
add_filter( 'tisa_otp_default_role', function ( $role ) {
    return 'customer';
} );

// جای‌نمای تازه به متن پیامک: ($replacements, DeliveryRequest $request)
add_filter( 'tisa_otp_message_tokens', function ( $replacements, $request ) {
    $replacements['{year}'] = '1404';
    return $replacements;
}, 10, 2 );
```

---

## ۹) حذف افزونه

غیرفعال‌سازی داده‌ها را پاک نمی‌کند. هنگام **حذف**، تنظیمات، کلید pepper و زمان‌بند cron همیشه پاک می‌شوند؛ جدول‌ها و متای شماره‌ها فقط وقتی پاک می‌شوند که `wipe_on_uninstall` (برگه «داده و رویدادها») روشن باشد. خاموش بودنش برای نصب دوباره بدون از دست دادن داده مفید است.
