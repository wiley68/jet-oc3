# JET-OC3-001 / IMP-05A — отчет за owner review

> Historical checkpoint report. The obsolete `artifacts/` directory was later removed
> at the owner's request after R3 verification. Paths/commands below describe that
> checkpoint; current build commands and output are documented in
> [BUILD-INSTALLER.md](../docs/BUILD-INSTALLER.md).

Статус: **2.0.0 TEST / UNRELEASED инсталационен кандидат е създаден и проверен**.
Не е финален release и не е production approval. Реален MySQL и външен store E2E
остават DEFERRED. Работата спира за owner review.

## A. Git и source baseline

- Repository `/home/wiley/projects/jet-oc3`, branch `main`.
- HEAD остава `dd36a97a262fe1cd153bf4783dc9d8c583108a56`.
- Начало на IMP-05A: 18 modified tracked, 94 untracked файла, 0 staged.
- Предходните IMP-01–04 промени са запазени; няма commit, push, tag, checkout,
  reset, почистване на source, Docker или нова пълна OpenCart инсталация.
- Прочетени са IMP-02/03/04 отчетите, manifest и MySQL инструкциите.

## B. Integration readiness

Четирите публични submission пътя са активни условно през
`JetCreditSubmissionHttp` и общите runtime/submission услуги:

| Път | Контекст |
| --- | --- |
| `extension/mt_jet_credit/jetcredit/jet_send` | Product |
| `extension/mt_jet_credit/jetcredit/jet_send_cart` | Cart |
| `extension/payment/jet/confirm` | Checkout Jet |
| `extension/payment/jet_card/confirm` | Checkout Jet Card |

В controllers няма legacy mail/counter fallback или постоянно 503 тяло.
Native fixture тестовете доказват преминаване при правилни предпоставки и отказ
при липсващи security capabilities. Runtime includes, класове, language aliases и
assets се разрешават. Десетте OCMOD операции в седем file blocks се прилагат към
официалните SHA-pinned OC 3.0.3.8 и 3.0.5.1 default-theme fixtures. Проверени са
идемпотентност, abort при липсващ anchor и синтаксис на осем PHP snippets.

Изпълнени са действителните modified native mail/checkout callbacks: suppression
е само за точните Jet-owned order/scheme при finalization, а non-Jet поведението
се запазва в тези fixtures. Това не е live modification-cache refresh или браузърен
тест на чужд theme/event/OCMOD. Няма открита нова локална несъвместимост преди ZIP.

## C. Реален MySQL — DEFERRED

Няма изрично одобрена disposable база; `JET_TEST_DB_APPROVED` не е
`DISPOSABLE_ONLY`. Не са намерени mysql/mariadb/mysqld/mariadbd executables или
стандартните локални sockets. PHP mysqli и pcntl са налични. **Не е направена DB
връзка и не са изпълнявани DB-writing тестове.** Без approval флага harness-ът
извежда DEFERRED преди създаване на връзка.

[Точните owner команди](../docs/MYSQL-TEST.md) включват CREATE DATABASE/USER,
ограничени grants само за новата локална schema, проверка за празнота, частно
въвеждане на тестова парола и изпълнение. Няма реални credentials в документацията.

Разширеният `mysql-concurrency.php` е синтактично проверен, но новите SQL сценарии
**не са реално изпълнени**. Подготвени са actual installer/storage/service probes
за fresh initialization, InnoDB/exact uniques, осем конкурентни отделни връзки,
atomic references/rollback, прекъсване след reserve, persistent blocked states,
manual registration/replay без counter increment и reactivation без reset. Само
order/mail seams са doubles; няма native order API/SMTP доставка. Harness-ът
създава/изчиства пет конкретни `jet_probe_` fixture таблици само в предварително
празна одобрена база; няма основание да се отчита ACTUAL MYSQL PASS на тази машина.

## D. Builder и употреба

Нови reusable инструменти: `tools/build-installer.py` и Bash wrapper
`tools/build-installer.sh`, извън ZIP. Използват налични Python stdlib/PHP/Git,
без зависимости и без промяна на runtime файлове при packaging.

```bash
python3 tests/release-manifest.py
bash tools/build-installer.sh candidate --output artifacts/imp05a
```

