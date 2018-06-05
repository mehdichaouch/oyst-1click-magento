<?php
/**
 * This file is part of Oyst_OneClick for Magento.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @author Oyst <plugin@oyst.com> <@oyst>
 * @category Oyst
 * @package Oyst_OneClick
 * @copyright Copyright (c) 2017 Oyst (http://www.oyst.com)
 */

use Oyst\Classes\OneClickItem;
use Oyst\Classes\OneClickMerchantDiscount;
use Oyst\Classes\OneClickOrderCartEstimate;
use Oyst\Classes\OneClickShipmentCatalogLess;
use Oyst\Classes\OneClickStock;
use Oyst\Classes\OystCarrier;
use Oyst\Classes\OystCategory;
use Oyst\Classes\OystPrice;
use Oyst\Classes\OystProduct;

/**
 * Catalog Model
 */
class Oyst_OneClick_Model_Catalog extends Mage_Core_Model_Abstract
{
    /**
     * Supported type of product
     *
     * @var array
     */
    protected $supportedProductTypes = array(
        Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
        Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
        Mage_Catalog_Model_Product_Type::TYPE_GROUPED,
        //Mage_Catalog_Model_Product_Type::TYPE_BUNDLE,
        //Mage_Catalog_Model_Product_Type::TYPE_VIRTUAL,
        //Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE,
    );

    /**
     * Translate Product attribute for Oyst <-> Magento
     *
     * @var array
     */
    protected $productAttrTranslate = array(
        'status' => array(
            'lib_property' => 'active',
            'type' => 'bool',
        ),
    );

    /**
     * Custom attribute code
     *
     * @var array
     */
    protected $customAttributesCode = array('color', 'size');

    /**
     * Selected system attribute code
     *
     * @var array
     */
    protected $systemSelectedAttributesCode = array();

    /**
     * Variations attribute code
     *
     * @var array
     */
    protected $variationAttributesCode = array('price', 'final_price');

    /**
     * User defined attribute code
     *
     * @var array
     */
    protected $userDefinedAttributeCode = array();

    /**
     * @var array
     */
    protected $productsIds = null;

    /**
     * @var array
     */
    protected $productsQuantities = null;

    /**
     * @var array
     */
    protected $products = array();

    /**
     * @var int
     */
    protected $configurableProductChildId = null;

    /**
     * @var null|Mage_CatalogInventory_Model_Stock_Item
     */
    private $stockItem = null;

    /**
     * @var bool Used to check if it's the first api call to display the button
     */
    private $isPreload;

    /**
     * Object construct
     */
    public function __construct()
    {
        if (!$this->getConfig('enable')) {
            Mage::throwException(Mage::helper('oyst_oneclick')->__('1-Click module is not enabled.'));
        }
    }

    /**
     * Get config from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getConfig($code)
    {
        return Mage::getStoreConfig("oyst/oneclick/$code");
    }

    /**
     * Catalog process from notification controller
     *
     * @param array $event
     * @param array $apiData
     *
     * @return string
     */
    public function processNotification($event, $apiData)
    {
        // Create new notification in db with status 'start'
        /** @var Oyst_OneClick_Model_Notification $notification */
        $notification = Mage::getModel('oyst_oneclick/notification');
        $notification->setData(array(
            'event' => $event,
            'oyst_data' => Zend_Json::encode($apiData),
            'status' => Oyst_OneClick_Model_Notification::NOTIFICATION_STATUS_START,
            'created_at' => Mage::getModel('core/date')->gmtDate(),
            'executed_at' => Mage::getModel('core/date')->gmtDate(),
        ));
        $notification->save();
        Mage::helper('oyst_oneclick')->log('Start processing notification: ' . $notification->getNotificationId());

        // Do action for each event type
        switch ($event) {
            case 'order.cart.estimate':
                $response = $this->cartEstimate($apiData);
                break;

            // Reduce qty in order or cancel booking
            case 'order.stock.released':
                $response = $this->stockReleased($apiData);
                break;

            // Increase qty in order
            case 'order.stock.book':
                $response = $this->stockBook($apiData);
                break;

            default:
                $response = '';
                Mage::helper('oyst_oneclick')->log('No action defined for event ' . $event);
                break;
        }

        // Save new status and result in db
        $notification->setStatus(Oyst_OneClick_Model_Notification::NOTIFICATION_STATUS_FINISHED)
            ->setMageResponse($response)
            ->setExecutedAt(Mage::getSingleton('core/date')->gmtDate())
            ->save();
        Mage::helper('oyst_oneclick')->log('End processing notification: ' . $notification->getNotificationId());

        return $response;
    }

