# JET-OC3-001 / IMP-05A-R2 — отчет за owner review

> Historical checkpoint report. The obsolete `artifacts/` directory was later removed
> at the owner's request after R3 verification. Paths/commands below describe that
> checkpoint; current build commands and output are documented in
> [BUILD-INSTALLER.md](../docs/BUILD-INSTALLER.md).

Статус: R1-F01 и R1-F02 са коригирани в локалния source и проверени чрез actual
source-level harnesses с SQL/native-order/mail doubles. **Няма нов ZIP.** Старият
IMP-05A кандидат е SUPERSEDED. Версията остава **2.0.0 TEST / UNRELEASED**.

## A. Начално Git състояние

Repository `/home/wiley/projects/jet-oc3`, branch `main`, HEAD
`dd36a97a262fe1cd153bf4783dc9d8c583108a56`. Начало: **18 modified tracked,
103 untracked, 0 staged**. Предходните IMP-01–05A промени са запазени. Прочетени са
IMP-03/04/05A отчетите, clean-install инструкциите и текущите identity/offer/
submission/store/receipt/checkout/frontend реализации; R1 е finding baseline.
Няма Git commit/push/tag/reset/checkout или destructive source cleanup.

## B. R1-F01 — причина и корекция

Старият session/context-only HMAC можеше да определи само едно заявление за
използвания контекст/същия продукт. Completed replay беше правилно защитен, но
нямаше изрично следващо поколение и нова покупка се превръщаше в стар резултат.

`submission_identity.php` вече различава purchase scope, generation, immutable
application ID и historical ownership. Само изричният HTTPS POST
`extension/mt_jet_credit/jetcredit/new_purchase`, с CSRF/owner/eligible prior claim,
създава следващото durable поколение. Product/Cart/Checkout показват established
outcome, historical receipt link и ясно действие за нова покупка. Refresh/нов cart
сами не обновяват identity. Same-product quantity/options и нов cart могат да се
финансират чрез следващо изрично поколение в същата сесия.

## C. R1-F02 — причина и корекция

Старият checkout helper пазеше само session snapshot и създаваше нов native draft
при променен snapshot/method. Нямаше durable owner преди addOrder.

`checkout.php` вече записва autocommitted `draft_creating` reservation, unique по
intent, преди native addOrder. Само insert winner с неговия server owner-run nonce
може да създава. После има native read-back comparison и owner/state CAS към
`draft_bound` с един order ID и keyed persisted-data digest. Contender/crash не
получава автоматично takeover. Bound draft се ползва повторно само след проверки.
Snapshot/method промяна се отказва, без втори addOrder/overwrite. Explicit old
native POST с intent остава исторически; необвързаният native page request може да
зареди active server generation, но никога не създава следващо поколение сам.
Native non-Jet branch остава непроменен.

## D. Нов модел за ownership, generation и identity

Scope HMAC обвързва store 0, native session ID, customer ID, product/cart/checkout
и product ID само при Product. Application ID е server HMAC на scope + generation.
Първо поколение е стабилно/implicit и не изисква write при всяка калкулация. Следващите
поколения са DB metadata; active е latest durable generation. Session intent
стойностите са hints, без concurrency authority. Cart fingerprint, options/quantity,
order ID и Jet/Jet Card не определят нова покупка. Двата checkout метода споделят
една identity в рамките на поколението.

Historical authorization проверява оригиналния scope/intent независимо от active
pointer. Новият signed offer носи exact intent/generation и ownership/financial
binding. Не се provision-ва или rotate-ва HMAC key при нова покупка. Модулната
версия е непроменена; вътрешната fresh schema revision е 2.

## E. Idempotency и concurrency guarantees

`SubmissionStore::advanceGeneration()` използва dedicated connection с autocommit,
InnoDB и transaction. Заключва prior executable claim с `SELECT ... FOR UPDATE`,
проверява owner **преди** historical information и допуска само `complete` с
`manual_registered` или `transport_accepted`. Под същия lock връща вече съществуващ
successor или записва server-derived N+1. Unique predecessor и unique scope/
generation са независими backstops. Няма разчитане на session write lock.

Browser-generated random request token обозначава retry; в DB се пази keyed digest.
Той не избира application ID. Еднакви или различни overlapping click tokens за
един predecessor водят до един child. Стар transition retry след по-късни покупки
връща неговия оригинален child, не active/latest ID. Не може да се прескочи held
child чрез повторно използване на стар completed ancestor. Rollback не оставя child;
при committed-but-lost response retry връща вече записания child.

Това са exact SQL/uniqueness guarantees на дизайна плюс локални source-level tests,
**не реално измерен InnoDB contention**. Няма държани locks през native addOrder/SMTP.

