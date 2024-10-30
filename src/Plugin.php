<?php
namespace LockmeIntegration;

use Closure;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Lockme\SDK\Lockme;
use LockmeIntegration\Libs\WrappedProvider;
use LockmeIntegration\Plugins\Amelia;
use LockmeIntegration\Plugins\Appointments;
use LockmeIntegration\Plugins\Booked;
use LockmeIntegration\Plugins\Bookly;
use LockmeIntegration\Plugins\Cpabc;
use LockmeIntegration\Plugins\Dopbsp;
use LockmeIntegration\Plugins\Easyapp;
use LockmeIntegration\Plugins\Ezscm;
use LockmeIntegration\Plugins\Woo;
use LockmeIntegration\Plugins\WPBooking;
use LockmeIntegration\Plugins\WPDevArt;
use LockmeIntegration\Util\LogTable;

include_once ABSPATH.'wp-admin/includes/plugin.php';

class Plugin
{
    private const DB_VER = '1.0';

    public $options;
    public $tab;
    private $url_key;
    private $plugins = [
        'amelia' => Amelia::class,
        'appointments'=> Appointments::class,
        'booked'=> Booked::class,
        'bookly' => Bookly::class,
        'cpabc' => Cpabc::class,
        'dopbsp'=> Dopbsp::class,
        'easyapp'=> Easyapp::class,
        'ezscm' => Ezscm::class,
        'woo'=> Woo::class,
        'wpdevart'=> WPDevArt::class,
        'wp_booking' => WPBooking::class,
    ];
    /**
     * @var PluginInterface[]
     */
    private $available_plugins = [];

    private $api = null;

    public function __construct()
    {
        $this->options = get_option('lockme_settings');

        $this->url_key = get_option('lockme_url_key');
        if (!$this->url_key) {
            try {
                $this->url_key = bin2hex(random_bytes(10));
            } catch (Exception $e) {
                $this->url_key = '39836295616564325481';
            }
            update_option('lockme_url_key', $this->url_key, false);
        }

        add_action('init', array(&$this, 'api_call'), PHP_INT_MAX);

        foreach ($this->plugins as $k=>$v) {
            /** @var PluginInterface $plugin */
            $plugin = new $v($this);
            if($plugin->CheckDependencies()){
                $this->available_plugins[$k] = $plugin;
            }
        }

        if (is_admin()) {
            $this->tab = $_GET['tab'] ?? 'api_options';

            add_action('admin_menu', array(&$this, 'admin_init'));
            add_action('admin_init', array(&$this, 'admin_register_settings'));
        }
    }

