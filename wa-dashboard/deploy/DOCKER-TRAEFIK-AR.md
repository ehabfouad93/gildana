# التركيب ورا Traefik (السيرفر اللي عليه n8n)

`docker ps` أثبت إن **Traefik ماسك 80 و 443**، و n8n ورا منه على `127.0.0.1:5678`.
يعني **ممنوع** نركّب nginx — هيتخانق على البورت و n8n هيقع.

الحل: نضيف الداشبورد كـ container ورا نفس الـ Traefik. مميزاته إن الـ SSL بيتظبط
أوتوماتيك زي n8n، و n8n مش هيتلمس خالص.

---

## خطوة 1 — الدومين

A record: `app.yourdomain.com` → `145.79.1.124`
(لازم يشتغل قبل ما نكمل، عشان Traefik يقدر يجيب شهادة SSL)

---

## خطوة 2 — ✅ اتعملت خلاص

القيم اتأكدت من السيرفر بتاعك:

| | |
|---|---|
| Network | `root_default` |
| certresolver | `mytlschallenge` |
| entrypoints | `web` = :80 · `websecure` = :443 |
| HTTP → HTTPS | Traefik بيعمله عالميًا (مش محتاجين نضيف حاجة) |

n8n شغال على `n8n.srv982416.hstgr.cloud` بنفس الإعدادات.

---

## خطوة 3 — نزّل الملفات

```
apt-get update
```

```
apt-get install -y git
```

```
mkdir -p /opt && cd /opt
```

```
git clone -b main-r0e4x9 https://github.com/ehabfouad93/gildana.git gildana
```

> **متعملش `mv` للمجلد الداخلي!** لو نقلت `wa-dashboard` لبرّه، مجلد `.git` هيفضل
> في مكانه القديم و`git pull` مش هيشتغل تاني. سيب الريبو كامل زي ما هو —
> التطبيق بيشتغل من `/opt/gildana/wa-dashboard` عادي.

```
ls /opt/gildana/wa-dashboard/deploy/docker/Dockerfile
```

آخر أمر لازم يطبع اسم الملف. لو **No such file** يبقى الفرع غلط.

> لو الـ clone طلب Username/Password → الريبو خاص. اكتب اسم المستخدم، وفي
> خانة Password **الصق الـ Personal Access Token** (مش الباسورد).

---

## خطوة 4 — ملف الإعدادات

```
cd /opt/gildana/wa-dashboard/deploy/docker
```

اعمل الملف (غيّر الدومين والقيم من خطوة 2):

```
nano .env
```

والصق جوّاه:

```
REVENECT_DOMAIN=app.yourdomain.com
REVENECT_DB_PASS=ضع_الباسورد_هنا
TRAEFIK_NETWORK=root_default
TRAEFIK_CERTRESOLVER=mytlschallenge
TZ=Africa/Cairo
```

> غيّر **`REVENECT_DOMAIN`** و **`REVENECT_DB_PASS`** بس — الباقي مظبوط لسيرفرك بالفعل.

احفظ بـ **Ctrl+O** ثم **Enter** ثم **Ctrl+X**.

> لتوليد باسورد قوي:
> ```
> openssl rand -base64 24
> ```

---

## خطوة 5 — شغّل

```
docker compose -f docker-compose.revenect.yml up -d --build
```

⏱️ أول مرة بتاخد 3–6 دقايق (بيبني الصورة).

اتأكد إن الاتنين شغالين:

```
docker ps | grep revenect
```

**و الأهم — اتأكد إن n8n لسه شغال:**

```
docker ps | grep n8n
```

---

## خطوة 6 — إعداد التطبيق + قاعدة البيانات

```
docker exec revenect cp config.sample.php config.php
```

بعدين افتح الملف:

```
docker exec -it revenect sed -i \
  -e "s/'host'    => '127.0.0.1'/'host'    => 'revenect-db'/" \
  -e "s/'name'    => 'wa_dashboard'/'name'    => 'revenect'/" \
  -e "s/'user'    => 'root'/'user'    => 'revenect'/" \
  config.php
```

حط الباسورد ومفتاح التشفير (نفس الباسورد اللي في `.env`):

```
docker exec -it revenect bash
```

جوّه الكونتينر:

```
php -r '$k=base64_encode(random_bytes(32)); $t=bin2hex(random_bytes(12));
 $s=file_get_contents("config.php");
 $s=preg_replace("/\x27pass\x27\s*=>\s*\x27[^\x27]*\x27/","\x27pass\x27    => \x27".getenv("DBPASS")."\x27",$s,1);
 $s=str_replace("CHANGE_ME_base64_32_bytes",$k,$s);
 $s=str_replace("CHANGE_ME_pick_any_random_string",$t,$s);
 file_put_contents("config.php",$s); echo "done\n";'
```

> قبل السطر ده اكتب: `export DBPASS='نفس_الباسورد'`

شغّل الجداول:

```
php -r 'require "includes/config_loader.php";require "includes/helpers.php";
 require "includes/crypto.php";require "includes/db.php"; print_r(migrate());'
```

```
exit
```

---

## خطوة 7 — الكرون (كل دقيقة)

```
( crontab -l 2>/dev/null; echo "* * * * * docker exec revenect php /var/www/html/cron/dispatch.php >/dev/null 2>&1" ) | crontab -
```

```
crontab -l
```

---

## خطوة 8 — افتح الداشبورد

```
https://app.yourdomain.com
```

Traefik هيجيب شهادة SSL لوحده (ممكن ياخد دقيقة أول مرة).

---

## نقل بيانات الاستضافة القديمة

عندك داشبورد شغال على cPanel بعملاء؟ اتبع **[](MIGRATE-DB-AR.md)** —
وأهم حاجة فيه: **مفتاح التشفير**، من غيره كل التوكنز هتبقى غير قابلة للقراءة.

---

## التحديث بعد كده

```
cd /opt/gildana && git pull origin main-r0e4x9
```

```
docker exec revenect php -r 'require "includes/config_loader.php";require "includes/helpers.php";require "includes/crypto.php";require "includes/db.php"; print_r(migrate());'
```

الملفات mounted من الهوست، فمش محتاج rebuild إلا لو الـ Dockerfile اتغيّر.

---

## لو حصلت مشكلة

| المشكلة | الحل |
|---|---|
| n8n وقع | `docker compose -f docker-compose.revenect.yml down` فورًا وابعتلي |
| الموقع مش بيفتح | `docker logs revenect --tail 40` |
| SSL مش شغال | اتأكد الـ A record شغال، وشوف `docker logs root-traefik-1 --tail 40` |
| خطأ اتصال بالقاعدة | `docker logs revenect-db --tail 20` · اتأكد إن `host` = `revenect-db` |
| الرام قربت تخلص | `docker stats --no-stream` |

⚠️ خُد نسخة من `/opt/gildana/wa-dashboard/config.php` — المفتاح اللي جوّاه بيفك تشفير كل التوكنز.
