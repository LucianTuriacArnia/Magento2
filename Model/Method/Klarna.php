<?php
// @codingStandardsIgnoreFile
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * It is available through the world-wide-web at this URL:
 * https://tldrlegal.com/license/mit-license
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to support@buckaroo.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact support@buckaroo.nl for more information.
 *
 * @copyright Copyright (c) Buckaroo B.V.
 * @license   https://tldrlegal.com/license/mit-license
 */

namespace Buckaroo\Magento2\Model\Method;

use Buckaroo\Magento2\Logging\Log;
use Magento\Catalog\Model\Product\Type;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use Magento\Quote\Model\Quote\AddressFactory;
use Buckaroo\Magento2\Service\Software\Data as SoftwareData;
use Magento\Store\Model\ScopeInterface;

class Klarna extends AbstractMethod
{
    /**
     * Payment Code
     */
    const PAYMENT_METHOD_CODE = '';

    const KLARNA_ORDER_SERVICE_ACTION = '';

    /**
     * Max articles that can be handled by klarna
     */
    const KLARNA_MAX_ARTICLE_COUNT = 99;

    /**
     * Check if the tax calculation includes tax.
     */
    const TAX_CALCULATION_INCLUDES_TAX = 'tax/calculation/price_includes_tax';
    const TAX_CALCULATION_SHIPPING_INCLUDES_TAX = 'tax/calculation/shipping_includes_tax';

    /**
     * Business methods that will be used in klarna.
     */
    const BUSINESS_METHOD_B2C = 1;
    const BUSINESS_METHOD_B2B = 2;

    const KLARNA_PAYMENT_METHOD_NAME = 'klarna';

    /**
     * @var string
     */
    public $buckarooPaymentMethodCode = 'klarna';

    // @codingStandardsIgnoreStart
    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code = self::PAYMENT_METHOD_CODE;

    /**
     * @var bool
     */
    protected $_isGateway               = true;

    /**
     * @var bool
     */
    protected $_canOrder                = true;

    /**
     * @var bool
     */
    protected $_canAuthorize            = false;

    /**
     * @var bool
     */
    protected $_canCapture              = false;

    /**
     * @var bool
     */
    protected $_canCapturePartial       = false;

    /**
     * @var bool
     */
    protected $_canRefund               = true;

    /**
     * @var bool
     */
    protected $_canVoid                 = true;

    /**
     * @var bool
     */
    protected $_canUseInternal          = true;

    /**
     * @var bool
     */
    protected $_canUseCheckout          = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;
    // @codingStandardsIgnoreEnd

    /**
     * @var null
     */
    public $remoteAddress               = null;

