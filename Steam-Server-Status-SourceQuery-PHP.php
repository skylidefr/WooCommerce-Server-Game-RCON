<?php
/*
Plugin Name: Steam Server Status SourceQuery PHP
Description: Affiche le nombre de joueurs connectés sur un ou plusieurs serveurs Steam et Minecraft avec personnalisation avancée.
Version: 1.4.0
Author: Skylide
GitHub Plugin URI: skylidefr/Steam-Server-Status-SourceQuery-PHP
GitHub Branch: main
Author URI: https://github.com/skylidefr/Steam-Server-Status-SourceQuery-PHP/
*/

if (!defined('ABSPATH')) exit;

// Chargement des dépendances
require_once __DIR__ . '/SourceQuery/BaseSocket.php';
require_once __DIR__ . '/SourceQuery/Socket.php';
require_once __DIR__ . '/SourceQuery/SourceQuery.php';
require_once __DIR__ . '/SourceQuery/Buffer.php';
require_once __DIR__ . '/SourceQuery/BaseRcon.php';
require_once __DIR__ . '/SourceQuery/SourceRcon.php';
require_once __DIR__ . '/SourceQuery/GoldSourceRcon.php';
require_once __DIR__ . '/SourceQuery/Exception/SourceQueryException.php';
require_once __DIR__ . '/SourceQuery/Exception/SocketException.php';
require_once __DIR__ . '/SourceQuery/Exception/AuthenticationException.php';
require_once __DIR__ . '/SourceQuery/Exception/InvalidArgumentException.php';
require_once __DIR__ . '/SourceQuery/Exception/InvalidPacketException.php';
require_once __DIR__ . '/MinecraftQuery/MinecraftQuery.php';
require_once __DIR__ . '/MinecraftQuery/MinecraftQueryException.php';

use xPaw\SourceQuery\SourceQuery;
use xPaw\MinecraftQuery;

/**
 * Classe principale du plugin
 */
class SteamServerStatusPlugin {
    
    private static $instance = null;
    private $version;
    private $plugin_slug = 'steam-server-status';
    private $plugin_file;
    
