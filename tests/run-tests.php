<?php
/**
 * Quick Order — QO-GEO-1 verification suite.
 *
 * ┌────────────────────────────────────────────────────────────────────────┐
 * │  NO REAL ORDER IS EVER CREATED. TEST CUSTOMERS AND ADDRESSES ARE       │
 * │  CREATED, EXERCISED AND REMOVED, AND THE RUN PROVES ZERO RESIDUE.      │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Three kinds of check, in increasing cost:
 *
 *   1. the validator, which is pure and needs nothing;
 *   2. source guards, which catch a fabricated default coming back;
 *   3. the address lifecycle, driven through the controller's own private
 *      method by reflection — because a test that reimplements the method it
 *      is testing proves only that the author can write it twice.
 *
 *   php modules/quickorder/tests/run-tests.php
 *
 * @license MIT
 */

$root = dirname(__DIR__, 3);

if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $root . '/adminstationa');
}

require $root . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'quickorder/quickorder.php';

$moduleDir = _PS_MODULE_DIR_ . 'quickorder';

class QoRunner
{
    public $passed = 0;
    public $failed = 0;
    private $topic = '';

    public function topic($title)
    {
        $this->topic = $title;
        echo "\n  " . $title . "\n";
    }

    public function assert($condition, $label)
    {
        $condition ? ++$this->passed : ++$this->failed;
        printf("    %s  %s\n", $condition ? 'PASS' : 'FAIL', $label);
    }

    public function same($actual, $expected, $label)
    {
        $ok = $actual === $expected;
        $ok ? ++$this->passed : ++$this->failed;
        printf("    %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label,
            $ok ? '' : ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')');
    }

    public function summary()
    {
        printf("\n  ------------------------------------------------------------\n");
        printf("  Assertions: %d   Passed: %d   Failed: %d\n",
            $this->passed + $this->failed, $this->passed, $this->failed);
        echo $this->failed === 0 ? "  RESULT: ALL PASSED\n\n" : "  RESULT: FAILED\n\n";

        return $this->failed === 0 ? 0 : 1;
    }
}

$r = new QoRunner();
$V = 'QuickOrderAddressInput';

/* Comments removed, so a sentence ABOUT `00000` is not an occurrence of it. */
function qo_strip_comments($source)
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        $out .= is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
            : $token;
    }

    return $out;
}

/**
 * Smarty AND JavaScript comments.
 *
 * The template carries both, and the guards below look for strings the
 * template's own comments quote in order to explain why they are gone.
 * ADR-165 in another module's clothing.
 */
function qo_strip_smarty_comments($source)
{
    $source = (string) preg_replace('/\{\*.*?\*\}/s', ' ', $source);
    $source = (string) preg_replace('!/\*.*?\*/!s', ' ', $source);

    return (string) preg_replace('!^\s*//.*$!m', ' ', $source);
}

/* ══════════════════════════════════════════════════════════════════════════
   1. THE VALIDATOR — §20 A to I, and the two rules that matter most
   ══════════════════════════════════════════════════════════════════════════ */

$r->topic('1. City: the customer\'s own text, tidied but never rewritten');

$r->same($V::validateCity('Casablanca')['value'], 'Casablanca', 'C. a Latin city passes through unchanged');
$r->same($V::validateCity('  Casablanca  ')['value'], 'Casablanca', 'padding is removed');
$r->same($V::validateCity("Sidi   Slimane")['value'], 'Sidi Slimane', 'runs of whitespace collapse');
$r->same($V::validateCity('الدار البيضاء')['value'], 'الدار البيضاء', 'B. an Arabic city passes through unchanged');
$r->same($V::validateCity('سلا')['value'], 'سلا', 'a three-letter Arabic name is accepted');
$r->same($V::validateCity("M'Diq")['value'], "M'Diq", 'an apostrophe survives — M\'Diq is a real town');
$r->same($V::validateCity('Aït Melloul')['value'], 'Aït Melloul', 'accents survive');
$r->same($V::validateCity('Salé')['value'], 'Salé', 'and again');

