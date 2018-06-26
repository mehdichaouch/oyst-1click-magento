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

/**
 * Quote Model
 */
class Oyst_OneClick_Model_Magento_Quote
{
    protected $paymentMethod = Oyst_OneClick_Model_Payment_Method_Oneclick::PAYMENT_METHOD_NAME;

    /** @var Mage_Sales_Model_Quote */
    private $quote = null;

    /** @var string[] API response */
    private $apiData = null;

    /** @var int Website id */
    private $websiteId = null;

    public function __construct($orderResponse)
    {
        $this->apiData = $orderResponse;
    }

    /**
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->quote;
    }

    /**
     * Builds the quote object (from Oyst order), which then can be converted to magento order
     *
     * @return array
     */
    public function syncQuoteFacade()
    {
        try {
            // do not change invoke order
            // ---------------------------------------
            $quoteId = $this->apiData['order']['context']['quote_id'];
            $storeId = $this->apiData['order']['context']['store_id'];

            $this->syncQuote($quoteId, $storeId);
            $this->syncQuoteItems();
            $this->syncCustomer();
            $this->syncAddresses();
            $this->syncCoupons();
            $this->syncShippingMethod();
            $this->syncPaymentMethodData();

            Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_quote_facade', array('api_data' => $this->apiData, 'quote' => $this->quote));

            $this->quote->getShippingAddress()->setCollectShippingRates(true);
            $this->quote->setAppliedRuleIds(null)->setTotalsCollectedFlag(false)->collectTotals()->save();
        } catch (Exception $e) {
            Mage::helper('oyst_oneclick')->log('Error build quote: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param int $storeId
     */
    private function syncQuote($quoteId, $storeId = null)
    {
        $this->quote = Mage::getModel('sales/quote')->load($quoteId);

        $this->quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);

        $store = Mage::app()->getStore($storeId);

        $this->quote->setStore($store);
        $this->quote->getStore()->setCurrentCurrencyCode($this->apiData['order']['order_amount']['currency']);
        $this->quote->setRemoteIp($this->apiData['order']['context']['remote_addr']);

        $this->quote->setIsMultiShipping(false);
        $this->quote->setIsSuperMode(true);
        $this->quote->setCreatedAt($this->apiData['order']['created_at']);
        $this->quote->setUpdatedAt($this->apiData['order']['created_at']);
        $this->quote->setOystOrderId($this->apiData['order']['id']);

        Mage::getSingleton('checkout/cart')->setQuote($this->quote);

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_quote_after', array('quote' => $this->quote, 'request' => $this->apiData));
    }

    /**
     * Create new customer.
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     *
     * @return false|Mage_Core_Model_Abstract
     */
    private function createCustomer($firstname, $lastname, $email)
    {
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');
        $store = Mage::app()->getStore();

        try {
            $customer->setWebsiteId($this->websiteId)
                ->setStore($store)
                ->setFirstname($firstname)
                ->setLastname($lastname)
                ->setEmail($email);
            $customer->save();

            // Send welcome email
            $customer->sendNewAccountEmail('registered', '', $store->getId(), $customer->generatePassword(16));

            /** @var Mage_Customer_Model_Address $address */
            $address = Mage::getModel('customer/address');

            $address->setCustomerId($customer->getId())
                ->setFirstname($customer->getFirstname())
                ->setLastname($customer->getLastname())
                ->setCountryId($this->apiData['order']['user']['address']['country'])
                ->setPostcode($this->apiData['order']['user']['address']['postcode'])
                ->setCity($this->apiData['order']['user']['address']['city'])
                ->setTelephone($this->apiData['order']['user']['phone'])
                ->setStreet($this->apiData['order']['user']['address']['postcode'])
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
                ->setSaveInAddressBook(true);
            $address->save();
        } catch (Exception $e) {
            Mage::helper('oyst_oneclick')->log($e->getMessage());
        }

        return $customer;
    }

    private function syncCustomer()
    {
        // Already customer ; Check by website
        if ($customer = $this->getCustomer()) {
            if ($customer instanceof Mage_Customer_Model_Customer) {
                $this->quote->setCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER);
                $this->quote->setCustomer($customer);
            }
        }

        // New guest
        if (!$customer) {
            $firstname = !empty($this->apiData['order']['user']['first_name']) ?
                $this->apiData['order']['user']['first_name'] :
                '';
            $lastname = !empty($this->apiData['order']['user']['last_name']) ?
                $this->apiData['order']['user']['last_name'] :
                '';
            $email = !empty($this->apiData['order']['user']['email']) ?
                $this->apiData['order']['user']['email'] :
                Mage::getStoreConfig('trans_email/ident_general/email');
            $this->quote->setCustomerFirstname($firstname);
            $this->quote->setCustomerLastname($lastname);
            $this->quote->setCustomerEmail($email);

            $checkoutMethod = Mage_Checkout_Model_Type_Onepage::METHOD_GUEST;

            if (Mage::getStoreConfig('oyst/oneclick/new_customer_account')) {
                $customer = $this->createCustomer($firstname, $lastname, $email);
                $checkoutMethod = Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER;
            }

            if ($customer instanceof Mage_Customer_Model_Customer && !$customer->getId()) {
                $this->quote->setCustomerIsGuest(true);
                $this->quote->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
            }

            $this->quote->setCheckoutMethod($checkoutMethod);
        }

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_customer_after', array('quote' => $this->quote, 'request' => $this->apiData));
    }

    /**
     * Retrieve customer by email.
     *
     * @return bool|Mage_Customer_Model_Customer
     */
    protected function getCustomer()
    {
        $this->websiteId = Mage::getModel('core/store')
            ->load($this->apiData['order']['context']['store_id'])
            ->getWebsiteId();

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');
        $customer->setWebsiteId($this->websiteId);
        $customer->loadByEmail($this->apiData['order']['user']['email']);

        if ($customer->getId()) {
            return $customer;
        }

        return false;
    }

    /**
     * Consider all address only from API same as New guest
     */
    private function syncAddresses()
    {
        /** @var Mage_Sales_Model_Quote_Address $billingAddress */
        $billingAddress = $this->quote->getBillingAddress();
        $billingInfoFormated = $this->getAddressData($billingAddress);
        $billingAddress->addData($billingInfoFormated);
        $billingAddress->implodeStreetAddress();
        $billingAddress->isObjectNew(false);

        $billingAddress->setSaveInAddressBook(false);
        $billingAddress->setShouldIgnoreValidation(true);

        /** @var Mage_Sales_Model_Quote_Address $shippingAddress */
        $shippingAddress = $this->quote->getShippingAddress();
        $shippingInfoFormated = $this->getAddressData($shippingAddress);
        $shippingAddress->setSameAsBilling(0); // maybe just set same as billing?
        $shippingAddress->addData($shippingInfoFormated);
        $shippingAddress->implodeStreetAddress();
        $shippingAddress->isObjectNew(false);

        $shippingAddress->setSaveInAddressBook(false);
        $shippingAddress->setShouldIgnoreValidation(true);

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_addresses_after', array('quote' => $this->quote, 'request' => $this->apiData));
    }

    private function syncShippingMethod()
    {
        $shippingAddress = $this->quote->getShippingAddress();

        $storeId = $this->apiData['order']['context']['store_id'];

        $shippingMethod = Mage::getStoreConfig('oyst/oneclick/carrier_default', $storeId);
        $shippingDescription = Mage::getStoreConfig('oyst_oneclick/carrier_name/' . $shippingMethod, $storeId);

        if (isset($this->apiData['order']['shipment']) &&
            isset($this->apiData['order']['shipment']['carrier']) &&
            isset($this->apiData['order']['shipment']['carrier']['id']) &&
            'SHIPMENT-404' != $this->apiData['order']['shipment']['id']
        ) {
            $shippingMethod = $this->apiData['order']['shipment']['carrier']['id'];
            $shippingDescription = $this->apiData['order']['shipment']['carrier']['name'];
        }

        $shippingAddress->setCollectShippingRates(true);

        $rates = $shippingAddress
            ->collectShippingRates()
            ->getAllShippingRates();

        $tmpCheapestPrice = null;
        $realShippingMethod = null;
        foreach ($rates as $rate) {
            $rateData = $rate->toArray();
            // MEANS THAT SHIPPING METHOD PROVIDED BY OYST REALLY EXISTS
            if ($rateData['code'] == $shippingMethod) {
                $realShippingMethod = $rateData['code'];
                break;
            }

            // MEANS THAT SHIPPING METHOD PROVIDED BY OYST EXISTS BUT CODE HAS TO BE FOUND
            if (strpos($rateData['code'], $shippingMethod) !== false) {
                if (!isset($tmpCheapestPrice)) {
                    $tmpCheapestPrice = $rateData['price'];
                }

                if ($rateData['price'] <= $tmpCheapestPrice) {
                    $realShippingMethod = $rateData['code'];
                }
            }
        }

        $shippingAddress->setShippingMethod($realShippingMethod);
        $shippingAddress->setShippingDescription($shippingDescription);

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_shipping_method_after', array('quote' => $this->quote, 'request' => $this->apiData));
    }

    /**
     * Transform Magento address to formatted array
     *
     * @param $address Mage_Sales_Model_Quote_Address
     *
     * @return array
     */
    protected function getAddressData(Mage_Sales_Model_Quote_Address $address)
    {
        $customerAddress = $this->apiData['order']['user']['address'];

        $country = Mage::getModel('directory/country')->loadByCode($customerAddress['country']);

        $street = isset($customerAddress['street']) ? $customerAddress['street'] : '';
        $street .= isset($customerAddress['complementary']) ? ' ' . $customerAddress['complementary'] : '';

        $formattedAddress = array(
            'email' => $this->apiData['order']['user']['email'],
            'firstname' => isset($customerAddress['first_name']) ? $customerAddress['first_name'] : '',
            'lastname' => isset($customerAddress['last_name']) ? $customerAddress['last_name'] : '',
            'telephone' => $this->apiData['order']['user']['phone'],
            'street' => $street,
            'postcode' => isset($customerAddress['postcode']) ? $customerAddress['postcode'] : '',
            'city' => isset($customerAddress['city']) ? $customerAddress['city'] : '',
            'region' => isset($customerAddress['city']) ? $customerAddress['city'] : '',
            'region_id' =>  null,
            'country_id' => $country->getIso2Code(),
            'company' => isset($customerAddress['company_name']) ? $customerAddress['company_name'] : '',
            'name' => (isset($customerAddress['label']) && 'N/A' != $customerAddress['label']) ? $customerAddress['label'] : '',
            'save_in_address_book' => 0,
        );

        if ('shipping' == $address->getAddressType() &&
            isset($this->apiData['order']['shipment']['pickup_store']) &&
            isset($this->apiData['order']['shipment']['pickup_store']['address'])
        ) {
            $pickupStoreAddress = $this->apiData['order']['shipment']['pickup_store']['address'];
            $formattedAddress['city'] = $pickupStoreAddress['city'];
            $formattedAddress['company'] = $pickupStoreAddress['name'] . ' / ' . $formattedAddress['company'];
            $formattedAddress['street'] = $pickupStoreAddress['name'] . ' - ' . $pickupStoreAddress['street'];
            $formattedAddress['postcode'] = $pickupStoreAddress['postal_code'];
        }

        return $formattedAddress;
    }

    private function syncCoupons()
    {
        if (isset($this->apiData['order']['context']['applied_coupons'])
            || isset($this->apiData['order']['applied_coupons'])
        ) {
            // Coupon applied from checkout
            if (isset($this->apiData['order']['context']['applied_coupons'])) {
                $this->quote->setCouponCode($this->apiData['order']['context']['applied_coupons']);
            }

            // Coupon applied from modal
            if (isset($this->apiData['discount_coupon'])) {
                $this->quote->coupon;
                $this->quote->setCouponCode($this->apiData['discount_coupon']);
            }

            // Coupon on order creation
            if (isset($this->apiData['order']['applied_coupons'])) {
                $appliedCoupons = $this->apiData['order']['applied_coupons'];
                foreach ($appliedCoupons as $appliedCoupon) {
                    if ($appliedCoupon['value'] == $this->apiData['order']['context']['applied_coupons']) {
                        continue;
                    }
                    $this->quote->setCouponCode($appliedCoupon['value']);
                    break;
                }
            }
        }

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_coupons_after', array('quote' => $this->quote, 'request' => $this->apiData));
    }

    private function syncQuoteItems()
    {
        $items = $this->apiData['order']['items'];
        $productReferences = array();

        foreach ($items as $item) {
            foreach (explode(';', $item['product']['reference']) as $productReference) {
                $productReferences[] = array('ref' => $productReference, 'qty' => $item['quantity']);
            }
        }

        $cartData = array();

        foreach ($this->quote->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }

            foreach ($productReferences as $productReference) {
                if ($item->getProductId() == $productReference['ref']) {
                    $cartData[$item->getId()]['qty'] = $productReference['qty'];
                    break;
                }
            }

            if (!isset($cartData[$item->getId()]['qty'])) {
                $cartData[$item->getId()]['qty'] = 0;
            }
        }

        $cart = Mage::getSingleton('checkout/cart');
        $cartData = $cart->suggestItemsQty($cartData);
        $cart->updateItems($cartData)->save();

        return $this;
    }

    private function syncPaymentMethodData()
    {
        /** @var Oyst_OneClick_Model_Payment_Method_Oneclick $paymentMethod */
        $paymentMethod = Mage::getModel('oyst_oneclick/payment_method_oneclick');

        /** @var Mage_Sales_Model_Quote_Payment $payment */
        $payment = $this->quote->getPayment();

        $payment
            ->importData(array('method' => $paymentMethod->getCode()))
            ->setCcLast4(substr($this->apiData['order']['user']['card']['preview'], -4));

        Mage::dispatchEvent('oyst_oneclick_model_magento_quote_sync_payment_method_after', array('quote' => $this->quote, 'request' => $this->apiData));

        $payment
            ->save();
    }
}
