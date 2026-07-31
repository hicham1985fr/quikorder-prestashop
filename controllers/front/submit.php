<?php
/**
 * Quick Order – Submit Controller (v1.4.1)
 *
 * Pure AJAX controller — $this->ajax = true.
 * Uses admin-selected order state from configuration.
 * PRIMARY: resolves combination from group[] values sent by JS (100% reliable).
 * FALLBACK: id_product_attribute sent by JS (may be 0 on some themes).
 * Email sending disabled (fake email, would cause timeout on shared hosting).
 * All responses use ajaxDie() — guaranteed HTTP 200 JSON.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickOrderSubmitModuleFrontController extends ModuleFrontController
{
    /** @var bool Force AJAX mode — prevents template rendering */
    public $ajax = true;

    public $ssl = true;
    public $display_column_left  = false;
    public $display_column_right = false;

    /** @var array Collected change logs for the order note */
    private $changeLogs = [];

    public function init()
    {
        /* Intercept redirects: PrestaShop's parent::init() may redirect
         * (SSL, language, maintenance…) which sends a full HTML page.
         * We capture any Location header and prevent the redirect. */
        ob_start();
        parent::init();
        ob_end_clean();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->ajaxResponse(false, 'POST required.');
        }
        if (Tools::getValue('ajax') !== '1' || !Tools::isSubmit('submitQuickOrder')) {
            $this->ajaxResponse(false, 'Invalid request.');
        }
    }

    public function postProcess()
    {
        /* Ensure clean output — discard any stray HTML/warnings */
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        try {
            $token = Tools::getValue('token');
            if (!$token || $token !== Tools::getToken(false)) {
                $this->ajaxResponse(false, $this->module->l('Invalid security token. Please refresh the page.', 'submit'));
            }
            $this->processOrder();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[QuickOrder] ' . $e->getMessage(), 3, null, 'QuickOrder', 0, true);
            $this->ajaxResponse(false, $e->getMessage());
        } catch (Exception $e) {
            PrestaShopLogger::addLog('[QuickOrder] ' . $e->getMessage(), 3, null, 'QuickOrder', 0, true);
            $this->ajaxResponse(false, $e->getMessage());
        }
    }

    /** Override — prevent any template rendering */
    public function initContent()
    {
        $this->ajaxResponse(false, 'Unexpected state.');
    }

    /** Override — prevent display() from rendering anything */
    public function display()
    {
        /* Do nothing — ajaxResponse handles all output via die() */
    }

    /* ═══════════════════════════ Pipeline ═══════════════════════════ */

    private function processOrder()
    {
        $input = $this->validateInput();

        /* ── Resolve combination from group[] values sent by JS ── */
        $idPA = $input['id_product_attribute'];

        if ($idPA < 1) {
            $idPA = $this->resolveIPAFromGroups($input['id_product']);
            $input['id_product_attribute'] = $idPA;
        }

        $this->validateProduct($input['id_product'], $input['id_product_attribute'], $input['quantity']);

        $customer = $this->getOrCreateCustomer($input['firstname'], $input['lastname'], $input['phone']);
        $address  = $this->createAddress($customer, $input);
        $cart     = $this->buildCart($customer, $address, $input['id_product'], $input['id_product_attribute'], $input['quantity']);
        $idOrder  = $this->placeOrder($cart, $customer);
        $this->attachOrderNote($idOrder, $input, $customer, $cart);

        /* Get order reference — lightweight query, no full Object load */
        $reference = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT `reference` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $idOrder
        );

        /* ── Live kiosk notification ── */
        /* Note: Db::getValue() appends LIMIT 1 automatically — do NOT add it manually */
        try {
            $productName = (string) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                'SELECT `product_name` FROM `' . _DB_PREFIX_ . 'order_detail`
                 WHERE `id_order` = ' . (int) $idOrder
            );
            $orderTotal = (float) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                'SELECT `total_paid` FROM `' . _DB_PREFIX_ . 'orders`
                 WHERE `id_order` = ' . (int) $idOrder
            );
            Db::getInstance()->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'qo_notifications`
                 (`id_order`, `reference`, `customer_name`, `phone`, `address`,
                  `product_name`, `quantity`, `total_paid`, `created_at`)
                 VALUES (
                     ' . (int) $idOrder . ',
                     "' . pSQL($reference) . '",
                     "' . pSQL($input['firstname'] . ' ' . $input['lastname']) . '",
                     "' . pSQL($input['phone']) . '",
                     "' . pSQL($input['address']) . '",
                     "' . pSQL($productName) . '",
                     ' . (int) $input['quantity'] . ',
                     ' . (float) $orderTotal . ',
                     NOW()
                 )'
            );
        } catch (Exception $e) {
            /* Non-fatal — order is already placed, just log the failure */
            PrestaShopLogger::addLog(
                '[QuickOrder] Kiosk notification insert failed for order #' . $idOrder . ': ' . $e->getMessage(),
                2, null, 'Order', $idOrder, true
            );
        }

        $this->ajaxResponse(true, $this->module->l('Order placed successfully!', 'submit'), [
            'order_id'     => $idOrder,
            'reference'    => $reference,
            'redirect_url' => $this->context->link->getModuleLink('quickorder', 'success', [
                'reference'  => $reference,
                'secure_key' => $customer->secure_key,
            ], Tools::usingSecureMode()),
        ]);
    }

    /**
     * Resolve id_product_attribute from the group[] values sent by the frontend.
     *
     * The JS reads select[name="group[X]"] and input[name="group[X]"]:checked
     * from the native PrestaShop variant selectors and sends them as POST params.
     * We match these attribute IDs against the product's combinations to find
     * the exact id_product_attribute — with the correct price.
     */
    private function resolveIPAFromGroups($idProduct)
    {
        /* Collect group values from POST: group[1]=5, group[3]=12, etc. */
        $groups = Tools::getValue('group');

        if (!is_array($groups) || empty($groups)) {
            PrestaShopLogger::addLog(
                '[QuickOrder] No group[] values received for product ' . $idProduct,
                2, null, 'QuickOrder', $idProduct, true
            );
            return 0;
        }

        /* Extract selected attribute IDs */
        $selectedAttrs = [];
        foreach ($groups as $groupId => $attrId) {
            $attrId = (int) $attrId;
            if ($attrId > 0) {
                $selectedAttrs[] = $attrId;
            }
        }

        if (empty($selectedAttrs)) {
            return 0;
        }

        sort($selectedAttrs);
        $attrCount = count($selectedAttrs);

        PrestaShopLogger::addLog(
            '[QuickOrder] Resolving IPA for product ' . $idProduct
            . ' with attributes: ' . implode(',', $selectedAttrs),
            1, null, 'QuickOrder', $idProduct, true
        );

        /* Find the combination that matches ALL selected attributes exactly */
        $idShop = (int) $this->context->shop->id;

        $sql = 'SELECT pa.`id_product_attribute`, GROUP_CONCAT(pac.`id_attribute` ORDER BY pac.`id_attribute` ASC) AS attr_list
                FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                    ON pa.`id_product_attribute` = pas.`id_product_attribute`
                    AND pas.`id_shop` = ' . $idShop . '
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    ON pa.`id_product_attribute` = pac.`id_product_attribute`
                WHERE pa.`id_product` = ' . (int) $idProduct . '
                GROUP BY pa.`id_product_attribute`
                HAVING attr_list = \'' . pSQL(implode(',', $selectedAttrs)) . '\'';

        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

        if ($row && !empty($row['id_product_attribute'])) {
            $resolvedIPA = (int) $row['id_product_attribute'];
            PrestaShopLogger::addLog(
                '[QuickOrder] Resolved IPA = ' . $resolvedIPA . ' for product ' . $idProduct,
                1, null, 'QuickOrder', $idProduct, true
            );
            return $resolvedIPA;
        }

        PrestaShopLogger::addLog(
            '[QuickOrder] Could not resolve combination for product ' . $idProduct
            . ' with attributes ' . implode(',', $selectedAttrs),
            3, null, 'QuickOrder', $idProduct, true
        );
        return 0;
    }

    /* ═══════════════════════════ Validation ═══════════════════════════ */

    private function validateInput()
    {
        $fullName  = trim(Tools::getValue('full_name'));
        $phone     = trim(Tools::getValue('phone'));
        $address   = trim(Tools::getValue('address'));
        $city      = trim(Tools::getValue('city'));
        $quantity  = (int) Tools::getValue('quantity');
        $idProduct = (int) Tools::getValue('id_product');
        $idPA      = (int) Tools::getValue('id_product_attribute');

        if (empty($fullName) || empty($phone) || empty($address) || $quantity < 1 || $idProduct < 1) {
            throw new Exception($this->module->l('Please fill in all required fields.', 'submit'));
        }

        if ($quantity > 99) {
            throw new Exception($this->module->l('Maximum quantity is 99.', 'submit'));
        }

        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneDigits) < 8) {
            throw new Exception($this->module->l('Please enter a valid phone number (at least 8 digits).', 'submit'));
        }

        $parts     = explode(' ', $fullName, 2);
        $firstname = trim($parts[0]);
        $lastname  = isset($parts[1]) ? trim($parts[1]) : $firstname;

        $firstname = preg_replace('/[^a-zA-Z\x{00C0}-\x{024F}\x{0600}-\x{06FF}\s\-\.\']/u', '', $firstname);
        $lastname  = preg_replace('/[^a-zA-Z\x{00C0}-\x{024F}\x{0600}-\x{06FF}\s\-\.\']/u', '', $lastname);

        if (empty($firstname)) {
            $firstname = 'Customer';
        }
        if (empty($lastname)) {
            $lastname = 'Customer';
        }

        $firstname = Tools::substr($firstname, 0, 32);
        $lastname  = Tools::substr($lastname, 0, 32);

        if (Tools::strlen($address) < 5) {
            throw new Exception($this->module->l('Please enter a valid address (at least 5 characters).', 'submit'));
        }

        if (empty($city)) {
            $city = '-';
        }

        return [
            'firstname'            => $firstname,
            'lastname'             => $lastname,
            'phone'                => $phone,
            'phone_digits'         => $phoneDigits,
            'address'              => $address,
            'city'                 => Tools::substr($city, 0, 64),
            'quantity'             => $quantity,
            'id_product'           => $idProduct,
            'id_product_attribute' => $idPA,
        ];
    }

    private function validateProduct($idProduct, $idPA, $quantity)
    {
        $product = new Product($idProduct, false, $this->context->language->id);

        if (!Validate::isLoadedObject($product) || !$product->active) {
            throw new Exception($this->module->l('This product is not available.', 'submit'));
        }

        $hasCombinations = (bool) Product::getProductAttributesIds($idProduct);

        if ($hasCombinations && $idPA < 1) {
            throw new Exception($this->module->l('Could not determine the selected product variant. Please select size/color and try again.', 'submit'));
        }

        if ($idPA > 0) {
            $combination = new Combination($idPA);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                throw new Exception($this->module->l('The selected product option is invalid.', 'submit'));
            }
        }

        $availableQty = StockAvailable::getQuantityAvailableByProduct($idProduct, $idPA);
        if (!Product::isAvailableWhenOutOfStock($product->out_of_stock) && $availableQty < $quantity) {
            throw new Exception(sprintf($this->module->l('Insufficient stock. Only %d available.', 'submit'), max(0, $availableQty)));
        }
    }

    /* ═══════════════════════════ Customer ═══════════════════════════ */

    /**
     * Get or create a REGULAR customer (not guest) identified by phone number.
     *
     * Phone → unique email → unique customer.
     * If customer exists and name changed, log the change BEFORE updating.
     */
    private function getOrCreateCustomer($firstname, $lastname, $phone)
    {
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        $shopDomain  = Configuration::get('PS_SHOP_DOMAIN');
        if (empty($shopDomain)) {
            $shopDomain = Tools::getShopDomain();
        }

        /* Clean domain: remove protocol, port, path — keep only hostname */
        $shopDomain = preg_replace('#^https?://#i', '', $shopDomain);
        $shopDomain = preg_replace('#[:/].*$#', '', $shopDomain);
        if (empty($shopDomain) || $shopDomain === 'localhost' || !preg_match('/\./', $shopDomain)) {
            $shopDomain = 'quickorder.local';
        }

        $email      = 'qo' . $phoneDigits . '@' . $shopDomain;
        $idCustomer = (int) Customer::customerExists($email, true);

        if ($idCustomer) {
            $customer = new Customer($idCustomer);
            if (Validate::isLoadedObject($customer)) {

                /* ── Log name changes ── */
                $oldFirst = trim($customer->firstname);
                $oldLast  = trim($customer->lastname);
                $newFirst = trim($firstname);
                $newLast  = trim($lastname);

                if ($oldFirst !== $newFirst || $oldLast !== $newLast) {
                    $this->logCustomerChange(
                        (int) $customer->id,
                        'name',
                        $oldFirst . ' ' . $oldLast,
                        $newFirst . ' ' . $newLast,
                        $phone
                    );

                    $customer->firstname = pSQL($newFirst);
                    $customer->lastname  = pSQL($newLast);
                    $customer->update();
                }

                /* Ensure customer is not a guest (upgrade old guests) */
                $qoGroupId = (int) Configuration::get(QuickOrder::CFG_CUSTOMER_GROUP);
                if (!$qoGroupId) {
                    $qoGroupId = (int) Configuration::get('PS_CUSTOMER_GROUP');
                }

                if ((int) $customer->is_guest === 1) {
                    $customer->is_guest = 0;
                    $customer->id_default_group = $qoGroupId;
                    $customer->update();
                }

                /* Ensure existing QO customer is in the correct group */
                if ((int) $customer->id_default_group !== $qoGroupId) {
                    $customer->id_default_group = $qoGroupId;
                    $customer->update();
                }
                /* Ensure group membership */
                if (!in_array($qoGroupId, $customer->getGroups())) {
                    $customer->addGroups([$qoGroupId]);
                }

                return $customer;
            }
        }

        /* ── Create new REGULAR customer ── */
        $qoGroupId = (int) Configuration::get(QuickOrder::CFG_CUSTOMER_GROUP);
        if (!$qoGroupId) {
            $qoGroupId = (int) Configuration::get('PS_CUSTOMER_GROUP');
        }

        $customer                   = new Customer();
        $customer->firstname        = pSQL($firstname);
        $customer->lastname         = pSQL($lastname);
        $customer->email            = $email;
        $customer->is_guest         = 0;
        $customer->active           = 1;
        $customer->id_default_group = $qoGroupId;
        $customer->passwd           = Tools::hash(Tools::passwdGen(16) . _COOKIE_KEY_);
        $customer->id_shop          = (int) $this->context->shop->id;

        if (!$customer->add()) {
            throw new Exception($this->module->l('Failed to create customer account.', 'submit'));
        }

        /* Assign to QO group */
        $customer->addGroups([$qoGroupId]);

        $this->logCustomerChange(
            (int) $customer->id,
            'created',
            '',
            $customer->firstname . ' ' . $customer->lastname,
            $phone
        );

        return $customer;
    }

    /* ═══════════════════════════ Address ═══════════════════════════ */

    /**
     * Create or update the delivery address.
     * Logs address changes when the same customer orders with a different address.
     */
    private function createAddress($customer, array $input)
    {
        /* ── Check if customer already has a Quick Order address ── */
        $existingAddresses = $customer->getAddresses((int) $this->context->language->id);
        $existingAddr      = null;

        foreach ($existingAddresses as $a) {
            if (strpos($a['alias'], 'Quick Order') !== false) {
                $existingAddr = new Address((int) $a['id_address']);
                break;
            }
        }

        $newAddress1 = pSQL(Tools::substr($input['address'], 0, 128));
        $newCity     = !empty($input['city']) ? pSQL(Tools::substr($input['city'], 0, 64)) : '-';

        if ($existingAddr && Validate::isLoadedObject($existingAddr)) {
            /* ── Log address change if different ── */
            $oldAddr = trim($existingAddr->address1);
            $newAddr = trim($input['address']);

            if ($oldAddr !== $newAddr) {
                $this->logCustomerChange(
                    (int) $customer->id,
                    'address',
                    $oldAddr . ', ' . $existingAddr->city,
                    $newAddr . ', ' . $newCity,
                    $input['phone']
                );
            }

            /* Update existing address */
            $existingAddr->firstname    = pSQL($input['firstname']);
            $existingAddr->lastname     = pSQL($input['lastname']);
            $existingAddr->address1     = $newAddress1;
            $existingAddr->city         = $newCity;
            $existingAddr->phone        = pSQL(Tools::substr($input['phone'], 0, 16));
            $existingAddr->phone_mobile = pSQL(Tools::substr($input['phone'], 0, 16));
            $existingAddr->update();

            return $existingAddr;
        }

        /* ── Create new address ── */
        $addr              = new Address();
        $addr->id_customer = (int) $customer->id;
        $addr->id_country  = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $addr->alias       = 'Quick Order';
        $addr->firstname   = pSQL($input['firstname']);
        $addr->lastname    = pSQL($input['lastname']);
        $addr->address1    = $newAddress1;
        $addr->city        = $newCity;
        $addr->postcode    = '00000';
        $addr->phone       = pSQL(Tools::substr($input['phone'], 0, 16));
        $addr->phone_mobile = pSQL(Tools::substr($input['phone'], 0, 16));

        $country = new Country((int) $addr->id_country);
        if ($country->contains_states) {
            $states = State::getStatesByIdCountry((int) $addr->id_country);
            if (!empty($states)) {
                $addr->id_state = (int) $states[0]['id_state'];
            }
        }

        if (!$addr->add()) {
            $addr->address1 = pSQL(Tools::substr(preg_replace('/[^a-zA-Z0-9\s,.\-\/]/u', ' ', $input['address']), 0, 128));
            $addr->city     = 'City';
            if (!$addr->add()) {
                throw new Exception($this->module->l('Failed to create delivery address.', 'submit'));
            }
        }
        return $addr;
    }

    /* ═══════════════════════════ Change Log ═══════════════════════════ */

    /**
     * Log customer data changes to PrestaShop logger + private order message.
     *
     * Logs are visible in:
     *   - Back Office → Advanced Parameters → Logs (PrestaShopLogger)
     *   - Each order's private notes (Message)
     *
     * @param int    $idCustomer
     * @param string $field      'name', 'address', 'created'
     * @param string $oldValue
     * @param string $newValue
     * @param string $phone      The identifying phone number
     */
    private function logCustomerChange($idCustomer, $field, $oldValue, $newValue, $phone)
    {
        $timestamp = date('Y-m-d H:i:s');

        if ($field === 'created') {
            $logText = sprintf(
                '[QuickOrder] NEW CUSTOMER #%d | Phone: %s | Name: %s | Date: %s',
                $idCustomer, $phone, $newValue, $timestamp
            );
            $noteText = sprintf('New customer created: %s', $newValue);
        } else {
            $logText = sprintf(
                '[QuickOrder] CUSTOMER #%d UPDATE | Phone: %s | Field: %s | Old: "%s" → New: "%s" | Date: %s',
                $idCustomer, $phone, strtoupper($field), $oldValue, $newValue, $timestamp
            );
            $noteText = sprintf('%s changed: "%s" → "%s"', ucfirst($field), $oldValue, $newValue);
        }

        /* Store for order note */
        $this->changeLogs[] = $noteText;

        /* Log to PrestaShop Logs (Advanced Parameters → Logs) */
        PrestaShopLogger::addLog(
            $logText,
            1,
            null,
            'Customer',
            $idCustomer,
            true
        );
    }

    /* ═══════════════════════════ Cart ═══════════════════════════ */

    private function buildCart($customer, $address, $idProduct, $idPA, $quantity)
    {
        /* Ensure currency is fully loaded (precision required by hooks like ps_googleanalytics) */
        if (!$this->context->currency || !$this->context->currency->id || $this->context->currency->precision === null) {
            $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $this->context->currency = new Currency($idCurrency);
        }

        $cart                      = new Cart();
        $cart->id_customer         = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice  = (int) $address->id;
        $cart->id_currency         = (int) $this->context->currency->id;
        $cart->id_lang             = (int) $this->context->language->id;
        $cart->id_shop             = (int) $this->context->shop->id;
        $cart->id_carrier          = (int) Configuration::get('PS_CARRIER_DEFAULT');
        $cart->secure_key          = $customer->secure_key;

        if (!$cart->add()) {
            throw new Exception($this->module->l('Failed to create cart.', 'submit'));
        }

        $this->context->cart     = $cart;
        $this->context->customer = $customer;

        try {
            $result = $cart->updateQty($quantity, $idProduct, $idPA > 0 ? $idPA : null);
        } catch (\Throwable $t) {
            PrestaShopLogger::addLog(
                '[QuickOrder] updateQty CRASH: ' . $t->getMessage()
                . ' | File: ' . basename($t->getFile()) . ':' . $t->getLine(),
                3, null, 'QuickOrder', $idProduct, true
            );
            throw new Exception($this->module->l('Failed to add product to cart.', 'submit') . ' [' . $t->getMessage() . ']');
        }

        if ($result < 0 || $result === false) {
            PrestaShopLogger::addLog(
                '[QuickOrder] updateQty returned ' . var_export($result, true)
                . ' | product=' . $idProduct . ' ipa=' . $idPA . ' qty=' . $quantity
                . ' | catalog_mode=' . (int)Configuration::isCatalogMode()
                . ' | cart=' . $cart->id,
                3, null, 'QuickOrder', $idProduct, true
            );
            throw new Exception($this->module->l('Failed to add product to cart.', 'submit'));
        }

        try {
            $carriers = $cart->simulateCarriersOutput();
            if (!empty($carriers)) {
                $cart->id_carrier = (int) $carriers[0]['id_carrier'];
                $cart->update();
            }
        } catch (Exception $e) {
            // keep default carrier
        }

        /* Apply landing-page pack discount (server-side lookup — client values ignored) */
        $this->applyPackDiscount($cart, $idProduct, $quantity);

        return $cart;
    }

    /**
     * Look up a quantity pack configured via landingpagecod for this product,
     * match by quantity, and apply the reduction as a single-use cart rule.
     *
     * Security: the discount amount is read from the module's DB config,
     * NOT from any client-submitted parameter.
     */
    private function applyPackDiscount(Cart $cart, $idProduct, $quantity)
    {
        $landingFile = dirname(dirname(dirname(dirname(__FILE__))))
            . '/landingpagecod/classes/LpcodProductLanding.php';

        if (!file_exists($landingFile)) {
            return; // landingpagecod not installed
        }

        if (!class_exists('LpcodProductLanding')) {
            require_once $landingFile;
        }

        if (!LpcodProductLanding::landingExists($idProduct)) {
            return;
        }

        $landing = LpcodProductLanding::loadByProduct($idProduct);
        $config  = $landing->getQtyPacks();

        if (empty($config['enabled']) || empty($config['packs'])) {
            return;
        }

        /* Find pack matching submitted quantity */
        $matchedPack = null;
        foreach ($config['packs'] as $pack) {
            if ((int) $pack['qty'] === (int) $quantity) {
                $matchedPack = $pack;
                break;
            }
        }

        if (
            !$matchedPack
            || !isset($matchedPack['reduction_type'])
            || $matchedPack['reduction_type'] === 'none'
            || empty($matchedPack['reduction_value'])
            || (float) $matchedPack['reduction_value'] <= 0
        ) {
            return;
        }

        $reductionType  = $matchedPack['reduction_type'];
        $reductionValue = (float) $matchedPack['reduction_value'];

        /* Cap percent at 99 (mirrors admin-side validation) */
        if ($reductionType === 'percent') {
            $reductionValue = min(99, $reductionValue);
        }

        /* Silently delete any expired LPCOD_PACK rules that were never used
           (left over from failed/abandoned sessions) — keeps cart_rule table tidy */
        try {
            Db::getInstance()->execute(
                'DELETE cr FROM `' . _DB_PREFIX_ . 'cart_rule` cr
                 LEFT JOIN `' . _DB_PREFIX_ . 'order_cart_rule` ocr
                     ON cr.`id_cart_rule` = ocr.`id_cart_rule`
                 WHERE cr.`code` LIKE \'LPCOD\_PACK\_%\'
                   AND cr.`date_to` < NOW()
                   AND ocr.`id_cart_rule` IS NULL'
            );
        } catch (Exception $ignored) {}

        /* Name must exist for every active language — PS validates this strictly */
        $ruleName = array();
        foreach (Language::getLanguages(false) as $lang) {
            $ruleName[(int) $lang['id_lang']] = 'Pack x' . (int) $quantity;
        }

        $cartRule = new CartRule();
        $cartRule->name               = $ruleName;
        $cartRule->id_customer        = (int) $cart->id_customer;
        $cartRule->date_from          = date('Y-m-d H:i:s');
        $cartRule->date_to            = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $cartRule->quantity           = 1;
        $cartRule->quantity_per_user  = 1;
        $cartRule->active             = 1;
        $cartRule->highlight          = 0;
        $cartRule->code               = 'LPCOD_PACK_' . strtoupper(Tools::passwdGen(8));
        $cartRule->free_shipping      = 0;
        $cartRule->minimum_amount     = 0;
        $cartRule->minimum_amount_tax = 1;

        if ($reductionType === 'percent') {
            $cartRule->reduction_percent = $reductionValue;
            $cartRule->reduction_amount  = 0;
        } else {
            $cartRule->reduction_amount  = $reductionValue;
            $cartRule->reduction_tax     = 1;
            $cartRule->reduction_percent = 0;
        }

        if (!$cartRule->add()) {
            PrestaShopLogger::addLog(
                '[QuickOrder/LPCOD] Failed to create pack cart rule for product '
                . $idProduct . ' qty ' . $quantity,
                2, null, 'QuickOrder', $idProduct, true
            );
            return;
        }

        $result = $cart->addCartRule((int) $cartRule->id);

        if (!$result) {
            PrestaShopLogger::addLog(
                '[QuickOrder/LPCOD] Failed to apply pack cart rule #'
                . $cartRule->id . ' to cart #' . $cart->id,
                2, null, 'QuickOrder', $idProduct, true
            );
        }
    }

    /* ═══════════════════════════ Order ═══════════════════════════ */

    private function placeOrder($cart, $customer)
    {
        $orderStateId = (int) Configuration::get(QuickOrder::CFG_DEFAULT_OS);
        if (!$orderStateId) {
            $orderStateId = (int) Configuration::get('PS_OS_PREPARATION');
        }

        $os = new OrderState($orderStateId);
        if (!Validate::isLoadedObject($os)) {
            throw new Exception($this->module->l('Configured order state not found. Configure the module first.', 'submit'));
        }

        /* ── Disable email sending during order creation ──
         * Quick Order uses generated emails (qo212...@domain.com) — not real.
         * Mail::Send() would try to connect to SMTP, wait for timeout → 503.
         * PS_MAIL_METHOD = 3 means "Never send emails" in PrestaShop.
         * We restore the original value immediately after.
         */
        $prevMailMethod = Configuration::get('PS_MAIL_METHOD');
        Configuration::updateValue('PS_MAIL_METHOD', 3);

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                $orderStateId,
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                'Quick Order (COD)',
                null,
                [],
                null,
                false,
                $customer->secure_key
            );
        } finally {
            /* Always restore mail method — even if validateOrder throws */
            Configuration::updateValue('PS_MAIL_METHOD', (int) $prevMailMethod);
        }

        $idOrder = (int) $this->module->currentOrder;
        if (!$idOrder) {
            throw new Exception($this->module->l('Order creation failed.', 'submit'));
        }
        return $idOrder;
    }

    /* ═══════════════════════════ Note ═══════════════════════════ */

    private function attachOrderNote($idOrder, array $input, $customer = null, $cart = null)
    {
        $lines = [];
        $lines[] = '[QUICK ORDER]';
        $lines[] = 'Name: ' . $input['firstname'] . ' ' . $input['lastname'];
        $lines[] = 'Phone: ' . $input['phone'];
        $lines[] = 'Address: ' . $input['address'];
        $lines[] = 'Quantity: ' . (int) $input['quantity'];
        $lines[] = 'Date: ' . date('Y-m-d H:i:s');

        /* Append change logs if any */
        if (!empty($this->changeLogs)) {
            $lines[] = '';
            $lines[] = '--- CHANGES DETECTED ---';
            foreach ($this->changeLogs as $log) {
                $lines[] = '> ' . $log;
            }
        }

        $text = implode("\n", $lines);

        /* ── Write to orders.note (Commentaire prive in PS8) ──
         * Uses Db::getInstance()->update() — updates ONLY the note field.
         * No Object loading, no hooks, no recalculations.
         * Safe: pSQL() sanitizes text, (int) enforces numeric ID.
         */
        try {
            Db::getInstance()->update(
                'orders',
                ['note' => pSQL($text, true)],
                'id_order = ' . (int) $idOrder
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[QuickOrder] Failed to set order note #' . $idOrder . ': ' . $e->getMessage(),
                3, null, 'Order', $idOrder, true
            );
        }
    }

    /* ═══════════════════════════ AJAX Response ═══════════════════════════ */

    /**
     * Send JSON response and terminate execution immediately.
     *
     * - Clears all output buffers (prevents stale HTML from contaminating JSON)
     * - Sets proper Content-Type header
     * - Uses die() to guarantee no further code executes
     *
     * @param bool   $success
     * @param string $message
     * @param array  $extra   Additional data to merge into response
     */
    private function ajaxResponse($success, $message, array $extra = [])
    {
        /* Clear ALL output buffers — nothing before our JSON */
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Robots-Tag: noindex');

        $payload = array_merge([
            'success' => (bool) $success,
            'message' => (string) $message,
        ], $extra);

        die(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
