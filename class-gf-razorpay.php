<?php

require_once ('razorpay-sdk/Razorpay.php');
use Razorpay\Api\Api;

GFForms::include_payment_addon_framework();

class GFRazorpay extends GFPaymentAddOn {

	protected $_version = GF_RAZORPAY_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'razorpay-gravity-forms';
	protected $_path = 'razorpay-gravity-forms/razorpay.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms Razorpay Add-On';
	protected $_short_title = 'Razorpay';
	protected $_supports_callbacks = true;

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_razorpay';
	protected $_capabilities_form_settings = 'gravityforms_razorpay';
	protected $_capabilities_uninstall = 'gravityforms_razorpay_uninstall';

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFRazorpay();
		}

		return self::$_instance;
	}

	private function __clone() {
	}

	public function init_frontend() {
		parent::init_frontend();

		add_action( 'gform_after_submission', array( $this, 'pay_using_razorpay'), 10, 2 );
	}

	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => '',
				'fields'      => array(
					array(
						'name'    => 'gf_razorpay_key',
						'label'   => esc_html__( 'Razorpay Key', 'razorpay-gravity-forms' ),
						'type'    => 'text',
						'class'   => 'medium',
					),
					array(
						'name'    => 'gf_razorpay_secret',
						'label'   => esc_html__( 'Razorpay Secret', 'razorpay-gravity-forms' ),
						'type'    => 'text',
						'class'   => 'medium',
					),
					array(
						'type' => 'save',
						'messages' => array(
							'success' => esc_html__( 'Settings have been updated.', 'razorpay-gravity-forms' )
						),
					),
				),
			),
		);
	}

	public function get_customer_fields_array($feed, $entry)
	{
		$fields = array();

		foreach ($this->get_customer_fields() as $field)
		{
			$field_id = $feed['meta'][ $field['meta_name'] ];

			$value = rgar( $entry, $field_id );

			$fields[$field['meta_name']] = $value;
		}

		return $fields;
	}

	public function get_customer_fields() {
		return array(
			array( 'name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName' ),
			array( 'name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName' ),
			array( 'name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email' ),
			array( 'name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address' ),
			array( 'name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2' ),
			array( 'name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city' ),
			array( 'name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state' ),
			array( 'name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip' ),
			array( 'name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country' ),
		);
	}

	/**
     * Generates the order form
     **/
    function generate_order_form($redirect_url, $json) {
        $html = <<<EOT
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    var data = $json;
</script>
<form name='razorpayform' action="$redirect_url" method="POST">
    <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
    <input type="hidden" name="razorpay_signature"  id="razorpay_signature" >
</form>
<p id="msg-razorpay-success" class="woocommerce-info woocommerce-message" style="display:none">
Please wait while we are processing your payment.
</p>
<p>
    <button id="btn-razorpay">Pay Now</button>
    <button id="btn-razorpay-cancel" onclick="document.razorpayform.submit()">Cancel</button>
</p>
<script>
    (function(){
    var setDisabled = function(id, state) {
      if (typeof state === 'undefined') {
        state = true;
      }
      var elem = document.getElementById(id);
      if (state === false) {
        elem.removeAttribute('disabled');
      }
      else {
        elem.setAttribute('disabled', state);
      }
    };
    // Payment was closed without handler getting called
    data.modal = {
      ondismiss: function() {
        setDisabled('btn-razorpay', false);
      }
    };
    data.handler = function(payment){
      setDisabled('btn-razorpay-cancel');
      var successMsg = document.getElementById('msg-razorpay-success');
      successMsg.style.display = "block";
      document.getElementById('razorpay_payment_id').value = payment.razorpay_payment_id;
      document.getElementById('razorpay_signature').value = payment.razorpay_signature;
      document.razorpayform.submit();
    };
    var razorpayCheckout = new Razorpay(data);
    // global method
    function openCheckout() {
      // Disable the pay button
      setDisabled('btn-razorpay');
      razorpayCheckout.open();
    }
    function addEvent(element, evnt, funct){
      if (element.attachEvent)
       return element.attachEvent('on'+evnt, funct);
      else
       return element.addEventListener(evnt, funct, false);
    }
    // Attach event listener
    addEvent(document.getElementById('btn-razorpay'), 'click', openCheckout);
    openCheckout();
})();
</script>
EOT;
            
        return $html;
    }

    public function callback() {
    	$entry_id = $_COOKIE['entry_id'];

    	$razorpay_order_id = $_COOKIE['razorpay_order_id'];

    	$entry = GFAPI::get_entry($entry_id);

    	$api = new Api($this->get_plugin_setting('gf_razorpay_key'), 
		 	$this->get_plugin_setting('gf_razorpay_secret'));

    	$attributes = array (
    		'razorpay_order_id'   => $razorpay_order_id,
    		'razorpay_payment_id' => rgpost('razorpay_payment_id'),
    		'razorpay_signature'  => rgpost('razorpay_signature'),
    	);

    	$success = false;

    	if ($entry  and !empty(rgpost('razorpay_payment_id')) and !empty(rgpost('razorpay_signature')))
    	{
    		try
        	{
            	$api->utility->verifyPaymentSignature($attributes);
            	$success = true;
        	}
        	catch (Errors\SignatureVerificationError $e)
        	{
            	$error .= $e->getMessage();
        	}
        }
        else
        {
        	$error = 'Payment Failed';
        }

        if ($success === true)
        {
        	$action['id']             = $attributes['razorpay_payment_id'];
			$action['type']           = 'complete_payment';
			$action['transaction_id'] = $attributes['razorpay_payment_id'];
			$action['amount']         = $entry['payment_amount'];
			$action['entry_id']       = $entry['id'];
        }
        else
        {
        	$action['id']             = $attributes['razorpay_payment_id'];
			$action['type']           = 'fail_payment';
			$action['transaction_id'] = $attributes['razorpay_payment_id'];
			$action['entry_id']       = $entry['id'];
			$action['amount']         = $entry['payment_amount'];
        }

        return $action;

    }

    public function post_callback($callback_action, $callback_result) {
		$entry               = GFAPI::get_entry($callback_action['entry_id']);
		$feed                = $this->get_payment_feed($entry);
		$transaction_id      = rgar($callback_action, 'transaction_id');
		$amount              = rgar($callback_action, 'amount');

		do_action('gform_razorpay_post_payment_' . $callback_action['type'],  $_POST, $entry, $feed);
    }

	public function generate_razorpay_form($entry, $customer_fields, $form){
		$feed = $this->get_payment_feed($entry, $form);

		$razorpay_args = array(
            'key'         => $this->get_plugin_setting('gf_razorpay_key'),
            'name'        => $form['name'],
            'amount'      => (int) round($entry['payment_amount']*100),
            'currency'    => $entry['currency'],
            'description' => $form['description'],
            'prefill'     => array(
            	'name'      => $customer_fields['billingInformation_firstName'],
                'email'     => $customer_fields['billingInformation_email'],
            ),
            'notes'       => array(
            	'gravity_forms_order_id' => $entry['id']
            ),
            'order_id'    => $entry['razorpay_order_id'],
        );

        $json = json_encode($razorpay_args);

        $redirect_url = '?page=gf_razorpay_callback';

        $html = $this->generate_order_form($redirect_url, $json);

        return $html;
	}

	public function is_callback_valid() 
	{
		if ( rgget( 'page' ) != 'gf_razorpay_callback' ) 
		{
			return false;
		}

		return true;
	}

	public function pay_using_razorpay($entry, $form)
	{
		$feed = $this->get_payment_feed($entry, $form);

		$customer_fields = $this->get_customer_fields_array($feed, $entry);

		$payment_amount = rgar( $entry, 'payment_amount' );

		if (empty($payment_amount) === true)
		{
			$payment_amount = GFCommon::get_order_total($form, $entry);
			gform_update_meta( $entry['id'], 'payment_amount', $payment_amount );
			$entry['payment_amount'] = $payment_amount;
		}

		$api = new Api($this->get_plugin_setting('gf_razorpay_key'), 
		 	$this->get_plugin_setting('gf_razorpay_secret'));

		$data = array(
            'receipt'         => $entry['id'],
            'amount'          => (int) round($payment_amount*100),
            'currency'        => $entry['currency'],
            'payment_capture' => 1
        );

		$razorpay_order = $api->order->create($data);

		gform_update_meta($entry['id'], 'razorpay_order_id', $razorpay_order['id']);

		$entry['razorpay_order_id'] = $razorpay_order['id'];

		GFAPI::update_entry($entry);

		setcookie('entry_id', $entry['id'], 
			time() + 31536000, COOKIEPATH, COOKIE_DOMAIN, false, true);
		setcookie('razorpay_order_id', $entry['razorpay_order_id'], 
			time() + 31536000, COOKIEPATH, COOKIE_DOMAIN, false, true);


		echo $this->generate_razorpay_form($entry, $customer_fields, $form);
	}

}