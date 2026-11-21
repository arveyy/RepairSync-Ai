# RepairSync AI (PHP + MySQL edition)

AI-assisted diagnostics and workflow management for gadget repair shops (capstone prototype).
Plain PHP 8 + MySQL, no Composer, no build step. Runs on XAMPP.

## Run it on XAMPP (Windows / macOS / Linux)

1. Install XAMPP with **PHP 8.1 or newer** (https://www.apachefriends.org).
2. Copy this whole folder into XAMPP's web folder and name it `repairsync`
   (Windows: `C:\xampp\htdocs\repairsync`).
3. Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**.
4. In your browser open **http://localhost/repairsync/setup.php** (once).
   It creates the database `repairsync`, the tables, the AI knowledge base (108 cases) and demo data.
5. Open **http://localhost/repairsync/** and sign in.

Demo accounts (password `RepairSync#2026`): `owner@`, `admin@`, `frontdesk@`, `tech1@`, `customer@`
(and `admin2@` for a second shop) `repairsync.test`.

Alternative to step 4: import `database/schema.mysql.sql` in phpMyAdmin (Import tab), then open `setup.php`
to add the data. Database settings (user `root`, empty password) are in `config.php`; to change them copy it to
`config.local.php` and edit there.

### Troubleshooting
| Message | Fix |
|---|---|
| "MySQL does not seem to be running" | Start MySQL in the XAMPP Control Panel. |
| "Access denied for user" | Put your MySQL user/password in `config.local.php`. |
| Pages load but buttons show errors / `/api` is 404 | Apache's `mod_rewrite` must be on (it is by default in XAMPP) and `AllowOverride All` for htdocs. |
| Blank page after upgrade | Clear the browser cache (Ctrl+F5). |

## Shop approval and personal booking links

- A business that registers starts as **Pending**. It can sign in and set up staff and its knowledge base, but it is not listed and clients cannot book with it.
- The **platform administrator** reviews it under *Shop registrations* (demo login `platform@repairsync.test`, password `RepairSync#2026`): **Approve**, **Reject** (reason required) or later **Suspend**. Rejected or suspended shops cannot sign in; they see the reason.
- Every approved shop has its own link, shown to its admin on the Workflow board (Copy link): `http://localhost/repairsync-php/public/#/shop/<shop-slug>`. It opens that shop's page with the free AI check and online booking for that shop only.
- To create your own platform administrator (instead of the demo one), put this in `config.local.php` and open `setup.php` again:
  `return ['platform_admin' => ['name' => 'Your Name', 'email' => 'you@example.com', 'password' => 'a-strong-password']];`

### Updating an existing install
Copy the new files over the old ones, then open `setup.php` once more. It is safe to re-run: it adds the new columns and the platform account and keeps your data. If registering a shop later shows an error about a CHECK constraint, run this in phpMyAdmin (SQL tab) on the `repairsync` database and try again:
`ALTER TABLE shops DROP CONSTRAINT chk_shop_status; ALTER TABLE users DROP CONSTRAINT chk_user_role;`

## TIM'SOLUTIONS as the test shop

- The default shop is **TIM'SOLUTIONS** with its three branches: Tisa (Main Branch, 8:00 AM to 9:00 PM), SM JMall Cyberzone and Lapulapu (Gun-ob). Re-running `setup.php` renames an older demo copy and sets the Tisa hours.
- **Shop settings** (administrator): edit the shop name, and each branch's address, contact number and opening hours. They appear on the shop's booking page.
- **Import data** (administrator): load past repairs and payments from a CSV (`templates/TIMSOLUTIONS_past_repairs_template.csv`). Preview first; nothing is saved until you confirm. **Customer names and phone numbers are never imported.** Every imported job is attached to one anonymized "past customer", and `job_no` prevents duplicates. Imported payments feed the sales graph and reports; repairs with a diagnosis can also be added to the AI knowledge base.
- **Knowledge base > Import cases** loads AI cases only (`templates/TIMSOLUTIONS_ai_cases_template.csv`).
- Real records need the owner's permission. Keep the original files private and out of GitHub.

## Without Apache (quick local test)
`php -S localhost:8000 router.php` then open http://localhost:8000/setup.php and http://localhost:8000/.
Set `REPAIRSYNC_DB_DRIVER=sqlite` to use a file database instead of MySQL (no server needed).

## Tests
`php tests/run-all.php` - 16 unit + 22 integration tests and the AI accuracy check (48 held-out queries:
top-1 43/48 = 89.6 %, top-3 47/48 = 97.9 %, identical to the earlier Node version).

## Layout
- `public/` the website (HTML/JS/CSS) and `public/api/index.php` (front controller)
- `src/` PHP code: `App.php` (all API routes), `Rag.php` + `Nlp.php` (BM25 retrieval), `Auth.php`, `Workflow.php`, `Db.php`, `Seeder.php`
- `database/` MySQL schema (`schema.mysql.sql`), SQLite twin, seed data
- `setup.php` one-time installer, `config.php` settings, `tests/`

## Security notes
Passwords hashed with `password_hash`; signed HttpOnly session cookie; anti-CSRF header on every write; role-based
permissions; per-shop data isolation; prepared statements only; login/public rate limits stored in MySQL; audit log;
CSP and other headers (also set in `public/.htaccess`). Set `secure_cookie` to `true` when serving over HTTPS.
The `src/`, `data/`, `database/`, `tests/` folders contain `.htaccess` files that block direct browser access.

## Honest limits
Demo data (shops, repairs, 2 years of sales) is synthetic. SMS/e-mail and payments are simulated records.
The optional Claude summary needs an API key in `config.local.php` and was not exercised here.
The MySQL path was written against standard MySQL/MariaDB syntax but all automated tests ran on SQLite
(no MySQL server was available while building) - run `php setup.php` and the checks above on your XAMPP to confirm.

## Home page screenshots
The home page ("See it in action") uses real screenshots stored in `public/img/shots/` (board, ai, check, track, reports, kb). To refresh them after changing the screens, take new screenshots and replace those files (same names). `router.php` now also serves .jpg/.webp for the PHP built-in server; Apache needs nothing.
