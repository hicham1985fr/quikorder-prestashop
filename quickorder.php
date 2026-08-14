<?php
/**
 * Quick Order – Cash on Delivery (v1.3.0)
 *
 * - Product page: Quick Order form with Name, Phone, Address, Quantity
 * - Variants: handled by native PrestaShop selectors (not duplicated in form)
 * - "Replace Add to Cart" mode: hides ONLY button + qty via CSS
 * - Catalog pages: replaces cart buttons with "Buy Now" product links
 * - Zero overrides, zero cart interception
 *
 * @author  QuickOrder
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/* QO-GEO-1 — delivery-geography validation. A plain require rather than an
   autoloader: this module has one class and no dependencies, and PrestaShop
   loads `quickorder.php` before any of its controllers run. */
require_once __DIR__ . '/classes/QuickOrderAddressInput.php';

class QuickOrder extends PaymentModule
{
    const CFG_DEFAULT_OS      = 'QUICKORDER_DEFAULT_OS';
    const CFG_REPLACE_CART    = 'QUICKORDER_REPLACE_CART';
    const CFG_CUSTOMER_GROUP  = 'QUICKORDER_CUSTOMER_GROUP';
    const CFG_KIOSK_TOKEN     = 'QUICKORDER_KIOSK_TOKEN';

    public function __construct()
    {
        $this->name            = 'quickorder';
        $this->tab             = 'payments_gateways';
        $this->version         = '1.5.0';
        $this->author          = 'QuickOrder';
        $this->need_instance   = 0;
        $this->bootstrap       = true;
        $this->currencies      = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Quick Order – Cash on Delivery');
        $this->description = $this->l('Adds a quick-order form (COD) on product pages.');

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    /* ══════════════════════════ Install / Uninstall ══════════════════════════ */

    public function install()
    {
        if (!Configuration::get(self::CFG_DEFAULT_OS)) {
            Configuration::updateValue(self::CFG_DEFAULT_OS, (int) Configuration::get('PS_OS_PREPARATION'));
        }
        Configuration::updateValue(self::CFG_REPLACE_CART, 0);

        $this->ensureCustomerGroup();
        $this->createNotificationsTable();

        if (!Configuration::get(self::CFG_KIOSK_TOKEN)) {
            Configuration::updateValue(self::CFG_KIOSK_TOKEN, bin2hex(random_bytes(16)));
        }

        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionOrderGridQueryBuilderModifier');
    }

    /**
     * Create the qo_notifications table used by the live kiosk display.
     */
    private function createNotificationsTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'qo_notifications` (
            `id_notification`  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order`         INT(11) UNSIGNED NOT NULL,
            `reference`        VARCHAR(9)       NOT NULL DEFAULT \'\',
            `customer_name`    VARCHAR(128)     NOT NULL DEFAULT \'\',
            `phone`            VARCHAR(32)      NOT NULL DEFAULT \'\',
            `address`          TEXT             NOT NULL,
            `product_name`     VARCHAR(255)     NOT NULL DEFAULT \'\',
            `quantity`         SMALLINT(5)      UNSIGNED NOT NULL DEFAULT 1,
            `total_paid`       DECIMAL(20,6)    NOT NULL DEFAULT 0.000000,
            `created_at`       DATETIME         NOT NULL,
            `displayed_at`     DATETIME         NULL DEFAULT NULL,
            PRIMARY KEY (`id_notification`),
            UNIQUE KEY `uq_order` (`id_order`),
            INDEX `idx_displayed` (`displayed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return Db::getInstance()->execute($sql);
    }

    public function uninstall()
    {
        /* Note: we do NOT delete the customer group — it may have customers assigned */
        /* Note: we do NOT drop qo_notifications — it may contain historical data    */
        Configuration::deleteByName(self::CFG_DEFAULT_OS);
        Configuration::deleteByName(self::CFG_REPLACE_CART);
        Configuration::deleteByName(self::CFG_CUSTOMER_GROUP);
        Configuration::deleteByName(self::CFG_KIOSK_TOKEN);
        return parent::uninstall();
    }

    /* ══════════════════════════ Hooks ══════════════════════════ */

    public function hookPaymentOptions($params)
    {
        return [];
    }

