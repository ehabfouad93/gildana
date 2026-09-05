# نقل قاعدة البيانات من الاستضافة القديمة للـ VPS

الداشبورد القديم شغال على cPanel وعليه عملاء حقيقيين. الهدف: ننقل كل حاجة للـ VPS
**من غير ما العملاء يحسّوا بأي انقطاع**، مع إمكانية الرجوع في أي لحظة.

---

## ⚠️ أهم حاجة — مفتاح التشفير

توكنز واتساب ومفاتيح الـ AI **مشفّرة** في قاعدة البيانات بمفتاح موجود في
`config.php` القديم.

لو نقلت البيانات من غير المفتاح: هتلاقي كل العملاء والحملات موجودين،
بس **كل التوكنز بايظة** وكل عميل هيحتاج يدخّل بياناته تاني.

**قبل أي حاجة:** افتح `config.php` القديم (cPanel → File Manager) واحتفظ بالسطرين دول:

```php
'encryption_key'       => '...',
'webhook_verify_token' => '...',
```

- **`encryption_key`** → إجباري، من غيره التوكنز ضايعة
- **`webhook_verify_token`** → لو غيّرته لازم تعدّله في Meta كمان، فالأسهل تنقله زي ما هو

---

## 1. صدّر قاعدة البيانات القديمة

**cPanel → Backup → Download a MySQL Database Backup** → اختار قاعدة الداشبورد
→ هينزّل ملف `.sql.gz`

> بديل: **phpMyAdmin → Export → Quick → SQL → Go**
> (لو القاعدة كبيرة، الـ Backup أضمن — phpMyAdmin بيقطع أحيانًا)

---

## 2. ارفع الملف للـ VPS

من جهازك (PowerShell):

```
scp "C:\Users\BS\Downloads\اسم_الملف.sql.gz" root@145.79.1.124:/root/
```

> أو ارفعه بـ FileZilla (SFTP · بورت 22 · user: `root`) لمجلد `/root/`

---

## 3. استورده

على السيرفر:

```
cd /root && ls *.sql*
```

فك الضغط لو `.gz`:

```
gunzip اسم_الملف.sql.gz
```

**امسح الجداول الفاضية الأول** (اللي اتعملت وقت التنصيب):

```
docker exec revenect-db mariadb -u root -p"$(grep REVENECT_DB_PASS /opt/gildana/wa-dashboard/deploy/docker/.env | cut -d= -f2-)" -e "DROP DATABASE revenect; CREATE DATABASE revenect CHARACTER SET utf8mb4;"
```

**استورد:**

```
docker exec -i revenect-db mariadb -u root -p"$(grep REVENECT_DB_PASS /opt/gildana/wa-dashboard/deploy/docker/.env | cut -d= -f2-)" revenect < /root/اسم_الملف.sql
```

ممكن ياخد دقيقة أو اتنين حسب الحجم.

---

## 4. حط مفتاح التشفير القديم

```
nano /opt/gildana/wa-dashboard/config.php
```

دوّر على السطرين دول وغيّرهم بالقيم القديمة:

```php
'encryption_key'       => 'المفتاح_القديم',
'webhook_verify_token' => 'التوكن_القديم',
```

**Ctrl+O** → Enter → **Ctrl+X**

> **متغيّرش** بيانات الـ `db` — دي بتاعة السيرفر الجديد وصح زي ما هي.

---

## 5. شغّل الترقيات الجديدة

القاعدة القديمة عندها ترقيات لحد `010`. الكود الجديد فيه `011` (قناة الرقم الشخصي):

```
docker exec -e DB_PASS='x' -e APP_DOMAIN='revenect.gildana.net' revenect php deploy/docker/make-config.php
```

> `config.php` موجود خلاص فمش هيتكتب فوقه — هيشغّل الترقيات الناقصة ويظبط الصلاحيات بس.

---

## 6. تأكد إن كل حاجة وصلت

```
docker exec revenect php deploy/docker/verify-migration.php
```

بُص على آخر جزء:

| النتيجة | معناها |
|---|---|
| `✓ Every stored secret decrypts` | 🎉 تمام، المفتاح مظبوط |
| `decrypt FAILED: N` | ❌ المفتاح غلط — ارجع لخطوة 4 |

وبُص على الأعداد فوق — لازم تطابق اللي عندك في الداشبورد القديم
(عدد العملاء، جهات الاتصال، الحملات…).

---

## 7. جرّب فعليًا قبل التحويل

على `https://revenect.gildana.net`:

- [ ] ادخل بحساب عميل قديم — الباسوردات اتنقلت زي ما هي
- [ ] شوف الحملات القديمة والتقارير
- [ ] **Health Check** — لازم يقول WhatsApp connected
- [ ] ابعت رسالة تجربة لرقمك

لو كل ده تمام → جاهز للتحويل.

---

## 8. التحويل النهائي

**في Meta** (لكل عميل على Cloud API) — غيّر الـ webhook:

```
https://revenect.gildana.net/webhook.php
```

**في cPanel → Zone Editor** — عدّل ريكورد `app.gildana.net`:

```
app.gildana.net  →  145.79.1.124
```

خلال 30 دقيقة العملاء هيبقوا على السيرفر الجديد.

> ولأن الدومين اتغيّر، ضيف في `config.php`:
> `'base_url' => 'https://app.gildana.net'`
> وضيف الدومين للـ Traefik في `.env` (`REVENECT_DOMAIN`) وأعد التشغيل.

---

## للرجوع في أي لحظة

**سيب الاستضافة القديمة شغالة أسبوع على الأقل.** لو حصلت مشكلة:

رجّع ريكورد `app.gildana.net` لـ `66.29.141.208` → كل حاجة ترجع زي ما كانت خلال دقايق.

⚠️ بس خد بالك: أي رسائل وصلت للسيرفر الجديد في الفترة دي مش هتكون على القديم.
عشان كده الأفضل تعمل التحويل في وقت هادي (بليل مثلاً).
