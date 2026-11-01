# JET-OC3-001 / IMP-04 — Native integration and conditional activation

**Четирите submission маршрута са условно активирани в кода. Няма оставащ
безусловен HTTP 503 маршрут.** При липсваща/несъвместима clean инсталация,
неприложени native guards, неподдържан build или HTTP вместо HTTPS заявката
остава HTTP 503 без нов claim/order/mail. Няма инсталация или активиране на жив магазин.

## A. Baseline и кумулативен Git статус

Начало: branch `main`, HEAD `dd36a97a262fe1cd153bf4783dc9d8c583108a56`,
16 modified tracked + 29 untracked файла, 0 staged. Прочетени са IMP-02/03
отчетите, Phase-1 решенията и актуалният код. Инвентаризирани са 61 runtime файла
без Windows streams. Записани са четирите начални 503 gates и service dependencies.

IMP-01 authorization/escaping и IMP-02 financial/offer правилата са запазени.
IMP-03 claims, order-before-bank, reference allocation, mail uncertainty и privacy
services са свързани с native OC3. Няма Git reset/cleanup или изтриване на стара работа.

Край: същият branch/HEAD; **18 modified tracked + 94 untracked**, 0 staged.
Няма commit, push, tag, version bump, final ZIP или live DB/store действие.
Версията остава 1.9.1; 2.0.0 не е издадена.

## B. Файлове

Нови runtime компоненти:

- `upload/system/library/jetcredit/installation.php` — clean install, private
  counter/secret/marker initialization, legacy refusal и reactivation.
- `upload/system/library/jetcredit/runtime.php` — request-local readiness.
- `upload/system/library/jetcredit/admin_settings.php` — explicit settings allowlist.
- `upload/system/library/jetcredit/submission_http.php` — общ public HTTP boundary.
- `upload/system/library/jetcredit/receipt.php` — подписана, session/customer-bound разписка.
- `upload/catalog/model/extension/mt_jet_credit/checkout.php` — native draft reuse/claim pinning.
- `upload/catalog/controller/extension/mt_jet_credit/success.php` и съответният
  `view/theme/default/template/extension/mt_jet_credit/success.twig` — безопасен success layout.
- 18 catalog language route файла: payment `jet`, `jet_card`, module `success` за
  `bg-bg`, `en-gb`, както и съвместимите aliases `bg`, `bulgarian`, `en`, `english`.

Обновени runtime файлове от предните фази:

- `install.xml`.
- Admin controllers: `extension/module/mt_jet_credit.php`, `extension/payment/jet.php`,
  `extension/payment/jet_card.php`; admin module Twig.
- Catalog controllers: `extension/mt_jet_credit/jetcredit.php`, payment `jet`/`jet_card`.
- Catalog models: `extension/mt_jet_credit/financial.php`, `submission.php`, `submission_order.php`.
- Shared `creditjet_offers.js`.
- Libraries: `native_mail_scope.php`, `submission_identity.php`, `submission_schema.php`,
  `submission_input.php`, `submission_mail.php`.

Добавени са install/native/OCMOD/compatibility tests, 26 SHA-pinned official source
fixtures плюс manifests/event excerpts, owner MySQL script/plan и clean-install
документация. Обновени са старите suites и README за разрешената IMP-04 интеграция.
Всички банкови/финансови golden bytes остават непроменени. Admin promotion model,
защитените mutation методи и всички съществуващи admin language variants са запазени.

Точният runtime release списък е `docs/release-manifest.json`: **88 файла** —
`install.xml` и 87 `upload/` assets с SHA-256 и размери. Tests/docs/Git/credentials/
cache/`:Zone.Identifier` не влизат в архива. Windows companion streams са оставени
в working tree; не е правен cleanup. Root layout на бъдещия ZIP: `install.xml` + `upload/`.

## C. Install/schema/secret lifecycle

Една InnoDB `jet_submission` таблица запазва claim/counter record types и
DB uniqueness за application intent, native order binding и bank reference.
Private counter row има schema version, `jet-oc3-clean-v1` installation marker и
persistent HMAC secret. Това е installation contract marker, не release version bump.

Чиста инсталация през native admin:

1. Проверява authenticated modify permission, текущ user_token и default store 0.
2. Взема кратък advisory install lock за съответния DB/prefix.
3. При existing valid marker проверява състоянието и връща reactivation, без reset.
4. При legacy promotion table/settings/Jet orders без валиден marker отказва
   initialization и показва administrator-facing обяснение; няма автоматична миграция.
