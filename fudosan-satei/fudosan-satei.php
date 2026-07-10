<?php
/**
 * Plugin Name: かんたん不動産AI査定
 * Description: 匿名の不動産価格査定フォーム。国交省「不動産情報ライブラリ」の実成約事例から参考価格レンジを算出し、結果をメール送信＋リード保存。ショートコード [fudosan_satei] をページに貼るだけ。
 * Version: 1.4.0
 * Author: (運営者)
 * License: GPLv2 or later
 * Text Domain: fudosan-satei
 *
 * ★法的注意: 本プラグインが出すのは宅建業の「価格査定（参考価格）」であり、
 *   不動産鑑定士の「鑑定評価」ではない。UI・メール・免責文で明示している。
 *   公開前に弁護士等の確認を推奨。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FS_VER', '1.4.0');
define('FS_OPT', 'fudosan_satei_options');
define('FS_ENDPOINT', 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT001');

/**
 * 自動更新の置き場（update.json の URL）。
 * ミカタのサーバー/WPサイト上のフォルダに update.json と fudosan-satei.zip を
 * 置き、その update.json の URL をここに設定する。新バージョンを置くと WP管理画面に
 * 「更新可能」バッジが出て、ワンクリック更新できる（各サイトへの手動配布は不要）。
 * ※ 空なら自動更新は無効（手動アップロードでの運用は可能）。
 */
define('FS_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/fudosan-satei-plugin/main/update.json');

/* 自動更新チェッカー（管理画面のみ・URL未設定なら無効） */
if (is_admin()) {
    require_once __DIR__ . '/includes/plugin-updater.php';
    new FS_Satei_Updater(__FILE__, FS_UPDATE_URL);
}

/* =========================================================================
 * 1. 有効化: リード保存テーブル作成
 * ======================================================================= */
register_activation_hook(__FILE__, 'fs_activate');
function fs_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_satei_leads';
    $charset = $wpdb->get_charset_collate();
    // dbDeltaは「1カラム1行」でないと既存テーブルへのカラム追加を取りこぼす
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        email VARCHAR(191) NOT NULL,
        pref VARCHAR(50) NULL,
        city VARCHAR(50) NULL,
        ptype VARCHAR(20) NULL,
        area FLOAT NULL,
        build_year INT NULL,
        station_min INT NULL,
        station_name VARCHAR(100) NULL,
        floor_plan VARCHAR(30) NULL,
        district VARCHAR(100) NULL,
        low BIGINT NULL,
        mid BIGINT NULL,
        high BIGINT NULL,
        sample_size INT NULL,
        marketing_opt_in TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    fs_ensure_columns();
}