    /**
     * Get products.
     *
     * @param array $data Products send from ajax call
     *
     * @return Mage_Catalog_Model_Resource_Product_Collection|null
     */
    private function getProducts($data)
    {
        $childrenIds = $stockFilter = array();

        foreach ($data as $item) {
            $index = 'productId';

            if (array_key_exists('configurableProductChildId', $item) && $item['configurableProductChildId']) {
                $childrenIds[$item['configurableProductChildId']] = $item['productId'];
                $index = 'configurableProductChildId';
            }

            $this->products[$item['productId']]['quantity'] = $stockFilter[$item[$index]] = $item['quantity'];
        }

        if (!count($this->products)
            || (!$this->checkItemsQty($stockFilter) && !$this->isPreload)
        ) {
            return array();
        }

        $products = $this->getProductCollection(array_keys($this->products));

        if (count($childrenIds)) {
            $childProducts = $this->getProductCollection(array_keys($childrenIds));

            foreach ($childProducts as $childProduct) {
                $this->products[$childrenIds[$childProduct->getId()]]['childProduct'] = $childProduct;
            }
        }

        return $products;
    }

    /**
     * Get products collection.
     *
     * @param array $data Product Ids
     *
     * @return Mage_Catalog_Model_Resource_Product_Collection
     */
    private function getProductCollection($data)
    {
        $products = Mage::getResourceModel('catalog/product_collection')
            ->addFieldToFilter('entity_id', array('in' => $data))
            ->addFinalPrice()
            ->addAttributeToSelect('*');

        return $products;
    }