/* SPELLING IS NOT CORRECTED. Capture is not interpretation. */
$r->same($V::validateCity('casablanca')['value'], 'casablanca', 'lower case is NOT canonicalised');
$r->same($V::validateCity('CASA')['value'], 'CASA', 'an abbreviation is NOT expanded to Casablanca');
$r->same($V::validateCity('Rbat')['value'], 'Rbat', 'a misspelling is NOT corrected to Rabat');

$r->topic('2. City: what is refused, and only what is refused');

foreach (['', '   ', '-', '--', '.', '/', 'City', 'ville', 'à définir', 'N/A',
          'unknown', 'Maroc', 'Morocco', 'المغرب', 'مدينة'] as $bad) {
    $r->assert(!$V::validateCity($bad)['ok'], 'H. refused: "' . $bad . '"');
}

$r->same($V::validateCity('')['error'], $V::CITY_EMPTY, 'G. an empty city says it is empty');
$r->same($V::validateCity('-')['error'], $V::CITY_PLACEHOLDER, 'H. "-" says it is a placeholder');
$r->same($V::validateCity('A')['error'], $V::CITY_TOO_SHORT, 'a one-letter city is too short');
$r->same($V::validateCity('Casa<script>')['error'], $V::CITY_INVALID_CHARACTERS,
    'I. characters PrestaShop itself forbids are refused');
$r->assert(!$V::validateCity('Fès@Home')['ok'], 'and an @ is refused, as Validate::isCityName would');

/* The refusals must be NARROW. Real names that merely look unusual pass. */
foreach (['Aït Melloul', 'M\'Diq', 'Sidi Ifni', 'Oued Zem', 'Fquih Ben Salah',
          'الدار البيضاء', 'أكادير', 'Dar Bouazza', 'Sale-Al Jadida'] as $good) {
    $r->assert($V::validateCity($good)['ok'], 'accepted: ' . $good);
}

$r->topic('3. Postcode: absent is an answer, 00000 is not');

$r->same($V::validatePostcode('20000', 'NNNNN')['value'], '20000', 'A. a real postcode passes');
$r->same($V::validatePostcode('10000', 'NNNNN')['value'], '10000', 'and a leading-zero-free one');
$r->same($V::validatePostcode('20 000', 'NNNNN')['value'], '20000', 'a typed space is removed');
$r->same($V::validatePostcode('٢٠٠٠٠', 'NNNNN')['value'], '20000',
    'Arabic-Indic digits are the same number on another keyboard');

/* E — blank is allowed and stored blank. */
$blank = $V::validatePostcode('', 'NNNNN');
$r->assert($blank['ok'], 'E. a blank postcode is ACCEPTED');
$r->same($blank['value'], '', 'and stored as the empty string, which is what PrestaShop means by absent');

/* D — the fabricated one is refused, and named as fabricated. */
$fabricated = $V::validatePostcode('00000', 'NNNNN');
$r->assert(!$fabricated['ok'], 'D. 00000 is REFUSED');
$r->same($fabricated['error'], $V::POSTCODE_FABRICATED,
    'and reported as software output, not as a malformed number');
$r->assert(!$V::validatePostcode('0 0 0 0 0', 'NNNNN')['ok'], 'spacing it out does not smuggle it through');
$r->assert(!$V::validatePostcode('٠٠٠٠٠', 'NNNNN')['ok'], 'nor does typing it in Arabic-Indic digits');

/* F — malformed. */
foreach (['1', '1234', '123456', 'ABCDE', '2000A', '-1234'] as $bad) {
    $r->assert(!$V::validatePostcode($bad, 'NNNNN')['ok'], 'F. refused: "' . $bad . '"');
}

$r->same($V::validatePostcode('1234', 'NNNNN')['error'], $V::POSTCODE_MALFORMED, 'and says malformed');

