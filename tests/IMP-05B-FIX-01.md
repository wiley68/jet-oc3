# IMP-05B-FIX-01 — локални поправки, 2.0.0 TEST / UNRELEASED

## A. Baseline и кумулативен статус

HEAD `2cc75057219f3c76a95b4107e9f78f44825daedc`, branch `main`.
Начално чисто работно дърво. Краен статус: 26 modified tracked и 3 untracked
файла, 0 staged. Промените са локални и unstaged; няма commit,
push/tag, version bump, ZIP, реална DB връзка, външен магазин или SMTP доставка.
Версията и clean schema revision 2 са запазени. Манифестът е обновен само за
новите runtime/XML hashes, с непроменени 89 package entries.

Изходният код на конкретния магазин и ефективното му config_currency не са
достъпвани. Причината за предишния реален install отказ остава недоказана.
EUR е вероятната му база според owner evidence; не приписваме отказа на друга база.

## B. Инсталация и безопасна схема

`upload/system/library/jetcredit/installation.php`: `load()` проверява точното
име с SHOW TABLE STATUS/Name equality, после columns, engine и indexes преди
зависимите SELECT. Аналогична проверка има за promotions. `submission_store.php`
проверява всички required columns преди schema_version и runtime probes.
Липсваща таблица е контролирано `missing_schema`, не mysqli warning.

Диагностиката е allowlisted и не включва SQL/credentials/secret/PII:
unsupported_store, authorization, installation_busy, missing_schema,
incomplete_schema, incorrect_schema_revision, invalid_eur_currency,
legacy_conflict_owner_review, legacy_promotions_owner_review,
legacy_ocmod_owner_review, secret_initialization_failed, schema_creation_failed,
offer_secret_unavailable и безопасни fallback storage/installation кодове.

Historical orders се проверяват само за резултат `installed_historical_only`;
не блокират и не се променят. Extension/permission registration не е финансов
конфликт. Disabled-status-only settings residue (`*_status=0`, not serialized)
е безвреден. Всички други съществуващи Jet settings изискват review: не се
пренасят стари thresholds/валутни настройки. Съществуваща jet_kop без валидна нова
инсталация блокира с отделен код независимо дали е празна — валутният смисъл не
може да бъде доказан безопасно. Идентифициран стар/неразпознат `cc_jet` OCMOD
също блокира. Съхранен OCMOD XML не е доказателство за активни runtime guards.

При реактивация се пазят secret/counter/claims/promotions. Partial/new incompatible
schema никога не се изтрива, поправя или reseed-ва автоматично. Няма 1.9.1 migration.

## C. Admin install flow

`upload/admin/controller/extension/module/mt_jet_credit.php::install()` връща
структуриран резултат без redirect или пълен HTML, запазва permissions/token/default
store checks и безопасната грешка в session. Нормалният `index()` запазва
header/column/footer composition и показва контролирана installation диагностика.

`install.xml` добавя една таргетирана операция към native module install caller:
проверява hook резултата за mt_jet_credit преди success, при отказ показва error в
native extension list и се връща. Други extensions не променят поведението си.
Използва се native permission/registration flow, без директно редактиране на core.

Това е fixture PASS, не browser PASS. Новият admin error flow изисква приложената
нова OCMOD операция; стар modification cache няма как да изпълнява новия caller.
Owner трябва да потвърди реалния AJAX/rendering flow след одобрен нов пакет.

## D. OpenCart 3.x

`native_mail_scope.php` приема major 3, без patch allowlist. Проверява реалните
Reflection method sources: executable suppression block в началото на index/alert,
цялата checkout reservation/error-handling интеграция, revision constants и markers.
Comments не се приемат за executable proof. Липсващи файлове/guards отказват.
Не се заобикаля native mail suppression. Non-Jet mail остава нормален.

Представителните source fixtures са 3.0.3.8/3.0.5.1. Същите fixtures са изпълнени
и с VERSION labels 3.0.3.9/3.99.99 за проверка на политиката. Това НЕ е actual
3.0.3.9 source/store certification. Owner приема series-wide patch risk.

## E–G. Валутен договор и Product/Cart/Checkout

`financial.php::requireEur()` изисква EUR session и валиден EUR currency service,
без EUR base restriction. Catalog financial adapter добавя `currencyContext()`,
`toEur()` и `eurAmount()`, използвайки native `currency->convert(amount,base,'EUR')`.
Проверяват се base availability, finite positive values, EUR DB status/ID/value
и съгласуваност с native service. Global settings/rates не се променят.

| Път | Base domain | EUR domain |
|---|---|---|
| Product | special/quantity discount/options/tax и native lines | Завършената gross сума, една conversion boundary |
| Cart | Native total chain с coupon/shipping/tax/fees | Финален authoritative total, една conversion boundary |
| Checkout | Persisted order/cart сравнение на lines/totals в base | Total през native API след валидиране на записания EUR курс |
| Direct order | products/tax/totals/total са base_amount | currency_code EUR, currency_value действителният EUR value |
| Bank | Native order lines са източник | Unit/gross prices и финансовото изчисление са EUR |

`source.amount` е EUR; `source.base_amount`, lines и totals са native base.
`submission_order.php` създава/проверява native orders в base, не записва EUR amount
в техните вътрешни totals. `bankProducts()` проверява conversion context и превръща
unit gross price през native API. Banking formatter/template е непроменен.

