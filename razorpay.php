<?php

/*
Plugin Name: Razorpay for Gravity Forms
Plugin URI: https://wordpress.org/plugins/razorpay-gravity-forms
Description: Integrates Gravity Forms with Razorpay Payments, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.3.5
Stable tag: 1.3.5
Author: Team Razorpay
Author URI: https://razorpay.com
Text Domain: razorpay-gravity-forms
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==

This is the official Razorpay payment gateway plugin for Gravity Forma. Allows you to accept credit cards, debit cards, netbanking and wallet with the gravity form plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away from your website.

*/


define('GF_RAZORPAY_VERSION', '1.3.5');

add_action('admin_post_nopriv_gf_razorpay_webhook', "gf_razorpay_webhook_init", 10);
add_action('gform_loaded', array('GF_Razorpay_Bootstrap', 'load'), 5);

class GF_Razorpay_Bootstrap
{
    public static function load()
    {
        if (method_exists('GFForms', 'include_payment_addon_framework') === false)
        {
            return;
        }

        require_once('class-gf-razorpay.php');

        GFAddOn::register('GFRazorpay');

        add_filter('gform_currencies', function (array $currencies) {

            $supported_currencies = json_decode(file_get_contents(__DIR__ . "/supported-currencies.json"), true)['supported-currencies'];

            foreach ($supported_currencies as $k => $v)
            {
                if (in_array($v['iso_code'], $currencies) === false)
                {
                    $currencies[$v['iso_code']] = array(
                        'name'               => __( $v['currency_name'], 'gravityforms' ),
                        'code'               => $v['iso_code'],
                        'symbol_left'        => $v['iso_code'],
                        'symbol_right'       => '',
                        'symbol_padding'     => ' ',
                        'thousand_separator' => ',',
                        'decimal_separator'  => '.',
                        'decimals'           => $v['exponent']
                    );
                }
            }
            $currencies['INR'] = array(
                'name'               => __( 'Indian Rupee', 'gravityforms' ),
                'code'               => 'INR',
                'symbol_left'        => '&#8377;',
                'symbol_right'       => '',
                'symbol_padding'     => ' ',
                'thousand_separator' => ',',
                'decimal_separator'  => '.',
                'decimals'           => 2
            );

            return $currencies;
        });
    }
}

function gf_razorpay()
{
    return GFRazorpay::get_instance();
}

// This is set to a priority of 10
// Initialize webhook processing
function gf_razorpay_webhook_init()
{
    $gf_razorpay = gf_razorpay();

    $gf_razorpay->process_webhook();
}

