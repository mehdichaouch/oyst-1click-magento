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

use Oyst\Api\OystApiClientFactory;
use Oyst\Api\OystOneClickApi;
use Oyst\Classes\Enum\AbstractOrderState;
use Oyst\Classes\OneClickCustomization;
use Oyst\Classes\OneClickNotifications;
use Oyst\Classes\OneClickOrderParams;

/**
 * OneClick ApiWrapper Model
 */
class Oyst_OneClick_Model_OneClick_ApiWrapper extends Oyst_OneClick_Model_Api
{
    // In modal timer is 5 minutes, 6 is to ensure it's over
    const CONFIG_XML_PATH_OYST_CHECKOUT_MODAL_TIMER = 'oyst/oneclick/checkout_modal_timer';

    const CONFIG_XML_PATH_OYST_PENDING_STATUS_FAILOVER_TIMER = 'oyst/oneclick/pending_status_failover_timer';

    /** @var Oyst_OneClick_Model_Api $oystClient */
    protected $oystClient;

    /** @var OystOneClickApi $oneClickApi */
    protected $oneClickApi;

    protected $type = OystApiClientFactory::ENTITY_ONECLICK;

    /* @var Mage_Sales_Model_Quote $quote */
    private $quote = null;

    public function __construct()
    {
        $this->oystClient = Mage::getModel('oyst_oneclick/api');
        $this->oneClickApi = $this->oystClient->getClient($this->type);
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
     * API send
     *
     * @param $dataFormated
     *
     * @return mixed
     *
     * @throws Mage_Core_Exception
     */
    public function authorizeOrder($dataFormated)
    {
        Mage::helper('oyst_oneclick')->log('$dataFormated');
        Mage::helper('oyst_oneclick')->log($dataFormated);

        $this->getCartItems($dataFormated);

        /** @var Oyst_OneClick_Model_Catalog $oystCatalog */
        $oystCatalog = Mage::getModel('oyst_oneclick/catalog');
        $oystProducts = $oystCatalog->getOystProducts($dataFormated);
        if (count($oystProducts) === 0) {
            $oystProducts[] = $oystCatalog->addDummyOystProduct();
        }

        if (isset($oystProducts['has_error'])) {
            return $oystProducts;
        }

        $notifications = $this->getOneClickNotifications();
        $dataFormated['preload'] = filter_var($dataFormated['preload'], FILTER_VALIDATE_BOOLEAN);

        // Book initial quantity
        if (!$dataFormated['preload'] && $this->getConfig('should_ask_stock')) {
            $notifications->addEvent('order.stock.released');
        }

        $orderParams = $this->getOneClickOrderParams($dataFormated);
        $context = $this->getContext();
        $customization = $this->getOneClickCustomization($dataFormated);

        try {
            $response = $this->oneClickApi->authorizeOrderV2(
                $oystProducts,
                $notifications,
                null,
                $orderParams,
                $context,
                $customization
            );
            $this->oystClient->validateResult($this->oneClickApi);
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $response;
    }

    /**
     * Get OneClickNotifications configuration
     *
     * @return OneClickNotifications
     */
    private function getOneClickNotifications()
    {
        $notifications = new OneClickNotifications();
        $notifications->setShouldAskShipments(true);
        $notifications->setShouldAskStock($this->getConfig('should_ask_stock'));
        $notifications->setUrl($this->getConfig('notification_url'));

        if ($notifications->isShouldAskShipments()) {
            $notifications->addEvent('order.cart.estimate');
        }

        return $notifications;
    }

    /**
     * Get OneClickOrderParams configuration.
     *
     * @param array $dataFormated
     *
     * @return OneClickOrderParams
     */
    private function getOneClickOrderParams($dataFormated)
    {
        $orderParams = new OneClickOrderParams();
        $orderParams->setManageQuantity($this->getConfig('allow_quantity_change'));

        if ($delay = Mage::getStoreConfig('oyst/oneclick/order_delay')) {
            $orderParams->setDelay($delay);
        }

        if ($reinitialize = Mage::getStoreConfig('oyst/oneclick/reinitialize_buffer')) {
            $orderParams->setShouldReinitBuffer($reinitialize);
        }

        if (isset($dataFormated['isCheckoutCart'])) {
            $orderParams->setIsCheckoutCart(true);
        }

        if ($allowDiscountCoupon = Mage::getStoreConfig('oyst/oneclick/allow_discount_coupon_from_modal')) {
            $orderParams->setAllowDiscountCoupon($allowDiscountCoupon);
        }

        return $orderParams;
    }

    /**
     * Get context configuration
     *
     * @return array
     */
    private function getContext()
    {
        $context = array(
            'id' => Mage::helper('oyst_oneclick')->generateId(),
            'remote_addr' => Mage::helper('core/http')->getRemoteAddr(),
            'store_id' => Mage::app()->getStore()->getStoreId(),
        );

        if ($this->quote instanceof Mage_Sales_Model_Quote) {
            $context['quote_id'] = $this->quote->getId();

            // This is setted here because nothing allow us to set this in order yet
            if (!is_null($this->quote->getCouponCode())) {
                $context['applied_coupons'] = $this->quote->getCouponCode();
            }
        }


        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $context['user_id'] = (string)Mage::getSingleton('customer/session')->getCustomer()->getId();
        }

        return $context;
    }

    /**
     * Get one click customization.
     *
     * @param array $dataFormated
     *
     * @return OneClickCustomization
     */
    private function getOneClickCustomization($dataFormated)
    {
        $customization = new OneClickCustomization();

        if (isset($dataFormated['isCheckoutCart'])) {
            $customization->setCta(
                Mage::getStoreConfig('oyst/oneclick/checkout_cart_cta_label', Mage::app()->getStore()->getStoreId()),
                Mage::helper('oyst_oneclick')->getRedirectUrl()
            );
        }

        return $customization;
    }

    /**
     * Get cart items
     *
     * @param array $dataFormated
     */
    public function getCartItems(&$dataFormated)
    {
        $products = array();

        /** @var Mage_Sales_Model_Quote quote */
        if (!isset($this->quote)
            || $this->quote->getId() != $dataFormated['quoteId']
        ) {
            $this->quote = Mage::getModel('sales/quote')->load($dataFormated['quoteId']);
            Mage::register('oyst-quote', $this->quote, true);
        }

        /** @var Mage_Sales_Model_Quote $items */
        $items = $this->quote->getAllVisibleItems();

        $returnItems = array();

        /** @var Mage_Sales_Model_Quote_Item $item */
        foreach ($items as $item) {
            if ($item->getHasChildren()) {
                foreach ($item->getChildren() as $child) {
                    $returnItems[] = $child;
                }
            } else {
                $returnItems[] = $item;
            }
        }

        /** @var Mage_Sales_Model_Quote_Item $item */
        foreach ($returnItems as $item) {
            if (!is_null($item->getParentItem())) {
                $products[] = array(
                    'productId' => $item->getParentItem()->getProductId(),
                    'quantity' => $item->getParentItem()->getQty(),
                    'configurableProductChildId' => $item->getProductId(),
                );
            } else {
                $products[] = array(
                    'productId' => $item->getProductId(),
                    'quantity' => $item->getQty(),
                );
            }
        }

        if (isset($dataFormated['substract_quote_items_qtys'])) {
            foreach ($dataFormated['substract_quote_items_qtys']['products'] as $memoProduct) {
                foreach ($products as $key => $product) {
                    if ($memoProduct['productId'] == $product['productId']) {
                        $products[$key]['quantity'] = $products[$key]['quantity'] - $memoProduct['quantity'];
                        if ($products[$key]['quantity'] == 0) {
                            unset($products[$key]);
                        }
                    }
                }
            }
        }

        $dataFormated['products'] = $products;
        Mage::helper('oyst_oneclick')->log($dataFormated['products']);
    }

    /**
     * Validate Oyst order status
     *
     * @param $oystOrderId
     *
     * @return bool
     */
    public function isOystOrderStatusValid($oystOrderId)
    {
        /** @var Oyst_OneClick_Model_Order_ApiWrapper $orderApi */
        $orderApi = Mage::getModel('oyst_oneClick/order_apiWrapper');

        try {
            $response = $orderApi->getOrder($oystOrderId);
        } catch (Exception $e) {
            Mage::logException($e);
        }

        // Is payment_failed status
        if (AbstractOrderState::PAYMENT_FAILED == $response['order']['current_status']) {
            return false;
        }

        // Is waiting status more than CONFIG_XML_PATH_OYST_MODAL_TIMER minutes
        if (AbstractOrderState::WAITING == $response['order']['current_status']) {
            $updatedAt = new DateTime($response['order']['updated_at']);
            $currentTime = new DateTime();

            $interval = $updatedAt->diff($currentTime);
            $modalTimer = Mage::getStoreConfig(self::CONFIG_XML_PATH_OYST_CHECKOUT_MODAL_TIMER);

            if ($modalTimer <= $interval->i) {
                return false;
            }
        }

        // Is pending status more than CONFIG_XML_PATH_OYST_PENDING_STATUS_FAILOVER_TIMER minutes
        if (AbstractOrderState::PENDING == $response['order']['current_status']) {
            $updatedAt = new DateTime($response['order']['updated_at']);
            $currentTime = new DateTime(gmdate("Y-m-d\TH:i:s\Z"));

            $interval = $updatedAt->diff($currentTime);

            $pendingStatusFailoverTimer = Mage::getStoreConfig(self::CONFIG_XML_PATH_OYST_PENDING_STATUS_FAILOVER_TIMER);

            if ($pendingStatusFailoverTimer <= $interval->i) {
                return false;
            }
        }

        return true;
    }
}