## F. Checkout reservation и crash поведение

| Граница | Резултат |
| --- | --- |
| Преди committed reservation | Няма native creation; следващ успешен owner може да продължи |
| След reservation, преди addOrder | Durable held `draft_creating`; няма automatic takeover |
| По време на addOrder / partial MyISAM write | Held reservation; native rows може да останат; няма retry/delete |
| След addOrder, преди потвърдено binding | Held reservation, възможен unbound order; owner reconciliation |
| След committed binding, изгубен response | Един bound order се използва повторно след проверка |
| Променен snapshot/method или persisted native данни | Контролиран отказ; без overwrite/втори addOrder |
| След completed/unknown bank claim | Authorized original outcome/review; без нов transport |

Reuse проверява current owner, store/customer, scheme, status 0, EUR/value/amount,
server snapshot и read-back products/options/totals digest. За завършен historical
claim връщането на стар order е само за outcome replay, не изпълнение на новите
финансови данни. След explicit next generation има отделна reservation/order.

Гаранцията е **един durable creation owner и най-много един успешно bound native
order за intent през тази интеграция**, при запазена защита от повторен bank attempt.
Не се твърди global exactly-once physical row creation в native/third-party code.
Crash може да остави partial/unbound native order. MyISAM writes не се rollback-ват
по предположение с InnoDB metadata. Recovery остава owner-operated, без lease retry.

## G. Изолация на стари offer/receipt/replay операции

Submission използва подадения immutable ID, проверява signed offer identity и
поколението, и не го заменя с current session pointer. Completed replay предхожда
fresh-offer verification, така че стар manual claim остава manual дори след нови
recipient настройки. Стар offer не може да стане executable като нов intent.

Rendered forms и quote DTO носят exact ID. Липсващ ID в legacy quote адресира само
generation ONE, никога latest. Старите completed quotes връщат старото потвърждение,
а held outcomes остават review-required. Browser проверява response identity,
request sequence и mount lifetime; delayed old quotes/transitions се игнорират.

Historical receipts остават обвързани с оригиналния completed claim и неговия owner,
независимо от active generation. И offer, и receipt запазват 1800 секунди expiry.
Receipt GET не стартира intent/order/bank attempt. Lost guest session не се
авторизира само с order ID. Променен customer/session се отказва преди outcome.

## H. UI интеграция

Product/Cart/двата checkout метода запазват калкулатора и използват общия JS:
completed outcome + safe historical link + „Започни нова покупка на изплащане“.
Само click изпраща new-purchase POST. Double click е блокиран локално, а DB пази
idempotency независимо от browser lock. Retry запазва token и prior ID.

При успешен transition old offer state се изчиства, consent/EGN controls се reset-ват
и се заявява fresh quote за новия ID. Checkout първо извиква native confirm за
fresh server order data/reservation. Неуспех в transition/draft/quote не дава
financing success. Success receipt page има безопасен return link към съответния
shopping context; връщането там само по себе си не обновява поколение.

Важно UX ограничение: method/snapshot промяна на вече bound, незавършен draft е
отказ, а не нов draft. Върнете първоначалния метод/snapshot или потърсете owner
review. При completed предишна покупка explicit new generation позволява нов
избор на метод. Няма redesign или промяна на финансови правила.

## I. Schema и installation

Остава една `jet_submission` таблица. Fresh revision 2 добавя `generation`/`draft`
record types, nullable `purchase_scope`, `purchase_generation`, `previous_intent`,
`order_digest` и unique `jet_successor`/`jet_generation`. Existing intent/order/
reference uniqueness, bank counter semantics, claim workflow и notification states
са запазени. Metadata rows не консумират банков reference. Не се изтриват исторически
claims/orders/references; няма runtime DDL, ALTER или upgrade migration.

Readiness изисква schema revision 2, новите колони и точните indexes. Налична
unreleased revision 1 е incompatible и се отказва без reset/re-provision. Следващият
кандидат е за отделна чиста инсталация/snapshot, не upgrade на 1.9.1 или стар RC.
Не е правена реална DB инсталация/връзка/промяна.

## J. Автоматизирана регресионна матрица