    // Types de jeux supportés
    private $supported_games = [
        'source' => [
            'source_cs2' => 'Counter-Strike 2',
            'source_csgo' => 'CS:GO',
            'source_css' => 'Counter-Strike: Source',
            'source_tf2' => 'Team Fortress 2',
            'source_l4d2' => 'Left 4 Dead 2',
            'source_l4d' => 'Left 4 Dead',
            'source_gmod' => "Garry's Mod",
            'source_rust' => 'Rust',
            'source_ark' => 'ARK: Survival Evolved',
            'source_7dtd' => '7 Days to Die',
            'source_insurgency' => 'Insurgency',
            'source_kf' => 'Killing Floor',
            'source_kf2' => 'Killing Floor 2',
            'source_generic' => 'Autre Source Engine'
        ],
        'goldsource' => [
            'goldsource_cs16' => 'Counter-Strike 1.6',
            'goldsource_hl1' => 'Half-Life 1',
            'goldsource_tfc' => 'Team Fortress Classic',
            'goldsource_dod' => 'Day of Defeat',
            'goldsource_generic' => 'Autre GoldSource'
        ],
        'minecraft' => [
            'minecraft' => 'Minecraft'
        ]
    ];
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->plugin_file = __FILE__;
        $this->init();
    }
    
    private function getVersion() {
        if (!isset($this->version)) {
            $plugin_data = get_plugin_data($this->plugin_file);
            $this->version = $plugin_data['Version'];
        }
        return $this->version;
    }
    
    private function init() {
        add_action('init', [$this, 'initPlugin']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_head', [$this, 'addFrontendStyles']);
        
        // Shortcodes
        add_shortcode('steam_status', [$this, 'singleServerShortcode']);
        add_shortcode('steam_status_all', [$this, 'allServersShortcode']);
        
        // Widget Elementor
        add_action('elementor/widgets/widgets_registered', [$this, 'registerElementorWidget']);
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'enqueueElementorEditorAssets']);
        add_action('elementor/frontend/after_enqueue_styles', [$this, 'enqueueElementorFrontendAssets']);
        
        // AJAX Handlers
        add_action('wp_ajax_test_discord_webhook', [$this, 'ajaxTestDiscordWebhook']);
        add_action('wp_ajax_test_server_connection', [$this, 'ajaxTestServerConnection']);
        
        // Système de mise à jour GitHub
        if (is_admin()) {
            new SteamStatusGitHubUpdater($this->plugin_file);
        }
        
        // Cron pour notifications Discord
        add_action('steam_status_check_servers', [$this, 'checkServersStatus']);
        add_action('steam_status_daily_summary', [$this, 'sendDailySummary']);
        
        if (!wp_next_scheduled('steam_status_check_servers')) {
            wp_schedule_event(time(), 'every_minute', 'steam_status_check_servers');
        }
        
        if (!wp_next_scheduled('steam_status_daily_summary')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'steam_status_daily_summary');
        }
    }
    
    public function initPlugin() {
        // Enregistrer l'intervalle personnalisé
        add_filter('cron_schedules', function($schedules) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display' => __('Chaque minute')
            ];
            return $schedules;
        });
    }
    
    public function registerElementorWidget() {
        if (did_action('elementor/loaded')) {
            require_once(__DIR__ . '/includes/elementor-widget.php');
            \Elementor\Plugin::instance()->widgets_manager->register_widget_type(new \SteamStatusElementorWidget());
        }
    }
    
    public function enqueueElementorEditorAssets() {
        wp_enqueue_style(
            'steam-elementor-editor',
            plugin_dir_url($this->plugin_file) . 'assets/elementor-styles.css',
            [],
            $this->getVersion()
        );
    }
    
    public function enqueueElementorFrontendAssets() {
        wp_enqueue_style(
            'steam-elementor-frontend',
            plugin_dir_url($this->plugin_file) . 'assets/elementor-styles.css',
            [],
            $this->getVersion()
        );
    }
    
    public function addAdminMenu() {
        add_options_page(
            'Steam & Minecraft Server Status',
            'Steam & Minecraft Status',
            'manage_options',
            $this->plugin_slug,
            [$this, 'renderSettingsPage']
        );
    }
    
    public function registerSettings() {
        $settings = [
            // Paramètres serveurs
            'steam_servers',
            'steam_show_name',
            'steam_cache_duration',
            
            // Textes personnalisables
            'steam_text_offline',
            'steam_text_no_servers',
            'steam_text_not_found',
            'steam_text_players',
            'steam_text_separator',
            'steam_text_no_players',
            
            // Couleurs et styles
            'steam_use_text_colors',
            'steam_use_border_colors',
            'steam_color_text_online',
            'steam_color_text_offline',
            'steam_color_border_online',
            'steam_color_border_offline',
            'steam_font_family',
            'steam_font_size',
            'steam_all_display_default',
            
            // Options latence
            'steam_show_latency_global',
            'steam_latency_cache_duration',
            'steam_latency_threshold_good',
            'steam_latency_threshold_medium',
            
            // Options Minecraft
            'steam_show_motd',
            'steam_show_version',
            
            // Options Discord
            'discord_enable_notifications',
            'discord_webhook_url',
            'discord_bot_username',
            'discord_bot_avatar',
            'discord_notify_offline',
            'discord_notify_online',
            'discord_notify_player_threshold',
            'discord_player_threshold_value',
            'discord_notify_high_latency',
            'discord_latency_threshold',
            'discord_daily_summary',
            'discord_mention_role',
            'discord_notification_cooldown',
            'discord_embed_color_online',
            'discord_embed_color_offline',
            'discord_embed_color_warning',
        ];
        
        foreach ($settings as $setting) {
            register_setting('steam_status_options_group', $setting, [
                'sanitize_callback' => [$this, 'sanitizeOption']
            ]);
        }
    }
    
    public function sanitizeOption($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeOption'], $value);
        }
        
        if (is_string($value)) {
            // Nettoyer les URLs webhook Discord
            if (strpos($value, 'discord.com/api/webhooks') !== false) {
                return esc_url_raw($value);
            }
            
            // Nettoyer les couleurs hex
            if (preg_match('/^#[a-f0-9]{6}$/i', $value)) {
                return sanitize_hex_color($value);
            }
            
            return sanitize_text_field($value);
        }
        
        return $value;
    }
    
    public function enqueueAdminAssets($hook) {
        if ($hook !== 'settings_page_' . $this->plugin_slug) return;
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        wp_enqueue_script(
            'steam-admin-js',
            plugin_dir_url($this->plugin_file) . 'assets/admin.js',
            ['jquery', 'wp-color-picker'],
            $this->getVersion(),
            true
        );
        
        wp_localize_script('steam-admin-js', 'steamAdminData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('steam_admin_actions'),
            'gameOptions' => $this->getGameOptionsHtml()
        ]);
    }
    // MÉTHODES AJAX
    
    public function ajaxTestDiscordWebhook() {
        check_ajax_referer('steam_admin_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissions insuffisantes');
        }
        
        $webhook_url = isset($_POST['webhook_url']) ? esc_url_raw($_POST['webhook_url']) : '';
        
        if (empty($webhook_url)) {
            wp_send_json_error('URL du webhook manquante');
        }
        
        $result = $this->sendDiscordMessage($webhook_url, [
            'username' => 'Server Status Bot',
            'embeds' => [[
                'title' => '✅ Test de connexion réussi',
                'description' => 'Le webhook Discord fonctionne correctement !',
                'color' => hexdec(str_replace('#', '', '#2ecc71')),
                'timestamp' => date('c'),
                'footer' => ['text' => 'Steam Server Status Plugin']
            ]]
        ]);
        
        if ($result) {
            wp_send_json_success('Webhook testé avec succès');
        } else {
            wp_send_json_error('Impossible de se connecter au webhook');
        }
    }
    
    public function ajaxTestServerConnection() {
        check_ajax_referer('steam_admin_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissions insuffisantes');
        }
        
        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';
        $port = isset($_POST['port']) ? intval($_POST['port']) : 0;
        $game_type = isset($_POST['game_type']) ? sanitize_text_field($_POST['game_type']) : 'source_generic';
        
        if (empty($ip) || $port <= 0) {
            wp_send_json_error('IP ou port invalide');
        }
        
        $server = [
            'ip' => $ip,
            'port' => $port,
            'game_type' => $game_type,
            'name' => 'Test'
        ];
        
        $data = $this->queryServer($server);
        
        if ($data['online']) {
            wp_send_json_success([
                'message' => 'Connexion réussie',
                'players' => $data['players'],
                'max' => $data['max'],
                'latency' => $data['latency'],
                'version' => $data['version']
            ]);
        } else {
            wp_send_json_error('Impossible de se connecter au serveur');
        }
    }
    
    // MÉTHODES DISCORD
    
    private function sendDiscordMessage($webhook_url, $data) {
        if (empty($webhook_url)) return false;
        
        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data),
            'timeout' => 10
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204;
    }
    
    public function checkServersStatus() {
        if (!get_option('discord_enable_notifications', 0)) {
            return;
        }
        
        $servers = get_option('steam_servers', []);
        $webhook_url = get_option('discord_webhook_url', '');
        
        if (empty($webhook_url) || !is_array($servers)) {
            return;
        }
        
        foreach ($servers as $id => $server) {
            $this->checkSingleServerStatus($id, $server, $webhook_url);
        }
    }
    
    private function checkSingleServerStatus($id, $server, $webhook_url) {
        $previous_state = get_transient('steam_server_state_' . $id);
        $cooldown = get_transient('steam_discord_cooldown_' . $id);
        
        // Si en cooldown, ne pas notifier
        if ($cooldown !== false) {
            return;
        }
        
        $current_data = $this->queryServer($server);
        $current_state = [
            'online' => $current_data['online'],
            'players' => $current_data['players'],
            'latency' => $current_data['latency']
        ];
        
        // Première vérification, sauvegarder l'état
        if ($previous_state === false) {
            set_transient('steam_server_state_' . $id, $current_state, 86400);
            return;
        }
        
        $notify = false;
        $notification_type = '';
        
        // Vérifier changement d'état online/offline
        if ($previous_state['online'] !== $current_state['online']) {
            if (!$current_state['online'] && get_option('discord_notify_offline', 1)) {
                $notify = true;
                $notification_type = 'offline';
            } elseif ($current_state['online'] && get_option('discord_notify_online', 1)) {
                $notify = true;
                $notification_type = 'online';
            }
        }
        
        // Vérifier seuil de joueurs
        if ($current_state['online'] && get_option('discord_notify_player_threshold', 0)) {
            $threshold = intval(get_option('discord_player_threshold_value', 10));
            if ($current_state['players'] >= $threshold && $previous_state['players'] < $threshold) {
                $notify = true;
                $notification_type = 'player_threshold';
            }
        }
        
        // Vérifier latence élevée
        if ($current_state['online'] && get_option('discord_notify_high_latency', 0) && $current_state['latency']) {
            $threshold = intval(get_option('discord_latency_threshold', 300));
            if ($current_state['latency'] >= $threshold) {
                $notify = true;
                $notification_type = 'high_latency';
            }
        }
        
        if ($notify) {
            $this->sendServerNotification($server, $current_state, $notification_type, $webhook_url);
            
            // Appliquer le cooldown
            $cooldown_duration = intval(get_option('discord_notification_cooldown', 300));
            set_transient('steam_discord_cooldown_' . $id, true, $cooldown_duration);
        }
        
        // Mettre à jour l'état
        set_transient('steam_server_state_' . $id, $current_state, 86400);
    }
    
    private function sendServerNotification($server, $state, $type, $webhook_url) {
        $bot_username = get_option('discord_bot_username', 'Server Status Bot');
        $bot_avatar = get_option('discord_bot_avatar', '');
        $mention_role = get_option('discord_mention_role', '');
        
        $embed = $this->buildDiscordEmbed($server, $state, $type);
        
        $message = [
            'username' => $bot_username,
            'embeds' => [$embed]
        ];
        
        if (!empty($bot_avatar)) {
            $message['avatar_url'] = $bot_avatar;
        }
        
        if (!empty($mention_role) && in_array($type, ['offline', 'high_latency'])) {
            $message['content'] = '<@&' . $mention_role . '>';
        }
        
        $this->sendDiscordMessage($webhook_url, $message);
    }
    
    private function buildDiscordEmbed($server, $state, $type) {
        $color_online = get_option('discord_embed_color_online', '#2ecc71');
        $color_offline = get_option('discord_embed_color_offline', '#e74c3c');
        $color_warning = get_option('discord_embed_color_warning', '#f39c12');
        
        $embed = [
            'timestamp' => date('c'),
            'footer' => ['text' => 'Steam Server Status']
        ];
        
        switch ($type) {
            case 'offline':
                $embed['title'] = '🔴 Serveur hors ligne';
                $embed['description'] = "Le serveur **{$server['name']}** est maintenant hors ligne.";
                $embed['color'] = hexdec(str_replace('#', '', $color_offline));
                break;
                
            case 'online':
                $embed['title'] = '🟢 Serveur en ligne';
                $embed['description'] = "Le serveur **{$server['name']}** est de retour en ligne !";
                $embed['color'] = hexdec(str_replace('#', '', $color_online));
                $embed['fields'] = [
                    [
                        'name' => 'Joueurs',
                        'value' => $state['players'] . ' joueurs connectés',
                        'inline' => true
                    ]
                ];
                if ($state['latency']) {
                    $embed['fields'][] = [
                        'name' => 'Latence',
                        'value' => $state['latency'] . 'ms',
                        'inline' => true
                    ];
                }
                break;
                
            case 'player_threshold':
                $threshold = intval(get_option('discord_player_threshold_value', 10));
                $embed['title'] = '👥 Seuil de joueurs atteint';
                $embed['description'] = "Le serveur **{$server['name']}** a atteint {$state['players']} joueurs (seuil: {$threshold}).";
                $embed['color'] = hexdec(str_replace('#', '', $color_online));
                break;
                
            case 'high_latency':
                $threshold = intval(get_option('discord_latency_threshold', 300));
                $embed['title'] = '⚠️ Latence élevée';
                $embed['description'] = "Le serveur **{$server['name']}** a une latence de {$state['latency']}ms (seuil: {$threshold}ms).";
                $embed['color'] = hexdec(str_replace('#', '', $color_warning));
                break;
        }
        
        $embed['fields'][] = [
            'name' => 'Serveur',
            'value' => $server['ip'] . ':' . $server['port'],
            'inline' => false
        ];
        
        return $embed;
    }
    
    public function sendDailySummary() {
        if (!get_option('discord_enable_notifications', 0) || !get_option('discord_daily_summary', 0)) {
            return;
        }
        
        $webhook_url = get_option('discord_webhook_url', '');
        if (empty($webhook_url)) return;
        
        $servers = get_option('steam_servers', []);
        if (!is_array($servers) || empty($servers)) return;
        
        $fields = [];
        $total_players = 0;
        $online_count = 0;
        
        foreach ($servers as $id => $server) {
            $data = $this->queryServer($server);
            
            if ($data['online']) {
                $online_count++;
                $total_players += $data['players'];
                $status = '🟢 En ligne';
                $info = "{$data['players']}/{$data['max']} joueurs";
                if ($data['latency']) {
                    $info .= " | {$data['latency']}ms";
                }
            } else {
                $status = '🔴 Hors ligne';
                $info = 'Indisponible';
            }
            
            $fields[] = [
                'name' => $server['name'],
                'value' => $status . ' - ' . $info,
                'inline' => false
            ];
        }
        
        $embed = [
            'title' => '📊 Résumé quotidien des serveurs',
            'description' => "**{$online_count}** serveur(s) en ligne sur **" . count($servers) . "**\n**{$total_players}** joueurs au total",
            'color' => hexdec(str_replace('#', '', get_option('discord_embed_color_online', '#2ecc71'))),
            'fields' => $fields,
            'timestamp' => date('c'),
            'footer' => ['text' => 'Résumé quotidien']
        ];
        
        $this->sendDiscordMessage($webhook_url, [
            'username' => get_option('discord_bot_username', 'Server Status Bot'),
            'embeds' => [$embed]
        ]);
    }
    // MÉTHODES PUBLIQUES pour Elementor et external access
    
    public function getServerDataCached($server, $id) {
        $cache_key = 'steam_status_' . $id;
        $latency_cache_key = 'steam_latency_' . $id;
        
        $cache_duration = intval(get_option('steam_cache_duration', 15));
        $latency_cache_duration = intval(get_option('steam_latency_cache_duration', 5));
        
        $data = get_transient($cache_key);
        $latency = get_transient($latency_cache_key);
        
        if ($data === false) {
            $data = $this->queryServer($server);
            if (empty($data['name']) && !empty($server['name'])) {
                $data['name'] = $server['name'];
            }
            
            $latency_data = $data['latency'];
            unset($data['latency']);
            
            set_transient($cache_key, $data, $cache_duration);
            set_transient($latency_cache_key, $latency_data, $latency_cache_duration);
            
            $data['latency'] = $latency_data;
        } else {
            if ($latency === false && $data['online']) {
                $temp_data = $this->queryServer($server);
                $latency = $temp_data['latency'] ?? null;
                set_transient($latency_cache_key, $latency, $latency_cache_duration);
            }
            $data['latency'] = $latency;
        }
        
        return $data;
    }
    
    public function getGameIcon($game_type) {
        $icons = [
            'source_cs2' => '🔫',
            'source_csgo' => '🔫',
            'source_css' => '🔫',
            'source_tf2' => '🎯',
            'source_l4d2' => '🧟',
            'source_l4d' => '🧟',
            'source_gmod' => '🔧',
            'source_rust' => '🏕️',
            'source_ark' => '🦖',
            'source_7dtd' => '🧟‍♂️',
            'source_insurgency' => '💥',
            'source_kf' => '🔪',
            'source_kf2' => '🔪',
            'goldsource_cs16' => '🔫',
            'goldsource_hl1' => '🔬',
            'goldsource_tfc' => '🎯',
            'goldsource_dod' => '⚔️',
            'minecraft' => '🧱',
            'source_generic' => '🎮',
            'goldsource_generic' => '🕹️'
        ];
        
        return $icons[$game_type] ?? '🎮';
    }
    
    public function getLatencyClass($latency) {
        $good_threshold = intval(get_option('steam_latency_threshold_good', 80));
        $medium_threshold = intval(get_option('steam_latency_threshold_medium', 200));
        
        if ($latency <= $good_threshold) {
            return 'good';
        } elseif ($latency <= $medium_threshold) {
            return 'medium';
        } else {
            return 'bad';
        }
    }
    
    public function getProtocolFromGameType($game_type) {
        if (strpos($game_type, 'minecraft') === 0) {
            return 'minecraft';
        } elseif (strpos($game_type, 'goldsource') === 0) {
            return 'goldsource';
        } else {
            return 'source';
        }
    }
    
    public function getSupportedGames() {
        return $this->supported_games;
    }
    
    public function getServers() {
        return get_option('steam_servers', []);
    }
    
    public function renderServerForElementor($server_id, $options = []) {
        $servers = get_option('steam_servers', []);
        if (!isset($servers[$server_id])) {
            return $this->renderOfflineStatus('Serveur introuvable');
        }
        
        $server = $servers[$server_id];
        $data = $this->getServerDataCached($server, $server_id);
        
        return $this->renderServerStatus($data, $server_id, $options['show_name'] ?? 1);
    }
    
    // MÉTHODES PRIVÉES
    
    private function queryServer($server) {
        $game_type = $server['game_type'] ?? 'source_generic';
        $protocol = $this->getProtocolFromGameType($game_type);
        
        $result = [
            'error' => false,
            'online' => false,
            'players' => 0,
            'max' => 0,
            'name' => $server['name'] ?? '',
            'latency' => null,
            'game_type' => $game_type,
            'protocol' => $protocol,
            'version' => null,
            'motd' => null
        ];
        
        try {
            if ($protocol === 'minecraft') {
                $result = $this->queryMinecraftServer($server, $result);
            } else {
                $result = $this->querySourceServer($server, $result, $protocol);
            }
        } catch (Exception $e) {
            error_log('Server Query Error for ' . $server['name'] . ': ' . $e->getMessage());
            $result['error'] = true;
            $result['online'] = false;
        }
        
        return $result;
    }
    
    private function queryMinecraftServer($server, $result) {
        $latencies = [];
        $timeout = 1;
        
        for ($i = 0; $i < 3; $i++) {
            $start_time = microtime(true);
            
            $query = new MinecraftQuery();
            $query->Connect($server['ip'], $server['port'], $timeout);
            $info = $query->GetInfo();
            
            $end_time = microtime(true);
            $latencies[] = ($end_time - $start_time) * 1000;
            
            if ($i < 2) usleep(100000);
        }
        
        $query = new MinecraftQuery();
        $query->Connect($server['ip'], $server['port'], $timeout);
        $info = $query->GetInfo();
        
        if ($info) {
            $result['online'] = true;
            $result['players'] = intval($info['Players'] ?? 0);
            $result['max'] = intval($info['MaxPlayers'] ?? 0);
            $result['version'] = $info['Version'] ?? null;
            $result['motd'] = $this->cleanMinecraftMotd($info['HostName'] ?? null);
            $result['latency'] = round(array_sum($latencies) / count($latencies));
        }
        
        return $result;
    }
    
    private function querySourceServer($server, $result, $protocol) {
        $latencies = [];
        $timeout = 1;
        $query = new SourceQuery();
        
        $source_protocol = ($protocol === 'goldsource') ? SourceQuery::GOLDSOURCE : SourceQuery::SOURCE;
        
        for ($i = 0; $i < 3; $i++) {
            $start_time = microtime(true);
            
            $query->Connect($server['ip'], $server['port'], $timeout, $source_protocol);
            $info = $query->GetInfo();
            
            $end_time = microtime(true);
            $latencies[] = ($end_time - $start_time) * 1000;
            
            $query->Disconnect();
            if ($i < 2) usleep(100000);
        }
        
        $query->Connect($server['ip'], $server['port'], $timeout, $source_protocol);
        $info = $query->GetInfo();
        
        $result['online'] = true;
        $result['players'] = intval($info['Players'] ?? 0);
        $result['max'] = intval($info['MaxPlayers'] ?? 0);
        $result['version'] = $info['Version'] ?? null;
        $result['latency'] = round(array_sum($latencies) / count($latencies));
        
        $query->Disconnect();
        
        return $result;
    }
    
    private function cleanMinecraftMotd($motd) {
        if (!$motd) return null;
        
        $motd = preg_replace('/§[0-9a-fk-or]/', '', $motd);
        
        if (is_array($motd)) {
            $motd = isset($motd['text']) ? $motd['text'] : '';
        }
        
        return trim($motd);
    }
    
    private function getGameOptionsHtml() {
        $html = '';
        foreach ($this->supported_games as $engine => $games) {
            $label = ucfirst($engine) . ' Engine';
            if ($engine === 'minecraft') $label = 'Minecraft';
            
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($games as $key => $name) {
                $html .= '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }
    
    private function renderGameOptions($selected = '') {
        $html = '';
        foreach ($this->supported_games as $engine => $games) {
            $label = ucfirst($engine) . ' Engine';
            if ($engine === 'minecraft') $label = 'Minecraft';
            
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($games as $key => $name) {
                $html .= sprintf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($key),
                    selected($selected, $key, false),
                    esc_html($name)
                );
            }
            $html .= '</optgroup>';
        }
        return $html;
    }
    
    private function getOptions() {
        return [
            'font_family' => get_option('steam_font_family', 'Arial, sans-serif'),
            'font_size' => intval(get_option('steam_font_size', 14)),
            'use_text_colors' => get_option('steam_use_text_colors', 1),
            'use_border_colors' => get_option('steam_use_border_colors', 1),
            'color_text_online' => get_option('steam_color_text_online', '#2ecc71'),
            'color_text_offline' => get_option('steam_color_text_offline', '#e74c3c'),
            'color_border_online' => get_option('steam_color_border_online', '#2ecc71'),
            'color_border_offline' => get_option('steam_color_border_offline', '#e74c3c'),
        ];
    }
    
    private function getAllOptions() {
        return [
            'show_name' => get_option('steam_show_name', 1),
            'cache_duration' => intval(get_option('steam_cache_duration', 15)),
            'text_offline' => get_option('steam_text_offline', 'Serveur injoignable'),
            'text_no_servers' => get_option('steam_text_no_servers', 'Aucun serveur configuré'),
            'text_not_found' => get_option('steam_text_not_found', 'Serveur introuvable'),
            'text_players' => get_option('steam_text_players', 'Joueurs connectés :'),
            'text_separator' => get_option('steam_text_separator', '/'),
            'text_no_players' => get_option('steam_text_no_players', 'Aucun joueur en ligne'),
            'use_text_colors' => get_option('steam_use_text_colors', 1),
            'use_border_colors' => get_option('steam_use_border_colors', 1),
            'color_text_online' => get_option('steam_color_text_online', '#2ecc71'),
            'color_text_offline' => get_option('steam_color_text_offline', '#e74c3c'),
            'color_border_online' => get_option('steam_color_border_online', '#2ecc71'),
            'color_border_offline' => get_option('steam_color_border_offline', '#e74c3c'),
            'font_family' => get_option('steam_font_family', 'Arial, sans-serif'),
            'font_size' => intval(get_option('steam_font_size', 14)),
            'all_display_default' => get_option('steam_all_display_default', 'table'),
            'show_latency_global' => get_option('steam_show_latency_global', 1),
            'latency_cache_duration' => intval(get_option('steam_latency_cache_duration', 5)),
            'latency_threshold_good' => intval(get_option('steam_latency_threshold_good', 80)),
            'latency_threshold_medium' => intval(get_option('steam_latency_threshold_medium', 200)),
            'show_motd' => get_option('steam_show_motd', 1),
            'show_version' => get_option('steam_show_version', 1),
        ];
    }
    
    public function addFrontendStyles() {
        $options = $this->getOptions();
        $this->renderInlineCSS($options);
    }
    
    private function renderInlineCSS($options) {
        $border_online = $options['use_border_colors'] ? "border:1px solid {$options['color_border_online']};" : "border:none;";
        $border_offline = $options['use_border_colors'] ? "border:1px solid {$options['color_border_offline']};" : "border:none;";
        $color_online = $options['use_text_colors'] ? $options['color_text_online'] : "inherit";
        $color_offline = $options['use_text_colors'] ? $options['color_text_offline'] : "inherit";
        
        echo "<style>
        .steam-status{ 
            font-family:{$options['font_family']}; 
            font-size:{$options['font_size']}px; 
            padding:10px; 
            border-radius:6px; 
            display:inline-block; 
            margin:6px; 
        }
        .steam-status.online{ 
            {$border_online} 
            color:{$color_online}; 
            background:rgba(0,0,0,0.03); 
        }
        .steam-status.offline{ 
            {$border_offline} 
            color:{$color_offline}; 
            background:rgba(0,0,0,0.02); 
        }
        .steam-status .server-name{ 
            font-weight:700; 
            display:block; 
            margin-bottom:4px; 
        }
        .steam-status .players,.steam-status .maxplayers{ 
            font-weight:600; 
            margin:0 4px; 
        }
        .steam-status .latency{
            font-size:{$options['font_size']}px;
        }
        .steam-status .latency.good{ color:#2ecc71; }
        .steam-status .latency.medium{ color:#f39c12; }
        .steam-status .latency.bad{ color:#e74c3c; }
        .steam-status .server-info{
            font-size:0.85em;
            opacity:0.8;
            display:block;
            margin-top:2px;
        }
        .steam-status .motd{
            font-style:italic;
            font-size:0.9em;
            margin-top:4px;
            display:block;
        }
        .steam-status .game-icon{
            width:16px;
            height:16px;
            margin-right:4px;
            vertical-align:middle;
        }
        .steam-status-table{ 
            width:100%; 
            border-collapse:collapse; 
        }
        .steam-status-table th,.steam-status-table td{ 
            padding:8px 10px; 
            border:1px solid #ddd; 
            text-align:left; 
        }
        .steam-card{ 
            display:inline-block; 
            vertical-align:top; 
            width:300px; 
            margin:6px; 
        }
        </style>";
    }
    
    // SHORTCODES
    
    public function singleServerShortcode($atts) {
        $servers = get_option('steam_servers', []);
        if (!is_array($servers) || empty($servers)) {
            return $this->renderOfflineStatus(get_option('steam_text_no_servers', 'Aucun serveur configuré'));
        }
        
        $atts = shortcode_atts([
            'id' => 0,
            'show_name' => get_option('steam_show_name', 1)
        ], $atts, 'steam_status');
        
        $id = intval($atts['id']);
        $show_name = intval($atts['show_name']);
        
        if (!isset($servers[$id])) {
            return $this->renderOfflineStatus(get_option('steam_text_not_found', 'Serveur introuvable'));
        }
        
        $server = $servers[$id];
        $data = $this->getServerDataCached($server, $id);
        
        return $this->renderServerStatus($data, $id, $show_name);
    }
    
    public function allServersShortcode($atts) {
        $servers = get_option('steam_servers', []);
        if (!is_array($servers) || empty($servers)) {
            return $this->renderOfflineStatus(get_option('steam_text_no_servers', 'Aucun serveur configuré'));
        }
        
        $display = get_option('steam_all_display_default', 'table');
        $show_name = get_option('steam_show_name', 1);
        
        if ($display === 'table') {
            return $this->renderAllServersTable($servers);
        } else {
            return $this->renderAllServersCards($servers, $show_name);
        }
    }
    
    private function renderOfflineStatus($message) {
        return '<div class="steam-status offline">' . esc_html($message) . '</div>';
    }
    
    private function renderServerStatus($data, $id, $show_name) {
        $servers = get_option('steam_servers', []);
        $server = $servers[$id] ?? [];
        
        $show_latency_global = get_option('steam_show_latency_global', 1);
        $show_latency_server = $server['show_latency'] ?? 0;
        $should_show_latency = $show_latency_global && $show_latency_server;
        
        $show_motd = get_option('steam_show_motd', 1);
        $show_version = get_option('steam_show_version', 1);
        
        $unique_id = 'steam-status-' . $id;
        $text_players = get_option('steam_text_players', 'Joueurs connectés :');
        $text_separator = get_option('steam_text_separator', '/');
        $text_offline = get_option('steam_text_offline', 'Serveur injoignable');
        
        $game_icon = $this->getGameIcon($data['game_type'] ?? 'source_generic');
        
        if ($data['online']) {
            $latency_display = '';
            if ($should_show_latency && $data['latency'] !== null) {
                $latency_class = $this->getLatencyClass($data['latency']);
                $latency_display = sprintf(
                    ' <span class="latency %s">(%dms)</span>',
                    $latency_class,
                    $data['latency']
                );
            }
            
            $version_display = '';
            if ($show_version && $data['version']) {
                $version_display = sprintf(' <span class="server-info">(%s)</span>', esc_html($data['version']));
            }
            
            $motd_display = '';
            if ($show_motd && $data['motd'] && $data['protocol'] === 'minecraft') {
                $motd_display = sprintf('<span class="motd">%s</span>', esc_html($data['motd']));
            }
            
            return sprintf(
                '<div id="%s" class="steam-status steam-status-server-%d online">%s%s<span class="label">%s</span><span class="players">%d</span><span class="separator">%s</span><span class="maxplayers">%d</span>%s%s%s</div>',
                $unique_id,
                $id,
                $show_name ? '<span class="server-name">' . $game_icon . esc_html($data['name']) . '</span>' : '',
                $game_icon,
                esc_html($text_players),
                $data['players'],
                esc_html($text_separator),
                $data['max'],
                $latency_display,
                $version_display,
                $motd_display
            );
        } else {
            return sprintf(
                '<div id="%s" class="steam-status steam-status-server-%d offline">%s%s%s</div>',
                $unique_id,
                $id,
                $show_name ? $game_icon . esc_html($data['name']) . ' : ' : '',
                $game_icon,
                esc_html($text_offline)
            );
        }
    }
    public function renderSettingsPage() {
        $servers = get_option('steam_servers', []);
        if (!is_array($servers)) $servers = [];
        
        $options = $this->getAllOptions();
        $nonce = wp_create_nonce('steam_admin_actions');
        ?>
        <div class="wrap">
            <h1>🎮 Réglages - Steam & Minecraft Server Status</h1>
            <form method="post" action="options.php">
                <?php settings_fields('steam_status_options_group'); ?>

                <h2>Configuration des serveurs</h2>
                <table class="widefat" id="steam-servers-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Type de jeu</th>
                            <th>Adresse IP</th>
                            <th>Port</th>
                            <th>Afficher latence</th>
                            <th>Test</th>
                            <th>Supprimer</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($servers as $index => $server): ?>
                        <tr>
                            <td><input type="text" name="steam_servers[<?php echo $index; ?>][name]" value="<?php echo esc_attr($server['name']); ?>" placeholder="Nom du serveur" class="regular-text"></td>
                            <td>
                                <select name="steam_servers[<?php echo $index; ?>][game_type]">
                                    <?php echo $this->renderGameOptions($server['game_type'] ?? 'source_generic'); ?>
                                </select>
                            </td>
                            <td><input type="text" name="steam_servers[<?php echo $index; ?>][ip]" value="<?php echo esc_attr($server['ip']); ?>" placeholder="45.90.160.141" class="regular-text"></td>
                            <td><input type="number" name="steam_servers[<?php echo $index; ?>][port]" value="<?php echo esc_attr($server['port']); ?>" placeholder="27015" class="small-text"></td>
                            <td><input type="checkbox" name="steam_servers[<?php echo $index; ?>][show_latency]" value="1" <?php checked(1, $server['show_latency'] ?? 0); ?>></td>
                            <td><button type="button" class="button test-server" data-nonce="<?php echo $nonce; ?>">Tester</button></td>
                            <td><button type="button" class="button remove-server">❌</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button button-primary" id="add-server">➕ Ajouter un serveur</button></p>

                <h2>Options d'affichage</h2>
                <table class="form-table">
                    <tr>
                        <th>Afficher le nom du serveur</th>
                        <td><label><input type="checkbox" name="steam_show_name" value="1" <?php checked(1, $options['show_name']); ?>> Afficher en front-end</label></td>
                    </tr>
                </table>
                
                <h2>Options Minecraft</h2>
                <table class="form-table">
                    <tr>
                        <th>MOTD</th>
                        <td><label><input type="checkbox" name="steam_show_motd" value="1" <?php checked(1, $options['show_motd']); ?>> Afficher le MOTD</label></td>
                    </tr>
                    <tr>
                        <th>Version</th>
                        <td><label><input type="checkbox" name="steam_show_version" value="1" <?php checked(1, $options['show_version']); ?>> Afficher la version</label></td>
                    </tr>
                </table>

                <h2>Configuration Latence</h2>
                <table class="form-table">
                    <tr>
                        <th>Affichage latence</th>
                        <td><label><input type="checkbox" name="steam_show_latency_global" value="1" <?php checked(1, $options['show_latency_global']); ?>> Activer globalement</label></td>
                    </tr>
                    <tr>
                        <th>Cache latence (secondes)</th>
                        <td><input type="number" name="steam_latency_cache_duration" value="<?php echo esc_attr($options['latency_cache_duration']); ?>" min="5" step="5"></td>
                    </tr>
                    <tr>
                        <th>Seuil "Bonne" latence (ms)</th>
                        <td><input type="number" name="steam_latency_threshold_good" value="<?php echo esc_attr($options['latency_threshold_good']); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th>Seuil "Moyenne" latence (ms)</th>
                        <td><input type="number" name="steam_latency_threshold_medium" value="<?php echo esc_attr($options['latency_threshold_medium']); ?>" min="1"></td>
                    </tr>
                </table>

                <h2>Cache</h2>
                <table class="form-table">
                    <tr>
                        <th>Durée du cache serveur (secondes)</th>
                        <td><input type="number" name="steam_cache_duration" value="<?php echo esc_attr($options['cache_duration']); ?>" min="5" step="5"></td>
                    </tr>
                </table>

                <h2>Textes personnalisables</h2>
                <table class="form-table">
                    <tr><th>Serveur injoignable</th><td><input type="text" name="steam_text_offline" value="<?php echo esc_attr($options['text_offline']); ?>" class="regular-text"></td></tr>
                    <tr><th>Aucun serveur configuré</th><td><input type="text" name="steam_text_no_servers" value="<?php echo esc_attr($options['text_no_servers']); ?>" class="regular-text"></td></tr>
                    <tr><th>Serveur introuvable</th><td><input type="text" name="steam_text_not_found" value="<?php echo esc_attr($options['text_not_found']); ?>" class="regular-text"></td></tr>
                    <tr><th>"Joueurs connectés"</th><td><input type="text" name="steam_text_players" value="<?php echo esc_attr($options['text_players']); ?>" class="regular-text"></td></tr>
                    <tr><th>Séparateur joueurs/max</th><td><input type="text" name="steam_text_separator" value="<?php echo esc_attr($options['text_separator']); ?>" class="small-text"></td></tr>
                    <tr><th>Aucun joueur</th><td><input type="text" name="steam_text_no_players" value="<?php echo esc_attr($options['text_no_players']); ?>" class="regular-text"></td></tr>
                </table>

                <h2>Style Online / Offline</h2>
                <table class="form-table">
                    <tr>
                        <th>Options</th>
                        <td>
                            <label><input type="checkbox" name="steam_use_text_colors" value="1" <?php checked(1, $options['use_text_colors']); ?>> Activer la couleur du texte</label><br>
                            <label><input type="checkbox" name="steam_use_border_colors" value="1" <?php checked(1, $options['use_border_colors']); ?>> Activer la couleur de la bordure</label>
                        </td>
                    </tr>
                </table>

                <h2>Couleurs Online / Offline</h2>
                <table class="form-table">
                    <tr><th>Texte Online</th><td><input type="text" class="steam-color-field" name="steam_color_text_online" value="<?php echo esc_attr($options['color_text_online']); ?>"></td></tr>
                    <tr><th>Texte Offline</th><td><input type="text" class="steam-color-field" name="steam_color_text_offline" value="<?php echo esc_attr($options['color_text_offline']); ?>"></td></tr>
                    <tr><th>Bordure Online</th><td><input type="text" class="steam-color-field" name="steam_color_border_online" value="<?php echo esc_attr($options['color_border_online']); ?>"></td></tr>
                    <tr><th>Bordure Offline</th><td><input type="text" class="steam-color-field" name="steam_color_border_offline" value="<?php echo esc_attr($options['color_border_offline']); ?>"></td></tr>
                </table>

                <h3>Prévisualisation</h3>
                <div id="preview-online" class="steam-status online" style="border: 2px solid <?php echo esc_attr($options['color_border_online']); ?>; color: <?php echo esc_attr($options['color_text_online']); ?>;">
                    🎮 Serveur Online - 10/20 joueurs
                </div>
                <div id="preview-offline" class="steam-status offline" style="border: 2px solid <?php echo esc_attr($options['color_border_offline']); ?>; color: <?php echo esc_attr($options['color_text_offline']); ?>;">
                    🎮 Serveur Offline
                </div>

                <h2>🔗 Intégration Discord</h2>
                <table class="form-table">
                    <tr>
                        <th>Activer les notifications Discord</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_enable_notifications" value="1" id="discord-enable" 
                                    <?php checked(1, get_option('discord_enable_notifications', 0)); ?>>
                                Envoyer des notifications Discord
                            </label>
                        </td>
                    </tr>
                    <tr class="discord-dependent">
                        <th>URL du Webhook Discord</th>
                        <td>
                            <input type="text" name="discord_webhook_url" class="regular-text" id="discord-webhook-url"
                                value="<?php echo esc_attr(get_option('discord_webhook_url', '')); ?>" 
                                placeholder="https://discord.com/api/webhooks/...">
                            <button type="button" id="test-discord-webhook" class="button" data-nonce="<?php echo $nonce; ?>">Tester</button>
                            <p class="description">
                                Pour créer un webhook : Paramètres du serveur Discord → Intégrations → Webhooks → Nouveau webhook
                            </p>
                        </td>
                    </tr>
                    <tr class="discord-dependent">
                        <th>Nom du bot</th>
                        <td>
                            <input type="text" name="discord_bot_username" class="regular-text"
                                value="<?php echo esc_attr(get_option('discord_bot_username', 'Server Status Bot')); ?>" 
                                placeholder="Server Status Bot">
                        </td>
                    </tr>
                    <tr class="discord-dependent">
                        <th>Avatar du bot (URL)</th>
                        <td>
                            <input type="text" name="discord_bot_avatar" class="regular-text"
                                value="<?php echo esc_attr(get_option('discord_bot_avatar', '')); ?>" 
                                placeholder="https://example.com/avatar.png">
                        </td>
                    </tr>
                </table>

                <h3 class="discord-dependent">Types de notifications</h3>
                <table class="form-table discord-dependent">
                    <tr>
                        <th>Serveur hors ligne</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_notify_offline" value="1" 
                                    <?php checked(1, get_option('discord_notify_offline', 1)); ?>>
                                Notifier quand un serveur passe hors ligne
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Serveur en ligne</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_notify_online" value="1" 
                                    <?php checked(1, get_option('discord_notify_online', 1)); ?>>
                                Notifier quand un serveur revient en ligne
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Seuil de joueurs</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_notify_player_threshold" value="1" id="notify-player-threshold"
                                    <?php checked(1, get_option('discord_notify_player_threshold', 0)); ?>>
                                Notifier quand le nombre de joueurs dépasse :
                                <input type="number" name="discord_player_threshold_value" 
                                    value="<?php echo intval(get_option('discord_player_threshold_value', 10)); ?>" 
                                    min="1" style="width: 60px;"> joueurs
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Latence élevée</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_notify_high_latency" value="1" id="notify-high-latency"
                                    <?php checked(1, get_option('discord_notify_high_latency', 0)); ?>>
                                Notifier quand la latence dépasse :
                                <input type="number" name="discord_latency_threshold" 
                                    value="<?php echo intval(get_option('discord_latency_threshold', 300)); ?>" 
                                    min="50" style="width: 60px;"> ms
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Résumé quotidien</th>
                        <td>
                            <label>
                                <input type="checkbox" name="discord_daily_summary" value="1" 
                                    <?php checked(1, get_option('discord_daily_summary', 0)); ?>>
                                Envoyer un résumé quotidien à minuit
                            </label>
                        </td>
                    </tr>
                </table>

                <h3 class="discord-dependent">Configuration avancée</h3>
                <table class="form-table discord-dependent">
                    <tr>
                        <th>ID du rôle à mentionner</th>
                        <td>
                            <input type="text" name="discord_mention_role" class="regular-text"
                                value="<?php echo esc_attr(get_option('discord_mention_role', '')); ?>" 
                                placeholder="123456789012345678">
                            <p class="description">
                                ID du rôle Discord à mentionner lors des alertes critiques (offline, latence élevée)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Cooldown des notifications</th>
                        <td>
                            <input type="number" name="discord_notification_cooldown" 
                                value="<?php echo intval(get_option('discord_notification_cooldown', 300)); ?>" 
                                min="60" step="60"> secondes
                            <p class="description">
                                Temps minimum entre deux notifications pour le même serveur
                            </p>
                        </td>
                    </tr>
                </table>

                <h3 class="discord-dependent">Couleurs des embeds Discord</h3>
                <table class="form-table discord-dependent">
                    <tr>
                        <th>Couleur Online</th>
                        <td>
                            <input type="text" class="steam-color-field" name="discord_embed_color_online" 
                                value="<?php echo esc_attr(get_option('discord_embed_color_online', '#2ecc71')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Couleur Offline</th>
                        <td>
                            <input type="text" class="steam-color-field" name="discord_embed_color_offline" 
                                value="<?php echo esc_attr(get_option('discord_embed_color_offline', '#e74c3c')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Couleur Avertissement</th>
                        <td>
                            <input type="text" class="steam-color-field" name="discord_embed_color_warning" 
                                value="<?php echo esc_attr(get_option('discord_embed_color_warning', '#f39c12')); ?>">
                        </td>
                    </tr>
                </table>

                <h2>Police</h2>
                <table class="form-table">
                    <tr>
                        <th>Police du texte</th>
                        <td><input type="text" name="steam_font_family" value="<?php echo esc_attr($options['font_family']); ?>" placeholder="Ex: Arial, sans-serif" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Taille du texte (px)</th>
                        <td><input type="number" name="steam_font_size" value="<?php echo esc_attr($options['font_size']); ?>" min="8" step="1"></td>
                    </tr>
                </table>

                <h2>Shortcode [steam_status_all]</h2>
                <table class="form-table">
                    <tr>
                        <th>Rendu par défaut</th>
                        <td>
                            <select name="steam_all_display_default">
                                <option value="table" <?php selected('table', $options['all_display_default']); ?>>Tableau</option>
                                <option value="cards" <?php selected('cards', $options['all_display_default']); ?>>Cartes</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2>Widget Elementor</h2>
                <p><strong>Le widget "Server Status" est maintenant disponible dans Elementor !</strong></p>
                <p>Vous pouvez l'utiliser en cherchant "Server Status" dans la liste des widgets Elementor.</p>

                <?php submit_button('Enregistrer les modifications'); ?>
            </form>
        </div>
        <?php
    }
    
    private function renderAllServersTable($servers) {
        $show_version = get_option('steam_show_version', 1);
        
        $html = '<table class="steam-status-table"><thead><tr><th>Serveur</th><th>Type</th><th>État</th><th>Joueurs</th><th>Latence</th>';
        if ($show_version) {
            $html .= '<th>Version</th>';
        }
        $html .= '</tr></thead><tbody>';
        
        foreach ($servers as $i => $server) {
            $data = $this->getServerDataCached($server, $i);
            $status = $data['online'] ? '<span class="online">Online</span>' : '<span class="offline">Offline</span>';
            $players = $data['online'] ? $data['players'] . ' / ' . $data['max'] : '0 / 0';
            
            $game_icon = $this->getGameIcon($data['game_type'] ?? 'source_generic');
            $protocol = $this->getProtocolFromGameType($data['game_type'] ?? 'source_generic');
            $game_name = $this->supported_games[$protocol][$data['game_type'] ?? 'source_generic'] ?? 'Inconnu';
            
            $latency_display = '-';
            if ($data['online'] && $data['latency'] !== null && ($server['show_latency'] ?? 0) && get_option('steam_show_latency_global', 1)) {
                $latency_class = $this->getLatencyClass($data['latency']);
                $latency_display = sprintf('<span class="latency %s">%dms</span>', $latency_class, $data['latency']);
            }
            
            $version_display = '-';
            if ($show_version && $data['version']) {
                $version_display = esc_html($data['version']);
            }
            
            $html .= sprintf(
                '<tr><td>%s %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
                $game_icon,
                esc_html($server['name']),
                $game_name,
                $status,
                $players,
                $latency_display
            );
            
            if ($show_version) {
                $html .= sprintf('<td>%s</td>', $version_display);
            }
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        return $html;
    }
    
    private function renderAllServersCards($servers, $show_name) {
        $show_version = get_option('steam_show_version', 1);
        $show_motd = get_option('steam_show_motd', 1);
        
        $html = '<div class="steam-cards">';
        
        foreach ($servers as $i => $server) {
            $data = $this->getServerDataCached($server, $i);
            $status = $data['online'] ? '<span class="online">Online</span>' : '<span class="offline">Offline</span>';
            $players = $data['online'] ? $data['players'] . ' / ' . $data['max'] : '0 / 0';
            
            $game_icon = $this->getGameIcon($data['game_type'] ?? 'source_generic');
            $protocol = $this->getProtocolFromGameType($data['game_type'] ?? 'source_generic');
            $game_name = $this->supported_games[$protocol][$data['game_type'] ?? 'source_generic'] ?? 'Inconnu';
            
            $latency_display = '';
            if ($data['online'] && $data['latency'] !== null && ($server['show_latency'] ?? 0) && get_option('steam_show_latency_global', 1)) {
                $latency_class = $this->getLatencyClass($data['latency']);
                $latency_display = sprintf(' <span class="latency %s">(%dms)</span>', $latency_class, $data['latency']);
            }
            
            $version_display = '';
            if ($show_version && $data['version']) {
                $version_display = sprintf('<br><span class="server-info">%s</span>', esc_html($data['version']));
            }
            
            $motd_display = '';
            if ($show_motd && $data['motd'] && $data['protocol'] === 'minecraft') {
                $motd_display = sprintf('<br><span class="motd">%s</span>', esc_html($data['motd']));
            }
            
            $css_class = $data['online'] ? 'online' : 'offline';
            
            $html .= sprintf(
                '<div class="steam-status steam-card steam-status-server-%d %s">%s<strong>%s [%s]</strong><br>%s%s<br>%s%s%s</div>',
                $i,
                $css_class,
                $show_name ? '<strong>' . $game_icon . ' ' . esc_html($server['name']) . '</strong><br>' : '',
                $game_icon,
                $game_name,
                $status,
                $latency_display,
                $players,
                $version_display,
                $motd_display
            );
        }
        
        $html .= '</div>';
        return $html;
    }
}

// Classes pour GitHub Updater et initialisation
class SteamStatusGitHubUpdater {
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
                'package' => $this->getZipUrl()
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
            ],
            'download_link' => $this->getZipUrl()
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
    
    private function getRepositoryInfo() {
        if ($this->github_response !== null) {
            return;
        }
        
        $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->github_user, $this->github_repo);
        $response = wp_remote_get($request_uri, ['timeout' => 10]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $this->github_response = json_decode(wp_remote_retrieve_body($response), true);
        } else {
            $this->github_response = false;
        }
    }
    
    private function getNewVersion() {
        $this->getRepositoryInfo();
        return !empty($this->github_response['tag_name']) ? ltrim($this->github_response['tag_name'], 'v') : false;
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
        return !empty($this->github_response['body']) ? $this->github_response['body'] : 'Pas de notes de version disponibles.';
    }
}

// Initialisation du plugin
add_action('plugins_loaded', function() {
    SteamServerStatusPlugin::getInstance();
});

// Hook de désactivation pour nettoyer les caches et crons
register_deactivation_hook(__FILE__, function() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_steam_status_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_steam_status_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_steam_latency_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_steam_latency_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_steam_server_state_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_steam_discord_cooldown_%'");
    
    // Supprimer les crons
    wp_clear_scheduled_hook('steam_status_check_servers');
    wp_clear_scheduled_hook('steam_status_daily_summary');
});