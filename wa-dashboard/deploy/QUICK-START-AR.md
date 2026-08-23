# الخطوات بالترتيب — انسخ سطر واحد كل مرة

السيرفر: `145.79.1.124` · Ubuntu 24.04 · عليه n8n

---

## 💡 نصيحة قبل ما تبدأ

الـ **web console** بتاع Hostinger بيلخبط اللصق (بيضيف حروف زي `^[[200~`).
الأفضل تستخدم **SSH عادي** من جهازك:

- **ويندوز:** افتح **PowerShell** أو **Windows Terminal** واكتب:
  ```
  ssh root@145.79.1.124
  ```
- لو هتفضل على الـ web console: **اكتب الأوامر بإيدك** أو الصق **سطر واحد بس** في المرة،
  ولو ظهر `^[[200~` في أول السطر امسحه قبل ما تضغط Enter.

---

# الخطوة 1 — الدومين

من مكان الدومين بتاعك، اعمل **A record**:

```
app.yourdomain.com  →  145.79.1.124
```

استنى 5–30 دقيقة. للتأكد من على جهازك:

```
ping app.yourdomain.com
```

لازم يرجّع `145.79.1.124`.

> **مش عندك دومين؟** تقدر تجرب مؤقتًا على `srv982416.hstgr.cloud`،
> بس الأحسن دومين حقيقي عشان الشكل قدام العملاء.

---

# الخطوة 2 — ادخل السيرفر

```
ssh root@145.79.1.124
```

---

# الخطوة 3 — شوف مين ماسك البورت 80

**دي أهم خطوة** — عشان ميقعش n8n.

```
docker ps
```

بُص على عمود **PORTS**:

- **لقيت `0.0.0.0:80->` أو `:443->`** → البورت محجوز لـ n8n. **قف هنا وابعتلي الناتج**،
  عشان محتاجين نحط الداشبورد ورا نفس الـ proxy.
- **مفيش أي حاجة على 80 أو 443** → كمّل عادي للخطوة 4. 🎉

---

# الخطوة 4 — نزّل الملفات

سطر ورا التاني:

```
apt-get update
```

```
apt-get install -y git
```

```
mkdir -p /var/www
```

```
cd /var/www
```

```
git clone -b main-r0e4x9 https://github.com/ehabfouad93/gildana.git tmp-repo
```

> لو طلب منك username/password → الريبو خاص. اعمل **Personal Access Token** من
> GitHub (Settings → Developer settings → Tokens) واستخدم:
> `git clone -b main-r0e4x9 https://<TOKEN>@github.com/ehabfouad93/gildana.git tmp-repo`

```
mv tmp-repo/wa-dashboard /var/www/app
```

```
rm -rf tmp-repo
```

### اتأكد إن الملفات وصلت

```
ls /var/www/app/deploy/vps-setup.sh
```

لازم يطبع اسم الملف. لو قال **No such file** → الـ clone جاب فرع غلط، أعِد الخطوة
وتأكد إن `-b main-r0e4x9` موجودة.

---

# الخطوة 5 — التنصيب (أمر واحد)

```
cd /var/www/app
```

```
bash deploy/vps-setup.sh app.yourdomain.com
```

⏱️ ياخد 2–5 دقايق. هيعمل كل ده لوحده:

- ينصّب nginx و PHP و MariaDB
- **يعمل قاعدة البيانات والمستخدم والباسورد** ← ده اللي كنت بتسأل عنه
- يكتب `config.php` بمفتاح تشفير جديد
- يشغّل الـ 11 migration (كل الجداول)
- يظبط الصلاحيات + كرون كل دقيقة

في الآخر هيطبعلك بيانات القاعدة. **صوّرها أو احفظها.**

> لو وقف وقال إن حاجة ماسكة البورت → ارجع للخطوة 3، ده الأمان اللي بيحمي n8n.

---

# الخطوة 6 — SSL

```
certbot --nginx -d app.yourdomain.com
```

هيسألك إيميل → اكتبه. وهيسأل redirect → اختار **2** (يحوّل كله لـ HTTPS).

---

# الخطوة 7 — افتح الداشبورد

```
https://app.yourdomain.com
```

هيطلب تعمل **حساب الأدمن** — ده أول حساب، بتاعك إنت.

بعد ما تدخل:

1. **Settings → Push Notifications → Generate keys** (مرة واحدة بس)
2. **Clients → Add client** → اعمل العميل
3. جوّه العميل → **Sending Channel**:
   - **Cloud API** → حط الـ Token و Phone Number ID
   - **Personal number** → العميل هيربط رقمه بنفسه من
     `Settings → My WhatsApp Number` بمسح QR

---

# ✅ اتأكد إن كل حاجة تمام

```
php /var/www/app/cron/dispatch.php
```

لازم يطبع سطور زي `Campaigns: sent=0 failed=0`. لو طبع ده يبقى الشغل تمام.

---

# لو حصلت مشكلة

| المشكلة | اعمل إيه |
|---|---|
| `ss: command not found` | عادي، السكربت بقى بيستخدم `docker ps` بدالها |
| `^[[200~` في أول السطر | امسحه قبل Enter، أو استخدم SSH من جهازك بدل الـ console |
| صفحة بيضا / 500 | `tail -30 /var/log/nginx/app.yourdomain.com.error.log` |
| n8n وقع | `systemctl stop nginx` فورًا وابعتلي ناتج `docker ps` |
| نسيت باسورد القاعدة | `grep pass /var/www/app/config.php` |

---

⚠️ **آخر حاجة:** خُد نسخة من `/var/www/app/config.php` على جهازك.
المفتاح اللي جوّاه بيفك تشفير كل توكنز واتساب — لو ضاع، كل العملاء هيدخّلوا بياناتهم تاني.
