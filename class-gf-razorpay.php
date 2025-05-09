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
    const GF_RAZORPAY_PAYMENT_ACTION       = 'gf_razorpay_payment_action';
    const GF_RAZORPAY_WEBHOOK_SECRET       = 'gf_razorpay_webhook_secret';
    const GF_RAZORPAY_WEBHOOK_ENABLED_AT   = 'gf_razorpay_webhook_enable_at';

    /**
     * Razorpay API attributes
     */
    const RAZORPAY_ORDER_ID                = 'razorpay_order_id';
    const RAZORPAY_PAYMENT_ID              = 'razorpay_payment_id';
    const RAZORPAY_SIGNATURE               = 'razorpay_signature';
    const CAPTURE                          = 'capture';
    const AUTHORIZE                        = 'authorize';
    const ORDER_PAID                       = 'order.paid';

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

    /**
     * Order status for rzp_gf_webhook_triggers table
     */
    const RZP_ORDER_CREATED = 0;
    const RZP_ORDER_PROCESSED_BY_CALLBACK = 1;

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

    protected $supportedWebhookEvents  = array(
        'order.paid'
    );

    protected $defaultWebhookEvents = array(
        'order.paid' => true
    );

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
        add_filter('gform_confirmation', array($this, 'generate_razorpay_order'), 10, 4);
    }

    public function plugin_settings_fields()
    {
        $webhookUrl = esc_url(admin_url('admin-post.php')) . '?action=gf_razorpay_webhook';

        return array(
            array(
                'title'               => 'Razorpay Settings',
                'description'         => __('First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=gravityform" target="_blank">signup</a> for a 
                Razorpay account or <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=gravityform" target="_blank">login</a> if you have an existing account.'),
                'fields'              => array(
                    array(
                        'name'        => self::GF_RAZORPAY_KEY,
                        'label'       => esc_html__('Razorpay Key', $this->_slug),
                        'type'        => 'text',
                        'class'       => 'medium',
                        'feedback_callback' => array($this, 'auto_enable_webhook'),
                    ),
                    array(
                        'name'        => self::GF_RAZORPAY_SECRET,
                        'label'       => esc_html__('Razorpay Secret', $this->_slug),
                        'type'        => 'text',
                        'class'       => 'medium',
                    ),
                    array(
                        'name'   => self::GF_RAZORPAY_PAYMENT_ACTION,
                        'label' => esc_html__('Payment Action', 'razorpay'),
                        'tooltip' => esc_html__('Payment action on order complete.', $this->_slug),
                        'type' => 'select',
                        'size' => 'regular',
                        'default' => self::CAPTURE,
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Authorize and Capture', $this->_slug ),
                                'value' => self::CAPTURE
                            ),
                            array(
                                'label' => esc_html__( 'Authorize', $this->_slug ),
                                'value' => self::AUTHORIZE
                            ),
                        )
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

        if ((strtolower($entry['payment_status']) === 'paid') and
            (strtolower($entry['payment_method']) === 'razorpay'))
        {
            $action = array(
                'id'             => $entry['transaction_id'],
                'type'           => 'complete_payment',
                'transaction_id' => $entry['transaction_id'],
                'amount'         => $entry['payment_amount'],
                'payment_method' => 'razorpay',
                'entry_id'       => $entry['id'],
                'error'          => null,
            );

            return $action;
        }

        $action = array(
            'id'             => $attributes[self::RAZORPAY_PAYMENT_ID],
            'type'           => 'fail_payment',
            'transaction_id' => $attributes[self::RAZORPAY_PAYMENT_ID],
            'amount'         => $entry['payment_amount'],
            'payment_method' => 'razorpay',
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

        // update order status in webhook table
        global $wpdb;

        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

        $wpdb->update(
            $wpdb->prefix . 'rzp_gf_webhook_triggers',
            array(
                'rzp_update_order_cron_status' => self::RZP_ORDER_PROCESSED_BY_CALLBACK
            ),
            array(
                'order_id'      => $entryId,
                'rzp_order_id'  => $razorpayOrderId
            )
        );

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
        if (is_wp_error( $callback_action ) || ! $callback_action)
        {
            return false;
        }

        $entry = null;

        $feed = null;

        $ref_id    = url_to_postid(wp_get_referer());
        $ref_title = $ref_id > 0 ? get_the_title($ref_id): "Home";
        $ref_url   = get_home_url();
        $form_id   = 0;

        if (isset($callback_action['entry_id']) === true)
        {
            $entry          = GFAPI::get_entry($callback_action['entry_id']);
            $feed           = $this->get_payment_feed($entry);
            $transaction_id = rgar($callback_action, 'transaction_id');
            $amount         = rgar($callback_action, 'amount');
            $status         = rgar($callback_action, 'type');
            $ref_url        = $entry['source_url'];
            $form_id        = $entry['form_id'];
        }

        if ($status === 'complete_payment')
        {
            do_action('gform_razorpay_complete_payment', $callback_action['transaction_id'], $callback_action['amount'], $entry, $feed);
        }
        else
        {
            do_action('gform_razorpay_fail_payment', $entry, $feed);
        }

        $form = GFAPI::get_form($form_id);

        if ( ! class_exists( 'GFFormDisplay' ) ) {
            require_once( GFCommon::get_base_path() . '/form_display.php' );
        }

        $form = GFFormDisplay::update_confirmation($form, $entry);

        if (rgar($form['confirmation'], 'type') == 'message')
        {
            $confirmation = GFFormDisplay::get_confirmation_message($form['confirmation'], $form, $entry, []);
        }
        else
        {
            $confirmation = array('redirect' => GFFormDisplay::get_confirmation_url($form['confirmation'], $form, $entry));
        }

        if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
            header( "Location: {$confirmation['redirect']}" );                   // nosemgrep : php.lang.security.non-literal-header.non-literal-header
            exit;
        }

        ?>
        <head> <link rel="stylesheet" type="text/css" href="<?php echo plugin_dir_url(__FILE__) .'assets/css/style.css';?>" ><script type="text/javascript" src="<?php echo plugin_dir_url(__FILE__) .'assets/js/script.js'?>" ></script> </head>
        <body>
        <div class="invoice-box">
            <table cellpadding="0" cellspacing="0">
                <tr class="top">
                    <td colspan="2">
                        <table> <tr> <td class="title"> <img src="https://razorpay.com/assets/razorpay-logo.svg" style="width:100%; max-width:300px;margin-left:30%"> </td>
                        </tr></table>
                    </td>
                </tr>
                <tr class="heading"> <td> Payment Details </td><td> Value </td></tr>
                <tr class="item"> <td> Status </td><td> <?php echo $status == 'complete_payment'? "Success ✅":"Fail 🚫"; ?> </td></tr>
                <?php
                if($status == 'complete_payment')
                {
                    ?>
                    <tr class="item"> <td> Transaction Id </td><td> # <?php echo $transaction_id; ?> </td></tr>
                    <?php
                }else{
                    ?>
                    <tr class="item"> <td> Transaction Error</td><td> <?php echo $callback_action['error']; ?> </td></tr>
                    <?php
                }
                ?>
                <tr class="item"> <td> Transaction Date </td><td> <?php echo date("F j, Y"); ?> </td></tr>
                <tr class="item last"> <td> Amount </td><td> <?php echo $amount ?> </td></tr>
            </table>
            <p style="font-size:17px;text-align:center;">Go back to the <strong><a href="<?php echo $ref_url; ?>"><?php echo $ref_title; ?></a></strong> page. </p>
            <!-- <p style="font-size:17px;text-align:center;"><strong>Note:</strong> This page will automatically redirected to the <strong><?php echo $ref_title; ?></strong> page in <span id="rzp_refresh_timer"></span> seconds.</p> -->
            <!-- <progress style = "margin-left: 40%;" value="0" max="10" id="progressBar"></progress> -->
            <div style="margin-left:22%; margin-top: 20px;">
                <?php echo $confirmation; ?>
            </div>
        </div>
        </body>';
        <!-- <script type="text/javascript">setTimeout(function(){window.location.href="<?php echo $ref_url; ?>"}, 1e3 * rzp_refresh_time), setInterval(function(){rzp_actual_refresh_time > 0 ? (rzp_actual_refresh_time--, document.getElementById("rzp_refresh_timer").innerText=rzp_actual_refresh_time) : clearInterval(rzp_actual_refresh_time)}, 1e3);</script> -->
        <?php

    }

    public function generate_razorpay_form($entry, $form)
    {
        $webhookEnabledAt =  (int)get_option(self::GF_RAZORPAY_WEBHOOK_ENABLED_AT);

        if (empty($webhookEnabledAt) === false)
        {
            if ($webhookEnabledAt + 86400 < time())
            {
                $this->auto_enable_webhook();
            }
        }
        else
        {
            $this->auto_enable_webhook();
        }

        // insert record in webhook table
        global $wpdb;

        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

        $tableName = $wpdb->prefix . 'rzp_gf_webhook_triggers';
        $webhookEvents = $wpdb->get_results("SELECT * FROM $tableName WHERE order_id=" . $entry['id'] . ";");
        if (empty($webhookEvents) === true)
        {
            $wpdb->insert(
                $tableName,
                array(
                    'order_id'                      => $entry['id'],
                    'rzp_order_id'                  => $entry[self::RAZORPAY_ORDER_ID],
                    'rzp_webhook_data'              => '[]',
                    'rzp_update_order_cron_status'  => self::RZP_ORDER_CREATED
                )
            );
        }

        $feed = $this->get_payment_feed($entry, $form);

        $customerFields = $this->get_customer_fields($form, $feed, $entry);

        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $callbackUrl = esc_url(site_url()) . '/?page=gf_razorpay_callback';

        $razorpayArgs = array(
            'key'           => $key,
            'name'          => get_bloginfo('name'),
            'amount'        => (int) round($entry['payment_amount'] * 100),
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
            "_"             => array(
                'integration'                => "gravityforms",
                'integration_version'        => GF_RAZORPAY_VERSION,
                'integration_parent_version' => GFForms::$version,
                'integration_type'           => 'plugin',
            ),
            'order_id'      => $entry[self::RAZORPAY_ORDER_ID],
            'callback_url'  => $callbackUrl,
            'integration'   => 'gravityforms',
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
<p id='msg-razorpay-success'  style='display:none; text-align:center'>
    <h3 style='text-align:center'>Please wait while we are processing your payment.</h3>
</p>
<p>
    <button id='btn-razorpay' style='display:none'>Pay With Razorpay</button>
    <button id='btn-razorpay-cancel' style='display:none' onclick='document.razorpayform.submit()'>Cancel</button>
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

    public function generate_razorpay_order($confirmation, $form, $entry)
    {
        $feed            = $this->get_payment_feed( $entry );
        $submission_data = $this->get_submission_data( $feed, $form, $entry );

        //Check if gravity form is executed without any payment
        if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
            return true;
        }
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

        $payment_action = $this->get_plugin_setting(self::GF_RAZORPAY_PAYMENT_ACTION) ? $this->get_plugin_setting(self::GF_RAZORPAY_PAYMENT_ACTION) : self::CAPTURE;

        $api = new Api($key, $secret);

        $data = array(
            'receipt'         => $entry['id'],
            'amount'          => (int) round($paymentAmount * 100),
            'currency'        => $entry['currency'],
            'payment_capture' => ($payment_action === self::CAPTURE) ? 1 : 0
        );

        try
        {
            $razorpayOrder = $api->order->create($data);


            gform_update_meta($entry['id'], self::RAZORPAY_ORDER_ID, $razorpayOrder['id']);

            $entry[self::RAZORPAY_ORDER_ID] = $razorpayOrder['id'];

            GFAPI::update_entry($entry);

            $httpSecure = is_ssl() ? true : false;

            setcookie(self::RAZORPAY_ORDER_ID, $entry[self::RAZORPAY_ORDER_ID],
                time() + self::COOKIE_DURATION, COOKIEPATH, COOKIE_DOMAIN, $httpSecure, true);

            echo $this->generate_razorpay_form($entry, $form);
        }
        catch (\Exception $e)
        {
            do_action('gform_razorpay_fail_payment', $entry, $feed);

            $errorMessage = $e->getMessage();

            echo $errorMessage;

        }
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

    public function init()
    {
        add_filter( 'gform_notification_events', array( $this, 'notification_events' ), 10, 2 );

        // Supports frontend feeds.
        $this->_supports_frontend_feeds = true;

        parent::init();

    }

    public function auto_enable_webhook()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $webhookExist = false;
            $webhookUrl = esc_url(admin_url('admin-post.php')) . '?action=gf_razorpay_webhook';
            $webhookEnabledAt = get_option(self::GF_RAZORPAY_WEBHOOK_ENABLED_AT);
            $webhookSecret = get_option(self::GF_RAZORPAY_WEBHOOK_SECRET);
            $time = time();

            if (empty($webhookEnabledAt))
            {
                add_option(self::GF_RAZORPAY_WEBHOOK_ENABLED_AT, $time);
            }
            else
            {
                update_option(self::GF_RAZORPAY_WEBHOOK_ENABLED_AT, $time);
            }

            $skip = 0;
            $count = 10;

            do {
                $webhooks = $this->webhookAPI("GET", "webhooks?count=" . $count . "&skip=" . $skip);
                $skip += 10;

                if ($webhooks['count'] > 0)
                {
                    foreach ($webhooks['items'] as $key => $value)
                    {
                        if ($value['url'] === $webhookUrl)
                        {
                            foreach ($value['events'] as $evntkey => $evntval)
                            {
                                if (($evntval == 1) and
                                    (in_array($evntkey, $this->supportedWebhookEvents) === true))
                                {
                                    $this->defaultWebhookEvents[$evntkey] = true;
                                }
                            }
                            $webhookExist = true;
                            $webhookId = $value['id'];
                            break;
                        }
                    }
                }
            } while  ($webhooks['count'] >= 10);

            if (empty($webhookSecret))
            {
                $webhookSecret = $this->createWebhookSecret();
                delete_option(self::GF_RAZORPAY_WEBHOOK_SECRET);
                add_option(self::GF_RAZORPAY_WEBHOOK_SECRET, $webhookSecret);
            }

            $data = [
                'url'    => $webhookUrl,
                'active' => true,
                'events' => $this->defaultWebhookEvents,
                'secret' => $webhookSecret,
            ];

            if ($webhookExist)
            {
                $this->webhookAPI('PUT', "webhooks/" . $webhookId, $data);
            }
            else
            {
                $this->webhookAPI('POST', "webhooks/", $data);
            }
        }
    }

    protected function createWebhookSecret()
    {
        $alphanumericString = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz';

        return substr(str_shuffle($alphanumericString), 0, 20);
    }

    protected function webhookAPI($method, $url, $data = array())
    {
        $webhook = [];
        try
        {
            $api = $this->getRazorpayApiInstance();

            $webhook = $api->request->request($method, $url, $data);
        }
        catch(Exception $e)
        {
            $log = array(
                'message' => $e->getMessage(),
            );

            error_log(json_encode($log));
        }

        return $webhook;
    }


    public function getRazorpayApiInstance()
    {
        $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

        $secret = $this->get_plugin_setting(self::GF_RAZORPAY_SECRET);

        return new Api($key, $secret);
    }

    // Added custom event to provide option to chose event to send notifications.
    public function notification_events($notification_events, $form)
    {
        $has_razorpay_feed = function_exists( 'gf_razorpay' ) ? gf_razorpay()->get_feeds( $form['id'] ) : false;

        if ($has_razorpay_feed) {
            $payment_events = array(
                'complete_payment'          => __('Payment Completed', 'gravityforms'),
            );

            return array_merge($notification_events, $payment_events);
        }

        return $notification_events;

    }

    //Add post payment action after payment success.
    public function post_payment_action($entry, $action)
    {
        $form = GFAPI::get_form( $entry['form_id'] );

        GFAPI::send_notifications( $form, $entry, rgar( $action, 'type' ) );
    }

    /**
     * [process_webhook to process the razorpay webhook]
     * @return [type] [description]
     */
    public function process_webhook()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        if (empty($data['event']) === false)
        {
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
            {
                $razorpay_webhook_secret = get_option(self::GF_RAZORPAY_WEBHOOK_SECRET);

                $key = $this->get_plugin_setting(self::GF_RAZORPAY_KEY);

                $secret = $this->get_plugin_setting(self::GF_RAZORPAY_SECRET);

                $api = new Api($key, $secret);
                //
                // If the webhook secret isn't set on wordpress, return
                //
                if (empty($razorpay_webhook_secret) === true)
                {
                    return;
                }

                try
                {
                    $api->utility->verifyWebhookSignature($post,
                        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                        $razorpay_webhook_secret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $log = array(
                        'message'   => $e->getMessage(),
                        'data'      => $data,
                        'event'     => 'gf.razorpay.signature.verify_failed'
                    );

                    error_log(json_encode($log));
                    status_header( 401 );
                    return;
                }

                if (in_array($data['event'], $this->supportedWebhookEvents) === true)
                {
                    $webhookFilteredData = [
                        'gravity_forms_order_id'    => $data['payload']['payment']['entity']['notes']['gravity_forms_order_id'],
                        'razorpay_payment_id'       => $data['payload']['payment']['entity']['id'],
                        'amount'                    => $data['payload']['payment']['entity']['amount'],
                        'event'                     => $data['event']
                    ];

                    global $wpdb;

                    require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

                    $tableName = $wpdb->prefix . 'rzp_gf_webhook_triggers';

                    $webhookEvents = $wpdb->get_results("SELECT rzp_webhook_data FROM $tableName where order_id=" . $webhookFilteredData['gravity_forms_order_id'] . ";");

                    $rzpWebhookData = (array) json_decode($webhookEvents['rzp_webhook_data']);

                    $rzpWebhookData[] = $webhookFilteredData;

                    $wpdb->update(
                        $tableName,
                        array(
                            'rzp_webhook_data'          => json_encode($rzpWebhookData),
                            'rzp_webhook_notified_at'   => time()
                        ),
                        array(
                            'order_id'      => $webhookFilteredData['gravity_forms_order_id'],
                            'rzp_order_id'  => $data['payload']['payment']['entity']['order_id']
                        )
                    );
                }
                else
                {
                    $log = array(
                        'message' => "webhook event ". $data['event'] . " is not supported.",
                    );

                    error_log(json_encode($log));
                }
            }
        }
    }

    /**
     * [order_paid Consume 'order.paid' webhook payload for order processing]
     * @param  [array] $data [webhook payload]
     * @return [type]       [description]
     */
    public function order_paid($data)
    {
        $entry_id = $data['gravity_forms_order_id'];

        if(empty($entry_id) === false)
        {
            $entry = GFAPI::get_entry($entry_id);

            if(is_array($entry) === true)
            {
                $razorpay_payment_id = $data['razorpay_payment_id'];

                //check the payment status not set
                if ((empty($entry['payment_status']) === true) or
                    (strtolower($entry['payment_status']) !== 'paid'))
                {
                    //check for valid amount
                    $payment_amount = $data['amount'];

                    $order_amount =  (int) round(rgar($entry, 'payment_amount' ) * 100);

                    //if valid amount paid mark the order complete
                    if($payment_amount === $order_amount)
                    {
                        $action = array(
                            'id'             => $razorpay_payment_id,
                            'type'           => 'complete_payment',
                            'transaction_id' => $razorpay_payment_id,
                            'amount'         => rgar($entry, 'payment_amount' ),
                            'entry_id'       => $entry_id,
                            'payment_method' => 'razorpay',
                            'error'          => null,
                        );

                        $this->complete_payment($entry, $action );
                    }
                }
            }
        }
    }

}