Native `payment_jet_total`/`payment_jet_card_total` остават base-currency availability
floors; module minimum и promo thresholds са EUR. Промоционният selector/priority,
финансовите формули и rounding не са променени.

Checkout изисква recorded currency_value да съответства на текущия контекст;
при drift няма тихо преизчисляване/презаписване на reserved order. Използва се
съществуващият explicit new-purchase lifecycle, без освобождаване на uncertain holds.

## H. Signed offers и replay

Conversion context включва base/session/financing codes, native base/EUR values,
applied rate и EUR ID/status. Той е част от source/pricing_context digest заедно
с base snapshot и получената EUR сума. HMAC key остава server-side; в offer payload
не са добавени identity/address данни. Промяна на rate invalidates quote дори ако
закръгленият amount остане същият. Verify препрочита source преди claim execution.

Ново финансиране при друга session currency отказва преди claim/order/mail.
Нова purchase generation също изисква EUR currency context. Completed claim replay
остава authorized historical outcome без нова поръчка, bank send или reference.
Bank product conversion context се препроверява преди подготовка на transport;
несигурен state не се retry-ва автоматично.

## I. Регресия

Всички резултати са локални fixtures/doubles, не реален OpenCart/MySQL E2E.

| Suite | Резултат |
|---|---|
| security.php | PASS, 246 assertions |
| financial.php | PASS, 205 assertions; непроменени financial goldens |
| submission.php | PASS, 594 assertions; bank goldens/manual/automatic/replay/reference |
| install.php | PASS, 73 checks; fresh/historical/residual/conflict/partial/revision/reactivation/hooks |
| purchase.php | PASS, 380 checks; generation/reservation/concurrency doubles |
| currency.php | PASS, 272 checks; EUR/RON/USD/BGN bases, session gates, 1/non-1/invalid rates, bank/order consistency |
| escaping.py | PASS, 356 assertions; PHP/XML injections и protected scopes |
| submission-contracts.py | PASS, 135 checks; 106 PHP files linted |
| ocmod.py | PASS, 317 checks; 9 PHP snippets; native fixture integration |
| native admin probes (чрез ocmod.py) | PASS, 10 checks за всеки fixture |
| native mail/checkout execution | PASS, 200 checks за всеки source fixture |
| compatibility.py | PASS, 223 checks; exact 89-entry manifest |
| builder.py --source-only | PASS, 15 executed; 6 archive tests skipped по забраната за ZIP |
| security-ui.cjs | PASS, 148 checks чрез наличния Windows node.exe |
| Bash syntax / git diff --check | PASS |

Негативните capability тестове запазват markers/constants, но повреждат executable
mail suppression или checkout reservation: всички public submission пътища остават
HTTP 503 без claim/order/mail. Availability codes са allowlisted и разграничават
native integration, schema и currency проблеми; неизвестни грешки остават generic.

## J–K. Отложени проверки и рискове

Реални MySQL DDL/permissions/lock/contention, modification refresh, browser/Twig,
реален OC3 3.0.3.9 checkout и contained SMTP са DEFERRED. Няма удостоверяване на всички
custom themes/third-party checkout extensions. Unknown pricing/capability остава fail closed.

Pre-existing financial settings/promotions/OCMOD изискват конкретен owner review,
не автоматично почистване. Не е установено кое условие е отказало стария реален
install. Собственикът трябва да провери новия actionable код в бъдещия E2E.
Курсовете 1.00000000 в owner store са приети без промяна/препоръка за корекция.

## L. Installer

GO за owner review на локалния source. След review е подходящ нов TEST build и
контролиран E2E. Тази задача не създава ZIP. Съществуващият R3 ZIP е SUPERSEDED,
запазен без промяна за provenance. Няма production/final-release approval.

## Source map за review

- `upload/system/library/jetcredit/installation.php:12` — `load()`
- `upload/system/library/jetcredit/installation.php:28` — `promotionReady()`
- `upload/system/library/jetcredit/installation.php:38` — `install()`
- `upload/system/library/jetcredit/installation.php:74` — `diagnostic()`
- `upload/system/library/jetcredit/submission_store.php:20` — `ready()`
- `upload/admin/controller/extension/module/mt_jet_credit.php:9` — `index()`
- `upload/admin/controller/extension/module/mt_jet_credit.php:248` — `install()`
- `upload/admin/controller/extension/module/mt_jet_credit.php:278` — `validate()`
- `upload/system/library/jetcredit/native_mail_scope.php:7` — `available()`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:116` — `product()`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:188` — `cartSnapshot()`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:234` — `orderSnapshot()`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:315` — `currencyContext()`
- `upload/catalog/model/extension/mt_jet_credit/financial.php:333` — `toEur()`
- `upload/catalog/model/extension/mt_jet_credit/submission_order.php:14` — `prepare()`
- `upload/catalog/model/extension/mt_jet_credit/submission_order.php:56` — `verify()`
- `upload/catalog/model/extension/mt_jet_credit/submission_order.php:84` — `bankProducts()`
- `upload/catalog/model/extension/mt_jet_credit/submission.php:89` — `newPurchase()`
- `upload/catalog/model/extension/payment/jet.php:4` — `getMethod()`
- `upload/catalog/model/extension/payment/jet_card.php:4` — `getMethod()`
