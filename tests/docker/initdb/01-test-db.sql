-- The headless test suite (CIVICRM_UF=UnitTests) runs against civicrm_test.
-- Without this grant the civicrm user cannot create it, civikitchen logs
-- "WARN: could not pre-create civicrm_test", and the suite silently falls
-- back to the LIVE database — leaking test settings into the demo instance.
CREATE DATABASE IF NOT EXISTS civicrm_test;
GRANT ALL PRIVILEGES ON civicrm_test.* TO 'civicrm'@'%';
-- Civi\Test's installer runs SET GLOBAL (e.g. innodb_flush_log_at_trx_commit)
-- which needs SUPER. Throwaway dev DB — acceptable.
GRANT SUPER ON *.* TO 'civicrm'@'%';
