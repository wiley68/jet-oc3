# JET-OC3-001 / IMP-02 — отчет за owner review

Статус: реализиран локален EUR quote/verification слой. Изпращането на заявления
е умишлено затворено до IMP-03. Версията остава 1.9.1. Няма deployment.

## A. Променени файлове

Нови runtime файлове:

- `upload/system/library/jetcredit/financial.php` — общи правила, парсване, формула и RATE.
- `upload/system/library/jetcredit/offer.php` — HMAC протокол.
- `upload/catalog/model/extension/mt_jet_credit/financial.php` — OC3 адаптер и проверка.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js` — общ quote клиент.

Променени в IMP-02 съществуващи runtime файлове:

- `install.xml`.
- `upload/admin/controller/extension/module/mt_jet_credit.php`.
- `upload/admin/view/template/extension/module/mt_jet_credit.twig`.
- `upload/catalog/controller/extension/mt_jet_credit/jetcredit.php`.
- `upload/catalog/controller/extension/payment/jet.php`.
- `upload/catalog/controller/extension/payment/jet_card.php`.
- `upload/catalog/model/extension/mt_jet_credit/jetcredit.php`.
- `upload/catalog/model/extension/payment/jet.php`.
- `upload/catalog/model/extension/payment/jet_card.php`.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.js`.
- `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.js`.
- `upload/catalog/view/theme/default/template/extension/payment/jet.twig`.
- `upload/catalog/view/theme/default/template/extension/payment/jet_card.twig`.

Добавени тестови/отчетни файлове: `tests/financial.php`,
`tests/financial-bootstrap.php`, `tests/fixtures/financial-oc3.json`,
`tests/fixtures/imp01-protected.json`, този отчет.
Обновени IMP-01 тестове: `tests/security.php`, `tests/escaping.py`,
`tests/security-ui.cjs`, `tests/README.md`.

Непокътнати IMP-01 промени: целият admin promotion model, защитените admin mutation
методи и двата admin payment Twig файла. Хеш проверките пазят mutation/model кода;
повторно изпълнените тестове пазят escaping и authorization договорите.

## B. EUR-only

Финансирането изисква `config_currency=EUR`, сесия EUR, наличен активен EUR запис
и стойност 1 както в currency услугата, така и в currency таблицата. Невалиден
контекст изключва двата Jet payment метода и quote UI или връща контролиран отказ.
Поддържа се default store 0, съобразно защитения admin scope от IMP-01.

Премахнати са четирирежимният selector, активните четения на
`module_mt_jet_credit_eur`, коефициентът `1.95583`, BGN обозначенията и вторичните
валутни полета. Няма автоматична конверсия или промяна на глобални настройки,
поръчки и промоции. Съществуващите настройки трябва да бъдат прегледани като EUR
стойности от собственика; кодът не ги мигрира. Други payment extensions не са променени.

Правила: P=A−D, P≥max(75, configured minimum), D≥0 и D<A. Начален срок 9 при
A<125; иначе конфигурираният срок, който също трябва да е допустим. Срокове
3/6/9/10/12; 15/18/24 от A=201; 30/36 от A=301. Праговете са по A. Оферта се
подписва само при показана месечна вноска ≥10 EUR. По-ниска preview вноска се
показва с отказ и без token. Numeric входовете отхвърлят NaN/INF, експонентни
низове, масиви/boolean, невалидна точност и невалидни integer срокове/идентификатори.

## C. Авторитетни източници

| Път | Източник и проверки |
| --- | --- |
| Product | Native catalog product/options/discounts за текущата група/магазин; количество, minimum, stock и required options; special заменя quantity discount както в OC3; +/- option modifiers; native tax. Няма временна количка или cart add/remove/clear. |
| Cart | Текущата native количка, stock/minimum/options, native taxes и включената total верига по sort order: subtotal, coupon, shipping, tax и стандартни fees/credits. A идва от окончателния native total. |
| Checkout | Съществуващият session order: store, customer или guest ownership, payment code, EUR/value, допустим статус; записани order products/options/totals. Стойностите се запазват. Сравнение с текущия cart контекст отхвърля остаряла поръчка, без презаписване или преизчисляване на банковите суми от каталога. |

