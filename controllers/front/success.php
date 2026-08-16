<?php
/**
 * Quick Order – Success Controller (v1.4.0)
 *
 * Loads full order details for a rich confirmation page:
 *   - Product(s) with image, name, attributes, quantity, price
 *   - Customer name & phone
 *   - Delivery address
 *   - Order totals (products, shipping, total)
 *   - Order date & reference
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickOrderSuccessModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $reference = Tools::getValue('reference');
        $secureKey = Tools::getValue('secure_key');
        $order     = null;
        $valid     = false;
        $data      = [];

        if ($reference && $secureKey) {
            $orders = Order::getByReference($reference);
            if ($orders && $orders->count()) {
                $candidate = $orders->getFirst();
                if (Validate::isLoadedObject($candidate)) {
                    $customer = new Customer((int) $candidate->id_customer);
                    if (Validate::isLoadedObject($customer) && $customer->secure_key === $secureKey) {
                        $order = $candidate;
                        $valid = true;
                        $data  = $this->buildOrderData($order, $customer);
                    }
                }
            }
        }

        // Fetch seller pixel IDs for ordered products
        $sellerPixels = array();
        if ($valid && $order) {
            $sellerPixels = $this->getSellerPixels($order);
        }

        $relatedProducts = [];
        if ($valid && $order) {
            $relatedProducts = $this->getRelatedProducts($order);
        }

        $this->context->smarty->assign(array_merge([
            'qo_valid'             => $valid,
            'qo_home_url'          => $this->context->link->getPageLink('index', true),
            /* Where « Continuer mes achats » goes. The homepage is a story;
               a shopper who has just bought wants the catalogue, so the CTA
               lands on the Marketplace — the home category, read from the
               configuration rather than hardcoded as 2. */
            'qo_shop_url'          => $this->context->link->getCategoryLink(
                (int) Configuration::get('PS_HOME_CATEGORY'),
                null,
                (int) $this->context->language->id
            ),
            'qo_seller_pixels'     => $sellerPixels,
            'qo_related_products'  => $relatedProducts,
        ], $data));

        $this->setTemplate('module:quickorder/views/templates/front/success.tpl');
    }

    /**
     * Build a complete data array for the template.
     */
    private function buildOrderData(Order $order, Customer $customer)
    {
        $idLang = (int) $this->context->language->id;

        /* ── Products ── */
        $products     = $order->getProducts();
        $productItems = [];

        foreach ($products as $p) {
            $imageUrl = '';
            $idProduct = (int) $p['product_id'];
            $idPA      = (int) $p['product_attribute_id'];

            // Get product image
            try {
                if ($idPA > 0) {
                    $images = Image::getImages($idLang, $idProduct, $idPA);
                }
                if (empty($images)) {
                    $images = Image::getImages($idLang, $idProduct);
                }
                if (!empty($images)) {
                    $idImage  = (int) $images[0]['id_image'];
                    $imageUrl = $this->context->link->getImageLink(
                        isset($p['link_rewrite']) ? $p['link_rewrite'] : 'product',
                        $idProduct . '-' . $idImage,
                        'home_default'
                    );
                }
            } catch (Exception $e) {
                // no image
            }

            $productItems[] = [
                'name'       => $p['product_name'],
                'quantity'   => (int) $p['product_quantity'],
                'unit_price' => Tools::displayPrice($p['unit_price_tax_incl'], (int) $order->id_currency),
                'total'      => Tools::displayPrice($p['total_price_tax_incl'], (int) $order->id_currency),
                'image_url'  => $imageUrl,
                'attributes' => isset($p['attributes']) ? $p['attributes'] : '',
            ];
        }

        /* ── Address ── */
        $address     = new Address((int) $order->id_address_delivery);
        $addressText = '';
        $phone       = '';

        if (Validate::isLoadedObject($address)) {
            $parts = [];
            if (!empty($address->address1)) {
                $parts[] = $address->address1;
            }
            if (!empty($address->address2)) {
                $parts[] = $address->address2;
            }
            if (!empty($address->city) && $address->city !== '-') {
                $parts[] = $address->city;
            }
            if (!empty($address->postcode) && $address->postcode !== '00000') {
                $parts[] = $address->postcode;
            }
            $addressText = implode(', ', $parts);
            $phone       = !empty($address->phone_mobile) ? $address->phone_mobile : $address->phone;
        }

        /* ── Order state ── */
        $orderState = '';
        $currentState = $order->getCurrentState();
        if ($currentState) {
            $os = new OrderState((int) $currentState, $idLang);
            if (Validate::isLoadedObject($os)) {
                $orderState = $os->name;
            }
        }

        /* ── Date ── */
        $orderDate = Tools::displayDate($order->date_add, null, true);

        /* ── Currency ISO code for tracking pixels ── */
        $currency = new Currency((int) $order->id_currency);
        $currencyIso = Validate::isLoadedObject($currency) ? $currency->iso_code : 'MAD';

        return [
            'qo_reference'      => $order->reference,
            'qo_id_order'       => (int) $order->id,
            'qo_date'           => $orderDate,
            'qo_status'         => $orderState,
            'qo_customer_name'  => $customer->firstname . ' ' . $customer->lastname,
            'qo_phone'          => $phone,
            'qo_address'        => $addressText,
            'qo_products'       => $productItems,
            'qo_total_products' => Tools::displayPrice($order->total_products_wt, (int) $order->id_currency),
            'qo_total_shipping' => Tools::displayPrice($order->total_shipping_tax_incl, (int) $order->id_currency),
            'qo_total_paid'     => Tools::displayPrice($order->total_paid, (int) $order->id_currency),
            'qo_total_paid_raw' => (float) $order->total_paid,
            'qo_currency_iso'   => $currencyIso,
            'qo_has_shipping'   => ((float) $order->total_shipping_tax_incl > 0),
        ];
    }

    /**
     * Fetch up to $limit active products from the same category as ordered items,
     * sorted by bestsellers first, excluding the ordered products themselves.
     */
    private function getRelatedProducts(Order $order, $limit = 4)
    {
        $idLang = (int) $this->context->language->id;

        $orderedIds = [];
        foreach ($order->getProducts() as $p) {
            $orderedIds[] = (int) $p['product_id'];
        }
        if (empty($orderedIds)) {
            return [];
        }

        $catIds = [];
        foreach ($orderedIds as $idProduct) {
            $row = Db::getInstance()->getRow(
                'SELECT id_category_default FROM `' . _DB_PREFIX_ . 'product`
                 WHERE id_product = ' . (int) $idProduct
            );
            if ($row) {
                $catIds[] = (int) $row['id_category_default'];
            }
        }
        $catIds = array_unique($catIds);
        if (empty($catIds)) {
            return [];
        }

        $excludeList = implode(',', $orderedIds);
        $catList     = implode(',', $catIds);

        $sql = 'SELECT p.id_product, pl.name, pl.link_rewrite
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . '
                LEFT JOIN (
                    SELECT product_id, SUM(product_quantity) AS sold
                    FROM `' . _DB_PREFIX_ . 'order_detail`
                    GROUP BY product_id
                ) od ON od.product_id = p.id_product
                WHERE p.id_category_default IN (' . $catList . ')
                  AND p.id_product NOT IN (' . $excludeList . ')
                  AND p.active = 1
                  AND p.visibility IN (\'both\', \'catalog\')
                ORDER BY COALESCE(od.sold, 0) DESC, p.date_add DESC
                LIMIT ' . (int) $limit;

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        $related = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];

            $imageUrl = '';
            $images   = Image::getImages($idLang, $idProduct);
            if (!empty($images)) {
                $idImage  = (int) $images[0]['id_image'];
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'],
                    $idProduct . '-' . $idImage,
                    'home_default'
                );
            }

            $price          = (float) Product::getPriceStatic($idProduct, true, null, 2);
            $priceFormatted = Tools::displayPrice($price, (int) $order->id_currency);

            $productUrl = $this->context->link->getProductLink(
                $idProduct,
                $row['link_rewrite'],
                null,
                null,
                $idLang
            );

            $related[] = [
                'name'      => $row['name'],
                'image_url' => $imageUrl,
                'url'       => $productUrl,
                'price'     => $priceFormatted,
            ];
        }

        return $related;
    }

    /**
     * Get unique seller Facebook Pixel IDs for ordered products.
     *
     * The seller tables belong to the ETS marketplace module, which is NOT
     * installed on every shop that runs Quick Order. A missing table is not a
     * quietly shorter pixel list: `DbPDO::_query()` turns the PDO error into a
     * PrestaShopException whatever the debug setting, so the query killed the
     * confirmation page of an order that had already been saved and charged.
     * The shopper got a 500 and no way to tell whether they had bought
     * anything. A marketing pixel must never be able to do that, so the
     * lookup is skipped when its tables are absent and swallowed if it fails
     * for any other reason.
     */
    private function getSellerPixels(Order $order)
    {
        $pixels = array();

        if (!$this->sellerTablesExist()) {
            return $pixels;
        }

        $products = $order->getProducts();

        if (empty($products)) {
            return $pixels;
        }

        $productIds = array();
        foreach ($products as $p) {
            $productIds[] = (int) $p['product_id'];
        }

        $sql = 'SELECT DISTINCT s.fb_pixel_id
                FROM `' . _DB_PREFIX_ . 'ets_mp_seller_product` sp
                INNER JOIN `' . _DB_PREFIX_ . 'ets_mp_seller` s
                    ON s.id_customer = sp.id_customer
                WHERE sp.id_product IN (' . implode(',', $productIds) . ')
                    AND s.fb_pixel_id IS NOT NULL
                    AND s.fb_pixel_id != ""';

        try {
            $rows = Db::getInstance()->executeS($sql);
        } catch (Exception $e) {
            return $pixels;
        }

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pixels[] = $row['fb_pixel_id'];
            }
        }

        return $pixels;
    }

    /**
     * True when both ETS marketplace seller tables are present.
     *
     * Answered once per request: the confirmation page asks at most twice and
     * the answer cannot change under it.
     */
    private function sellerTablesExist()
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $db = Db::getInstance();
        $exists = true;

        /* information_schema rather than `SHOW TABLES LIKE`: `getValue()`
           appends `LIMIT 1`, which SHOW refuses, and LIKE would treat the
           prefix underscore as a wildcard. This match is exact. */
        foreach (array('ets_mp_seller_product', 'ets_mp_seller') as $table) {
            $sql = 'SELECT COUNT(*) FROM `information_schema`.`TABLES`
                    WHERE `TABLE_SCHEMA` = DATABASE()
                        AND `TABLE_NAME` = \'' . pSQL(_DB_PREFIX_ . $table) . '\'';

            if (!(int) $db->getValue($sql)) {
                $exists = false;
                break;
            }
        }

        return $exists;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->module->l('Order Confirmation', 'success'),
            'url'   => '',
        ];
        return $breadcrumb;
    }
}
