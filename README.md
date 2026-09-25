# ZDSPGC Event QR Attendance System

**Zamboanga del Sur Provincial Government College** — QR-code based attendance
monitoring for school events (convocation, foundation day, orientation, sports
fest, seminars, examinations).

A web system built with **PHP (main) + HTML/CSS/JS views and a MySQL *or*
SQLite database**. PHP does all the logic — authentication, signing and
verification of QR codes, the check-in rules, reports and CSV exports. HTML is
only the interface, and a little JavaScript drives the camera/QR rendering.

> **Unsa ni?** Kini nga system nag-monitor sa attendance sa mga event sa ZDSPGC
> pinaagi sa QR code. Ang estudyante naay QR ID; i-scan sa officer sa pultahan
> (o i-scan sa estudyante mismo gamit ang cellphone), ug automatic nga marekord
> ang oras — **on time** o **late** depende sa grace period sa event. Ang tanan
> nga data naka-store sa database sa school, ug ang QR kay **signed** — kung
> bag-uhon ang ID, dili na modawat ang daan nga QR code.

---

## 1. What it does

| Feature | Detail |
|---|---|
| **Event management** | Create events with a start/end window, venue, grace period and self check-in on/off. Open or close check-in with one click. |
| **Signed student QR IDs** | Every student gets an HMAC-signed QR token. Printable ID cards (single or a whole sheet). Re-issuing an ID instantly voids the old printout. |
| **Scan station** | Officer-facing screen: webcam scanning, USB QR-scanner support, manual student-number entry, big colour verdict (On time / Late / Already in / Rejected), beep feedback, live counters. |
| **Student self check-in** | The event poster carries a signed link. A student scans the poster, then scans their own ID on their phone. Only works when the organiser enables it. |
| **Duplicate protection** | A student can only be recorded once per event — enforced by a unique index in the database, not just in the UI. |
| **On time vs. late** | Decided server-side from `start time + grace minutes`. Nothing the client sends can change it. |
| **Reports** | Turnout per event, per course, punctuality rate, absentee lists, and the full attendance log with filters (event, course, status, method, date range, free text). |
| **CSV export** | Attendance log, roster, per-event summary and absentee lists (UTF-8 with BOM, formula-injection safe). |
| **Bulk student import** | Upload the whole campus roster as a CSV — blank template or roster-export layout. Duplicate student numbers are skipped (or updated on request), problem rows are reported, and every imported student gets a signed QR ID. A 500-row random sample roster is included for demos. |
| **Staff accounts & roles** | Administrator / Officer / Faculty with capability-based access control, plus an append-only audit log. |

---

## 2. Quick start

### Option A — Windows: one command (XAMPP, MySQL)

Double-click **`run-local.bat`** in the project folder (or run it in a terminal).
It finds PHP, starts MySQL/MariaDB on the port from `includes/config.php`,
creates the database and installs the tables the first time, starts the dev
server on **http://localhost:8080/** and opens your browser. Run `stop.bat` when
you are done; MySQL keeps running because other tools may share it.

### Option B — zero setup (SQLite, good for defence/demo)

Any PHP 8.1+ with `pdo_sqlite` and `mbstring`:

```bash
cd zdspgc-qr-attendance
php -S localhost:8080
```

Then open **http://localhost:8080/install.php** and click *Install database*.
The installer creates the tables and loads sample students, events and check-ins.

### Option C — XAMPP / WAMP (MySQL, the usual school setup)

1. Copy the folder into `C:\xampp\htdocs\zdspgc-qr-attendance`.
2. In phpMyAdmin create a database named `zdspgc_attendance`.
3. Edit `includes/config.php`:
   ```php
   const DB_DRIVER = 'mysql';
   const DB_USER   = 'root';
   const DB_PASS   = '';        // your XAMPP MySQL password
   ```
4. Open **http://localhost/zdspgc-qr-attendance/install.php** and click *Install database*
   (or import `sql/mysql-schema.sql` in phpMyAdmin, then run install.php to seed).