Разрешени total кодове: sub_total, shipping, tax, coupon, handling, low_order_fee,
voucher, reward, credit, total. Непознати включени total кодове, recurring продукти,
file опции, нестандартни modifiers и несъвместими line/total стойности се отказват.
Стандартните total модели се извикват по native OC3 договора; тестовете ползват
изолирани doubles. Не е написан паралелен pricing engine за чужди разширения.

## D. Проверени оферти

Формат: base64url(JSON) + HMAC-SHA256; проверка с `hash_equals`; срок 1800 секунди.
Payload съдържа версия, EUR, issued/expires, финансови стойности и HMAC digest-и
на binding/source/policy/recipient. Не съдържа име, ЕГН, email, телефон, адрес или
открит session ID. Свободният текст в опциите също влиза само в snapshot digest.

Binding обхваща store/session/customer/group, product/cart/checkout, jet/jet_card
и order ID при checkout. Source включва продуктовите редове/опции, totals и
релевантния session pricing контекст. Policy включва правила, минимум, проценти,
промоционален запис, default срок и enable настройки; recipient настройката е
отделно подписана. POST quotes изискват сесиен CSRF token.

`verifySubmission()` повторно чете източника и политиката, преизчислява и сравнява
подписа, срока, snapshot-ите и всички защитени финансови стойности. Browser rates,
цени и скрити продуктови списъци не определят сумите. Наличните стари display
полета могат само да предизвикат отказ при несъответствие.

Предварително provisioned dedicated secret: `JETCREDIT_OFFER_SECRET` в защитена
сървърна конфигурация или `jetcredit_offer_secret` в OpenCart Config registry.
Очаква се криптографски случаен 32-byte ключ като 64 hex знака, постоянен за
магазина и еднакъв за неговите workers. Не използвайте тестовия fixture ключ.
Няма admin input/JavaScript output, автоматично генериране на HMAC ключ или DB write.
Липсващ/невалиден ключ затваря quotes/payment eligibility; admin показва диагностика.
Rotation обезсилва старите оферти. Clean-install provisioning остава за IMP-03/04.
Сесийният CSRF token е отделен от този ключ.

## E. Frontend и OCMOD

Общият JS изпраща контекст, схема, CSRF, D, срок и при product ID/quantity/options.
Получава EUR резултат, допустими срокове и подписана оферта. Сумите/съобщенията се
поставят чрез `.text()`, options чрез DOM nodes; суров HTTP текст не се показва и
не се логва. Промени обезсилват текущия token/стойности; закъснели отговори се
игнорират; изтичане изчиства офертата. Quote retry остава наличен.

Product/cart entry JS и двата payment Twig използват общия клиент. Няма browser
bank submit. Кандидатстващите бутони остават disabled и има видимо съобщение за
временно недостъпно кандидатстване. Съществуващите форми/основен layout са запазени
за следващата интеграция, но не изпращат лични данни.

OCMOD съдържа 5 file blocks/5 operations; премахнати са 3 операции за старите cart
price списъци. Запазени са съответните native search anchors, metadata и версията.
Проверени са XML и трите injected PHP фрагмента. OCMOD error handling/packaging
преработката остава за IMP-04; реална modification refresh не е изпълнявана.

## F. Промоции и формула

Запазени: specific selector преди `*`, composite string без числов cast,
включителни начална/крайна дата, threshold по P, `-1.00` забрана и замяна на двата
процента от избраната промоция. Няма ново комбиниране на mixed-cart промоции.
Процентите остават raw decimal до строгото валидиране, преди подписване.