    public function api_call(): void
    {
        // Check for OAuth2 state
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        if ($code && $state) {
            try {
                $api = $this->GetApi();
                $sessionState = get_option('lockme_oauth2_state');
                $token = $api->getTokenForCode($code, $state, $sessionState);
                if ($token instanceof AccessToken) {
                    update_option('lockme_oauth2_token', $token, false);
                }
                delete_option('lockme_oauth2_state');
                wp_redirect('options-general.php?page=lockme_integration&tab=api_options');
                exit;
            } catch (Exception $e) {
                wp_redirect('options-general.php?page=lockme_integration&tab=api_options');
                exit;
            }
        }

        if(isset($_POST['oauth_token'])){
            $token = stripslashes($_POST['oauth_token']);
            $api = $this->GetApi();
            try {
                $api->loadAccessToken(
                    function () use ($token) { return json_decode($token, true); },
                    function ($token) { update_option('lockme_oauth2_token', $token, false); }
                );
            }catch (Exception $e){
            }
        }

        // Proceed with API callback
        $api = $_GET['lockme_api'] ?? null;
        if ($api !== $this->url_key) {
            return;
        }

        define('LOCKME_MESSAGING', 1);

        try {
            $messageid = $_SERVER['HTTP_X_MESSAGEID'];
            $api = $this->GetApi();
            $message = $api->GetMessage((int) $messageid);

            if (
                $message['data']['extid']
                && str_starts_with($message['data']['extid'], $this->options['id_prefix'])
            ) {
                $message['data']['extid'] = substr(
                    $message['data']['extid'],
                    strlen($this->options['id_prefix'])
                );
            }

            foreach ($this->available_plugins as $k=>$plugin) {
                if ($plugin->GetMessage($message)) {
                    $api->MarkMessageRead((int) $messageid);
                    break;
                }
            }

            echo 'OK';
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        die();
    }

    public function GetApi(): ?Lockme
    {
        if (null !== $this->api) {
            return $this->api;
        }

        if ($this->options['client_id'] && $this->options['client_secret']) {
            $lm = new Lockme([
                'provider' => new WrappedProvider([
                    'clientId' => $this->options['client_id'],
                    'clientSecret' => $this->options['client_secret'],
                    'redirectUri' => get_admin_url().'options-general.php?page=lockme_integration&tab=api_options',
                    'api_domain' => $this->options['api_domain'] ?: 'https://api.lock.me'
                ]),
                'tmp_dir' => get_temp_dir(),
            ]);
            try{
                $lm->loadAccessToken(
                    function () { return get_option('lockme_oauth2_token'); },
                    function ($token) { update_option('lockme_oauth2_token', $token, false); }
                );
            } catch (Exception $e) {
                return $this->api = $lm;
            }
            return $this->api = $lm;
        }
        return null;
    }

    public function admin_init(): void
    {
        add_options_page('LockMe integration', 'Lockme', 'manage_options', 'lockme_integration', array(&$this, 'admin_page'));
    }

    public function admin_register_settings(): void
    {
        register_setting('lockme-admin', 'lockme_settings');

        add_settings_section(
            'lockme_settings_section',
            'API settings',
            static function () {
                echo '<p>Basic data of the LockMe partner - available at <a href="https://lock.me/cockpit/" target="_blank">lock.me/cockpit</a></p>';
            },
            'lockme-admin'
        );

        add_settings_field(
            'client_id',
            'App ID',
            function () {
                echo '<input name="lockme_settings[client_id]"  type="text" value="'.$this->options['client_id'].'" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'client_secret',
            'App secret',
            function () {
                echo '<input name="lockme_settings[client_secret]" type="text" value="'.$this->options['client_secret'].'" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'rodo_mode',
            'RODO mode (anonymize data)',
            function () {
                echo '<input type="checkbox" name="lockme_settings[rodo_mode]" value="1"  '.checked(1, $this->options['rodo_mode'] ?? false, false).' /> <small>If you enable this function, only information about the date and time of the visit will be sent as part of the data exchange via API between your website and Lockme. All customer personal data will remain only on your website. Note! If you edit such a reservation from the lockme panel, there is a risk that it will delete the data in your reservation system. Remember to manage such reservations only through your system.</small>';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'id_prefix',
            'Bookings ID prefix',
            function () {
                echo '<input name="lockme_settings[id_prefix]" type="text" value="'.($this->options['id_prefix'] ?? '').'" /> <small>Set this if you may have conflicts on external ID (like when you reset database, change booking plugin or website or have more than one connected integration). IMPORTANT: changing this value on working system <strong>will break your integration</strong>.</small>';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'api_domain',
            'API domain',
            function () {
                echo '<input name="lockme_settings[api_domain]" type="text" value="'.$this->options['api_domain'].'" placeholder="https://api.lock.me" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'api_url',
            'Webhook URL',
            function () {
                echo '<input readonly type="text" value="'.get_site_url().'/?lockme_api='.$this->url_key.'" onfocus="select()" /> <small>You need to add an integration of type webhook on Lockme to get updates about bookings automatically. Use this value as a webhook URL address and use the nevest API version from 2.x options.</small>';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'redirect_uri',
            'Redirect URI',
            function () {
                echo '<input readonly type="text" value="'.get_admin_url().'options-general.php?page=lockme_integration&tab=api_options" onfocus="select()" /> <small>set in Lockme cockpit as a redirect URI for your API app.</small>';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        foreach ($this->available_plugins as $k=>$plugin) {
            $plugin->RegisterSettings();
        }
    }

    public function admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h2>LockMe integration</h2>';

        echo '<p><strong>IMPORTANT!</strong> Please remember that this plugin purpose is to help you with synchronizing data between your site and Lockme. Author does not take any responsibility for correct data synchronization or any side effects of using it.</p>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '    <a href="?page=lockme_integration&tab=api_options" class="nav-tab '.($this->tab ===
                                                                                       'api_options'?'nav-tab-active':'').'">API settings</a>';
        foreach ($this->available_plugins as $k=>$plugin) {
            echo '    <a href="?page=lockme_integration&tab='.$k.'_plugin" class="nav-tab '.($this->tab === $k.'_plugin'?'nav-tab-active':'').'">'.$plugin->getPluginName().' plugin</a>';
        }
        echo '    <a href="?page=lockme_integration&tab=api_logs" class="nav-tab '.($this->tab ===
                                                                                       'api_logs'?'nav-tab-active':'').'">API error logs</a>';
        echo '</h2>';

        if ($this->tab === 'api_logs') {
            $logTable = new LogTable();
            $logTable->prepare_items();

            echo '<div class="wrap">';
            echo '<h2>Lockme API error logs</h2>';
            echo '<form method="get">';
            $logTable->display();
            echo '</form>';
            echo '</div>';

            return;
        }

        echo '<form method="post" action="options.php">';

        if ($this->tab === 'api_options') {
            settings_fields('lockme-admin');
            do_settings_sections('lockme-admin');

            $api = $this->GetApi();
            if ($api) {
                try {
                    $test = $api->Test();
                    if ($test === 'OK') {
                        echo '<p>Connection to LockMe API <strong>CORRECT</strong>.</p>';
                        $user = $api->getResourceOwner();
                        echo '<p>Logged in to Lockme as: <strong>'.$user->toArray()['nick'].'</strong></p>';
                    } else {
                        echo '<p>Response <strong>ERROR</strong>.</p>';
                    }
                } catch (Exception $e) {
                    echo '<p><strong>API ERROR: '.$e->getMessage().'.</p>';
                    if ($e instanceof IdentityProviderException) {
                        echo '<p>Response from Lockme:<br><textarea readonly>'.$e->getResponseBody().'</textarea></p>';
                    }
                }

                delete_option('lockme_oauth2_state');
                $params = [];
                $authorizationUrl = $api->getAuthorizationUrl(['rooms_manage'], $params);
                add_option('lockme_oauth2_state', $params['oauth2_lockme_state'], false, false);

                echo '<p><a href="'.$authorizationUrl.'">Click here</a> to connect the plugin with Lockme.</p>';
                echo '<p>Custom Oauth2 Access Token (use <b>only if you know what are you doing</b>):</p>';
                echo '<p><textarea name="oauth_token"></textarea></p>';
            } else {
                echo '<p><strong>NO API connection</strong>.</p>';
            }
            $token = get_option('lockme_oauth2_token');
            echo '<p>Current access token (don\'t share with anyone!)</p>';
            echo '<p><textarea readonly>'.\json_encode($token).'</textarea></p>';
        } else {
            foreach ($this->available_plugins as $k=>$plugin) {
                if ($this->tab === $k.'_plugin') {
                    try {
                        $plugin->DrawForm();
                    }catch (Exception $e){
                        echo '<p>Configuration error. Details: '.$e->getMessage().'</p>';
                    }
                }
            }
        }

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function AnonymizeData(array $data): array
    {
        if($this->options['rodo_mode']){
            return array_filter(
                $data,
                static function($key) {
                    return in_array(
                        $key,
                        [
                            'roomid',
                            'date',
                            'hour',
                            'status',
                            'extid'
                        ]
                    );
                },
                ARRAY_FILTER_USE_KEY
            );
        }
        if ($data['extid'] && $this->options['id_prefix']) {
            $data['extid'] = $this->options['id_prefix'].$data['extid'];
        }
        return $data;
    }

    public function activate(): void
    {
        wp_set_options_autoload([
            'lockme_settings',
            'lockme_url_key',
            'lockme_oauth2_token',
            'lockme_db_ver',
            'lockme_amelia',
            'lockme_app',
            'lockme_booked',
            'lockme_bookly',
            'lockme_cpabc',
            'lockme_dopbsp',
            'lockme_easyapp',
            'lockme_ezscm',
            'lockme_woo',
            'lockme_wpb',
            'lockme_wpdevart',
        ], false);

        $this->createDatabase();
    }

    public function createDatabase(): void
    {
        global $wpdb;

        if (get_option('lockme_db_ver') !== self::DB_VER) {
            $table = $wpdb->prefix.'lockme_log';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table (
              id int(11) NOT NULL AUTO_INCREMENT,
              time timestamp DEFAULT current_timestamp NOT NULL,
              method varchar(10) NOT NULL,
              uri varchar(255) NOT NULL,
              params mediumtext NOT NULL,
              response mediumtext NOT NULL,
              PRIMARY KEY  (id)
            ) $charset_collate";

            require_once ABSPATH.'wp-admin/includes/upgrade.php';

            update_option('lockme_db_ver', self::DB_VER, false);
        }
    }
}