/* dbDeltaの取りこぼし対策: 不足カラムを明示的にALTERで追加（確実） */
function fs_ensure_columns() {
    global $wpdb;
    $t = $wpdb->prefix . 'fudosan_satei_leads';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t) return;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `$t`", 0);
    if (!is_array($cols)) return;
    $need = array(
        'station_name' => 'VARCHAR(100) NULL',
        'floor_plan'   => 'VARCHAR(30) NULL',
        'district'     => 'VARCHAR(100) NULL',
        'station_min'  => 'INT NULL',
    );
    foreach ($need as $c => $def) {
        if (!in_array($c, $cols, true)) {
            $wpdb->query("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
}

/* 自動更新でバージョンが上がったらテーブル定義を追従（新カラム追加等） */
add_action('plugins_loaded', 'fs_maybe_upgrade');
function fs_maybe_upgrade() {
    if (get_option('fs_db_ver') !== FS_VER) {
        fs_activate();
        update_option('fs_db_ver', FS_VER);
    }
}

/* =========================================================================
 * 2. 設定（APIキー・運営者情報）
 * ======================================================================= */
function fs_opt($key, $default = '') {
    $o = get_option(FS_OPT, array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

add_action('admin_menu', function () {
    // 専用のトップレベルメニュー（設定 と 査定結果・顧客情報 をまとめる）
    add_menu_page('匿名不動産AI査定', '匿名不動産AI査定', 'manage_options', 'fudosan-satei', 'fs_settings_page', 'dashicons-building', 58);
    add_submenu_page('fudosan-satei', '設定', '設定', 'manage_options', 'fudosan-satei', 'fs_settings_page');
    add_submenu_page('fudosan-satei', '査定結果・顧客情報', '査定結果・顧客情報', 'manage_options', 'fudosan-satei-leads', 'fs_leads_page');
});

add_action('admin_init', function () {
    register_setting('fs_group', FS_OPT, 'fs_sanitize_options');
});

function fs_sanitize_options($in) {
    $areas = array();
    if (!empty($in['areas']) && is_array($in['areas'])) {
        foreach ($in['areas'] as $a) { $a = sanitize_text_field($a); if ($a !== '') $areas[] = $a; }
    }
    return array(
        'api_key'          => sanitize_text_field($in['api_key'] ?? ''),
        'use_mock'         => !empty($in['use_mock']) ? '1' : '',
        'site_name'        => sanitize_text_field($in['site_name'] ?? 'かんたん不動産AI査定'),
        'operator_name'    => sanitize_text_field($in['operator_name'] ?? ''),
        'operator_contact' => sanitize_text_field($in['operator_contact'] ?? ''),
        'from_email'       => sanitize_email($in['from_email'] ?? get_option('admin_email')),
        'privacy_url'      => esc_url_raw($in['privacy_url'] ?? ''),
        'terms_url'        => esc_url_raw($in['terms_url'] ?? ''),
        // 表示項目（未送信=チェック外れ=非表示）
        'show_district'    => !empty($in['show_district']) ? '1' : '',
        'show_station'     => !empty($in['show_station']) ? '1' : '',
        'show_floor_plan'  => !empty($in['show_floor_plan']) ? '1' : '',
        'show_build_year'  => !empty($in['show_build_year']) ? '1' : '',
        'show_marketing'   => !empty($in['show_marketing']) ? '1' : '',
        // 対象エリア（空=全国）
        'areas'            => $areas,
        // 自動返信メール
        'mail_subject'     => sanitize_text_field($in['mail_subject'] ?? ''),
        'mail_body'        => sanitize_textarea_field($in['mail_body'] ?? ''),
    );
}

/* 表示項目の判定（未保存＝デフォルト表示、保存済みは値そのもの。空='非表示'を区別） */
function fs_show($key) {
    $o = get_option(FS_OPT, array());
    if (!is_array($o) || !array_key_exists('show_' . $key, $o)) return true; // 未設定=表示
    return $o['show_' . $key] === '1';
}

/* 対象エリアで都道府県を絞る（未設定なら全国）。数値文字列キーは整数化されるため非strict比較 */
function fs_area_prefs() {
    $all = fs_prefs();
    $sel = fs_opt('areas', array());
    if (empty($sel) || !is_array($sel)) return $all;
    $sel = array_map('strval', $sel);
    $out = array();
    foreach ($all as $code => $name) if (in_array((string)$code, $sel, true)) $out[(string)$code] = $name;
    return $out ?: $all;
}

/* 自動返信メールの初期本文（差し込みタグ付き） */
function fs_default_mail_body() {
    return "【{site_name}】査定結果のお知らせ\n\n"
        . "この度はご利用ありがとうございます。ご入力の内容に基づく参考価格は以下の通りです。\n\n"
        . "{property_details}\n\n"
        . "─────────────────────\n"
        . "  参考価格レンジ : {price_low} 〜 {price_high}\n"
        . "  中央値の目安   : {price_mid}\n"
        . "─────────────────────\n\n"
        . "{reason}\n\n"
        . "───────────────────────────────\n"
        . "【重要なご注意】\n"
        . "・本結果はAIによる簡易的な『参考価格（価格査定）』であり、\n"
        . "  不動産鑑定士による『鑑定評価』ではありません。\n"
        . "・過去の周辺取引事例からの機械的な推定値で、実際の売却価格・\n"
        . "  成約価格を保証するものではありません。\n"
        . "・正確な価格は、現地確認を含む個別査定が必要です。\n"
        . "───────────────────────────────\n\n"
        . "{operator_name}\n"
        . "お問い合わせ: {operator_contact}";
}

function fs_settings_page() {
    $o = get_option(FS_OPT, array());
    $sel_areas = (isset($o['areas']) && is_array($o['areas'])) ? $o['areas'] : array();
    ?>
    <div class="wrap">
        <h1>かんたん不動産AI査定 設定</h1>
        <?php if (isset($_GET['testmail'])) {
            $tm_ok = ($_GET['testmail'] === '1');
            $tm_to = isset($_GET['to']) ? sanitize_email(wp_unslash($_GET['to'])) : '';
            echo '<div class="notice notice-' . ($tm_ok ? 'success' : 'error') . '"><p>' .
                ($tm_ok
                    ? 'テストメールを <strong>' . esc_html($tm_to) . '</strong> に送信しました。届かない場合は<strong>迷惑メールフォルダ</strong>も確認してください（届かない＝SPF/DKIM未設定の可能性大）。'
                    : 'テストメールの送信に失敗しました。WP Mail SMTP などの送信設定を確認してください。') .
                '</p></div>';
        } ?>
        <p>ページに <code>[fudosan_satei]</code> を貼ると査定フォームが表示されます。<br>
        メインビジュアル横などに置く短縮版：<code>[fudosan_satei design="compact"]</code> ／
        ステップ入口：<code>[fudosan_satei design="teaser" url="/satei/"]</code></p>
        <h2 class="nav-tab-wrapper" id="fs-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="basic">基本設定</a>
            <a href="#" class="nav-tab" data-tab="display">表示項目・対象エリア</a>
            <a href="#" class="nav-tab" data-tab="mail">自動返信メール</a>
        </h2>
        <form method="post" action="options.php">
            <?php settings_fields('fs_group'); ?>

            <div class="fs-tabpanel" data-tab="basic">
            <table class="form-table">
                <tr><th>APIキー</th><td>
                    <input type="text" name="<?php echo FS_OPT; ?>[api_key]" value="<?php echo esc_attr(fs_opt('api_key')); ?>" size="50">
                    <p class="description">国交省 不動産情報ライブラリのAPIキー（<code>Ocp-Apim-Subscription-Key</code>）。未入力の場合はモックデータで動作します。</p>
                </td></tr>
                <tr><th>強制モック</th><td>
                    <label><input type="checkbox" name="<?php echo FS_OPT; ?>[use_mock]" value="1" <?php checked(fs_opt('use_mock'), '1'); ?>> テスト用のダミー事例を使う（キーがあっても優先）</label>
                </td></tr>
                <tr><th>サイト名</th><td><input type="text" name="<?php echo FS_OPT; ?>[site_name]" value="<?php echo esc_attr(fs_opt('site_name', 'かんたん不動産AI査定')); ?>" size="40"></td></tr>
                <tr><th>運営者名</th><td><input type="text" name="<?php echo FS_OPT; ?>[operator_name]" value="<?php echo esc_attr(fs_opt('operator_name')); ?>" size="40"></td></tr>
                <tr><th>問い合わせ先</th><td><input type="text" name="<?php echo FS_OPT; ?>[operator_contact]" value="<?php echo esc_attr(fs_opt('operator_contact')); ?>" size="40"></td></tr>
                <tr><th>送信元メール</th><td><input type="email" name="<?php echo FS_OPT; ?>[from_email]" value="<?php echo esc_attr(fs_opt('from_email', get_option('admin_email'))); ?>" size="40">
                    <p class="description">到達率のため WP Mail SMTP 等で SPF/DKIM を設定推奨。</p></td></tr>
                <tr><th>プライバシーポリシーURL</th><td><input type="url" name="<?php echo FS_OPT; ?>[privacy_url]" value="<?php echo esc_attr(fs_opt('privacy_url')); ?>" size="50"></td></tr>
                <tr><th>利用規約・免責URL</th><td><input type="url" name="<?php echo FS_OPT; ?>[terms_url]" value="<?php echo esc_attr(fs_opt('terms_url')); ?>" size="50"></td></tr>
            </table>
            </div>

            <div class="fs-tabpanel" data-tab="display" style="display:none">
            <h3>フォームに表示する任意項目</h3>
            <table class="form-table"><tr><th>表示する項目</th><td>
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_district]" value="1" <?php checked(fs_show('district')); ?>> 地区（町名）</label><br>
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_station]" value="1" <?php checked(fs_show('station')); ?>> 最寄駅・駅まで徒歩（分）</label><br>
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_floor_plan]" value="1" <?php checked(fs_show('floor_plan')); ?>> 間取り</label><br>
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_build_year]" value="1" <?php checked(fs_show('build_year')); ?>> 築年</label><br>
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_marketing]" value="1" <?php checked(fs_show('marketing')); ?>> 「営業案内メールを希望」チェック欄</label>
                <p class="description">チェックを外した項目はフォームに表示されません。<br>※ 種別・都道府県・市区町村・面積・メール・同意チェックは常に表示されます。</p>
            </td></tr></table>

            <h3>対象エリア（都道府県）</h3>
            <p class="description">チェックした都道府県だけを選択肢に出します。<strong>1つも選ばなければ全国（47都道府県）</strong>が対象です。</p>
            <div style="columns:4;-webkit-columns:4;max-width:820px;margin-top:8px">
            <?php foreach (fs_prefs() as $code => $name): ?>
                <label style="display:block;padding:2px 0"><input type="checkbox" name="<?php echo FS_OPT; ?>[areas][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array((string)$code, array_map('strval', $sel_areas), true)); ?>> <?php echo esc_html($name); ?></label>
            <?php endforeach; ?>
            </div>
            </div>

            <div class="fs-tabpanel" data-tab="mail" style="display:none">
            <table class="form-table">
                <tr><th>件名</th><td>
                    <input type="text" name="<?php echo FS_OPT; ?>[mail_subject]" value="<?php echo esc_attr(fs_opt('mail_subject')); ?>" size="60" placeholder="【{site_name}】査定結果のお知らせ">
                    <p class="description">空欄で初期件名（【サイト名】査定結果のお知らせ）。</p>
                </td></tr>
                <tr><th>本文</th><td>
                    <textarea name="<?php echo FS_OPT; ?>[mail_body]" rows="22" style="width:100%;max-width:760px;font-family:monospace;font-size:13px"><?php echo esc_textarea(fs_opt('mail_body') ?: fs_default_mail_body()); ?></textarea>
                    <p class="description">
                        空欄にして保存すると初期文面に戻ります。使える差し込みタグ：<br>
                        <code>{site_name}</code> <code>{property_details}</code>（物件情報のまとまり） <code>{price_low}</code> <code>{price_high}</code> <code>{price_mid}</code> <code>{reason}</code> <code>{ptype}</code> <code>{pref}</code> <code>{city}</code> <code>{district}</code> <code>{area}</code> <code>{floor_plan}</code> <code>{build_year}</code> <code>{station}</code> <code>{operator_name}</code> <code>{operator_contact}</code>
                        <br><strong style="color:#b32d2e">※「鑑定評価ではない」旨の免責文は必ず残してください（法的に重要です）。</strong>
                    </p>
                </td></tr>
                <tr><th>到達確認</th><td>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fs_test_mail'), 'fs_test_mail')); ?>" class="button">テストメールを自分宛に送信</a>
                    <p class="description">
                        現在の件名・本文テンプレートでサンプルを送ります（保存してから押してください）。<br>
                        <strong>迷惑メールに入る場合</strong>は「WP Mail SMTP」等でSMTP送信にし、送信ドメインの <code>SPF</code> / <code>DKIM</code> / <code>DMARC</code> を設定してください。
                    </p>
                </td></tr>
            </table>
            </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <script>
    (function(){
        var tabs = document.querySelectorAll('#fs-tabs .nav-tab');
        var panels = document.querySelectorAll('.fs-tabpanel');
        tabs.forEach(function(t){
            t.addEventListener('click', function(e){
                e.preventDefault();
                tabs.forEach(function(x){ x.classList.remove('nav-tab-active'); });
                t.classList.add('nav-tab-active');
                var name = t.getAttribute('data-tab');
                panels.forEach(function(p){ p.style.display = (p.getAttribute('data-tab') === name) ? '' : 'none'; });
            });
        });
    })();
    </script>
