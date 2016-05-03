<?php
/**
 *
 * PayNl payment plugin
 *
 * @author pay.nl development team
 * @version $Id:
 * @package VirtueMart
 * @subpackage payment
 * http://www.pay.nl
 */

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

if (!class_exists('PaynlHelperPaypal')) {
    require(JPATH_SITE . '/plugins/vmpayment/paynl/paynl/helpers/paynlHelperPaynl.php');
}
if (!class_exists('PaynlHelperCustomerData')) {
    require(JPATH_SITE . '/plugins/vmpayment/paynl/paynl/helpers/paynlHelperCustomerData.php');
}
if (!class_exists('PaynlHelperPaynlStd')) {
    require(JPATH_SITE . '/plugins/vmpayment/paynl/paynl/helpers/paynlHelperPaynlStd.php');
}

class plgVmPaymentPaynl extends vmPSPlugin
{

    // instance of class
    private $customerData;
    private $_autobilling_max_amount = '';
    private $_user_data_valid = false;
    private $_errormessage = array();

    function __construct(& $subject, $config)
    {

        parent::__construct($subject, $config);

        $this->customerData = new PaynlHelperCustomerData();
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id'; //virtuemart_paypal_id';
        $this->_tableId = 'id'; //'virtuemart_paypal_id';
        $varsToPush = array(
            'service_id' => array('', 'char'),
            'token_api' => array('', 'char'),
            'payNL_optionId' => array('', 'char'),
            'payNL_optionList' => array('', 'char'),
            'status_pending' => array('', 'char'),
            'status_success' => array('', 'char'),
            'status_canceled' => array('', 'char'),
            'status_expired' => array('', 'char'),
            'status_capture' => array('', 'char'),
            'status_refunded' => array('', 'char'),
            //discount
            'cost_per_transaction' => array('', 'float'),
            'cost_percent_total' => array('', 'char'),
            'tax_id' => array(0, 'int'),
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('PayNL Table');
    }

    function getTableSQLFields()
    {

        $SQLfields = array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'token_api' => 'varchar(50)',
            'service_id' => 'varchar(50)',
            'payNL_optionList' => 'int(1)',
            'payNL_optionId' => 'int(1)',
            'cost_per_transaction' => 'varchar(20)',
            'cost_percent_total' => 'varchar(20)',

        );
        return $SQLfields;
    }

    /**
     * @param $product
     * @param $productDisplay
     * @return bool
     */
    function plgVmOnProductDisplayPayment($product, &$productDisplay)
    {

        $vendorId = 1;
        if ($this->getPluginMethods($vendorId) === 0) {
            return FALSE;
        }

        foreach ($this->methods as $this->_currentMethod) {
            if ($this->_currentMethod->paypalproduct == 'exp') {
                $paypalInterface = $this->_loadPaynlInterface();
                $product = $paypalInterface->getExpressProduct();
                $productDisplayHtml = $this->renderByLayout('expproduct',
                    array(
                        'text' => vmText::_('VMPAYMENT_PAYPAL_EXPCHECKOUT_AVAILABALE'),
                        'img' => $product['img'],
                        'link' => $product['link'],
                        'sandbox' => $this->_currentMethod->sandbox,
                        'virtuemart_paymentmethod_id' => $this->_currentMethod->virtuemart_paymentmethod_id,
                    )
                );
                $productDisplay[] = $productDisplayHtml;

            }
        }
        return TRUE;
    }


    function plgVmDisplayLogin(VirtuemartViewUser $user, &$html, $from_cart = FALSE)
    {

        // only to display it in the cart, not in list orders view
        if (!$from_cart) {
            return NULL;
        }

        $vendorId = 1;
        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }

