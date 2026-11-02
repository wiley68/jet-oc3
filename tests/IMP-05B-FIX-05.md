# IMP-05B-FIX-05 — отчет

Локална реализация за Jet-OC3 **2.0.0 TEST / UNRELEASED**. Нов ZIP не е създаван.

## A. Git baseline и променени файлове

Repository: `wiley68/jet-oc3`, branch `main`.
HEAD: `2cc75057219f3c76a95b4107e9f78f44825daedc`.

Начало: 32 modified tracked + 10 untracked; край: 34 modified tracked + 11
untracked; няма staged файлове. Това са общите незаписани промени, включително
предходните фази, а не само FIX-05. Няма commit, push, tag или промяна на версията.

Само следните 11 файла са променени/добавени в FIX-05:

| Файл | Промяна |
|---|---|
| `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_offers.js` | Debounce, quote lifecycle, безопасно продължаване, popup status |
| `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_products.css` | Step 1/2 scoped typography/alignment, popup status, премахнат recalculate CSS |
| `upload/catalog/view/theme/default/template/extension/mt_jet_credit/creditjet_cart.css` | Премахнат CSS само за стария бутон; запазен shared CSS import |
| `install.xml` | Product/cart markup: премахнат бутон, decimal text input и достъпен status вътре в popup |
| `upload/catalog/view/theme/default/template/extension/payment/jet.twig` | Checkout: премахнат бутон/CSS, decimal text input |
| `upload/catalog/view/theme/default/template/extension/payment/jet_card.twig` | Същото за card checkout |
| `tests/security-ui.cjs` | Virtual clock и targeted debounce/stale/normalization regression |
| `tests/popup-contracts.py` | CSS isolation, typography, достъпен status и липса на стария бутон |
| `tests/README.md` | Инструкции за FIX-05 regression без ZIP |
| `tests/IMP-05B-FIX-05.md` | Този отчет |
| `docs/release-manifest.json` | Обновени SHA-256 за разрешените runtime/XML промени; 89 assets |

SHA-256 на всички останали вече modified/untracked файлове е сравнен със снимката
преди FIX-05. Предишните FIX-01–04 PHP/admin/settings/prefill промени са запазени.
Няма промени във financial PHP, прагове, формули, HMAC, bank payloads или валути.

## B. Стилове Step 1 / Step 2

Step 2 inputs/selects/textareas и placeholders са ляво подравнени чрез селектори
само под `#jet-product-popup-container #jet_step_2`. Няма промяна в global theme CSS.

Step 1 стойностите са 20px / weight 600 / line-height 24px и дясно подравнени;
контролите запазват височина 48px. Native select запазва отделното ляво подравняване.
Описателните етикети са 16pt / weight 500 / line-height 20px, с mobile 12pt / 14px.
Запазени са focus/error/disabled правила и responsive layout.

Използван е вече провереният read-only Jet-Woo reference, commit
`8126bdd49046669583ab8e8c02f7bf2124a46521`, версия 2.0.0. CSS е прочетен повторно от
локалното evidence копие; Git blob SHA-1 е потвърден като
`dcfcda43264a6152c012f967343ddd1dbadd67d8`.
Provenance: `tests/fixtures/jet-woo-ui-reference.json`.

Отстранено е припокриването на `first-child` и `last-child` върху единствения div
за OC3 numeric value, което е прилагало по-леката currency-label типография.
Не е променяна типографията на вече приетите product button installments.

## C. Debounce и lifecycle

Trailing-edge timer: **400 ms след последната редакция**. Всяка нова редакция
отменя предишния timer и не изпраща незабавна заявка. Използва се последната сума.

Показаните резултати се запазват по време на редактиране и refresh. В popup има
`role="status" aria-live="polite"` съобщение за обновяване; loader започва само
при действителна заявка. Няма изчистване/повторно рисуване при всяко натискане.

Blur и Enter изпълняват pending редакцията веднага. Опитът за продължаване или
submit първо я обработва; при необходима нова заявка изчаква валидна оферта и
ново действие от потребителя. Не създава order/submission вместо quote refresh.
Term, scheme, quantity и product options продължават да обновяват автоматично и
обработват текущата сума, като отменят чакащия debounce.

Затваряне и destroy/remount отменят timer и не допускат късни callbacks. При HTTP
failure/timeout loader се изключва, старата визуална оферта остава неизползваема,
а следваща валидна редакция позволява възстановяване. Quote timeout остава 30 s.

## D. Премахнат „Преизчисли“

Премахнати са markup, event handler и CSS само за този бутон в product/cart и
двата checkout payment шаблона. Няма останали `preizcisli` identifiers в runtime
или OCMOD XML. Keyboard flow използва Enter/blur и нормалното продължаване;
server validation и automatic quote refresh са запазени.

## E. Празна първоначална вноска → 0

По време на писане празното поле остава празно. На 400 ms или blur/Enter/flush
се записва `0` и се заявява оферта с нула. Ако вече има идентична неизтекла zero
оферта, тя може да се използва без излишна заявка. Няма добавяне на нула пред цифри.