5. **Change `APP_SECRET` in `includes/config.php`** before printing any real student QR ID.

To make event poster QR links reachable from phones, set `PUBLIC_BASE_URL` in
`includes/config.php` to the HTTPS address students will use, for example:
`https://attendance.example.com/zdspgc-qr-attendance`. Leave it empty to use the
current request host. Mobile camera access requires HTTPS.


### Demo accounts (created by the installer)

> These credentials are documented here only — the login page does not display them.

| Role | Username | Password |
|---|---|---|
| System Administrator | `admin` | `Admin@2026` |
| Student Affairs Officer | `officer` | `Officer@2026` |
| Faculty / Door Marshal | `faculty` | `Faculty@2026` |

Sign in, open **Scan station**, pick *Foundation Day 2026 — Opening Program*
(already open in the sample data), then try a student QR from **Students →
QR ID → Print** (print to PDF and scan it with your phone) or simply type a
student number such as `2024-00308` into the manual box.

---

## 3. How the QR code works

The system never trusts a QR code on its own. Each token is
`base64url(payload) + "." + base64url(HMAC-SHA256(payload)[0..15])`:

```
payload = 1|S|2024-00308|9f3a1c7d20ab      student ID
payload = 1|E|EVT-FOUND-2026|4b8e01cc7a55  event poster
```

Verification (in `Attendance::resolveStudentByToken()`) does **three** things:

1. recomputes the HMAC with `APP_SECRET` and compares it with `hash_equals()`
   (constant time, no timing leak);
2. looks the student/event up in the database;
3. compares the nonce in the token with the nonce stored in the database.

`qr_nonce` is what makes a QR **revocable**: press *New QR ID* and the old
printout, photo or screenshot stops working immediately. Because the token is
*derived* and not stored as a secret, an ID can be reprinted at any time
without keeping secrets in the database.

---

## 4. Attendance rules (enforced in PHP)

| Rule | Where |
|---|---|
| Check-in opens `EARLY_CHECKIN_MINUTES` (60) before the start time and stops at the end time | `Attendance::windowState()` |
| On time = scanned at or before `starts_at + grace_minutes`; later scans are **LATE** | `Attendance::checkIn()` |
| One record per student per event (database unique index — a repeat scan shows the original time) | `uq_attendance_event_student` |
| Closing an event blocks every scan instantly, including self check-in | `events.status = 'closed'` |
| Self check-in only when the event has `self_checkin = 1` | `Attendance::checkIn()` |
| Inactive students are refused with a message to see the registrar | `students.status` |
| Manual entry is allowed only for signed-in staff (never for self check-in) | `api/checkin.php` |
| Every accepted scan is written to the audit log with operator, station, IP and time | `Security::audit()` |

Times are stored as `Y-m-d H:i:s` in `APP_TIMEZONE` (`Asia/Manila`).

---

## 5. Pages

| Page | Who | Purpose |
|---|---|---|
| `install.php` | first run | Creates the tables, seeds demo data, shows an environment self-test |
| `login.php` / `logout.php` | staff | Sign in / out (bcrypt, rate limited, idle timeout) |
| `index.php` | all staff | Dashboard: today's check-ins, live events, latest scans |
| `scan.php` | scanner roles | Full-screen scan station (camera + manual + live counters) |
| `checkin.php` | students (public) | Self check-in: scan the poster, then your own ID |
| `events.php` / `event.php` | officers+ | Create/edit events, poster QR, walk-in entry, undo, per-event report |
| `students.php` / `student.php` | officers+ | Roster, QR ID card, re-issue, attendance history |
| `qr_cards.php` | officers+ | Print-optimised sheet of student QR ID cards |
| `attendance.php` | all staff | Full log with filters, pagination and CSV export |
| `reports.php` | all staff | Turnout per event, per course, absentee lists |
| `users.php` | admins | Staff accounts, role matrix, audit log |
| `settings.php` | all staff | Change your own password, review system settings |
| `export.php` | all staff | CSV downloads (`attendance`, `students`, `summary`, `absentees`) |
| `api/checkin.php` | the scanner | Validates and records a check-in (JSON) |
| `api/stats.php` | the scanner | Live counters + last scans (JSON) |