| Проверка | Резултат / доказателство |
| --- | --- |
| `php tests/security.php` | PASS — 246, IMP-01 guards/authorization |
| `php tests/financial.php` | PASS — 205, EUR/formula/thresholds/verified offers |
| `php tests/submission.php` | PASS — 594, four-path service/replay/privacy/manual/counter/failures |
| `php tests/install.php` | PASS — 47, actual fresh definitions/lifecycle с SQL doubles |
| `php tests/purchase.php` | PASS — 380, T-NEW-PURCHASE/T-CHECKOUT-BINDING чрез actual identity/repository/service/helper/controllers с shared SQL-row/order/mail doubles |
| `node.exe < tests/security-ui.cjs` | PASS — 148, actual UI callbacks/new action/retry/delayed response identity/sequence |
| `python3 tests/escaping.py` | PASS — 354, Twig/encoding/XML/snippets/IMP-01 preservation |
| `python3 tests/submission-contracts.py` | PASS — 133; 104 PHP files lint; banking golden provenance/privacy/SQL contracts |
| `python3 tests/ocmod.py` | PASS — 288; 10 operations/8 PHP snippets; pinned OC 3.0.3.8/3.0.5.1 |
| Native modified callbacks, included in ocmod suite | PASS — 200 + 200; native guards/checkout/public routes/non-Jet behavior; DB/order/SMTP doubles |
| `python3 tests/compatibility.py` | PASS — 222, includes/language/Twig/manifest |
| `python3 tests/release-manifest.py` | PASS — 89 exact runtime/XML assets; updated hashes, no ZIP |
| `python3 tests/builder.py --source-only` | PASS — 12 source tests; 4 archive-producing tests SKIPPED/DEFERRED по забраната за ZIP generation |
| `php tests/mysql-concurrency.php` | DEFERRED преди connection, без одобрена disposable DB |
| Python AST / Bash syntax / `git diff --check` | PASS |
| Full browser/Twig/store/SMTP integration | DEFERRED — external owner validation |

Новите 380 проверки покриват всички четири пътя, first automatic/manual, replay,
second/third generation, same-product quantity/options, нов cart с друг продукт,
new checkout, scheme changes, foreign customer/session, repeated/overlapping
transitions, rollback/uncertain commit, original-child retries, historical/expired
receipt, lost guest session, old forms/offers/quotes/native POST, unknown prior/child,
reservation contention, read-back reuse/tampering и четирите crash boundaries.

Предишната native assertion, изискваща втори draft при gateway switch, е заменена
с отказ/същия intent/без втори order. Всички съществуващи replay/guard контракти са
запазени; protected admin hashes и банковите/финансовите golden fixtures са непокътнати.
Не са променяни формули, прагове, банков templates или валутни режими.

## K. Реални integration gaps

Няма одобрена disposable MySQL база; actual contention/DDL/commit uncertainty
остават DEFERRED. Owner-only harness е разширен с осем-worker generation successor
и draft reservation/binding probes, но тук не е изпълнен с DB. PHP syntax е PASS;
това не е ACTUAL MYSQL PASS. SQL doubles не симулират InnoDB lock waits/server
failover. Native fixture tests не са full OC3 bootstrap/cache refresh или browser.

Остават owner target-store проверки за native Request/session backend, HTML/Twig/
checkout AJAX flow, метод/snapshot refusals, real MySQL/MariaDB, contained SMTP
outcomes, unknown COMMIT/draft recovery и third-party OCMOD/event conflicts. Следващата
build стъпка трябва да повтори четирите archive-producing builder tests и checksum/
ZIP-byte coverage. В R2 забраната за ZIP generation е спазена, включително за тестове.

## L. Кумулативен Git и artifacts статус

Край: **18 modified tracked + 108 untracked файла, 0 staged**; branch/HEAD са
непроменени. Scope включва всички предишни промени, R2 runtime/tests/docs и този отчет.
Няма commit/push/tag или external store access/real bank mail/financial application.
Няма Docker, full local OpenCart install или live DB writes.

`tools/build-installer.py` и Bash wrapper не са променяни. Само тестовият runner
има source-only режим. Manifest е обновен изрично като source metadata; не е build.

Има точно един ZIP — старият IMP-05A archive, запазен със същия SHA-256:
`a4139818e55aac49ec982775dea33bdea57f6b10a91dfc10a99a0e923df05219`.
Оригиналният build report/checksum са запазени. `artifacts/imp05a/SUPERSEDED.md`,
clean-install и builder инструкциите ясно го маркират SUPERSEDED; няма нов кандидат.

## M. GO / NO-GO

**Условно GO за следваща, отделно одобрена стъпка за нов TEST / UNRELEASED installer**
от текущия source след owner review. Локалните R1-F01/F02 regression gates са PASS.
При тази бъдеща стъпка се изискват пълните archive-producing checks и нов build
report/checksum; старият ZIP не валидира R2 и не трябва да се използва за това.

**NO-GO за final release/production**, докато real MySQL и owner target-store/
contained SMTP evidence не са налични. Не се твърди global exactly-once physical
native order creation. Спирам след този отчет и чакам owner review.
