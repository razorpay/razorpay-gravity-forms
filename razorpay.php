<?php

/*
Plugin Name: Razorpay for Gravity Forms
Plugin URI: https://wordpress.org/plugins/razorpay-gravity-forms
Description: Integrates Gravity Forms with Razorpay Payments, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.3.6
Stable tag: 1.3.6
Author: Team Razorpay
Author URI: https://razorpay.com
Text Domain: razorpay-gravity-forms
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==

This is the official Razorpay payment gateway plugin for Gravity Forma. Allows you to accept credit cards, debit cards, netbanking and wallet with the gravity form plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away from your website.

*/


define('GF_RAZORPAY_VERSION', '1.3.6');

add_action('admin_post_nopriv_gf_razorpay_webhook', "gf_razorpay_webhook_init", 10);
add_action('gform_loaded', array('GF_Razorpay_Bootstrap', 'load'), 5);
add_action('plugins_loaded', 'createRzpWebhookTables');
add_action('rzp_gf_webhook_exec_cron', 'execRzpWebhookEvents');

add_filter('cron_schedules','rzpCronSchedules');

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

function createRzpWebhookTables()
{
    $installedVersion = get_option('GF_RAZORPAY_VERSION');

    if ($installedVersion !== GF_RAZORPAY_VERSION)
    {
        // create table to save webhook events
        global $wpdb;

        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}rzp_gf_webhook_triggers` (
                `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL,
                `rzp_order_id` varchar(25) NOT NULL,
                `rzp_webhook_data` text,
                `rzp_webhook_notified_at` varchar(30),
                `rzp_update_order_cron_status` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)) $charset_collate;";

        if (empty(dbDelta($sql)) === false)
        {
            update_option('GF_RAZORPAY_VERSION', GF_RAZORPAY_VERSION);
        }

        // create razorpay GF cron
        createRzpCron('rzp_gf_webhook_exec_cron', time(), 'rzp_gf_webhook_cron_interval');
    }
}

function rzpCronSchedules($schedules)
{
    if (isset($schedules["rzp_gf_webhook_cron_interval"]) === false)
    {
        $schedules["rzp_gf_webhook_cron_interval"] = array(
            'interval'  => 5 * 60,
            'display'   => __('Every 5 minutes'));
    }

    return $schedules;
}

function createRzpCron($hookName, $startTime, $recurrence)
{
    if (wp_next_scheduled($hookName) === false)
    {
        wp_schedule_event($startTime, $recurrence, $hookName);
    }
}

function execRzpWebhookEvents()
{
    global $wpdb;

    require_once(ABSPATH . '/wp-admin/includes/upgrade.php');

    $rzp_order_processed_by_webhook = 2;
    $tableName = $wpdb->prefix . 'rzp_gf_webhook_triggers';

    $webhookEvents = $wpdb->get_results("SELECT order_id, rzp_order_id, rzp_webhook_data FROM $tableName WHERE rzp_webhook_notified_at < " . (string)(time() - 300) ." AND rzp_update_order_cron_status=0;");

    foreach ($webhookEvents as $row)
    {
        $events = json_decode($row->rzp_webhook_data);
        foreach ($events as $event)
        {
            $event = (array) $event;
            switch ($event['event'])
            {
                case 'order.paid':
                    $gf_razorpay = gf_razorpay();
                    $gf_razorpay->order_paid($event);

                    $wpdb->update(
                        $tableName,
                        array(
                            'rzp_update_order_cron_status' => $rzp_order_processed_by_webhook
                        ),
                        array(
                            'order_id'      => $row->order_id,
                            'rzp_order_id'  => $row->rzp_order_id
                        )
                    );
                    return;

                default:
                    return;
            }
        }
    }
}
