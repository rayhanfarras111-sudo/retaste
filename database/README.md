# Database ReTaste

The project uses the `rejeki_sore` MySQL/MariaDB database. The schema and
required default settings, categories, and subscription plans are in
[`rejeki_sore.sql`](./rejeki_sore.sql). It does not insert sample accounts,
shops, packages, or transactions.

## Local XAMPP setup

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Create an empty database named
   `rejeki_sore` in phpMyAdmin, select it, and import `rejeki_sore.sql`.
3. Open `http://localhost/retaste/`. Registration and login now use the
   `users` table. The default local connection is `127.0.0.1`, user `root`,
   and an empty password. Override these using `REJEKI_DB_HOST`,
   `REJEKI_DB_NAME`, `REJEKI_DB_USER`, and `REJEKI_DB_PASSWORD` as needed.

The SQL file drops and recreates its tables and views, clearing existing
records. Import it only into a new/empty database or after backing up any data
you need to keep. After import, dashboards remain empty until real records are
created. Register buyer or seller accounts through the application; provision
an admin account separately through a trusted database administrator.

Login and registration use MySQL. The buyer, seller, and admin dashboards
require an authenticated account and load their initial data from MySQL.
Seller shop profiles and admin approval/rejection decisions are stored in the
database. Shop profiles use the written address; sellers do not need to enter
latitude or longitude. Some other dashboard actions are not yet connected to
persistent backend endpoints.