Контролът е `type="text" inputmode="decimal"`, така че браузърът не заменя
невалидни текстови/отрицателни стойности с празно поле и после с нула. Непразните
стойности се изпращат към съществуващата server validation; негативни, нечислови,
твърде големи и невалидно прецизни суми не получават ново клиентско изчисление.

Нормализацията на числовия cache key служи само за deduplication, например
`12.5` и `012.500`. Request запазва въведената непразна сума. Стойности над
съществуващия server лимит 40 знака не се приравняват към валидна кеширана сума.

## F. Защити срещу stale quote / stale AJAX

При редакция hidden signed offer се изчиства незабавно, readiness става false,
а continuation/submit се деактивират. `ready()` допълнително сравнява текущите
финансови параметри с параметрите на приетата оферта.

Новата редакция увеличава sequence **преди** abort на старата AJAX заявка.
Success/error callbacks проверяват sequence и alive, така че стар response не
може да активира token или да изключи loader на по-нова заявка.

Използва се request snapshot за key на приетия response. Идентична in-flight
заявка не се дублира от change/blur/Enter. Използването на предишна идентична
оферта не удължава expiry timer; оферта, изтекла по време на редакцията, не може
да бъде възстановена. Финалната server-side offer/HMAC проверка остава авторитетна.

## G. Регресионни резултати

Всички изпълнени проверки са PASS, с локални doubles/fixtures, без реални услуги.

| Suite / проверка | Резултат |
|---|---|
| `php tests/security.php` | 246 assertions |
| `php tests/financial.php` | 205 assertions; financial goldens |
| `php tests/currency.php` | 272 checks |
| `php tests/install.php` | 73 checks |
| `php tests/admin-settings.php` | 172 cumulative checks |
| `python3 tests/admin-tabs.py` | 340 checks |
| `php tests/prefill.php` | 29 checks |
| `php tests/submission.php` | 594 assertions; bank/submission goldens |
| `php tests/purchase.php` | 380 checks |
| `python3 tests/popup-contracts.py` | 260 checks |
| `node.exe < tests/security-ui.cjs` | 319 checks |
| `python3 tests/escaping.py` | 356 assertions |
| `python3 tests/submission-contracts.py` | 137 checks; **108 PHP файла lint PASS** |
| `python3 tests/ocmod.py` | 317 checks; 9 PHP snippets |
| Native OCMOD execution | По 200 mail/submit и 10 admin-install checks за 3.0.3.8 / 3.0.5.1 |
| `python3 tests/compatibility.py` | 223 checks |
| `python3 tests/release-manifest.py` | Exact source manifest: 89 assets |
| `python3 tests/builder.py --source-only` | 15 executed PASS / 6 archive tests SKIPPED по забраната за ZIP |
| JS syntax | 6 standalone/inline scripts; Twig JSON placeholders само за parse |
| XML parse / Bash syntax / `git diff --check` | PASS |

Новите JS сценарии покриват product, cart, checkout jet и checkout jet_card:
burst typing/точния trailing edge, една заявка, dedup, empty→zero и positive→zero,
blur/Enter, term/scheme change по време на debounce, abort/out-of-order/failure,
close/remount/destroy, expired cached quote, continuation/submit guards и запазен
server-authoritative invalid-input flow. Не се инсталират dependencies.

## H. Оставащи browser E2E проверки

След отделно разрешен TEST build собственикът трябва да провери на OC 3.0.3.9:

1. Desktop/mobile Step 2 text/placeholder alignment и Step 1 hierarchy/select/focus.
2. Бързо писане и пауза ~400 ms; запазени стойности и видим updating status без
   мигане на всеки клавиш; само една quote заявка след burst.
3. Изтриване до празно, blur/Enter, decimal edits и zero→positive→zero.
4. Term/scheme/product options при pending edit; keyboard-only продължаване.
5. Бавна мрежа/HTTP error, близко изтичане на token, затваряне и повторно отваряне;
   без stale submit, duplicate application или заседнал loader.
6. Запазени вече приети FIX-04 button typography/евро/back/profile prefill и
   administrative FIX-01–03 behavior.

Automated DOM/transport doubles не удостоверяват реалното browser/Twig/theme
изобразяване. OCMOD native snapshots са 3.0.3.8/3.0.5.1; настоящият локален тест
не е ново интеграционно удостоверяване на 3.0.3.9. Предишният owner E2E PASS остава
evidence за предходния пакет, не за все още непакетираните FIX-05 промени.

## I. Препоръка

**GO за следващ отделно разрешен TEST build** и последващи owner-operated E2E
проверки от H. Няма установен локален regression blocker. Production release
изисква отделна проверка/одобрение след реалния browser E2E.

Съществуващият BUILD-03 ZIP е непроменен и **не съдържа FIX-05**:

`dist/CC_OpenCart.3.x_JET_v.2.0.0.ocmod.zip` — 269979 bytes,
SHA-256 `92cb709e13fb86f8441c3c62ba34f96ba684c7c90691e0ddfa6b9194a187c82a`.

Не са изпълнявани installer build, външен store, реални DB операции, email или
bank submission. Jet-Woo не е модифициран. Работата спира след този отчет.