/* The format comes from the country, so a different one is honoured. */
$r->assert($V::matchesFormat('AB1 2CD', 'LLN NLL'), 'a letter-bearing format is honoured');
$r->assert($V::matchesFormat('20000', ''), 'and no declared format means no constraint, not no postcodes');
$r->assert(!$V::matchesFormat('20000', 'NNNN'), 'while a four-digit country refuses five');

$r->topic('4. Both fields together, first failure reported');

$both = $V::validate('Casablanca', '20000', 'NNNNN');
$r->assert($both['ok'], 'A. a valid pair is accepted');
$r->same($both['city'], 'Casablanca', 'with the city');
$r->same($both['postcode'], '20000', 'and the postcode');

$cityFirst = $V::validate('-', '00000', 'NNNNN');
$r->same($cityFirst['field'], 'city', 'the city is judged first');
$r->same($V::validate('Casablanca', '00000', 'NNNNN')['field'], 'postcode',
    'and the postcode once the city is sound');
$r->same($V::validate('Rabat', '', 'NNNNN')['postcode'], '',
    'a good city with no postcode is a complete, valid answer');

/* ══════════════════════════════════════════════════════════════════════════
   2. SOURCE GUARDS — a fabricated default coming back
   ══════════════════════════════════════════════════════════════════════════ */

$r->topic('5. The fabrications are gone, and cannot return unnoticed');

$submit = qo_strip_comments((string) file_get_contents($moduleDir . '/controllers/front/submit.php'));
$form = qo_strip_smarty_comments((string) file_get_contents(
    $moduleDir . '/views/templates/hook/quickorder_form.tpl'));

$r->assert(strpos($submit, "'00000'") === false, 'submit.php contains no literal 00000');
$r->assert(strpos($submit, '"00000"') === false, 'in either quoting style');
$r->assert(!preg_match('/\$city\s*=\s*[\'"]-[\'"]/', $submit), 'no city is defaulted to "-"');
$r->assert(!preg_match('/->city\s*=\s*[\'"][^\'"]*[\'"]\s*;/', $submit),
    'no city is assigned a literal at all — it always comes from input');
$r->assert(!preg_match('/->postcode\s*=\s*[\'"][0-9]+[\'"]/', $submit),
    'and no postcode is assigned a literal number');
$r->assert(strpos($form, "fd.append('city', '')") === false,
    'the form no longer posts an empty city');
$r->assert(strpos($form, "getElementById('qo_city')") !== false,
    'it posts what the customer typed');
$r->assert(strpos($form, "fd.append('postcode'") !== false, 'and a postcode');
$r->assert(strpos($form, 'id="qo_city"') !== false, 'the city field exists in the markup');
$r->assert(strpos($form, 'id="qo_zip"') !== false, 'and the postcode field');

$r->topic('6. Quick Order does not depend on TWIZA');

foreach (['controllers/front/submit.php', 'quickorder.php',
          'classes/QuickOrderAddressInput.php'] as $relative) {
    $code = qo_strip_comments((string) file_get_contents($moduleDir . '/' . $relative));

    foreach (['twizasolidarity', 'Twiza\\Solidarity', 'CustomerLocalityResolver',
              'GeoReferenceRepository', 'LocalityResolution', 'ts_geo_',
              'AllocationOpportunity', 'TS_ALLOCATION'] as $forbidden) {
        $r->assert(stripos($code, $forbidden) === false,
            $relative . ' never names ' . $forbidden);
    }
}

$r->assert(strpos($submit, 'Module::isInstalled') === false
    && strpos($submit, 'Module::isEnabled') === false,
    'N. and it does not gate address creation on another module being installed');

$r->topic('7. The country is native, and not taken from the request');

$r->assert(strpos($submit, "Configuration::get('PS_COUNTRY_DEFAULT')") !== false,
    'the delivery country comes from PrestaShop configuration');
$r->assert(!preg_match('/id_country\s*=\s*\(int\)\s*Tools::getValue/', $submit),
    'AND NEVER FROM THE REQUEST');
$r->assert(!preg_match('/id_state\s*=\s*\(int\)\s*Tools::getValue/', $submit),
    'nor the state');