5. Проверява EUR base, active EUR/value 1; дефинира schema и promotion table.
6. С един INSERT записва counter=0, marker и secret в `initializing` state.
7. Записва само изрично дефинирани disabled defaults; никакъв arbitrary POST import.
8. Преминава към ready `counter` state след defaults. Partial setup остава блокиран.

Secret се генерира еднократно с random_bytes или се приема валиден предварително
provisioned `JETCREDIT_OFFER_SECRET`. Пази се в protected counter record; не се
извежда към Twig/JS и не се записва от ordinary settings POST. Последващ constant
трябва да съвпада с record; mismatch се отказва. Няма request-time key provisioning.

Runtime само чете initialization; проверява InnoDB/index/column/counter readiness.
Няма runtime DDL/seed или implicit migration. Claims използват отделна native DB
връзка, независима от native order transaction behavior. Order storage engines
не са променяни. Първият automatic reference е 1; manual не консумира номер.

Uninstall изключва module status и запазва promotions, claims, secret, marker,
номерацията и historical native orders. Reactivation не ги нулира. Нито една
реална installation/schema операция не е изпълнявана в тази задача.

## D. Native OpenCart mail guard

Официалните stock event seeds за двете версии потвърждават customer `mail/order`
и merchant `mail/order/alert` като `addOrderHistory/before` events. Initial email
може да се изпрати при notify=false. Следователно този флаг не е readiness proof.

OCMOD добавя narrow guard в двата реални callbacks. Той проверява request-local
scope, точния order ID и неговия Jet payment code. Scope обхваща само Jet-owned
native finalization call и се възстановява във finally. Няма global disable,
config override на mail alerts или промяна на ordinary non-Jet notifications.

Readiness проверява реално заредените modified controller файлове чрез reflection,
capability revision и двата guard markers, плюс checkout integration marker.
Не приема configuration flag като доказателство. Поддържат се точно проверените
OC3 builds 3.0.3.8 и 3.0.5.1; missing capability/друг build остава 503.

Native voucher after-mail event е отчетен отделно. Покупка на gift vouchers през
финансиран cart/checkout е unsupported и се отказва преди claim/finalization;
не се въвежда банков формат/правило за подаръчни ваучери. Native voucher redemption
credits остават в стандартната total верига. Non-Jet voucher flow не се променя.

PASS: actual modified customer/merchant callbacks; non-Jet initial notifications;
точен order/context scope; cleanup при exception. Native DB/order/SMTP transport
са doubles. Third-party events/OCMOD и live mail execution остават integration checks.

## E. Product/Cart интеграция

`jet_send` и `jet_send_cart` делегират към общия HTTP/service слой. Няма legacy
bank mail body/counter fallback. POST, HTTPS, CSRF, method, intent/offer, source,
current policy, customer/consents и recipient configuration се проверяват server-side.

Product: реален product/quantity/options/special/discount/tax; durable claim преди
native addOrder; persisted read-back преди bank; съществуващата количка не се пипа.
Cart: native totals/coupons/shipping/taxes/fees; точно един order; conditional
snapshot-row cleanup; changed/new rows и changed current pricing context се пазят.
Cleanup failure не е основание за bank/notification/order retry.

Frontend изпраща native nested option arrays, signed offer, server intent, CSRF,
D/term и необходимите customer/consent полета. Browser prices/APR/bank totals не
се приемат за authority. Busy/consent/readiness states, stale quote/expiry handling
и explicit same-intent status replay са свързани с реалните endpoints. Unknown
network outcome няма automatic retry. Лични данни не се изпращат през HTTP.

Реалният `Request::clean()` е проверен: HTML-кодира входовете, включително JSON.
Адаптерът приема native arrays и съвместимия encoded JSON формат; native order
полета запазват безопасната storage encoding конвенция, а bank body е plaintext.
Това е проверено с actual Request::clean и quote/angle/ampersand option/name inputs.
Native character/storage limits се проверяват преди order side effects. Няма нов
EGN checksum/century policy; запазена е установената direct/checkout validation.

## F. Checkout jet / jet_card

Native checkout/confirm е модифициран само за тези два payment codes. Non-Jet
branch остава оригиналният native addOrder call. Без claim unchanged draft се
ползва повторно. При промяна на totals/gateway преди claim може да се създаде нов
native draft; старият не се изтрива. След claim се използва claim-bound order ID.

