<?php

require_once ('razorpay-sdk/Razorpay.php');
use Razorpay\Api\Api;
use Razorpay\Api\Errors;

GFForms::include_payment_addon_framework();

class GFRazorpay extends GFPaymentAddOn
{
    protected $_version                    = GF_RAZORPAY_VERSION;
    protected $_min_gravityforms_version   = '1.9.3';
    protected $_slug                       = 'razorpay-gravity-forms';
    protected $_path                       = 'razorpay-gravity-forms/razorpay.php';
    protected $_full_path                  = __FILE__;
    protected $_url                        = 'http://www.gravityforms.com';
    protected $_title                      = 'Gravity Forms Razorpay Add-On';
    protected $_short_title                = 'Razorpay';
    protected $_supports_callbacks         = true;

    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_razorpay';
    protected $_capabilities_form_settings = 'gravityforms_razorpay';
    protected $_capabilities_uninstall     = 'gravityforms_razorpay_uninstall';

    // Automatic upgrade enabled
    protected $_enable_rg_autoupgrade      = true;

    private static $_instance              = null;

    const GF_RAZORPAY_KEY                  = 'gf_razorpay_key';
    const GF_RAZORPAY_SECRET               = 'gf_razorpay_secret';
    const RAZORPAY_ORDER_ID                = 'razorpay_order_id';

    //cookie set for one day
    const COOKIE_DURATION                  = 86400;

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

    public function get_customer_fields_array($feed, $entry)
    {
        $fields = array();

        $customerFields = $this->get_customer_fields();

        foreach ($customerFields as $field)
        {
            $fieldId = $feed['meta'][$field['meta_name']];

            $value = rgar($entry, $fieldId);

            $fields[$field['meta_name']] = $value;
        }

        return $fields;
    }

    public function get_customer_fields()
    {
        return array(
            array(
                'name'      => 'first_name',
                'label'     => 'First Name',
                'meta_name' => 'billingInformation_firstName'
            ),
            array(
                'name'      => 'last_name',
                'label'     => 'Last Name',
                'meta_name' => 'billingInformation_lastName'
            ),
            array(
                'name'      => 'email',
                'label'     => 'Email',
                'meta_name' => 'billingInformation_email'
            ),
            array(
                'name'      => 'address1',
                'label'     => 'Address',
                'meta_name' => 'billingInformation_address'
            ),
            array(
                'name'      => 'address2',
                'label'     => 'Address 2',
                'meta_name' => 'billingInformation_address2'
            ),
            array(
                'name'      => 'city',
                'label'     => 'City',
                'meta_name' => 'billingInformation_city'
            ),
            array(
                'name'      => 'state',
                'label'     => 'State',
                'meta_name' => 'billingInformation_state'
            ),
            array(
                'name'      => 'zip',
                'label'     => 'Zip',
                'meta_name' => 'billingInformation_zip'
            ),
            array(
                'name'      => 'country',
                'label'     => 'Country',
                'meta_name' => 'billingInformation_country'
            ),
        );
    }

    public function callback()
    {
        $razorpayOrderId = $_COOKIE[self::RAZORPAY_ORDER_ID];

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $secret = $this->get_plugin_setting(self::GF_RAZORPAY_SECRET);

        $api = new Api($key, $secret);

        $order = $api->order->fetch($razorpayOrderId);

        $entryId = $order['receipt'];

        $entry = GFAPI::get_entry($entryId);

        $attributes = $this->get_callback_attributes();

        $success = false;

        $error = 'Payment Failed';

        if ((empty($entry) === false) and
            (empty($attributes['razorpay_payment_id']) === false) and
            (empty($attributes['razorpay_signature']) ===false))
        {
            try
            {
                $api->utility->verifyPaymentSignature($attributes);
                $success = true;
            }
            catch (Errors\SignatureVerificationError $e)
            {
                $error = $e->getMessage();
            }
        }

        $action = array(
            'id'             => $attributes['razorpay_payment_id'],
            'type'           => 'complete_payment',
            'transaction_id' => $attributes['razorpay_payment_id'],
            'amount'         => $entry['payment_amount'],
            'entry_id'       => $entry['id']
        );

        if ($success === false)
        {
            $action['type'] = 'fail_payment';

            $action['error'] = $error;
        }

        return $action;
    }

    public function get_callback_attributes()
    {
        return array(
            'razorpay_order_id'   => $_COOKIE[self::RAZORPAY_ORDER_ID],
            'razorpay_payment_id' => sanitize_text_field(rgpost('razorpay_payment_id')),
            'razorpay_signature'  => sanitize_text_field(rgpost('razorpay_signature')),
        );
    }

    public function post_callback($callback_action, $callback_result)
    {
        $entry = GFAPI::get_entry($callback_action['entry_id']);

        $feed  = $this->get_payment_feed($entry );

        do_action('gform_razorpay_post_payment', $callback_action,  $_POST, $entry, $feed);
    }

    public function generate_razorpay_form($entry, $customerFields, $form)
    {
        $feed = $this->get_payment_feed($entry, $form);

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $razorpayArgs = array(
            'key'         => $key,
            'name'        => $form['name'],
            'amount'      => (int) round($entry['payment_amount'] * 100),
            'currency'    => $entry['currency'],
            'description' => $form['description'],
            'prefill'     => array(
                'name'    => $customerFields['billingInformation_firstName'],
                'email'   => $customerFields['billingInformation_email'],
            ),
            'notes'       => array(
                'gravity_forms_order_id' => $entry['id']
            ),
            'order_id'    => $entry['razorpay_order_id'],
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

        $redirectUrl = '?page=gf_razorpay_callback';

        return $this->generate_order_form($redirectUrl);
    }

    function generate_order_form($redirectUrl)
    {
        $html = <<<EOT
<form id ='razorpayform' name='razorpayform' action="$redirectUrl" method='POST'>
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
        $feed = $this->get_payment_feed($entry, $form);

        $customerFields = $this->get_customer_fields_array($feed, $entry);

        //gravity form method to get value of payment_amount key from entry
        $paymentAmount = rgar($entry, 'payment_amount' );

        //It will be null first time in the entry
        if (empty($paymentAmount) === true)
        {
            $paymentAmount = GFCommon::get_order_total($form, $entry);
            gform_update_meta($entry['id'], 'payment_amount', $paymentAmount);
            $entry['payment_amount'] = $paymentAmount;
        }

        $key = $this->get_plugin_setting('gf_razorpay_key');

        $secret = $this->get_plugin_setting('gf_razorpay_secret');

        $api = new Api($key, $secret);

        $data = array(
            'receipt'         => $entry['id'],
            'amount'          => (int) round($paymentAmount * 100),
            'currency'        => $entry['currency'],
            'payment_capture' => 1
        );

        $razorpayOrder = $api->order->create($data);

        gform_update_meta($entry['id'], 'razorpay_order_id', $razorpayOrder['id']);

        $entry['razorpay_order_id'] = $razorpayOrder['id'];

        GFAPI::update_entry($entry);

        setcookie(self::RAZORPAY_ORDER_ID, $entry['razorpay_order_id'],
            time() + self::COOKIE_DURATION, COOKIEPATH, COOKIE_DOMAIN, false, true);

        echo $this->generate_razorpay_form($entry, $customerFields, $form);
    }
}