Тази команда вече е изпълнена. За повторен build изберете нов `--output`, защото
съществуващ artifact directory се отказва. ZIP bytes за същия source/toolchain са
детерминистични: sorted entries, fixed timestamp/mode/compression. Build report
записва точни per-file hashes, source fingerprint, manifest/builder hashes,
toolchain, baseline HEAD/dirty status и `git_tag=null`, статус TEST / UNRELEASED.

Бъдещ committed build, само след отделен преглед и действителен commit/tag:

```bash
bash tools/build-installer.sh release --ref '<full-SHA-or-tag>' --output artifacts/final-review
```

Режимът чете immutable Git blobs, не dirty файлове, и ползва отделна `releases/`
директория. Branch/implicit HEAD не се приемат; няма publication. Реалните Git
object reads са проверени върху baseline HEAD, който правилно се отказва като
пълен build поради липсващ candidate manifest. Пълният бъдещ release pipeline е
тестван с изрично обозначен immutable Git adapter double; не е създаван commit/tag.

Builder-ът изисква точен explicit manifest, проверява missing/unexpected paths,
hash/size, XML/version, runtime includes/assets, всички runtime PHP файлове и
всеки ZIP entry/CRC/byte. Отказва traversal, duplicates, symlinks, стандартни
credential config файлове и output в runtime директорията. Windows Zone metadata,
tests/docs/tools/Git/IDE/caches/logs/credentials не влизат в ZIP. Manifest се
обновява само изрично за преглед чрез `tests/release-manifest.py --write`.

## E. Действителен кандидат

Директория:

```text
artifacts/imp05a/candidates/3c20354b8533c1daf0510e71d22f973d6b9a3ac9a078a09533524c1792805458/
```

Файлове в нея:

- `CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip` — **260363 bytes**.
- `CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip.sha256`.
- `build-report.json` — TEST / UNRELEASED, не Git tag build.

SHA-256 на ZIP:

```text
a4139818e55aac49ec982775dea33bdea57f6b10a91dfc10a99a0e923df05219
```

ZIP има **89 entries**, включително 70 PHP файла, точно от manifest. Корени:
`install.xml` и `upload/`, без обвиваща project директория; directory entries са
implicit. Няма други root файлове. PHPMailer, private runtime libraries, controllers,
models, language aliases, Twig/JS/CSS, images/fonts са включени. Независима повторна
проверка сравни всеки ZIP entry с manifest и source bytes, CRC, XML version и SHA,
както и report hashes на builder/manifest. Builder repeat tests доказаха еднакви
ZIP bytes и запазени runtime source bytes.

## F. Версия

Единствен authoritative version source:
`upload/system/library/jetcredit/version.php`, `JetCreditVersion::VERSION=2.0.0`.
Двата payment controllers го използват; XML и manifest са съгласувани. Това са
изрично подготвени **uncommitted candidate metadata промени**, не release/tag.
Историческите отчети/формула provenance 1.9.1 не са пренаписвани. Version mismatch
се отказва; packaging никога не bump-ва версия. Няма migration от 1.9.1.

## G. Пълна регресионна матрица

| Проверка | Резултат |
| --- | --- |
| `php tests/security.php` | PASS — 246 assertions |
| `php tests/financial.php` | PASS — 205 assertions, оригинални OC3 financial goldens |
| `php tests/submission.php` | PASS — 594 assertions, SQL/order/mail doubles |
| `php tests/install.php` | PASS — 47 checks, lifecycle SQL doubles |
| `python3 tests/escaping.py` | PASS — 348 assertions; IMP-01 preserved; XML/snippets |
| `node.exe < tests/security-ui.cjs` | PASS — 106 checks; трите JS файла и owned inline scripts parse/actual callbacks |
| `python3 tests/submission-contracts.py` | PASS — 131 checks; 102 PHP файла lint; банкови fixtures/provenance |
| `python3 tests/ocmod.py` | PASS — 288 checks; 10 operations, 8 PHP snippets |
| Native integration, OC 3.0.3.8 / 3.0.5.1 | PASS — 200 + 200 checks, включени в ocmod suite; negative readiness fixtures също PASS |
| `python3 tests/compatibility.py` | PASS — 218 checks; includes/languages/Twig/manifest |
| `python3 tests/release-manifest.py` | PASS — 89 exact runtime/XML assets |
| `python3 tests/builder.py` | PASS — 16 tests; exact coverage/roots/filename/version/hashes, deterministic repeats, exclusions, missing/unexpected, overwrite, source preservation, symlinks, PHP/XML failures, Git adapter isolation |
| Действителен candidate build + независим ZIP/source/hash/CRC/XML/report check | PASS — 89 entries, 260363 bytes, checksum по E |
| `bash -n tools/build-installer.sh` / Python AST | PASS — wrapper; 7 Python файла |
| `php -l tests/mysql-concurrency.php` | PASS; expanded DB сценарии не са изпълнявани |
| `php tests/mysql-concurrency.php` | DEFERRED — без одобрена база; no connection |
| `git diff --check` | PASS |
| Full store/browser/Twig/cache refresh/actual SMTP | DEFERRED — owner-operated test store |