<?php }

function fs_leads_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_satei_leads';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    $export = wp_nonce_url(admin_url('admin-post.php?action=fs_export_leads'), 'fs_export_leads');
    echo '<div class="wrap"><h1>査定結果・顧客情報</h1>';
    if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>削除しました。</p></div>';
    $dberr = get_option('fs_last_db_error');
    if ($dberr) echo '<div class="notice notice-error"><p><strong>直近に保存エラーが発生しました：</strong> ' . esc_html($dberr) . '<br>最新版に更新すると自動修復を試みます。次の査定で解消されない場合は、この赤いメッセージの文面を共有してください。</p></div>';
    echo '<p>登録数：' . $total . ' 件（表示は最新200件）　<a class="button button-primary" href="' . esc_url($export) . '">CSVエクスポート（Excel）</a></p>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>日時</th><th>メール</th><th>所在地</th><th>種別</th><th>面積</th><th>間取り</th><th>築年</th><th>最寄駅</th><th>レンジ(万円)</th><th>事例数</th><th>営業可</th><th>操作</th></tr></thead><tbody>';
    if ($rows) foreach ($rows as $r) {
        $st = trim((isset($r->station_name) ? $r->station_name : '') . (!empty($r->station_min) ? " 徒歩{$r->station_min}分" : ''));
        $del = wp_nonce_url(admin_url('admin-post.php?action=fs_delete_lead&id=' . $r->id), 'fs_delete_lead_' . $r->id);
        printf('<tr><td>%s</td><td>%s</td><td>%s %s %s</td><td>%s</td><td>%s㎡</td><td>%s</td><td>%s</td><td>%s</td><td>%s〜%s</td><td>%s</td><td>%s</td><td><a href="%s" onclick="return confirm(\'このリードを削除しますか？\')" style="color:#b32d2e">削除</a></td></tr>',
            esc_html($r->created_at), esc_html($r->email), esc_html($r->pref), esc_html($r->city),
            esc_html((isset($r->district) && $r->district !== '') ? $r->district : ''),
            esc_html($r->ptype), esc_html($r->area), esc_html((isset($r->floor_plan) && $r->floor_plan !== '') ? $r->floor_plan : '-'), esc_html($r->build_year ?: '-'),
            esc_html($st !== '' ? $st : '-'),
            $r->low ? number_format($r->low/10000) : '-', $r->high ? number_format($r->high/10000) : '-',
            esc_html($r->sample_size), $r->marketing_opt_in ? '○' : '', esc_url($del));
    } else echo '<tr><td colspan="12">まだありません</td></tr>';
    echo '</tbody></table></div>';
}

/* CSVエクスポート（Excel向けShift_JIS） */
add_action('admin_post_fs_export_leads', 'fs_export_leads');
function fs_export_leads() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fs_export_leads');
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fudosan_satei_leads ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="satei_leads.csv"');
    $out = fopen('php://output', 'w');
    $head = array('ID','日時','メール','都道府県','市区町村','地区','種別','面積','間取り','築年','最寄駅','徒歩分','下限(円)','中央(円)','上限(円)','事例数','営業同意');
    $cols = array('id','created_at','email','pref','city','district','ptype','area','floor_plan','build_year','station_name','station_min','low','mid','high','sample_size','marketing_opt_in');
    $sjis = function ($s) { return mb_convert_encoding((string)$s, 'SJIS-win', 'UTF-8'); };
    fputcsv($out, array_map($sjis, $head));
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) $line[] = $sjis(isset($r[$c]) ? $r[$c] : '');
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* リード削除（個人情報の削除依頼対応） */
add_action('admin_post_fs_delete_lead', 'fs_delete_lead');
function fs_delete_lead() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id = intval($_GET['id'] ?? 0);
    check_admin_referer('fs_delete_lead_' . $id);
    if ($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'fudosan_satei_leads', array('id' => $id));
    }
    wp_safe_redirect(admin_url('admin.php?page=fudosan-satei-leads&deleted=1'));
    exit;
}

/* =========================================================================
 * 3. 都道府県・市区町村（国交省XIT002より生成した全国マスタ includes/jp-cities.php）
 * ======================================================================= */
function fs_jp_data() {
    static $d = null;
    if ($d === null) {
        $d = @include __DIR__ . '/includes/jp-cities.php';
        if (!is_array($d) || empty($d['prefs'])) $d = array('prefs' => array(), 'cities' => array());
    }
    return $d;
}
function fs_prefs()  { return fs_jp_data()['prefs']; }
function fs_cities() { return fs_jp_data()['cities']; }
function fs_city_name($pref, $code) {
    foreach (fs_cities()[$pref] ?? array() as $c) if ($c[0] === $code) return $c[1];
    return $code;
}

/* =========================================================================
 * 4. 査定エンジン（satei.py 移植）
 * ======================================================================= */
$GLOBALS['FS_PTYPE_MAP']   = array('mansion' => '中古マンション等', 'house' => '宅地(土地と建物)', 'land' => '宅地(土地)');
$GLOBALS['FS_PTYPE_LABEL'] = array('mansion' => '中古マンション', 'house' => '中古一戸建て（土地＋建物）', 'land' => '土地');
$GLOBALS['FS_WAREKI']      = array('令和' => 2018, '平成' => 1988, '昭和' => 1925);

function fs_wareki_to_year($s) {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('/(令和|平成|昭和)\s*(元|\d+)年?/u', $s, $m)) {
        $n = ($m[2] === '元') ? 1 : intval($m[2]);
        return $GLOBALS['FS_WAREKI'][$m[1]] + $n;
    }
    if (preg_match('/(\d{4})/', $s, $m)) return intval($m[1]);
    return null;
}

function fs_to_int($s) {
    if ($s === null || $s === '') return null;
    if (preg_match('/\d+/', str_replace(',', '', (string)$s), $m)) return intval($m[0]);
    return null;
}

function fs_unit_price($rec) {
    $up = fs_to_int($rec['UnitPrice'] ?? '');
    if ($up) return floatval($up);
    $price = fs_to_int($rec['TradePrice'] ?? '');
    $area  = fs_to_int($rec['Area'] ?? '');
    if ($price && $area && $area > 0) return $price / $area;
    return null;
}

function fs_percentile($sorted, $p) { // $sorted 昇順, $p:0..1（線形補間）
    $n = count($sorted);
    if ($n === 0) return 0;
    if ($n === 1) return $sorted[0];
    $rank = $p * ($n - 1);
    $lo = (int)floor($rank); $hi = (int)ceil($rank);
    if ($lo === $hi) return $sorted[$lo];
    return $sorted[$lo] + ($rank - $lo) * ($sorted[$hi] - $sorted[$lo]);
}