    /**
     * {@inheritdoc}
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);
        $data = $this->assignDataConvertToArray($data);

        if (isset($data['additional_data']['termsCondition'])) {
            $additionalData = $data['additional_data'];
            $this->getInfoInstance()->setAdditionalInformation('termsCondition', $additionalData['termsCondition']);
            $this->getInfoInstance()->setAdditionalInformation('customer_gender', $additionalData['customer_gender']);
            $this->getInfoInstance()->setAdditionalInformation('customer_billingName', $additionalData['customer_billingName']);
            $this->getInfoInstance()->setAdditionalInformation('customer_identificationNumber', $additionalData['customer_identificationNumber']);

            $dobDate = \DateTime::createFromFormat('d/m/Y', $additionalData['customer_DoB']);
            $dobDate = (!$dobDate ? $additionalData['customer_DoB'] : $dobDate->format('Y-m-d'));
            $this->getInfoInstance()->setAdditionalInformation('customer_DoB', $dobDate);

            if (isset($additionalData['customer_telephone'])) {
                $this->getInfoInstance()->setAdditionalInformation(
                    'customer_telephone',
                    $additionalData['customer_telephone']
                );
            }
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getPaymentMethodName()
    {
        return static::KLARNA_PAYMENT_METHOD_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getOrderTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('order');

        $services = [
            'Name'             => 'klarna',
            'Action'           => static::KLARNA_ORDER_SERVICE_ACTION,
            'RequestParameter' => $this->getKlarnaRequestParameters($payment),
            'Version'          => 0,
        ];

        $transactionBuilder->setOrder($payment->getOrder())
            ->setServices($services)
            ->setMethod('TransactionRequest');

        return $transactionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getVoidTransactionBuilder($payment)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getRefundTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('refund');

        $services = [
            'Name'   => $this->getPaymentMethodName(),
            'Action' => 'Refund',
        ];

        $requestParams = $this->addExtraFields($this->_code);
        $services = array_merge($services, $requestParams);

        /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
        $creditmemo = $payment->getCreditmemo();
        $articles = [];

        if (isset($services['RequestParameter'])) {
            $articles = array_merge($services['RequestParameter'], $articles);
        }

        $services['RequestParameter'] = $articles;

        /** @noinspection PhpUndefinedMethodInspection */
        $transactionBuilder->setOrder($payment->getOrder())
            ->setServices($services)
            ->setMethod('TransactionRequest')
            ->setOriginalTransactionKey(
                $payment->getAdditionalInformation(self::BUCKAROO_ORIGINAL_TRANSACTION_KEY_KEY)
            )
            ->setChannel('Web');

        if ($this->canRefundPartialPerInvoice() && $creditmemo) {
            $invoice = $creditmemo->getInvoice();

            $transactionBuilder->setInvoiceId($this->getRefundTransactionBuilderInvoceId($invoice->getOrder()->getIncrementId(), $payment))
                ->setOriginalTransactionKey($payment->getParentTransactionId());
        }

        return $transactionBuilder;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment
     *
     * @return array
     * @throws \Buckaroo\Magento2\Exception
     */
    public function getKlarnaRequestParameters($payment)
    {
        // First data to set is the billing address data.
        $requestData = $this->getRequestBillingData($payment);

        // If the shipping address is not the same as the billing it will be merged inside the data array.
        if ($this->isAddressDataDifferent($payment) || is_null($payment->getOrder()->getShippingAddress())) {
            $requestData = array_merge($requestData, $this->getRequestShippingData($payment));
        }

        $this->logger2->addDebug(__METHOD__.'|1|');
        $this->logger2->addDebug(var_export($payment->getOrder()->getShippingMethod(), true));

        if ($payment->getOrder()->getShippingMethod() == 'dpdpickup_dpdpickup') {
            $quote = $this->quoteFactory->create()->load($payment->getOrder()->getQuoteId());
            $this->updateShippingAddressByDpdParcel($quote, $requestData);
        }

        if (($payment->getOrder()->getShippingMethod() == 'dhlparcel_servicepoint')
            &&
            $payment->getOrder()->getDhlparcelShippingServicepointId()
        ) {
            $this->updateShippingAddressByDhlParcel(
                $payment->getOrder()->getDhlparcelShippingServicepointId(),
                $requestData
            );
        }

        if (($payment->getOrder()->getShippingMethod() == 'sendcloud_sendcloud')
            &&
            $payment->getOrder()->getSendcloudServicePointId()
        ) {
            $this->updateShippingAddressBySendcloud($payment->getOrder(), $requestData);
        }

        $this->handleShippingAddressByMyParcel($payment, $requestData);

        // Merge the article data; products and fee's
        $requestData = array_merge($requestData, $this->getRequestArticlesData($payment));

        return $requestData;
    }

