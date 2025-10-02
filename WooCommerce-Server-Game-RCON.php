<?php
/**
 * Plugin Name: WooCommerce Server Game RCON
 * Description: Server Game RCON - HPOS compatible, grouped sends, dynamic variables, per-order history, debug mode - Optimisé pour Valheim
 * Version: 1.0.3
 * Author: Skylide
 * Requires PHP: 7.4
 * GitHub Plugin URI: skylidefr/WooCommerce-Server-Game-RCON
 * GitHub Branch: main
 * Author URI: https://github.com/skylidefr/WooCommerce-Server-Game-RCON/
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Server_Game_RCON')) {

class WC_Server_Game_RCON {

    private $option_name = 'server_game_rcon_settings';
    private $log_file;
    private $hook_retry = 'server_game_rcon_retry_failed';
    private $history_meta = '_server_game_rcon_log';
    private $max_history_entries = 50;
    private $max_command_length = 500;
    private $plugin_file;
    private $version;

    public function __construct() {
        $this->plugin_file = __FILE__;
        
        $upload_dir = wp_upload_dir();
        $this->log_file = trailingslashit($upload_dir['basedir']) . 'server-game-rcon-debug.log';

        register_activation_hook(__FILE__, [$this, 'on_activation']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivation']);

        if (is_admin()) {
            new ServerGameRCONGitHubUpdater($this->plugin_file);
        }

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        add_action('add_meta_boxes', [$this, 'add_product_metabox']);
        add_action('save_post_product', [$this, 'save_product_metabox']);

        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'render_order_manual_send_button'], 10, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'render_order_rcon_history'], 20, 1);

        add_action('woocommerce_order_status_changed', [$this, 'maybe_send_rcon'], 10, 4);

        add_action('woocommerce_after_add_to_cart_form', [$this, 'render_product_page_fields']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_fields_before_cart'], 10, 3);
        add_action('woocommerce_add_to_cart', [$this, 'save_fields_to_session']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_session_fields_to_order'], 5, 2);
        
        add_action('woocommerce_after_checkout_billing_form', [$this, 'add_billing_custom_fields']);
        add_action('woocommerce_checkout_process', [$this, 'validate_game_username']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_custom_checkout_fields_new'], 10, 2);

        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_columns'], 20, 1);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_columns_legacy'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_order_columns_hpos'], 10, 2);

        add_action('wp_ajax_server_game_test_rcon_connection', [$this, 'ajax_test_rcon_connection']);
        add_action('wp_ajax_server_game_send_rcon_manual', [$this, 'ajax_send_rcon_manual']);
        add_action('wp_ajax_server_game_reset_rcon_status', [$this, 'ajax_reset_rcon_status']);
        add_action('wp_ajax_server_game_clear_logs', [$this, 'ajax_clear_logs']);
        add_action($this->hook_retry, [$this, 'retry_failed_commands'], 10, 1);

        add_action('server_game_rcon_cleanup_logs', [$this, 'cleanup_old_logs']);
        add_action('woocommerce_checkout_order_processed', [$this, 'cleanup_session_fields']);
    }
    // NOUVELLE MÉTHODE - Vérifie si le produit utilise les variables
    private function product_requires_user_fields($product_id) {
        $commands = get_post_meta($product_id, '_server_game_rcon_commands', true);
        
        if (empty($commands) || !is_array($commands)) {
            return ['username' => false, 'steamid' => false];
        }
        
        $all_commands = implode(' ', $commands);
        
        return [
            'username' => strpos($all_commands, '{game_username}') !== false,
            'steamid' => strpos($all_commands, '{steam_id}') !== false
        ];
    }

    private function product_has_rcon_commands($product_id) {
        $commands = get_post_meta($product_id, '_server_game_rcon_commands', true);
        return !empty($commands) && is_array($commands) && count($commands) > 0;
    }

    // MÉTHODE MODIFIÉE
    public function render_product_page_fields() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $required_fields = $this->product_requires_user_fields($product->get_id());
        
        // Si le produit n'utilise aucune de ces variables, on n'affiche rien
        if (!$required_fields['username'] && !$required_fields['steamid']) {
            return;
        }
        
        $username_enabled = $this->is_username_field_enabled() && $required_fields['username'];
        $steamid_enabled = $this->is_steamid_field_enabled() && $required_fields['steamid'];
        
        if (!$username_enabled && !$steamid_enabled) {
            return;
        }
        
        $session_username = WC()->session ? WC()->session->get('rcon_game_username', '') : '';
        $session_steamid = WC()->session ? WC()->session->get('rcon_steam_id', '') : '';
        
        ?>
        <style>
        .server-game-rcon-product-fields {
            clear: both !important;
            margin: 20px 0 !important;
            padding: 15px !important;
            background: #f8f9fa !important;
            border-left: 4px solid #2196F3 !important;
            box-sizing: border-box !important;
            max-width: 500px !important;
            width: 100% !important;
        }
        .server-game-rcon-product-fields h4 {
            margin: 0 0 8px 0 !important;
            color: #2196F3 !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            line-height: 1.4 !important;
        }
        .server-game-rcon-product-fields .description-text {
            margin: 0 0 12px 0 !important;
            color: #666 !important;
            font-size: 12px !important;
            line-height: 1.5 !important;
        }
        .server-game-rcon-product-fields .form-row {
            margin-bottom: 12px !important;
        }
        .server-game-rcon-product-fields label {
            font-size: 13px !important;
            font-weight: 500 !important;
        }
        .server-game-rcon-product-fields input[type="text"] {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            font-size: 14px !important;
        }
        </style>
        
        <div class="server-game-rcon-product-fields">
            <h4>🎮 Informations de jeu requises</h4>
            <p class="description-text">Ces informations seront utilisées pour vous attribuer automatiquement vos achats dans le jeu.</p>
            
            <?php
            if ($username_enabled) {
                woocommerce_form_field('rcon_game_username', [
                    'type'        => 'text',
                    'class'       => ['form-row', 'form-row-wide'],
                    'label'       => __('Pseudo In-Game *', 'wc-server-game-rcon'),
                    'placeholder' => __('Votre nom d\'utilisateur exact dans le jeu', 'wc-server-game-rcon'),
                    'required'    => true,
                    'custom_attributes' => [
                        'maxlength' => '50',
                        'pattern'   => '[a-zA-Z0-9_.-]+',
                        'title'     => 'Seuls les lettres, chiffres, tirets, points et underscores sont autorisés'
                    ]
                ], $session_username);
            }
            
            if ($steamid_enabled) {
                woocommerce_form_field('rcon_steam_id', [
                    'type'        => 'text',
                    'class'       => ['form-row', 'form-row-wide'],
                    'label'       => __('SteamID64 *', 'wc-server-game-rcon'),
                    'placeholder' => __('Votre SteamID64 (17 chiffres)', 'wc-server-game-rcon'),
                    'required'    => true,
                    'description' => __('Trouvez votre SteamID64 sur <a href="https://steamid.io/" target="_blank" rel="noopener">steamid.io</a>', 'wc-server-game-rcon'),
                    'custom_attributes' => [
                        'maxlength' => '17',
                        'pattern'   => '[0-9]{17}',
                        'title'     => 'Votre SteamID64 doit contenir exactement 17 chiffres'
                    ]
                ], $session_steamid);
            }
            ?>
        </div>
        <?php
    }

    // MÉTHODE MODIFIÉE
    public function validate_fields_before_cart($passed, $product_id, $quantity) {
        $required_fields = $this->product_requires_user_fields($product_id);
        
        // Si le produit n'utilise pas ces variables, pas de validation
        if (!$required_fields['username'] && !$required_fields['steamid']) {
            return $passed;
        }
        
        if ($this->is_username_field_enabled() && $required_fields['username']) {
            if (empty($_POST['rcon_game_username'])) {
                wc_add_notice(__('Le pseudo In-Game est requis pour ce produit.', 'wc-server-game-rcon'), 'error');
                return false;
            }
            
            $username = sanitize_text_field($_POST['rcon_game_username']);
            
            if (strlen($username) > 50 || strlen($username) < 2) {
                wc_add_notice(__('Le pseudo doit contenir entre 2 et 50 caractères.', 'wc-server-game-rcon'), 'error');
                return false;
            }
            
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                wc_add_notice(__('Le pseudo ne peut contenir que des lettres, chiffres, tirets, points et underscores.', 'wc-server-game-rcon'), 'error');
                return false;
            }
        }
        
        if ($this->is_steamid_field_enabled() && $required_fields['steamid']) {
            if (empty($_POST['rcon_steam_id'])) {
                wc_add_notice(__('Le SteamID64 est requis pour ce produit.', 'wc-server-game-rcon'), 'error');
                return false;
            }
            
            $steam_id = sanitize_text_field($_POST['rcon_steam_id']);
            
            if (!preg_match('/^[0-9]{17}$/', $steam_id)) {
                wc_add_notice(__('Le SteamID64 doit contenir exactement 17 chiffres.', 'wc-server-game-rcon'), 'error');
                return false;
            }
            
            if (strpos($steam_id, '7656119') !== 0) {
                wc_add_notice(__('Le SteamID64 semble invalide. Il doit commencer par 7656119.', 'wc-server-game-rcon'), 'error');
                return false;
            }
        }
        
        return $passed;
    }
    public function save_fields_to_session() {
        if (!WC()->session) {
            return;
        }
        
        if ($this->is_username_field_enabled() && !empty($_POST['rcon_game_username'])) {
            $username = $this->sanitize_game_username($_POST['rcon_game_username']);
            WC()->session->set('rcon_game_username', $username);
            $this->debug_log("Game username saved to session: {$username}");
        }
        
        if ($this->is_steamid_field_enabled() && !empty($_POST['rcon_steam_id'])) {
            $steam_id = $this->sanitize_steam_id($_POST['rcon_steam_id']);
            WC()->session->set('rcon_steam_id', $steam_id);
            $this->debug_log("SteamID saved to session: {$steam_id}");
        }
    }

    public function save_session_fields_to_order($order, $data) {
        if (!WC()->session) {
            return;
        }
        
        $username = WC()->session->get('rcon_game_username', '');
        $steam_id = WC()->session->get('rcon_steam_id', '');
        
        if ($this->is_username_field_enabled() && !empty($username)) {
            $this->set_order_meta($order, '_game_username', $username);
            $this->set_order_meta($order, '_game_username_source', 'product_page');
            $this->set_order_meta($order, '_game_username_verified_at', current_time('mysql'));
            $this->debug_log("Game username saved to order #{$order->get_id()}: {$username}");
        }
        
        if ($this->is_steamid_field_enabled() && !empty($steam_id)) {
            $this->set_order_meta($order, '_steam_id', $steam_id);
            $this->set_order_meta($order, '_steam_id_source', 'product_page');
            $this->set_order_meta($order, '_steam_id_verified_at', current_time('mysql'));
            $this->debug_log("SteamID saved to order #{$order->get_id()}: {$steam_id}");
        }
    }

    public function cleanup_session_fields($order_id) {
        if (!WC()->session) {
            return;
        }
        
        WC()->session->__unset('rcon_game_username');
        WC()->session->__unset('rcon_steam_id');
        $this->debug_log("Session fields cleaned for order #{$order_id}");
    }
    // MÉTHODES UTILITAIRES
    
    private function getVersion() {
        if (!isset($this->version)) {
            $plugin_data = get_plugin_data($this->plugin_file);
            $this->version = $plugin_data['Version'];
        }
        return $this->version;
    }

    public function on_activation() {
        if (!file_exists($this->log_file)) {
            @file_put_contents($this->log_file, "Server Game RCON log created: " . date('c') . PHP_EOL);
        }
        
        if (!wp_next_scheduled('server_game_rcon_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'server_game_rcon_cleanup_logs');
        }
    }

    public function on_deactivation() {
        wp_clear_scheduled_hook('server_game_rcon_cleanup_logs');
        wp_clear_scheduled_hook($this->hook_retry);
    }

    public function cleanup_old_logs() {
        if (!file_exists($this->log_file) || filesize($this->log_file) <= 10 * 1024 * 1024) {
            return;
        }

        try {
            $temp_file = $this->log_file . '.tmp';
            $file = new SplFileObject($this->log_file, 'r');
            $temp = new SplFileObject($temp_file, 'w');
            
            $file->seek(PHP_INT_MAX);
            $total_lines = $file->key();
            
            $start_line = max(0, $total_lines - 1000);
            $file->seek($start_line);
            
            while (!$file->eof()) {
                $temp->fwrite($file->fgets());
            }
            
            unset($file);
            unset($temp);
            
            @rename($temp_file, $this->log_file);
        } catch (Exception $e) {
            $this->debug_log("Cleanup failed: " . $e->getMessage(), null, 'ERROR');
        }
    }

    private function debug_log($message, $context = null, $level = 'INFO') {
        if (!$this->is_debug_enabled()) {
            return;
        }
        
        if (is_array($context) && isset($context['password'])) {
            $context['password'] = '[REDACTED]';
        }
        if (is_string($context) && strpos($context, 'password') !== false) {
            $context = '[REDACTED - Contains password]';
        }
        
        $entry = '[' . date('c') . "] [$level] " . $message;
        if ($context !== null) {
            $entry .= ' | ' . print_r($context, true);
        }
        $entry .= PHP_EOL;
        @file_put_contents($this->log_file, $entry, FILE_APPEND | LOCK_EX);
    }

    public function is_debug_enabled() {
        $options = get_option($this->option_name, []);
        return !empty($options['debug']);
    }

    private function is_username_field_enabled() {
        $opts = get_option($this->option_name, []);
        return !empty($opts['enable_username_field']);
    }

    private function is_steamid_field_enabled() {
        $opts = get_option($this->option_name, []);
        return !empty($opts['enable_steamid_field']);
    }

    private function should_verify_player_exists() {
        $opts = get_option($this->option_name, []);
        return !empty($opts['verify_player_exists']);
    }

    private function get_order_id($order) {
        return is_object($order) ? $order->get_id() : intval($order);
    }

    private function get_order_meta($order, $meta_key, $single = true) {
        if (is_object($order)) {
            return $order->get_meta($meta_key, $single);
        } else {
            $order_id = intval($order);
            return get_post_meta($order_id, $meta_key, $single);
        }
    }

    private function set_order_meta($order, $meta_key, $meta_value) {
        if (is_object($order)) {
            $order->update_meta_data($meta_key, $meta_value);
            $order->save();
        } else {
            $order_id = intval($order);
            update_post_meta($order_id, $meta_key, $meta_value);
        }
    }

    private function delete_order_meta($order, $meta_key) {
        if (is_object($order)) {
            $order->delete_meta_data($meta_key);
            $order->save();
        } else {
            $order_id = intval($order);
            delete_post_meta($order_id, $meta_key);
        }
    }

    private function sanitize_game_username($username) {
        $username = sanitize_text_field($username);
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
    }

    private function sanitize_steam_id($steam_id) {
        $steam_id = sanitize_text_field($steam_id);
        return preg_replace('/[^0-9]/', '', $steam_id);
    }
    // INTERFACE ADMIN
    
    public function add_admin_menu() {
        add_options_page('Server Game RCON', 'Server Game RCON', 'manage_options', 'server-game-rcon', [$this, 'options_page']);
    }

    public function settings_init() {
        register_setting('server_game_rcon_group', $this->option_name, [$this, 'sanitize_settings']);
        add_settings_section('server_game_rcon_section', 'Connexion RCON', null, 'serverGameRcon');
        add_settings_field('servers', 'Serveurs RCON', [$this, 'field_servers_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('timeout', 'Timeout (s)', [$this, 'field_timeout_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('enable_username_field', 'Champ pseudo', [$this, 'field_enable_username_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('enable_steamid_field', 'Champ SteamID', [$this, 'field_enable_steamid_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('verify_player_exists', 'Vérification joueur', [$this, 'field_verify_player_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('auto_retry', 'Retry failed', [$this, 'field_auto_retry_render'], 'serverGameRcon', 'server_game_rcon_section');
        add_settings_field('debug', 'Debug', [$this, 'field_debug_render'], 'serverGameRcon', 'server_game_rcon_section');
    }

    public function sanitize_settings($input) {
        $out = [];
        
        $out['timeout'] = max(5, min(30, intval($input['timeout'] ?? 10)));
        $out['enable_username_field'] = !empty($input['enable_username_field']) ? 1 : 0;
        $out['enable_steamid_field'] = !empty($input['enable_steamid_field']) ? 1 : 0;
        $out['auto_retry'] = !empty($input['auto_retry']) ? 1 : 0;
        $out['verify_player_exists'] = !empty($input['verify_player_exists']) ? 1 : 0;
        $out['debug'] = !empty($input['debug']) ? 1 : 0;

        $servers = [];
        if (isset($input['servers'])) {
            if (is_array($input['servers'])) {
                $servers = $input['servers'];
            } else if (is_string($input['servers'])) {
                $decoded = json_decode(stripslashes($input['servers']), true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $servers = $decoded;
                }
            }
        }

        $out['servers'] = [];
        foreach ($servers as $s) {
            if (!is_array($s)) continue;
            
            $name = sanitize_text_field($s['name'] ?? '');
            $host = sanitize_text_field($s['host'] ?? '');
            $port = max(1, min(65535, intval($s['port'] ?? 0)));
            $password = sanitize_text_field($s['password'] ?? '');
            $timeout = max(5, min(30, intval($s['timeout'] ?? $out['timeout'])));
            
            if (!empty($host) && !filter_var($host, FILTER_VALIDATE_IP) && !filter_var('http://' . $host, FILTER_VALIDATE_URL)) {
                if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
                    continue;
                }
            }
            
            if (!empty($host) && !empty($password) && $port > 0) {
                $out['servers'][] = [
                    'name' => $name,
                    'host' => $host,
                    'port' => $port,
                    'password' => $password,
                    'timeout' => $timeout,
                ];
            }
        }

        if (empty($out['servers']) && !empty($input['host']) && !empty($input['password'])) {
            $out['servers'][] = [
                'name' => 'Default',
                'host' => sanitize_text_field($input['host']),
                'port' => max(1, min(65535, intval($input['port'] ?? 2457))),
                'password' => sanitize_text_field($input['password']),
                'timeout' => $out['timeout'],
            ];
        }

        return $out;
    }

    public function field_timeout_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="number" name="%s[timeout]" value="%d" min="5" max="30" step="1"> secondes', esc_attr($this->option_name), intval($opts['timeout'] ?? 10));
        echo '<p class="description">Recommandé: 10-15 secondes pour Valheim</p>';
    }

    public function field_enable_username_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="checkbox" name="%s[enable_username_field]" value="1" %s> Afficher le champ pseudo de jeu', 
               esc_attr($this->option_name), 
               checked(!empty($opts['enable_username_field']), true, false));
        echo '<p class="description">Variable: {game_username} - S\'affiche sur la page produit</p>';
    }

    public function field_enable_steamid_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="checkbox" name="%s[enable_steamid_field]" value="1" %s> Afficher le champ SteamID64', 
               esc_attr($this->option_name), 
               checked(!empty($opts['enable_steamid_field']), true, false));
        echo '<p class="description">Recommandé pour Valheim. Variable: {steam_id} - S\'affiche sur la page produit</p>';
    }

    public function field_verify_player_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="checkbox" name="%s[verify_player_exists]" value="1" %s> Vérifier que le joueur existe sur le serveur', 
               esc_attr($this->option_name), 
               checked(!empty($opts['verify_player_exists']), true, false));
        echo '<p class="description"><strong>Non recommandé pour Valheim</strong></p>';
    }

    public function field_auto_retry_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="checkbox" name="%s[auto_retry]" value="1" %s> Planifier retry pour commandes échouées', 
               esc_attr($this->option_name), 
               checked(!empty($opts['auto_retry']), true, false));
    }

    public function field_debug_render() {
        $opts = get_option($this->option_name, []);
        printf('<input type="checkbox" name="%s[debug]" value="1" %s> Activer debug détaillé', 
               esc_attr($this->option_name), 
               checked(!empty($opts['debug']), true, false));
    }
    public function field_servers_render() {
        $opts = get_option($this->option_name, []);
        $servers = $opts['servers'] ?? [];
        $json = json_encode($servers, JSON_UNESCAPED_SLASHES);
        ?>
        <table class="form-table">
        <tr>
            <th scope="row"><label>Serveurs RCON</label></th>
            <td>
                <table id="server-game-rcon-servers-table" class="widefat" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th style="width:18%;">Nom</th>
                            <th style="width:22%;">Host</th>
                            <th style="width:8%;">Port</th>
                            <th style="width:22%;">Mot de passe</th>
                            <th style="width:8%;">Timeout</th>
                            <th style="width:8%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <p>
                    <button type="button" class="button" id="server-game-add-rcon-server">+ Ajouter un serveur</button>
                </p>
                <input type="hidden" name="<?php echo esc_attr($this->option_name); ?>[servers]" id="server_game_rcon_servers" value="<?php echo esc_attr($json); ?>" />
                <p class="description">Port RCON Valheim par défaut: <strong>2457</strong></p>
            </td>
        </tr>
        </table>

        <script>
        jQuery(document).ready(function($){
            const tbody = $('#server-game-rcon-servers-table tbody');
            const input = $('#server_game_rcon_servers');

            function rowHtml(srv, idx) {
                const escapedName = (srv.name || '').replace(/"/g, '&quot;');
                const escapedHost = (srv.host || '').replace(/"/g, '&quot;');
                const escapedPassword = (srv.password || '').replace(/"/g, '&quot;');
                
                return `<tr data-idx="${idx}">
                    <td><input type="text" class="regular-text srv-name" value="${escapedName}" maxlength="50" /></td>
                    <td><input type="text" class="regular-text srv-host" value="${escapedHost}" maxlength="253" /></td>
                    <td><input type="number" class="small-text srv-port" value="${srv.port||2457}" min="1" max="65535" /></td>
                    <td><input type="password" class="regular-text srv-password" value="${escapedPassword}" maxlength="100" /></td>
                    <td><input type="number" class="small-text srv-timeout" value="${srv.timeout||10}" min="5" max="30" /></td>
                    <td><button type="button" class="button link-delete srv-remove">Supprimer</button></td>
                </tr>`;
            }

            function render() {
                tbody.empty();
                let servers = [];
                try { servers = JSON.parse(input.val()); } catch(e) { servers = []; }
                servers.forEach((s, i) => {
                    tbody.append(rowHtml(s, i));
                });
                bindEvents();
            }

            function bindEvents() {
                tbody.find('input').off('input.server_game').on('input.server_game', function(){
                    saveFromUI();
                });
                tbody.find('.srv-remove').off('click.server_game').on('click.server_game', function(e){
                    e.preventDefault();
                    const tr = $(this).closest('tr');
                    const idx = tr.index();
                    let servers = [];
                    try { servers = JSON.parse(input.val()); } catch(e) { servers = []; }
                    servers.splice(idx, 1);
                    input.val(JSON.stringify(servers));
                    render();
                });
            }

            function saveFromUI() {
                let servers = [];
                tbody.find('tr').each(function(){
                    const row = $(this);
                    const name = row.find('.srv-name').val().trim();
                    const host = row.find('.srv-host').val().trim();
                    const port = parseInt(row.find('.srv-port').val()) || 2457;
                    const password = row.find('.srv-password').val();
                    const timeout = parseInt(row.find('.srv-timeout').val()) || 10;
                    
                    if (host && password) {
                        servers.push({
                            name: name,
                            host: host,
                            port: Math.max(1, Math.min(65535, port)),
                            password: password,
                            timeout: Math.max(5, Math.min(30, timeout))
                        });
                    }
                });
                input.val(JSON.stringify(servers));
            }

            $('#server-game-add-rcon-server').off('click.server_game').on('click.server_game', function(e){
                e.preventDefault();
                let servers = [];
                try { servers = JSON.parse(input.val()); } catch(e) { servers = []; }
                servers.push({name:'',host:'',port:2457,password:'',timeout:10});
                input.val(JSON.stringify(servers));
                render();
            });

            render();
        });
        </script>
        <?php
    }
    public function options_page() {
        if (!current_user_can('manage_options')) return;
        
        $updater = new ServerGameRCONGitHubUpdater($this->plugin_file);
        $has_update = false;
        $new_version = '';
        
        if (method_exists($updater, 'hasUpdate')) {
            $has_update = $updater->hasUpdate();
            if ($has_update && method_exists($updater, 'getNewVersion')) {
                $new_version = $updater->getNewVersion();
            }
        }
        ?>
        <div class="wrap">
            <h1>Server Game RCON - Valheim Edition</h1>
            
            <div class="notice notice-info">
                <p><strong>Version actuelle :</strong> <?php echo esc_html($this->getVersion()); ?>
                <?php if ($has_update && $new_version): ?>
                    <span style="color: #d54e21;"> - Mise à jour disponible : v<?php echo esc_html($new_version); ?> !</span>
                    <a href="<?php echo admin_url('plugins.php'); ?>" class="button button-secondary" style="margin-left: 10px;">Mettre à jour</a>
                <?php endif; ?>
                </p>
            </div>

            <div class="notice notice-success">
                <p><strong>Mode hybride activé :</strong> Les champs s'affichent sur la page produit (compatible Elementor)</p>
            </div>

            <div class="notice notice-warning">
                <p><strong>Configuration Valheim :</strong></p>
                <ul style="margin-left: 20px;">
                    <li>Port RCON par défaut : <strong>2457</strong></li>
                    <li>Timeout recommandé : <strong>10-15 secondes</strong></li>
                    <li>Commandes fiables : <code>broadcast</code>, <code>say</code>, <code>save</code></li>
                    <li>Variables disponibles : {game_username}, {steam_id}, {billing_first_name}, {order_id}</li>
                </ul>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('server_game_rcon_group');
                do_settings_sections('serverGameRcon');
                submit_button('Enregistrer');
                ?>
            </form>

            <p><button id="server-game-test-rcon" class="button button-secondary">Tester la connexion</button></p>

            <h2>Logs (100 dernières lignes)</h2>
            <?php if (file_exists($this->log_file)): 
                $content = file_get_contents($this->log_file);
                $lines = explode("\n", $content);
                $last_lines = array_slice($lines, -100);
                $limited_content = implode("\n", $last_lines);
            ?>
                <textarea readonly style="width:100%;height:300px;font-family:monospace;"><?php echo esc_textarea($limited_content); ?></textarea>
                <p><button id="server-game-clear-logs" class="button button-secondary">Vider les logs</button></p>
            <?php else: ?>
                <p>Aucun log</p>
            <?php endif; ?>
        </div>
        <?php
    }
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['settings_page_server-game-rcon', 'post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }
        
        wp_enqueue_script('jquery');
        $nonce_test = wp_create_nonce('server_game_test_rcon_nonce');
        $nonce_send = wp_create_nonce('server_game_send_rcon_manual');
        $nonce_reset = wp_create_nonce('server_game_reset_rcon_status');
        
        $script = "
        jQuery(document).ready(function($){
            let lastRequest = 0;
            const minInterval = 1000;
            
            function throttle(fn) {
                const now = Date.now();
                if (now - lastRequest < minInterval) {
                    alert('Veuillez patienter avant de faire une nouvelle requête.');
                    return;
                }
                lastRequest = now;
                fn();
            }
            
            $('#server-game-test-rcon').off('click.server_game').on('click.server_game', function(e){
                e.preventDefault();
                throttle(function() {
                    var button = $('#server-game-test-rcon');
                    var original = button.text();
                    
                    var servers = [];
                    try {
                        servers = JSON.parse($('#server_game_rcon_servers').val() || '[]');
                    } catch(e) {
                        servers = [];
                    }
                    
                    if (servers.length === 0) {
                        alert('Aucun serveur configuré pour le test');
                        return;
                    }
                    
                    var server = servers[0];
                    var timeout = $(\"input[name='{$this->option_name}[timeout]']\").val() || server.timeout || 10;
                    
                    button.prop('disabled', true).text('Test en cours...');
                    $.post(ajaxurl, {
                        action: 'server_game_test_rcon_connection',
                        nonce: '{$nonce_test}',
                        host: server.host || '',
                        port: server.port || '',
                        password: server.password || '',
                        timeout: timeout
                    }, function(resp){
                        if (resp && resp.success) {
                            alert('Connexion OK sur ' + (server.name || 'serveur') + ': ' + (resp.data.message || 'OK'));
                        } else {
                            alert('Échec connexion: ' + (resp.data && resp.data.message ? resp.data.message : 'Erreur inconnue'));
                        }
                    }).fail(function(xhr){
                        alert('Erreur AJAX: ' + xhr.status);
                    }).always(function(){ 
                        button.prop('disabled', false).text(original); 
                    });
                });
            });

            $(document).off('click.server_game', '.server-game-send-rcon').on('click.server_game', '.server-game-send-rcon', function(e){
                e.preventDefault();
                if (!confirm('Renvoyer les commandes RCON ?')) return;
                
                throttle(function() {
                    var orderId = $('.server-game-send-rcon').data('order-id');
                    var btn = $('.server-game-send-rcon');
                    var orig = btn.text();
                    btn.prop('disabled', true).text('Envoi...');
                    $.post(ajaxurl, {
                        action: 'server_game_send_rcon_manual',
                        nonce: '{$nonce_send}',
                        order_id: orderId
                    }, function(resp){
                        if (resp && resp.success) {
                            alert('Succès: ' + (resp.data.message || 'OK'));
                            location.reload();
                        } else {
                            alert('Échec: ' + (resp.data && resp.data.message ? resp.data.message : 'Erreur inconnue'));
                        }
                    }).fail(function(xhr){
                        alert('Erreur AJAX: ' + xhr.status);
                    }).always(function(){ 
                        btn.prop('disabled', false).text(orig); 
                    });
                });
            });
            
            $(document).off('click.server_game', '.server-game-reset-rcon').on('click.server_game', '.server-game-reset-rcon', function(e){
                e.preventDefault();
                if (!confirm('Réinitialiser le statut RCON ?')) return;
                
                throttle(function() {
                    var orderId = $('.server-game-reset-rcon').data('order-id');
                    var btn = $('.server-game-reset-rcon');
                    var orig = btn.text();
                    btn.prop('disabled', true).text('Reset...');
                    $.post(ajaxurl, {
                        action: 'server_game_reset_rcon_status',
                        nonce: '{$nonce_reset}',
                        order_id: orderId
                    }, function(resp){
                        if (resp && resp.success) {
                            alert('Statut réinitialisé');
                            location.reload();
                        } else {
                            alert('Échec: ' + (resp.data && resp.data.message ? resp.data.message : 'Erreur inconnue'));
                        }
                    }).fail(function(xhr){
                        alert('Erreur AJAX: ' + xhr.status);
                    }).always(function(){ 
                        btn.prop('disabled', false).text(orig); 
                    });
                });
            });

            $('#server-game-clear-logs').off('click.server_game').on('click.server_game', function(e){
                e.preventDefault();
                if (confirm('Vider les logs ?')) {
                    $.post(ajaxurl, {
                        action: 'server_game_clear_logs',
                        nonce: '{$nonce_test}'
                    }, function(resp){
                        if (resp && resp.success) {
                            location.reload();
                        }
                    });
                }
            });
        });
        ";

        wp_add_inline_script('jquery', $script);
    }
    public function add_product_metabox() {
        add_meta_box('server_game_rcon_product', 'Server Game RCON', [$this, 'render_product_metabox'], 'product', 'side', 'default');
    }

    public function render_product_metabox($post) {
        wp_nonce_field('server_game_rcon_product_save', 'server_game_rcon_product_nonce');
        $commands = get_post_meta($post->ID, '_server_game_rcon_commands', true);
        if (is_array($commands)) $value = implode("\n", $commands);
        else if (is_string($commands)) $value = $commands; 
        else $value = '';

        $opts = get_option($this->option_name, []);
        $servers = $opts['servers'] ?? [];
        $selected_server = get_post_meta($post->ID, '_server_game_rcon_server', true);
        ?>
        <p><label>Serveur RCON</label></p>
        <p>
            <select name="server_game_rcon_server" style="width:100%;">
                <option value="" <?php selected($selected_server, ''); ?>>Par défaut</option>
                <option value="all" <?php selected($selected_server, 'all'); ?>>Tous les serveurs</option>
                <?php
                if (!empty($servers) && is_array($servers)) {
                    foreach ($servers as $i => $s) {
                        $label = esc_html($s['name'] ?? ($s['host'] . ':' . ($s['port'] ?? '')));
                        printf('<option value="%d" %s>%s</option>', $i, selected((string)$selected_server, (string)$i, false), $label);
                    }
                }
                ?>
            </select>
        </p>

        <p><label>Commandes RCON (une par ligne)</label></p>
        <p><textarea name="server_game_rcon_commands" style="width:100%;height:120px;" maxlength="<?php echo $this->max_command_length * 10; ?>"><?php echo esc_textarea($value); ?></textarea></p>
        <p class="description"><strong>Variables:</strong><br>
        {game_username}, {steam_id}, {billing_first_name}, {billing_last_name}, {billing_email}, {order_id}</p>
        <p class="description"><strong>Exemples Valheim:</strong><br>
        <code>broadcast Bienvenue {billing_first_name} !</code><br>
        <code>say Merci pour votre achat #{order_id}</code></p>
        <p class="description" style="color: #0073aa;"><strong>Les champs s'affichent automatiquement sur la page produit si vous utilisez {game_username} ou {steam_id}</strong></p>
        <?php
    }

    public function save_product_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (empty($_POST['server_game_rcon_product_nonce']) || !wp_verify_nonce($_POST['server_game_rcon_product_nonce'], 'server_game_rcon_product_save')) return;
        
        $raw = sanitize_textarea_field($_POST['server_game_rcon_commands'] ?? '');
        $lines = array_filter(array_map('trim', explode("\n", $raw)), function($l){ 
            return $l !== '' && strlen($l) <= $this->max_command_length; 
        });
        
        update_post_meta($post_id, '_server_game_rcon_commands', $lines);
        
        $server_sel = sanitize_text_field($_POST['server_game_rcon_server'] ?? '');
        if (!in_array($server_sel, ['', 'all']) && !is_numeric($server_sel)) {
            $server_sel = '';
        }
        update_post_meta($post_id, '_server_game_rcon_server', $server_sel);
    }
    // GESTION DES COMMANDES ET AFFICHAGE
    
    public function render_order_manual_send_button($order) {
        if (!current_user_can('manage_woocommerce')) return;
        
        $order_id = $this->get_order_id($order);
        $already_sent = $this->get_order_meta($order, '_server_game_rcon_sent');
        
        $game_username = $this->get_order_meta($order, '_game_username');
        $steam_id = $this->get_order_meta($order, '_steam_id');
        $source = $this->get_order_meta($order, '_game_username_source');
        
        echo '<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 4px solid #2196F3;">';
        echo '<h4>Server Game RCON</h4>';
        
        if ($game_username || $steam_id) {
            echo '<p style="margin: 5px 0;"><strong>Données capturées:</strong></p>';
            echo '<ul style="margin: 5px 0 10px 20px;">';
            if ($game_username) echo '<li>Pseudo: <code>' . esc_html($game_username) . '</code>' . ($source ? ' <small>(via ' . esc_html($source) . ')</small>' : '') . '</li>';
            if ($steam_id) echo '<li>SteamID: <code>' . esc_html($steam_id) . '</code></li>';
            echo '</ul>';
        }
        
        echo '<button class="button server-game-send-rcon" data-order-id="'.esc_attr($order_id).'">Renvoyer commandes RCON</button>';
        
        if ($already_sent === 'yes') {
            $sent_at = $this->get_order_meta($order, '_server_game_rcon_sent_at');
            echo ' <span style="color:green;font-weight:bold;">✅ Déjà envoyé';
            if ($sent_at) {
                echo ' le ' . esc_html(date('d/m/Y à H:i', strtotime($sent_at)));
            }
            echo '</span>';
            echo '<br><button class="button button-secondary server-game-reset-rcon" data-order-id="'.esc_attr($order_id).'" style="margin-top:5px;">Réinitialiser statut</button>';
        } else {
            echo ' <span style="color:orange;">⚠️ Pas encore envoyé</span>';
        }
        echo '</div>';
    }

    public function render_order_rcon_history($order) {
        if (!current_user_can('manage_woocommerce')) return;
        
        $logs = $this->get_order_meta($order, $this->history_meta);
        if (empty($logs) || !is_array($logs)) {
            echo '<p><strong>Historique RCON :</strong> Aucun envoi enregistré.</p>';
            return;
        }
        
        echo '<div style="background:#f9f9f9;border:1px solid #e1e1e1;padding:8px;margin-top:8px;">';
        echo '<strong>Historique RCON :</strong><ul style="margin:0 0 0 18px;">';
        
        $recent_logs = array_slice($logs, -10);
        foreach ($recent_logs as $entry) {
            $time = esc_html($entry['time'] ?? '');
            $status = !empty($entry['success']) ? '✅' : '❌';
            $msg = esc_html($entry['message'] ?? (is_string($entry['data']) ? $entry['data'] : json_encode($entry['data'])));
            echo "<li>{$status} <small>{$time}</small> — {$msg}</li>";
        }
        echo '</ul></div>';
    }

    public function add_order_columns($columns) {
        $columns['rcon_status'] = __('RCON', 'wc-server-game-rcon');
        return $columns;
    }

    public function render_order_columns_legacy($column, $post_id) {
        if ($column === 'rcon_status') {
            $logs = get_post_meta($post_id, $this->history_meta, true);
            $this->render_rcon_status_column($logs);
        }
    }

    public function render_order_columns_hpos($column, $order) {
        if ($column === 'rcon_status') {
            $logs = $this->get_order_meta($order, $this->history_meta);
            $this->render_rcon_status_column($logs);
        }
    }

    private function render_rcon_status_column($logs) {
        if (empty($logs) || !is_array($logs)) {
            echo '<span style="color:gray;" title="Aucun envoi">—</span>';
        } else {
            $last = end($logs);
            $success = !empty($last['success']);
            $icon = $success ? '✅' : '❌';
            $color = $success ? 'green' : 'red';
            $title = $success ? 'Envoyé avec succès' : 'Échec d\'envoi';
            echo "<span style='color:{$color};' title='{$title}'>{$icon}</span>";
        }
    }
    // FALLBACK CHECKOUT CLASSIQUE
    
    public function add_billing_custom_fields($checkout) {
        $session_username = WC()->session ? WC()->session->get('rcon_game_username', '') : '';
        $session_steamid = WC()->session ? WC()->session->get('rcon_steam_id', '') : '';
        
        if ($session_username && $session_steamid) {
            return;
        }
        
        echo '<div id="server_game_rcon_fields">';
        echo '<h3>Informations de jeu</h3>';
        
        if ($this->is_username_field_enabled()) {
            woocommerce_form_field('game_username', [
                'type' => 'text',
                'class' => ['form-row-wide'],
                'label' => __('Nom d\'utilisateur de jeu *'),
                'placeholder' => __('Votre nom d\'utilisateur exact dans le jeu'),
                'required' => true,
                'description' => __('Ce nom doit correspondre exactement à votre pseudo dans le jeu.'),
                'custom_attributes' => [
                    'maxlength' => '50',
                    'pattern' => '[a-zA-Z0-9_.-]+',
                    'title' => 'Seuls les lettres, chiffres, tirets, points et underscores sont autorisés'
                ]
            ], $checkout->get_value('game_username'));
        }
        
        if ($this->is_steamid_field_enabled()) {
            woocommerce_form_field('steam_id', [
                'type' => 'text',
                'class' => ['form-row-wide'],
                'label' => __('SteamID64 *'),
                'placeholder' => __('Votre SteamID64 (17 chiffres)'),
                'required' => true,
                'description' => __('Trouvez votre SteamID64 sur <a href="https://steamid.io/" target="_blank">steamid.io</a>'),
                'custom_attributes' => [
                    'maxlength' => '17',
                    'pattern' => '[0-9]{17}',
                    'title' => 'Votre SteamID64 doit contenir exactement 17 chiffres'
                ]
            ], $checkout->get_value('steam_id'));
        }
        
        echo '</div>';
    }

    public function validate_game_username() {
        if (WC()->session && WC()->session->get('rcon_game_username', '')) {
            return;
        }
        
        if ($this->is_username_field_enabled()) {
            if (empty($_POST['game_username'])) {
                wc_add_notice(__('Le nom d\'utilisateur de jeu est requis.'), 'error');
                return;
            }
            
            $username = sanitize_text_field($_POST['game_username']);
            
            if (strlen($username) > 50 || strlen($username) < 2) {
                wc_add_notice(__('Le nom d\'utilisateur de jeu doit contenir entre 2 et 50 caractères.'), 'error');
                return;
            }
            
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                wc_add_notice(__('Le nom d\'utilisateur de jeu ne peut contenir que des lettres, chiffres, tirets, points et underscores.'), 'error');
                return;
            }
        }
        
        if ($this->is_steamid_field_enabled()) {
            if (empty($_POST['steam_id'])) {
                wc_add_notice(__('Le SteamID64 est requis.'), 'error');
                return;
            }
            
            $steam_id = sanitize_text_field($_POST['steam_id']);
            
            if (!preg_match('/^[0-9]{17}$/', $steam_id)) {
                wc_add_notice(__('Le SteamID64 doit contenir exactement 17 chiffres.'), 'error');
                return;
            }
            
            if (strpos($steam_id, '7656119') !== 0) {
                wc_add_notice(__('Le SteamID64 semble invalide. Il doit commencer par 7656119.'), 'error');
                return;
            }
        }
    }

    public function save_custom_checkout_fields_new($order, $data) {
        if ($this->get_order_meta($order, '_game_username_source') === 'product_page') {
            return;
        }
        
        if ($this->is_username_field_enabled() && !empty($_POST['game_username'])) {
            $username = $this->sanitize_game_username($_POST['game_username']);
            $this->set_order_meta($order, '_game_username', $username);
            $this->set_order_meta($order, '_game_username_source', 'checkout');
            $this->set_order_meta($order, '_game_username_verified_at', current_time('mysql'));
        }
        
        if ($this->is_steamid_field_enabled() && !empty($_POST['steam_id'])) {
            $steam_id = $this->sanitize_steam_id($_POST['steam_id']);
            $this->set_order_meta($order, '_steam_id', $steam_id);
            $this->set_order_meta($order, '_steam_id_source', 'checkout');
            $this->set_order_meta($order, '_steam_id_verified_at', current_time('mysql'));
        }
    }

    public function verify_player_exists_on_server($username) {
        $opts = get_option($this->option_name, []);
        $servers = $this->get_configured_servers($opts);
        
        if (empty($servers)) {
            return ['success' => false, 'message' => 'Aucun serveur configuré'];
        }
        
        foreach ($servers as $server) {
            $host = $server['host'] ?? '';
            $port = intval($server['port'] ?? 2457);
            $password = $server['password'] ?? '';
            $timeout = intval($server['timeout'] ?? 10);
            
            if (empty($host) || empty($password)) continue;
            
            $result = $this->execute_rcon_command($host, $port, $password, 'info', $timeout);
            
            if ($result['success']) {
                return ['success' => true, 'player_found' => true, 'note' => 'Valheim ne supporte pas la vérification de joueur via RCON'];
            }
        }
        
        return ['success' => false, 'message' => 'Impossible de se connecter aux serveurs'];
    }
    // AJAX HANDLERS
    
    public function ajax_test_rcon_connection() {
        check_ajax_referer('server_game_test_rcon_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes']);
        }
        
        $host = sanitize_text_field($_POST['host'] ?? '');
        $port = max(1, min(65535, intval($_POST['port'] ?? 2457)));
        $password = sanitize_text_field($_POST['password'] ?? '');
        $timeout = max(5, min(30, intval($_POST['timeout'] ?? 10)));
        
        if (empty($host) || empty($password)) {
            wp_send_json_error(['message' => 'Host et mot de passe requis']);
        }
        
        $res = $this->execute_rcon_command($host, $port, $password, 'say [RCON Test] Connexion OK', $timeout);
        if (!empty($res['success'])) {
            wp_send_json_success(['message' => 'Connexion RCON OK', 'response' => substr($res['body'] ?? '', 0, 100)]);
        } else {
            wp_send_json_error(['message' => 'Échec connexion: ' . ($res['error'] ?? 'unknown')]);
        }
    }

    public function ajax_send_rcon_manual() {
        check_ajax_referer('server_game_send_rcon_manual', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes']);
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) wp_send_json_error(['message' => 'ID de commande invalide']);
        
        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Commande introuvable']);
        
        $this->delete_order_meta($order, '_server_game_rcon_sent');
        
        $ok = $this->send_rcon_commands_grouped($order, true);
        if ($ok) {
            $this->set_order_meta($order, '_server_game_rcon_sent', 'yes');
            $this->set_order_meta($order, '_server_game_rcon_sent_at', current_time('mysql'));
            $this->set_order_meta($order, '_server_game_rcon_sent_method', 'manual');
            wp_send_json_success(['message' => 'Commandes RCON envoyées manuellement']);
        } else {
            wp_send_json_error(['message' => 'Échec envoi, voir logs']);
        }
    }

    public function ajax_reset_rcon_status() {
        check_ajax_referer('server_game_reset_rcon_status', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes']);
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) wp_send_json_error(['message' => 'ID de commande invalide']);
        
        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Commande introuvable']);
        
        $this->delete_order_meta($order, '_server_game_rcon_sent');
        $this->delete_order_meta($order, '_server_game_rcon_sent_at');
        $this->delete_order_meta($order, '_server_game_rcon_sent_method');
        
        $this->debug_log("RCON status reset for order {$order_id} by admin", null, 'INFO');
        wp_send_json_success(['message' => 'Statut réinitialisé']);
    }

    public function ajax_clear_logs() {
        check_ajax_referer('server_game_test_rcon_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes']);
        }
        
        if (file_exists($this->log_file)) {
            @file_put_contents($this->log_file, "Logs cleared: " . date('c') . PHP_EOL);
        }
        
        wp_send_json_success(['message' => 'Logs vidés']);
    }
    // LOGIQUE RCON PRINCIPALE
    
    public function maybe_send_rcon($order_id, $old_status, $new_status, $order) {
        $status = str_replace('wc-', '', (string) $new_status);
        
        $lock_file = sys_get_temp_dir() . '/rcon_lock_' . $order_id . '.lock';
        $lock_handle = @fopen($lock_file, 'c');
        
        if (!$lock_handle) {
            $this->debug_log("Could not create lock file for order {$order_id}", null, 'ERROR');
            return;
        }
        
        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            $this->debug_log("RCON already processing for order {$order_id}, skipping", null, 'INFO');
            fclose($lock_handle);
            return;
        }
        
        try {
            if ($status === 'completed') {
                if (!$order instanceof WC_Order) {
                    $order = wc_get_order($order_id);
                }
                if ($order) {
                    $already_sent = $this->get_order_meta($order, '_server_game_rcon_sent');
                    if ($already_sent === 'yes') {
                        $this->debug_log("RCON already sent for order {$order_id}, skipping", null, 'INFO');
                        return;
                    }
                    
                    $this->debug_log("Sending RCON for order {$order_id} ({$old_status} → {$new_status})", null, 'INFO');
                    $success = $this->send_rcon_commands_grouped($order);
                    
                    if ($success) {
                        $this->set_order_meta($order, '_server_game_rcon_sent', 'yes');
                        $this->set_order_meta($order, '_server_game_rcon_sent_at', current_time('mysql'));
                        $this->debug_log("RCON successfully sent for order {$order_id}", null, 'INFO');
                    }
                } else {
                    $this->debug_log("ERROR: Unable to load WC_Order for order_id={$order_id}", null, 'ERROR');
                }
            }
        } finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            @unlink($lock_file);
        }
    }

    public function send_rcon_commands_grouped($order_input, $manual = false) {
        $order = $order_input instanceof WC_Order ? $order_input : wc_get_order($order_input);
        if (!$order) {
            $this->debug_log("Order not found: " . (is_object($order_input) ? $order_input->get_id() : $order_input), null, 'ERROR');
            return false;
        }

        $order_id = $order->get_id();
        $this->debug_log("send_rcon_commands_grouped start for order {$order_id}");

        $opts = get_option($this->option_name, []);
        $servers = $this->get_configured_servers($opts);
        
        if (empty($servers)) {
            $this->debug_log('RCON config incomplete', null, 'ERROR');
            $this->save_order_history($order_id, false, 'Config RCON incomplète');
            return false;
        }

        $commands_with_product = $this->gather_product_commands($order);
        
        if (empty($commands_with_product)) {
            $this->debug_log("No commands to send for order {$order_id}", null, 'INFO');
            $this->save_order_history($order_id, true, 'No commands to send');
            return true;
        }

        $commands_by_server = $this->distribute_commands_by_server($commands_with_product, $servers);
        
        if (empty($commands_by_server)) {
            $this->debug_log("No valid server configuration found for order {$order_id}", null, 'ERROR');
            $this->save_order_history($order_id, false, 'Aucun serveur valide configuré');
            return false;
        }

        return $this->execute_commands_on_servers($commands_by_server, $servers, $order_id, $manual);
    }

    private function get_configured_servers($opts) {
        $servers = $opts['servers'] ?? [];
        
        if (empty($servers) && !empty($opts['host']) && !empty($opts['password'])) {
            $servers[] = [
                'name' => 'Default',
                'host' => $opts['host'],
                'port' => intval($opts['port'] ?? 2457),
                'password' => $opts['password'],
                'timeout' => intval($opts['timeout'] ?? 10),
            ];
        }
        
        return $servers;
    }

    private function gather_product_commands($order) {
        $commands_with_product = [];
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $commands = (array) get_post_meta($product_id, '_server_game_rcon_commands', true);
            $server_sel = get_post_meta($product_id, '_server_game_rcon_server', true);
            
            if (!empty($commands)) {
                foreach ($commands as $raw_cmd) {
                    $cmd = trim($raw_cmd);
                    if ($cmd === '' || strlen($cmd) > $this->max_command_length) continue;
                    
                    $prepared = $this->replace_variables($cmd, $order, $product_id);
                    $commands_with_product[] = [
                        'product_id' => $product_id,
                        'command' => $prepared,
                        'raw' => $cmd,
                        'server' => $server_sel,
                    ];
                }
            }
        }
        
        return $commands_with_product;
    }

    private function distribute_commands_by_server($commands_with_product, $servers) {
        $commands_by_server = [];
        
        foreach ($commands_with_product as $entry) {
            $sel = $entry['server'];
            
            if ($sel === 'all') {
                foreach (array_keys($servers) as $si) {
                    if (!isset($commands_by_server[$si])) {
                        $commands_by_server[$si] = [];
                    }
                    $commands_by_server[$si][] = $entry;
                }
            } elseif ($sel !== '' && is_numeric($sel)) {
                $si = intval($sel);
                if (isset($servers[$si])) {
                    if (!isset($commands_by_server[$si])) {
                        $commands_by_server[$si] = [];
                    }
                    $commands_by_server[$si][] = $entry;
                } else {
                    $this->debug_log("Product {$entry['product_id']}: Selected server index {$si} not found, using default", null, 'WARNING');
                    $this->fallback_to_first_server($commands_by_server, $servers, $entry);
                }
            } else {
                $this->fallback_to_first_server($commands_by_server, $servers, $entry);
            }
        }
        
        return $commands_by_server;
    }

    private function fallback_to_first_server(&$commands_by_server, $servers, $entry) {
        if (!empty($servers)) {
            reset($servers);
            $first_server = key($servers);
            if (!isset($commands_by_server[$first_server])) {
                $commands_by_server[$first_server] = [];
            }
            $commands_by_server[$first_server][] = $entry;
        }
    }
    private function execute_commands_on_servers($commands_by_server, $servers, $order_id, $manual) {
        $opts = get_option($this->option_name, []);
        $sent = 0;
        $total_expected = array_sum(array_map('count', $commands_by_server));
        $errors = [];
        
        $this->debug_log("Commands distribution: " . json_encode(array_map('count', $commands_by_server)), null, 'INFO');
        
        foreach ($commands_by_server as $si => $entries) {
            if (!isset($servers[$si])) {
                $errors[] = "Unknown server index: {$si}";
                continue;
            }
            
            $srv = $servers[$si];
            $server_result = $this->process_server_commands($srv, $si, $entries, $order_id);
            $sent += $server_result['sent'];
            $errors = array_merge($errors, $server_result['errors']);
        }

        $this->debug_log("Total sent: {$sent}/{$total_expected} commands", null, 'INFO');

        if ($sent === $total_expected) {
            $this->debug_log("All commands sent successfully for order {$order_id}", null, 'INFO');
            return true;
        } else {
            $this->debug_log("Partial/failed sending for {$order_id}: sent {$sent}/{$total_expected}. Errors: " . implode(' | ', array_slice($errors, 0, 3)), null, 'ERROR');
            
            if (!empty($opts['auto_retry']) && !$manual) {
                $this->schedule_retry($order_id);
            }
            return false;
        }
    }

    private function process_server_commands($srv, $si, $entries, $order_id) {
        $server_name = $srv['name'] ?? "Server-{$si}";
        $host = $srv['host'] ?? '';
        $port = intval($srv['port'] ?? 2457);
        $password = $srv['password'] ?? '';
        $timeout = intval($srv['timeout'] ?? 10);
        
        $result = ['sent' => 0, 'errors' => []];
        
        if (empty($host) || empty($password)) {
            $error_msg = "Server {$server_name} (#{$si}) misconfigured";
            $result['errors'][] = $error_msg;
            $this->debug_log($error_msg, null, 'ERROR');
            return $result;
        }

        $this->debug_log("Processing server {$server_name} ({$host}:{$port}) - " . count($entries) . " commands", null, 'INFO');

        $fp = $this->establish_rcon_connection($host, $port, $timeout);
        if (!$fp['success']) {
            $error_msg = "Connection failed to {$server_name}: " . $fp['error'];
            $result['errors'][] = $error_msg;
            $this->save_order_history($order_id, false, $error_msg);
            return $result;
        }

        $connection = $fp['connection'];
        
        try {
            $auth_result = $this->authenticate_rcon_connection($connection, $password, $server_name);
            
            if (!$auth_result['success']) {
                $error_msg = "Authentication failed to {$server_name}: " . $auth_result['error'];
                $result['errors'][] = $error_msg;
                $this->save_order_history($order_id, false, $error_msg);
                return $result;
            }

            foreach ($entries as $ent) {
                $cmd_result = $this->send_single_rcon_command($connection, $ent['command'], $server_name, $timeout);
                
                if ($cmd_result['success']) {
                    $result['sent']++;
                    $success_msg = "✅ {$server_name}: {$ent['raw']} → {$ent['command']}";
                    $this->save_order_history($order_id, true, $success_msg);
                    $this->debug_log("Command executed successfully on {$server_name}: {$ent['command']}", null, 'INFO');
                } else {
                    $error_msg = "❌ {$server_name}: Failed '{$ent['command']}' - " . $cmd_result['error'];
                    $result['errors'][] = $error_msg;
                    $this->save_order_history($order_id, false, $error_msg);
                    $this->debug_log($error_msg, null, 'ERROR');
                }
            }
        } finally {
            if (is_resource($connection)) {
                @fclose($connection);
            }
        }
        
        return $result;
    }

    private function establish_rcon_connection($host, $port, $timeout) {
        $max_attempts = 2;
        $fp = null;
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $this->debug_log("Connection attempt {$attempt}/{$max_attempts} to {$host}:{$port}", null, 'INFO');
            
            $errno = 0;
            $errstr = '';
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
            
            if ($fp !== false) {
                if (stream_set_timeout($fp, $timeout)) {
                    return ['success' => true, 'connection' => $fp];
                } else {
                    @fclose($fp);
                    return ['success' => false, 'error' => 'Failed to set timeout'];
                }
            }
            
            if ($attempt < $max_attempts) {
                $this->debug_log("Connection failed: {$errstr} ({$errno}), retrying...", null, 'WARNING');
                sleep(1);
            }
        }
        
        if (is_resource($fp)) {
            @fclose($fp);
        }
        
        return ['success' => false, 'error' => "{$errstr} ({$errno})"];
    }

    private function authenticate_rcon_connection($fp, $password, $server_name) {
        $auth_pkt = $this->rcon_build_packet(1, 3, $password);
        if (@fwrite($fp, $auth_pkt) === false) {
            return ['success' => false, 'error' => 'Write failed (auth)'];
        }
        
        $response = $this->read_rcon_response_safe($fp, 5);
        if (!$response['success']) {
            return ['success' => false, 'error' => 'Auth response failed: ' . $response['error']];
        }
        
        if (($response['id'] ?? 0) === -1) {
            return ['success' => false, 'error' => 'Authentication rejected (wrong password)'];
        }
        
        return ['success' => true];
    }

    private function schedule_retry($order_id) {
        if (!wp_next_scheduled($this->hook_retry, [$order_id])) {
            wp_schedule_single_event(time() + 300, $this->hook_retry, [$order_id]);
            $this->debug_log("Scheduled retry for order {$order_id}", null, 'INFO');
        }
    }
    public function replace_variables($command, $order, $product_id = 0) {
        if (!is_object($order)) {
            $order = wc_get_order($order);
        }
        
        $repl = [
            '{order_id}' => intval($order->get_id()),
            '{billing_email}' => sanitize_email($order->get_billing_email()),
            '{billing_first_name}' => sanitize_text_field($order->get_billing_first_name()),
            '{billing_last_name}' => sanitize_text_field($order->get_billing_last_name()),
            '{game_username}' => $this->sanitize_game_username($this->get_order_meta($order, '_game_username')),
            '{steam_id}' => $this->sanitize_steam_id($this->get_order_meta($order, '_steam_id')),
        ];

        $command = strtr($command, $repl);
        $command = preg_replace('/[;&|`$(){}[\]<>]/', '', $command);
        
        return trim($command);
    }

    private function save_order_history($order_id, $success, $message, $product_id = 0, $command = '', $response = '') {
        $order = is_object($order_id) ? $order_id : wc_get_order($order_id);
        $order_id_int = is_object($order_id) ? $order_id->get_id() : $order_id;
        
        $logs = $this->get_order_meta($order, $this->history_meta);
        if (!is_array($logs)) $logs = [];
        
        if (count($logs) >= $this->max_history_entries) {
            $logs = array_slice($logs, -($this->max_history_entries - 1));
        }
        
        $logs[] = [
            'time' => current_time('mysql'),
            'success' => $success ? 1 : 0,
            'message' => is_string($message) ? substr($message, 0, 500) : json_encode($message),
            'product_id' => intval($product_id),
            'command' => substr($command, 0, 200),
            'response' => substr($response, 0, 200),
        ];
        
        $this->set_order_meta($order ?: $order_id_int, $this->history_meta, $logs);
    }

    public function retry_failed_commands($order_id) {
        $this->debug_log("Retry fired for order {$order_id}");
        $this->send_rcon_commands_grouped($order_id);
    }

    private function read_rcon_response_safe($fp, $timeout = 10) {
        $start_time = microtime(true);
        $header = '';
        
        while (strlen($header) < 4 && (microtime(true) - $start_time) < $timeout) {
            $chunk = @fread($fp, 4 - strlen($header));
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $header .= $chunk;
        }
        
        if (strlen($header) < 4) {
            return ['success' => false, 'error' => 'Timeout reading header'];
        }
        
        $size = unpack('V', $header)[1];
        if ($size <= 0 || $size > 65536) {
            return ['success' => false, 'error' => 'Invalid response size: ' . $size];
        }
        
        $payload = '';
        while (strlen($payload) < $size && (microtime(true) - $start_time) < $timeout) {
            $chunk = @fread($fp, $size - strlen($payload));
            if ($chunk === false || $chunk === '') {
                usleep(10000);
                continue;
            }
            $payload .= $chunk;
        }
        
        if (strlen($payload) < $size) {
            return ['success' => false, 'error' => 'Timeout reading payload'];
        }
        
        $data = unpack('Vid/Vtype/a*body', $payload);
        $body = isset($data['body']) ? rtrim($data['body'], "\x00") : '';
        
        return [
            'success' => true, 
            'body' => $body, 
            'id' => $data['id'] ?? 0, 
            'type' => $data['type'] ?? 0
        ];
    }

    private function send_single_rcon_command($fp, $command, $server_name, $timeout) {
        $cmd_pkt = $this->rcon_build_packet(2, 2, $command);
        if (@fwrite($fp, $cmd_pkt) === false) {
            return ['success' => false, 'error' => 'Write failed'];
        }
        
        $response = $this->read_rcon_response_safe($fp, $timeout);
        if (!$response['success']) {
            return ['success' => false, 'error' => 'Response failed: ' . $response['error']];
        }
        
        return ['success' => true, 'response' => $response['body']];
    }

    private function rcon_build_packet($id, $type, $body) {
        $payload = pack('V', $id) . pack('V', $type) . $body . "\x00\x00";
        $size = strlen($payload);
        return pack('V', $size) . $payload;
    }

    public function execute_rcon_command($host, $port, $password, $command, $timeout = 10) {
        $this->debug_log("execute_rcon_command '{$command}' to {$host}:{$port}");
        
        $connection_result = $this->establish_rcon_connection($host, $port, $timeout);
        if (!$connection_result['success']) {
            return ['success' => false, 'error' => $connection_result['error']];
        }
        
        $fp = $connection_result['connection'];
        
        try {
            $auth_result = $this->authenticate_rcon_connection($fp, $password, 'test');
            if (!$auth_result['success']) {
                return ['success' => false, 'error' => $auth_result['error']];
            }
            
            $cmd_result = $this->send_single_rcon_command($fp, $command, 'test', $timeout);
            return $cmd_result;
            
        } finally {
            if (is_resource($fp)) {
                @fclose($fp);
            }
        }
    }

} // FIN DE LA CLASSE WC_Server_Game_RCON

}
// CLASSE GITHUB UPDATER

class ServerGameRCONGitHubUpdater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_user;
    private $github_repo;
    private $github_response;
    
    public function __construct($file) {
        $this->file = $file;
        add_action('admin_init', [$this, 'setPluginProperties']);
    }
    
    public function setPluginProperties() {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
        
        $this->parseGitHubInfo();
        
        if ($this->github_user && $this->github_repo) {
            add_filter('pre_set_site_transient_update_plugins', [$this, 'modifyTransient'], 10, 1);
            add_filter('plugins_api', [$this, 'pluginPopup'], 10, 3);
            add_filter('upgrader_post_install', [$this, 'afterInstall'], 10, 3);
            add_action('admin_notices', [$this, 'updateNotice']);
        }
    }
    
    private function parseGitHubInfo() {
        $plugin_content = file_get_contents($this->file);
        preg_match('/GitHub Plugin URI:\s*(.+)/', $plugin_content, $github_matches);
        
        if (isset($github_matches[1])) {
            $github_uri = trim($github_matches[1]);
            $parts = explode('/', $github_uri);
            
            if (count($parts) >= 2) {
                $this->github_user = trim($parts[0]);
                $this->github_repo = trim($parts[1]);
            }
        }
    }
    
    public function modifyTransient($transient) {
        if (!property_exists($transient, 'checked') || !$transient->checked) {
            return $transient;
        }
        
        $this->getRepositoryInfo();
        $new_version = $this->getNewVersion();
        $current_version = $transient->checked[$this->basename] ?? $this->plugin['Version'];
        
        if (version_compare($new_version, $current_version, 'gt')) {
            $transient->response[$this->basename] = (object) [
                'new_version' => $new_version,
                'slug' => current(explode('/', $this->basename)),
                'url' => $this->plugin['PluginURI'],
                'package' => $this->getZipUrl(),
                'tested' => get_bloginfo('version'),
                'requires_php' => $this->plugin['RequiresPHP'] ?? '7.4'
            ];
        }
        
        return $transient;
    }
    
    public function pluginPopup($res, $action, $args) {
        if (empty($args->slug) || $args->slug !== current(explode('/', $this->basename))) {
            return $res;
        }
        
        $this->getRepositoryInfo();
        
        return (object) [
            'name' => $this->plugin['Name'],
            'slug' => $this->basename,
            'version' => $this->getNewVersion(),
            'author' => $this->plugin['AuthorName'],
            'author_profile' => $this->plugin['AuthorURI'],
            'last_updated' => $this->getDate(),
            'homepage' => $this->plugin['PluginURI'],
            'short_description' => $this->plugin['Description'],
            'sections' => [
                'Description' => $this->plugin['Description'],
                'Updates' => $this->getChangelog(),
                'Installation' => 'Téléchargez et activez le plugin depuis cette interface ou manuellement via FTP.',
            ],
            'download_link' => $this->getZipUrl(),
            'tested' => get_bloginfo('version'),
            'requires_php' => $this->plugin['RequiresPHP'] ?? '7.4'
        ];
    }
    
    public function afterInstall($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->active) {
            activate_plugin($this->basename);
        }
        
        return $result;
    }
    
    public function updateNotice() {
        if (!$this->hasUpdate()) {
            return;
        }
        
        $new_version = $this->getNewVersion();
        $plugin_name = $this->plugin['Name'];
        
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html($plugin_name) . '</strong> : Une nouvelle version (' . esc_html($new_version) . ') est disponible. ';
        echo '<a href="' . admin_url('plugins.php') . '">Mettre à jour maintenant</a></p>';
        echo '</div>';
    }
    
    public function hasUpdate() {
        $this->getRepositoryInfo();
        $new_version = $this->getNewVersion();
        $current_version = $this->plugin['Version'];
        
        return version_compare($new_version, $current_version, 'gt');
    }
    
    public function getNewVersion() {
        $this->getRepositoryInfo();
        return !empty($this->github_response['tag_name']) ? ltrim($this->github_response['tag_name'], 'v') : false;
    }
    
    private function getRepositoryInfo() {
        if ($this->github_response !== null) {
            return;
        }
        
        $cache_key = 'server_game_rcon_github_' . md5($this->github_user . $this->github_repo);
        $cached_response = get_transient($cache_key);
        
        if ($cached_response !== false) {
            $this->github_response = $cached_response;
            return;
        }
        
        $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->github_user, $this->github_repo);
        $response = wp_remote_get($request_uri, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            ]
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $this->github_response = json_decode(wp_remote_retrieve_body($response), true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                set_transient($cache_key, $this->github_response, 12 * HOUR_IN_SECONDS);
            } else {
                $this->github_response = false;
                set_transient($cache_key, false, HOUR_IN_SECONDS);
            }
        } else {
            $this->github_response = false;
            set_transient($cache_key, false, HOUR_IN_SECONDS);
        }
    }
    
    private function getZipUrl() {
        $this->getRepositoryInfo();
        return !empty($this->github_response['zipball_url']) ? $this->github_response['zipball_url'] : false;
    }
    
    private function getDate() {
        $this->getRepositoryInfo();
        return !empty($this->github_response['published_at']) ? date('Y-m-d', strtotime($this->github_response['published_at'])) : false;
    }
    
    private function getChangelog() {
        $this->getRepositoryInfo();
        $changelog = !empty($this->github_response['body']) ? $this->github_response['body'] : 'Pas de notes de version disponibles.';
        
        $changelog = wp_kses_post($changelog);
        $changelog = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $changelog);
        $changelog = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $changelog);
        $changelog = preg_replace('/^\* (.+)$/m', '<ul><li>$1</li></ul>', $changelog);
        $changelog = preg_replace('/^\- (.+)$/m', '<ul><li>$1</li></ul>', $changelog);
        $changelog = preg_replace('/<\/ul>\s*<ul>/', '', $changelog);
        
        return $changelog;
    }
}

// INITIALISATION DU PLUGIN

add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new WC_Server_Game_RCON();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Le plugin Server Game RCON nécessite WooCommerce pour fonctionner.</p></div>';
        });
    }
});

// NETTOYAGE À LA DÉSACTIVATION

register_deactivation_hook(__FILE__, function() {
    global $wpdb;
    
    wp_clear_scheduled_hook('server_game_rcon_cleanup_logs');
    wp_clear_scheduled_hook('server_game_rcon_retry_failed');
    
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_server_game_rcon_github_') . '%',
            $wpdb->esc_like('_transient_timeout_server_game_rcon_github_') . '%'
        )
    );
});