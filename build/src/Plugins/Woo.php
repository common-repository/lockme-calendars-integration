<?php

namespace LockmeDep\LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
use RuntimeException;
use WC_Booking;
use WP_Query;
class Woo implements PluginInterface
{
    private $options;
    private $plugin;
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_woo');
        if (is_array($this->options) && ($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('woocommerce_new_booking', [$this, 'AddEditReservation'], 5, 1);
            foreach (['unpaid', 'pending-confirmation', 'confirmed', 'paid', 'complete', 'in-cart'] as $action) {
                add_action('woocommerce_booking_' . $action, [$this, 'AddEditReservation'], 5, 1);
            }
            add_action('woocommerce_booking_cancelled', [$this, 'Delete'], 5, 1);
            add_action('woocommerce_booking_trash', [$this, 'Delete'], 5, 1);
            add_action('woocommerce_booking_was-in-cart', [$this, 'Delete'], 5, 1);
            add_action('trashed_post', [$this, 'Delete'], 5, 1);
            add_action('before_delete_post', [$this, 'Delete'], 5, 1);
            add_action('post_updated', [$this, 'AddEditReservation']);
            add_action('init', function () {
                if ($_GET['woo_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=woo_plugin&woo_exported=1');
                    exit;
                }
            }, \PHP_INT_MAX);
        }
    }
    public function ExportToLockMe(): void
    {
        $args = ['post_type' => 'wc_booking', 'meta_key' => '_booking_start', 'meta_value' => date('YmdHis'), 'meta_compare' => '>=', 'posts_per_page' => -1, 'post_status' => 'any'];
        $loop = new WP_Query($args);
        while ($loop->have_posts()) {
            $loop->the_post();
            $post = $loop->post;
            $this->AddEditReservation($post->ID);
        }
    }
    public function CheckDependencies(): bool
    {
        return is_plugin_active('woocommerce-bookings/woocommmerce-bookings.php') || is_plugin_active('woocommerce-bookings/woocommerce-bookings.php');
    }
    public function RegisterSettings(): void
    {
        if (!$this->CheckDependencies()) {
            return;
        }
        register_setting('lockme-woo', 'lockme_woo');
        add_settings_section('lockme_woo_section', 'Woocommerce Bookings plugin settings', static function () {
            echo '<p>Integration settings with the Woocommerce Bookings plugin</p>';
        }, 'lockme-woo');
        add_settings_field('woo_use', 'Enable integration', function () {
            echo '<input name="lockme_woo[use]" type="checkbox" value="1"  ' . checked(1, $this->options['use'] ?? null, \false) . ' />';
        }, 'lockme-woo', 'lockme_woo_section', []);
        if (($this->options['use'] ?? null) && $this->plugin->tab === 'woo_plugin') {
            add_settings_field('slot_length', 'Dłogośc slota (w min)', function () {
                echo '<input name="lockme_woo[slot_length]" type="text" value="' . $this->options['slot_length'] . '" />';
            }, 'lockme-woo', 'lockme_woo_section', []);
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }
            $args = ['post_type' => 'product', 'numberposts' => -1];
            $calendars = get_posts($args);
            foreach ($calendars as $calendar) {
                add_settings_field('calendar_' . $calendar->ID, 'Room for ' . $calendar->post_title, function () use ($rooms, $calendar) {
                    echo '<select name="lockme_woo[calendar_' . $calendar->ID . ']">';
                    echo '<option value="">--select--</option>';
                    foreach ($rooms as $room) {
                        echo '<option value="' . $room['roomid'] . '" ' . selected(1, $room['roomid'] == $this->options['calendar_' . $calendar->ID], \false) . '>' . $room['room'] . ' (' . $room['department'] . ')</options>';
                    }
                    echo '</select>';
                }, 'lockme-woo', 'lockme_woo_section', []);
            }
            add_settings_field('export_woo', 'Send data to LockMe', static function () {
                echo '<a href="?page=lockme_integration&tab=woo_plugin&woo_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
            }, 'lockme-woo', 'lockme_woo_section', []);
        }
    }
    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }
        //     $booking = new WC_Booking(2918);
        //     var_dump(get_post_meta(2918));
        if ($_GET['woo_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-woo');
        do_settings_sections('lockme-woo');
    }
    public function AddEditReservation($postid)
    {
        if (!is_numeric($postid)) {
            return \false;
        }
        if (defined('LOCKME_MESSAGING')) {
            return \false;
        }
        clean_post_cache($postid);
        $post = get_post($postid);
        if ($post->post_type !== 'wc_booking') {
            return \false;
        }
        $booking = new WC_Booking($postid);
        if (!$booking->populated) {
            return \false;
        }
        if (in_array($booking->status, ['cancelled', 'trash', 'was-in-cart'])) {
            return $this->Delete($postid);
        }
        $appdata = $this->AppData($booking);
        if (!$appdata['roomid']) {
            return null;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = null;
        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$postid}");
        } catch (Exception $e) {
        }
        try {
            if (!$lockme_data) {
                //Add new
                $api->AddReservation($appdata);
            } else {
                //Update
                $api->EditReservation((int) $appdata['roomid'], "ext/{$postid}", $appdata);
            }
        } catch (Exception $e) {
        }
        return \true;
    }
    public function Delete($postid)
    {
        if (defined('LOCKME_MESSAGING')) {
            return null;
        }
        clean_post_cache($postid);
        $post = get_post($postid);
        if ($post->post_type !== 'wc_booking') {
            return \false;
        }
        $booking = new WC_Booking($postid);
        if (!$booking->populated) {
            return \false;
        }
        if (!in_array($booking->status, ['cancelled', 'trash', 'was-in-cart'])) {
            return $this->AddEditReservation($postid);
        }
        $appdata = $this->AppData($booking);
        if (!$appdata['roomid']) {
            return \false;
        }
        $api = $this->plugin->GetApi();
        try {
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$booking->get_id()}");
        } catch (Exception $e) {
        }
        return null;
    }
    public function GetMessage(array $message): bool
    {
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return \false;
        }
        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $date = $data['date'];
        $hour = date('H:i:s', strtotime($data['hour']));
        $start = strtotime($date . ' ' . $hour);
        $calendar_id = $this->GetCalendar($roomid);
        switch ($message['action']) {
            case 'add':
                $booking = create_wc_booking($calendar_id, ['product_id' => $calendar_id, 'start_date' => $start, 'end_date' => $start + 60 * (int) $this->options['slot_length'], 'persons' => $data['people'], 'cost' => $data['price']], 'pending-confirmation', \true);
                if ($booking) {
                    try {
                        $api = $this->plugin->GetApi();
                        $api->EditReservation($roomid, $lockme_id, $this->plugin->AnonymizeData(['extid' => $booking->get_id()]));
                        return \true;
                    } catch (Exception $e) {
                    }
                } else {
                    throw new RuntimeException('Saving error');
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $post = get_post($data['extid']);
                    if (!$post || $post->post_type !== 'wc_booking') {
                        return \false;
                    }
                    $booking = new WC_Booking($data['extid']);
                    if (!$booking->populated) {
                        return \false;
                    }
                    if ($booking->status !== 'confirmed' && $data['status']) {
                        $booking->update_status('confirmed');
                    }
                    $meta_args = ['_booking_persons' => $data['people'], '_booking_cost' => $data['price'], '_booking_start' => date('YmdHis', $start), '_booking_end' => date('YmdHis', $start + 60 * (int) $this->options['slot_length'])];
                    foreach ($meta_args as $key => $value) {
                        update_post_meta($booking->get_id(), $key, $value);
                    }
                    return \true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    wp_delete_post($data['extid']);
                    return \true;
                }
                break;
        }
        return \false;
    }
    private function AppData($booking): array
    {
        $order = $booking->get_order();
        return $this->plugin->AnonymizeData(['roomid' => $this->options['calendar_' . $booking->get_product_id()], 'date' => date('Y-m-d', $booking->get_start()), 'hour' => date('H:i:s', $booking->get_start()), 'pricer' => 'API', 'price' => $booking->get_cost(), 'status' => $booking->get_status() === 'in-cart' ? 0 : 1, 'people' => array_sum($booking->get_person_counts()), 'extid' => $booking->get_id(), 'email' => $order ? $order->get_billing_email() : '', 'phone' => $order ? $order->get_billing_phone() : '', 'name' => $order ? $order->get_billing_first_name() : '', 'surname' => $order ? $order->get_billing_last_name() : '']);
    }
    private function GetCalendar($roomid)
    {
        $args = ['numberposts' => -1, 'post_type' => 'product'];
        $calendars = get_posts($args);
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_' . $calendar->ID] == $roomid) {
                return $calendar->ID;
            }
        }
        return null;
    }
    public function getPluginName(): string
    {
        return 'WooCommerce Bookings';
    }
}
