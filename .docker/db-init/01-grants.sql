-- Grant the dev user global privileges so headless extension tests can
-- create/drop their own scratch database (civicrm_test by default).
GRANT ALL PRIVILEGES ON *.* TO 'civicrm'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;

-- Scratch database for headless tests (tests/phpunit/bootstrap.php points
-- CIVICRM_DSN here under CIVICRM_UF=UnitTests — never the main dev DB).
CREATE DATABASE IF NOT EXISTS civicrm_test;
