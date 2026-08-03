-- OPTIONAL hardening step: a least-privilege application user.
--
-- The installers do NOT run this — they install using whatever
-- credentials are in .env, which for a development box is usually root. Run
-- this by hand as root when you want the application connecting with an
-- account that can do nothing but call procedures, then point DB_USER/DB_PASS
-- in .env at it.
--
-- The names below must match .env. Change them here if you changed them there.

CREATE DATABASE IF NOT EXISTS organice CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER IF NOT EXISTS 'organice_app'@'localhost' IDENTIFIED BY 'organice_dev_password_2026';

-- EXECUTE-only: the app can run stored procedures, nothing else. A SQL
-- injection that somehow got past the prepared statements would still have no
-- SELECT, INSERT or DROP privilege to reach for.
GRANT EXECUTE ON organice.* TO 'organice_app'@'localhost';
FLUSH PRIVILEGES;