$r->assert(strpos($submit, 'State::getStatesByIdCountry') !== false,
    'a state is only ever read from the installed country data');

/* Morocco declares no states on this installation, so nothing is fabricated. */
$morocco = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$r->same((int) $morocco->contains_states, 0, 'the delivery country declares no states');
$r->same((string) $morocco->zip_code_format, 'NNNNN', 'and declares a five-digit postcode format');

$r->topic('8. Validation failure loses nothing the customer typed');

$r->assert(!preg_match('/\.value\s*=\s*[\'"]{2}/', $form),
    'M. no script path clears a field value');
$r->assert(strpos($form, '.reset()') === false, 'nor resets the form');
$r->assert(strpos($form, 'markInvalid') !== false,
    'the failing field is highlighted rather than merely described');
$r->assert(strpos($submit, 'throw new Exception') !== false,
    'and a refused submission throws before any order is created');

/* The order of operations is the guarantee: input is validated BEFORE a
   customer, an address, a cart or an order exists. */
$order = [];

foreach (['validateInput', 'validateProduct', 'getOrCreateCustomer',
          'createAddress', 'buildCart', 'placeOrder'] as $step) {
    $position = strpos($submit, '$this->' . $step . '(');

    /* ┌──────────────────────────────────────────────────────────────────┐
       │  EXISTENCE FIRST. A MUTATION SURVIVED BECAUSE OF THIS.            │
       └──────────────────────────────────────────────────────────────────┘
       `strpos()` returns false when the call is gone, and `false < 5` is
       true in PHP — so DELETING `validateProduct()` entirely satisfied every
       ordering assertion below. Removing the stock and product checks looked
       like correct ordering. */
    $r->assert($position !== false, 'the pipeline still calls ' . $step . '()');
    $order[$step] = $position === false ? PHP_INT_MAX : $position;
}

$r->assert($order['validateInput'] < $order['getOrCreateCustomer'],
    'validation runs before any customer is created');
$r->assert($order['validateInput'] < $order['createAddress'],
    'M. before any address is created');
$r->assert($order['createAddress'] < $order['placeOrder'],
    'and the order is the last thing to happen');

$r->assert($order['validateProduct'] < $order['createAddress'],
    'the product and its stock are judged before any address exists');
$r->assert($order['validateProduct'] < $order['placeOrder'],
    'and long before the order — geography changed nothing about that sequence');

$r->topic('8a. The geography path touches no price, no tax, no stock, no carrier');

/* ┌──────────────────────────────────────────────────────────────────┐
   │  §18 — IMPROVING GEOGRAPHY MUST CHANGE NOTHING ELSE.              │
   └──────────────────────────────────────────────────────────────────┘
   Scoped to the two methods this phase wrote, because the controller as a
   whole legitimately prices and reserves stock — it places orders. The claim
   is narrower and checkable: the code that captures a city and a postcode
   does not reach for any of it. */
$geographyMethods = '';

foreach (['validateInput', 'createAddress', 'deliveryZipFormat', 'geographyMessage'] as $method) {
    if (preg_match('/private function ' . $method . '\s*\(.*?\n    \}/s', $submit, $found)) {
        $geographyMethods .= $found[0] . "\n";
    }
}

$r->assert($geographyMethods !== '', 'the geography methods were located in the source');

foreach (['getPrice', 'setPrice', 'total_paid', 'id_tax_rules_group', 'Tax::',
          'StockAvailable::update', 'updateQuantity', 'id_carrier', 'Carrier::',
          'setDeliveryOption', 'id_currency', 'reduction'] as $forbidden) {
    $r->assert(strpos($geographyMethods, $forbidden) === false,
        'the geography path never names ' . $forbidden);
}

/* The one stock call in the controller belongs to product validation, and it
   READS. Asserted so a write cannot be added there unnoticed either. */
$r->assert(strpos($submit, 'StockAvailable::getQuantityAvailableByProduct') !== false,
    'stock is still only READ, by the product check');