    /**
     * displayHeader — fires early, before <head> closes.
     * Asset registration works here (unlike displayProductAdditionalInfo).
     */
    public function hookDisplayHeader($params)
    {
        $replaceCart = (int) Configuration::get(self::CFG_REPLACE_CART);
        $controller  = $this->context->controller;
        $page        = isset($controller->php_self) ? $controller->php_self : '';

        /* ── Form CSS: every front page, not just the product page ──
         *
         * displayProductAdditionalInfo also renders inside the quickview
         * modal (catalog/_partials/quickview.tpl), and that modal is built
         * from an AJAX partial whose <head> is thrown away — the markup
         * lands in the DOM of the *catalog* page. Registering the stylesheet
         * on the product page alone left the form in the modal completely
         * unstyled. The file is scoped to #qo-box / .qo-*, so carrying it
         * everywhere costs one small request and collides with nothing. */
        $controller->registerStylesheet(
            'qo-form-css',
            'modules/' . $this->name . '/views/css/quickorder-form.css',
            ['media' => 'all', 'priority' => 150]
        );

        /* ── Product page ── */
        if ($page === 'product') {
            /* Hide native cart UI only when replace-cart mode is ON */
            if ($replaceCart) {
                $controller->registerStylesheet(
                    'qo-hide-cart',
                    'modules/' . $this->name . '/views/css/quickorder-hide-cart.css',
                    ['media' => 'all', 'priority' => 900]
                );
            }
            return '';
        }

        if (!$replaceCart) {
            return '';
        }

        /* ── Catalog pages: register JS that replaces cart buttons ── */
        $catalogPages = [
            'index', 'category', 'search', 'prices-drop',
            'new-products', 'best-sales', 'manufacturer', 'supplier',
        ];

        if (in_array($page, $catalogPages)) {
            $controller->registerJavascript(
                'qo-catalog-js',
                'modules/' . $this->name . '/views/js/quickorder-catalog.js',
                ['position' => 'bottom', 'priority' => 900]
            );

            /* Pass translated label to JS (prestashop.js global) */
            Media::addJsDef([
                'qo_buy_label' => $this->l('إشتري الآن'),
            ]);
        }

        return '';
    }