    protected function updateShippingAddressByMyParcel($myParcelLocation, &$requestData)
    {
        $mapping = [
            ['Street', $myParcelLocation['street']],
            ['PostalCode', $myParcelLocation['postal_code']],
            ['City', $myParcelLocation['city']],
            ['Country', $myParcelLocation['cc']],
            ['StreetNumber', $myParcelLocation['number']],
            ['StreetNumberAdditional', $myParcelLocation['number_suffix']],
        ];

        $this->logger2->addDebug(__METHOD__ . '|1|' . var_export($mapping, true));

        foreach ($mapping as $mappingItem) {
            if (!empty($mappingItem[1])) {
                $found = false;
                foreach ($requestData as $key => $value) {
                    if ($requestData[$key]['Group'] == 'ShippingCustomer') {
                        if ($requestData[$key]['Name'] == $mappingItem[0]) {
                            $requestData[$key]['_'] = $mappingItem[1];
                            $found = true;
                        }
                    }
                }
                if (!$found) {
                    $requestData[] = [
                        '_'    => $mappingItem[1],
                        'Name' => $mappingItem[0],
                        'Group' => 'ShippingCustomer',
                        'GroupID' =>  '',
                    ];
                }
            }
        }
    }

    /**
     * @param $payment
     *
     * @return array
     * @throws \Buckaroo\Magento2\Exception
     */
    public function getRequestArticlesData($payment)
    {
        $this->logger2->addDebug(__METHOD__.'|1|');

        $includesTax = $this->_scopeConfig->getValue(
            static::TAX_CALCULATION_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE
        );

        $quote = $this->quoteFactory->create()->load($payment->getOrder()->getQuoteId());
        $cartData = $quote->getAllItems();

        // Set loop variables
        $articles = [];
        $count    = 1;

        /** @var \Magento\Sales\Model\Order\Item $item */
        foreach ($cartData as $item) {

            if (empty($item)
                || $item->hasParentItemId()
                || $item->getRowTotalInclTax() == 0
            ) {
                continue;
            }

            $article = $this->getArticleArrayLine(
                $count,
                $item->getName(),
                $item->getSku(),
                $item->getQty(),
                $this->calculateProductPrice($item, $includesTax),
                $item->getTaxPercent() ?? 0
            );

            $articles = array_merge($articles, $article);

            if ($count < self::KLARNA_MAX_ARTICLE_COUNT) {
                $count++;
                continue;
            }

            break;
        }

        $serviceLine = $this->getServiceCostLine($count, $payment->getOrder());

        if (!empty($serviceLine)) {
            $articles = array_merge($articles, $serviceLine);
            $count++;
        }

        // Add additional shipping costs.
        $shippingCosts = $this->getShippingCostsLine($payment->getOrder(), $count);

        if (!empty($shippingCosts)) {
            $articles = array_merge($articles, $shippingCosts);
            $count++;
        }

        $discountline = $this->getDiscountLine($count, $payment);

        if (!empty($discountline)) {
            $articles = array_merge($articles, $discountline);
            $count++;
        }

        return $articles;
    }

    /**
     * @param \Magento\Sales\Model\Order|\Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo $order
     *
     * @param $count
     * @return array
     */
    private function getShippingCostsLine($order, $count, &$itemsTotalAmount = 0)
    {
        $shippingCostsArticle = [];

        $shippingAmount = $this->getShippingAmount($order);
        if ($shippingAmount <= 0) {
            return $shippingCostsArticle;
        }

        $request = $this->taxCalculation->getRateRequest(null, null, null);
        $taxClassId = $this->taxConfig->getShippingTaxClass();
        $percent = $this->taxCalculation->getRate($request->setProductClassId($taxClassId));

        $shippingCostsArticle = [
            [
                '_'       => 'Shipping fee',
                'Name'    => 'Description',
                'Group'   => 'Article',
                'GroupID' =>  $count,
            ],
            [
                '_'       => $shippingAmount,
                'Name'    => 'GrossUnitPrice',
                'Group'   => 'Article',
                'GroupID' =>  $count,
            ],
            [
                '_'       => $percent,
                'Name'    => 'VatPercentage',
                'Group'   => 'Article',
                'GroupID' =>  $count,
            ],
            [
                '_'       => '1',
                'Name'    => 'Quantity',
                'Group'   => 'Article',
                'GroupID' =>  $count,
            ],
            [
                '_'       => '1',
                'Name'    => 'Identifier',
                'Group'   => 'Article',
                'GroupID' => $count,
            ]
        ];

        $itemsTotalAmount += $shippingAmount;

        return $shippingCostsArticle;
    }