        $cart = VirtueMartCart::getCart();
        if ($this->getPluginMethods($cart->vendorId) === 0) {
            return FALSE;
        }
        if ($cart->pricesUnformatted['salesPrice'] <= 0.0) {
            return FALSE;
        }
        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
            return NULL;
        }
    }

    function plgVmOnCheckoutAdvertise($cart, &$payment_advertise)
    {

        if ($this->getPluginMethods($cart->vendorId) === 0) {
            return FALSE;
        }
        if ($cart->pricesUnformatted['salesPrice'] <= 0.0) {
            return NULL;
        }
        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
            return NULL;
        }

    }


    function plgVmConfirmedOrder($cart, $order)
    {

        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }

        $this->getPaymentCurrency($this->_currentMethod);
        $email_currency = $this->getEmailCurrency($this->_currentMethod);

        $payment_name = $this->renderPluginName($this->_currentMethod, $order);

        $paypalInterface = $this->_loadPaynlInterface();
        $paypalInterface->debugLog('order number: ' . $order['details']['BT']->order_number, 'plgVmConfirmedOrder', 'message');
        $paypalInterface->setCart($cart);
        $paypalInterface->setOrder($order);
        $paypalInterface->setTotal($order['details']['BT']->order_total);
        $paypalInterface->loadCustomerData();


        // Prepare data that should be stored in the database
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $payment_name;
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['paypal_custom'] = $paypalInterface->getContext();
        $dbValues['cost_per_transaction'] = $this->_currentMethod->cost_per_transaction;
        $dbValues['cost_percent_total'] = $this->_currentMethod->cost_percent_total;
        $dbValues['payment_currency'] = $this->_currentMethod->payment_currency;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['payment_order_total'] = $paypalInterface->getTotal();
        $dbValues['tax_id'] = $this->_currentMethod->tax_id;
        $dbValues['amount'] = floatval($paypalInterface->getTotal()) * 100;
        $dbValues['option_id'] = $this->_currentMethod->payNL_optionList;
        $dbValues['virtuemart_order_id'] = VirtueMartModelOrders::getOrderIdByOrderNumber($dbValues['order_number']);

        VmConfig::loadJLang('com_virtuemart_orders', TRUE);

        $result = $paypalInterface->ManageCheckout();

        //save on transaction table
        $this->saveTransaction(array(
            'transaction_id' => $result['transaction']['transactionId'],
            'option_id' => $this->_currentMethod->payNL_optionList,
            'amount' => $paypalInterface->getTotal(),
            'order_id' => $dbValues['virtuemart_order_id'],
            'status' => 'PENDING'
        ));

        $url = $result['transaction']['paymentURL'];
        $dbValues['transaction_id'] = $result['transaction']['transactionId'];
        $this->storePSPluginInternalData($dbValues);
        $app = JFactory::getApplication();
        $app->redirect($url);
    }


    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency($this->_currentMethod);
        $paymentCurrencyId = $this->_currentMethod->payment_currency;
    }

    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {

        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }
        if (!($payments = $this->_getPaypalInternalData($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        if (empty($payments[0]->email_currency)) {
            $vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
            $db = JFactory::getDBO();
            $q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`=' . $vendorId;
            $db->setQuery($q);
            $emailCurrencyId = $db->loadResult();
        } else {
            $emailCurrencyId = $payments[0]->email_currency;
        }

    }

    function plgVmOnPaymentResponseReceived(&$html)
    {

        if (!class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        VmConfig::loadJLang('com_virtuemart_orders', TRUE);

        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);

        $order_number = vRequest::getString('on', 0);
        $orderId = vRequest::getString('orderId', 0);

        $vendorId = 0;
        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return NULL;
        }
        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
            return '';
        }
        //check status from pay.nl
        $api_status = $this->checkStatus($orderId);

        $payment_name = $this->renderPluginName($this->_currentMethod);
        $payment = end($payments);

        VmConfig::loadJLang('com_virtuemart');
        $orderModel = VmModel::getModel('orders');
        $order = $orderModel->getOrder($virtuemart_order_id);

        $lastTransactionStatus = $this->getLastAPIStatus($virtuemart_order_id)->status;
        $isPaid = $lastTransactionStatus == 'PAID' ? true : false; //$this->isPaid($virtuemart_order_id);

        if (!class_exists('CurrencyDisplay'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
        $currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);


        if ($isPaid && $api_status == 'PAID') {
            VmInfo(vmText::sprintf('VMPAYMENT_PAYNL_PAYMENT_STATUS_CONFIRMED', $order_number));
            $html = $this->renderByLayout('response', array("success" => true,
                "payment_name" => $payment_name,
                "amount" => $order['details']['BT']->order_total,
                "order" => $order,
                "transactionID" => $orderId,
                "currency" => $currency,
            ));
            $cart = VirtueMartCart::getCart();
            JRequest::setVar('html', $html);
            $cart->emptyCart();
            return TRUE;

        }

        vmdebug('plgVmOnPaymentResponseReceived', $payment);

        if ($api_status == "CANCEL" && !$isPaid) {
            $order['comments'] = JText::_('COM_VIRTUEMART_PAYMENT_CANCELLED_BY_SHOPPER');
            $order['order_status'] = $this->getCustomState($api_status);
            // VmInfo (JText::_ ('COM_VIRTUEMART_PAYMENT_CANCELLED_BY_SHOPPER'));
            if ($api_status != $lastTransactionStatus) {
                $orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, false);
                $this->updateTransaction($virtuemart_order_id, $api_status);
            }

            $msg = (JText::_('COM_VIRTUEMART_PAYMENT_CANCELLED_BY_SHOPPER'));
            $type = 'error';
            $app = JFactory::getApplication();
            $app->enqueueMessage($msg, $type);
            $app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'));
            return true;
        }

        if (!$isPaid && $api_status == 'PAID') {
            VmInfo(vmText::sprintf('VMPAYMENT_PAYNL_PAYMENT_STATUS_CONFIRMED', $order_number));
            $html = $this->renderByLayout('response', array("success" => true,
                "payment_name" => $payment_name,
                "amount" => $order['details']['BT']->order_total,
                "order" => $order,
                "transactionID" => $orderId,
                "currency" => $currency,
            ));
            $order_history['order_status'] = $this->getCustomState($api_status);
            $order_history['customer_notified'] = 0;
            $order_history['comments'] = '';
            $order_history['comments'] = vmText::sprintf('VMPAYMENT_PAYNL_PAYMENT_STATUS_CONFIRMED', $this->order['details']['BT']->order_number);
            $orderModel->updateStatusForOneOrder($virtuemart_order_id, $order_history, TRUE);
            $this->updateTransaction($virtuemart_order_id, $api_status);

            $cart = VirtueMartCart::getCart();
            JRequest::setVar('html', $html);
            $cart->emptyCart();
            return TRUE;

        }
        return false;
    }

    function plgVmOnUserPaymentCancel()
    {

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $order_number = vRequest::getString('on', '');
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', '');
        if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
            return NULL;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return NULL;
        }
        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }

        VmInfo(vmText::_('VMPAYMENT_PAYPAL_PAYMENT_CANCELLED'));
        $session = JFactory::getSession();
        $return_context = $session->getId();
        if (strcmp($paymentTable->paypal_custom, $return_context) === 0) {
            $this->handlePaymentUserCancel($virtuemart_order_id);
        }
        return TRUE;
    }

    //exchange
    function plgVmOnPaymentNotification()
    {


        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $paynl_data = vRequest::getGet();
        if ($paynl_data['action'] == 'pending') {
            echo 'TRUE|ignoring pending';
            die;
        }


        $order_number = $paynl_data['extra1'];

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($paynl_data['extra1']))) {
            return FALSE;
        }

        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
            return FALSE;
        }

        $this->_currentMethod = $this->getVmPluginMethod($payments[0]->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        $orderModel = VmModel::getModel('orders');
        $order = $orderModel->getOrder($virtuemart_order_id);

        $stateFromAPI = $this->checkStatus($paynl_data['order_id']);

        $order_history['order_status'] = $this->getCustomState($stateFromAPI);
        $order_history['customer_notified'] = 1;
        $order_history['comments'] = '';


        //$isPaid = $this->isPaid($virtuemart_order_id);
        $lastTransactionStatus = $this->getLastAPIStatus($virtuemart_order_id)->status;
        $isPaid = $lastTransactionStatus == 'PAID' ? true : false; //$this->isPaid($virtuemart_order_id);

        //check if the transaction is already paid
        if ($isPaid) {
            echo 'TRUE|message: transaction already paid, data:' . print_r($paynl_data, TRUE) . ', virtuemart_payment_state:' . $order['details']['BT']->order_status . ', api_current_state: ' . $stateFromAPI .
                ', order_history:' . print_r($order_history, true) . ', status_canceled: ' . $this->_currentMethod->status_canceled .
                ', status_capture: ' . $this->_currentMethod->status_capture . ', status_expired: ' . $this->_currentMethod->status_expired .
                ', status_partial_refunded: ' . $this->_currentMethod->status_partial_refunded . ', status_pending: ' . $this->_currentMethod->status_pending .
                ', status_refunded: ' . $this->_currentMethod->status_refunded . ', status_success: ' . $this->_currentMethod->status_success;
            return FALSE;
        }

        echo 'TRUE|data:' . print_r($paynl_data, TRUE) . ', virtuemart_payment_state:' . $order['details']['BT']->order_status . ', api_current_state: ' . $stateFromAPI .
            ', order_history:' . print_r($order_history, true) . ', status_canceled: ' . $this->_currentMethod->status_canceled .
            ', status_capture: ' . $this->_currentMethod->status_capture . ', status_expired: ' . $this->_currentMethod->status_expired .
            ', status_partial_refunded: ' . $this->_currentMethod->status_partial_refunded . ', status_pending: ' . $this->_currentMethod->status_pending .
            ', status_refunded: ' . $this->_currentMethod->status_refunded . ', status_success: ' . $this->_currentMethod->status_success;
        //change status
        $order['order_status'] = $this->getCustomState($stateFromAPI);
        $order['customer_notified'] = 1;
        $order['comments'] = '';

        if ($stateFromAPI == 'PAID' && $stateFromAPI != $lastTransactionStatus) {
            $order_history['comments'] = vmText::sprintf('VMPAYMENT_PAYNL_PAYMENT_STATUS_CONFIRMED', $this->order['details']['BT']->order_number);
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();
            $orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
        }

        if ($stateFromAPI == 'CANCEL' && $stateFromAPI != $lastTransactionStatus) {
            $order['customer_notified'] = 0;
            $order_history['comments'] = JText::_('COM_VIRTUEMART_PAYMENT_CANCELLED_BY_SHOPPER');
            //$this->handlePaymentUserCancel($virtuemart_order_id);
            $orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
        }

        if ($stateFromAPI == 'PENDING' && $stateFromAPI != $lastTransactionStatus) {
            $order['customer_notified'] = 0;
            $orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
        }

        //update transaction table
        $this->updateTransaction($virtuemart_order_id, $stateFromAPI);


        //
        //save data
        $this->_storePaynlInternalData($paynl_data, $virtuemart_order_id, $payments[0]->virtuemart_paymentmethod_id);
        $paynlInterface = $this->_loadPaynlInterface();
        $paynlInterface->setOrder($order);
        $paynlInterface->debugLog($paynl_data, 'PaymentNotification, paynl_data:', 'debug');
        $paynlInterface->debugLog($order_number, 'PaymentNotification, order_number:', 'debug');
        $paynlInterface->debugLog($payments[0]->virtuemart_paymentmethod_id, 'PaymentNotification, virtuemart_paymentmethod_id:', 'debug');
        $paynlInterface->debugLog('order_number:' . $order_number . ' new_status:' . $stateFromAPI, 'plgVmOnPaymentNotification', 'debug');

        return true;

    }


    private function checkStatus($order_id)
    {
        if (!class_exists('Pay_Api_Info')) {
            require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/Api.php');
            require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/api/Info.php');
            require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/Helper.php');
            //require(JPATH_SITE . '/plugins/vmpayment/paynl/pay/api/Start.php');
        }
        $payApiInfo = new Pay_Api_Info();
        $payApiInfo->setApiToken($this->_currentMethod->token_api);
        $payApiInfo->setServiceId($this->_currentMethod->service_id);
        $payApiInfo->setTransactionId($order_id);
        try {
            $result = $payApiInfo->doRequest();
        } catch (Exception $ex) {
            vmError($ex->getMessage());
        }

        return Pay_Helper::getStateText($result['paymentDetails']['state']);

    }


    /*********************/
    /* Private functions */
    /*********************/
    private function _loadPaynlInterface()
    {
        //$this->_currentMethod->paypalproduct = $this->getPaypalProduct($this->_currentMethod);
        $paynlInterface = new PaynlHelperPaynlStd($this->_currentMethod, $this);
        return $paynlInterface;
    }

    private function _storePaynlInternalData($paynl_data, $virtuemart_order_id, $virtuemart_paymentmethod_id)
    {
        $paynlInterface = $this->_loadPaynlInterface();


        if ($paynl_data) {
            $response_fields['status'] = $paynl_data['action'];
            $response_fields['payment_order_total'] = $paynl_data['amount'];
            $response_fields['order_number'] = $paynl_data['extra1'];
            $response_fields['transaction_id'] = $paynl_data['order_id'];
            $response_fields['option_id'] = $paynl_data['payment_method_id'];
            $response_fields['paynl_fullresponse'] = json_encode($paynl_data);
        }

        $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
        $response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
        return $this->storePSPluginInternalData($response_fields, $this->_tablepkey, 0);

    }

    private function _getPaypalInternalData($virtuemart_order_id, $order_number = '')
    {

        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
        if ($order_number) {
            $q .= " `order_number` = '" . $order_number . "'";
        } else {
            $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
        }

        $db->setQuery($q);
        if (!($payments = $db->loadObjectList())) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        return $payments;
    }

    protected function renderPluginName($activeMethod)
    {
        $return = '';
        $plugin_name = $this->_psType . '_name';
        $plugin_desc = $this->_psType . '_desc';
        $description = '';


        $pluginName = $return . '<span class="' . $this->_type . '_name">' . $activeMethod->$plugin_name . '</span>';

        if (!empty($activeMethod->$plugin_desc)) {
            $pluginName .= '<span class="' . $this->_type . '_description">' . $activeMethod->$plugin_desc . '</span>';
        }
        //$pluginName .= $this->displayExtraPluginNameInfo($activeMethod);
        return $pluginName;
    }

    /**
     * Display stored payment data for an order
     *
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {

        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return NULL; // Another method was selected, do nothing
        }
        if (!($this->_currentMethod = $this->getVmPluginMethod($payment_method_id))) {
            return FALSE;
        }
        if (!($payments = $this->_getPaypalInternalData($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        $html = '<table class="adminlist" width="50%">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $code = "paypal_response_";
        $first = TRUE;
        foreach ($payments as $payment) {
            $html .= '<tr class="row1"><td>' . vmText::_('VMPAYMENT_PAYPAL_DATE') . '</td><td align="left">' . $payment->created_on . '</td></tr>';
            // Now only the first entry has this data when creating the order
            if ($first) {
                $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $payment->payment_name);
                // keep that test to have it backwards compatible. Old version was deleting that column  when receiving an IPN notification
                if ($payment->payment_order_total and $payment->payment_order_total != 0.00) {
                    $html .= $this->getHtmlRowBE('COM_VIRTUEMART_TOTAL', $payment->payment_order_total . " " . shopFunctions::getCurrencyByID($payment->payment_currency, 'currency_code_3'));
                }

                $first = FALSE;
            } else {
                $paypalInterface = $this->_loadPaynlInterface();

                if (isset($payment->paypal_fullresponse) and !empty($payment->paypal_fullresponse)) {
                    $paypal_data = json_decode($payment->paypal_fullresponse);
                    $paypalInterface = $this->_loadPaynlInterface();
                    $html .= $paypalInterface->onShowOrderBEPayment($paypal_data);

                    $html .= '<tr><td></td><td>
    <a href="#" class="PayPalLogOpener" rel="' . $payment->id . '" >
        <div style="background-color: white; z-index: 100; right:0; display: none; border:solid 2px; padding:10px;" class="vm-absolute" id="PayPalLog_' . $payment->id . '">';

                    foreach ($paypal_data as $key => $value) {
                        $html .= ' <b>' . $key . '</b>:&nbsp;' . $value . '<br />';
                    }

                    $html .= ' </div>
        <span class="icon-nofloat vmicon vmicon-16-xml"></span>&nbsp;';
                    $html .= vmText::_('VMPAYMENT_PAYPAL_VIEW_TRANSACTION_LOG');
                    $html .= '  </a>';
                    $html .= ' </td></tr>';
                } else {
                    $html .= $paypalInterface->onShowOrderBEPaymentByFields($payment);
                }
            }


        }
        $html .= '</table>' . "\n";

        $doc = JFactory::getDocument();
        $js = "
	jQuery().ready(function($) {
		$('.PayPalLogOpener').click(function() {
			var logId = $(this).attr('rel');
			$('#PayPalLog_'+logId).toggle();
			return false;
		});
	});";
        $doc->addScriptDeclaration($js);
        return $html;

    }


    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @param VirtueMartCart $cart
     * @param int $activeMethod
     * @param array $cart_prices
     * @return bool
     */
    protected function checkConditions($cart, $activeMethod, $cart_prices)
    {

        //Check method publication start
        if ($activeMethod->publishup) {
            $nowDate = JFactory::getDate();
            $publish_up = JFactory::getDate($activeMethod->publishup);
            if ($publish_up->toUnix() > $nowDate->toUnix()) {
                return FALSE;
            }
        }
        if ($activeMethod->publishdown) {
            $nowDate = JFactory::getDate();
            $publish_down = JFactory::getDate($activeMethod->publishdown);
            if ($publish_down->toUnix() <= $nowDate->toUnix()) {
                return FALSE;
            }
        }
        $this->convert_condition_amount($activeMethod);

        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $this->getCartAmount($cart_prices);
        $amount_cond = ($amount >= $activeMethod->min_amount AND $amount <= $activeMethod->max_amount
            OR
            ($activeMethod->min_amount <= $amount AND ($activeMethod->max_amount == 0)));

        $countries = array();
        if (!empty($activeMethod->countries)) {
            if (!is_array($activeMethod->countries)) {
                $countries[0] = $activeMethod->countries;
            } else {
                $countries = $activeMethod->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            if ($amount_cond) {
                return TRUE;
            }
        }

        return FALSE;
    }


    /**
     * @param $jplugin_id
     * @return bool|mixed
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        if ($jplugin_id != $this->_jid) {
            return FALSE;
        }
        $this->_currentMethod = $this->getPluginMethod(vRequest::getInt('virtuemart_paymentmethod_id'));
        if ($this->_currentMethod->published) {

            $sandbox = "";
            if ($this->_currentMethod->sandbox) {
                $sandbox = 'SANDBOX_';
                $sandbox_param = 'sandbox_';
            }


            if ($this->_currentMethod->paypalproduct == 'std') {
                if ($this->_currentMethod->sandbox) {
                    $param = 'sandbox_merchant_email';
                } else {
                    $param = 'paypal_merchant_email';
                }
                if (empty ($this->_currentMethod->$param)) {
                    $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'MERCHANT'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                    vmWarn($text);
                }
            }
            if ($this->_currentMethod->paypalproduct == 'exp' OR $this->_currentMethod->paypalproduct == 'hosted' OR $this->_currentMethod->paypalproduct == 'api') {
                $param = $sandbox_param . 'api_login_id';
                if (empty ($this->_currentMethod->$param)) {
                    $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'USERNAME'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                    vmWarn($text);
                }
                $param = $sandbox_param . 'api_password';
                if (empty ($this->_currentMethod->$param)) {
                    $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'PASSWORD'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                    vmWarn($text);
                }

                if ($this->_currentMethod->authentication == 'signature') {
                    $param = $sandbox_param . 'api_signature';
                    if (empty ($this->_currentMethod->$param)) {
                        $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'SIGNATURE'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                        vmWarn($text);
                    }
                } else {
                    $param = $sandbox_param . 'api_certificate';
                    if (empty ($this->_currentMethod->$param)) {
                        $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'CERTIFICATE'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                        vmWarn($text);
                    }
                }
            }
            if ($this->_currentMethod->paypalproduct == 'hosted') {
                $param = $sandbox_param . 'payflow_partner';
                if (empty ($this->_currentMethod->$param)) {
                    $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_' . $sandbox . 'PAYFLOW_PARTNER'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                    vmWarn($text);
                }
            }
            if ($this->_currentMethod->paypalproduct == 'exp' AND empty ($this->_currentMethod->expected_maxamount)) {
                $text = vmText::sprintf('VMPAYMENT_PAYPAL_PARAMETER_REQUIRED', vmText::_('VMPAYMENT_PAYPAL_EXPECTEDMAXAMOUNT'), $this->_currentMethod->payment_name, $this->_currentMethod->virtuemart_paymentmethod_id);
                vmWarn($text);
            }

        }

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     *     * This event is fired after the payment method has been selected.
     * It can be used to store additional payment info in the cart.
     * @param VirtueMartCart $cart
     * @param $msg
     * @return bool|null
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return true;
    }

    /*******************/
    /* Order cancelled */
    /* May be it is removed in VM 2.1
    /*******************/
    public function plgVmOnCancelPayment(&$order, $old_order_status)
    {
        return NULL;

    }

    /**
     *  Order status changed
     * @param $order
     * @param $old_order_status
     * @return bool|null
     */
    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status)
    {

        //Load the method
        if (!($this->_currentMethod = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        //Load only when updating status to shipped
        if ($order->order_status != $this->_currentMethod->status_capture AND $order->order_status != $this->_currentMethod->status_refunded) {
            return null;
        }
        //Load the payments
        if (!($payments = $this->_getPaypalInternalData($order->virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return null;
        }

        if ($this->_currentMethod->paypalproduct == 'std') {
            return null;
        }
        //$this->_currentMethod->paypalproduct = $this->($this->_currentMethod);

        $payment = end($payments);
        if ($this->_currentMethod->payment_action == 'Authorization' and $order->order_status == $this->_currentMethod->status_capture) {
            $paypalInterface = $this->_loadPaynlInterface();
            $paypalInterface->setOrder($order);
            $paypalInterface->setTotal($order->order_total);
            $paypalInterface->loadCustomerData();
            if ($paypalInterface->DoCapture($payment)) {
                $paypalInterface->debugLog(vmText::_('VMPAYMENT_PAYNL_API_TRANSACTION_CAPTURED'), 'plgVmOnUpdateOrderShipment', 'message', true);
                $this->_storePaynlInternalData($paypalInterface->getResponse(false), $order->virtuemart_order_id, $payment->virtuemart_paymentmethod_id);
            }

        } elseif ($order->order_status == $this->_currentMethod->status_refunded OR $order->order_status == $this->_currentMethod->status_canceled) {
            $paypalInterface = $this->_loadPaynlInterface();
            $paypalInterface->setOrder($order);
            $paypalInterface->setTotal($order->order_total);
            $paypalInterface->loadCustomerData();

        }

        return true;
    }

    function plgVmOnUpdateOrderLinePayment(&$order)
    {
        // $xx=1;
    }

    /**
     * * List payment methods selection
     * @param VirtueMartCart $cart
     * @param int $selected
     * @param $htmlIn
     * @return bool
     */

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {

        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return false;
            } else {
                return false;
            }
        }
        $method_name = $this->_psType . '_name';

        $htmla = array();
        foreach ($this->methods as $this->_currentMethod) {
            if ($this->checkConditions($cart, $this->_currentMethod, $cart->pricesUnformatted)) {

                $html = '';
                $cart_prices = array();
                $cart_prices['withTax'] = '';
                $cart_prices['salesPrice'] = '';
                $methodSalesPrice = $this->setCartPrices($cart, $cart_prices, $this->_currentMethod);

                $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
                $html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                $htmla[] = $html;
            }
        }
        $htmlIn[] = $htmla;
        return true;

    }


    /**
     * Validate payment on checkout
     * @param VirtueMartCart $cart
     * @return bool|null
     */
    function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
    {

        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
            return FALSE;
        }

        $paypalInterface = new PaynlHelperPaynlStd($this->_currentMethod, $this);// $this->_loadPaynlInterface();
        $paypalInterface->setTotal($cart->pricesUnformatted['billTotal']);
        return true;

    }

    //Calculate the price (value, tax_id) of the selected method, It is called by the calculator
    //This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {

        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    // Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
    // The plugin must check first if it is the correct type
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    // This method is fired when showing the order details in the frontend.
    // It displays the method-specific data.
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    // This method is fired when showing when priting an Order
    // It displays the the payment method-specific data.
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

//	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
//
//		return $this->declarePluginParams('payment', $name, $id, $data);
//	}

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    private function saveTransaction($data)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $columns = array('transaction_id', 'option_id', 'amount', 'order_id', 'status');
        $values = array(
            $db->quote($data['transaction_id']),
            $db->quote($data['option_id']),
            $db->quote(strval($data['amount'] * 100)),
            $db->quote($data['order_id']),
            $db->quote($data['status'])
        );
        $query
            ->insert($db->quoteName('#__paynl_transactions'))
            ->columns($db->quoteName($columns))
            ->values(implode(',', $values));
        $db->setQuery($query);
        $result = $db->query();
        return $result;
    }

    private function updateTransaction($orderId, $status)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        $date = date_create()->format('Y-m-d H:i:s');

        $fields = array(
            $db->quoteName('status') . ' = ' . $db->quote($status),
            $db->quoteName('last_update') . ' = ' . $db->quote($date->date),
        );

        $conditions = array(
            $db->quoteName('order_id') . ' = ' . $db->quote($orderId)
        );

        $query->update($db->quoteName('#__paynl_transactions'))->set($fields)->where($conditions);

        $db->setQuery($query);
        $result = $db->query();
        return $result;
    }

    private function getLastAPIStatus($orderId)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        $conditions = array(
            $db->quoteName('order_id') . " = " . $db->quote($orderId)
        );

        $query->select('status');
        $query->from($db->quoteName('#__paynl_transactions'));
        $query->where($conditions);

        $db->setQuery($query);
        $result = $db->loadObject();
        return $result;
    }

    private function getCustomState($state)
    {
        switch ($state) {
            case 'PAID':
                $vmstate = $this->_currentMethod->status_success;
                break;
            case 'CANCEL':
                $vmstate = $this->_currentMethod->status_canceled;
                break;
            case 'PENDING':
                $vmstate = $this->_currentMethod->status_pending;
                break;

        }
        return $vmstate;
    }

}

// No closing tag