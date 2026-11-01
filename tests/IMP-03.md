# JET-OC3-001 / IMP-03 — Secure Submission Lifecycle, Privacy & Concurrency

Локалният submission слой е реализиран и проверен с изолирани doubles.
**Четирите публични маршрута остават HTTP 503, а UI остава quote-only.**
Причината е проверената зависимост от native mail guard и безопасно success
свързване в IMP-04. Тестовият guard не е production интеграция.

## A. Начално Git/source състояние

Прочетени са заданието, `tests/IMP-02.md`, актуалните финансови/offer/controller/
Twig източници, оригиналният OC3 v1.9.1 банков код от Git и кешираният в Phase-1
native OC3 код. Не е достъпван външен магазин.

Начало: branch `main`, HEAD `dd36a97a262fe1cd153bf4783dc9d8c583108a56`,
16 modified tracked + 13 untracked файла; няма staged промени. IMP-01/02 са
запазени. Admin protection hashes, финансовите goldens и всички стари suites
продължават да минават. Версията остава 1.9.1.

## B. Файлове и schema

Нови runtime файлове:

- `upload/system/library/jetcredit/submission_schema.php` — reviewable CREATE TABLE
  и explicit counter seed SQL definitions; няма изпълнение или install hook.
- `upload/system/library/jetcredit/submission_store.php` — InnoDB repository,
  atomic acquisition, CAS transitions, reference transaction и schema probes.
- `upload/system/library/jetcredit/submission_identity.php` — стабилен server intent
  и session/customer/context replay authorization.
- `upload/system/library/jetcredit/submission_input.php` — consent/identity/
  recipient validation и версии на локалните acknowledgement текстове.
- `upload/system/library/jetcredit/submission_mail.php` — съвместим банков formatter,
  безопасни отделни известия, PHPMailer preflight/transport boundary.
- `upload/system/library/jetcredit/submission.php` — общ orchestration/state machine.
- `upload/system/library/jetcredit/native_mail_scope.php` — request-local guard
  contract; `available()` умишлено връща false до реалната core интеграция.
- `upload/catalog/model/extension/mt_jet_credit/submission_order.php` — native
  order build/read-back/finalization и conditional cart cleanup.
- `upload/catalog/model/extension/mt_jet_credit/submission.php` — общ OC3 service
  facade; не е свързан с публичните HTTP controllers в тази фаза.

Обновен IMP-02 файл:
`upload/catalog/model/extension/mt_jet_credit/financial.php` — quote DTO включва
стабилен `application_intent`; source запазва native cart IDs, total titles и
reward стойности за нормален order; recipient binding включва merchant и sender.
Формулата, коефициентите, валутните правила и промоционалният selector не са променяни.

Нови тестове/fixtures: `tests/submission.php`, `tests/submission-bootstrap.php`,
`tests/submission-contracts.py`, `tests/fixtures/bank-oc3.json`,
`tests/fixtures/bank-oc3-cart-options.json`, `tests/fixtures/oc3-native-mail-index.php`.
Обновен `tests/README.md`; добавен този отчет. OCMOD и frontend не са променяни
в IMP-03; непълна функционалност не е активирана чрез UI.

Една таблица `<DB_PREFIX>jet_submission`, `ENGINE=InnoDB`, explicit `claim` и
`counter` record types. DB uniqueness: `(store_id,record_type,intent)`,
`(store_id,record_type,order_id)` и `(store_id,bank_reference)`. Има durable
processing/bank/admin/customer/cleanup states, owner-run nonce, HMAC digests,
consent bit flags/version/time. Няма EGN, име, email, телефон, адрес или complete
bank body в таблицата. Schema version е 1.

Production repository получава **собствена native OC3 DB връзка**, недостъпна за
order моделите. Проверява autocommit, InnoDB, точните unique index колони, schema
колоните и explicit counter initialization. Няма runtime DDL, auto-seed,
генериране на module HMAC secret или промени на global DB/store settings.
Наличният постоянен IMP-02 secret остава задължителен.

## C. Workflow за четирите пътя

Общ нов request: POST/CSRF/method/context → server intent authorization → storage
readiness → read-only replay lookup. Само при липса на claim: verify offer,
повторно четене/recalculation на native source, current policy/recipient config,
consents/customer data, configured status и подготовка на order data → atomic claim.

