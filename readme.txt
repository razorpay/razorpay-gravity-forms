=== Razorpay for Gravity Forms ===
Contributors: razorpay
Tags: razorpay, payments, india, gravityforms, ecommerce
Requires at least: 3.9.2
Tested up to: 5.3.2
Stable tag: 1.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows you to use Razorpay payment gateway with the gravity forms plugin.

== Description ==

This is the official Razorpay payment gateway plugin for Gravity Forms. Allows you to accept credit cards, debit cards, netbanking and wallets with the gravity forms plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away from your website.

This is compatible with version greater than 1.9.3 gravity forms.

== Installation ==

1. Install the plugin from the [Wordpress Plugin Directory](Need to specify url).
2. To use this plugin correctly, you need to be able to make network requests. Please make sure that you have the php-curl extension installed.

== Dependencies ==

1. Wordpress v3.9.2 and later
2. Gravity Forms v1.9.3 and later
3. PHP v5.6.0 and later
4. php-curl

== Configuration ==

1. Visit the Gravity Forms settings page, and click on the Razorpay tab.
2. Add in your Key Id and Key Secret.

== Changelog ==

= 1.2.1 =
* Bug fix

= 1.2.0 =
* Added Meta-data for internal analysis.
* Added webhook support for "order.paid".

= 1.1.1 =
* Handle non payment form submit
* Handle error if configuration is mismatch.

= 1.1.0 =
* Add admin notification event to choose email notification after form submit or payment complete
* Update latest sdk 2.5.0

== Support ==

Visit [razorpay.com](https://razorpay.com) for support requests or email us at <integrations@razorpay.com>.

== License ==

The Razorpay Gravity Forms plugin is released under the GPLv2 license, same as that
of WordPress. See the LICENSE file for the complete LICENSE text.