---

## 6. Security

| Control | Implementation |
|---|---|
| Password storage | `password_hash()` (bcrypt) + `password_verify()`; no plaintext anywhere |
| Brute force | Failed-attempt counter with lockout (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_MINUTES`) plus session rate limits on sign-ins and check-ins |
| CSRF | Token in every state-changing form and in the `X-CSRF-Token` header used by the scanner's `fetch()` calls, compared with `hash_equals()` |
| SQL injection | Prepared statements only (PDO); no user input is concatenated into SQL |
| XSS | All output escaped through `Helpers::e()` (`htmlspecialchars`, `ENT_QUOTES`) |
| QR forgery | HMAC-SHA256 signature **and** a database nonce check (see §3) |
| Session hardening | `session_regenerate_id()` on login, `HttpOnly` + `SameSite=Lax` cookies, `Secure` on HTTPS, idle timeout |
| Authorisation | Capability checks on every page *and* every POST action (`Auth::requireCapability`) |
| Audit trail | Append-only `audit_log`: sign-ins, failures, blocks, checks-ins, undo, QR re-issue, exports, CRUD |
| File exposure | `.htaccess` denies `includes/`, `storage/` and `*.sqlite|*.sql|*.md` |
| CSV safety | Formula-injection guard (`Security::csvSafe`) + UTF-8 BOM so Excel opens it correctly |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` |

### Before you use this for real

- [ ] Set a long random `APP_SECRET` (rotating it voids every existing QR code — re-print IDs afterwards).
- [ ] Set `APP_ENV = 'production'` and `DEMO_MODE = false`.
- [ ] Change the three demo passwords, remove unused accounts, keep at least one active admin.
- [ ] Serve over **HTTPS** — phone cameras need a secure context for `getUserMedia()`.
- [ ] Back up the database on a schedule (`storage/attendance.sqlite` or a MySQL dump).
- [ ] Delete or protect `install.php` once the system is set up.
- [ ] Keep `display_errors = Off` and `log_errors = On` in `php.ini`.

---

## 7. Database

Five tables (identical on both drivers — see `sql/sqlite-schema.sql` and `sql/mysql-schema.sql`):

| Table | Key columns | Notes |
|---|---|---|
| `users` | `username` (unique), `role`, `password_hash`, `status` | Staff accounts |
| `students` | `student_no` (unique), `course`, `year_level`, `qr_nonce`, `status` | `qr_nonce` makes a QR revocable |
| `events` | `code` (unique), `starts_at`, `ends_at`, `grace_minutes`, `self_checkin`, `status`, `qr_nonce` | Check-in window rules |
| `attendance` | `event_id`, `student_id`, `checked_in_at`, `status`, `method`, `source`, `station`, `scanned_by`, `ip` | `UNIQUE (event_id, student_id)` prevents duplicates |
| `audit_log` | `created_at`, `actor`, `role`, `action`, `detail`, `scope`, `ip` | Append-only activity trail |

Deleting an event cascades to its attendance rows; deleting a student does the same.

---

## 8. API reference (used by the scanner)

### `POST api/checkin.php`

Headers: `Content-Type: application/json`, `X-CSRF-Token: <token>`

```json
{ "token": "<student QR token>", "event_id": 2, "method": "qr",
  "source": "station", "station": "Main Gate" }
```

Manual fallback (signed-in staff only): send `"method": "manual"` with `"student_no": "2024-00308"` and no `token`.

Response:

```json
{ "ok": true, "code": "on_time", "message": "Check-in recorded — on time.",
  "student": { "student_no": "2024-00308", "full_name": "Fuentes, Joshue D.",
               "course": "BSIT", "year_level": "1st Year", "section": "C" },
  "record":  { "checked_in_at": "2026-03-12 07:58:11", "status": "on_time",
               "method": "qr", "source": "station" },
  "event":   { "id": 2, "code": "EVT-FOUND-2026", "title": "Foundation Day 2026 — Opening Program" },
  "counters": { "total": 7, "on_time": 7, "late": 0 } }
```

`code` values: `on_time`, `late`, `duplicate`, `invalid_token`, `unknown_student`,
`no_event`, `not_yet_open`, `event_ended`, `event_closed`, `self_disabled`,
`manual_denied`, `rate_limited`, `csrf`, `bad_response`.

### `GET api/stats.php?event_id=2`

Returns live counters and (for signed-in staff) the last 10 scans, used by the
station's 8-second auto-refresh.

---

## 9. Project structure

```
zdspgc-qr-attendance/
├── index.php               Dashboard (today's numbers, live events, latest scans)
├── login.php               Staff sign-in (bcrypt + rate limiting + lockout)
├── logout.php              POST-only sign-out
├── install.php             Create tables + seed demo data + environment self-test
├── scan.php                Scan station (camera + manual + live counters)
├── checkin.php             Student self check-in from the event poster
├── events.php              Event list + create/edit/close/delete + poster re-issue
├── event.php               One event: numbers, poster QR, walk-in entry, records
├── students.php            Student roster (search, add, edit, QR, status)
├── student.php             One student: profile, printable QR ID, history
├── qr_cards.php            Print sheet of student QR ID cards
├── attendance.php          Full attendance log (filters + pagination + CSV)
├── reports.php             Turnout per event / per course / absentee lists
├── users.php               Staff accounts, role matrix, audit log
├── settings.php            Change your own password, system info
├── export.php              CSV exports (attendance, students, summary, absentees)
├── api/
│   ├── checkin.php         JSON check-in endpoint
│   └── stats.php           JSON live counters
├── includes/
│   ├── config.php          ← the only file you normally edit
│   ├── bootstrap.php       loads config, classes, session, install check
│   ├── cli-setup.php       CLI database bootstrap used by run-local.bat
│   ├── Database.php        PDO wrapper (SQLite or MySQL)
│   ├── Schema.php          runs the schema files + seeds demo data
│   ├── Security.php        CSRF, signed tokens, rate limiting, audit, CSV guard
│   ├── Auth.php            sessions, roles, capabilities, idle timeout
│   ├── Attendance.php      the check-in rules and every query
│   ├── Helpers.php         escaping, input, URLs, flash, date formatting
│   ├── icons.php           inline SVG icons (no icon font, no emoji)
│   └── layout/
│       ├── header.php      HTML shell + role-aware navigation
│       └── footer.php      footer + script loading
├── assets/
│   ├── css/style.css       All styling + printable QR card styles
│   ├── img/
│   │   ├── logo.png          ZDSPGC seal (from seal-source.jpg, background made transparent)
│   │   ├── login-bg-vicenzo-sagun.png  Login background photo used by the login page
│   │   ├── login-bg-vicenzo-sagun.svg  Vicenzo Sagun landscape (vector, alternate background)
│   │   ├── login-bg.jpg      Earlier login background (kept as reference)
│   │   └── seal-source.jpg   Original seal artwork (reference copy)
│   └── js/
│       ├── app.js          Confirm dialogs, table filters, copy, print
│       ├── qr.js           Renders QR codes (wraps the vendored encoder)
│       ├── scanner.js      Camera decode, manual entry, beeps, live counters
│       └── vendor/
│           ├── qrcode-generator.js   QR encoder (MIT, © Kazuhiko Arase)
│           └── html5-qrcode.min.js   QR camera decoder (MIT, © Minhaz)
├── sql/
│   ├── sqlite-schema.sql   Schema for SQLite (default)
│   └── mysql-schema.sql    Schema for MySQL/XAMPP (importable in phpMyAdmin)
├── storage/               SQLite database + `.htaccess` deny
├── run-local.bat          One-command launcher: MySQL + database + dev server + browser
├── stop.bat               Stops the dev server (MySQL keeps running)
├── .htaccess              Blocks includes/, storage/ and data files
└── README.md
```

### Third-party code

Two JavaScript libraries are vendored under `assets/js/vendor/` so the system
works **offline** (no CDN, no internet needed at the venue):

| Library | Version | Licence | Used for |
|---|---|---|---|
| `qrcode-generator` (Kazuhiko Arase) | 1.4.4 | MIT | Drawing the QR images (student IDs, event posters) |
| `html5-qrcode` (Minhaz) | 2.3.8 | MIT | Reading QR codes with the camera |

Everything else is written for this project. All check-in logic, security and
storage happens in PHP; the browser never decides whether a scan counts.

---

## 10. Verified behaviour

The whole system was exercised end-to-end with a real PHP 8.3 web server
(`php -S`) against a live database. Every check below passed:

| Area | Verified behaviour |
|---|---|
| Installer | Creates 5 tables and seeds 16 students / 4 events / sample attendance; re-running is idempotent |
| Pages | All 16 pages render HTTP 200 for a signed-in officer (including filtered variants) |
| Sign-in | Correct credentials → session + redirect; wrong password → generic error, lockout after 5 tries, audit entry written |
| QR check-in | Valid token recorded with the correct on-time/late status and updated counters |
| Duplicate | Second scan of the same student/event → `duplicate`, original time shown, no second row |
| Forgery | Tampered signature → `invalid_token`; tampered poster link → rejected |
| Window rules | Closed event → `event_closed`; event days away → `not_yet_open` |
| Manual entry | Officer types a student number → recorded as `manual`; unknown number → `unknown_student` |
| Self check-in | Anonymous phone session scans the signed poster link + own ID → recorded with `source: self` |
| CSRF | JSON POST without `X-CSRF-Token` → HTTP 419; forms without a token redirect to sign-in |
| RBAC | Faculty is blocked from Students/Events/Accounts (with a message) but can open the scan station; an anonymous session posting `source: station` is refused with HTTP 403 `manual_denied` |
| CRUD | Create/delete event, toggle open/close, add student, re-issue QR ID, create/delete staff account, walk-in check-in, QR re-issue — all persisted and audit-logged |
| Exports | `attendance`, `students`, `summary`, `absentees` CSVs download with rows and a UTF-8 BOM |
| Audit log | Sign-ins, installs, exports, checks-ins (including method and station) all appear with actor, time and IP |
| Syntax | `php -l` passes on every PHP file in the project |

---

## 11. Known limitations

Be honest about these when you demo or deploy:

1. **Self check-in trusts possession of the QR.** Anyone holding a photo of a
   student's ID could check in as that student. For high-stakes events keep the
   staffed scan station as the source of truth (or add a PIN/face check).
2. **No offline queue.** If the venue Wi-Fi drops mid-scan the station shows a
   network error; use the manual box or try again when the connection returns.
3. **No enrolment table.** Every active student is considered eligible for every
   event. Add an enrolment/invitation table if you need restricted guest lists.
4. **SQLite is single-writer.** Perfect for one or two stations. For a large
   event with many stations switch `DB_DRIVER` to `mysql`.
5. **The audit log is append-only through the UI**, but a database
   administrator can still edit the file/table — as with any such log.
6. **QR images are drawn by JavaScript.** If JS is disabled the token is still
   shown as text and can be typed into the manual box.
7. **Times come from the server clock** (`APP_TIMEZONE`) — keep it NTP-synced.

## 12. Roadmap

- Enrolment table per event (restrict check-in to the invited students).
- Multi-day / multi-session events (morning and afternoon check-ins).
- Offline station queue (IndexedDB) that syncs when the connection returns.
- Optional second factor for self check-in (student PIN or face match).
- Registrar-ready PDF reports and certificates of attendance.
- WCAG AA accessibility pass and kiosk mode for the scan station.

---

*Built for ZDSPGC · PHP 8.1+ · SQLite (default) or MySQL 5.7+ / MariaDB 10.4+ ·
no build step, no framework, no internet required at runtime.*