    /**
     * Get the discount cost lines
     *
     * @param (int)                                                                              $latestKey
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment
     *
     * @return array
     */
    public function getDiscountLine($latestKey, $payment)
    {
        $article = [];
        $discount = $this->getDiscountAmount($payment);

        if ($discount >= 0) {
            return $article;
        }

        $article = $this->getArticleArrayLine(
            $latestKey,
            'Korting',
            1,
            1,
            round($discount, 2),
            0
        );

        return $article;
    }

    /**
     * @param $latestKey
     * @param $articleDescription
     * @param $articleId
     * @param $articleQuantity
     * @param $articleUnitPrice
     * @param $articleVat
     *
     * @return array
     */
    public function getArticleArrayLine(
        $latestKey,
        $articleDescription,
        $articleId,
        $articleQuantity,
        $articleUnitPrice,
        $articleVat = ''
    ) {
        $article = [
            [
                '_'       => $articleDescription,
                'Name'    => 'Description',
                'GroupID' => $latestKey,
                'Group' => 'Article',
            ],
            [
                '_'       => $articleId,
                'Name'    => 'Identifier',
                'Group' => 'Article',
                'GroupID' => $latestKey,
            ],
            [
                '_'       => $articleQuantity,
                'Name'    => 'Quantity',
                'GroupID' => $latestKey,
                'Group' => 'Article',
            ],
            [
                '_'       => $articleUnitPrice,
                'Name'    => 'GrossUnitPrice',
                'GroupID' => $latestKey,
                'Group' => 'Article',
            ],
            [
                '_'       => $articleVat,
                'Name'    => 'VatPercentage',
                'GroupID' => $latestKey,
                'Group' => 'Article',
            ]
        ];

        return $article;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment
     *
     * @return array
     */
    public function getRequestBillingData($payment)
    {
        /**
         * @var \Magento\Sales\Api\Data\OrderAddressInterface $billingAddress
         */
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();
        $streetFormat   = $this->formatStreet($billingAddress->getStreet());

        $birthDayStamp = str_replace('/', '-', $payment->getAdditionalInformation('customer_DoB'));
        $identificationNumber = $payment->getAdditionalInformation('customer_identificationNumber');
        $telephone = $payment->getAdditionalInformation('customer_telephone');
        $telephone = (empty($telephone) ? $billingAddress->getTelephone() : $telephone);
        $category = 'B2C';

        $gender = 'female';
        if ($payment->getAdditionalInformation('customer_gender') === '1') {
            $gender = 'male';
        }

        $billingData = [
            [
                '_'    => $category,
                'Name' => 'Category',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getFirstname(),
                'Name' => 'FirstName',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getLastName(),
                'Name' => 'LastName',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $gender,
                'Name' => 'Gender',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $streetFormat['street'],
                'Name' => 'Street',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getPostcode(),
                'Name' => 'PostalCode',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getCity(),
                'Name' => 'City',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getCountryId(),
                'Name' => 'Country',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $billingAddress->getEmail(),
                'Name' => 'Email',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ],
        ];

        if (!empty($telephone)) {
            $billingData[] = [
                '_'    => $telephone,
                'Name' => 'Phone',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($streetFormat['house_number'])) {
            $billingData[] = [
                '_'    => $streetFormat['house_number'],
                'Name' => 'StreetNumber',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($streetFormat['number_addition'])) {
            $billingData[] = [
                '_'    => $streetFormat['number_addition'],
                'Name' => 'StreetNumberAdditional',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if ($billingAddress->getCountryId() == 'FI') {
            $billingData[] = [
                '_'    => $identificationNumber,
                'Name' => 'IdentificationNumber',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        if ($birthDayStamp) {
            $billingData[] = [
                '_'    => $birthDayStamp,
                'Name' => 'BirthDate',
                'Group' => 'BillingCustomer',
                'GroupID' => '',
            ];
        }

        return $billingData;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment
     *
     * @return array
     */
    public function getRequestShippingData($payment)
    {
        $order = $payment->getOrder();
        /**
         * @var \Magento\Sales\Api\Data\OrderAddressInterface $shippingAddress
         */
        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress == null) {
            $shippingAddress = $order->getBillingAddress();
        }

        $postNLPakjeGemakAddress = $this->getPostNLPakjeGemakAddressInQuote($order->getQuoteId());

        if (!empty($postNLPakjeGemakAddress) && !empty($postNLPakjeGemakAddress->getData())) {
            $shippingAddress = $postNLPakjeGemakAddress;
        }

        $telephone = $payment->getAdditionalInformation('customer_telephone');
        $telephone = (empty($telephone) ? $shippingAddress->getTelephone() : $telephone);
        $streetFormat    = $this->formatStreet($shippingAddress->getStreet());
        $category = 'B2C';

        $gender = 'female';
        if ($payment->getAdditionalInformation('customer_gender') == '1') {
            $gender = 'male';
        }

        $shippingData = [
            [
                '_'    => $category,
                'Name' => 'Category',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getFirstname(),
                'Name' => 'FirstName',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getLastName(),
                'Name' => 'LastName',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $gender,
                'Name' => 'Gender',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $streetFormat['street'],
                'Name' => 'Street',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getPostcode(),
                'Name' => 'PostalCode',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getCity(),
                'Name' => 'City',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getCountryId(),
                'Name' => 'Country',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
            [
                '_'    => $shippingAddress->getEmail(),
                'Name' => 'Email',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ],
        ];

        if (!empty($telephone)) {
            $shippingData[] = [
                '_'    => $telephone,
                'Name' => 'Phone',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($streetFormat['house_number'])) {
            $shippingData[] = [
                '_'    => $streetFormat['house_number'],
                'Name' => 'StreetNumber',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }

        if (!empty($streetFormat['number_addition'])) {
            $shippingData[] = [
                '_'    => $streetFormat['number_addition'],
                'Name' => 'StreetNumberAdditional',
                'Group' => 'ShippingCustomer',
                'GroupID' => '',
            ];
        }

        return $shippingData;
    }

    /**
     * Failure message from failed Aferpay Transactions
     *
     * {@inheritdoc}
     */
    protected function getFailureMessageFromMethod($transactionResponse)
    {
        $transactionType = $transactionResponse->TransactionType;
        $methodMessage = '';

        if ($transactionType != 'C011' && $transactionType != 'C016' && $transactionType != 'C039' && $transactionType != 'I038') {
            return $methodMessage;
        }

        if ($transactionType == 'I038') {
            if (isset($transactionResponse->Services->Service->ResponseParameter->Name)
                &&
                ($transactionResponse->Services->Service->ResponseParameter->Name === 'ErrorResponseMessage')
                &&
                isset($transactionResponse->Services->Service->ResponseParameter->_)
            ) {
                return $transactionResponse->Services->Service->ResponseParameter->_;
            }
        }

        $subcodeMessage = $transactionResponse->Status->SubCode->_;
        $subcodeMessage = explode(':', $subcodeMessage);

        if (count($subcodeMessage) > 1) {
            array_shift($subcodeMessage);
        }

        $methodMessage = trim(implode(':', $subcodeMessage));

        return $methodMessage;
    }

    /**
     * {@inheritdoc}
     */
    public function getCaptureTransactionBuilder($payment)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizeTransactionBuilder($payment)
    {
        return false;
    }
}