Checkout intent вече е стабилен за session checkout context, а не за draft ID.
Regeneration/refresh/back/two tabs/jet↔jet_card не го подновяват и не заобикалят
persistent uniqueness. Authorization остава store/session/customer/context-bound;
claim има unique original order binding. Двата gateway statuses се запазват.

Публичните `jet/confirm` и `jet_card/confirm` използват съществуващия order,
правят authoritative verification и не създават втори order при submission.
Automatic/manual workflows, changed totals, refresh, gateway switch, unknown
SMTP и history exceptions са изпълнени през actual modified native confirm и
реалните public controllers с изолирана persistence/transport среда.

## G. Replay и success flow

Complete claim връща existing `manual_registered` или `transport_accepted` outcome.
Quote refresh също може да върне съществуващата разписка, без нов банков POST.
Recipient configuration change не превръща завършена manual регистрация в automatic.
Unfinished/unknown/finalization_failed claim остава review_required и няма redirect.

Само durable `complete` outcome получава краткосрочна подписана receipt, bound към
store/session/customer/context/intent/order/result. Success GET проверява подписа
и persistent claim отново. Чужд/променен/незавършен outcome не се показва.
Разписката не съдържа ЕГН, име, email, телефон или адрес.

Owned success controller използва normal header/footer/columns/continue layout,
но не изпълнява native checkout/success, който сляпо clear-ва session cart. Друг
активен tab/order/cart не се чисти. Известието различава manual registration от
transport acceptance; не твърди банково одобрение или крайна доставка.

Няма automatic intent renewal за втора покупка в същия session/context. Това
съзнателно пази claim от bypass чрез recalculation. Изрична new-purchase/renewal
policy и operator recovery на blocked claims остават за owner review; не са
подменени с implicit reset. Secret/session rotation може да прекъсне replay.

## H. OCMOD и version compatibility

`install.xml`: **7 file blocks / 10 operations**, operation-level `error="abort"`,
explicit default-theme targets и unique ignoreif markers. Няма direct core-file
modification. Премахнат е file-level error атрибутът. Existing product/cart
placement и native total authority са проверени; owned attributes/JSON са escaped.

