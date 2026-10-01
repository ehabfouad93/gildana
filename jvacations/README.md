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
 Advisor ──► Booker ──► Communicator ──► Sales ──► Accountant / Owner Services
 adds the    books the   confirms the     closes:   statement · payments ·
 client      meeting     meeting          contract  holiday weeks
   new        booked      confirmed        contracted
                     ▲          │                 └──► lost (with reason)
                     └──────────┘ "needs new date"      cancelled (by communicator)
```

| Role | Sees | Can do |
|---|---|---|
| **Advisor** | Only the clients they created, and each one's current stage | Add a client (duplicate-phone check); edit it until the booker picks it up |
| **Booker** | Every *new* client, plus the ones they booked | Set the meeting date, time and place, and assign the sales rep |
| **Communicator** | Booked and confirmed meetings, plus the ones they handled | Confirm or adjust the date/time, log "no answer", send back for a new date, or cancel |
| **Sales** | Only meetings assigned to them, after confirmation | **Succeeded** → enter the official data → contract generated (preview / print / PDF / Word). **Not closed** → reason |
| **Accountant** | All reservations and statements (read-only) | Total, the 25% down payment and every instalment (24 or 36 months, as chosen on the contract form); print the statement, export CSV |
| **Owner Services** | Owner profiles, reservations, instalments | Record each payment (paid / partial / unpaid, date, method, receipt). Separate tab: yearly week allowance per owner, and booking stays by project, date and time |
| **Admin** | Everything | Team accounts, projects, company details and contract terms, reopen lost clients |

Every hand-off is stamped with who did it and when, and recorded on the
client's timeline. Any department can add a note there for the next one.

## The money rules

- **Down payment** = total × `down_pct` (25% by default, set by the admin; Sales cannot change it), due on the contract date.
- **Instalments**: the remainder divided over **24 or 36 months** (the options come from Settings and are chosen on the contract form). Each instalment is rounded down to a whole currency unit, and the **last one absorbs the difference**, so the plan always adds up to the total exactly.
- Due dates run monthly from the first-instalment date. Month ends are clamped, so 31 Jan is followed by 28/29 Feb, then 31 Mar.
- Sales can correct a contract until the first payment is recorded. After that, the plan is locked.
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
├── includes/     bootstrap, db, auth, i18n, view, domain (rules), money (pure maths)
├── lang/         en.php, ar.php
├── migrations/   001_init.sql
├── tests/        run.php — money and schedule maths, no DB needed
└── assets/       jv.css, jv.js, contract.css (the printable document)
```

## Tests

```
php tests/run.php
```