    /**
     * displayProductAdditionalInfo — renders the Quick Order form.
     */
    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!isset($params['product'])) {
            return '';
        }

        $product   = $params['product'];
        $idProduct = (int) (is_array($product) ? ($product['id_product'] ?? 0) : ($product->id ?? 0));

        if ($idProduct < 1) {
            return '';
        }

        /* ── Check stock availability ── */
        $productObj   = new Product($idProduct, false, $this->context->language->id);
        $availableQty = StockAvailable::getQuantityAvailableByProduct($idProduct);
        $allowOOS     = Product::isAvailableWhenOutOfStock($productObj->out_of_stock);
        $inStock      = ($availableQty > 0 || $allowOOS);

        /* Build AJAX URL — use index.php?fc=module format to avoid
         * redirect issues with friendly URLs, language prefixes, or
         * domain mismatches (PS_SHOP_DOMAIN vs actual domain) */
        $useSSL  = Tools::usingSecureMode();
        $baseUrl = ($useSSL ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__;
        $ajaxUrl = $baseUrl . 'index.php?fc=module&module=quickorder&controller=submit';

        /* ── Price data for front-office display ── */
        $priceWithoutReduction = $productObj->getPriceWithoutReduct(false, null, 2);
        $priceFinal            = $productObj->getPrice(true, null, 2);
        $hasDiscount           = ($priceWithoutReduction > $priceFinal);
        $discountPercent       = $hasDiscount
            ? round(100 - ($priceFinal / $priceWithoutReduction * 100))
            : 0;
        $currencySign = $this->context->currency->sign;

        /* ── Does this product have combinations/variants? ── */
        $hasVariants = (bool) Product::getProductAttributesIds($idProduct);

        $this->context->smarty->assign([
            'qo_id_product'       => $idProduct,
            'qo_action_url'       => $ajaxUrl,
            'qo_token'            => Tools::getToken(false),
            'qo_in_stock'         => $inStock,
            'qo_stock_qty'        => (int) $availableQty,
            'qo_price'            => $priceFinal,
            'qo_price_old'        => $hasDiscount ? $priceWithoutReduction : 0,
            'qo_discount_percent' => $discountPercent,
            'qo_currency_sign'    => $currencySign,
            'qo_has_variants'     => $hasVariants,
        ]);

        return $this->fetch('module:quickorder/views/templates/hook/quickorder_form.tpl');
    }

    /* ══════════════════════════ Customer Group ══════════════════════════ */

    /**
     * Ensure the ClientQO customer group exists.
     * Copies settings from the default "Client" group (id_group = PS_CUSTOMER_GROUP).
     * Stores the group ID in QUICKORDER_CUSTOMER_GROUP config.
     *
     * @return int The group ID
     */
    public function ensureCustomerGroup()
    {
        $idGroup = (int) Configuration::get(self::CFG_CUSTOMER_GROUP);

        /* Already configured and valid */
        if ($idGroup > 0) {
            $existing = new Group($idGroup);
            if (Validate::isLoadedObject($existing)) {
                return $idGroup;
            }
        }

        /* Try to find existing group by name */
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $sql = 'SELECT gl.`id_group`
                FROM `' . _DB_PREFIX_ . 'group_lang` gl
                WHERE gl.`name` = \'ClientQO\' AND gl.`id_lang` = ' . $idLang;
        $found = (int) Db::getInstance()->getValue($sql);

        if ($found > 0) {
            Configuration::updateValue(self::CFG_CUSTOMER_GROUP, $found);
            return $found;
        }

        /* Create new group copying settings from Client group */
        $clientGroupId = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $clientGroup   = new Group($clientGroupId);

        $group = new Group();
        $group->reduction            = $clientGroup->reduction ?? 0;
        $group->price_display_method = $clientGroup->price_display_method ?? 1;
        $group->show_prices          = $clientGroup->show_prices ?? 1;

        /* Set name in all languages */
        $languages = Language::getLanguages(false);
        $names = [];
        foreach ($languages as $lang) {
            $names[(int) $lang['id_lang']] = 'ClientQO';
        }
        $group->name = $names;

        if ($group->add()) {
            Configuration::updateValue(self::CFG_CUSTOMER_GROUP, (int) $group->id);

            /* Copy category permissions from Client group */
            $this->copyGroupCategoryPermissions($clientGroupId, (int) $group->id);

            return (int) $group->id;
        }

        /* Fallback: use Client group */
        return $clientGroupId;
    }

    /**
     * Copy category access permissions from source group to target group.
     */
    private function copyGroupCategoryPermissions($sourceGroupId, $targetGroupId)
    {
        $sourceGroupId = (int) $sourceGroupId;
        $targetGroupId = (int) $targetGroupId;

        /* Detect table columns — id_shop may not exist without multistore */
        $cols = Db::getInstance()->executeS(
            'DESCRIBE `' . _DB_PREFIX_ . 'category_group`'
        );
        $colNames = array_column($cols, 'Field');
        $hasShop  = in_array('id_shop', $colNames);

        $selectCols = '`id_category`' . ($hasShop ? ', `id_shop`' : '');
        $rows = Db::getInstance()->executeS(
            'SELECT ' . $selectCols . '
             FROM `' . _DB_PREFIX_ . 'category_group`
             WHERE `id_group` = ' . $sourceGroupId
        );

        if (!empty($rows)) {
            $insertCols = '`id_category`, `id_group`' . ($hasShop ? ', `id_shop`' : '');
            $values = [];
            foreach ($rows as $row) {
                if ($hasShop) {
                    $values[] = '(' . (int) $row['id_category'] . ', ' . $targetGroupId . ', ' . (int) $row['id_shop'] . ')';
                } else {
                    $values[] = '(' . (int) $row['id_category'] . ', ' . $targetGroupId . ')';
                }
            }
            Db::getInstance()->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'category_group` (' . $insertCols . ') VALUES ' . implode(',', $values)
            );
        }
    }

    /* ══════════════════════════ Order Grid — Phone Filter ══════════════════════════ */

    /**
     * Adds a "Phone" column and search filter to the back-office orders grid.
     *
     * The delivery address table (alias `a`) is already joined in the core
     * OrderQueryBuilder, so no extra JOIN is needed here.
     */
    public function hookActionOrderGridDefinitionModifier(array $params)
    {
        /** @var \PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinition $definition */
        $definition = $params['definition'];

        // Insert the Phone column right after the Customer column
        $definition->getColumns()->addAfter(
            'customer',
            (new \PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn('phone'))
                ->setName($this->l('Phone'))
                ->setOptions([
                    'field'    => 'phone',
                    'sortable' => false,
                ])
        );

        // Add the phone text filter (associated to the phone column above)
        $definition->getFilters()->add(
            (new \PrestaShop\PrestaShop\Core\Grid\Filter\Filter(
                'phone',
                \Symfony\Component\Form\Extension\Core\Type\TextType::class
            ))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->l('Search phone')],
                ])
                ->setAssociatedColumn('phone')
        );
    }

    /**
     * Selects `a.phone` from the already-joined delivery address and applies
     * the phone LIKE filter when the staff enters a value in the grid search row.
     */
    public function hookActionOrderGridQueryBuilderModifier(array $params)
    {
        /** @var \Doctrine\DBAL\Query\QueryBuilder $searchQb */
        $searchQb = $params['search_query_builder'];
        /** @var \Doctrine\DBAL\Query\QueryBuilder $countQb */
        $countQb  = $params['count_query_builder'];
        /** @var \PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface $criteria */
        $criteria = $params['search_criteria'];

        // Always pull the phone so every row has the value available
        $searchQb->addSelect('a.`phone`');

        $filters = $criteria->getFilters();

        if (!empty($filters['phone'])) {
            $searchQb
                ->andWhere('a.`phone` LIKE :qo_phone')
                ->setParameter('qo_phone', '%' . $filters['phone'] . '%');

            $countQb
                ->andWhere('a.`phone` LIKE :qo_phone')
                ->setParameter('qo_phone', '%' . $filters['phone'] . '%');
        }
    }

    /* ══════════════════════════ Back-office Config ══════════════════════════ */

    public function getContent()
    {
        // Ensure the grid hooks are registered for already-installed instances
        // registerHook() is idempotent — safe to call repeatedly
        $this->registerHook('actionOrderGridDefinitionModifier');
        $this->registerHook('actionOrderGridQueryBuilderModifier');

        $output = '';

        /* ── Regenerate kiosk token ── */
        if (Tools::isSubmit('submitRegenerateKioskToken')) {
            Configuration::updateValue(self::CFG_KIOSK_TOKEN, bin2hex(random_bytes(16)));
            $output .= $this->displayConfirmation($this->l('Kiosk token regenerated. Update the URL on your display screen.'));
        }

        if (Tools::isSubmit('submitQuickOrderConfig')) {
            $selectedOS    = (int) Tools::getValue(self::CFG_DEFAULT_OS);
            $replaceCart   = (int) Tools::getValue(self::CFG_REPLACE_CART);
            $selectedGroup = (int) Tools::getValue(self::CFG_CUSTOMER_GROUP);

            if ($selectedOS > 0) {
                $os = new OrderState($selectedOS);
                if (Validate::isLoadedObject($os)) {
                    Configuration::updateValue(self::CFG_DEFAULT_OS, $selectedOS);
                    Configuration::updateValue(self::CFG_REPLACE_CART, $replaceCart ? 1 : 0);

                    if ($selectedGroup > 0) {
                        $grp = new Group($selectedGroup);
                        if (Validate::isLoadedObject($grp)) {
                            Configuration::updateValue(self::CFG_CUSTOMER_GROUP, $selectedGroup);
                        }
                    }

                    $output .= $this->displayConfirmation($this->l('Settings saved.'));
                } else {
                    $output .= $this->displayError($this->l('The selected order state does not exist.'));
                }
            } else {
                $output .= $this->displayError($this->l('Please select a valid order state.'));
            }
        }

        /* Ensure group exists on every config page load */
        $this->ensureCustomerGroup();

        return $output . $this->renderConfigForm();
    }

    private function renderConfigForm()
    {
        $idLang      = (int) $this->context->language->id;
        $orderStates = OrderState::getOrderStates($idLang);
        $stateOptions = [];

        foreach ($orderStates as $os) {
            $stateOptions[] = [
                'id_order_state' => (int) $os['id_order_state'],
                'name'           => $os['name'],
            ];
        }

        /* Build group options */
        $groups = Group::getGroups($idLang);
        $groupOptions = [];
        foreach ($groups as $g) {
            $groupOptions[] = [
                'id_group' => (int) $g['id_group'],
                'name'     => $g['name'],
            ];
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Quick Order Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type'     => 'select',
                        'label'    => $this->l('Default order status for Quick Orders'),
                        'name'     => self::CFG_DEFAULT_OS,
                        'desc'     => $this->l('The order status assigned to every new Quick Order.'),
                        'required' => true,
                        'options'  => [
                            'query' => $stateOptions,
                            'id'    => 'id_order_state',
                            'name'  => 'name',
                        ],
                    ],
                    [
                        'type'     => 'select',
                        'label'    => $this->l('Customer group for Quick Order customers'),
                        'name'     => self::CFG_CUSTOMER_GROUP,
                        'desc'     => $this->l('Customers created via Quick Order will be assigned to this group. Default: ClientQO (auto-created).'),
                        'required' => true,
                        'options'  => [
                            'query' => $groupOptions,
                            'id'    => 'id_group',
                            'name'  => 'name',
                        ],
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Enable Quick Order mode (replace Add to Cart)'),
                        'name'    => self::CFG_REPLACE_CART,
                        'desc'    => $this->l('When enabled: hides "Add to Cart" on product pages via CSS and replaces catalog cart buttons with a "Buy Now" link to the product page. When disabled: both workflows coexist. No overrides, no cart interception — fully reversible.'),
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'replace_on',  'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'replace_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->table           = $this->table;
        $helper->identifier      = $this->identifier;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action   = 'submitQuickOrderConfig';
        $helper->default_form_language    = $idLang;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->fields_value = [
            self::CFG_DEFAULT_OS      => (int) Configuration::get(self::CFG_DEFAULT_OS),
            self::CFG_REPLACE_CART    => (int) Configuration::get(self::CFG_REPLACE_CART),
            self::CFG_CUSTOMER_GROUP  => (int) Configuration::get(self::CFG_CUSTOMER_GROUP),
        ];

        $formHtml = $helper->generateForm([$fieldsForm]);

        /* ── Kiosk URL panel ── */
        $token    = Configuration::get(self::CFG_KIOSK_TOKEN);
        $useSSL   = (bool) Configuration::get('PS_SSL_ENABLED');
        $domain   = Configuration::get('PS_SHOP_DOMAIN' . ($useSSL ? '_SSL' : ''));
        $base     = ($useSSL ? 'https' : 'http') . '://' . $domain . __PS_BASE_URI__;
        $kioskUrl = $base . 'modules/quickorder/kiosk/?token=' . $token;

        $adminToken  = Tools::getAdminTokenLite('AdminModules');
        $currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $kioskPanel  = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-desktop"></i> ' . $this->l('Live Order Kiosk') . '
            </div>
            <div class="form-wrapper">
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Kiosk URL') . '</label>
                    <div class="col-lg-9">
                        <input type="text" class="form-control" readonly
                               value="' . htmlspecialchars($kioskUrl, ENT_QUOTES) . '"
                               onclick="this.select();" style="font-family:monospace;font-size:12px;" />
                        <p class="help-block">' . $this->l('Open this URL on your big screen (Chrome, full-screen F11). No login required. Tap the screen once to enable sound.') . '</p>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-lg-9 col-lg-offset-3">
                        <form method="post" action="' . htmlspecialchars($currentIndex . '&token=' . $adminToken, ENT_QUOTES) . '"
                              onsubmit="return confirm(\'' . $this->l('Regenerate token? The current kiosk URL will stop working.') . '\');">
                            <button type="submit" name="submitRegenerateKioskToken" class="btn btn-default">
                                <i class="icon-refresh"></i> ' . $this->l('Regenerate Token') . '
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>';

        return $kioskPanel . $formHtml;
    }
}
