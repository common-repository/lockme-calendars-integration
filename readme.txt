=== Lockme OAuth2 calendars integration ===
Contributors: lustmored
Donate link:
Tags: lustmored
Requires PHP: 8.1
Requires at least: 6.4
Tested up to: 6.6
Stable tag: 2.8.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin integrates popular booking systems with Lockme OAuth2 API.

== Description ==

This plugin acts as a middleware between your booking system and [Lockme OAuth2 API](https://apidoc.lockme.pl/ "Lockme API 2.0 documentation") (a.k.a. API 2.0).

Usage of this plugin isn't required, but if you are Lockme partner and want to seamlessly integrate your booking solution with that found on Lockme website, it is the easiest way. It **will send booking data** created via WordPress site to Lockme and handle messages about bookings from Lockme.

Currently, publicly supported calendar systems are:

* QuickCal (formerly Booked), recommended version 2.0.9 or newer
* Pinpoint booking system, recommended version 2.6 or newer
* Booking Calendar Pro WpDevArt version 10.1 or newer (please don't)
* Bookly Appointment Booking, recommended version 14.5 or newer
* Easy Appointments - version 2.1.4 and newer
* Booking Calendar WP Plugin - only version 6.0.1 tested (also - please don't)
* WooCommerce Bookings - version 1.9.1 and newer
* Appointments by WPMU DEV - version 2.4.0 and newer
* Appointment Booking Calendar - version 7.2.34 and newer
* ez Schedule Manager - version 2.2 (once again - please don't)
* Amelia - version 7.8 or newer (consider alternatives if possible)

Other booking systems to be available after porting to API 2.0 and testing. Systems marked as "please don't" are considered extremely unfriendly to our integration purposes and probably will break upon updating. If you still have choice please consider using other booking systems.

**IMPORTANT!** This plugin does its best to work in whatever condition it has to, but it should be noted that author does not give any warrant regarding data consistency between Lockme and your booking system. If for some reason some bookings will not be sent between systems, you should handle it manually. Plugin author does not take any responsibility for such problems.

**ALSO IMPORTANT!** Any integration can break at any time upon updating booking systems. In that case please report this fact immediately, so we can work on fix. Unfortunately most booking systems doesn't care about extensibility at all, so very dirty hacks are necessary for this plugin to work correctly. We are sorry if your eyes will bleed upon reading some solutions in our code - they're not clean, but they work in conditions most booking systems create.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/plugin-name` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Use the Settings->Lockme screen to fill your OAuth2 Client ID and Client Secret (to be found in Lockme Panel)
1. Copy redirect URI from WordPress Lockme configuration page to an app in Lockme Panel
1. Log in to Lockme to establish connection, using "Click here" link on setting page
1. Set up Callback URL in department configuration on Lockme
1. Choose tab corresponding to your booking system and configure rooms
1. Optionally send all bookings data to Lockme for a good beginning

== Frequently asked questions ==

= I don't have booking system yet, but want to work with Lockme. Which one should I choose? =

We always recommend Booked. For what we saw it has the best codebase and allows for spotless integration with our plugin. It also is really easy to set up.

= My booking system is not listed as available. Will this plugin work with it? =

Show answer - no. Long answer - please contact us at kontakt@lockme.pl, and we'll do our best to integrate with whatever booking system you have.

== Screenshots ==

1. Main settings page for connection with Lockme API

== Changelog ==

= 2.8 =

* Added ID prefix option for situations where ID conflict might occur between systems or installations.

= 2.7 =

* This plugin no longer uses and initializes session for token generation.

= 2.6 =

* Add support for Amelia Booking.

= 2.5 =

* Drop support for PHP 8.0 and WordPress lower than 6.4.
* Mark lockme related options as not autoloaded.
* Only use session when needed and close it immediately after for performance regression problems.

= 2.4 =

* The plugin is now fully in English. There are no plans for multilingual support right now.

= 2.3 =

* Add logging all API communication errors to the database.

= 2.2 =

* Add information about invoice data (only Booking System PRO for now).

= 2.1 =

* Move everything into own namespace to mitigate conflicts.

= 2.0 =
* Require PHP 7.2 and Lockme SDK 2.0.
* Solve race condition on refreshing tokens.
* Adapt to another ABI change in Bookly.

= 1.3 =
* Added option to use plugin against any specified domain per Lockme team internal needs.

= 1.2 =
* Added RODO mode that will prevent plugin from sending ANY customer information to Lockme (only sent data is date, hour, room, booking ID and its status).
* Refactored plaugins list to show only installed and supported plugins.
* Added option to manually upload access token. Using it is highly discouraged if you don't know what are you doing.

= 1.0 =
First public release based on Lockme OAuth2 API (a.k.a. API 2.0), currently publicly supporting Booked and Pinpoint Booking System (a.k.a. dopbsp).
