<?php

namespace LockmeIntegration\Plugins;

use DateInterval;
use DateTime;
use EADBModels;
use EATableColumns;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use RuntimeException;

class Easyapp implements PluginInterface
{
    private $options;
    private $models;
    private $resdata;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        global $wpdb;
        $this->plugin = $plugin;
        $this->options = get_option('lockme_easyapp');

        if (is_array($this->options) && ($this->options['use'] ?? null) && $this->CheckDependencies()) {
            $this->models = new EADBModels($wpdb, new EATableColumns, []);

            if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'ea_appointment' && $_GET['id']) {
                $this->resdata = $this->AppData($_GET['id']);
            }

            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function () {
                if ($_GET['easyapp_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=easyapp_plugin&easyapp_exported=1');
                    exit;
                }
            }, PHP_INT_MAX);
        }
    }

    public function CheckDependencies(): bool
    {
        return is_plugin_active('easy-appointments/main.php');
    }

    private function AppData($resid): array
    {
        $res = $this->models->get_appintment_by_id($resid);

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['location'].'_'.$res['service'].'_'.$res['worker']],
                    'date' => date('Y-m-d', strtotime($res['date'])),
                    'hour' => date('H:i:s', strtotime($res['start'])),
                    'people' => 0,
                    'pricer' => 'API',
                    'price' => $res['price'],
                    'name' => $res['name'],
                    'email' => $res['email'],
                    'phone' => $res['phone'],
                    'status' => $res['status'] === 'confirmed',
                    'extid' => $res['id']
                ]
            );
    }

    public function ExportToLockMe(): void
    {
        set_time_limit(0);

        $start = new DateTime;
        $end = new DateTime;
        $end->add(new DateInterval('P1Y'));
        $rows = $this->models->get_all_appointments(['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')]);

        foreach ($rows as $row) {
            $this->Update($row->id, (array)$row);
        }
    }

    public function RegisterSettings(): void
    {
        global $wpdb;
        if (!$this->CheckDependencies()) {
            return;
        }

        register_setting('lockme-easyapp', 'lockme_easyapp');

        add_settings_section(
            'lockme_easyapp_section',
            'Easy Appointments plugin settings',
            static function () {
                echo '<p>Integration settings with the Easy Appointments plugin</p>';
            },
            'lockme-easyapp'
        );

        add_settings_field(
            'easyapp_use',
            'Enable integration',
            function () {
                echo '<input name="lockme_easyapp[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'] ?? null,
                        false).' />';
            },
            'lockme-easyapp',
            'lockme_easyapp_section',
            []
        );

        if (($this->options['use'] ?? null) && $this->plugin->tab === 'easyapp_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }
            $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');

            foreach ($calendars as $calendar) {
                add_settings_field(
                    'calendar_'.$calendar->id,
                    'Room for '.$calendar->name,
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_easyapp[calendar_'.$calendar->id.']">';
                        echo '<option value="">--select--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar->id],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-easyapp',
                    'lockme_easyapp_section',
                    []
                );
            }
            add_settings_field(
                'export_easyapp',
                'Send data to LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=easyapp_plugin&easyapp_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
                },
                'lockme-easyapp',
                'lockme_easyapp_section',
                []
            );
        }
    }

    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }

        if ($_GET['easyapp_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-easyapp');
        do_settings_sections('lockme-easyapp');
    }

    public function AddEditReservation($id): void
    {
        $data = $this->models->get_appintment_by_id($id);
        if (!$data) {
            return;
        }

        $this->Update($id, $data);
    }

    public function ShutDown(): void
    {
        global $wpdb;
        if (defined('DOING_AJAX') && DOING_AJAX) {
            switch ($_GET['action']) {
                case 'ea_res_appointment':
                case 'nopriv_ea_res_appointment':
                    $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s",
                        $_GET['location'], $_GET['service'], $_GET['worker'], $_GET['date'],
                        date('H:i:s', strtotime($_GET['start']))), ARRAY_A);
                    if ($res) {
                        $this->Add($res);
                    }
                    break;
                case 'ea_cancel_appointment':
                case 'nopriv_ea_cancel_appointment':
                    $this->Delete($_GET['id']);
                    break;
                case 'ea_appointment':
                    $api = $this->plugin->GetApi();
                    switch ($_GET['_method']) {
                        case 'PUT':
                            $this->AddEditReservation($_GET['id']);
                            break;
                        case 'DELETE':
                            try {
                                $api->DeleteReservation((int) $this->resdata['roomid'], "ext/{$_GET['id']}");
                            } catch (Exception $e) {
                            }
                            break;
                        default:
                            $input = json_decode(file_get_contents('php://input'), true);
                            if (!$_GET['_method'] && $input['date']) {
                                $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s",
                                    $input['location'], $input['service'], $input['worker'], $input['date'],
                                    date('H:i:s', strtotime($input['start']))), ARRAY_A);
                                if ($res) {
                                    $this->Add($res);
                                }
                            }
                    }
                    break;
            }
        }
    }

    public function GetMessage(array $message): bool
    {
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];

        [$location, $service, $worker] = explode('_', $this->GetCalendar($roomid));

        switch ($message['action']) {
            case 'add':
                $s = $this->models->get_row('ea_services', $service);
                $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                $id = $this->models->replace('ea_appointments', [
                    'location' => $location,
                    'service' => $service,
                    'worker' => $worker,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'date' => $data['date'],
                    'start' => $data['hour'],
                    'end_date' => $data['date'],
                    'end' => date('H:i:s', $end_time),
                    'price' => $data['price'],
                    'description' => 'LOCKME!',
                    'status' => $data['status'] ? 'confirmed' : 'pending'
                ], true);
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id,
                        $this->plugin->AnonymizeData(['extid' => $id->id])
                    );
                    return true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $s = $this->models->get_row('ea_services', $service);
                    $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                    $this->models->replace('ea_appointments', [
                        'id' => $data['extid'],
                        'location' => $location,
                        'service' => $service,
                        'worker' => $worker,
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'phone' => $data['phone'],
                        'date' => $data['date'],
                        'start' => $data['hour'],
                        'end_date' => $data['date'],
                        'end' => date('H:i:s', $end_time),
                        'price' => $data['price'],
                        'description' => 'LOCKME!',
                        'status' => $data['status'] ? 'confirmed' : 'pending'
                    ], true);
                    return true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    $this->models->delete('ea_appointments', ['id' => $data['extid']]);
                    return true;
                }
                break;
        }
        return false;
    }

    private function Add($res): void
    {
        if (in_array($res['status'], ['canceled', 'abandoned'])) {
            return;
        }

        $api = $this->plugin->GetApi();

        try {
            $api->AddReservation($this->AppData($res['id']));
        } catch (Exception $e) {
        }
    }

    private function Update($id, $res): void
    {
        $appdata = $this->AppData($res['id']);

        if(!$appdata['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            $this->Add($res);

            return;
        }
        if (in_array($res['status'], ['canceled', 'abandoned'])) {
            $this->Delete($id);

            return;
        }

        try {
            $api->EditReservation((int) $appdata['roomid'], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
    }

    private function Delete($resid): void
    {
        $res = $this->models->get_appintment_by_id($resid);
        if (!$res) {
            return;
        }

        $appdata = $this->AppData($res['id']);

        if(!$appdata['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$resid}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return;
        }

        try {
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$resid}");
        } catch (Exception $e) {
        }
    }

    /**
     * @param $roomid
     * @return mixed
     * @throws Exception
     */
    private function GetCalendar($roomid)
    {
        global $wpdb;

        $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_'.$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        throw new RuntimeException('No calendar');
    }

    public function getPluginName(): string
    {
        return 'Easy Appointments';
    }
}