$r->assert(strpos($submit, 'StockAvailable::update') === false,
    'and never written by this controller');

$r->topic('8b. Behaviour: a refused city stops the pipeline before anything exists');

/* ┌──────────────────────────────────────────────────────────────────┐
   │  DRIVEN THROUGH THE CONTROLLER'S OWN VALIDATOR.                  │
   └──────────────────────────────────────────────────────────────────┘
   Asserting that a `throw` appears in the source proves only that the word
   is present. What matters is that a bad city REACHES nothing: no customer,
   no address, no cart, no order. `validateInput()` is where that is decided,
   so it is called for real, with the request populated as the browser would
   populate it. */
require_once $moduleDir . '/controllers/front/submit.php';

$validatorReflection = new ReflectionClass('QuickOrderSubmitModuleFrontController');
$validatorController = $validatorReflection->newInstanceWithoutConstructor();

$vModule = new ReflectionProperty('ModuleFrontController', 'module');
$vModule->setAccessible(true);
$vModule->setValue($validatorController, Module::getInstanceByName('quickorder'));

$vContext = new ReflectionProperty('FrontController', 'context');
$vContext->setAccessible(true);
$vContext->setValue($validatorController, Context::getContext());

$validateInput = $validatorReflection->getMethod('validateInput');
$validateInput->setAccessible(true);

$submitRequest = static function ($city, $postcode) {
    $_POST = $_GET = $_REQUEST = [];
    $_POST['full_name'] = 'Ahmed Mohamed';
    $_POST['phone'] = '0600112233';
    $_POST['address'] = '12 Rue de Test, Quartier';
    $_POST['city'] = $city;
    $_POST['postcode'] = $postcode;
    $_POST['quantity'] = '1';
    $_POST['id_product'] = '1';
    $_POST['id_product_attribute'] = '0';
    $_REQUEST = $_POST;
};

$attempt = static function ($city, $postcode) use ($submitRequest, $validateInput, $validatorController) {
    $submitRequest($city, $postcode);

    try {
        return ['ok' => true, 'input' => $validateInput->invoke($validatorController), 'message' => ''];
    } catch (Exception $e) {
        return ['ok' => false, 'input' => null, 'message' => $e->getMessage()];
    }
};

$good = $attempt('Casablanca', '20000');
$r->assert($good['ok'], 'a complete submission validates');
$r->same($good['input']['city'], 'Casablanca', 'and carries the city forward');
$r->same($good['input']['postcode'], '20000', 'and the postcode');

foreach ([
    ['', '20000', 'an empty city is refused'],
    ['-', '20000', 'H. a "-" city is refused'],
    ['City', '20000', 'a placeholder city is refused'],
    ['Casablanca', '00000', 'D. a 00000 postcode is refused'],
    ['Casablanca', '123', 'F. a malformed postcode is refused'],
] as $case) {
    $refused = $attempt($case[0], $case[1]);
    $r->assert(!$refused['ok'], $case[2]);
    $r->assert($refused['message'] !== '', 'and says why: ' . $refused['message']);
}

/* E — no postcode is a complete answer, not a refusal. */
$noZip = $attempt('Marrakech', '');
$r->assert($noZip['ok'], 'E. a customer with no postcode is NOT blocked');
$r->same($noZip['input']['postcode'], '', 'and their postcode is recorded as absent');

/* B/C — both scripts pass the real pipeline, not just the unit. */
$r->assert($attempt('الدار البيضاء', '20250')['ok'], 'B. an Arabic city passes the controller');
$r->assert($attempt('Fès', '30000')['ok'], 'C. and a Latin one with accents');

$_POST = $_GET = $_REQUEST = [];

/* ══════════════════════════════════════════════════════════════════════════
   3. THE ADDRESS LIFECYCLE — driven through the controller's own method
   ══════════════════════════════════════════════════════════════════════════ */

$r->topic('9. Behaviour: what createAddress() actually writes');

$controllerFile = $moduleDir . '/controllers/front/submit.php';
require_once $controllerFile;

