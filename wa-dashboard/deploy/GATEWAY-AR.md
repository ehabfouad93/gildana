# تشغيل جيتواي الرقم الشخصي

رسالة **"The WhatsApp gateway is not set up yet"** معناها إن الداشبورد شغال تمام،
بس الجيتواي اللي بيمسك جلسة واتساب لسه متثبتش. دي خطواته.

---

## ⚠️ اقرا ده الأول

- الطريقة دي **مخالفة لشروط واتساب** والرقم ممكن **يتحظر**. الوقفة اللي في النظام
  (15 رسالة ثم 3 دقايق) بتقلل الخطر بس **مش بتلغيه**.
- **متستخدمهاش لرقمك الشخصي المهم.** استخدم رقم مخصص للشغل.
- الأفضل دايمًا للعملاء الجادين: **WhatsApp Cloud API** الرسمي.

---

## الذاكرة

الجيتواي بيضيف 3 كونتينرات (Evolution + Postgres + Redis) ≈ **700 ميجا - 1 جيجا**.

خطتك **KVM 1 = 4 جيجا**، ومستخدم منها 27% دلوقتى. يعني هيمشي، بس **المساحة هتبقى ضيقة**.
راقبها بـ:

```
docker stats --no-stream
```

لو الرام قربت تخلص → Upgrade لـ KVM 2.

---

## 1. المفاتيح

```
cd /opt/gildana/wa-dashboard/deploy/docker
```

```
echo "EVOLUTION_API_KEY=$(openssl rand -hex 24)" >> .env
```

```
echo "EVOLUTION_DB_PASS=$(openssl rand -hex 16)" >> .env
```

```
cat .env
```

اتأكد إن السطرين الجداد ظهروا.

---

## 2. شغّل الجيتواي

```
cd /opt/gildana && git pull origin main-r0e4x9
```

```
cd /opt/gildana/wa-dashboard/deploy/docker
```

```
docker compose -f docker-compose.revenect.yml -f docker-compose.gateway.yml up -d
```

⏱️ أول مرة بتاخد 2–4 دقايق (بينزّل الصور).

---

## 3. اتأكد إنه شغال

```
docker ps | grep evolution
```

لازم تشوف **3**: `evolution-api` · `evolution-db` · `evolution-redis`

```
docker logs evolution-api --tail 20
```

**اختبار الاتصال من الداشبورد نفسه** (ده اللي بيهم فعلاً):

```
docker exec revenect curl -s -o /dev/null -w '%{http_code}\n' http://evolution-api:8080
```

لازم يرجّع **`200`**. لو رجّع `000` يبقى الاتصال بين الكونتينرين مش شغال.

---

## 4. ظبطه في الداشبورد

هات المفتاح:

```
grep EVOLUTION_API_KEY .env
```

وبعدين في الداشبورد → **Admin → Settings → Personal-Number Gateway**:

| الخانة | القيمة |
|---|---|
| Gateway base URL | `http://evolution-api:8080` |
| Auth header | `apikey` |
| API key | المفتاح من `.env` |

> ⚠️ **`http://evolution-api:8080` مش `127.0.0.1`.** جوّه الكونتينر، `127.0.0.1`
> معناها الكونتينر نفسه — الداشبورد بيوصل للجيتواي باسم الخدمة على الشبكة الداخلية.

اضغط **Save gateway**.

---

## 5. اربط رقم

- **Admin → Clients** → افتح العميل → **Sending Channel** → اختار **Personal number** → Save
- ادخل بحساب العميل → **Settings → My WhatsApp Number** → **Connect my WhatsApp**
- امسح الـ QR من الموبايل: **واتساب → الأجهزة المرتبطة → ربط جهاز**

الصفحة هتقلب لـ **Connected** ومعاها الرقم لوحدها.

---

## لو حصلت مشكلة

| المشكلة | الحل |
|---|---|
| لسه "gateway is not set up" | المفتاح أو الـ URL مش متسجّلين — راجع خطوة 4 |
| QR مش بيظهر | `docker logs evolution-api --tail 40` |
| `000` في اختبار الاتصال | الكونتينرات مش على نفس الشبكة — `docker network inspect docker_revenect` |
| الرام خلصت | `docker stats` · فكّر في Upgrade |
| `pull access denied` | اسم الصورة قديم — اعمل `git pull` وجرّب تاني |
| الجلسة بتفصل كل شوية | الموبايل لازم يكون أونلاين — واتساب ويب بيفصل لو التليفون قفل خالص |

---

## لو قررت تلغيه

```
docker compose -f docker-compose.revenect.yml -f docker-compose.gateway.yml down
```

```
docker compose -f docker-compose.revenect.yml up -d
```

الداشبورد هيفضل شغال عادي، والعملاء على Cloud API مش هيتأثروا.