function fs_estimate($records, $ptype, $area, $year, $district = '') {
    $type = $GLOBALS['FS_PTYPE_MAP'][$ptype] ?? '';
    $same = array_values(array_filter($records, function ($r) use ($type) {
        return ($r['Type'] ?? '') === $type;
    }));

    $filters = array();

    // ⓪ 地区（町名）フィルタ: 指定があり十分な件数があれば同じ地区に絞る（最も効く）
    if ($district !== '') {
        $dpool = array_values(array_filter($same, function ($r) use ($district) {
            return trim($r['DistrictName'] ?? '') === $district;
        }));
        if (count($dpool) >= 5) {
            $same = $dpool;
            $filters[] = sprintf('地区「%s」の事例', $district);
        }
    }

    // ① 面積帯フィルタ（対象±30%→±50%、件数を確保できる範囲で）
    $pool = $same;
    foreach (array(0.3, 0.5) as $frac) {
        $lo = $area * (1 - $frac); $hi = $area * (1 + $frac);
        $band = array_values(array_filter($same, function ($r) use ($lo, $hi) {
            $a = fs_to_int($r['Area'] ?? '');
            return $a !== null && $a >= $lo && $a <= $hi;
        }));
        if (count($band) >= 5) {
            $pool = $band;
            $filters[] = sprintf('面積が近い事例（%d〜%d㎡）', (int)$lo, (int)$hi);
            break;
        }
    }

    // ② 築年フィルタ（マンション・戸建のみ、±10→±20）
    if ($year && in_array($ptype, array('mansion', 'house'), true)) {
        foreach (array(10, 20) as $span) {
            $near = array_values(array_filter($pool, function ($r) use ($year, $span) {
                $y = fs_wareki_to_year($r['BuildingYear'] ?? '');
                return $y !== null && abs($y - $year) <= $span;
            }));
            if (count($near) >= 5) {
                $pool = $near;
                $filters[] = sprintf('築年が対象（%d年）±%d年の事例', $year, $span);
                break;
            }
        }
    }

    $units = array();
    foreach ($pool as $r) {
        $u = fs_unit_price($r);
        if ($u !== null && $u > 0) $units[] = $u;
    }

    if (count($units) < 5) {
        return array('ok' => false, 'sample_size' => count($units),
            'reason' => 'この地域・条件に近い取引事例が不足しているため、自動査定ができませんでした。個別査定をご利用ください。');
    }

    sort($units);
    $p25 = fs_percentile($units, 0.25);
    $med = fs_percentile($units, 0.5);
    $p75 = fs_percentile($units, 0.75);

    $reason = sprintf('周辺の%s成約事例のうち、条件の近い %d件の㎡単価をもとに、四分位（25%%〜75%%）でレンジを算出しました。採用した㎡単価の中央値は約 %s 円/㎡です。',
        $GLOBALS['FS_PTYPE_LABEL'][$ptype], count($units), number_format((int)$med));
    if ($filters) $reason .= '（絞り込み: ' . implode(' ／ ', $filters) . '）';

    return array(
        'ok' => true,
        'low' => (int)($p25 * $area), 'mid' => (int)($med * $area), 'high' => (int)($p75 * $area),
        'unit_mid' => (int)$med, 'sample_size' => count($units), 'reason' => $reason,
    );
}

function fs_yen_man($v) { return number_format($v / 10000) . '万円'; }

/* =========================================================================
 * 5. API取得（reinfolib）＋モックフォールバック
 * ======================================================================= */
function fs_use_mock() {
    return fs_opt('use_mock') === '1' || fs_opt('api_key') === '';
}

function fs_fetch_records($city, $year, $quarter) {
    if (fs_use_mock()) return fs_mock_records($city);
    $url = add_query_arg(array('year' => $year, 'quarter' => $quarter, 'city' => $city), FS_ENDPOINT);
    $res = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array('Ocp-Apim-Subscription-Key' => fs_opt('api_key')),
    ));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return array();
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return (is_array($body) && isset($body['data']) && is_array($body['data'])) ? $body['data'] : array();
}

function fs_fetch_recent($city, $latest_year, $quarters_back = 8) {
    $ck = 'fs_recs_' . $city . '_' . $latest_year . '_' . $quarters_back;
    $cached = get_transient($ck);
    if (is_array($cached)) return $cached;
    $recs = array(); $y = $latest_year; $q = 4;
    for ($i = 0; $i < $quarters_back; $i++) {
        $recs = array_merge($recs, fs_fetch_records($city, $y, $q));
        if (--$q === 0) { $q = 4; $y--; }
    }
    set_transient($ck, $recs, 12 * HOUR_IN_SECONDS);
    return $recs;
}

/* 市区町村内の地区名（町名）を、取引件数の多い順に返す: array(array(name, count), ...) */
function fs_districts($city) {
    $recs = fs_fetch_recent($city, intval(date('Y')) - 1, 8);
    $counts = array();
    foreach ($recs as $r) {
        $d = trim($r['DistrictName'] ?? '');
        if ($d !== '') $counts[$d] = (isset($counts[$d]) ? $counts[$d] : 0) + 1;
    }
    arsort($counts);
    $out = array();
    foreach ($counts as $name => $c) $out[] = array($name, $c);
    return $out;
}

add_action('wp_ajax_fudosan_satei_districts', 'fs_ajax_districts');
add_action('wp_ajax_nopriv_fudosan_satei_districts', 'fs_ajax_districts');
function fs_ajax_districts() {
    $city = sanitize_text_field($_GET['city'] ?? '');
    if (!$city) wp_send_json(array('districts' => array(), 'counts' => null));

    $recs = fs_fetch_recent($city, intval(date('Y')) - 1, 8);

    // 地区名（取引件数の多い順）
    $dc = array();
    foreach ($recs as $r) {
        $d = trim($r['DistrictName'] ?? '');
        if ($d !== '') $dc[$d] = (isset($dc[$d]) ? $dc[$d] : 0) + 1;
    }
    arsort($dc);
    $districts = array();
    foreach ($dc as $name => $c) $districts[] = array($name, $c);

    // 物件種別ごとの事例数（＝査定できるかの事前判定に使う）
    $counts = array('mansion' => 0, 'house' => 0, 'land' => 0);
    foreach ($recs as $r) {
        $t = $r['Type'] ?? '';
        foreach ($GLOBALS['FS_PTYPE_MAP'] as $key => $val) {
            if ($t === $val) { $counts[$key]++; break; }
        }
    }
    wp_send_json(array('districts' => $districts, 'counts' => $counts));
}

function fs_mock_records($city) {
    $seed = ctype_digit(substr($city, -1)) ? intval(substr($city, -1)) : 3;
    $base = 700000 + $seed * 40000;
    $recs = array();
    $man = array(array(70,'令和3年',1.05),array(75,'平成28年',0.98),array(65,'平成22年',0.90),array(80,'令和5年',1.10),
                 array(72,'平成18年',0.85),array(68,'平成30年',1.00),array(85,'令和1年',1.02),array(60,'平成15年',0.80),
                 array(70,'平成27年',0.95),array(78,'令和2年',1.08));
    $dists = array('中央町', '南町', '北町');
    $i = 0;
    foreach ($man as $m) {
        $unit = (int)($base * $m[2]);
        $recs[] = array('Type'=>'中古マンション等','TradePrice'=>(string)((int)($m[0]*$unit)),'UnitPrice'=>'','Area'=>(string)$m[0],'BuildingYear'=>$m[1],'FloorPlan'=>'3LDK','Structure'=>'ＲＣ','DistrictName'=>$dists[$i++ % 3]);
    }
    foreach (array(array(110,'令和2年',42000000),array(130,'平成27年',38000000),array(100,'平成20年',33000000),array(150,'令和4年',52000000),array(120,'平成25年',40000000),array(105,'令和1年',36000000)) as $h) {
        $recs[] = array('Type'=>'宅地(土地と建物)','TradePrice'=>(string)$h[2],'UnitPrice'=>'','Area'=>(string)$h[0],'BuildingYear'=>$h[1],'Structure'=>'木造','DistrictName'=>$dists[$i++ % 3]);
    }
    foreach (array(array(120,28000000),array(140,31000000),array(100,24000000),array(165,37000000),array(110,26000000),array(130,30000000)) as $l) {
        $recs[] = array('Type'=>'宅地(土地)','TradePrice'=>(string)$l[1],'UnitPrice'=>'','Area'=>(string)$l[0],'BuildingYear'=>'','DistrictName'=>$dists[$i++ % 3]);
    }
    return $recs;
}

/* =========================================================================
 * 6. メール本文
 * ======================================================================= */