| Път | Реализиран service workflow | Публичен маршрут |
| --- | --- | --- |
| Product direct | Авторитетни product/quantity/options/discount/special/tax; durable claim преди native addOrder; read-back на header/products/options/totals; едва след това bank/manual регистрация; съществуващата количка не се променя. | `jet_send`: 503 |
| Cart direct | Native cart и total chain, включително coupon/shipping/tax/fees; native order и read-back преди bank; compare-and-delete cleanup на точните първоначални cart редове. | `jet_send_cart`: 503 |
| Checkout jet | Съществуващият session-selected order и payment context; няма addOrder; unique order binding; configured `payment_jet_order_status_id`. | `jet/confirm`: 503 |
| Checkout jet_card | Същата защита и original order; card format marker; configured `payment_jet_card_order_status_id`. | `jet_card/confirm`: 503 |

Service matrix е тествана за двете схеми и за automatic/manual режими, общо
12 комбинации. Това не е live HTTP/OpenCart E2E. Native default-store URL,
price/tax/options/reward и total стойностите се запазват при order build.
Checkout финансовите стойности не се заменят с текущи catalog цени.

## D. State machine и replay

```text
claimed
  ├─ direct: order_creating
  └─ checkout / returned direct order: order_verifying
       ├─ failure/crash → order_uncertain / unfinished marker → REVIEW, no retry
       └─ order_ready
            ├─ manual → registered (manual_registered, not_attempted)
            ├─ preflight failure → before_transport_failed
            └─ automatic atomic reservation → bank_attempting
                 ├─ accepted → registered (transport_accepted)
                 ├─ false/exception/partial/unknown → bank_unknown
                 └─ local preSend failure → before_transport_failed
registered → finalizing → complete
                    └─ failure/crash → finalization_failed / unfinished → REVIEW
```

Всяка transition изисква store, claim ID, собствен owner_run и очакваното
състояние. Няма timeout takeover, автоматично resume или автоматично изтриване
на незавършени/неясни claims.

Intent е server HMAC derivation от session/context/entity с отделен purpose,
пазен в session. Няма browser-selected identity. Два едновременни първи quotes
получават същия intent и при session backend без write lock. Recalculation,
D/term промени и jet/jet_card switching не създават нов intent. Quote подписът
и application intent са различни понятия.

Authorization digest включва store/session/customer/context/entity/intent и
изключва scheme/recipient, за да не позволяват заобикаляне на existing claim.
Checkout order mapping остава server-side и след cleanup. DB order uniqueness
спира втори intent за същия order. Чужд authorization не получава outcome.

Само `complete` връща съществуващия `manual_registered` или `transport_accepted`
резултат и order ID. Това read-only replay се проверява преди текущ recipient/
expiry validation: завършена manual регистрация не се изпраща към банката при
последваща промяна на recipient configuration. Всички unfinished states остават
`review_required`; не създават нов order, reference, bank mail или notifications.

Няма автоматично „нова заявка“ за същия product/cart context след completion.
Explicit new-purchase UX/renewal policy трябва да се реши при owner интеграцията;
recalculation не се използва за reset на защита. Secret/session rotation може да
обезсили replay authorization и изисква контролирано owner reconciliation.

## E. Reference allocation и concurrency evidence

Automatic: preflight → кратка InnoDB транзакция → `SELECT ... FOR UPDATE` на claim
и counter → increment на counter + `bank_reference`/`bank_state=attempting` в
същата транзакция → COMMIT → transport. Няма SQL locks по време на SMTP.
Резервираният номер не се използва повторно. Manual и completed replay не
консумират номер. Legacy settings counter не участва в runtime authority.

Counter seed definition изисква explicit owner-provided last-reserved стойност.
Тя трябва да е съгласувана с исторически издадените банкови номера при бъдещата
инсталация; не се извлича автоматично от стария неатомарен counter. Gaps след
резервация/неясен commit/transport failure са очаквани.

PASS с in-memory SQL doubles: competing unique insert с interleaved заявки,
един owner/order/bank attempt, unique order binding, отделни references за claims,
CAS отказ на повторна резервация, rollback на reference+attempt, lost/uncertain
commit и acceptance persistence failure. **Реални MySQL/MariaDB DDL, lock waits,
deadlocks, uniqueness и parallel-connection tests са DEFERRED.** Няма предоставена
безопасна база; не е създавана Docker среда или пълен OpenCart install.