    /**
     * Check items quantity.
     *
     * @param array $data Product Ids
     *
     * @return bool
     */
    private function checkItemsQty($data)
    {
        $stockItems = Mage::getModel('cataloginventory/stock_item')
            ->getCollection()
            ->addFieldToFilter('product_id', array('in' => array_keys($data)))
            ->addStockFilter(Mage::getModel('cataloginventory/stock'));

        foreach ($stockItems as $stockItem) {
            $checkQuoteItemQty = $stockItem->checkQuoteItemQty($data[$stockItem->getProductId()], $stockItem->getQty());
            if ($checkQuoteItemQty->getData('has_error')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return OystProduct array
     *
     * @param array $dataFormated
     *
     * @return OystProduct[]|array Array of OystProduct or array with errors
     */
    public function getOystProducts($dataFormated)
    {
        $this->isPreload = filter_var($dataFormated['preload'], FILTER_VALIDATE_BOOLEAN);

        $products = $this->getProducts($dataFormated['products']);

        $this->userDefinedAttributeCode = $this->getUserDefinedAttributeCode();
        $this->systemSelectedAttributesCode = $this->getSystemSelectedAttributeCode();

        $productsFormated = array();
        foreach ($products as $product) {
            if (isset($this->products[$product->getId()]['childProduct']) &&
                ($childId = $this->products[$product->getId()]['childProduct']->getId())) {
                $this->configurableProductChildId = $childId;
            }

            $quantity = $this->products[$product->getId()]['quantity'];
            $productsFormated[] = $this->format(array($product), $quantity);

            // Book initial quantity
            if (!$this->isPreload && $this->getConfig('should_ask_stock') && 0 !== $quantity) {
                $productId = isset($this->configurableProductChildId) ? $this->configurableProductChildId : $product->getId();
                $this->stockItemToBook($productId, $quantity);
                Mage::helper('oyst_oneclick')->log(
                    sprintf('Book initial qty %s for productId %s', $quantity, $productId)
                );
            }

            $this->configurableProductChildId = null;
        }

        return $productsFormated;
    }

    /**
     * Transform Database Data to formatted product
     *
     * @param Mage_Catalog_Model_Product[] $products
     * @param int $qty
     *
     * @return OystProduct
     */
    protected function format($products, $qty = null)
    {
        $oystProduct = $this->addDummyOystProduct();

        foreach ($products as $product) {
            if (!$product->isConfigurable() && !is_null($this->configurableProductChildId) && $product->getId() != $this->configurableProductChildId) {
                continue;
            }

            // this price is overwritten later with taxes and others stuff
            $price = new OystPrice(1, $this->getCatalogBaseCurrencyCode());

            $qty = is_null($qty) ? 1 : $qty;

            $oystProduct = new OystProduct($product->getEntityId(), $product->getName(), $price, $qty);

            // Get product attributes
            $this->getAttributes($product, $this->productAttrTranslate, $oystProduct);

            // Add others attributes
            // Don't get price from child product
            if (in_array($product->getId(), array_keys($this->products))) {
                $this->addAmount($product, $oystProduct);
            }

            $this->addComplexAttributes($product, $oystProduct);
            $this->addImages($product, $oystProduct);
            $this->addCustomAttributesToInformations($product, $oystProduct);

            if ($product->isConfigurable()) {
                $this->addVariations($product, $oystProduct);
            }

            $this->addProductCustomOptionsToInformations($oystProduct);

            // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
            // in release stock event
            if ($product->isConfigurable()) {
                $oystProduct->__set('reference', $product->getId() . ';' . $this->configurableProductChildId);
                $oystProduct->__set('variation_reference', $this->configurableProductChildId);
            }
        }

        return $oystProduct;
    }

    /**
     * Return the user defined attributes code
     *
     * @return array
     */
    protected function getUserDefinedAttributeCode()
    {
        /** @var Mage_Eav_Model_Entity_Type $type */
        $type = Mage::getModel('eav/entity_type');
        $type->loadByCode('catalog_product');

        /** @var $attrs Mage_Eav_Model_Resource_Entity_Attribute_Collection */
        $attrs = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($type)
            ->addFieldToFilter('is_user_defined', true)
            ->addFieldToFilter('frontend_input', 'select');

        $userDefinedAttributeCode = array();
        /** @var $attribute Mage_Eav_Model_Entity_Attribute */
        foreach ($attrs as $attribute) {
            $userDefinedAttributeCode[] = $attribute->getAttributeCode();
        }

        return $userDefinedAttributeCode;
    }

    /**
     * Return the selected system attributes code
     *
     * @return array
     */
    protected function getSystemSelectedAttributeCode()
    {
        $code = 'systemattributes';
        $attrsIds = explode(',', Mage::getStoreConfig("oyst/oneclick/$code"));

        $systemSelectedAttributeCode = array();
        foreach ($attrsIds as $attributeId) {
            $attribute = Mage::getResourceModel('eav/entity_attribute_collection')
                ->addFieldToFilter('attribute_id', $attributeId)
                ->addFieldToSelect('attribute_code')
                // @codingStandardsIgnoreLine
                ->getFirstItem()
            ;

            $systemSelectedAttributeCode[] = $attribute->getAttributeCode();
        }

        return $systemSelectedAttributeCode;
    }

    /**
     * Return the product attributes code defined by user
     *
     * @param Mage_Catalog_Model_Product $product
     * @param $userDefinedAttributeCode
     *
     * @return array
     */
    protected function getProductAttributeCodeDefinedByUser(Mage_Catalog_Model_Product $product, $userDefinedAttributeCode)
    {
        $attributes = $product->getAttributes();
        $productAttributeCode = array();
        foreach ($attributes as $attribute) {
            $productAttributeCode[] = $attribute->getAttributeCode();
        }

        $attributeCodes = array_unique(
            array_merge(
                array_intersect($userDefinedAttributeCode, $productAttributeCode),
                $this->customAttributesCode,
                $this->systemSelectedAttributesCode
            )
        );

        return $attributeCodes;
    }

    /**
     * Get product attributes
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $translateAttribute
     * @param OystProduct $oystProduct
     */
    protected function getAttributes(Mage_Catalog_Model_Product $product, array $translateAttribute, OystProduct &$oystProduct)
    {
        foreach ($translateAttribute as $attributeCode => $simpleAttribute) {
            if ($data = $product->getData($attributeCode)) {
                if ($simpleAttribute['type'] == 'jsonb') {
                    $data = Zend_Json::encode(array(
                        'meta' => $data,
                    ));
                } else {
                    settype($data, $simpleAttribute['type']);
                }

                if ($data !== null) {
                    $oystProduct->__set($simpleAttribute['lib_property'], ($data));
                }
            } elseif (array_key_exists('required', $simpleAttribute) && $simpleAttribute['required'] == true) {
                if ('jsonb' == $simpleAttribute['type']) {
                    $data = '{}';
                } else {
                    $data = 'Empty';
                    settype($data, $simpleAttribute['type']);
                }
            }
        }
    }

    /**
     * Add variations attributes to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     *
     * @return array
     */
    protected function addVariations(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        if (!$this->isPreload && isset($this->products[$product->getId()]['childProduct'])) {
            $variationProductsFormated = $this->format(array($this->products[$product->getId()]['childProduct']));
            if (property_exists($variationProductsFormated, 'informations')) {
                $oystProduct->__set('informations', $variationProductsFormated->__get('informations'));
            }
        }
    }

    /**
     * Add price to oyst product.
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addAmount(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $data = array();

        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::registry('oyst-quote');

        if (is_null($quote)) {
            /** @var Mage_Checkout_Model_Session $oystRelatedQuoteId */
            $oystRelatedQuoteId = Mage::getSingleton('checkout/session')->getOystRelatedQuoteId();

            $quote = Mage::getModel('sales/quote')->load($oystRelatedQuoteId);
        }

        /** @var Mage_Sales_Model_Quote_Item $quoteItem */
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            if ($product->getId() == $quoteItem->getProductId()) {
                /** @var Mage_Checkout_Helper_Data $checkout */
                $checkout = Mage::helper('checkout');

                $data['price-including-tax'] = round($checkout->getPriceInclTax($quoteItem), 2);
                break;
            }
        }

        $oystPriceIncludingTaxes = new OystPrice($data['price-including-tax'], $this->getCatalogBaseCurrencyCode());
        $oystProduct->__set('amountIncludingTax', $oystPriceIncludingTaxes);
    }

