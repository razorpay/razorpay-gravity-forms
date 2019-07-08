<?php

require_once ('razorpay-sdk/Razorpay.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

GFForms::include_payment_addon_framework();

class GFRazorpay extends GFPaymentAddOn
{
    /**
     * Razorpay plugin config key ID and key secret
     */
    const GF_RAZORPAY_KEY                  = 'gf_razorpay_key';
    const GF_RAZORPAY_SECRET               = 'gf_razorpay_secret';

    /**
     * Razorpay API attributes
     */
    const RAZORPAY_ORDER_ID                = 'razorpay_order_id';
    const RAZORPAY_PAYMENT_ID              = 'razorpay_payment_id';
    const RAZORPAY_SIGNATURE               = 'razorpay_signature';

    /**
     * Cookie set for one day
     */
    const COOKIE_DURATION                  = 86400;

    /**
     * Customer related fields
     */
    const CUSTOMER_FIELDS_NAME             = 'name';
    const CUSTOMER_FIELDS_EMAIL            = 'email';
    const CUSTOMER_FIELDS_CONTACT          = 'contact';

    // TODO: Check if all the variables below are needed

    /**
     * @var string Version of current plugin
     */
    protected $_version                    = GF_RAZORPAY_VERSION;

    /**
     * @var string Minimum version of gravity forms
     */
    protected $_min_gravityforms_version   = '1.9.3';

    /**
     * @var string URL-friendly identifier used for form settings, add-on settings, text domain localization...
     */
    protected $_slug                       = 'razorpay-gravity-forms';

    /**
     * @var string Relative path to the plugin from the plugins folder. Example "gravityforms/gravityforms.php"
     */
    protected $_path                       = 'razorpay-gravity-forms/razorpay.php';

    /**
     * @var string Full path the the plugin. Example: __FILE__
     */
    protected $_full_path                  = __FILE__;

    /**
     * @var string URL to the Gravity Forms website. Example: 'http://www.gravityforms.com' OR affiliate link.
     */
    protected $_url                        = 'http://www.gravityforms.com';

    /**
     * @var string Title of the plugin to be used on the settings page, form settings and plugins page. Example: 'Gravity Forms MailChimp Add-On'
     */
    protected $_title                      = 'Gravity Forms Razorpay Add-On';

    /**
     * @var string Short version of the plugin title to be used on menus and other places where a less verbose string is useful. Example: 'MailChimp'
     */
    protected $_short_title                = 'Razorpay';

    /**
     * Defines if the payment add-on supports callbacks.
     *
     * If set to true, callbacks/webhooks/IPN will be enabled and the appropriate database table will be created.
     *
     * @since  Unknown
     * @access protected
     *
     * @used-by GFPaymentAddOn::upgrade_payment()
     *
     * @var bool True if the add-on supports callbacks. Otherwise, false.
     */
    protected $_supports_callbacks         = true;

    /**
     * If true, feeds will be processed asynchronously in the background.
     *
     * @since 2.2
     * @var bool
     */
    public $_async_feed_processing         = false;

    // --------------------------------------------- Permissions Start -------------------------------------------------

    /**
     * @var string|array A string or an array of capabilities or roles that have access to the settings page
     */
    protected $_capabilities_settings_page = 'gravityforms_razorpay';

    /**
     * @var string|array A string or an array of capabilities or roles that have access to the form settings
     */
    protected $_capabilities_form_settings = 'gravityforms_razorpay';

    /**
     * @var string|array A string or an array of capabilities or roles that can uninstall the plugin
     */
    protected $_capabilities_uninstall     = 'gravityforms_razorpay_uninstall';

    // --------------------------------------------- Permissions End ---------------------------------------------------

    /**
     * @var bool Used by Rocketgenius plugins to activate auto-upgrade.
     * @ignore
     */
    protected $_enable_rg_autoupgrade      = true;

    /**
     * @var GFRazorpay
     */
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

        add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );

        add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
    }

    public function delay_post( $is_disabled, $form, $entry )
    {
		$feed            = $this->get_payment_feed( $entry );

		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! $feed || empty( $submission_data['payment_amount'] ) )
		{
			return $is_disabled;
		}

		return ! rgempty( 'delayPost', $feed['meta'] );
	}

	public function delay_notification( $is_disabled, $notification, $form, $entry )
    {
		if ( rgar( $notification, 'event' ) != 'form_submission' )
		{
			return $is_disabled;
		}

		$feed            = $this->get_payment_feed( $entry );

		$submission_data = $this->get_submission_data( $feed, $form, $entry );

		if ( ! $feed || empty( $submission_data['payment_amount'] ) )
		{
			return $is_disabled;
		}

		$selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();

		return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
	}

    public function plugin_settings_fields()
    {
        return array(
            array(
                'title'               => 'razorpay_settings',
                'fields'              => array(
                    array(
                        'name'        => self::GF_RAZORPAY_KEY,
                        'label'       => esc_html__('Razorpay Key', $this->_slug),
                        'type'        => 'text',
                        'class'       => 'medium',
                    ),
                    array(
                        'name'        => self::GF_RAZORPAY_SECRET,
                        'label'       => esc_html__('Razorpay Secret', $this->_slug),
                        'type'        => 'text',
                        'class'       => 'medium',
                    ),
                    array(
                        'type'        => 'save',
                        'messages'    => array(
                            'success' => esc_html__('Settings have been updated.', $this->_slug)
                        ),
                    ),
                ),
            ),
        );
    }


    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();

        if ( ! rgar( $settings, self::GF_RAZORPAY_KEY ) || ! rgar( $settings, self::GF_RAZORPAY_SECRET ) )
        {
            return sprintf( esc_html__( 'To get started, please configure your %Razorpay Settings%s!', $this->_slug ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
        }
        else {
            return parent::feed_list_no_item_message();
        }
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

    public function redirect_url( $feed, $submission_data, $form, $entry )
    {
        //updating lead's payment_status to Processing
        GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );

        $this->generate_razorpay_order($entry, $form);
    }

    public function generate_razorpay_order($entry, $form)
    {
        //gravity form method to get value of payment_amount key from entry
        $paymentAmount = rgar($entry, 'payment_amount');

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
            'amount'          => $paymentAmount * 100,
            'currency'        => $entry['currency'],
            'payment_capture' => 1
        );

        $razorpayOrder = $api->order->create($data);

        gform_update_meta($entry['id'], self::RAZORPAY_ORDER_ID, $razorpayOrder['id']);

        $entry[self::RAZORPAY_ORDER_ID] = $razorpayOrder['id'];

        GFAPI::update_entry($entry);

        setcookie(
            self::RAZORPAY_ORDER_ID,
            $entry[self::RAZORPAY_ORDER_ID],
            time() + self::COOKIE_DURATION,
            COOKIEPATH,
            COOKIE_DOMAIN,
            false,
            true
        );

        echo $this->generate_razorpay_form($entry, $form);
    }

    public function generate_razorpay_form($entry, $form)
    {
        GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );

        $feed = $this->get_payment_feed($entry, $form);

        $customerFields = $this->get_customer_fields($form, $feed, $entry);

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $razorpayArgs = array(
            'key'           => $key,
            'name'          => $this->get_plugin_setting('gf_razorpay_company_name'),
            'amount'        => $entry['payment_amount'] * 100,
            'currency'      => $entry['currency'],
            'description'   => $form['description'],
            'prefill'       => array(
                'name'      => $customerFields[self::CUSTOMER_FIELDS_NAME],
                'email'     => $customerFields[self::CUSTOMER_FIELDS_EMAIL],
                'contact'   => $customerFields[self::CUSTOMER_FIELDS_CONTACT],
            ),
            'notes'         => array(
                'gravity_forms_order_id' => $entry['id']
            ),
            'order_id'      => $entry[self::RAZORPAY_ORDER_ID],
        );

        wp_enqueue_script('razorpay_script',
                            plugin_dir_url(__FILE__) . 'script.js',
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

        $redirect_url = '?page=' . $this->_slug;

        return $this->generate_order_form($redirect_url);
    }

    public function generate_order_form($redirect_url)
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
            (empty($attributes[self::RAZORPAY_SIGNATURE]) === false))
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
        if ( is_wp_error( $callback_action ) || ! $callback_action )
        {
                return false;
        }

        $entry          = GFAPI::get_entry( $callback_action['entry_id'] );

        $feed           = $this->get_payment_feed( $entry );

        $transaction_id = rgar( $callback_action, 'transaction_id' );

        $amount         = rgar( $callback_action, 'amount' );

        $id             = rgar( $callback_action, 'id' );

        $status         = rgar( $callback_action, 'type' );

        if ( rgar( $callback_action, 'type' ) === 'complete_payment' )
        {
            do_action('gform_razorpay_complete_payment', $callback_action['transaction_id'], $callback_action['amount'], $entry, $feed);

            if ( has_filter( 'gform_razorpay_complete_payment' ) ) {
                $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_razorpay_complete_payment.' );
            }
            echo ' Payment Successful. You transaction_id is ' . $callback_action['transaction_id'];
            $this->fulfill_order( $entry, $transaction_id, $amount, $feed );
        }
        else
        {
            GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Failed' );

            do_action('gform_razorpay_fail_payment', $entry, $feed);

            if ( has_filter( 'gform_razorpay_fail_payment' ) ) {
                $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_razorpay_fail_payment.' );
            }
        }
    }

    public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null )
    {
        if ( ! $feed ) {
            $feed = $this->get_payment_feed( $entry );
        }

        $form = GFFormsModel::get_form_meta( $entry['form_id'] );

        if ( rgars( $feed, 'meta/delayPost' ) )
        {
            $this->log_debug( __METHOD__ . '(): Creating post.' );

            $entry['post_id'] = GFFormsModel::create_post( $form, $entry );

            $this->log_debug( __METHOD__ . '(): Post created.' );
        }

        if ( rgars( $feed, 'meta/delayNotification' ) ) {
            //sending delayed notifications
            $notifications = $this->get_notifications_to_send( $form, $feed );

            GFCommon::send_notifications( $notifications, $form, $entry, true, 'form_submission' );
        }

        do_action( 'gform_razorpay_fulfillment', $entry, $feed, $transaction_id, $amount );

        if ( has_filter( 'gform_razorpay_fulfillment' ) )
        {
            $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_razorpay_fulfillment.' );
        }

    }

    public function is_callback_valid()
    {
        // Will check if the return url is valid
        if (rgget('page') !== $this->_slug) {
            return false;
        }

        return true;
    }

    public function billing_info_fields()
    {
        $fields = array(
            array('name' => self::CUSTOMER_FIELDS_NAME, 'label' => esc_html__('Name', 'gravityforms'), 'required' => false),
            array('name' => self::CUSTOMER_FIELDS_EMAIL, 'label' => esc_html__('Email', 'gravityforms'), 'required' => false),
            array('name' => self::CUSTOMER_FIELDS_CONTACT, 'label' => esc_html__('Phone', 'gravityforms'), 'required' => false),
        );

        return $fields;
    }

    public function init()
    {

        add_filter( 'gform_notification_events', array( $this, 'notification_events' ), 10, 2 );

        add_filter( 'gform_post_payment_action', array( $this, 'post_payment_action' ), 10, 2 );

        // Supports frontend feeds.
        $this->_supports_frontend_feeds = true;

        parent::init();

    }

    // Added custom event to provide option to chose event to send notifications.
    public function notification_events($notification_events, $form)
    {
        $has_razorpay_feed = function_exists( 'gf_razorpay' ) ? gf_razorpay()->get_feeds( $form['id'] ) : false;

        if ($has_razorpay_feed) {
            $payment_events = array(
                'complete_payment'          => __('Payment Completed', 'gravityforms'),
                'refund_payment'            => __('Payment Refunded', 'gravityforms'),
                'fail_payment'              => __('Payment Failed', 'gravityforms'),
                'add_pending_payment'       => __('Payment Pending', 'gravityforms'),
                'void_authorization'        => __('Authorization Voided', 'gravityforms'),
                'create_subscription'       => __('Subscription Created', 'gravityforms'),
                'cancel_subscription'       => __('Subscription Canceled', 'gravityforms'),
                'expire_subscription'       => __('Subscription Expired', 'gravityforms'),
                'add_subscription_payment'  => __('Subscription Payment Added', 'gravityforms'),
                'fail_subscription_payment' => __('Subscription Payment Failed', 'gravityforms'),
            );

          return array_merge($notification_events, $payment_events);
        }

        return $notification_events;

    }

    public function post_payment_action($entry, $action)
    {
        $form = GFAPI::get_form( $entry['form_id'] );

        GFAPI::send_notifications( $form, $entry, rgar( $action, 'type' ) );
    }
}

?>