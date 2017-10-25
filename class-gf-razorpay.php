<?php

require_once ('razorpay-sdk/Razorpay.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

GFForms::include_payment_addon_framework();

class GFRazorpay extends GFPaymentAddOn
{
    const GF_RAZORPAY_KEY                  = 'gf_razorpay_key';
    const GF_RAZORPAY_SECRET               = 'gf_razorpay_secret';

    const RAZORPAY_ORDER_ID                = 'razorpay_order_id';
    const RAZORPAY_PAYMENT_ID              = 'razorpay_payment_id';
    const RAZORPAY_SIGNATURE               = 'razorpay_signature';

    //cookie set for one day
    const COOKIE_DURATION                  = 86400;

    const CUSTOMER_FIELDS_NAME             = 'name';
    const CUSTOMER_FIELDS_EMAIL            = 'email';
    const CUSTOMER_FIELDS_CONTACT          = 'contact';

    protected $_version                    = GF_RAZORPAY_VERSION;
    protected $_min_gravityforms_version   = '1.9.3';
    protected $_slug                       = 'razorpay-gravity-forms';
    protected $_path                       = 'razorpay-gravity-forms/razorpay.php';
    protected $_full_path                  = __FILE__;
    protected $_url                        = 'http://www.gravityforms.com';
    protected $_title                      = 'Gravity Forms Razorpay Add-On';
    protected $_short_title                = 'Razorpay';
    protected $_supports_callbacks         = true;
    public $_async_feed_processing         = false;

    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_razorpay';
    protected $_capabilities_form_settings = 'gravityforms_razorpay';
    protected $_capabilities_uninstall     = 'gravityforms_razorpay_uninstall';

    // Automatic upgrade enabled
    protected $_enable_rg_autoupgrade      = true;

    private static $_instance              = null;

    public static function get_instance()
    {
        if (self::$_instance === null)
        {
            self::$_instance = new GFRazorpay();
        }

        return self::$_instance;
    }

    public function init_frontend()
    {
        parent::init_frontend();

        add_action('gform_after_submission', array($this, 'pay_using_razorpay'), 10, 2);
    }

    public function plugin_settings_fields()
    {
        return array(
            array(
                'title'               => 'razorpay_settings',
                'fields'              => array(
                    array(
                        'name'        => self::GF_RAZORPAY_KEY,
                        'label'       => esc_html__('Razorpay Key', 'razorpay-gravity-forms'),
                        'type'        => 'text',
                        'class'       => 'medium',
                    ),
                    array(
                        'name'        => self::GF_RAZORPAY_SECRET,
                        'label'       => esc_html__('Razorpay Secret', 'razorpay-gravity-forms' ),
                        'type'        => 'text',
                        'class'       => 'medium',
                    ),
                    array(
                        'type'        => 'save',
                        'messages'    => array(
                            'success' => esc_html__('Settings have been updated.', 'razorpay-gravity-forms' )
                        ),
                    ),
                ),
            ),
        );
    }

    public function get_customer_fields($form, $feed, $entry)
    {
        $fields = array();

        $billing_fields = $this->billing_info_fields();

        foreach ($billing_fields as $field)
        {
            $field_id = $feed['meta']['billingInformation_' . $field['name']];

            $value = $this->get_field_value($form, $entry, $field_id);

            $fields[$field['name']] = $value;
        }

        return $fields;
    }

    public function callback()
    {
        $razorpayOrderId = $_COOKIE[self::RAZORPAY_ORDER_ID];

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $secret = $this->get_plugin_setting(self::GF_RAZORPAY_SECRET);

        $api = new Api($key, $secret);

        try
        {
            $order = $api->order->fetch($razorpayOrderId);
        }
        catch (\Exception $e)
        {
            $action = array(
                'type'  => 'fail_payment',
                'error' => $e->getMessage()
            );

            return $action;
        }

        $entryId = $order['receipt'];

        $entry = GFAPI::get_entry($entryId);

        $attributes = $this->get_callback_attributes();

        $action = array(
            'id'             => $attributes[self::RAZORPAY_PAYMENT_ID],
            'type'           => 'fail_payment',
            'transaction_id' => $attributes[self::RAZORPAY_PAYMENT_ID],
            'amount'         => $entry['payment_amount'],
            'entry_id'       => $entry['id'],
            'error'          => 'Payment Failed',
        );

        $success = false;

        if ((empty($entry) === false) and
            (empty($attributes[self::RAZORPAY_PAYMENT_ID]) === false) and
            (empty($attributes[self::RAZORPAY_SIGNATURE]) ===false))
        {
            try
            {
                $api->utility->verifyPaymentSignature($attributes);

                $success = true;
            }
            catch (Errors\SignatureVerificationError $e)
            {
                $action['error'] = $e->getMessage();

                return $action;
            }
        }

        if ($success === true)
        {
            $action['type'] = 'complete_payment';

            $action['error'] = null;
        }

        return $action;
    }

    public function get_callback_attributes()
    {
        return array(
            self::RAZORPAY_ORDER_ID   => $_COOKIE[self::RAZORPAY_ORDER_ID],
            self::RAZORPAY_PAYMENT_ID => sanitize_text_field(rgpost(self::RAZORPAY_PAYMENT_ID)),
            self::RAZORPAY_SIGNATURE  => sanitize_text_field(rgpost(self::RAZORPAY_SIGNATURE)),
        );
    }

    public function post_callback($callback_action, $callback_result)
    {
        $entry = null;

        $feed = null;

        if (isset($callback_action['entry_id']) === true)
        {
            $entry = GFAPI::get_entry($callback_action['entry_id']);

            $feed  = $this->get_payment_feed($entry);
        }
        if ($callback_action['type'] === 'fail_payment')
        {
            do_action('gform_razorpay_fail_payment', $entry, $feed);

            echo $callback_action['error'];
        }
        else
        {
            do_action('gform_razorpay_complete_payment', $callback_action['transaction_id'],
                $callback_action['amount'], $entry, $feed);

            echo ' Payment Successful. You transaction_id is ' . $callback_action['transaction_id'];
        }
    }

    public function generate_razorpay_form($entry, $form)
    {

        $feed = $this->get_payment_feed($entry, $form);

        $customerFields = $this->get_customer_fields($form, $feed, $entry);

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $razorpayArgs = array(
            'key'         => $key,
            'name'        => get_bloginfo('name'),
            'amount'      => (int) round($entry['payment_amount'] * 100),
            'currency'    => $entry['currency'],
            'description' => $form['description'],
            'prefill'     => array(
                'name'    => $customerFields[self::CUSTOMER_FIELDS_NAME],
                'email'   => $customerFields[self::CUSTOMER_FIELDS_EMAIL],
                'contact' => $customerFields[self::CUSTOMER_FIELDS_CONTACT],
            ),
            'notes'       => array(
                'gravity_forms_order_id' => $entry['id']
            ),
            'order_id'    => $entry[self::RAZORPAY_ORDER_ID],
        );

        wp_enqueue_script('razorpay_script',
                          plugin_dir_url(__FILE__). 'script.js',
                          array('checkout')
        );

        wp_localize_script('razorpay_script',
                           'razorpay_script_vars',
                           array(
                               'data' => $razorpayArgs
                           )
        );

        wp_register_script('checkout',
                           'https://checkout.razorpay.com/v1/checkout.js',
                           null,
                           null
        );

        wp_enqueue_script('checkout');

        $redirect_url = '?page=gf_razorpay_callback';

        return $this->generate_order_form($redirect_url);
    }

    function generate_order_form($redirect_url)
    {
        $html = <<<EOT
<form id ='razorpayform' name='razorpayform' action="$redirect_url" method='POST'>
    <input type='hidden' name='razorpay_payment_id' id='razorpay_payment_id'>
    <input type='hidden' name='razorpay_signature'  id='razorpay_signature' >
</form>
<p id='msg-razorpay-success'  style='display:none'>
    Please wait while we are processing your payment.
</p>
<p>
    <button id='btn-razorpay'>Pay With Razorpay</button>
    <button id='btn-razorpay-cancel' onclick='document.razorpayform.submit()'>Cancel</button>
</p>
EOT;
        return $html;
    }

    public function is_callback_valid()
    {
        // Will check if the return url is valid
        if (rgget('page') !== 'gf_razorpay_callback')
        {
            return false;
        }

        return true;
    }

    public function pay_using_razorpay($entry, $form)
    {
        //gravity form method to get value of payment_amount key from entry
        $paymentAmount = rgar($entry, 'payment_amount' );

        //It will be null first time in the entry
        if (empty($paymentAmount) === true)
        {
            $paymentAmount = GFCommon::get_order_total($form, $entry);
            gform_update_meta($entry['id'], 'payment_amount', $paymentAmount);
            $entry['payment_amount'] = $paymentAmount;
        }

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $secret = $this->get_plugin_setting(self::GF_RAZORPAY_SECRET);

        $api = new Api($key, $secret);

        $data = array(
            'receipt'         => $entry['id'],
            'amount'          => (int) round($paymentAmount * 100),
            'currency'        => $entry['currency'],
            'payment_capture' => 1
        );

        $razorpayOrder = $api->order->create($data);

        gform_update_meta($entry['id'], self::RAZORPAY_ORDER_ID, $razorpayOrder['id']);

        $entry[self::RAZORPAY_ORDER_ID] = $razorpayOrder['id'];

        GFAPI::update_entry($entry);

        setcookie(self::RAZORPAY_ORDER_ID, $entry[self::RAZORPAY_ORDER_ID],
            time() + self::COOKIE_DURATION, COOKIEPATH, COOKIE_DOMAIN, false, true);

        echo $this->generate_razorpay_form($entry, $form);
    }

    public function billing_info_fields()
    {
        $fields = array(
            array( 'name' => self::CUSTOMER_FIELDS_NAME, 'label' => esc_html__( 'Name', 'gravityforms' ), 'required' => false ),
            array( 'name' => self::CUSTOMER_FIELDS_EMAIL, 'label' => esc_html__( 'Email', 'gravityforms' ), 'required' => false ),
            array( 'name' => self::CUSTOMER_FIELDS_CONTACT, 'label' => esc_html__( 'Phone', 'gravityforms' ), 'required' => false ),
        );

        return $fields;
    }
}