function fs_mail_body($ctx) {
    $tmpl = fs_opt('mail_body', '');
    if (trim($tmpl) === '') $tmpl = fs_default_mail_body();

    // 物件情報のまとまり（入力があるものだけ行を出す）
    $pd = array();
    $pd[] = "■ 物件種別 : {$ctx['ptype_label']}";
    $loc = trim($ctx['pref'] . ' ' . $ctx['city'] . (!empty($ctx['district']) ? ' ' . $ctx['district'] : ''));
    $pd[] = "■ 所在地   : {$loc}";
    $pd[] = "■ 面積     : {$ctx['area']} ㎡";
    if (!empty($ctx['floor_plan'])) $pd[] = "■ 間取り   : {$ctx['floor_plan']}";
    if (!empty($ctx['build_year'])) $pd[] = "■ 築年     : {$ctx['build_year']}年";
    $st = trim((!empty($ctx['station_name']) ? $ctx['station_name'] : '') . (!empty($ctx['station_min']) ? " 徒歩{$ctx['station_min']}分" : ''));
    if ($st !== '') $pd[] = "■ 最寄駅   : {$st}";

    $repl = array(
        '{site_name}'        => fs_opt('site_name', 'AI査定'),
        '{property_details}' => implode("\n", $pd),
        '{ptype}'            => $ctx['ptype_label'],
        '{pref}'             => $ctx['pref'],
        '{city}'             => $ctx['city'],
        '{district}'         => isset($ctx['district']) ? $ctx['district'] : '',
        '{area}'             => $ctx['area'],
        '{floor_plan}'       => isset($ctx['floor_plan']) ? $ctx['floor_plan'] : '',
        '{build_year}'       => isset($ctx['build_year']) ? $ctx['build_year'] : '',
        '{station}'          => $st,
        '{price_low}'        => $ctx['low_man'],
        '{price_high}'       => $ctx['high_man'],
        '{price_mid}'        => $ctx['mid_man'],
        '{reason}'           => $ctx['reason'],
        '{operator_name}'    => fs_opt('operator_name', ''),
        '{operator_contact}' => fs_opt('operator_contact', ''),
    );
    return strtr($tmpl, $repl);
}

/* 件名テンプレ */
function fs_mail_subject() {
    $s = fs_opt('mail_subject', '');
    if (trim($s) === '') $s = '【{site_name}】査定結果のお知らせ';
    return strtr($s, array('{site_name}' => fs_opt('site_name', 'AI査定')));
}

/* テストメール送信（迷惑メール判定・文面の確認用） */
add_action('admin_post_fs_test_mail', 'fs_test_mail');
function fs_test_mail() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fs_test_mail');
    $to = wp_get_current_user()->user_email;
    $ctx = array(
        'ptype_label' => '中古マンション', 'pref' => '東京都', 'city' => '渋谷区', 'district' => '恵比寿',
        'area' => 70, 'floor_plan' => '2LDK', 'build_year' => 2015,
        'station_name' => '恵比寿駅', 'station_min' => 5,
        'low_man' => '6,000万円', 'mid_man' => '6,500万円', 'high_man' => '7,000万円',
        'reason' => '（テスト送信です。実際にはここに査定根拠が入ります）',
    );
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $from = fs_opt('from_email'); $site = fs_opt('site_name', 'AI査定');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $ok = wp_mail($to, '[テスト] ' . fs_mail_subject(), fs_mail_body($ctx), $headers);
    wp_safe_redirect(admin_url('admin.php?page=fudosan-satei&testmail=' . ($ok ? '1' : '0') . '&to=' . rawurlencode($to)));
    exit;
}

/* =========================================================================
 * 7. AJAX（admin-ajax 経由。REST無効化環境でも動く）
 * ======================================================================= */
