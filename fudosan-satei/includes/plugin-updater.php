<?php
/**
 * かんたん不動産AI査定 — プラグイン自動更新チェッカー
 *
 * 更新サーバー(JSON)を WordPress の更新サーバーとして使い、標準の
 * 「プラグイン更新」フローに組み込む。管理画面に「更新可能」バッジが出て、
 * ワンクリックで zip 取得・展開できる（毎回の手動アップロード不要）。
 *
 * 動作:
 *   1. WP が定期的に pre_set_site_transient_update_plugins を叩く
 *   2. 本クラスが更新サーバーから JSON を取得し、ヘッダーバージョンと比較
 *   3. 新しければ transient->response に追加 → 「更新可能」バッジ表示
 *   4. ユーザーが「更新」クリック → WP が download_url から zip 取得・展開
 *
 * 更新サーバーの JSON 例（update.json）:
 *   {
 *     "name": "かんたん不動産AI査定",
 *     "version": "1.0.1",
 *     "download_url": "https://.../fudosan-satei.zip",
 *     "requires": "5.8",
 *     "tested": "6.7",
 *     "requires_php": "7.4",
 *     "homepage": "https://...",
 *     "sections": { "changelog": "・○○を修正 ..." }
 *   }
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('FS_Satei_Updater')) :

class FS_Satei_Updater {
    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $update_url;
    private $cache_key;
    private $cache_ttl;

    public function __construct($plugin_file, $update_url, $cache_ttl = 1800) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug     = dirname($this->plugin_basename);
        $this->update_url      = $update_url;
        $this->cache_key       = 'fs_satei_updater_' . md5($this->plugin_basename);
        $this->cache_ttl       = (int)$cache_ttl;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api',                            array($this, 'plugins_api_filter'), 10, 3);
        add_action('upgrader_process_complete',              array($this, 'purge_cache'), 10, 2);
    }

    /** 更新サーバーから最新メタ情報を取得（キャッシュあり） */
    private function fetch_remote_info() {
        if (empty($this->update_url)) return null;
        $cached = get_transient($this->cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get($this->update_url, array(
            'timeout' => 10,
            'headers' => array('Accept' => 'application/json'),
        ));
        if (is_wp_error($response)) return null;
        if ((int)wp_remote_retrieve_response_code($response) !== 200) return null;

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) || empty($data->version)) return null;

        set_transient($this->cache_key, $data, $this->cache_ttl);
        return $data;
    }

    /** WP の更新チェックに割り込んで、自分の更新情報を注入する */
    public function check_for_update($transient) {
        if (!is_object($transient)) return $transient;

        $remote = $this->fetch_remote_info();
        if (!$remote) return $transient;

        $current = $this->current_installed_version();
        if (!$current) return $transient;

        if (version_compare($current, $remote->version, '<')) {
            $entry = (object)array(
                'id'           => $this->plugin_basename,
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->plugin_basename,
                'new_version'  => $remote->version,
                'url'          => isset($remote->homepage) ? $remote->homepage : '',
                'package'      => isset($remote->download_url) ? $remote->download_url : '',
                'tested'       => isset($remote->tested) ? $remote->tested : '',
                'requires'     => isset($remote->requires) ? $remote->requires : '',
                'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
                'icons'        => array(),
                'banners'      => array(),
            );
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = array();
            }
            $transient->response[$this->plugin_basename] = $entry;
        } else {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$this->plugin_basename] = (object)array(
                'id'          => $this->plugin_basename,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote->version,
                'url'         => '',
                'package'     => '',
            );
        }
        return $transient;
    }

    /** 「詳細を表示」モーダル用 */
    public function plugins_api_filter($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) return $result;

        $remote = $this->fetch_remote_info();
        if (!$remote) return $result;

        return (object)array(
            'name'         => isset($remote->name) ? $remote->name : $this->plugin_slug,
            'slug'         => $this->plugin_slug,
            'version'      => $remote->version,
            'tested'       => isset($remote->tested) ? $remote->tested : '',
            'requires'     => isset($remote->requires) ? $remote->requires : '',
            'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
            'author'       => isset($remote->author) ? $remote->author : '',
            'download_link'=> isset($remote->download_url) ? $remote->download_url : '',
            'sections'     => isset($remote->sections) ? (array)$remote->sections : array(),
            'banners'      => array(),
        );
    }

    /** 更新完了後にキャッシュを破棄して再チェックを促す */
    public function purge_cache($upgrader, $hook_extra) {
        if (!is_array($hook_extra)) return;
        if (($hook_extra['action'] ?? '') !== 'update') return;
        if (($hook_extra['type']   ?? '') !== 'plugin') return;
        delete_transient($this->cache_key);
    }

    /** 現在インストール済みのバージョン（プラグインヘッダー）を取得 */
    private function current_installed_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->plugin_file, false, false);
        return isset($data['Version']) ? $data['Version'] : '';
    }
}

endif;
