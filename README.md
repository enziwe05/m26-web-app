# M26 Site Tracker

Internal operations platform for **M26 Technologies**, a power and telecoms specialist maintaining telecom tower sites across Eswatini and Equatorial Guinea.

**In daily production use by ~20 staff.** Built and shipped solo across 11+ development phases for a paying client.

---

## The problem

M26 sends field technicians to tower sites to service generators, batteries, fencing, and equipment. Before this system, that work was tracked through spreadsheets, WhatsApp messages, and phone calls — with no reliable record of what was done, by whom, or when, and no photographic proof of completed work.

## What it does

**Office staff** create maintenance visits, assign them to technicians, monitor progress, review photo evidence, and track which sites have gone too long without a visit.

**Field technicians** open assigned visits on their phones, work through a checklist, upload before/after photos as proof for each item, clock in and out for attendance, and submit the completed visit.

---

## Core features

| Area | What's built |
|---|---|
| **Visit lifecycle** | Create → assign → in-progress (auto) → photo-verified completion → submit |
| **Photo evidence** | Before/after photo upload per checklist item, stored and displayed with a lightbox viewer |
| **Role-based access** | Three roles (admin, supervisor, employee) with distinct permissions and navigation |
| **Attendance tracking** | Clock in/out, shift assignment, and timesheet views |
| **Geolocation** | Location capture on site visits |
| **Self-selected visits** | Technicians can pick a site themselves; the system distinguishes these from office-assigned work |
| **Stale site tracking** | Surfaces sites that haven't been serviced recently, with plain-language "3 weeks ago" formatting |
| **Offsite submissions** | Handling for work logged away from an assigned site |
| **Automated closeout** | Cron job for auto-closing stale visits |
| **Site documents** | Contracts, drawings, and leases attached to each site |
| **Mobile-first** | Responsive across three breakpoints — technicians use it on phones in the field |

---

## Tech stack

- **Backend:** PHP 8 (no framework — deliberately plain, procedural)
- **Database:** MySQL (InnoDB, utf8mb4) with versioned migration files
- **Frontend:** Hand-written HTML/CSS, no framework, no build step
- **Auth:** Cookie-based sessions with server-side role guards on every protected page
- **Security:** bcrypt password hashing, prepared statements throughout (no string-interpolated SQL)
- **Files:** Local upload handling with type and size validation

## Notable engineering decisions

**No framework, on purpose.** The client needs a system that runs on cheap shared hosting and stays maintainable without a build pipeline. Plain PHP was the right call for the constraints, not a limitation.

**Prepared statements everywhere.** Every query touching user input uses bound parameters — a deliberate upgrade over the string-interpolation pattern common in older PHP codebases.

**Migrations over schema drift.** Schema changes ship as dated migration files (`database/migrations/`) rather than ad-hoc edits, so the live database can be brought forward reliably.

**Photo proof as a first-class requirement.** Before/after images per checklist item are the client's core need — the whole visit model is built around producing verifiable evidence, not just status flags.

---

## Structure

```
incl/            Shared: DB connection, helpers, header, sidebar, geo, lightbox
css/             Single stylesheet
database/        Schema, migrations, seed data
uploads/         Visit photos and site documents
*.php            One file per action (add_site, create_visit, clock, timesheets, ...)
```

## Running locally

Requires PHP 8+ and MySQL (developed on MAMP).

1. Import `database/m26.sql` in phpMyAdmin
2. Update credentials in `incl/dbconn.php`
3. Serve the folder and open `login.php`

Seed data is included for local testing. Demo accounts in the SQL file are not used in production.

---

Built by [Enziwe Dlamini](https://linkedin.com/in/enziwe-dlamini) · [github.com/enziwe05](https://github.com/enziwe05)