## F. Banking/admin/customer messages

- Bank: само валидираните адреси от непразен comma-separated recipient list;
  complete съвместим banking body; без автоматичен CC/BCC към admin/customer.
- Admin: отделна PHPMailer instance към `config_email`; registration/order/amount
  и маскирани инициали/телефон; без EGN и complete banking body.
- Customer: отделна instance към валидирания customer email; безопасно потвърждение
  с order/amount/result; без EGN или банковото съобщение.

Съобщенията са plaintext UTF-8/base64. SMTP debug е изключен и debug callback
не извежда съдържание. Exception/ErrorInfo/body не се логват или връщат. Няма
нови PHPMailer dependencies; използва се съществуващата bundled библиотека.
Preflight (`preSend`) е отделен от `postSend`. След transport false/exception
се пази `unknown`, включително partial recipient acceptance; няма automatic retry.
Успехът означава transport acceptance, не банково одобрение или крайна доставка.

Всеки secondary category има собствен durable pending→attempting→outcome state;
failure не връща workflow към bank. Replay не повтаря secondary mail. Ако процесът
умре след изпращане, attempting остава блокиран.

Golden compatibility: първите 6 fixtures са captured от оригиналния Git v1.9.1
body код преди новия formatter. Първоначалните два cart случая ползват underscore
variation input, който старият JSON decoder третира като липса на опции; тези
байтове са запазени. Два допълнителни baseline captures с валиден legacy cart
option JSON покриват опциите. Original fixtures не са редактирани заради теста.
Всички 8 body cases и subjects се сравняват; SHA pins пазят fixture bytes, а
fragment hashes се проверяват срещу оригиналния Git source. Field labels,
подредба, CRLF/semicolon формат и card marker са съвместими. Финансовите суми
идват от проверения order/calculation. Native fees/coupons са в principal/total;
старият банков формат не е разширяван с нови полета за тях.

## G. Manual registration

Само exact empty string `module_mt_jet_credit_mail=''` означава manual.
NULL/missing, whitespace-only, trailing/empty comma entry, невалиден адрес,
header/control characters и display-name syntax се отказват; няма fallback.

Manual запазва/създава и проверява order, durable `manual_registered`, без bank
mail и reference. Отделните безопасни известия изрично казват, че заявката НЕ е
изпратена до банката. Configuration change/replay не преобразува тази
регистрация в automatic application. Публичното активиране остава gated.

## H. Native order/mail lifecycle и privacy

Order creation не е включена в claim транзакция. При native MyISAM partial
creation claim вече е committed; грешка или process death остава блокирана.
Няма rollback assumption, автоматично addOrder retry или orphan deletion.
Order comment съдържа само `Jet registration <intent>` за owner reconciliation,
не EGN/пълно банково съобщение. Header/products/options/totals се четат обратно
преди bank. Direct данните ползват normal OC3 order полетата; EGN не се записва.

Използва се native `addOrderHistory()` с конфигурирания gateway status и
фиксиран безопасен comment. Bank outcome се записва **преди** history call.
Partial/history failure след acceptance остава review-only и не повтаря mail.
Не е въведен статус „банково одобрение“.

Провереният stock `mail/order::index` изпраща initial email при 0→positive status
дори при notify=false. Отделният `alert` callback може да изпрати admin mail.
Затова `native_mail_scope.php` е само contract за future narrowly scoped guards
в **двата** callbacks, за точния Jet-owned order/payment code в текущия call.
Тестовете доказват stock notify=false поведението и scope isolation/finally;
реален guarded native event execution е DEFERRED. Няма глобално изключване на
OpenCart email. Production `available()` е false и routes/UI остават затворени.

Cart cleanup е conditional DELETE по cart ID/session/customer/product/quantity/
recurring/options. Променени/нови редове остават, променен текущ pricing context
запазва количката. Cleanup failure не обезсилва completed registration и не
предизвиква bank retry. Product пътят никога не чисти реалната количка.
Own session order_id се disarm-ва преди cleanup; чужд order_id не се пипа.
Бъдещото success wiring **не трябва** сляпо да redirect-ва към stock
checkout/success, ако друг tab има различен активен order_id — core route може
да изчисти тази друга количка. Безопасният нормален success flow е IMP-04 gate.