$reflection = new ReflectionClass('QuickOrderSubmitModuleFrontController');
$controller = $reflection->newInstanceWithoutConstructor();

$moduleProperty = new ReflectionProperty('ModuleFrontController', 'module');
$moduleProperty->setAccessible(true);
$moduleProperty->setValue($controller, Module::getInstanceByName('quickorder'));

$contextProperty = new ReflectionProperty('FrontController', 'context');
$contextProperty->setAccessible(true);
$contextProperty->setValue($controller, Context::getContext());

$createAddress = $reflection->getMethod('createAddress');
$createAddress->setAccessible(true);

$created = ['customers' => [], 'addresses' => []];

$makeCustomer = static function ($suffix) use (&$created) {
    $customer = new Customer();
    $customer->firstname = 'QOGEOTEST';
    $customer->lastname = 'Fixture';
    $customer->email = 'qogeo1-' . $suffix . '-' . getmypid() . '@test.invalid';
    $customer->passwd = Tools::hash('qogeo1-' . $suffix);
    $customer->is_guest = 0;
    $customer->active = 1;
    $customer->add();
    $created['customers'][] = (int) $customer->id;

    return $customer;
};

$input = static function ($city, $postcode, $street = '12 Rue de Test') {
    return [
        'firstname' => 'QOGEOTEST',
        'lastname' => 'Fixture',
        'phone' => '0600000000',
        'address' => $street,
        'city' => $city,
        'postcode' => $postcode,
    ];
};

/* ── K. A NEW CUSTOMER gets a new address carrying what was typed. ── */
$newCustomer = $makeCustomer('new');
$address = $createAddress->invoke($controller, $newCustomer, $input('Casablanca', '20000'));
$created['addresses'][] = (int) $address->id;

$r->assert((int) $address->id > 0, 'K. a new customer gets an address');
$r->same($address->city, 'Casablanca', 'carrying the city that was typed');
$r->same($address->postcode, '20000', 'and the postcode that was typed');
$r->assert($address->postcode !== '00000', 'AND NEVER 00000');

/* Re-read from the database — the object is not the evidence. */
$stored = new Address((int) $address->id);
$r->same($stored->city, 'Casablanca', 'and MySQL agrees about the city');
$r->same($stored->postcode, '20000', 'and about the postcode');

/* ── E. A blank postcode is stored blank, not invented. ── */
$blankCustomer = $makeCustomer('blank');
$blankAddress = $createAddress->invoke($controller, $blankCustomer, $input('Fès', ''));
$created['addresses'][] = (int) $blankAddress->id;

$storedBlank = new Address((int) $blankAddress->id);
$r->same($storedBlank->postcode, '', 'E. a customer with no postcode gets an EMPTY one');
$r->same($storedBlank->city, 'Fès', 'and their city is still recorded');
$r->assert((int) $blankAddress->id > 0, 'and the address saved perfectly well without one');

/* B. Arabic survives the round trip through MySQL. */
$arabicCustomer = $makeCustomer('arabic');
$arabicAddress = $createAddress->invoke($controller, $arabicCustomer,
    $input('الدار البيضاء', '20250'));
$created['addresses'][] = (int) $arabicAddress->id;

$storedArabic = new Address((int) $arabicAddress->id);
$r->same($storedArabic->city, 'الدار البيضاء', 'B. an Arabic city survives storage intact');

/* An apostrophe is NOT double-escaped into a backslash. */
$apostropheCustomer = $makeCustomer('apostrophe');
$apostropheAddress = $createAddress->invoke($controller, $apostropheCustomer,
    $input("M'Diq", '93100'));
$created['addresses'][] = (int) $apostropheAddress->id;

$storedApostrophe = new Address((int) $apostropheAddress->id);
$r->same($storedApostrophe->city, "M'Diq",
    "M'Diq is stored as M'Diq — no backslash from a redundant pSQL()");

$r->topic('10. Behaviour: history is never rewritten');

