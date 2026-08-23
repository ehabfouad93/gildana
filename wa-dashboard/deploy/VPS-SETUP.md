# رفع الداشبورد على VPS (Ubuntu 24.04) — خطوة بخطوة

> 👈 **عايز الخطوات مبسّطة، سطر سطر؟** افتح **[`QUICK-START-AR.md`](QUICK-START-AR.md)**.
> الملف ده فيه التفاصيل الكاملة والشرح.

الخادم في المثال: `145.79.1.124` · `srv982416.hstgr.cloud` · Hostinger KVM 1 · شغّال عليه n8n.

---

## ⚠️ قبل ما تبدأ — حاجتين مهمين

**1. البورت 80/443 غالبًا محجوز لـ n8n.**
الـ n8n متركّب بـ Docker، ومعاه عادةً reverse proxy ماسك 80/443. لو ركّبت nginx على طول
هيحصل تعارض و n8n ممكن يقع. اتأكد الأول:

```bash
docker ps          # شوف عمود PORTS — لو فيه ‎:80->‎ أو ‎:443->‎ يبقى محجوز
```

> لو `ss: command not found` — عادي، مش كل السيرفرات فيها الأداة دي.
> `docker ps` بيكفي هنا لأن n8n شغال بـ Docker.

- **مفيش نتيجة** → البورت فاضي، كمّل عادي.
- **فيه Traefik / Caddy / Docker** → عندك اختيارين:
  - **(أ)** تحط الداشبورد ورا نفس الـ proxy بتاع n8n (تضيفه كـ service في الـ docker-compose)، أو
  - **(ب)** تسيب nginx يمسك 80/443 وتمرّر n8n من خلاله.

السكربت **بيقف لوحده** لو لقى حاجة تانية ماسكة البورت، عشان ميوقعش n8n.

**2. الخطة KVM 1 = 1 CPU + 4 GB RAM.**
n8n واخد ~23% دلوقتي. الداشبورد + MySQL + جيتواي واتساب هيمشوا، **بس** بشرط تستخدم
جيتواي **Baileys** (زي Evolution API) مش `whatsapp-web.js`، لأن ده بيشغّل Chrome لكل عميل
(300–500 ميجا للواحد) وهياكل الرام. لو العملاء هيكتروا، اعمل Upgrade لـ KVM 2.

---

## 1. الدومين

من مزوّد الدومين اعمل **A record**:

```
app.yourdomain.com   →   145.79.1.124
```

استنى شوية لحد ما ينتشر (`ping app.yourdomain.com` يرجّع الـ IP).

---

## 2. رفع الملفات

ادخل على السيرفر:

```bash
ssh root@145.79.1.124
```

### الطريقة الأسهل — Git (بتخلّي التحديث بعد كده أمر واحد)

> ⚠️ **مهم:** الشغل الجديد (قناة الرقم الشخصي + مجلد `deploy`) لسه على فرع
> **`main-r0e4x9`** مش على `main`، لأن PR #1 لسه متعملّهوش merge.
> لازم تحدّد الفرع بـ `-b`، وإلا هتجيب نسخة قديمة من غير الملفات دي أصلاً.
> بعد ما تعمل merge للـ PR، امسح `-b main-r0e4x9` من الأمر.

```bash
apt-get update && apt-get install -y git
mkdir -p /var/www && cd /var/www
git clone -b main-r0e4x9 https://github.com/ehabfouad93/gildana.git tmp-repo
mv tmp-repo/wa-dashboard /var/www/app
rm -rf tmp-repo
```

اتأكد إن الملفات وصلت فعلاً قبل ما تكمّل:

```bash
ls /var/www/app/deploy/vps-setup.sh          # لازم يبقى موجود
ls /var/www/app/migrations/011_*.sql          # قناة الرقم الشخصي
```

> ريبو خاص؟ استخدم Personal Access Token:
> `git clone -b main-r0e4x9 https://<TOKEN>@github.com/ehabfouad93/gildana.git`

### أو ZIP / SFTP

من جهازك:

```bash
scp -r wa-dashboard root@145.79.1.124:/var/www/app
```

أو ارفع ZIP بـ FileZilla (SFTP · بورت 22 · user: `root`) وفكّه:

```bash
cd /var/www && unzip app.zip -d app
```

المهم في الآخر يبقى عندك `/var/www/app/index.php` موجود.

---

## 3. التنصيب + قاعدة البيانات (أمر واحد)