    /**
     * Add complex attribute to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addComplexAttributes(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $oystProduct->__set('url', $product->getUrlInStore(array('_ignore_category' => true)));
        $oystProduct->__set('materialized', !($product->isVirtual()) ? true : false);
    }

    /**
     * Add categories to product array
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addCategories(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        /** @var Mage_Catalog_Model_Resource_Category_Collection $categoryCollection */
        $categoryCollection = $product->getCategoryCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('url_key');

        $categories = array();

        /** @var Mage_Catalog_Model_Category $category */
        foreach ($categoryCollection as $category) {
            // Count slash to determine if it's a main category
            $isMain = substr_count($category->getPath(), '/') == 2;
            $oystCategory = new OystCategory($category->getId(), $category->getName(), $isMain);
            $categories[] = $oystCategory->toArray();
        }

        $oystProduct->__set('categories', $categories);
    }

    /**
     * Add picture link of product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addImages(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attribute = Mage::getSingleton('catalog/product')->getResource()->getAttribute('media_gallery');
        $media = Mage::getResourceSingleton('catalog/product_attribute_backend_media');
        $gallery = $media->loadGallery($product, new Varien_Object(array('attribute' => $attribute)));

        $images = array();
        foreach ($gallery as $image) {
            $images[] = $product->getMediaConfig()->getMediaUrl($image['file']);
        }

        $catalogPlaceholderImage = Mage::getStoreConfig('catalog/placeholder/image_placeholder');
        if (empty($images) && !empty($catalogPlaceholderImage)) {
            $images[] = sprintf(
                '%s/placeholder/%s',
                Mage::getSingleton('catalog/product_media_config')->getBaseMediaUrl(),
                Mage::getStoreConfig('catalog/placeholder/image_placeholder')
            );
        }

        if (!empty($images)) {
            $oystProduct->__set('images', $images);
        }
    }

    /**
     * Add related product of parent product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addRelatedProducts(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        if ($relatedProducts = $product->getRelatedProductIds()) {
            $oystProduct->__set('related_products', $relatedProducts);
        }
    }

    /**
     * Add custom attributes to oyst product informations.
     *
     * @param Mage_Catalog_Model_Product $product
     * @param OystProduct $oystProduct
     */
    protected function addCustomAttributesToInformations(Mage_Catalog_Model_Product $product, OystProduct &$oystProduct)
    {
        $attributeCodes = $this->getProductAttributeCodeDefinedByUser($product, $this->userDefinedAttributeCode);

        Mage::helper('oyst_oneclick')->log('$attributeCodes');
        Mage::helper('oyst_oneclick')->log($attributeCodes);

        $informations = array();

        foreach ($attributeCodes as $attributeCode) {
            $value = '';

            if (($attribute = $product->getResource()->getAttribute($attributeCode))
                && !(null === $product->getData($attributeCode))) {
                $value = $attribute->getFrontend()->getValue($product);
            }

            if (empty($value)) {
                continue;
            }
            Mage::helper('oyst_oneclick')->log('$attributeCode: ' . $attributeCode . '  -  value: ' . $value);

            $informations[$attributeCode] = $value;
        }
        $oystProduct->__set('informations', $informations);
    }

    /**
     * Add product custom options to oyst product informations.
     *
     * @param OystProduct $oystProduct
     */
    protected function addProductCustomOptionsToInformations(OystProduct &$oystProduct)
    {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::registry('oyst-quote');

        if (is_null($quote)) {
            /** @var Mage_Checkout_Model_Session $oystRelatedQuoteId */
            $oystRelatedQuoteId = Mage::getSingleton('checkout/session')->getOystRelatedQuoteId();

            $quote = Mage::getModel('sales/quote')->load($oystRelatedQuoteId);
        }

        $productOptions = array();

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            /* @var $helper Mage_Catalog_Helper_Product_Configuration */
            $helper = Mage::helper('catalog/product_configuration');
            // @codingStandardsIgnoreLine
            $options = $helper->getConfigurableOptions($quoteItem);

            foreach ($options as $option) {
                $productOptions[$option['label']] = $option['value'];
            }
        }

        $oystProduct->__set('informations', array_merge($oystProduct->__get('informations'), $productOptions));
    }

    /**
     * Check if product is supported
     *
     * @param Mage_Catalog_Model_Product $product
     *
     * @return bool
     */
    public function isSupportedProduct($product)
    {
        $supported = false;

        if (in_array($product->getTypeId(), $this->supportedProductTypes)) {
            $supported = true;
        }

        $transport = new Varien_Object();
        $transport->setIsSupported($supported);
        Mage::dispatchEvent('oyst_oneclick_model_catalog_is_supported_product', array(
            'product' => $product, 'transport' => $transport
        ));
        $supported = $transport->getIsSupported();

        return $supported;
    }

    /**
     * Get cart estimate.
     *
     * @param array $apiData
     *
     * @return string
     */
    public function cartEstimate($apiData)
    {
        /** @var Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder */
        $magentoQuoteBuilder = Mage::getModel('oyst_oneclick/magento_quote', $apiData);

        $magentoQuoteBuilder->syncQuoteFacade();

        // Object to format data of EndpointShipment
        $oneClickOrderCartEstimate = new OneClickOrderCartEstimate();

        $this->getCartRules($magentoQuoteBuilder, $oneClickOrderCartEstimate);

        $this->validateCoupon($apiData, $magentoQuoteBuilder, $oneClickOrderCartEstimate);

        $this->getShipments($apiData, $magentoQuoteBuilder, $oneClickOrderCartEstimate);

        $this->getCartAmount($apiData, $magentoQuoteBuilder, $oneClickOrderCartEstimate);

        return $oneClickOrderCartEstimate->toJson();
    }

    /**
     * Validate coupon.
     *
     * @param array $apiData
     * @param Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder
     * @param OneClickOrderCartEstimate $oneClickOrderCartEstimate
     */
    private function validateCoupon($apiData, &$magentoQuoteBuilder, &$oneClickOrderCartEstimate)
    {
        if (!isset($apiData['discount_coupon'])) {
            return;
        }

        $coupons = explode(',', $magentoQuoteBuilder->getQuote()->getCouponCode());
        if (in_array($apiData['discount_coupon'], $coupons)) {
            return;
        }

        $errorMessage = Mage::helper('sales')->__('Coupon code %s is not valid.', strip_tags($apiData['discount_coupon']));

        $oneClickOrderCartEstimate->setDiscountCouponError($errorMessage);
    }

    /**
     * Get shipments.
     *
     * @param array $apiData
     * @param Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder
     * @param OneClickOrderCartEstimate $oneClickOrderCartEstimate
     */
    private function getShipments($apiData, &$magentoQuoteBuilder, &$oneClickOrderCartEstimate)
    {
        /** @var Mage_Core_Model_Store $storeId */
        $storeId = Mage::getModel('core/store')->load($apiData['order']['context']['store_id']);

        /** @var Mage_Sales_Model_Quote_Address $address */
        $address = $magentoQuoteBuilder->getQuote()->getShippingAddress();

        $rates = $address
            ->collectShippingRates()
            ->getAllShippingRates();
        $isPrimarySet = false;

        /** @var Mage_Tax_Helper_Data $coreHelper */
        $taxHelper = Mage::helper('tax');

        /** @var Mage_Core_Helper_Data $coreHelper */
        $coreHelper = Mage::helper('core');

        $ignoredShipments = Mage::helper('oyst_oneclick/shipments')->getIgnoredShipments();
        foreach ($rates as $rate) {
            $rateData = $rate->toArray();
            try {
                if (in_array($rateData['code'], $ignoredShipments)) {
                    continue;
                }

                $price = $coreHelper->currency($rateData['price'], false, false);
                if (!$taxHelper->shippingPriceIncludesTax()) {
                    $price = $taxHelper->getShippingPrice($price, true, $address);
                }

                $rateCode = $rateData['code'];
                $mappingName = $this->getConfigMappingName($rateCode, $storeId);

                // For Webshopapps Matrix rates module
                if (strpos($rateData['code'], 'matrixrate_matrixrate') !== false) {
                    $rateCode = 'matrixrate_matrixrate';
                    $mappingName = $rateData['method_title'];
                }

                Mage::helper('oyst_oneclick')->log(
                    sprintf(
                        '%s (%s): %s',
                        trim($mappingName),
                        $rateData['code'],
                        $price
                    )
                );
                $carrierMapping = $this->getConfigMappingDelay($rateCode, $storeId);

                // This mean it's disable for 1-Click
                if ("0" === $carrierMapping || is_null($carrierMapping)) {
                    continue;
                }

                $oystPrice = new OystPrice($price, Mage::app()->getStore()->getCurrentCurrencyCode());

                $oystCarrier = new OystCarrier(
                    $rateData['code'],
                    trim($mappingName),
                    $carrierMapping
                );

                $shipment = new OneClickShipmentCatalogLess(
                    $oystPrice,
                    $this->getConfigCarrierDelay($rateCode, $storeId),
                    $oystCarrier
                );

                if ($rateCode === $this->getConfig('carrier_default')) {
                    $shipment->setPrimary(true);
                    $isPrimarySet = true;
                }

                $oneClickOrderCartEstimate->addShipment($shipment);
            } catch (Exception $e) {
                Mage::logException($e);
                continue;
            }
        }

        if (!$isPrimarySet) {
            $oneClickOrderCartEstimate->setDefaultPrimaryShipmentByType();
        }
    }

    /**
     * Get cart rules.
     *
     * @param Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder
     * @param OneClickOrderCartEstimate $oneClickOrderCartEstimate
     */
    private function getCartRules(&$magentoQuoteBuilder, &$oneClickOrderCartEstimate)
    {
        $discountRules = array();

        if (is_null($quoteAppliedRuleIds = $magentoQuoteBuilder->getQuote()->getAppliedRuleIds())) {
            return;
        }

        if (!is_null($couponCode = $magentoQuoteBuilder->getQuote()->getCouponCode())) {
            Mage::helper('oyst_oneclick')->log('CouponCode: ' . $couponCode);
        }

        /** @var Mage_Sales_Model_Resource_Quote_Item_Collection $quoteItems */
        $quoteItems = Mage::getResourceModel('sales/quote_item_collection')
            ->setQuote($magentoQuoteBuilder->getQuote())
            ->addFieldToFilter('parent_item_id', array('null' => true))
        ;

        /** @var Mage_Sales_Model_Quote_Item $quoteItem */
        foreach ($quoteItems->getData() as $quoteItemData) {
            // Manage gift product
            if (0 == $quoteItemPrice = $quoteItemData['price']) {
                $freeItem = new OneClickItem(
                    $quoteItemData['product_id'],
                    new OystPrice($quoteItemPrice, $magentoQuoteBuilder->getQuote()->getBaseCurrencyCode()),
                    $quoteItemData['qty']
                );
                $freeItem->__set('title', $quoteItemData['name']);

                $oneClickOrderCartEstimate->addFreeItems($freeItem);

                continue;
            }
        }

        // Handle Classic discount rules
        foreach ($magentoQuoteBuilder->getQuote()->getTotals() as $total) {
            if ($total->getCode() != 'discount') {
                continue;
            }

            if ($total->getFullInfo()) {
                /** @var Mage_SalesRule_Model_Resource_Rule_Collection $salesRuleCollection */
                $salesRuleCollection = Mage::getModel('salesrule/rule')->getCollection();
                $salesRules = $salesRuleCollection
                    ->addFieldToFilter('rule_id', array('in' => array_keys($total->getFullInfo())))
                    ->addFieldToFilter('simple_action', array('nin' => $this->getForbiddenSalesRulesActions()))
                    ->setOrder('sort_order', $salesRuleCollection::SORT_ORDER_ASC);

                foreach ($total->getFullInfo() as $salesRuleId => $discountInfo) {
                    if(!in_array($salesRuleId, explode(',', $quoteAppliedRuleIds))) {
                        continue;
                    }

                    $salesRule = $salesRules->getItemById($salesRuleId);
                    Mage::helper('oyst_oneclick')->log('RuleId: ' . $salesRule->getRuleId() . ' - Type: ' . $salesRule->getSimpleAction());

                    $discountRules[] = array(
                        'name' => $salesRule->getName(),
                        'amount' => $discountInfo['amount']
                    );
                }
            } else {
                $discountRules[] = array(
                    'name' => $total->getTitle(),
                    'amount' => abs($total->getValue())
                );
            }
        }

        foreach ($discountRules as $discountRule) {
            $merchantDiscount = new OneClickMerchantDiscount(
                new OystPrice($discountRule['amount'], $magentoQuoteBuilder->getQuote()->getBaseCurrencyCode()),
                $discountRule['name']
            );

            $oneClickOrderCartEstimate->addMerchantDiscount($merchantDiscount);

            Mage::helper('oyst_oneclick')->log(Zend_Json::encode($merchantDiscount->toArray()));
        }
    }

    /**
     * Get cart amount.
     *
     * @param array $apiData
     * @param Oyst_OneClick_Model_Magento_Quote $magentoQuoteBuilder
     * @param OneClickOrderCartEstimate $oneClickOrderCartEstimate
     */
    private function getCartAmount($apiData, &$magentoQuoteBuilder, &$oneClickOrderCartEstimate)
    {
        // Get order amount
        $totals = $magentoQuoteBuilder->getQuote()->getTotals();
        $grandTotal = $magentoQuoteBuilder->getQuote()->getGrandTotal();

        if (!isset($totals['shipping'])) {
            $shippingAmount = 0;

            $cartEstimate = $oneClickOrderCartEstimate->toArray();
            foreach ($cartEstimate['shipments'] as $shipment) {
                if (is_null($apiData['order']['shipment']) && $shipment['primary']) {
                    $shippingAmount = Mage::helper('oyst_oneclick')->getHumanAmount($shipment['amount']['value']);
                    break;
                }

                // shipments changed in modal
                if (!is_null($apiData['order']['shipment']) &&
                    $apiData['order']['shipment']['id'] == $shipment['carrier']['id']
                ) {
                    $shippingAmount = Mage::helper('oyst_oneclick')->getHumanAmount($shipment['amount']['value']);
                    break;
                }
            }

            Mage::helper('oyst_oneclick')->log('$shippingAmount: ' . $shippingAmount);

            $grandTotal = $totals['grand_total']->getValue() + $shippingAmount;
        }

        Mage::helper('oyst_oneclick')->log('$grandTotal: ' . $grandTotal);
        $oneClickOrderCartEstimate->setCartAmount(new OystPrice($grandTotal, $magentoQuoteBuilder->getQuote()->getBaseCurrencyCode()));
    }

    /**
     * Get config for carrier delay from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getConfigCarrierDelay($code, $storeId)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_delay/$code", $storeId);
    }

    /**
     * Get config for carrier mapping from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getConfigMappingDelay($code, $storeId)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_mapping/$code", $storeId);
    }

    /**
     * Get config for carrier name from Magento
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getConfigMappingName($code, $storeId)
    {
        return Mage::getStoreConfig("oyst_oneclick/carrier_name/$code", $storeId);
    }

    /**
     *
     *
     * @return mixed
     */
    private function stockReleased($apiData)
    {
        try {
            if (!isset($apiData['products'])) {
                throw new \InvalidArgumentException(Mage::helper('oyst_oneclick')->__('Products info is missing'));
            }

            foreach ($apiData['products'] as $product) {
                $qty = isset($product['quantity']) ? $product['quantity'] : 1;

                if (0 === $qty) {
                    continue;
                }

                $productId = $product['reference'];

                // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                // in release stock event
                if (false !== strpos($productId, ';')) {
                    $p = explode(';', $productId);
                    $product['reference'] = $p[0];
                    $product['variation_reference'] = $p[1];
                }

                if (isset($product['variation_reference'])) {
                    $productId = $product['variation_reference'];
                }

                /** @var Mage_CatalogInventory_Model_Stock_Item stockItem */
                $this->stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);

                if (!$this->stockItem->getId()) {
                    $this->stockItem->setProductId($productId);
                    $this->stockItem->setStockId(Mage::getModel('cataloginventory/stock')->getId());
                }

                if ($this->stockItem->getManageStock()) {
                    $this->stockItem->setQty($this->stockItem->getQty() + $qty);
                    $this->stockItem->setIsInStock((int)($qty > 0)); // Set the Product to InStock
                    // @codingStandardsIgnoreLine
                    $this->stockItem->save();
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Book stock item(s)
     *
     * @return string
     */
    private function stockBook($apiData)
    {
        try {
            if (isset($apiData['items'])) {
                foreach ($apiData['items'] as $item) {
                    $qty = $item['quantity'];
                    $productId = $item['reference'];

                    // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                    // in release stock event
                    if (false  !== strpos($productId, ';')) {
                        $p = explode(';', $productId);
                        $productId['reference'] = $p[0];
                        $item['variation_reference'] = $p[1];
                    }

                    if (isset($item['variation_reference'])) {
                        $productId = $item['variation_reference'];
                    }

                    $this->stockItemToBook($productId, $qty);
                }
            } else {
                $qty = $apiData['quantity'];

                $productId = $apiData['product_reference'];

                // @TODO Temporary code, waiting to allow any kind of field in product e.g. variation_reference
                // in release stock event
                if (false  !== strpos($productId, ';')) {
                    $p = explode(';', $productId);
                    $productId = $p[0];
                    $apiData['variation_reference'] = $p[1];
                }

                if (isset($apiData['variation_reference'])) {
                    $productId = $apiData['variation_reference'];
                }

                $stockItemToBook = $this->stockItemToBook($productId, $qty);

                /** @var OneClickStock $stockBookResponse */
                $stockBookResponse = new OneClickStock($stockItemToBook, $apiData['product_reference']);

                return Zend_Json::encode($stockBookResponse->toArray());
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Book a stock unit
     *
     * @return string
     */
    public function stockItemToBook($productId, $qty)
    {
        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);

        /** @var Mage_CatalogInventory_Model_Stock_Item stockItem */
        $this->stockItem = $product->getStockItem();
        $stockItemToBook = $this->stockItem->getQty() >= $qty ? $qty : 0;

        if ($stockItemToBook) {
            $this->stockItem->setData('qty', $this->stockItem->getQty() - $stockItemToBook);
            $this->stockItem->save();
        }

        return $stockItemToBook;
    }

    /**
     * This method is required because the API service authorize Order which is called on preload
     * does not accept an empty cart, so we have to artificially force a dummy product
     * @return OystProduct
     */
    public function addDummyOystProduct()
    {
        $price = new OystPrice(1, $this->getCatalogBaseCurrencyCode());
        return new OystProduct(1, 'Dummy Product', $price, 1);
    }

    public function getCatalogBaseCurrencyCode($storeId = null)
    {
        return Mage::app()->getStore($storeId)->getBaseCurrencyCode();
    }

    protected function getForbiddenSalesRulesActions()
    {
        $transport = new Varien_Object();
        $transport->setForbiddenSalesRulesActions(array('add_gift'));
        Mage::dispatchEvent('oyst_oneclick_model_catalog_get_forbidden_sales_rules_actions', array(
            'transport' => $transport
        ));
        return $transport->getForbiddenSalesRulesActions();
    }
}