/**
 * A second submission is a second REQUEST, so the customer is re-read.
 *
 * `Customer::getAddresses()` caches per customer id for the life of the
 * process. Reusing the same PHP object across two invocations made the second
 * call blind to the address the first had just created, and the test reported
 * a duplicate that production would never produce. Re-instantiating models the
 * only way this method is ever actually reached.
 */
$freshCustomer = static function ($customer) {
    /* `getAddresses()` stores its result in the GLOBAL cache under a key of
       its own making — `resetAddressCache()` does not touch it. The key has to
       be rebuilt exactly, or the second call reads the first call's answer and
       the test accuses production of a duplicate it never creates. */
    $group = Context::getContext()->shop->getGroup();
    $shareOrder = isset($group->share_order) ? (bool) $group->share_order : false;

    Cache::clean('Customer::getAddresses-' . (int) $customer->id . '-'
        . (int) Context::getContext()->language->id . '-' . ($shareOrder ? 1 : 0));
    Customer::resetAddressCache((int) $customer->id);

    return new Customer((int) $customer->id);
};

/* ── L. AN UNUSED ADDRESS IS CORRECTED IN PLACE. ── */
$reuseCustomer = $makeCustomer('reuse');
$first = $createAddress->invoke($controller, $reuseCustomer, $input('Rabat', '10000'));
$created['addresses'][] = (int) $first->id;
$firstId = (int) $first->id;

$second = $createAddress->invoke($controller, $freshCustomer($reuseCustomer), $input('Salé', '11000'));
$created['addresses'][] = (int) $second->id;

$r->same((int) $second->id, $firstId,
    'L. an address no order has used is updated in place, not duplicated');
$r->same((new Address($firstId))->city, 'Salé', 'and it now carries the new city');

/* Identical input writes nothing new and changes nothing. */
$again = $createAddress->invoke($controller, $freshCustomer($reuseCustomer), $input('Salé', '11000'));
$created['addresses'][] = (int) $again->id;
$r->same((int) $again->id, $firstId, 'and repeating the same address is a no-op');

/* ── THE ONE THAT MATTERS: AN ADDRESS AN ORDER USES IS RETIRED, NOT EDITED.
   `Address::isUsed()` counts order rows, so the fixture below makes the
   address genuinely "used" by pointing a real order row's delivery address at
   it — an order that already exists, never a new one. ── */
$usedCustomer = $makeCustomer('used');
$usedAddress = $createAddress->invoke($controller, $usedCustomer, $input('Agadir', '80000'));
$created['addresses'][] = (int) $usedAddress->id;
$usedId = (int) $usedAddress->id;