26 full official source snapshots са SHA-256 pinned в `tests/fixtures/oc3/manifest.json`,
с URLs към съответните version tags. Additional event excerpts са в `events.json`.
Основни източници: [OC3 3.0.3.8](https://github.com/opencart/opencart/tree/3.0.3.8/upload),
[OC3 3.0.5.1](https://github.com/opencart/opencart/tree/3.0.5.1/upload).

XML е приложен локално с проверените native line-search/ignoreif semantics върху
точните fixtures; всяко намерено anchor е unambiguous. Second application не
добавя duplicate insertion; missing native alert anchor abort-ва. Lint-нати са
8 injected PHP snippets и пълните modified native PHP файлове. Изпълняват се
реалните modified native controllers и Request::clean. Full live modification-cache
refresh и full Twig/browser rendering не се представят като изпълнени.

Не се заявява universal theme/checkout/PHP compatibility. PHP 8.3.6 е наличният
CLI за module/targeted native tests; пълен stock OC3 bootstrap върху него не е
сертифициран. Например старият Request constructor има dynamic-property behavior;
тестът извиква реалния clean метод без да симулира пълен OpenCart install.

## I. Admin/languages

Default-store-only native authenticated install/settings behavior; arbitrary POST
не provision-ва private values. Merchant ID, enabled flags, EUR minima/rates/terms,
style inputs, recipient list и двата gateway statuses имат typed validation.
Invalid/missing status не се активира. EUR diagnostics проверяват base и active
EUR/value 1. Глобалните currency settings не се променят.

Записан exact empty recipient е explicit manual mode; липсваща/невалидна/whitespace
стойност е отказ, не fallback. Старото подвеждащо admin обяснение за system-email
fallback е премахнато. Readonly legacy counter input също е премахнат от POST.
Secret/marker/counter са извън ordinary settings allowlist.

Catalog language route files са добавени и payment controllers подават translation
данните към Twig. Всички existing admin language variants са запазени byte-for-byte.
Deprecated FILTER_SANITIZE_STRING няма в runtime; typed validation/native-safe
storage/output го замества. Няма нов mbstring dependency в собствените helpers.

## J. Регресионна матрица

| Проверка | Клас доказателство | Резултат |
| --- | --- | --- |
| `php tests/security.php` | Isolated mock | PASS — 246 assertions; IMP-01 guards/model/output contracts |
| `php tests/financial.php` | Isolated mock/goldens | PASS — 205 assertions; EUR rules/formula/HMAC/authority |
| `php tests/submission.php` | Isolated SQL/OC3/transport doubles | PASS — 594 assertions; IMP-03 lifecycle, replay, privacy, uncertainty |
| `php tests/install.php` | Isolated install/SQL doubles | PASS — 47 checks; fresh/reactivate/legacy/partial/uninstall/protected values |
| `python3 tests/escaping.py` | Encoding/Twig output contracts | PASS — 348 assertions |
| `node.exe < tests/security-ui.cjs` | Actual JS callbacks with DOM/AJAX doubles | PASS — 106 checks; quote/submit/consent/expiry/replay/safe redirect |
| `python3 tests/ocmod.py` | Pinned native source application | PASS — 288 checks; 2 exact builds, anchors/idempotence/abort/PHP snippets |
| Native fixture subprocess 3.0.3.8 | Actual modified native/public controllers; DB/order/SMTP doubles | PASS — 200 checks |
| Native fixture subprocess 3.0.5.1 | Same | PASS — 200 checks |
| Missing/unsupported native capability subprocesses | Native fixture negative readiness | PASS — 4 × 8 checks; all 4 routes remain 503 without side effects |
| `python3 tests/submission-contracts.py` | Source/golden provenance + PHP lint | PASS — 130 checks; **101 PHP files** |
| `python3 tests/compatibility.py` | Include/language/Twig/manifest contracts | PASS — 215 checks |
| Release source manifest | Exact allowlist and hashes | PASS — **88 runtime/XML assets**, no archive |
| `git diff --check` | Working tree formatting | PASS |
| Actual MySQL/InnoDB DDL/concurrency | Real DB | **DEFERRED** — няма одобрена disposable база |
| Real store E2E / browser / full Twig / cache refresh | Live integration | **DEFERRED** |

Bank/financial goldens не са редактирани. Старият тест, който приема всеки 503 за
безусловен gate, е заменен с public/native activation плюс negative readiness
проверки. Старият order-ID-bound checkout replay тест е заменен с native draft
regeneration/gateway-switch тестове; session/customer/intent isolation остава.
Receipt redirect token може да се обнови по време; сравнява се съществуващият
outcome/order, не временният URL byte-for-byte.

## K. Оставащи блокери и отложени проверки

- Actual MySQL/InnoDB concurrency и clean-install SQL execution трябва да минат
  преди final release. Няма одобрена тестова база, live DB connection или Docker.
  `tests/mysql-concurrency.php` по подразбиране само отпечатва DEFERRED. Owner plan
  в `docs/MYSQL-TEST.md` изисква explicit approval, празна local disposable DB и
  dedicated user; изпълнява real parallel connections/uniqueness/references/rollback.
- Native MyISAM partial order, process interruption, real SMTP acceptance/partial
  failure, fraud/stock/total-confirm events и конкретни third-party extensions
  изискват owner store integration. Mock concurrency не е proof за real locking.
- Browser/full Twig, actual modification refresh, theme layout и reverse-proxy
  trusted HTTPS flag трябва да се проверят. Неподдържан build/guard/schema/context
  се отказва; няма автоматично заобикаляне.
- Multi-store, recurring/file options, purchased gift vouchers и unknown pricing
  extensions са unsupported. Не е добавен migration/recovery/purge framework.
- Explicit second-purchase intent policy и reconciliation на uncertain claims са
  owner decisions; автоматичен bank retry/reset не е въведен.
- Final version metadata, packaging/build, archive inspection и release approval
  остават за следващата изрично одобрена задача.

## L. Release candidate readiness

Локално интегриран, условно активиран кандидат за двете проверени stock OC3 версии
и default theme. И четирите public paths са свързани и тествани през controllers
и native fixtures; **няма route, оставен с безусловен BLOCKED/503 gate**.
Всички остават fail-closed при missing readiness. На външен магазин не е активирано нищо.

Кандидатът е готов за owner review и следваща integration validation. **Не е
готов за финален 2.0.0 release**, докато actual MySQL и store E2E gates не са
изпълнени и owner review не е приключил. Source manifest и clean-install docs са
подготвени; няма installer ZIP, version bump или Git publication.

Работата спира след IMP-04.
