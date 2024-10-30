<?php

/*
Plugin Name: Lockme calendars integration
Plugin URI:  https://github.com/Lustmored/lockme
Description: This plugin integrates popular booking systems with Lockme OAuth2 API.
Version:     2.8.0
Author:      Jakub Caban
Author URI:  https://lockme.pl
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

namespace LockmeDep {
    if (function_exists('\\quickcal_get_name')) {
        function quickcal_get_name(...$args) {
            \quickcal_get_name(...$args);
        }
    }
    if (function_exists('\\quickcal_apply_custom_timeslots_details_filter')) {
        function quickcal_apply_custom_timeslots_details_filter(...$args) {
            \quickcal_apply_custom_timeslots_details_filter(...$args);
        }
    }
    if (function_exists('\\booked_apply_custom_timeslots_details_filter')) {
        function booked_apply_custom_timeslots_details_filter(...$args) {
            \booked_apply_custom_timeslots_details_filter(...$args);
        }
    }
    if (function_exists('\\quickcal_apply_custom_timeslots_filter')) {
        function quickcal_apply_custom_timeslots_filter(...$args) {
            \quickcal_apply_custom_timeslots_filter(...$args);
        }
    }
    if (function_exists('\\booked_apply_custom_timeslots_filter')) {
        function booked_apply_custom_timeslots_filter(...$args) {
            \booked_apply_custom_timeslots_filter(...$args);
        }
    }
}

namespace {

    defined('ABSPATH') or die('No script kiddies please!');

    require_once __DIR__.'/build/vendor/scoper-autoload.php';

    $lockme = new LockmeDep\LockmeIntegration\Plugin();
    register_activation_hook(__FILE__, [$lockme, 'activate']);
    add_action('plugins_loaded', [$lockme, 'createDatabase']);
}
