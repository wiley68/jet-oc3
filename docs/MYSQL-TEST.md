# Owner-operated disposable MySQL/InnoDB validation

IMP-05A / R2 status: **DEFERRED**. No explicitly approved disposable database or approval
flag exists here; no mysql/mariadb client/server executable or conventional local
socket was found. PHP mysqli and pcntl are available. No connection or DB-writing
test was attempted. Mock/native-fixture suites are separate evidence.

The following commands are for the **owner**, only after explicitly approving a
local disposable test dataset. They are not commands the agent has executed. Use
an existing owner-managed MySQL/MariaDB server on loopback, never store credentials,
a real store schema, a remote host or an already populated database. No Docker or
new full OpenCart installation is needed. If a safe server is unavailable, keep the
result DEFERRED; do not redirect the harness to an existing store.

## Exact preparation and run

1. With your normal local DB administrator client, inspect that the proposed schema
   does not exist (`SHOW DATABASES LIKE 'jet_oc3_disposable_imp05a';`). If it exists,
   stop and select another unused `jet_oc3_disposable_<suffix>` name; do not drop it.
   Then execute the SQL below. Replace the password placeholder with a new local
   test-only secret; this document contains no usable credentials. Do not reuse a
   production account. `CREATE` intentionally lacks `IF NOT EXISTS`.

   ```sql
   CREATE DATABASE jet_oc3_disposable_imp05a CHARACTER SET utf8mb4;
   CREATE USER 'jet_oc3_probe'@'127.0.0.1' IDENTIFIED BY '<OWNER_CHOSEN_TEST_SECRET>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER
     ON jet_oc3_disposable_imp05a.* TO 'jet_oc3_probe'@'127.0.0.1';
   SHOW GRANTS FOR 'jet_oc3_probe'@'127.0.0.1';
   ```

2. Confirm the dedicated account connects only to the empty approved schema:

   ```bash
   mysql --host=127.0.0.1 --port=3306 --user=jet_oc3_probe --password \
     --database=jet_oc3_disposable_imp05a --execute='SELECT VERSION(); SHOW TABLES;'
   php -r 'exit(extension_loaded("mysqli") && function_exists("pcntl_fork") ? 0 : 1);'
   ```

3. From the repository root, approve **only that disposable schema** and run:

   ```bash
   export JET_TEST_DB_APPROVED=DISPOSABLE_ONLY
   export JET_TEST_DB_HOST=127.0.0.1
   export JET_TEST_DB_PORT=3306
   export JET_TEST_DB_NAME=jet_oc3_disposable_imp05a
   export JET_TEST_DB_USER=jet_oc3_probe
   read -rsp 'Disposable database password: ' JET_TEST_DB_PASSWORD
   echo
   export JET_TEST_DB_PASSWORD
   php tests/mysql-concurrency.php
   unset JET_TEST_DB_PASSWORD JET_TEST_DB_APPROVED JET_TEST_DB_HOST JET_TEST_DB_PORT JET_TEST_DB_NAME JET_TEST_DB_USER
   ```

   The shell reads the password privately; do not place it in history, source or
   reports. Record PASS/failure, server/PHP versions and test commit/source hashes.
   Successful output is explicitly **ACTUAL MYSQL PASS**; offline tests cannot
   emit this label. Without the approval flag the script prints DEFERRED and exits
   before constructing a DB connection.

## Scope and expected checks

The harness refuses non-loopback hosts, names outside
`jet_oc3_disposable_[a-z0-9_]+`, missing mysqli/pcntl, and **any existing table**.
Inside the approved empty schema it creates only five `jet_probe_` fixture tables:
`jet_submission`, `jet_kop`, `setting`, `order`, `currency`. The last three are
minimal synthetic metadata, not real native orders or a full OpenCart install.
Only these five names are dropped in the normal cleanup/finally path. A killed
process can leave evidence: inspect it before rerunning; never delete unrelated
tables or point the script at a store to make it pass.

The executable uses actual installer/schema/storage/service code to check:

- Fresh initialization with one protected counter/marker/key row, InnoDB and the
  exact required unique indexes (intent/order/reference).
- Eight same-intent workers with separate connections and one winner; eight
  distinct concurrent reservations with unique serialized references.
- Duplicate native-order binding refusal; transactional rollback when reserving
  the reference cannot persist the attempt marker.
- Simulated process interruption **after committed reservation**, durable
  `bank_attempting`/`bank_unknown` and other uncertain states on another connection;
  no second reservation/takeover or side effects on authorized replay.
- Actual manual service registration with SQL storage, completed read-only replay,
  no bank reference and no counter increment.
- R2: eight concurrent generation transitions from one completed claim return one
  durable successor; eight draft reservation workers have one owner and one bound
  synthetic order ID, without consuming bank references. These prepared scenarios
  are also unexecuted here.
- Actual installer reactivation without rewriting defaults or resetting the entire
  counter/marker/secret record.

Order/finalization and mail seams remain non-delivering doubles. This harness does
not exercise native order tables, SMTP, network partition during COMMIT or a full
store/theme. Those remain the [owner E2E matrix](CLEAN-INSTALL.md). Run the disposable
suite against the target MySQL/MariaDB version too; do not infer production readiness
from a different local database engine or offline SQL doubles.

After recording results, the owner may remove **only this explicitly created empty
fixture database and dedicated account**, using their normal administrator process.
This is separate from a store uninstall, which must preserve business records.
