<?php
/*
 Plugin Name: Woocommerce Paychoice Plugin
 Plugin URI: https://github.com/paychoice/woocommerce
 Description: Woocommerce plugin for Paychoice Payment Gateway
 Version: 1.0
 Author: Danial Tham
 */
 
add_action('plugins_loaded', 'woocommerce_paychoice_gateway_init', 0);
add_filter('woocommerce_payment_gateways', 'woocommerce_add_paychoice_gateway');

function woocommerce_paychoice_gateway_init()
{
    if (!class_exists('WC_Payment_Gateway'))
        return;
        
    class WC_Paychoice_Gateway extends WC_Payment_Gateway
    {
        //Server response code constants
        const SERVER_ERROR = 500;
        const SERVER_RESPONSE_OK = 200;
        const SERVER_UNAUTHORIZED = 401;
        const SERVER_PAYMENT_REQUIRED = 402;
        
        //Response status code constants
        const PAYMENT_SUCCESS = 0;
        const PAYMENT_DISHONOUR = 5;
        const PAYMENT_ERROR = 6;
        
        public function __construct()
        {
            $this -> id = 'paychoice';
            $this -> title = 'Paychoice Payment Gateway';
            $this -> has_fields = true;
            $this -> method_title = 'Paychoice Payment Gateway';
            $this -> method_description = 'Direct payment for Paychoice Payment Gateway';

            $this -> init_form_fields();
            $this -> init_settings();

            $this -> title = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> api_username = $this -> settings['api_username'];
            $this -> api_password = $this -> settings['api_password'];
            $this -> currency = $this -> settings['currency'];
            $this -> useSandbox = ($this -> settings['mode']=='sandbox'?true:false);
            
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action('woocommerce_update_options_payment_gateways_' . $this -> id, array(&$this,'process_admin_options'));
            } else
            {
                add_action('woocommerce_update_options_payment_gateways', array(&$this,'process_admin_options'));
            }
        }

        //Enable function to send output log for debug, change to destination file to where-ever suits you
        /**function log_output($message)
        {
            error_log(date('m/d/Y h:i:s a', time()).": \n".$message."\n\n", 3, "/var/tmp/wordpress_debug.log");
        }**/

        function getWoocommerceVersionNumber() 
        {
            // If get_plugins() isn't available, require it
            if ( ! function_exists( 'get_plugins' ) )
                require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            
            // Create the plugins folder and file variables
            $plugin_folder = get_plugins( '/' . 'woocommerce' );
            $plugin_file = 'woocommerce.php';
            
            // If the plugin version number is set, return it 
            if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
                return $plugin_folder[$plugin_file]['Version'];
        
            } else {
            // Otherwise return null
                return NULL;
            }
        }
        
        //Helper function to display error for different version of Woocommerce
        public function displayErrorMessage($message)
        {
            global $woocommerce;
             
            if ($this->getWoocommerceVersionNumber() >= 2.1)
            {
                return wc_add_notice(__($message, 'paychoice'),'error');
            }
            
            return $woocommerce->add_error(__($message, 'paychoice'));
        }
        
        //Function to retrieve error count for different version of Woocommerce
        public function getWoocommerceErrorCount()
        {
            global $woocommerce;
             
            if ($this->getWoocommerceVersionNumber() >= 2.1)
            {
                 return wc_notice_count('error');
            }
            
            return $woocommerce->error_count();
        }
        
        //Retrieves array of credit card type
        public function getCreditCardTypes()
        {
            return array(
                'VI' => __('Visa','paychoice'),
                'MC' => __('MasterCard','paychoice'),
                'AE' => __('American Express','paychoice'),
                'DI' => __('Discover','paychoice'),
                'JCB' => __('JCB','paychoice')
            );
        }
        
        //Regexps validation for different types of credit cards
        public function creditCardTypeRegexp()
        {
            return array(
                'VI' => '#^4\d{3}[ \-]?\d{4}[ \-]?\d{4}[ \-]?\d{4}$#i',
                'MC' => '#^5\d{3}[ \-]?\d{4}[ \-]?\d{4}[ \-]?\d{4}$#i',
                'AE' => '#^3\d{3}[ \-]?\d{6}[ \-]?\d{5}$#i',
                'DI' => '#^6011[ \-]?\d{4}[ \-]?\d{4}[ \-]?\d{4}$#i',
                'JCB' => '#^(?:(?:2131|1800|35\d{3})\d{11})$#'
            );
        }
        
        //Retrieves the available currency
        public function getAvailableCurrency()
        {
            return array('AUD' => __('Australian Dollar (AUD)','paychoice'));
        }
        
        function is_valid_luhn($number) 
        {
            settype($number, 'string');
            
            $sumTable = array
            (
                array(0,1,2,3,4,5,6,7,8,9),
                array(0,2,4,6,8,1,3,5,7,9)
            );
            
            $sum = 0;
            $flip = 0;
            
            for ($i = strlen($number) - 1; $i >= 0; $i--) 
            {
                $sum += $sumTable[$flip++ & 0x1][$number[$i]];
            }
            
            return $sum % 10 === 0;
        }
        
        //Initialize setting form fields
        public function init_form_fields()
        {
            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'paychoice'),
                    'type' => 'checkbox',
                    'label' => __('Enable Paychoice Payment Gateway', 'paychoice'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'paychoice'),
                    'type' => 'text',
                    'description' => __('This is the title displayed to the user during checkout.', 'paychoice'),
                    'default' => __('Paychoice Payment Gateway (Credit Card)', 'paychoice')
                ),
                'description' => array(
                    'title' => __('Description', 'paychoice'),
                    'type' => 'textarea',
                    'description' => __('This is the description which the user sees during checkout.', 'paychoice'),
                    'default' => __("Credit Card Payment via Paychoice", 'paychoice')
                ),
                'api_username' => array(
                    'title' => __('API Username', 'paychoice'),
                    'type' => 'text',
                    'description' => __('Api username provided by Paychoice', 'paychoice'),
                    'default' => ''
                ),
                'api_password' => array(
                    'title' => __('API Password', 'paychoice'),
                    'type' => 'password',
                    'description' => __('Api password provided by Paychoice', 'paychoice'),
                    'default' => ''
                ),
                'mode' => array(
                    'title' => __('Mode Type', 'paychoice'),
                    'type' => 'select',
                    'options' => array(
                        'sandbox' => __('Sandbox', 'paychoice'),
                        'live' => __('Live', 'paychoice')
                    ),
                    'description' => __('Select Sandbox for testing or Live for production.', 'paychoice')
                ),
                'currency' => array(
                    'title' => __('Currency', 'paychoice'),
                    'type' => 'select',
                    'options' => $this->getAvailableCurrency(),
                    'description' => __('Currency to use against Paychoice payment gateway', 'paychoice')
                ),
                'cardtypes' => array(
                    'title' => __('Allowable Card Types', 'paychoice'),
                    'type' => 'multiselect',
                    'options' => $this->getCreditCardTypes(),
                    'description' => __('Allowable credit card types, hold ctrl/cmd to select multiple ', 'paychoice')
                )
            );
        }

        //Admin panel options
        public function admin_options()
        {
            echo '<h3>' . __('Paychoice Direct Payment', 'paychoice') . '</h3>';
            echo '<p>' . __('Paychoice Direct payment process transactions through Paychoice without leaving your site.', 'paychoice') . '</p>';
            echo '<table class="form-table">';
            $this -> generate_settings_html();
            echo '</table><!--/.form-table-->';
        }
        
        //Setup the payment field forms for the user
        public function payment_fields()
        {
            global $woocommerce; 
            
            $checkout = $woocommerce->checkout();
            
            // add available cards
            $card_select = "";
            $ccType = $this->getCreditCardTypes();
            
            //Manually adds the credit card as drop down list
            foreach($this->settings['cardtypes'] as $key => $value)
            {
                $card_select .= "<option value='".$value."' >".$ccType[$value]."</option>\n";
            }
            
            // create month options and select current month as default
            $expiry_month = "";
            for ($i=0; $i < 12; $i++){
                $month = sprintf('%02d', $i+1);
                if($month == date('m'))
                    $select = 'selected ';
                else
                    $select = '';
                $expiry_month .= "<option value='" . $month . "' " . $select . ">" . $month . "</option>\n";
            }    
            
            // create options for valid from and expires on years
            $current_year = date('y');
            $expiry_year = "";
            for($y = $current_year; $y < $current_year + 7; $y++){
                $year = sprintf('%02d', $y);
                $expiry_year .= "<option value='" . $year . "' " . $select . ">" . $year . "</option>\n";
            }
                
            ?>
           
            <table style="width: 75%;">
            <tbody>
            <tr>
            <td><label for="paychoice_fullname"><?php _e('Card Holder Name', 'paychoice') ?> <span class="required">*</span></label></td>
            <td><input id="paychoice_fullname" class="input-text" type="text" value="" placeholder="<?php _e('Fullname', 'paychoice') ?>" name="paychoice_fullname"></td>
            </tr>
            <tr>
            <td><label for="paychoice_cardtype"><?php _e('Card Type', 'paychoice') ?> <span class="required">*</span></label></td>
            <td>
                <select id="paychoice_cardtype" name="paychoice_cardtype">
                  <?php echo $card_select; ?>
                </select>
            </td>
            </tr>
            <tr>
            <td><label for="paychoice_cardnumber"><?php _e('Card Number', 'paychoice') ?> <span class="required">*</span></label></td>
            <td><input id="paychoice_cardnumber"  name="paychoice_cardnumber" class="input-text" autocomplete="off" type="text" value="" placeholder="<?php _e('Card Number', 'paychoice') ?>"></td>
            </tr>
            <tr>
            <td><label for="paychoice_expiry_date"><?php _e('Expiry Date', 'paychoice') ?> <span class="required">*</span></label></td>
            <td>
                <select id="paychoice_expiry_month" name="paychoice_expiry_month">
                    <?php echo $expiry_month; ?>
                </select>&nbsp;
                <select id="paychoice_expiry_year" name="paychoice_expiry_year">
                    <?php echo $expiry_year; ?>
                </select>
            </td>
            </tr>
            <tr>            
            <td><label for="paychoice_cvv"><?php _e('CVV', 'paychoice') ?> <span class="required">*</span></label></td>
            <td><input id="paychoice_cvv"  name="paychoice_cvv" maxlength="4" class="input-text" autocomplete="off" type="text" value="" placeholder="<?php _e('CVV', 'paychoice') ?>">
            <span><?php _e('CVV number.', 'paychoice') ?></span></td>            
            </tr>
            </tbody>
            </table> 

            <?php
        }

        //Function inherited from WC_Settings_API, validates checkout fields before processing payment
        function validate_fields()
        {
            global $woocommerce;
            
            if(empty($_POST['paychoice_fullname']))
            {
                $this->displayErrorMessage('Name required.');
            }
            if(empty($_POST['paychoice_cardnumber']))
            {
                $this->displayErrorMessage('Credit Card number required.');
            }
            if(empty($_POST['paychoice_cvv']))
            {
                $this->displayErrorMessage('CVV number required.');
            }
            
            if(!empty($_POST['paychoice_cardnumber']))
            {
                //Luhn algorithm
                if(!$this->is_valid_luhn($_POST['paychoice_cardnumber']))
                {
                    $this->displayErrorMessage('Invalid credit card number.');
                }else
                {
                    $ccTypeRegexp = $this->creditCardTypeRegexp();
                    //Validate credit card type
                    if(!preg_match($ccTypeRegexp[$_POST['paychoice_cardtype']],$_POST['paychoice_cardnumber']))
                    {
                        $this->displayErrorMessage('Card number does not match credit card type.');
                    }
                }
            }

            if(!empty($_POST['paychoice_cardnumber']))
            {
                if(!preg_match("/^\d{3,4}$/", $_POST['paychoice_cvv']))
                {
                    $this->displayErrorMessage('Invalid CVV number.');
                }
            }
                
            if($this->getWoocommerceErrorCount() == 0)
            {                                                        
                $this->validated = TRUE;
            }
            else
            {
                $this->validated = FALSE;
            }
        }

        //Just as the function name suggests
        function process_payment($order_id)
        {
            global $woocommerce;

            if(!$this->validated) return;
             
            $this->order = new WC_Order($order_id);
             
            $this->transaction_reference = $this->generateTransactionReference();
             
            $result = $this->processPaymentInfo();
            
            switch ($result)
            {
                case self::PAYMENT_SUCCESS:
                    $this->order->add_order_note( __('Payment completed', 'paychoice') . ' (Transaction reference: ' . $this->transaction_reference . ')' );
                    $woocommerce->cart->empty_cart();
                    $this->order->payment_complete();
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $this->order )
                    );
                    return;
                case self::PAYMENT_DISHONOUR:
                    $this->order->add_order_note( __('Payment dishonoured', 'paychoice') . ' (Transaction reference: ' . $this->transaction_reference . ')' );
                    $message = 'Your transaction has been declined. Please try again later';
                    $this->displayErrorMessage($message);
                    return;
                case self::PAYMENT_ERROR:
                default:
                    $message = 'There has been an error processing your payment. '.(isset($this->exception_message)?$this->exception_message:'');
                    $this->displayErrorMessage($message);
                    return;
            }
        }
        
        //Prepare the data prior sending to paychoice
        private function processPaymentInfo()
        {
            $requestData = array();
            
            $requestData["currency"] = $this->currency;
            $requestData["amount"] = $this->order->order_total;
            $requestData["reference"] = $this->transaction_reference;
            $requestData["card[name]"] = $_POST['paychoice_fullname'];
            $requestData["card[number]"] = $_POST['paychoice_cardnumber'];
            $requestData["card[expiry_month]"] = $_POST['paychoice_expiry_month'];
            $requestData["card[expiry_year]"] = $_POST['paychoice_expiry_year'];
            $requestData["card[cvv]"] = $_POST['paychoice_cvv'];
               
            $credentials = $this->api_username . ":" . $this->api_password;
            
            try{
               $response = $this->sendChargeRequest($credentials, $this->useSandbox, $requestData);
            }catch(PaychoiceException $e)
            {
                $this->exception_message = $e->getMessage();
                return self::PAYMENT_ERROR;
            }
            
            return $response->charge->status_code;
        }

        //Sends the charge request via curl to paychoice
        public function sendChargeRequest($credentials, $useSandbox, $requestData)
        {
            $headers = array();
        
            $environment = $useSandbox == true ? "sandbox" : "secure";
            $endPoint = "https://{$environment}.paychoice.com.au/api/v3/charge";
            
            // Initialise CURL and set base options
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded; charset=utf-8'));
            
            // Setup CURL request method
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->encodeData($requestData));
            // Setup CURL params for this request
            curl_setopt($curl, CURLOPT_URL, $endPoint);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, $credentials);  
    
            // Run CURL
            $response = curl_exec($curl);
            $error = curl_error($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
            $responseObject = json_decode($response);
            
            add_post_meta($this->order->id, 'paychoice-transaction-response', serialize($response));
            if (is_object($responseObject) && $responseObject->object_type == "error")
            {
                $errorParam = strlen($responseObject->error->param) > 0 ? ". Parameter: " . $responseObject->error->param : "";
                throw new PaychoiceException("Paychoice returned an error. " . $responseObject->error->message. ". Please try again");
            }
    
            // Check for CURL errors
            if (isset($error) && strlen($error))
            {
                throw new PaychoiceException("Could not successfully communicate with payment processor. Error: {$error}.");
            }
            else if (isset($responseCode) && strlen($responseCode))
            {
                switch($responseCode)
                {
                    case self::SERVER_RESPONSE_OK:
                        break;
                    case self::SERVER_ERROR:
                        throw new PaychoiceException("Could not successfully communicate with payment processor.");
                        break;
                    case self::SERVER_UNAUTHORIZED:
                        throw new PaychoiceException("Please contact admin.");
                        break;
                    case self::SERVER_PAYMENT_REQUIRED:
                         throw new PaychoiceException("Please try again");
                        //The payment information exists within the responseObject thus returning it
                        return; 
                    default:
                        throw new PaychoiceException("Please try again later");
                        break;
                }
            }
            return $responseObject;
        }

        
        //Encodes the data 
        private function encodeData($requestData)
        {
            if (!is_array($requestData))
            {
                throw new PaychoiceException("Request data is not in an array");
            }
    
            $formValues = "";
            foreach($requestData as $key=>$value)
            {
                $formValues .= $key.'='.urlencode($value).'&';
            }
            rtrim($formValues, '&');
    
            return $formValues;
        }
        
        //Generates a unique transaction reference number
        private function generateTransactionReference()
        {
            $datetime = date("ymdHis");
            return $datetime."-".uniqid();  
        }
    }

    function woocommerce_add_paychoice_gateway($methods)
    {
        $methods[] = 'WC_Paychoice_Gateway';
        return $methods;
    }
    
    //Built in paychoice exception class
    class PaychoiceException extends Exception
    {
    }
    
}
?>