```bash
cd /var/www/app
bash deploy/vps-setup.sh app.yourdomain.com
```

السكربت بيعمل كل ده لوحده:

| | |
|---|---|
| ينصّب | nginx · PHP-FPM · MariaDB · certbot |
| يعمل | قاعدة بيانات `revenect` + مستخدم بباسورد عشوائي |
| يكتب | `config.php` بمفتاح تشفير جديد وrandom verify token |
| يشغّل | كل الـ migrations (11 ملف) — **مش محتاج تستورد أي SQL يدوي** |
| يظبط | الصلاحيات (`www-data`، و`config.php` على 640) |
| يضيف | كرون كل **دقيقة** — ده اللي بيخلّي وقفة الـ 3 دقايق مظبوطة بالظبط |

في الآخر هيطبعلك اسم القاعدة والمستخدم والباسورد. **احتفظ بيهم.**

السكربت **آمن لو شغّلته تاني** — مش هيمسح `config.php` ولا القاعدة الموجودة.

**متغيرات اختيارية:**

```bash
SKIP_APT=1     bash deploy/vps-setup.sh app.yourdomain.com   # الباكدچات متركّبة خلاص
FORCE_NGINX=1  bash deploy/vps-setup.sh app.yourdomain.com   # عارف إن البورت فاضي
```

---

## 4. شهادة SSL

```bash
certbot --nginx -d app.yourdomain.com
```

**إجباري** — الـ webhook بتاع Meta و الـ PWA مش هيشتغلوا من غير HTTPS.

---

## 5. افتح الداشبورد

```
https://app.yourdomain.com
```

أول مرة هيطلب تعمل **حساب الأدمن**. بعدها:

1. **Admin → Settings → Push Notifications → Generate keys** (مرة واحدة).
2. **Admin → Clients** → اعمل عميل → اختار الـ Channel:
   - **Cloud API** → حط الـ token و Phone Number ID،
     و في Meta حط الـ webhook: `https://app.yourdomain.com/webhook.php`
     (الـ verify token جوّه `config.php`).
   - **Personal number** → العميل بنفسه هيربط رقمه من
     **Settings → My WhatsApp Number** بمسح QR.

---

## 6. جيتواي الرقم الشخصي (بس لو هتستخدم الفيتشر ده)

لازم يشتغل على نفس السيرفر ويسمع على **localhost بس** — لأنه ماسك جلسات واتساب
الحقيقية بتاعة عملائك، وأي حد يوصله يقدر يبعت من أرقامهم ويقرا محادثاتهم.

بعد ما تركّبه:

**Admin → Settings → Personal-Number Gateway**

```
Gateway base URL : http://127.0.0.1:3000
Auth header      : apikey
API key          : <المفتاح بتاع الجيتواي>
```

وفي إعدادات الجيتواي نفسه حط الـ webhook:

```
https://app.yourdomain.com/webhook_personal.php
```

(السيستم بيحط الـ secret بتاع كل عميل في الرابط تلقائيًا وقت الربط.)

---

## التحديثات بعد كده

```bash
cd /var/www/app
git pull                       # لو نزّلت من فرع الـ PR: git pull origin main-r0e4x9
php -r 'require "includes/config_loader.php";require "includes/helpers.php";
        require "includes/crypto.php";require "includes/db.php"; print_r(migrate());'
chown -R www-data:www-data /var/www/app
```

`config.php` مش بيتغيّر أبدًا مع الـ pull (مستثنى من git).

---

## لو حاجة مشيت غلط

| المشكلة | الحل |
|---|---|
| صفحة بيضا / خطأ 500 | `tail -50 /var/log/nginx/app.yourdomain.com.error.log` |
| الحملات مش بتتبعت | `php /var/www/app/cron/dispatch.php` وشوف الخرج · اتأكد `crontab -l` فيه السطر |
| n8n وقع بعد التنصيب | البورت اتاخد منه — `systemctl stop nginx` وارجع لخيار (أ) فوق |
| صور القوالب مش بتوصل | `/var/www/app/uploads` لازم تكون `www-data` و 775 |
| نسيت باسورد القاعدة | موجود جوّه `config.php` |

⚠️ **خُد نسخة من `config.php`.** المفتاح اللي جوّاه هو اللي بيفك تشفير كل التوكنز
المحفوظة — لو ضاع، كل العملاء هيحتاجوا يدخّلوا بياناتهم تاني.