Direct изисква трите съществуващи acknowledgement flags, checkout — двата.
Пазят се flags, UTC timestamp и SHA версия на реалните локални текстове/URLs,
без IP/имена/EGN. Не се твърди версия на remote bank legal document.
Validation отхвърля arrays/boolean, празни/твърде дълги стойности и raw/encoded
line controls. Names са до native 32 characters, phone 32 bytes, email 96 bytes.
EGN policy следва съществуващия UI: direct regex без нов checksum; checkout
непразни 1–10 digits. Не е добавена нова national-ID бизнес политика.

## I. Регресионна матрица

| Проверка | Резултат |
| --- | --- |
| IMP-01 `php tests/security.php` | PASS — 246 assertions |
| IMP-02 `php tests/financial.php` | PASS — 205 assertions; golden формула/граници/authority/HMAC/gates |
| `python3 tests/escaping.py` | PASS — 336 assertions; запазени admin guards/escaping, XML и 3 injected PHP fragments |
| `node.exe < tests/security-ui.cjs` | PASS — 75 checks; JS syntax/admin/quotes/stale/error/UI gates, без HTTP |
| IMP-03 `php tests/submission.php` | PASS — 601 assertions; 12 mode/path комбинации и failure/privacy/replay/concurrency contracts |
| `python3 tests/submission-contracts.py` | PASS — 79 checks; immutable golden provenance, SQL/privacy/gates и syntax на 50 PHP файла |
| `git diff --check` | PASS |
| Real MySQL/MariaDB concurrency/DDL | DEFERRED — няма safe test DB |
| Live OC3 routing/native event guard/history/MyISAM | DEFERRED |
| Browser/full Twig/normal success redirect | DEFERRED |

Общо **1542 assertions/checks** в шестте suites. PHP 8.3.6 е наличната тествана
версия. Windows Node е наличният WSL interop runtime; няма dependency install.
PHPMailer tests ползват transport doubles; допълнително real bundled preSend е
изпълнен без real postSend/SMTP/mail. Няма изпратено реално писмо/заявление.

## J. Integration зависимости и рискове

1. IMP-04 трябва да реализира и докаже narrow native customer/admin mail guards;
   само config flag или notify=false няма да отвори routes.
2. Installer трябва owner-authorized да provision-не постоянния secret, InnoDB
   schema и historical-safe counter seed. Нищо от това не е изпълнено тук.
3. Нужни са реални concurrent DB tests на отделни връзки и target MySQL/MariaDB,
   включително uncertain commit и MyISAM partial order сценарии.
4. Frontend/controller submit wiring и safe normal checkout success flow остават
   изключени. При активиране трябва да предават quote `application_intent` като
   `jet_intent`, offer/CSRF/current inputs и canonical consent flags към facade.
5. Source/pricing/notification OCMOD/events и session backends на конкретен магазин
   не са live проверени. Поддържан е default store 0; непознат pricing остава отказ.
6. Unfinished/unknown claims изискват owner reconciliation. Няма автоматична
   доставка, retry, lease takeover, purge или management/migration framework.
7. Една application identity на context остава до explicit future new-purchase
   действие. Secret/session rotation и claim recovery не трябва да заобикалят
   order uniqueness или да използват recalculation като reset.
8. Bundled PHPMailer modernization/packaging и native fraud/stock/total confirm
   behavior на реалната OC3 инсталация са integration проверки за следващата фаза.

## K. Кумулативно Git състояние

Branch `main`, HEAD `dd36a97a262fe1cd153bf4783dc9d8c583108a56`.
Кумулативно IMP-01+IMP-02+IMP-03: **16 modified tracked + 29 untracked файла**,
0 staged. IMP-03 добавя 9 runtime и 7 tests/fixtures/report файла и обновява
съществуващия untracked financial adapter/README.
Няма commit, push, tag, reset/cleanup, version bump, ZIP, live DB writes, schema
installation, банково заявление, реално писмо или външен store access.

## L. Готовност за IMP-04

Готови за review/integration: schema definitions, persistent claim/reference
repository, identity/consent/privacy слой, shared workflow, native order adapter,
mail formatter/transports и isolated regression evidence.

**Не е production-ready submission release. Всичките четири HTTP gates остават.**
IMP-04 може да продължи с конкретните core guard, initialization, safe success и
UI/controller activation зависимости, след owner review и integration validation.
Работата спира след IMP-03.
