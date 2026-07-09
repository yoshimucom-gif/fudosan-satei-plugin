<?php
/**
 * かんたん不動産AI査定 — プラグイン自動更新チェッカー（非公開GitHub対応）
 *
 * 非公開(private)のGitHubリポジトリを更新サーバーとして使い、標準の
 * 「プラグイン更新」フローに組み込む。管理画面に「更新可能」バッジが出て、
 * ワンクリックで zip 取得・展開できる（毎回の手動アップロード不要）。
 *
 * 配信モデル（push だけで全サイト更新）:
 *   リポジトリに次の2ファイルを置き、git push するだけ。
 *     - update.json          … 最新バージョン情報
 *     - fudosan-satei.zip     … 配布本体
 *
 * 認証:
 *   GitHub Contents API を Personal Access Token で叩く。
 *   トークンはコードに埋め込まず、各サイトのWP設定（DB）に保存する。
 *   → 公開リポジトリにコードが出ないので、非公開のまま運用できる。
 *
 * 必要トークン: リポジトリの「Contents: Read-only」権限のみ
 *   （Fine-grained PAT を対象リポジトリだけに絞るのが安全）。
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('FS_Satei_Updater')) :

class FS_Satei_Updater {
    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $owner;
    private $repo;
    private $branch;
    private $asset;      // 配布zipのファイル名
    private $token;
    private $api_base;   // https://api.github.com/repos/{owner}/{repo}
    private $cache_key;
    private $cache_ttl;

    public function __construct($plugin_file, $owner, $repo, $token, $asset = 'fudosan-satei.zip', $branch = 'main', $cache_ttl = 1800) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug     = dirname($this->plugin_basename);
        $this->owner           = $owner;
        $this->repo            = $repo;
        $this->token           = $token;
        $this->asset           = $asset;
        $this->branch          = $branch;
        $this->api_base        = 'https://api.github.com/repos/' . $owner . '/' . $repo;
        $this->cache_key       = 'fs_satei_updater_' . md5($this->plugin_basename);
        $this->cache_ttl       = (int)$cache_ttl;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api',                            array($this, 'plugins_api_filter'), 10, 3);
        add_action('upgrader_process_complete',              array($this, 'purge_cache'), 10, 2);
        add_filter('http_request_args',                      array($this, 'authorize_download'), 10, 2);
    }

    /** 配布zipのダウンロードURL（Contents API・raw） */
    private function package_url() {
        return $this->api_base . '/contents/' . rawurlencode($this->asset) . '?ref=' . rawurlencode($this->branch);
    }

    /** WPがpackageをダウンロードする際、対象URLにだけ認証ヘッダーを付与する */
    public function authorize_download($args, $url) {
        if (empty($this->token)) return $args;
        if (strpos($url, $this->api_base . '/contents/' . rawurlencode($this->asset)) !== 0) return $args;
        if (!isset($args['headers']) || !is_array($args['headers'])) $args['headers'] = array();
        $args['headers']['Authorization']       = 'Bearer ' . $this->token;
        $args['headers']['Accept']              = 'application/vnd.github.raw';
        $args['headers']['User-Agent']          = 'fudosan-satei-updater';
        $args['headers']['X-GitHub-Api-Version']= '2022-11-28';
        return $args;
    }

    /** GitHub Contents API から生ファイルを取得 */
    private function gh_get_raw($path) {
        if (empty($this->token)) return null;
        $res = wp_remote_get($this->api_base . $path, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization'        => 'Bearer ' . $this->token,
                'Accept'               => 'application/vnd.github.raw',
                'User-Agent'           => 'fudosan-satei-updater',
                'X-GitHub-Api-Version' => '2022-11-28',
            ),
        ));
        if (is_wp_error($res)) return null;
        if ((int)wp_remote_retrieve_response_code($res) !== 200) return null;
        return wp_remote_retrieve_body($res);
    }

    /** update.json を取得（キャッシュあり） */
    private function fetch_remote_info() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) return $cached;

        $body = $this->gh_get_raw('/contents/update.json?ref=' . rawurlencode($this->branch));
        if (!$body) return null;
        $data = json_decode($body);
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
                'package'      => $this->package_url(),
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
            'download_link'=> $this->package_url(),
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
