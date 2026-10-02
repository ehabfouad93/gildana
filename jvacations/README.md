# J Vacations

A mini-ERP for a holiday-membership business. It connects every department on
one client record, from the first call to the last instalment. Each person
signs in with one role, and the role decides what they can see and do.

Built to the same rules as the other Gildana apps: plain PHP 8 (parses on 7.4),
MySQL via PDO, bilingual EN/AR with full RTL. **No Composer, no npm, no build
step, no framework.**

---

## The pipeline

```
 Advisor ─► Booker ─► Communicator ─► Sales Manager ─► Sales ─► Accountant / Owner Services
 adds the   books the  confirms the     client arrives:  closes:  statement · payments ·
 client     meeting    meeting          assigns the rep  contract holiday weeks
   new       booked     confirmed         arrived         contracted
                  ▲          │               │                 └─► lost (with reason)
                  └──────────┴── new date / no-show            cancelled (by communicator)
```

The sales rep is **optional** at booking and confirmation. The Sales Manager
assigns (or changes) the rep when the client arrives, and the rep can only close
a client who is at the office.

| Role | Default permissions (the admin can change any of them under **Roles & permissions**) |
|---|---|
| **Advisor** | Add clients; sees only the clients they created |
| **Booker** | Booking queue: date, time, place, optional sales rep |
| **Communicator** | Confirm / adjust, no answer, new date, cancel; send a WhatsApp by hand |
| **Sales Manager** | Expected today / at the office; mark arrival + assign or reassign the rep; no-show; sees all clients and contracts |
| **Sales** | Clients assigned to them once they arrive: contract, or not closed |
| **Accountant** | Reservations, statements, collections board (read-only) |
| **Owner Services** | Owner profiles, record payments, holiday weeks, projects |
| **Admin** | Everything: team, roles, WhatsApp, contract templates, settings, reschedule plans, edit contracts after payment |

Every hand-off is stamped with who and when on the client's timeline, and every
client has **one number** (`#12`) shown on every step, the contract and the
statement. The search box at the top finds a client by that number, contract
number, any phone format, national ID or name.

## WhatsApp automations

Admin → WhatsApp connects the **Meta WhatsApp Cloud API** (token, phone number
ID, business account ID). "Refresh templates" loads your approved templates. An
automation is: *when the client reaches a step, send this template, filling
{{1}}, {{2}}… with these fields* (client name, meeting date/time, sales rep,
contract number, amounts, next due date…).

Steps: added · booked · confirmed · needs new date / no-show · arrived ·
contracted · not closed · cancelled · instalment paid · holiday booked.

A failed send never blocks the pipeline. It is logged with Meta's reason under
**Message log** and can be resent from there.

## Contract templates

Admin → Contract templates accepts your own Word contract (.docx) with
placeholders such as `{official_name}`, `{total}` and `{monthly}`, plus
`{schedule_table}` on its own line for the full payment table. Templates can be
per language and per project. On a contract, everyone allowed to see it gets a
download button for each matching template. Starter templates (Arabic and
English) with every placeholder are one click away. The ZIP handling is plain
PHP, so no extra server extension is needed.

## The money rules

- **Down payment** = total × `down_pct` (25% by default, set by the admin; Sales cannot change it), due on the contract date.
- **Instalments**: the remainder divided over **24 or 36 months** (the options come from Settings and are chosen on the contract form). Each instalment is rounded down to a whole currency unit, and the **last one absorbs the difference**, so the plan always adds up to the total exactly.
- Due dates run monthly from the first-instalment date. Month ends are clamped, so 31 Jan is followed by 28/29 Feb, then 31 Mar.
- Sales can correct a contract until the first payment is recorded. After that the plan is locked. The admin can still correct the contract data, and can **reschedule**: paid instalments stay, a partly paid one is closed at what was paid, and the unpaid rest is re-split over any number of months (for example 24 → 36). The admin can also edit a single instalment's date or amount.
- "Overdue" means not fully paid and past its due date, using the business time zone set in `config.php`.

## Weeks & stays

Each contract grants **N weeks per year** for **Y years** starting from a given
year. Owner Services sees the allowance as a year-by-year grid and books stays
(project, check-in date and time, weeks, guests). The app refuses a booking
that:

- exceeds that year's allowance,
- falls outside the membership term, or
- overlaps another stay for the same owner.

Overdue instalments show a warning but don't block the booking. That decision
is left to staff.

---

## Install

1. Create a MySQL/MariaDB database (utf8mb4).
2. Copy `config.sample.php` to `config.php` and fill in the database details and time zone.
3. Open `/setup.php` in the browser. It creates the tables, then asks for the first admin account.
4. As admin, open **Settings** (company name, address, contract terms, plan options),
   then **Projects** (at least one project is required before a contract can be saved),
   then **Team** (one account per person, each with a role).

## Layout

```
jvacations/
├── index.php  setup.php  logout.php  set_lang.php   entry points
├── app/          every screen; nav and access are decided by role
├── includes/     bootstrap, db, auth, caps (permissions), i18n, view, domain (rules),
│                 money (pure maths), whatsapp, docx + zip (Word templates)
├── storage/      uploaded contract templates (never served)
├── lang/         en.php, ar.php
├── migrations/   001_init.sql
├── tests/        run.php — money, schedule, reschedule and ZIP; no DB needed
└── assets/       jv.css, jv.js, contract.css (the printable document)
```

## Tests

```
php tests/run.php
```