Формулата остава `(P/n) × (1+n×percent/100)`. RATE/ГЛП/ГПР алгоритъмът и
закръглянето са от OC3 1.9.1; общата сума използва незакръглената месечна вноска.
Шест отделни golden случая сравняват monthly/total/ГЛП/ГПР, включително zero-rate,
първоначална вноска и дълги срокове. Няма Woo runtime dependency или нова банкова формула.

## G. Локална регресионна матрица

| Проверка | Резултат |
| --- | --- |
| `php tests/security.php` | PASS — 246 assertions; IMP-01 guards/validation/escaping preparation |
| `php tests/financial.php` | PASS — 205 assertions; goldens, прагове 75/125/201/301/10, срокове/D, промоции, двете схеми, options/discounts, native totals, checkout, currency, tamper/expiry/binding/staleness, 4 submission gates |
| `python3 tests/escaping.py` | PASS — 336 assertions; hostile encoding, Twig output contracts, IMP-01 preservation, XML, 3 injected PHP fragments |
| `node.exe < tests/security-ui.cjs` | PASS — 75 checks; actual admin/quote callbacks с DOM/AJAX doubles, options, stale replies, expiry, controlled errors и gates |
| PHP CLI lint | PASS — 38 PHP файла, включително tests и runtime; наличен PHP 8.3.6 |
| `git diff --check` | PASS |

Общо 862 assertions/checks в четирите suites. Windows Node използва наличния WSL
interop; не са инсталирани зависимости. Друга PHP версия не е налична/тествана.
Това са изолирани проверки, не live E2E: няма пълен Twig renderer, браузър, реален
MySQL, theme/OCMOD application или външно инфраструктурно изпълнение.

## H. Изрично отложено за IMP-03

`jet_send`, `jet_send_cart`, `jet/confirm`, `jet_card/confirm` връщат HTTP 503 и
`submission_unavailable` без models, DB, mail, order history, counters или cart
cleanup. Старите mail/order тела са премахнати от тези controllers; няма legacy
fallback. Валидна оферта също не отваря изпращане в тази фаза.

Предстоят atomic submission claim/idempotency, concurrency/replay защита,
server-side identity/consent validation, recipient/mail policy, order/payment
lifecycle, доставка и recovery. HMAC офертата не е lock или гаранция за доставка.
IMP-03 трябва да извика verification непосредствено преди защитените side effects
и да реши race/claim транзакционния договор. Нова claim таблица не е създадена.

## I. Рискове и блокери

- До provisioned постоянен secret EUR quotes умишлено не работят. Owner provisioning
  и clean-install lifecycle са бъдеща стъпка; няма промяна на жива конфигурация.
- Кандидатстване/checkout completion чрез Jet остават блокирани до IMP-03, дори
  когато quote е валиден. Тази фаза не е готов banking submission release.
- Нестандартни pricing/total extensions, recurring/file options и multi-store
  изискват отделна адаптация; текущото поведение е отказ.
- Подмяна на стандартни OC3 модели чрез външни events/OCMOD не може да се докаже
  съвместима само от този repository. Нужна е owner integration проверка.
- Order/cart comparison е консервативен; mismatch или несъвместима precision
  блокира офертата и изисква актуален checkout order, без промяна на записаните суми.
- Live Twig/theme/OCMOD/MySQL и другите PHP версии остават непроверени. Запазената
  legacy promotion schema няма нов concurrency constraint; install/uninstall не
  са променяни тук. Packaging/модернизацията на PHPMailer е извън IMP-02.

## J. Кумулативно Git състояние

Branch `main`, HEAD `dd36a97a262fe1cd153bf4783dc9d8c583108a56`.
Кумулативно IMP-01+IMP-02: 16 modified tracked файла и 13 untracked нови файла
(4 runtime и 9 tests/fixtures/docs). Няма staged промени, commit, push, tag,
version bump или ZIP. IMP-01 работата остава в същия незаписан working tree.
Не са извършвани реални DB writes, банкови писма, заявления или външен store достъп.

Работата спира след IMP-02 за owner review.