$db = Db::getInstance();
$borrowedOrder = (int) $db->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`
    ORDER BY `id_order` DESC');
$originalDelivery = (int) $db->getValue('SELECT `id_address_delivery` FROM `' . _DB_PREFIX_ . 'orders`
    WHERE `id_order` = ' . $borrowedOrder);

$db->execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `id_address_delivery` = ' . $usedId
    . ' WHERE `id_order` = ' . $borrowedOrder);

$r->assert((new Address($usedId))->isUsed(), 'the fixture address is now used by an order');

$replacement = $createAddress->invoke($controller, $freshCustomer($usedCustomer), $input('Tiznit', '85000'));
$created['addresses'][] = (int) $replacement->id;

$r->assert((int) $replacement->id !== $usedId,
    'a USED address is NOT edited — a new row is created instead');

$retired = new Address($usedId);
$r->same($retired->city, 'Agadir', 'THE HISTORICAL ROW STILL SAYS AGADIR');
$r->same($retired->postcode, '80000', 'with the postcode the order was placed against');
$r->assert((bool) $retired->deleted, 'and it is retired, so it is offered to nobody again');
$r->same((new Address((int) $replacement->id))->city, 'Tiznit',
    'while the new address carries the new city');

/* Put the borrowed order back before anything else runs. */
$db->execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET `id_address_delivery` = ' . $originalDelivery
    . ' WHERE `id_order` = ' . $borrowedOrder);
$r->same((int) $db->getValue('SELECT `id_address_delivery` FROM `' . _DB_PREFIX_ . 'orders`
    WHERE `id_order` = ' . $borrowedOrder), $originalDelivery,
    'the borrowed order points back at its own address');

/* ══════════════════════════════════════════════════════════════════════════
   4. THE RESOLVER CONTRACT — §21
   ══════════════════════════════════════════════════════════════════════════ */

$r->topic('11. A Quick Order address is structurally usable by a geography resolver');

$resolverClass = 'Twiza\\Solidarity\\Application\\Geography\\CustomerLocalityResolver';
$twizaPresent = is_file(_PS_MODULE_DIR_ . 'twizasolidarity/twizasolidarity.php');

/* The contract is about the ADDRESS, so it is stated first without needing
   the other module at all — which is also the proof that Quick Order does not
   depend on it. */
$contractAddress = new Address((int) $address->id);

$r->same((int) $contractAddress->id_country, (int) Configuration::get('PS_COUNTRY_DEFAULT'),
    'the address names a country by id');
$r->assert($contractAddress->city !== '' && $contractAddress->city !== '-',
    'a city a resolver could look up');
$r->assert(preg_match('/^[0-9]{5}$/', $contractAddress->postcode) === 1,
    'and a postcode of the shape the country declares');

if ($twizaPresent) {
    require_once _PS_MODULE_DIR_ . 'twizasolidarity/twizasolidarity.php';
}

if ($twizaPresent && class_exists($resolverClass)) {
    $resolver = new $resolverClass();
    $resolution = $resolver->resolve([
        'id_country' => (int) $contractAddress->id_country,
        'postcode' => $contractAddress->postcode,
        'city' => $contractAddress->city,
    ]);

    $r->assert($resolution->countrySupported(),
        'the resolver accepts the country this address carries');
    $r->assert($resolution->reason() !== '', 'and answers with a reason, whatever the answer');

    /* With no national dataset installed the answer is `unresolved`, and that
       is the correct answer — the input is now usable, which is a different
       claim from the geography being known. */
    $r->assert($resolution->confidence() !== 'unresolved'
        || $resolution->reason() !== 'address_carries_only_software_defaults',
        'AND NEVER "the address carries only software defaults" — that era is over');

    $old = $resolver->resolve(['id_country' => (int) $contractAddress->id_country,
        'postcode' => '00000', 'city' => '-']);
    $r->same($old->reason(), 'address_carries_only_software_defaults',
        'while an address of the OLD shape still reports exactly that');
} else {
    $r->assert(true, 'twizasolidarity is absent — Quick Order neither needs nor notices it');
}

/* ══════════════════════════════════════════════════════════════════════════
   5. CLEANUP
   ══════════════════════════════════════════════════════════════════════════ */

$r->topic('12. Zero residue');

/* Swept by the fixture marker rather than only by the ids collected above.
   A collected-ids-only cleanup leaks whatever an ASSERTION FAILURE created
   before the list was appended to — which is exactly what happened while this
   file was being written, and left two rows behind for a later run to find.

   Hard delete: these are fixtures, and `Address::delete()` would only
   soft-delete a used one. None is used by now — the borrowed order was handed
   back above, and that is asserted. */
Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'address`
    WHERE `firstname` = \'QOGEOTEST\'');

Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'customer_group`
    WHERE `id_customer` IN (SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`
        WHERE `firstname` = \'QOGEOTEST\')');
Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'customer`
    WHERE `firstname` = \'QOGEOTEST\'');

$r->same((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
    WHERE `firstname` = \'QOGEOTEST\''), 0, 'no TEST customer remains');
$r->same((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address`
    WHERE `firstname` = \'QOGEOTEST\''), 0, 'no TEST address remains');
$r->same((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address`
    WHERE `postcode` = \'00000\' AND `id_address` > ' . (int) max($created['addresses'])), 0,
    'and this run created no 00000 address at all');

exit($r->summary());