Наличен interpreter: PHP 8.3.6; Node през съществуващ Windows WSL interop, без
инсталирани зависимости. Native fixture изпълнението не е full OC3 bootstrap.

Банковите/финансовите golden bytes остават непроменени:

```text
bank-oc3.json              1943d5638547d29f63e940415886078d02d8fea03fc4c2cb76aebb0698b5c836
bank-oc3-cart-options.json 602e133e851b3a06f06791cb7fa13ef5cdbb5c43aab48e3be9d8adf6b17b2153
financial-oc3.json         a9ae6c9c98c66de3dc1d9888575aa614066375f791b40a5aa2e983d48483a9cc
```

## H. Предпоставки за външен test store

Само owner-operated изолиран тест: backup/snapshot, exact supported native OC3,
default theme/checkout, store 0, active EUR base/value 1 и EUR session, HTTPS/PHP
flag, успешен native installer/OCMOD refresh, валидни съществуващи order statuses,
InnoDB/uniques/private marker/key, enable флагове на module и двете payment methods.
Нужен е capture-only SMTP sink без външен relay, само synthetic customers и
owner-controlled recipients. Manual mode също може да изпраща safe notifications.
Не използвайте production bank addresses или реални заявления. В тази фаза няма
външен store достъп, реална email доставка или production installation.

## I. Owner E2E план

[CLEAN-INSTALL.md](../docs/CLEAN-INSTALL.md) съдържа точните upload/refresh/module/
payment/recipient/status стъпки, SMTP containment, 503 диагностика, rollback и
матрица с очаквани резултати. Ред: одобрен disposable MySQL → изолиран clean test
store → manual Product/Cart/Jet/Jet Card → automatic само към sink → native non-Jet
контрола → concurrency/replay/tamper/stale/consent → контролирани failure/unknown
сценарии → безопасен success/cart cleanup → disable/reactivation/rollback.
Owner записва target versions, изходи и evidence без PII/ключове.

## J. Оставащи блокери и граници

- Реален MySQL/InnoDB contention/DDL/новите harness scenarios не са валидирани.
- Full store E2E, browser/Twig/cache-refresh, SMTP outcome и third-party conflicts
  не са валидирани; PHP syntax не сертифицира пълния OC3 runtime.
- Няма automatic intent renewal за втора покупка в същия session context. Изрична
  new-purchase policy/UI остава owner-review blocker за production; отделни E2E
  сценарии използват отделни clean sessions, без да изтриват claims/counters.
- 1.9.1 upgrade migration, други themes/versions, multi-store и непознати pricing/
  checkout extensions са извън поддържания обхват. Няма bypass на fail-closed guards.
- Преди final release са нужни owner evidence/review и отделна авторизация за
  Git/release операции. Не е създаден final committed artifact или publication.

## K. Кумулативен Git статус

След IMP-05A: **18 modified tracked + 103 untracked файла; 0 staged**.
Това включва всички предходни реализации, този отчет, builder/tests/docs/version
и трите candidate artifacts. `git diff --stat` показва само tracked changes и не
описва целия cumulative scope. Artifact build report пази отделна status snapshot
от момента преди записване на output/report файловете; runtime hash snapshot е
точен и остава непроменен. HEAD/branch са непроменени; няма commit/push/tag.

## L. Готовност за IMP-05B

**Да — за owner review и контролиран външен test-store validation с този кандидат.**
**Не — за final release/production**, докато MySQL и target-store/SMTP E2E evidence,
intent-policy решението и owner прегледът не са завършени. Спирам след отчета.