add_action('wp_ajax_fudosan_satei', 'fs_ajax');
add_action('wp_ajax_nopriv_fudosan_satei', 'fs_ajax');
function fs_ajax() {
    check_ajax_referer('fudosan_satei', 'nonce');

    $ptype = sanitize_text_field($_POST['ptype'] ?? '');
    $pref  = sanitize_text_field($_POST['pref_code'] ?? '');
    $city  = sanitize_text_field($_POST['city_code'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $area  = floatval($_POST['area'] ?? 0);
    $byear = ($_POST['build_year'] ?? '') !== '' ? intval($_POST['build_year']) : null;
    $smin  = ($_POST['station_min'] ?? '') !== '' ? intval($_POST['station_min']) : null;
    $sname = sanitize_text_field($_POST['station_name'] ?? '');
    $fplan = sanitize_text_field($_POST['floor_plan'] ?? '');
    $district = sanitize_text_field($_POST['district'] ?? '');
    $agree = !empty($_POST['agree']);
    $mkt   = !empty($_POST['marketing']);

    $errors = array();
    if (!$agree) $errors[] = '個人情報の取扱いへの同意が必要です。';
    if (!is_email($email)) $errors[] = 'メールアドレスの形式が正しくありません。';
    if (!isset(fs_prefs()[$pref]) || !$city) $errors[] = '都道府県・市区町村を選択してください。';
    if (!isset($GLOBALS['FS_PTYPE_MAP'][$ptype])) $errors[] = '物件種別を選択してください。';
    if ($area <= 0 || $area > 100000) $errors[] = '面積（㎡）を正しく入力してください。';
    if ($errors) wp_send_json(array('ok' => false, 'errors' => $errors));

    $records = fs_fetch_recent($city, intval(date('Y')) - 1, 8);
    $res = fs_estimate($records, $ptype, $area, $byear, $district);

    $pref_name = fs_prefs()[$pref];
    $city_name = fs_city_name($pref, $city);

    // リード保存（事例不足でも保存＝営業価値）
    global $wpdb;
    $ins = $wpdb->insert($wpdb->prefix . 'fudosan_satei_leads', array(
        'created_at' => current_time('mysql'), 'email' => $email,
        'pref' => $pref_name, 'city' => $city_name, 'ptype' => $ptype,
        'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
        'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district,
        'low' => $res['low'] ?? null, 'mid' => $res['mid'] ?? null, 'high' => $res['high'] ?? null,
        'sample_size' => $res['sample_size'] ?? 0, 'marketing_opt_in' => $mkt ? 1 : 0,
    ));
    if ($ins === false) {
        // 保存失敗を記録し、カラム不足なら自己修復して1回だけ再試行
        update_option('fs_last_db_error', $wpdb->last_error . ' @ ' . current_time('mysql'));
        fs_ensure_columns();
        $wpdb->insert($wpdb->prefix . 'fudosan_satei_leads', array(
            'created_at' => current_time('mysql'), 'email' => $email,
            'pref' => $pref_name, 'city' => $city_name, 'ptype' => $ptype,
            'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
            'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district,
            'low' => $res['low'] ?? null, 'mid' => $res['mid'] ?? null, 'high' => $res['high'] ?? null,
            'sample_size' => $res['sample_size'] ?? 0, 'marketing_opt_in' => $mkt ? 1 : 0,
        ));
    } else {
        delete_option('fs_last_db_error');
    }

    $label = $GLOBALS['FS_PTYPE_LABEL'][$ptype];

    if (!$res['ok']) {
        wp_send_json(array('ok' => false, 'insufficient' => true, 'reason' => $res['reason'], 'email' => $email));
    }

    $ctx = array(
        'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name, 'area' => $area, 'build_year' => $byear,
        'district' => $district, 'station_name' => $sname, 'station_min' => $smin, 'floor_plan' => $fplan,
        'low_man' => fs_yen_man($res['low']), 'mid_man' => fs_yen_man($res['mid']), 'high_man' => fs_yen_man($res['high']),
        'reason' => $res['reason'],
    );
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $from = fs_opt('from_email'); $site = fs_opt('site_name', 'AI査定');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $mail_ok = wp_mail($email, fs_mail_subject(), fs_mail_body($ctx), $headers);

    wp_send_json(array(
        'ok' => true, 'mail_ok' => (bool)$mail_ok, 'email' => $email,
        'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name,
        'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
        'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district,
        'low_man' => $ctx['low_man'], 'mid_man' => $ctx['mid_man'], 'high_man' => $ctx['high_man'],
        'sample_size' => $res['sample_size'], 'reason' => $res['reason'],
    ));
}

/* =========================================================================
 * 8. ショートコード [fudosan_satei]
 * ======================================================================= */
add_shortcode('fudosan_satei', 'fs_shortcode');
/**
 * デザインパターン:
 *   [fudosan_satei]                  標準（全項目・幅100%・枠なし）
 *   [fudosan_satei design="compact"] コンパクト（必須＋築年のみ・カード・幅440px。メインビジュアル横向け）
 *   [fudosan_satei design="card"]    全項目をカード（枠＋影）で表示
 */
function fs_shortcode($atts = array()) {
    $a = shortcode_atts(array(
        'design' => 'default', 'url' => '', 'button' => '', 'title' => '', 'subtitle' => '',
    ), $atts, 'fudosan_satei');
    $design = in_array($a['design'], array('default', 'compact', 'card', 'teaser'), true) ? $a['design'] : 'default';
    $compact = ($design === 'compact');
    $teaser  = ($design === 'teaser');   // ステップ1: 3項目だけ入力して本フォームへ引き継ぐ
    $target  = esc_url($a['url']);
    $btn     = $a['button'] !== '' ? sanitize_text_field($a['button'])
                                   : ($teaser ? '査定をする' : '無料で査定結果を受け取る');
    $t_title = $a['title'] !== ''    ? sanitize_text_field($a['title'])    : '60秒でかんたん入力！';
    $t_sub   = $a['subtitle'] !== '' ? sanitize_text_field($a['subtitle']) : '査定結果はメールでお届けします';

    // compact/teaser は入力を最小限に（compactでは築年は精度のため残す）
    $show_district   = fs_show('district')   && !$compact && !$teaser;
    $show_station    = fs_show('station')    && !$compact && !$teaser;
    $show_floor_plan = fs_show('floor_plan') && !$compact && !$teaser;
    $show_build_year = fs_show('build_year') && !$teaser;
    $show_marketing  = fs_show('marketing')  && !$compact && !$teaser;

    $prefs  = fs_area_prefs();
    $cities = fs_cities();
    $nonce  = wp_create_nonce('fudosan_satei');

    // ステップ1から引き継いだ値（?fs_ptype=&fs_pref=&fs_city=）を検証して初期選択に使う
    $prefill = array(
        'ptype' => isset($_GET['fs_ptype']) ? sanitize_text_field(wp_unslash($_GET['fs_ptype'])) : '',
        'pref'  => isset($_GET['fs_pref'])  ? sanitize_text_field(wp_unslash($_GET['fs_pref']))  : '',
        'city'  => isset($_GET['fs_city'])  ? sanitize_text_field(wp_unslash($_GET['fs_city']))  : '',
    );
    if (!isset($GLOBALS['FS_PTYPE_MAP'][$prefill['ptype']])) $prefill['ptype'] = '';
    if (!isset($prefs[$prefill['pref']])) { $prefill['pref'] = ''; $prefill['city'] = ''; }
    $ajax   = admin_url('admin-ajax.php');
    $year   = intval(date('Y'));
    $privacy = fs_opt('privacy_url');
    $terms   = fs_opt('terms_url');

    // teaser は選択肢の先頭を項目名（プレースホルダ）にする
    $pref_options = '<option value="">' . ($teaser ? '都道府県' : '選択') . '</option>';
    foreach ($prefs as $code => $name) $pref_options .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';

    $ptype_options = '<option value="">' . ($teaser ? '物件種別' : '選択してください') . '</option>'
        . '<option value="mansion">中古マンション</option>'
        . '<option value="house">中古一戸建て（土地＋建物）</option>'
        . '<option value="land">土地</option>';

    $agree_label = 'プライバシーポリシーおよび免責事項に同意します（必須）';
    if ($privacy || $terms) {
        $p = $privacy ? '<a href="' . esc_url($privacy) . '" target="_blank" rel="noopener">プライバシーポリシー</a>' : 'プライバシーポリシー';
        $t = $terms ? '<a href="' . esc_url($terms) . '" target="_blank" rel="noopener">免責事項</a>' : '免責事項';
        $agree_label = $p . 'および' . $t . 'に同意します（必須）';
    }

    $cities_json = wp_json_encode($cities, JSON_UNESCAPED_UNICODE);
    $uid = 'fs-' . uniqid();

    ob_start(); ?>
<div class="fs-wrap fs-design-<?php echo esc_attr($design); ?>" id="<?php echo esc_attr($uid); ?>">
  <style>
    .fs-wrap{--fs-brand:#1f6feb;--fs-ink:#1a1f36;--fs-muted:#6b7280;--fs-line:#e5e7eb;width:100%;max-width:none;margin:0;color:var(--fs-ink);font-family:inherit;line-height:1.75;font-size:17px}
    .fs-card{background:transparent;border:0;border-radius:0;padding:0}
    .fs-wrap label{display:block;font-weight:600;margin:18px 0 7px;font-size:19px}
    .fs-req{color:#c0392b;font-size:14px;margin-left:4px}
    .fs-wrap input,.fs-wrap select{width:100%;padding:14px 15px;border:1px solid #cbd5e1;border-radius:9px;font-size:18px;background:#fff;box-sizing:border-box}
    .fs-row{display:flex;gap:12px}.fs-row>div{flex:1}
    .fs-hint{color:var(--fs-muted);font-size:15px;margin-top:5px;line-height:1.7}
    .fs-check{display:flex;gap:9px;align-items:flex-start;margin-top:14px}
    .fs-check input{width:auto;margin-top:6px;transform:scale(1.2)}.fs-check label{margin:0;font-weight:400;font-size:16px}
    .fs-wrap button{margin-top:24px;width:100%;background:var(--fs-brand);color:#fff;border:0;border-radius:10px;padding:18px;font-size:20px;font-weight:700;cursor:pointer}
    .fs-wrap button:disabled{opacity:.6;cursor:wait}
    .fs-disc{background:#fff8e6;border:1px solid #f0e0a8;border-radius:10px;padding:15px 17px;font-size:14px;color:#6b5a12;margin-top:18px}
    .fs-err{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;padding:10px 12px;border-radius:9px;margin-bottom:10px;font-size:16px}
    .fs-price{font-size:34px;font-weight:800;color:var(--fs-brand);text-align:center;margin:8px 0}
    .fs-mid{text-align:center;color:var(--fs-muted);font-size:16px}
    .fs-spec{width:100%;border-collapse:collapse;margin:16px 0;font-size:17px}
    .fs-spec th,.fs-spec td{border-bottom:1px solid var(--fs-line);padding:12px 10px;text-align:left}
    .fs-spec th{color:var(--fs-muted);font-weight:600;width:38%}
    .fs-ok{color:#0a7d33;font-weight:600;font-size:16px}
    .fs-coverage{color:var(--fs-muted);font-size:14px;line-height:1.6;margin-top:8px}

    /* デザイン: compact / teaser（メインビジュアル横などに収める短い版） */
    .fs-design-compact,.fs-design-teaser{max-width:440px}
    .fs-design-compact .fs-card,.fs-design-teaser .fs-card{background:#fff;border:1px solid var(--fs-line);border-radius:14px;padding:20px 18px;box-shadow:0 8px 28px rgba(16,24,40,.10)}
    .fs-design-compact label,.fs-design-teaser label{font-size:16px;margin:12px 0 5px}
    .fs-design-compact input,.fs-design-compact select,.fs-design-teaser input,.fs-design-teaser select{padding:11px 12px;font-size:16px}
    .fs-design-compact button,.fs-design-teaser button{margin-top:16px;padding:14px;font-size:17px}
    .fs-design-compact .fs-form .fs-hint,.fs-design-teaser .fs-form .fs-hint{display:none}
    .fs-design-compact .fs-coverage,.fs-design-teaser .fs-coverage{font-size:13px;margin-top:6px}
    .fs-design-compact .fs-check label{font-size:14px}
    .fs-design-compact .fs-disc{font-size:12px;padding:10px 12px;margin-top:12px}
    .fs-design-compact .fs-price{font-size:28px}
    .fs-design-compact .fs-spec{font-size:15px}
    .fs-design-compact .fs-spec th,.fs-design-compact .fs-spec td{padding:9px 8px}
    .fs-design-teaser .fs-note{color:var(--fs-muted);font-size:12px;margin-top:10px;line-height:1.6}

    /* teaser: ヘッダー */
    .fs-design-teaser .fs-card{padding:22px 20px}
    .fs-teaser-head{text-align:center;padding-bottom:14px;margin-bottom:6px;border-bottom:1px solid var(--fs-line)}
    .fs-teaser-title{font-size:19px;font-weight:800;color:var(--fs-brand);line-height:1.4}
    .fs-teaser-sub{font-size:13px;color:var(--fs-muted);margin-top:4px}

    /* teaser: ラベル横並び＋必須バッジ */
    .fs-design-teaser .fs-trow{display:flex;align-items:center;gap:10px;margin:14px 0}
    .fs-design-teaser .fs-tlabel{flex:0 0 auto;width:112px;display:flex;align-items:center;gap:6px;font-weight:700;font-size:15px}
    .fs-design-teaser .fs-tfield{flex:1;min-width:0}
    .fs-design-teaser .fs-tfield select{margin:0}
    .fs-badge{background:#ff5a36;color:#fff;font-size:11px;font-weight:700;border-radius:4px;padding:3px 6px;line-height:1;flex:0 0 auto}
    .fs-badge.fs-done{background:var(--fs-brand);border-radius:50%;width:21px;height:21px;padding:0;font-size:12px;display:inline-flex;align-items:center;justify-content:center}

    /* teaser: 次に入力すべき欄をハイライト（ちかちか） */
    .fs-design-teaser select.fs-next{border-color:#7fb2ff;animation:fsPulse 1.5s ease-in-out infinite}
    @keyframes fsPulse{
      0%,100%{box-shadow:0 0 0 3px rgba(31,111,235,.16)}
      50%{box-shadow:0 0 0 7px rgba(31,111,235,.28)}
    }
    @media (prefers-reduced-motion:reduce){
      .fs-design-teaser select.fs-next{animation:none;box-shadow:0 0 0 3px rgba(31,111,235,.20)}
    }

    /* デザイン: card（全項目を枠＋影のカードで） */
    .fs-design-card .fs-card{background:#fff;border:1px solid var(--fs-line);border-radius:14px;padding:24px 22px;box-shadow:0 4px 18px rgba(16,24,40,.06)}
  </style>

  <div class="fs-card fs-form-card" id="fs-form-card">
    <div class="fs-errors" id="fs-errors"></div>
<?php if ($teaser): ?>
    <div class="fs-teaser-head">
      <div class="fs-teaser-title"><?php echo esc_html($t_title); ?></div>
<?php if ($t_sub !== ''): ?>
      <div class="fs-teaser-sub"><?php echo esc_html($t_sub); ?></div>
<?php endif; ?>
    </div>
    <form class="fs-form" id="fs-form">
      <div class="fs-trow">
        <div class="fs-tlabel">物件種別<span class="fs-badge">必須</span></div>
        <div class="fs-tfield"><select name="ptype" required><?php echo $ptype_options; ?></select></div>
      </div>
      <div class="fs-trow">
        <div class="fs-tlabel">都道府県<span class="fs-badge">必須</span></div>
        <div class="fs-tfield"><select class="fs-pref" name="pref_code" id="fs-pref" required><?php echo $pref_options; ?></select></div>
      </div>
      <div class="fs-trow">
        <div class="fs-tlabel">市区町村<span class="fs-badge">必須</span></div>
        <div class="fs-tfield"><select class="fs-city" name="city_code" id="fs-city" required><option value="">市区町村</option></select></div>
      </div>

      <div class="fs-coverage"></div>
<?php else: ?>
    <form class="fs-form" id="fs-form">
      <label>物件種別<span class="fs-req">必須</span></label>
      <select name="ptype" required><?php echo $ptype_options; ?></select>

      <div class="fs-row">
        <div>
          <label>都道府県<span class="fs-req">必須</span></label>
          <select class="fs-pref" name="pref_code" id="fs-pref" required><?php echo $pref_options; ?></select>
        </div>
        <div>
          <label>市区町村<span class="fs-req">必須</span></label>
          <select class="fs-city" name="city_code" id="fs-city" required><option value="">先に都道府県を選択</option></select>
        </div>
      </div>

      <div class="fs-coverage"></div>
<?php endif; ?>

<?php if ($show_district): ?>
      <label>地区（町名）<span class="fs-hint" style="font-weight:400">任意・選ぶと査定精度が上がります</span></label>
      <select class="fs-district" name="district"><option value="">市区町村を選ぶと表示されます</option></select>
<?php endif; ?>

<?php if (!$teaser): ?>
      <div class="fs-row">
        <div>
          <label>面積（㎡）<span class="fs-req">必須</span></label>
          <input type="number" name="area" step="0.01" min="1" placeholder="例：70" required>
          <div class="fs-hint">マンション・戸建は専有/延床、土地は敷地面積</div>
        </div>
<?php if ($show_build_year): ?>
        <div>
          <label>築年（西暦）</label>
          <input type="number" name="build_year" min="1950" max="<?php echo $year; ?>" placeholder="例：2015">
          <div class="fs-hint">土地の場合は不要</div>
        </div>
<?php endif; ?>
      </div>
<?php endif; ?>

<?php if ($show_station): ?>
      <div class="fs-row">
        <div>
          <label>最寄駅<span class="fs-hint" style="font-weight:400">任意</span></label>
          <input type="text" name="station_name" placeholder="例：渋谷駅">
        </div>
        <div>
          <label>駅まで徒歩（分）<span class="fs-hint" style="font-weight:400">任意</span></label>
          <input type="number" name="station_min" min="0" max="60" placeholder="例：8">
        </div>
      </div>
<?php endif; ?>

<?php if ($show_floor_plan): ?>
      <label>間取り<span class="fs-hint" style="font-weight:400">任意</span></label>
      <select name="floor_plan">
        <option value="">選択しない</option>
        <option>1R</option><option>1K</option><option>1DK</option><option>1LDK</option>
        <option>2K</option><option>2DK</option><option>2LDK</option>
        <option>3K</option><option>3DK</option><option>3LDK</option>
        <option>4LDK以上</option>
      </select>
<?php endif; ?>

<?php if (!$teaser): ?>
      <label>結果をお届けするメールアドレス<span class="fs-req">必須</span></label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <div class="fs-check">
        <input type="checkbox" name="agree" id="fs-agree" value="1" required>
        <label for="fs-agree"><?php echo $agree_label; ?></label>
      </div>
<?php endif; ?>
<?php if ($show_marketing): ?>
      <div class="fs-check">
        <input type="checkbox" name="marketing" id="fs-mkt" value="1">
        <label for="fs-mkt">売却に関するご提案・お役立ち情報のメール受け取りを希望します（任意）</label>
      </div>
<?php endif; ?>

      <button class="fs-submit" type="submit" id="fs-submit"><?php echo esc_html($btn); ?></button>
    </form>

<?php if ($teaser): ?>
    <div class="fs-note">次の画面で面積・築年などを入力すると、参考価格をメールでお届けします。<br>※AIによる参考価格（価格査定）で、不動産鑑定士による鑑定評価ではありません。</div>
<?php else: ?>
    <div class="fs-disc">
      本サービスの結果はAIによる簡易的な<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。実際の売却価格を保証するものではありません。
    </div>
<?php endif; ?>
  </div>

  <div class="fs-card fs-result" id="fs-result" style="display:none"></div>
</div>

<script>
(function(){
  var CITIES = <?php echo $cities_json; ?>;
  var AJAX = <?php echo wp_json_encode($ajax); ?>;
  var NONCE = <?php echo wp_json_encode($nonce); ?>;
  var WRAP_ID = <?php echo wp_json_encode($uid); ?>;
  var TEASER = <?php echo $teaser ? 'true' : 'false'; ?>;
  var TARGET = <?php echo wp_json_encode($target); ?>;
  var PREFILL = <?php echo wp_json_encode($prefill); ?>;

  function init(){
  var wrap = document.getElementById(WRAP_ID);
  if (!wrap || wrap.getAttribute('data-fs-init')) return;
  wrap.setAttribute('data-fs-init', '1');
  var pref = wrap.querySelector('.fs-pref'), city = wrap.querySelector('.fs-city');
  var district = wrap.querySelector('.fs-district');
  var form = wrap.querySelector('.fs-form'), errBox = wrap.querySelector('.fs-errors');
  var formCard = wrap.querySelector('.fs-form-card'), resultCard = wrap.querySelector('.fs-result');
  var btn = wrap.querySelector('.fs-submit');

  var ptypeSel = wrap.querySelector('select[name="ptype"]');
  var cov = wrap.querySelector('.fs-coverage');
  var TYPE_COUNTS = null;
  var TYPE_LABELS = { mansion:'中古マンション', house:'一戸建て', land:'土地' };

  // 選んだ市区町村の事例数を出し、少なければ査定前に警告する
  function renderCoverage(){
    if (!cov) return;
    if (!TYPE_COUNTS) { cov.innerHTML = ''; return; }
    var parts = [];
    for (var k in TYPE_LABELS) parts.push(TYPE_LABELS[k] + ' ' + (TYPE_COUNTS[k] || 0) + '件');
    var html = 'この地域の取引事例：' + parts.join(' ／ ');
    var t = ptypeSel ? ptypeSel.value : '';
    if (t && (TYPE_COUNTS[t] || 0) < 5) {
      html += '<br><strong style="color:#c0392b">⚠ 選択中の種別は事例が少ないため、査定できない場合があります（その場合は個別査定をご案内します）。</strong>';
    }
    cov.innerHTML = html;
  }

  // teaser: 入力済みは「必須」→青いチェックに、次に入力すべき欄を光らせる
  function updateTeaserState(){
    if (!TEASER) return;
    var fields = [ptypeSel, pref, city];
    var firstEmpty = -1;
    fields.forEach(function(el, i){
      if (!el) return;
      el.classList.remove('fs-next');
      var row = el.closest ? el.closest('.fs-trow') : null;
      var badge = row ? row.querySelector('.fs-badge') : null;
      var filled = !!el.value;
      if (badge) {
        if (filled) { badge.classList.add('fs-done'); badge.textContent = '✓'; }
        else { badge.classList.remove('fs-done'); badge.textContent = '必須'; }
      }
      if (!filled && firstEmpty < 0) firstEmpty = i;
    });
    if (firstEmpty >= 0 && fields[firstEmpty]) fields[firstEmpty].classList.add('fs-next');
  }

  pref.addEventListener('change', function(){
    var list = CITIES[pref.value] || [];
    city.innerHTML = '<option value="">' + (TEASER ? '市区町村' : '選択してください') + '</option>' +
      list.map(function(c){ return '<option value="'+c[0]+'">'+c[1]+'</option>'; }).join('');
    if (district) district.innerHTML = '<option value="">市区町村を選ぶと表示されます</option>';
    TYPE_COUNTS = null; renderCoverage(); updateTeaserState();
  });

  city.addEventListener('change', function(){
    TYPE_COUNTS = null;
    updateTeaserState();
    if (!city.value) {
      if (district) district.innerHTML = '<option value="">市区町村を選ぶと表示されます</option>';
      renderCoverage();
      return;
    }
    if (district) district.innerHTML = '<option value="">読み込み中…</option>';
    if (cov) cov.textContent = 'この地域の取引事例を確認中…';
    fetch(AJAX + '?action=fudosan_satei_districts&city=' + encodeURIComponent(city.value), { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (district) {
          district.innerHTML = '<option value="">指定なし（市区町村全体）</option>' +
            ((d && d.districts) || []).map(function(x){ return '<option value="'+esc(x[0])+'">'+esc(x[0])+'（'+x[1]+'件）</option>'; }).join('');
        }
        TYPE_COUNTS = (d && d.counts) || null;
        renderCoverage();
      })
      .catch(function(){
        if (district) district.innerHTML = '<option value="">指定なし（市区町村全体）</option>';
        if (cov) cov.textContent = '';
      });
  });

  if (ptypeSel) ptypeSel.addEventListener('change', function(){ renderCoverage(); updateTeaserState(); });

  updateTeaserState(); // 初期表示（最初の未入力欄を光らせる）

  // ステップ1（teaser）から引き継いだ値を復元し、市区町村・事例数まで自動で読み込む
  if (!TEASER && PREFILL && (PREFILL.ptype || PREFILL.pref)) {
    if (PREFILL.ptype && ptypeSel) ptypeSel.value = PREFILL.ptype;
    if (PREFILL.pref) {
      pref.value = PREFILL.pref;
      pref.dispatchEvent(new Event('change'));
      if (PREFILL.city) {
        city.value = PREFILL.city;
        city.dispatchEvent(new Event('change'));
      }
    }
  }

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  form.addEventListener('submit', function(e){
    e.preventDefault();

    // ステップ1（teaser）: 入力値をURLに載せてフル入力フォームへ引き継ぐ
    if (TEASER) {
      if (!TARGET) return;
      var p = [];
      if (ptypeSel && ptypeSel.value) p.push('fs_ptype=' + encodeURIComponent(ptypeSel.value));
      if (pref.value) p.push('fs_pref=' + encodeURIComponent(pref.value));
      if (city.value) p.push('fs_city=' + encodeURIComponent(city.value));
      window.location.href = TARGET + (TARGET.indexOf('?') >= 0 ? '&' : '?') + p.join('&');
      return;
    }

    errBox.innerHTML = '';
    btn.disabled = true; btn.textContent = '査定中…';

    var fd = new FormData(form);
    fd.append('action', 'fudosan_satei');
    fd.append('nonce', NONCE);

    fetch(AJAX, { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false; btn.textContent = '無料で査定結果を受け取る';
        if (d.errors) { errBox.innerHTML = d.errors.map(function(x){return '<div class="fs-err">'+esc(x)+'</div>';}).join(''); return; }
        renderResult(d);
      })
      .catch(function(){
        btn.disabled = false; btn.textContent = '無料で査定結果を受け取る';
        errBox.innerHTML = '<div class="fs-err">通信エラーが発生しました。時間をおいて再度お試しください。</div>';
      });
  });

  function renderResult(d){
    var disc = '<div class="fs-disc">本結果はAIによる簡易的な<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。過去の周辺取引事例からの機械的な推定値で、実際の売却価格・成約価格を保証するものではありません。正確な価格には現地確認を含む個別査定が必要です。</div>';
    var html;
    if (d.ok) {
      var st = (d.station_name ? esc(d.station_name) : '') + (d.station_min ? ' 徒歩'+esc(d.station_min)+'分' : '');
      var rows = '<tr><th>物件種別</th><td>'+esc(d.ptype_label)+'</td></tr>'
        + '<tr><th>所在地</th><td>'+esc(d.pref)+' '+esc(d.city)+(d.district?' '+esc(d.district):'')+'</td></tr>'
        + '<tr><th>面積</th><td>'+esc(d.area)+' ㎡</td></tr>'
        + (d.floor_plan ? '<tr><th>間取り</th><td>'+esc(d.floor_plan)+'</td></tr>' : '')
        + (d.build_year ? '<tr><th>築年</th><td>'+esc(d.build_year)+'年</td></tr>' : '')
        + (st.trim() ? '<tr><th>最寄駅</th><td>'+st+'</td></tr>' : '');
      html = '<h3 style="margin-top:0">査定結果</h3>'
        + '<p>ご入力の条件に基づく<strong>参考価格</strong>は以下の通りです。</p>'
        + '<div class="fs-price">'+esc(d.low_man)+' 〜 '+esc(d.high_man)+'</div>'
        + '<p class="fs-mid">中央値の目安：'+esc(d.mid_man)+' ／ 使用事例 '+esc(d.sample_size)+'件</p>'
        + '<table class="fs-spec">'+rows+'</table>'
        + '<p class="fs-hint">'+esc(d.reason)+'</p>'
        + (d.mail_ok ? '<p class="fs-ok">✓ '+esc(d.email)+' 宛に査定結果をメールで送信しました。</p>'
                     : '<p class="fs-err">メール送信に失敗しました。時間をおいて再度お試しください。</p>')
        + disc;
    } else {
      html = '<h3 style="margin-top:0">査定結果</h3><p>'+esc(d.reason)+'</p>'
        + '<p class="fs-hint">'+esc(d.email)+' 宛に受付のご連絡をお送りしました。個別査定をご希望の場合はご返信ください。</p>' + disc;
    }
    resultCard.innerHTML = html;
    formCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultCard.scrollIntoView({ behavior:'smooth', block:'start' });
  }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
<?php
    return ob_get_clean();
}
