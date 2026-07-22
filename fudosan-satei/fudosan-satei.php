<?php
/**
 * Plugin Name: かんたん不動産AI査定
 * Description: 匿名の不動産価格査定フォーム。国交省「不動産情報ライブラリ」の実成約事例から参考価格レンジを算出し、結果をメール送信＋リード保存。ショートコード [fudosan_satei] をページに貼るだけ。
 * Version: 1.14.1
 * Author: (運営者)
 * License: GPLv2 or later
 * Text Domain: fudosan-satei
 *
 * ★法的注意: 本プラグインが出すのは宅建業の「価格査定（参考価格）」であり、
 *   不動産鑑定士の「鑑定評価」ではない。UI・メール・免責文で明示している。
 *   公開前に弁護士等の確認を推奨。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FS_VER', '1.14.1');
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
        purpose VARCHAR(50) NULL,
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
        'purpose'      => 'VARCHAR(50) NULL',
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

/* =========================================================================
 * 濫用対策（メール爆撃の踏み台にされると送信ドメインのレピュテーションが死ぬ）
 * ※ nonce は未ログインだと全訪問者で同一値・最大24時間有効のため、ボット対策にならない。
 * ======================================================================= */

/**
 * 送信元IP。CDN/リバースプロキシ配下では REMOTE_ADDR がプロキシのIPになり、
 * 全員が同一IP扱い＝正規の利用者まで一律ブロックしてしまうため、標準的なヘッダを優先する。
 * ヘッダは偽装可能だが、本命の防御は「メールアドレス単位の制限」なので許容する
 * （攻撃者は宛先を変えられない＝爆撃したい相手のアドレスは固定されるため）。
 */
function fs_client_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $h) {
        if (!empty($_SERVER[$h])) {
            $parts = explode(',', $_SERVER[$h]);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/* $id 単位で $limit 回/$window 秒 を超えていないか。超えたら false（超過分は数えず即拒否） */
function fs_rate_ok($bucket, $id, $limit, $window) {
    if ($id === '') return true;
    $k = 'fs_rl_' . $bucket . '_' . md5(strtolower($id));
    $n = (int) get_transient($k);
    if ($n >= $limit) return false;
    set_transient($k, $n + 1, $window);
    return true;
}

/* 上限値はフィルタで変更可能にしておく */
function fs_rl_limits() {
    return array(
        'ip_max'       => (int) apply_filters('fs_rl_ip_max', 5),                 // 同一IP: 1時間に5件
        'ip_window'    => (int) apply_filters('fs_rl_ip_window', HOUR_IN_SECONDS),
        'email_max'    => (int) apply_filters('fs_rl_email_max', 3),              // 同一メール: 24時間に3件
        'email_window' => (int) apply_filters('fs_rl_email_window', DAY_IN_SECONDS),
    );
}

/**
 * ボット判定。ハニーポット（人には見えない欄）と、表示から送信までの経過時間で弾く。
 * このフォームはJSでfetch送信するためJSは必ず動く＝fs_elapsed は必ず入る。
 * 問題があれば errors の配列を返す。
 */
function fs_bot_errors() {
    if (!empty($_POST['fs_website'])) return array('送信を受け付けられませんでした。');       // 人間は触れない欄
    $elapsed = isset($_POST['fs_elapsed']) ? intval($_POST['fs_elapsed']) : 0;
    if ($elapsed < 3000) return array('入力が早すぎます。もう一度お試しください。');           // 3秒未満＝自動送信
    return array();
}

/* APIキー未設定＝自動査定が動いていない。管理者が気づけないと機会損失になるので必ず知らせる */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (fs_opt('api_key') !== '' || fs_use_mock()) return;
    echo '<div class="notice notice-error"><p><strong>【匿名不動産AI査定】APIキーが未設定のため、自動査定を停止しています。</strong><br>'
       . 'お客様には価格を表示せず「担当者よりご連絡します」とご案内し、リードの保存と担当者への通知は継続しています。'
       . '<a href="' . esc_url(admin_url('admin.php?page=fudosan-satei')) . '">設定画面</a>で国土交通省のAPIキーをご登録ください。</p></div>';
});

/* 公開前チェック。お客様に見える信頼性の材料が抜けたまま公開されるのを防ぐ */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $miss = array();
    if (fs_opt('operator_name', '') === '')  $miss[] = '運営者名（会社名）';
    if (fs_opt('license_no', '') === '')     $miss[] = '宅地建物取引業の免許番号';
    if (fs_opt('operator_contact', '') === '') $miss[] = '問い合わせ先';
    if (fs_opt('privacy_url', '') === '')    $miss[] = 'プライバシーポリシーURL';
    if (!$miss) return;
    echo '<div class="notice notice-warning"><p><strong>【匿名不動産AI査定】公開前に未設定の項目があります：'
       . esc_html(implode(' / ', $miss)) . '</strong><br>'
       . 'これらはフォーム上でお客様に表示され、「どこの会社に自宅の情報を渡すのか」を判断する材料になります。'
       . '未設定のままだと信頼性が下がり、個人情報の利用目的の説明としても不十分です。'
       . '<a href="' . esc_url(admin_url('admin.php?page=fudosan-satei')) . '">設定画面</a>からご記入ください。</p></div>';
});

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
        'operator_address' => sanitize_text_field($in['operator_address'] ?? ''),
        'license_no'       => sanitize_text_field($in['license_no'] ?? ''),
        'from_email'       => sanitize_email($in['from_email'] ?? get_option('admin_email')),
        'notify_email'     => sanitize_email($in['notify_email'] ?? ''),
        'notify_on'        => !empty($in['notify_on']) ? '1' : '',
        'privacy_url'      => esc_url_raw($in['privacy_url'] ?? ''),
        'terms_url'        => esc_url_raw($in['terms_url'] ?? ''),
        // 表示項目（未送信=チェック外れ=非表示）
        'show_district'    => !empty($in['show_district']) ? '1' : '',
        'show_station'     => !empty($in['show_station']) ? '1' : '',
        'show_floor_plan'  => !empty($in['show_floor_plan']) ? '1' : '',
        'show_build_year'  => !empty($in['show_build_year']) ? '1' : '',
        'show_purpose'     => !empty($in['show_purpose']) ? '1' : '',
        'show_marketing'   => !empty($in['show_marketing']) ? '1' : '',
        // 対象エリア（空=全国）
        'areas'            => $areas,
        // 自動返信メール
        'mail_subject'     => sanitize_text_field($in['mail_subject'] ?? ''),
        'mail_body'        => sanitize_textarea_field($in['mail_body'] ?? ''),
        // 装飾（色）
        'color_brand'      => sanitize_hex_color($in['color_brand'] ?? '')    ?: '#1f6feb',
        'color_btn_text'   => sanitize_hex_color($in['color_btn_text'] ?? '') ?: '#ffffff',
        'color_title'      => sanitize_hex_color($in['color_title'] ?? '')    ?: '#1f6feb',
        'color_badge'      => sanitize_hex_color($in['color_badge'] ?? '')    ?: '#ff5a36',
    );
}

/* 利用目的の選択肢（リードの質を測る重要データ） */
function fs_purposes() {
    return array(
        '売却を検討している',
        '相続した・相続する予定',
        '離婚による財産分与',
        '住み替えを検討している',
        '資産価値を把握したい',
        'その他',
    );
}

/* teaser で選べる項目（fields属性で指定）。req=必須扱い */
function fs_teaser_fields() {
    return array(
        'purpose'    => array('label' => 'ご利用目的',   'name' => 'purpose',    'req' => false),
        'ptype'      => array('label' => '物件種別',     'name' => 'ptype',      'req' => true),
        'pref'       => array('label' => '都道府県',     'name' => 'pref_code',  'req' => true),
        'city'       => array('label' => '市区町村',     'name' => 'city_code',  'req' => true),
        'area'       => array('label' => '面積（㎡）',   'name' => 'area',       'req' => true),
        'build_year' => array('label' => '築年（西暦）', 'name' => 'build_year', 'req' => false),
    );
}

/* fields="ptype,pref,city" を検証済みの順序付きリストに */
function fs_parse_teaser_fields($raw) {
    $known = fs_teaser_fields();
    $out = array();
    foreach (explode(',', (string)$raw) as $k) {
        $k = trim($k);
        if ($k !== '' && isset($known[$k]) && !in_array($k, $out, true)) $out[] = $k;
    }
    if (!$out) $out = array('ptype', 'pref', 'city');
    // 市区町村は都道府県が無いと選べないので、無ければ直前に補う
    $ci = array_search('city', $out, true);
    if ($ci !== false && !in_array('pref', $out, true)) array_splice($out, $ci, 0, array('pref'));
    return $out;
}

/* #rrggbb → "r,g,b"（ブランド色を rgba() で薄く使うため） */
function fs_hex_to_rgb($hex) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '31,111,235';
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

/* 表示項目の判定（未保存＝デフォルト表示、保存済みは値そのもの。空='非表示'を区別） */
/**
 * リード通知のON/OFF。fs_opt は「空文字＝未設定＝デフォルト」と解釈するため、
 * チェックを外した状態('')を fs_opt('notify_on','1') で読むと '1' が返り、OFFにできない。
 * よって fs_show と同じく array_key_exists で判定する。
 */
function fs_notify_on() {
    $o = get_option(FS_OPT, array());
    if (!is_array($o) || !array_key_exists('notify_on', $o)) return true;   // 未設定=通知する
    return $o['notify_on'] === '1';
}

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
        <p>ページに <code>[fudosan_satei]</code> を貼ると査定フォームが表示されます。詳しい書き方は「<strong>使い方</strong>」タブへ。</p>
        <h2 class="nav-tab-wrapper" id="fs-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="basic">基本設定</a>
            <a href="#" class="nav-tab" data-tab="display">表示項目・対象エリア</a>
            <a href="#" class="nav-tab" data-tab="mail">自動返信メール</a>
            <a href="#" class="nav-tab" data-tab="style">デザイン</a>
            <a href="#" class="nav-tab" data-tab="usage">使い方</a>
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
                <tr><th>運営者名（会社名）</th><td><input type="text" name="<?php echo FS_OPT; ?>[operator_name]" value="<?php echo esc_attr(fs_opt('operator_name')); ?>" size="40" placeholder="例：ミカタ株式会社">
                    <p class="description">フォームとメールに表示されます。<strong>お客様が「どこの会社に情報を渡すのか」を判断する材料</strong>なので、必ずご記入ください。</p></td></tr>
                <tr><th>宅地建物取引業<br>免許番号</th><td><input type="text" name="<?php echo FS_OPT; ?>[license_no]" value="<?php echo esc_attr(fs_opt('license_no')); ?>" size="40" placeholder="例：岡山県知事免許（1）第○○○○号">
                    <p class="description">フォームに表示されます。不動産の査定を受け付ける以上、<strong>これが無いとお客様から見て信頼性が大きく下がります</strong>。</p></td></tr>
                <tr><th>所在地</th><td><input type="text" name="<?php echo FS_OPT; ?>[operator_address]" value="<?php echo esc_attr(fs_opt('operator_address')); ?>" size="50" placeholder="例：岡山県岡山市北区○○1-2-3"></td></tr>
                <tr><th>問い合わせ先</th><td><input type="text" name="<?php echo FS_OPT; ?>[operator_contact]" value="<?php echo esc_attr(fs_opt('operator_contact')); ?>" size="40" placeholder="例：086-000-0000 / info@example.com"></td></tr>
                <tr><th>送信元メール</th><td><input type="email" name="<?php echo FS_OPT; ?>[from_email]" value="<?php echo esc_attr(fs_opt('from_email', get_option('admin_email'))); ?>" size="40">
                    <p class="description">お客様への査定結果メールの差出人。到達率のため WP Mail SMTP 等で SPF/DKIM を設定推奨。</p></td></tr>
                <tr><th>通知先メール（担当者）</th><td><input type="email" name="<?php echo FS_OPT; ?>[notify_email]" value="<?php echo esc_attr(fs_opt('notify_email')); ?>" size="40">
                    <p class="description">査定リードが入ったら、このアドレスに通知します。空欄なら送信元メール（無ければ管理者アドレス）に通知します。<br>通知したくない場合は下の「担当者に通知する」のチェックを外してください。</p></td></tr>
                <tr><th>リード通知</th><td>
                    <label><input type="checkbox" name="<?php echo FS_OPT; ?>[notify_on]" value="1" <?php checked(fs_opt('notify_on', '1'), '1'); ?>> 査定リードが入ったら担当者に通知する</label>
                </td></tr>
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
                <label><input type="checkbox" name="<?php echo FS_OPT; ?>[show_purpose]" value="1" <?php checked(fs_show('purpose')); ?>> 利用目的（売却検討・相続・離婚など）</label><br>
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
                        <code>{site_name}</code> <code>{property_details}</code>（物件情報のまとまり） <code>{price_low}</code> <code>{price_high}</code> <code>{price_mid}</code> <code>{reason}</code> <code>{ptype}</code> <code>{pref}</code> <code>{city}</code> <code>{district}</code> <code>{area}</code> <code>{floor_plan}</code> <code>{build_year}</code> <code>{station}</code> <code>{purpose}</code> <code>{operator_name}</code> <code>{operator_contact}</code>
                    </p>
                    <p class="description" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;margin-top:10px">
                        <strong>次の2つは、本文を自由に書き換えても自動で付きます（消せません）。</strong><br>
                        ・<strong>免責文</strong>（「鑑定評価ではない」旨）— 法令上、必ず必要なためです。<br>
                        ・<strong>戸建て・土地の注意文言</strong> — 現地確認が必要な要素で価格が大きく変わるため、
                        「目安である」旨と訪問査定のご案内を、価格のすぐ下に自動で表示します（マンションには付きません）。<br>
                        本文には<strong>ご案内したい内容だけ</strong>をお書きください。
                        ご自身で同じ趣旨の文面を書かれた場合は、二重にならないよう自動付加を行いません。
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

            <div class="fs-tabpanel" data-tab="style" style="display:none">
            <h3>フォームの色</h3>
            <p class="description">保存すると、標準・compact・card・teaser すべてのフォームに反映されます。</p>
            <table class="form-table">
                <tr><th>ブランドカラー</th><td>
                    <input type="color" name="<?php echo FS_OPT; ?>[color_brand]" value="<?php echo esc_attr(fs_opt('color_brand', '#1f6feb')); ?>">
                    <p class="description">ボタンの背景、査定価格の文字、入力済みチェック（✓）、次の入力欄のハイライトに使われます。</p>
                </td></tr>
                <tr><th>ボタンの文字色</th><td>
                    <input type="color" name="<?php echo FS_OPT; ?>[color_btn_text]" value="<?php echo esc_attr(fs_opt('color_btn_text', '#ffffff')); ?>">
                </td></tr>
                <tr><th>見出しの色</th><td>
                    <input type="color" name="<?php echo FS_OPT; ?>[color_title]" value="<?php echo esc_attr(fs_opt('color_title', '#1f6feb')); ?>">
                    <p class="description">teaser の見出し（例：60秒でかんたん入力！）の文字色。</p>
                </td></tr>
                <tr><th>「必須」バッジの色</th><td>
                    <input type="color" name="<?php echo FS_OPT; ?>[color_badge]" value="<?php echo esc_attr(fs_opt('color_badge', '#ff5a36')); ?>">
                    <p class="description">未入力の項目に付くバッジ。入力すると「ブランドカラーの ✓」に変わります。</p>
                </td></tr>
            </table>
            <p class="description">初期値：ブランド <code>#1f6feb</code> ／ ボタン文字 <code>#ffffff</code> ／ 見出し <code>#1f6feb</code> ／ バッジ <code>#ff5a36</code></p>
            </div>

            <div class="fs-tabpanel" data-tab="usage" style="display:none">
            <h3>ショートコードの貼り方</h3>
            <p>固定ページや投稿、ウィジェット（カスタムHTML）に貼ります。</p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:170px">用途</th><th>ショートコード</th></tr></thead>
                <tbody>
                <tr><td><strong>標準</strong><br><span class="description">査定ページ本体</span></td>
                    <td><code>[fudosan_satei]</code><br><span class="description">全項目・幅100%・枠なし。ここで査定を実行し結果を表示＆メール送信。</span></td></tr>
                <tr><td><strong>コンパクト</strong><br><span class="description">サイドバー等</span></td>
                    <td><code>[fudosan_satei design="compact"]</code><br><span class="description">必須＋築年のみ。幅440pxのカード。ここでも査定は完結します。</span></td></tr>
                <tr><td><strong>カード</strong></td>
                    <td><code>[fudosan_satei design="card"]</code><br><span class="description">全項目を枠＋影のカードで表示。</span></td></tr>
                <tr><td><strong>ステップ入口</strong><br><span class="description">トップのメインビジュアル横</span></td>
                    <td><code>[fudosan_satei design="teaser" url="/satei/"]</code><br><span class="description"><strong>3項目だけ</strong>入力してもらい、ボタンで <code>url</code> のページへ。入力値は自動で引き継がれます。</span></td></tr>
                </tbody>
            </table>

            <h3>ステップ構成にする場合（推奨）</h3>
            <ol>
                <li>トップページのヒーロー横に<br>
                    <code>[fudosan_satei design="teaser" url="/satei/" logo="ロゴ画像URL" title="60秒でかんたん入力！" subtitle="査定結果はメールでお届け" button="無料査定スタート"]</code></li>
                <li>査定ページ（例：<code>/satei/</code>）に <code>[fudosan_satei]</code></li>
            </ol>
            <p class="description">トップで選んだ「物件種別・都道府県・市区町村」が査定ページに引き継がれ、選択済みの状態で開きます。</p>

            <h3>属性一覧</h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:120px">属性</th><th style="width:150px">対象</th><th>説明</th></tr></thead>
                <tbody>
                <tr><td><code>design</code></td><td>すべて</td><td><code>default</code>（省略時） / <code>compact</code> / <code>card</code> / <code>teaser</code></td></tr>
                <tr><td><code>url</code></td><td>teaser</td><td><strong>必須。</strong>ボタンの遷移先（査定ページのURL）</td></tr>
                <tr><td><code>button</code></td><td>すべて</td><td>ボタンの文言（省略時：teaserは「査定をする」／他は「無料で査定結果を受け取る」）</td></tr>
                <tr><td><code>title</code></td><td>teaser</td><td>見出し（省略時：60秒でかんたん入力！）</td></tr>
                <tr><td><code>subtitle</code></td><td>teaser</td><td>小見出し</td></tr>
                <tr><td><code>logo</code></td><td>teaser</td><td>ロゴ画像URL（メディアにアップしてURLを貼る）。指定するとロゴ左・見出し右の横並びに</td></tr>
                <tr><td><code>note</code></td><td>teaser</td><td>フォーム下の小さな注記（省略時は表示なし）</td></tr>
                <tr><td><code>fields</code></td><td>teaser</td><td>
                    出す項目をカンマ区切りで指定。省略時は <code>ptype,pref,city</code><br>
                    使える値：<code>purpose</code>（ご利用目的・任意） <code>ptype</code>（物件種別） <code>pref</code>（都道府県） <code>city</code>（市区町村） <code>area</code>（面積） <code>build_year</code>（築年・任意）<br>
                    <span class="description">書いた順に並びます。<code>city</code> を入れると <code>pref</code> が自動で補われます。選んだ項目は<strong>すべてフル入力フォームに引き継がれます</strong>。</span>
                </td></tr>
                </tbody>
            </table>
            <p class="description">例：<code>[fudosan_satei design="teaser" url="/satei/" fields="purpose,ptype,pref,city"]</code>（ご利用目的から聞く4項目）</p>
            <p class="description">ボタン色・見出し色などの装飾は「<strong>デザイン</strong>」タブでまとめて設定します。</p>

            <h3>そのほか</h3>
            <ul style="list-style:disc;margin-left:20px">
                <li><strong>入力項目の増減・対象エリアの限定</strong> …「表示項目・対象エリア」タブ</li>
                <li><strong>自動返信メールの文面</strong> …「自動返信メール」タブ（差し込みタグ対応・テスト送信ボタンあり）</li>
                <li><strong>集まった顧客情報</strong> … 左メニュー「匿名不動産AI査定 → 査定結果・顧客情報」（CSV書き出し／削除）</li>
                <li>市区町村を選ぶと<strong>その地域の取引事例数</strong>が表示され、少ない種別は査定前に警告します</li>
                <li>「地区（町名）」を選ぶと<strong>査定精度が上がります</strong>（同じ市区町村でも地区で相場が違うため）</li>
            </ul>

            <h3 style="color:#b32d2e">法的な注意</h3>
            <p class="description">
                本プラグインが出すのは宅建業の<strong>「価格査定（参考価格）」</strong>であり、不動産鑑定士による<strong>「鑑定評価」ではありません</strong>。
                画面・メール・免責文でその旨を明示しています。<strong>免責文は削除しないでください。</strong>公開前に弁護士等の確認を推奨します。
            </p>
            </div>

            <div id="fs-save"><?php submit_button(); ?></div>
        </form>
    </div>
    <script>
    (function(){
        var tabs = document.querySelectorAll('#fs-tabs .nav-tab');
        var panels = document.querySelectorAll('.fs-tabpanel');
        var save = document.getElementById('fs-save');
        tabs.forEach(function(t){
            t.addEventListener('click', function(e){
                e.preventDefault();
                tabs.forEach(function(x){ x.classList.remove('nav-tab-active'); });
                t.classList.add('nav-tab-active');
                var name = t.getAttribute('data-tab');
                panels.forEach(function(p){ p.style.display = (p.getAttribute('data-tab') === name) ? '' : 'none'; });
                if (save) save.style.display = (name === 'usage') ? 'none' : ''; // 使い方タブでは保存ボタンを隠す
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
    echo '<th>日時</th><th>メール</th><th>利用目的</th><th>所在地</th><th>種別</th><th>面積</th><th>間取り</th><th>築年</th><th>最寄駅</th><th>レンジ(万円)</th><th>事例数</th><th>営業可</th><th>操作</th></tr></thead><tbody>';
    if ($rows) foreach ($rows as $r) {
        $st = trim((isset($r->station_name) ? $r->station_name : '') . (!empty($r->station_min) ? " 徒歩{$r->station_min}分" : ''));
        $del = wp_nonce_url(admin_url('admin-post.php?action=fs_delete_lead&id=' . $r->id), 'fs_delete_lead_' . $r->id);
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s %s %s</td><td>%s</td><td>%s㎡</td><td>%s</td><td>%s</td><td>%s</td><td>%s〜%s</td><td>%s</td><td>%s</td><td><a href="%s" onclick="return confirm(\'このリードを削除しますか？\')" style="color:#b32d2e">削除</a></td></tr>',
            esc_html($r->created_at), esc_html($r->email),
            esc_html((isset($r->purpose) && $r->purpose !== '') ? $r->purpose : '-'),
            esc_html($r->pref), esc_html($r->city),
            esc_html((isset($r->district) && $r->district !== '') ? $r->district : ''),
            esc_html($r->ptype), esc_html($r->area), esc_html((isset($r->floor_plan) && $r->floor_plan !== '') ? $r->floor_plan : '-'), esc_html($r->build_year ?: '-'),
            esc_html($st !== '' ? $st : '-'),
            $r->low ? number_format($r->low/10000) : '-', $r->high ? number_format($r->high/10000) : '-',
            esc_html($r->sample_size), $r->marketing_opt_in ? '○' : '', esc_url($del));
    } else echo '<tr><td colspan="13">まだありません</td></tr>';
    echo '</tbody></table></div>';
}

/* CSVエクスポート（Excel向けShift_JIS） */
/**
 * CSVインジェクション対策。= + - @ 等で始まるセルは Excel が数式として実行してしまうため、
 * 先頭に ' を付けて無害な文字列にする（お客様の自由入力がそのままCSVに入るため必須）。
 * 数値（-5 等）はそのまま通す。
 */
function fs_csv_safe($s) {
    $s = (string)$s;
    if ($s === '' || is_numeric($s)) return $s;
    return (strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
}

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
    $head = array('ID','日時','メール','利用目的','都道府県','市区町村','地区','種別','面積','間取り','築年','最寄駅','徒歩分','下限(円)','中央(円)','上限(円)','事例数','営業同意');
    $cols = array('id','created_at','email','purpose','pref','city','district','ptype','area','floor_plan','build_year','station_name','station_min','low','mid','high','sample_size','marketing_opt_in');
    $sjis = function ($s) { return mb_convert_encoding((string)$s, 'SJIS-win', 'UTF-8'); };
    fputcsv($out, array_map($sjis, $head));
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) $line[] = $sjis(fs_csv_safe(isset($r[$c]) ? $r[$c] : ''));
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

/**
 * 種別ごとの妥当な面積レンジ。桁間違い等の明らかな入力ミスだけを弾くための緩い範囲で、
 * 実在する物件を拒否しないことを優先する（正確さの担保は査定側の絞り込み警告が担当）。
 * 根拠＝国交省の実取引データ（渋谷区・岡山市北区/2025年全4四半期）の Area 分布:
 *   中古マンション等   実績 10〜640㎡（岡山は最大145㎡）
 *   宅地(土地と建物)   実績 30〜1500㎡
 *   宅地(土地)         実績 30〜4800㎡（9999は打ち切り値のため除外）
 */
function fs_area_range($ptype) {
    switch ($ptype) {
        case 'mansion': return array(10, 1000);
        case 'house':   return array(20, 5000);
        case 'land':    return array(10, 10000);
    }
    return array(1, 100000);
}

function fs_to_int($s) {
    if ($s === null || $s === '') return null;
    if (preg_match('/\d+/', str_replace(',', '', (string)$s), $m)) return intval($m[0]);
    return null;
}

/**
 * 国交省データの Area は「9999」が『9999㎡以上』の打ち切り値。
 * 実面積が不明なまま 9999 で割ると㎡単価が過大になるため、この事例は採用しない。
 * （実データ確認: 面積は1500〜4800㎡まで100㎡刻みで連続し、その先が飛んで 8888/9999 に集中）
 */
define('FS_AREA_SENTINEL', 9999);

function fs_unit_price($rec) {
    // UnitPrice は面積に依存しない実測値なので、打ち切りガードより先に採用する
    // （土地は UnitPrice が常に入っており、9999㎡以上の事例でも単価自体は正しい）
    $up = fs_to_int($rec['UnitPrice'] ?? '');
    if ($up) return floatval($up);
    // TradePrice/Area で代替する場合のみ、面積の打ち切りが単価を狂わせるので除外する
    $area = fs_to_int($rec['Area'] ?? '');
    if ($area !== null && $area >= FS_AREA_SENTINEL) return null;
    $price = fs_to_int($rec['TradePrice'] ?? '');
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

    $filters  = array();
    $warnings = array();   // 絞り込めなかった条件は必ず利用者に伝える（黙って全件にフォールバックしない）

    // ⓪ 地区（町名）フィルタ: 指定があり十分な件数があれば同じ地区に絞る（最も効く）
    if ($district !== '') {
        $dpool = array_values(array_filter($same, function ($r) use ($district) {
            return trim($r['DistrictName'] ?? '') === $district;
        }));
        if (count($dpool) >= 5) {
            $same = $dpool;
            $filters[] = sprintf('地区「%s」の事例', $district);
        } else {
            $warnings[] = sprintf('地区「%s」の事例が5件未満のため、市区町村全体の事例で算出しています', $district);
        }
    }

    // ① 面積帯フィルタ（対象±30%→±50%、件数を確保できる範囲で）
    $pool = $same;
    $area_matched = false;
    foreach (array(0.3, 0.5) as $frac) {
        $lo = $area * (1 - $frac); $hi = $area * (1 + $frac);
        $band = array_values(array_filter($same, function ($r) use ($lo, $hi) {
            $a = fs_to_int($r['Area'] ?? '');
            return $a !== null && $a >= $lo && $a <= $hi;
        }));
        if (count($band) >= 5) {
            $pool = $band;
            $filters[] = sprintf('面積が近い事例（%d〜%d㎡）', (int)$lo, (int)$hi);
            $area_matched = true;
            break;
        }
    }
    if (!$area_matched) {
        $warnings[] = sprintf('ご入力の面積（%s㎡）に近い事例が5件未満のため、面積を問わない事例で算出しています', rtrim(rtrim(number_format($area, 2, '.', ''), '0'), '.'));
    }

    // ② 築年フィルタ（マンション・戸建のみ、±10→±20）
    if ($year && in_array($ptype, array('mansion', 'house'), true)) {
        $year_matched = false;
        foreach (array(10, 20) as $span) {
            $near = array_values(array_filter($pool, function ($r) use ($year, $span) {
                $y = fs_wareki_to_year($r['BuildingYear'] ?? '');
                return $y !== null && abs($y - $year) <= $span;
            }));
            if (count($near) >= 5) {
                $pool = $near;
                $filters[] = sprintf('築年が対象（%d年）±%d年の事例', $year, $span);
                $year_matched = true;
                break;
            }
        }
        if (!$year_matched) {
            $warnings[] = sprintf('ご入力の築年（%d年）に近い事例が5件未満のため、築年を問わない事例で算出しています', $year);
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

    // 絞り込みが1つも効いていないのに「条件の近い」と書くと事実と異なるため、文言を分ける
    $reason = sprintf('周辺の%s成約事例のうち%s %d件の㎡単価をもとに、四分位（25%%〜75%%）でレンジを算出しました。採用した㎡単価の中央値は約 %s 円/㎡です。',
        $GLOBALS['FS_PTYPE_LABEL'][$ptype], ($filters ? '、条件の近い' : ''), count($units), number_format((int)$med));
    if ($filters)  $reason .= '（絞り込み: ' . implode(' ／ ', $filters) . '）';
    if ($warnings) $reason .= ' ※' . implode('。※', $warnings) . '。条件の近い事例が少ないため、この結果は精度が低くなります。個別査定をご利用ください。';

    return array(
        'ok' => true,
        'low' => (int)($p25 * $area), 'mid' => (int)($med * $area), 'high' => (int)($p75 * $area),
        'unit_mid' => (int)$med, 'sample_size' => count($units), 'reason' => $reason,
        'low_confidence' => !empty($warnings),
    );
}

function fs_yen_man($v) { return number_format($v / 10000) . '万円'; }

/* =========================================================================
 * 5. API取得（reinfolib）＋モックフォールバック
 * ======================================================================= */
/**
 * モックは「強制モック」を明示ONにしたときだけ。
 * ★APIキー未設定でモックに落としてはいけない。落とすと、実在しない架空の取引事例をもとにした
 *   価格を、お客様に「周辺の成約事例」として提示・メールしてしまう（宅建業法47条・景表法5条1号）。
 *   キーが無いときは価格を出さず、個別査定へ誘導する（fs_api_ready を参照）。
 */
function fs_use_mock() {
    return fs_opt('use_mock') === '1';
}

/* 査定の根拠データを出せる状態か（APIキーがある or 明示的なテストモード） */
function fs_api_ready() {
    return fs_opt('api_key') !== '' || fs_use_mock();
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

/* 実在する市区町村コードのみ許可（transientキー汚染と外部APIの濫用を防ぐ） */
function fs_city_valid($code) {
    if ($code === '' || !ctype_digit($code)) return false;
    foreach (fs_cities() as $list) {
        foreach ($list as $c) if ((string)$c[0] === (string)$code) return true;
    }
    return false;
}

add_action('wp_ajax_fudosan_satei_districts', 'fs_ajax_districts');
add_action('wp_ajax_nopriv_fudosan_satei_districts', 'fs_ajax_districts');
function fs_ajax_districts() {
    // 未ログインでも叩ける公開エンドポイント。nonceと実在コード照合が無いと、
    // 全国約1,900市区町村を総当たりされて外部APIのクォータを枯渇させられる（1回で最大8コール）。
    check_ajax_referer('fudosan_satei', 'nonce');
    $city = sanitize_text_field($_GET['city'] ?? '');
    if (!fs_city_valid($city)) wp_send_json(array('districts' => array(), 'counts' => null));

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
    if (!empty($ctx['purpose'])) $pd[] = "■ 利用目的 : {$ctx['purpose']}";

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
        '{purpose}'          => isset($ctx['purpose']) ? $ctx['purpose'] : '',
        '{price_low}'        => $ctx['low_man'],
        '{price_high}'       => $ctx['high_man'],
        '{price_mid}'        => $ctx['mid_man'],
        '{reason}'           => $ctx['reason'],
        '{operator_name}'    => fs_opt('operator_name', ''),
        '{operator_contact}' => fs_opt('operator_contact', ''),
    );
    // 未設定の項目で「お問い合わせ: 」のようにラベルだけが残らないよう、その行ごと落とす
    if (trim($repl['{operator_contact}']) === '') $tmpl = preg_replace('/^.*\{operator_contact\}.*\R?/m', '', $tmpl);
    if (trim($repl['{operator_name}'])    === '') $tmpl = preg_replace('/^\h*\{operator_name\}\h*\R?/m', '', $tmpl);
    // 土地・戸建ての注意文言も、テンプレート編集で消せないよう本文の外で必ず付ける。
    // 差し込み先は {property_details} の直後＝価格や物件情報のすぐ下（署名より前に置く）
    $caution = fs_type_caution(isset($ctx['ptype']) ? $ctx['ptype'] : '');
    if ($caution !== '' && strpos($tmpl, '埋設物') === false) {
        $block = "\n\n───────────────────────────────\n" . $caution
               . "\n───────────────────────────────";
        if (strpos($tmpl, '{reason}') !== false) {
            $tmpl = str_replace('{reason}', '{reason}' . $block, $tmpl);          // 価格と根拠の直後
        } elseif (strpos($tmpl, '{property_details}') !== false) {
            $tmpl = str_replace('{property_details}', '{property_details}' . $block, $tmpl);
        } else {
            $tmpl = rtrim($tmpl) . $block;   // 差し込みタグが無いテンプレートでも必ず出す
        }
    }
    return fs_with_disclaimer(rtrim(strtr($tmpl, $repl)));
}

/**
 * 免責文は「鑑定評価ではない」ことを示す法的に必須の文面。
 * 本文テンプレートは管理画面で自由に編集できるため、テンプレート内に免責を置くと
 * 編集した瞬間に消えてしまう。よって★テンプレートの外で必ず連結する。
 * （既に免責を含むテンプレートには二重に付けない）
 */
function fs_legal_disclaimer() {
    return "───────────────────────────────\n"
        . "【重要なご注意】\n"
        . "・本結果は国土交通省の不動産取引データを統計処理した簡易的な\n"
        . "  『参考価格（価格査定）』であり、不動産鑑定士による『鑑定評価』ではありません。\n"
        . "・過去の周辺取引事例からの機械的な推定値で、実際の売却価格・\n"
        . "  成約価格を保証するものではありません。\n"
        . "・正確な価格は、現地確認を含む個別査定が必要です。\n"
        . "───────────────────────────────";
}

/**
 * 土地・戸建て向けの注意文言。
 * これらは現地確認が必要な要素（接道・高低差・埋設物など）で価格が大きく動くため、
 * 匿名査定の結果が実勢と大きく乖離する（実測: 岡山市北区の土地は±30%以内が20.7%）。
 * ★免責と同じく、価格を出すすべての経路（画面・メール）に必ず添えること。
 */
function fs_type_caution($ptype, $context = 'mail') {
    if (!in_array($ptype, array('house', 'land'), true)) return '';   // マンションは対象外
    // 結びは経路で変える（画面ではまだメールを見ていないので「ご返信ください」と言えない）
    $cta = ($context === 'screen')
        ? "より精緻な査定をご希望の場合は、訪問査定（現地を確認したうえでの本査定）を承ります。"
          . "この後お送りするメールにご返信いただければ、担当者よりご連絡いたします。"
        : "より精緻な査定をご希望の場合は、訪問査定（現地を確認したうえでの本査定）を承ります。"
          . "ご売却のご事情やご希望の時期など、このメールにそのままご返信ください。担当者よりご連絡いたします。";
    return "土地・戸建ての査定は本来、地形、接道道路の種別、権利関係、高低差、埋設物など、"
         . "現地を確認しなければ反映できない要素に大きく左右されます。\n"
         . "そのため、匿名査定の結果はあくまで目安であり、実際の価格と大きく異なる場合がございます。\n"
         . $cta;
}

function fs_with_disclaimer($body) {
    // mbstring が無いサーバーでも動くよう strpos を使う（UTF-8同士の検索は strpos で正しく判定できる）
    if (strpos($body, '鑑定評価') === false) $body .= "\n\n" . fs_legal_disclaimer();
    return $body . "\n";
}

/* 事例不足・自動査定停止時にお客様へ送る受付メール（価格は出さない。免責は自動付加） */
function fs_insufficient_mail_body($ctx, $reason) {
    $site = fs_opt('site_name', 'AI査定');
    $loc  = trim($ctx['pref'] . ' ' . $ctx['city'] . ' ' . $ctx['district']);
    $b = array();
    $b[] = "【{$site}】お申し込みを受け付けました";
    $b[] = "";
    $b[] = "この度はご利用いただきありがとうございます。";
    $b[] = "以下の内容でお申し込みを受け付けました。";
    $b[] = "";
    $b[] = "■ 物件種別 : {$ctx['ptype_label']}";
    $b[] = "■ 所在地   : {$loc}";
    $b[] = "■ 面積     : {$ctx['area']} ㎡";
    if (!empty($ctx['build_year'])) $b[] = "■ 築年     : {$ctx['build_year']}年";
    if (!empty($ctx['floor_plan'])) $b[] = "■ 間取り   : {$ctx['floor_plan']}";
    if (!empty($ctx['purpose']))    $b[] = "■ 利用目的 : {$ctx['purpose']}";
    $b[] = "";
    $b[] = $reason;
    $b[] = "";
    $b[] = "個別査定をご希望の場合は、このメールにご返信ください。担当者よりご連絡いたします。";
    $op = fs_opt('operator_name', ''); $oc = fs_opt('operator_contact', '');
    if ($op !== '' || $oc !== '') {
        $b[] = "";
        if ($op !== '') $b[] = $op;
        if ($oc !== '') $b[] = "お問い合わせ: " . $oc;
    }
    return fs_with_disclaimer(rtrim(implode("\n", $b)));
}

/* 管理者通知メールの本文（担当者へ）。事例不足で査定できなかったリードにも対応する */
function fs_admin_notify_body($ctx, $email, $res, $mkt = false) {
    $loc = trim($ctx['pref'] . ' ' . $ctx['city'] . ' ' . $ctx['district']);
    $b = array();
    $b[] = "新しい査定リードが届きました。";
    $b[] = "";
    $b[] = "■ お客様メール : {$email}";
    $b[] = "■ 営業連絡の可否 : " . ($mkt ? '希望あり（オプトイン済み）' : '希望なし ※営業メールは送らないでください');
    $b[] = "";
    $b[] = "■ 物件種別 : {$ctx['ptype_label']}";
    $b[] = "■ 所在地   : {$loc}";
    $b[] = "■ 面積     : {$ctx['area']} ㎡";
    if (!empty($ctx['build_year'])) $b[] = "■ 築年     : {$ctx['build_year']}年";
    if (!empty($ctx['floor_plan'])) $b[] = "■ 間取り   : {$ctx['floor_plan']}";
    if (!empty($ctx['station_name'])) {
        $b[] = "■ 最寄駅   : {$ctx['station_name']}" . (!empty($ctx['station_min']) ? " 徒歩{$ctx['station_min']}分" : '');
    }
    if (!empty($ctx['purpose'])) $b[] = "■ 利用目的 : {$ctx['purpose']}";
    $b[] = "";
    if (!empty($res['ok'])) {
        $b[] = "─────────────────────";
        $b[] = "  お客様に提示した参考価格";
        $b[] = "  " . fs_yen_man($res['low']) . " 〜 " . fs_yen_man($res['high']) . "（中央値 " . fs_yen_man($res['mid']) . "）";
        $b[] = "  使用事例 {$res['sample_size']}件";
        $b[] = "─────────────────────";
        if (!empty($res['low_confidence'])) {
            $b[] = "";
            $b[] = "※条件の近い事例が不足しており、精度の低い査定です。フォローの際はご注意ください。";
        }
    } else {
        $b[] = "─────────────────────";
        $b[] = "  事例不足のため自動査定できませんでした";
        $b[] = "  （お客様には個別査定をご案内しています）";
        $b[] = "─────────────────────";
        $b[] = "";
        $b[] = "※自動査定が出せていないため、個別対応のチャンスです。";
    }
    $b[] = "";
    $b[] = "管理画面「不動産AI査定 → 査定結果・顧客情報」からも確認できます。";
    return implode("\n", $b);
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

    // ボット・自動送信を先に弾く（DB・外部API・メールに一切触らせない）
    $bot = fs_bot_errors();
    if ($bot) wp_send_json(array('ok' => false, 'errors' => $bot));

    $lim = fs_rl_limits();
    if (!fs_rate_ok('ip', fs_client_ip(), $lim['ip_max'], $lim['ip_window'])) {
        wp_send_json(array('ok' => false, 'errors' => array(
            '送信が集中しています。しばらく時間をおいてからお試しください。')));
    }

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
    $purpose = sanitize_text_field($_POST['purpose'] ?? '');
    if ($purpose !== '' && !in_array($purpose, fs_purposes(), true)) $purpose = ''; // 選択肢以外は無視
    $agree = !empty($_POST['agree']);
    $mkt   = !empty($_POST['marketing']);

    $errors = array();
    if (!$agree) $errors[] = '個人情報の取扱いへの同意が必要です。';
    if (!is_email($email)) $errors[] = 'メールアドレスの形式が正しくありません。';
    if (!isset(fs_prefs()[$pref]) || !$city) $errors[] = '都道府県・市区町村を選択してください。';
    if (!isset($GLOBALS['FS_PTYPE_MAP'][$ptype])) $errors[] = '物件種別を選択してください。';
    if ($area <= 0) {
        $errors[] = '面積（㎡）を正しく入力してください。';
    } elseif (isset($GLOBALS['FS_PTYPE_MAP'][$ptype])) {
        list($amin, $amax) = fs_area_range($ptype);
        if ($area < $amin || $area > $amax) {   // 桁間違い等を弾く（5㎡のマンション等）
            $errors[] = sprintf('%sの面積は %s〜%s㎡ の範囲でご入力ください（ご入力: %s㎡）。',
                $GLOBALS['FS_PTYPE_LABEL'][$ptype], number_format($amin), number_format($amax),
                rtrim(rtrim(number_format($area, 2, '.', ''), '0'), '.'));
        }
    }
    if ($errors) wp_send_json(array('ok' => false, 'errors' => $errors));

    // ★本命の防御：同一アドレス宛の連続送信を止める。これが無いと、任意の第三者のアドレスを
    //   入れて連打されるだけで、自社ドメインからのメール爆撃の踏み台になる。
    if (!fs_rate_ok('email', $email, $lim['email_max'], $lim['email_window'])) {
        wp_send_json(array('ok' => false, 'errors' => array(
            'このメールアドレスでのお申し込みが続いています。24時間ほどおいてからお試しください。')));
    }

    // ★APIキーが無いときに架空データで価格を出してはいけない。価格を出さず個別査定へ誘導する
    //   （リード保存と担当者通知は下でそのまま行う＝営業機会は失わない）
    if (fs_api_ready()) {
        $records = fs_fetch_recent($city, intval(date('Y')) - 1, 8);
        $res = fs_estimate($records, $ptype, $area, $byear, $district);
    } else {
        $res = array('ok' => false, 'sample_size' => 0,
            'reason' => 'ただいま自動査定を停止しております。ご入力の内容は担当者が確認し、個別にご連絡いたします。');
    }

    $pref_name = fs_prefs()[$pref];
    $city_name = fs_city_name($pref, $city);

    // リード保存（事例不足でも保存＝営業価値）
    global $wpdb;
    $ins = $wpdb->insert($wpdb->prefix . 'fudosan_satei_leads', array(
        'created_at' => current_time('mysql'), 'email' => $email,
        'pref' => $pref_name, 'city' => $city_name, 'ptype' => $ptype,
        'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
        'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district, 'purpose' => $purpose,
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
            'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district, 'purpose' => $purpose,
            'low' => $res['low'] ?? null, 'mid' => $res['mid'] ?? null, 'high' => $res['high'] ?? null,
            'sample_size' => $res['sample_size'] ?? 0, 'marketing_opt_in' => $mkt ? 1 : 0,
        ));
    } else {
        delete_option('fs_last_db_error');
    }

    $label = $GLOBALS['FS_PTYPE_LABEL'][$ptype];

    // 担当者へのリード通知。事例不足で査定できなかった場合も「リードはリード」なので必ず通知する
    // （早期returnより前に置くこと。後ろに置くと事例不足リードだけ通知が飛ばない）
    if (fs_notify_on()) {
        $nfrom  = fs_opt('from_email');
        $nsite  = fs_opt('site_name', 'AI査定');
        $notify = fs_opt('notify_email', '') ?: ($nfrom ?: get_option('admin_email'));
        if ($notify) {
            $nheaders = array('Content-Type: text/plain; charset=UTF-8');
            if ($nfrom) $nheaders[] = 'From: ' . $nsite . ' <' . $nfrom . '>';
            $nctx = array(
                'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name, 'district' => $district,
                'area' => $area, 'build_year' => $byear, 'floor_plan' => $fplan,
                'station_name' => $sname, 'station_min' => $smin, 'purpose' => $purpose,
            );
            wp_mail($notify, '【AI査定】新しい査定リードが届きました', fs_admin_notify_body($nctx, $email, $res, $mkt), $nheaders);
        }
    }

    if (!$res['ok']) {
        // 画面で「メールをお送りしました」と案内する以上、必ず送る（価格は出さず、免責は自動付加）
        $iheaders = array('Content-Type: text/plain; charset=UTF-8');
        $ifrom = fs_opt('from_email'); $isite = fs_opt('site_name', 'AI査定');
        if ($ifrom) $iheaders[] = 'From: ' . $isite . ' <' . $ifrom . '>';
        $ictx = array(
            'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name, 'district' => $district,
            'area' => $area, 'build_year' => $byear, 'floor_plan' => $fplan,
            'station_name' => $sname, 'station_min' => $smin, 'purpose' => $purpose,
        );
        $imail_ok = wp_mail($email, '【' . $isite . '】お申し込みを受け付けました',
            fs_insufficient_mail_body($ictx, $res['reason']), $iheaders);
        wp_send_json(array('ok' => false, 'insufficient' => true, 'reason' => $res['reason'],
            'email' => $email, 'mail_ok' => (bool)$imail_ok));
    }

    $ctx = array(
        'ptype' => $ptype,   // 種別ごとの注意文言（土地・戸建て）の判定に使う
        'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name, 'area' => $area, 'build_year' => $byear,
        'district' => $district, 'station_name' => $sname, 'station_min' => $smin, 'floor_plan' => $fplan,
        'purpose' => $purpose,
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
        'station_name' => $sname, 'floor_plan' => $fplan, 'district' => $district, 'purpose' => $purpose,
        'low_man' => $ctx['low_man'], 'mid_man' => $ctx['mid_man'], 'high_man' => $ctx['high_man'],
        'sample_size' => $res['sample_size'], 'reason' => $res['reason'],
        'caution' => fs_type_caution($ptype, 'screen'),   // 土地・戸建てのみ。価格と必ずセットで表示する
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
        'design' => 'default', 'url' => '', 'button' => '', 'title' => '', 'subtitle' => '', 'logo' => '',
        'note' => '', 'fields' => '',
    ), $atts, 'fudosan_satei');
    $design = in_array($a['design'], array('default', 'compact', 'card', 'teaser'), true) ? $a['design'] : 'default';
    $compact = ($design === 'compact');
    $teaser  = ($design === 'teaser');   // ステップ1: 3項目だけ入力して本フォームへ引き継ぐ
    $target  = esc_url($a['url']);
    $btn     = $a['button'] !== '' ? sanitize_text_field($a['button'])
                                   : ($teaser ? '査定をする' : '無料で査定結果を受け取る');
    $t_title = $a['title'] !== ''    ? sanitize_text_field($a['title'])    : '60秒でかんたん入力！';
    $t_sub   = $a['subtitle'] !== '' ? sanitize_text_field($a['subtitle']) : '査定結果はメールでお届けします';
    $t_logo  = $a['logo'] !== ''     ? esc_url($a['logo'])                 : '';
    $t_note  = $a['note'] !== ''     ? sanitize_text_field($a['note'])     : '';   // teaser下部の注記（既定は非表示）
    $t_fields = $teaser ? fs_parse_teaser_fields($a['fields']) : array();          // teaserに出す項目

    // 装飾（設定画面のデザインタブ）
    $c_brand    = fs_opt('color_brand', '#1f6feb');
    $c_btn_text = fs_opt('color_btn_text', '#ffffff');
    $c_title    = fs_opt('color_title', '#1f6feb');
    $c_badge    = fs_opt('color_badge', '#ff5a36');
    $c_brand_rgb = fs_hex_to_rgb($c_brand);

    // compact/teaser は入力を最小限に（compactでは築年は精度のため残す）
    $show_district   = fs_show('district')   && !$compact && !$teaser;
    $show_station    = fs_show('station')    && !$compact && !$teaser;
    $show_floor_plan = fs_show('floor_plan') && !$compact && !$teaser;
    $show_build_year = fs_show('build_year') && !$teaser;
    $show_purpose    = fs_show('purpose')    && !$compact && !$teaser;
    $show_marketing  = fs_show('marketing')  && !$compact && !$teaser;

    $prefs  = fs_area_prefs();
    $cities = fs_cities();
    $nonce  = wp_create_nonce('fudosan_satei');

    $ajax   = admin_url('admin-ajax.php');
    $year   = intval(date('Y'));

    // ステップ1から引き継いだ値（?fs_ptype=&fs_pref=&fs_city=&fs_purpose=&fs_area=&fs_build_year=）を検証
    $g = function ($k) { return isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : ''; };
    $prefill = array(
        'ptype'      => $g('fs_ptype'),
        'pref'       => $g('fs_pref'),
        'city'       => $g('fs_city'),
        'purpose'    => $g('fs_purpose'),
        'area'       => $g('fs_area'),
        'build_year' => $g('fs_build_year'),
    );
    if (!isset($GLOBALS['FS_PTYPE_MAP'][$prefill['ptype']])) $prefill['ptype'] = '';
    if (!isset($prefs[$prefill['pref']])) { $prefill['pref'] = ''; $prefill['city'] = ''; }
    if ($prefill['city'] !== '') {                                   // 市区町村コードが都道府県に属するか
        $ok = false;
        foreach (($cities[$prefill['pref']] ?? array()) as $c) { if ($c[0] === $prefill['city']) { $ok = true; break; } }
        if (!$ok) $prefill['city'] = '';
    }
    if ($prefill['purpose'] !== '' && !in_array($prefill['purpose'], fs_purposes(), true)) $prefill['purpose'] = '';
    if ($prefill['area'] !== '' && (!is_numeric($prefill['area']) || $prefill['area'] <= 0 || $prefill['area'] > 100000)) $prefill['area'] = '';
    if ($prefill['build_year'] !== '') {
        $by = intval($prefill['build_year']);
        $prefill['build_year'] = ($by >= 1950 && $by <= $year) ? (string)$by : '';
    }

    // 入力ガイド（必須→✓、次の欄を光らせる）の対象
    if ($teaser) {
        $reg = fs_teaser_fields();
        $required_names = array();
        foreach ($t_fields as $k) if ($reg[$k]['req']) $required_names[] = $reg[$k]['name'];
    } else {
        $required_names = array('ptype', 'pref_code', 'city_code', 'area', 'email');
    }
    $privacy = fs_opt('privacy_url');
    $terms   = fs_opt('terms_url');

    // プレースホルダーは「行動」を示す（ラベルと同じ語を繰り返さない）
    $pref_options = '<option value="">選択してください</option>';
    foreach ($prefs as $code => $name) $pref_options .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';

    $ptype_options = '<option value="">選択してください</option>'
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
    .fs-wrap{--fs-brand:<?php echo esc_attr($c_brand); ?>;--fs-brand-rgb:<?php echo esc_attr($c_brand_rgb); ?>;--fs-btn-text:<?php echo esc_attr($c_btn_text); ?>;--fs-title:<?php echo esc_attr($c_title); ?>;--fs-badge-bg:<?php echo esc_attr($c_badge); ?>;--fs-ink:#1a1f36;--fs-muted:#6b7280;--fs-line:#e5e7eb;width:100%;max-width:none;margin:0;color:var(--fs-ink);font-family:inherit;line-height:1.75;font-size:17px}
    .fs-card{background:transparent;border:0;border-radius:0;padding:0}
    .fs-wrap label{display:block;font-weight:600;margin:18px 0 7px;font-size:19px}
    /* 必須／任意バッジ */
    .fs-req,.fs-opt{font-size:11px;font-weight:700;border-radius:4px;padding:4px 7px;line-height:1;margin-left:8px;display:inline-flex;align-items:center;vertical-align:middle;letter-spacing:.02em;white-space:nowrap;flex:0 0 auto}
    .fs-req{background:var(--fs-badge-bg);color:#fff}
    .fs-opt{background:#eef1f5;color:#6b7280}
    .fs-req.fs-done{background:var(--fs-brand);color:#fff;border-radius:50%;width:20px;height:20px;padding:0;font-size:12px;justify-content:center}

    /* 引き継ぎ時の「続きはこちらから」バナー */
    .fs-resume{display:flex;align-items:baseline;flex-wrap:wrap;gap:4px 10px;background:rgba(var(--fs-brand-rgb),.07);border:1px solid rgba(var(--fs-brand-rgb),.22);border-left:4px solid var(--fs-brand);border-radius:8px;padding:12px 14px;margin:26px 0 6px;font-size:15px}
    .fs-resume b{color:var(--fs-brand);font-weight:800}
    .fs-resume span{color:var(--fs-muted);font-size:14px}

    /* セクション見出し（メリハリ） */
    .fs-section{display:flex;align-items:center;font-weight:800;font-size:17px;color:var(--fs-ink);margin:32px 0 4px;padding-left:11px;border-left:4px solid var(--fs-brand);line-height:1.5}
    .fs-form > .fs-section:first-child{margin-top:0}

    .fs-wrap input,.fs-wrap select{width:100%;padding:14px 15px;border:1px solid #cbd5e1;border-radius:9px;font-size:18px;background:#fff;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
    .fs-wrap input:focus,.fs-wrap select:focus{outline:none;border-color:var(--fs-brand);box-shadow:0 0 0 3px rgba(var(--fs-brand-rgb),.15)}
    .fs-row{display:flex;gap:12px}.fs-row>div{flex:1}
    .fs-hint{color:var(--fs-muted);font-size:15px;margin-top:5px;line-height:1.7}
    .fs-check{display:flex;gap:9px;align-items:flex-start;margin-top:14px}
    .fs-check input{width:auto;margin-top:6px;transform:scale(1.2)}.fs-check label{margin:0;font-weight:400;font-size:16px}
    .fs-wrap button{margin-top:24px;width:100%;background:var(--fs-brand);color:var(--fs-btn-text);border:0;border-radius:10px;padding:18px;font-size:20px;font-weight:700;cursor:pointer}
    .fs-wrap button:hover{filter:brightness(.93)}
    .fs-wrap button:disabled{opacity:.6;cursor:wait;filter:none}
    .fs-disc{background:#fff8e6;border:1px solid #f0e0a8;border-radius:10px;padding:15px 17px;font-size:14px;color:#6b5a12;margin-top:18px}
    .fs-testmode{background:#fdecea;border:2px solid #c0392b;border-radius:10px;padding:13px 15px;font-size:15px;color:#c0392b;font-weight:700;margin-bottom:18px}
    /* 土地・戸建ての注意文言。価格のすぐ下に置き、読み飛ばされないよう左に色帯を立てる */
    .fs-caution{background:#f7f9fc;border:1px solid var(--fs-line);border-left:4px solid var(--fs-brand);border-radius:8px;padding:14px 16px;font-size:15px;line-height:1.85;color:#374151;margin-top:16px}
    /* ハニーポット：display:none だと一部のボットに読まれるため画面外へ逃がす */
    .fs-hp{position:absolute!important;left:-9999px!important;top:auto;width:1px;height:1px;overflow:hidden}
    .fs-privacy-note{background:#f6f8fa;border:1px solid var(--fs-line);border-radius:9px;padding:13px 15px;font-size:14px;color:#4b5563;line-height:1.75;margin-top:16px}
    .fs-operator{margin-top:18px;padding-top:16px;border-top:1px solid var(--fs-line);font-size:14px;color:#4b5563;line-height:1.9}
    .fs-operator-t{font-weight:700;color:var(--fs-ink);margin-bottom:4px;font-size:15px}
    .fs-operator span{display:inline-block;min-width:6.5em;color:var(--fs-muted)}
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
    .fs-design-compact .fs-section{display:none} /* 短くするため見出しは省略 */
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
    .fs-teaser-title{font-size:19px;font-weight:800;color:var(--fs-title);line-height:1.4}
    .fs-teaser-sub{font-size:13px;color:var(--fs-muted);margin-top:4px}
    /* ロゴあり: ロゴ左・テキスト右の横並び */
    .fs-teaser-head.fs-has-logo{display:flex;align-items:center;gap:12px;text-align:left}
    .fs-teaser-head.fs-has-logo .fs-teaser-logo{flex:0 0 auto;line-height:0}
    .fs-teaser-head.fs-has-logo .fs-teaser-logo img{display:block;max-height:56px;max-width:80px;width:auto;height:auto}
    .fs-teaser-head.fs-has-logo .fs-teaser-texts{flex:1;min-width:0}
    @media (max-width:380px){
      .fs-teaser-head.fs-has-logo{flex-direction:column;text-align:center;gap:8px}
    }

    /* teaser: ラベル横並び＋必須バッジ */
    .fs-design-teaser .fs-trow{display:flex;align-items:center;gap:10px;margin:14px 0}
    .fs-design-teaser .fs-tlabel{flex:0 0 auto;width:142px;display:flex;align-items:center;gap:6px;font-weight:700;font-size:15px;line-height:1.35}
    .fs-design-teaser .fs-tfield{flex:1;min-width:0}
    .fs-design-teaser .fs-tfield select,.fs-design-teaser .fs-tfield input{margin:0}
    .fs-design-teaser .fs-tlabel .fs-req,.fs-design-teaser .fs-tlabel .fs-opt{margin-left:0}
    .fs-badge{background:var(--fs-badge-bg);color:#fff;font-size:11px;font-weight:700;border-radius:4px;padding:4px 7px;line-height:1;flex:0 0 auto;white-space:nowrap}
    .fs-badge.fs-done{background:var(--fs-brand);border-radius:50%;width:21px;height:21px;padding:0;font-size:12px;display:inline-flex;align-items:center;justify-content:center}

    /* 次に入力すべき欄をハイライト（全デザイン共通） */
    .fs-wrap select.fs-next,.fs-wrap input.fs-next{border-color:rgba(var(--fs-brand-rgb),.55);animation:fsPulse 1.5s ease-in-out infinite}
    @keyframes fsPulse{
      0%,100%{box-shadow:0 0 0 3px rgba(var(--fs-brand-rgb),.16)}
      50%{box-shadow:0 0 0 7px rgba(var(--fs-brand-rgb),.28)}
    }
    @media (prefers-reduced-motion:reduce){
      .fs-wrap select.fs-next,.fs-wrap input.fs-next{animation:none;box-shadow:0 0 0 3px rgba(var(--fs-brand-rgb),.20)}
    }

    /* デザイン: card（全項目を枠＋影のカードで） */
    .fs-design-card .fs-card{background:#fff;border:1px solid var(--fs-line);border-radius:14px;padding:24px 22px;box-shadow:0 4px 18px rgba(16,24,40,.06)}
  </style>

  <div class="fs-card fs-form-card" id="fs-form-card">
<?php if (fs_use_mock()): /* テストモードの価格はダミー。お客様に必ず知らせる（消せない表示） */ ?>
    <div class="fs-testmode">⚠ テストモードで動作しています。表示される価格は<strong>ダミーデータ</strong>であり、実際の取引事例に基づくものではありません。</div>
<?php endif; ?>
    <div class="fs-errors" id="fs-errors"></div>
<?php if ($teaser): ?>
    <div class="fs-teaser-head<?php echo $t_logo ? ' fs-has-logo' : ''; ?>">
<?php if ($t_logo): ?>
      <div class="fs-teaser-logo"><img src="<?php echo esc_url($t_logo); ?>" alt="<?php echo esc_attr(fs_opt('site_name', '')); ?>"></div>
<?php endif; ?>
      <div class="fs-teaser-texts">
        <div class="fs-teaser-title"><?php echo esc_html($t_title); ?></div>
<?php if ($t_sub !== ''): ?>
        <div class="fs-teaser-sub"><?php echo esc_html($t_sub); ?></div>
<?php endif; ?>
      </div>
    </div>
    <form class="fs-form" id="fs-form">
<?php $reg = fs_teaser_fields(); foreach ($t_fields as $k): $fd = $reg[$k]; ?>
      <div class="fs-trow">
        <div class="fs-tlabel"><?php echo esc_html($fd['label']); ?><?php
            echo $fd['req'] ? '<span class="fs-badge">必須</span>' : '<span class="fs-opt">任意</span>'; ?></div>
        <div class="fs-tfield">
<?php if ($k === 'purpose'): ?>
          <select name="purpose">
            <option value="">選択してください</option>
<?php foreach (fs_purposes() as $p): ?>
            <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
<?php endforeach; ?>
          </select>
<?php elseif ($k === 'ptype'): ?>
          <select name="ptype" required><?php echo $ptype_options; ?></select>
<?php elseif ($k === 'pref'): ?>
          <select class="fs-pref" name="pref_code" id="fs-pref" required><?php echo $pref_options; ?></select>
<?php elseif ($k === 'city'): ?>
          <select class="fs-city" name="city_code" id="fs-city" required><option value="">先に都道府県を選択</option></select>
<?php elseif ($k === 'area'): ?>
          <input type="number" name="area" step="0.01" min="1" placeholder="例：70" required>
<?php elseif ($k === 'build_year'): ?>
          <input type="number" name="build_year" min="1950" max="<?php echo $year; ?>" placeholder="例：2015">
<?php endif; ?>
        </div>
      </div>
<?php endforeach; ?>

      <div class="fs-coverage"></div>
<?php else: ?>
    <form class="fs-form" id="fs-form">
      <?php /* ボット対策。人には見えず、自動入力ツールだけが埋める欄 */ ?>
      <div class="fs-hp" aria-hidden="true">
        <label for="fs-website">ウェブサイト（入力しないでください）</label>
        <input type="text" name="fs_website" id="fs-website" tabindex="-1" autocomplete="off">
      </div>
<?php if ($show_purpose): ?>
      <div class="fs-section">ご利用目的</div>
      <label>どのようなご事情ですか<span class="fs-opt">任意</span></label>
      <select name="purpose">
        <option value="">選択してください</option>
<?php foreach (fs_purposes() as $p): ?>
        <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
<?php endforeach; ?>
      </select>
<?php endif; ?>

      <div class="fs-section">物件の情報</div>
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
      <label>地区（町名）<span class="fs-opt">任意</span></label>
      <select class="fs-district" name="district"><option value="">市区町村を選ぶと表示されます</option></select>
      <div class="fs-hint">選ぶと査定精度が上がります（同じ市区町村でも地区で相場が違うため）</div>
<?php endif; ?>

<?php if (!$teaser): ?>
      <div class="fs-row">
        <div>
          <label id="fs-area-label">面積（㎡）<span class="fs-req">必須</span></label>
          <input type="number" name="area" step="0.01" min="1" placeholder="例：70" required>
          <?php /* ★戸建は「土地面積」を入力させること。国交省データの宅地(土地と建物)のArea＝土地面積であり、
                    延床を入力させて円/土地㎡に掛けると系統的に過大評価になる（渋谷区で中央値+63%を実測） */ ?>
          <div class="fs-hint" id="fs-area-hint">マンションは専有面積、戸建・土地は土地（敷地）面積</div>
        </div>
<?php if ($show_build_year): ?>
        <div>
          <label>築年（西暦）<span class="fs-opt">任意</span></label>
          <input type="number" name="build_year" min="1950" max="<?php echo $year; ?>" placeholder="例：2015">
          <div class="fs-hint">土地の場合は不要</div>
        </div>
<?php endif; ?>
      </div>
<?php endif; ?>

<?php if ($show_station): ?>
      <div class="fs-row">
        <div>
          <label>最寄駅<span class="fs-opt">任意</span></label>
          <input type="text" name="station_name" placeholder="例：渋谷駅">
        </div>
        <div>
          <label>駅まで徒歩（分）<span class="fs-opt">任意</span></label>
          <input type="number" name="station_min" min="0" max="60" placeholder="例：8">
        </div>
      </div>
<?php endif; ?>

<?php if ($show_floor_plan): ?>
      <label>間取り<span class="fs-opt">任意</span></label>
      <select name="floor_plan">
        <option value="">選択しない</option>
        <option>1R</option><option>1K</option><option>1DK</option><option>1LDK</option>
        <option>2K</option><option>2DK</option><option>2LDK</option>
        <option>3K</option><option>3DK</option><option>3LDK</option>
        <option>4LDK以上</option>
      </select>
<?php endif; ?>

<?php if (!$teaser): ?>
      <div class="fs-section">ご連絡先</div>
      <label>結果をお届けするメールアドレス<span class="fs-req">必須</span></label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <?php /* 個人情報の利用目的の明示（個情法21条）。同意を求める直前に必ず出す。
               プライバシーポリシーURLが未設定でも、最低限ここで目的が伝わるようにしておく */ ?>
      <div class="fs-privacy-note">
        <strong>個人情報の取り扱いについて</strong><br>
        ご入力いただいた内容は、<?php echo esc_html(fs_opt('operator_name', '当社')); ?>が<strong>査定結果のご連絡と、それに関するご案内</strong>のために利用します。
        ご本人の同意なく第三者に提供することはありません。削除をご希望の場合は下記の連絡先までお申し付けください。
      </div>

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
<?php if ($t_note !== ''): ?>
    <div class="fs-note"><?php echo esc_html($t_note); ?></div>
<?php endif; ?>
<?php else: ?>
    <div class="fs-disc">
      本サービスの結果はAIによる簡易的な<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。実際の売却価格を保証するものではありません。
    </div>
<?php endif; ?>
<?php
    /* 提供元の明示。お客様が「どこの誰に自宅の情報を渡すのか」を判断する材料であり、
       不動産では免許番号の有無が信頼性を大きく左右する。設定済みの項目だけを出す。 */
    $op_name = fs_opt('operator_name', ''); $op_lic = fs_opt('license_no', '');
    $op_addr = fs_opt('operator_address', ''); $op_tel = fs_opt('operator_contact', '');
    if ($op_name || $op_lic || $op_addr || $op_tel):
?>
    <div class="fs-operator">
      <div class="fs-operator-t">このサービスの提供元</div>
<?php if ($op_name): ?>      <div><span>運営</span><?php echo esc_html($op_name); ?></div>
<?php endif; if ($op_lic): ?>      <div><span>免許番号</span><?php echo esc_html($op_lic); ?></div>
<?php endif; if ($op_addr): ?>      <div><span>所在地</span><?php echo esc_html($op_addr); ?></div>
<?php endif; if ($op_tel): ?>      <div><span>お問い合わせ</span><?php echo esc_html($op_tel); ?></div>
<?php endif; ?>
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
  var LOADED_AT = Date.now();   // ページキャッシュがあってもJS側で計測すれば正しく効く
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

  // 入力済みの必須項目は「必須」→ ✓ に、次に入力すべき欄を光らせる（全デザイン共通）
  var REQUIRED = <?php echo wp_json_encode($required_names); ?>;

  // teaser は .fs-trow 内の .fs-badge、それ以外は直前の <label> 内の .fs-req
  function badgeFor(el){
    var row = el.closest ? el.closest('.fs-trow') : null;
    if (row) return row.querySelector('.fs-badge');
    var lbl = el.previousElementSibling;
    return (lbl && lbl.tagName === 'LABEL') ? lbl.querySelector('.fs-req') : null;
  }

  var resumeBox = null; // 「続きはこちらから」バナー

  function updateFormState(){
    var firstEmpty = null, remaining = 0;
    REQUIRED.forEach(function(name){
      var el = form.elements[name];
      if (!el) return;
      el.classList.remove('fs-next');
      var b = badgeFor(el);
      var filled = !!(el.value && String(el.value).trim() !== '');
      if (b) {
        if (filled) { b.classList.add('fs-done'); b.textContent = '✓'; }
        else { b.classList.remove('fs-done'); b.textContent = '必須'; }
      }
      if (!filled) { remaining++; if (!firstEmpty) firstEmpty = el; }
    });
    if (firstEmpty) firstEmpty.classList.add('fs-next');

    if (resumeBox) {
      if (remaining === 0) { resumeBox.style.display = 'none'; }
      else {
        resumeBox.style.display = '';
        resumeBox.querySelector('span').textContent = 'あと' + remaining + '項目で完了です';
      }
    }
  }

  // 引き継ぎで来たとき、最初の未入力欄の直前にバナーを差し込む
  function insertResumeBanner(){
    var el = wrap.querySelector('.fs-next');
    if (!el) return;
    var anchor = el;                                   // フォーム直下のブロックまで遡る
    while (anchor && anchor.parentNode !== form) anchor = anchor.parentNode;
    if (!anchor) return;
    var prev = anchor.previousElementSibling;          // ラベルがあればその手前に置く
    if (prev && prev.tagName === 'LABEL') anchor = prev;
    resumeBox = document.createElement('div');
    resumeBox.className = 'fs-resume';
    resumeBox.innerHTML = '<b>↓ 続きはこちらから</b><span></span>';
    form.insertBefore(resumeBox, anchor);
    updateFormState();
  }

  REQUIRED.forEach(function(name){
    var el = form.elements[name];
    if (!el) return;
    el.addEventListener('change', updateFormState);
    el.addEventListener('input', updateFormState);
  });

  if (pref) pref.addEventListener('change', function(){
    var list = CITIES[pref.value] || [];
    if (city) city.innerHTML = '<option value="">' + (pref.value ? '選択してください' : '先に都道府県を選択') + '</option>' +
      list.map(function(c){ return '<option value="'+c[0]+'">'+c[1]+'</option>'; }).join('');
    if (district) district.innerHTML = '<option value="">市区町村を選ぶと表示されます</option>';
    TYPE_COUNTS = null; renderCoverage(); updateFormState();
  });

  if (city) city.addEventListener('change', function(){
    TYPE_COUNTS = null;
    updateFormState();
    if (!city.value) {
      if (district) district.innerHTML = '<option value="">市区町村を選ぶと表示されます</option>';
      renderCoverage();
      return;
    }
    if (district) district.innerHTML = '<option value="">読み込み中…</option>';
    if (cov) cov.textContent = 'この地域の取引事例を確認中…';
    fetch(AJAX + '?action=fudosan_satei_districts&nonce=' + encodeURIComponent(NONCE) + '&city=' + encodeURIComponent(city.value), { credentials:'same-origin' })
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

  // 面積の意味を種別ごとに明示する（戸建に延床を入れさせると査定が過大になるため）
  function updateAreaLabel(){
    var lbl = wrap.querySelector('#fs-area-label'), hint = wrap.querySelector('#fs-area-hint');
    if (!lbl || !ptypeSel) return;
    var badge = lbl.querySelector('.fs-req');
    var t = { mansion: ['専有面積（㎡）', '登記簿またはパンフレットに記載の専有面積をご入力ください'],
              house:   ['土地面積（㎡）', '建物の延床面積ではなく、土地（敷地）の面積をご入力ください'],
              land:    ['土地面積（㎡）', '土地（敷地）の面積をご入力ください'] }[ptypeSel.value]
            || ['面積（㎡）', 'マンションは専有面積、戸建・土地は土地（敷地）面積'];
    lbl.textContent = t[0];
    if (badge) lbl.appendChild(badge);
    if (hint) hint.textContent = t[1];
  }
  updateAreaLabel();
  if (ptypeSel) ptypeSel.addEventListener('change', function(){ updateAreaLabel(); renderCoverage(); updateFormState(); });

  updateFormState(); // 初期表示（最初の未入力欄を光らせる）

  // 引き継ぎで来たときは、最初の未入力欄（＝光っている欄）まで自動スクロール
  function scrollToFirstEmpty(){
    var target = resumeBox || wrap.querySelector('.fs-next') || btn;
    if (!target) return;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // 市区町村の非同期読込でレイアウトが動くため、少し待ってから
    setTimeout(function(){
      target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    }, 120);
  }

  // ステップ1（teaser）から引き継いだ値を復元し、市区町村・事例数まで自動で読み込む
  if (!TEASER && PREFILL) {
    var hasPrefill = Object.keys(PREFILL).some(function(k){ return !!PREFILL[k]; });
    ['purpose','area','build_year'].forEach(function(n){
      var el = form.elements[n];
      if (el && PREFILL[n]) el.value = PREFILL[n];
    });
    if (PREFILL.ptype && ptypeSel) ptypeSel.value = PREFILL.ptype;
    if (PREFILL.pref && pref) {
      pref.value = PREFILL.pref;
      pref.dispatchEvent(new Event('change'));
      if (PREFILL.city && city) {
        city.value = PREFILL.city;
        city.dispatchEvent(new Event('change'));
      }
    }
    updateFormState();
    if (hasPrefill) {                       // 引き継ぎ時のみ（通常の直アクセスでは動かさない）
      insertResumeBanner();
      scrollToFirstEmpty();
    }
  }

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  form.addEventListener('submit', function(e){
    e.preventDefault();

    // ステップ1（teaser）: 入力値をURLに載せてフル入力フォームへ引き継ぐ
    if (TEASER) {
      if (!TARGET) return;
      var MAP = { ptype:'fs_ptype', pref_code:'fs_pref', city_code:'fs_city',
                  purpose:'fs_purpose', area:'fs_area', build_year:'fs_build_year' };
      var p = [];
      Object.keys(MAP).forEach(function(n){
        var el = form.elements[n];
        if (el && el.value) p.push(MAP[n] + '=' + encodeURIComponent(el.value));
      });
      window.location.href = TARGET + (TARGET.indexOf('?') >= 0 ? '&' : '?') + p.join('&');
      return;
    }

    errBox.innerHTML = '';
    btn.disabled = true; btn.textContent = '査定中…';

    var fd = new FormData(form);
    fd.append('action', 'fudosan_satei');
    fd.append('nonce', NONCE);
    fd.append('fs_elapsed', String(Date.now() - LOADED_AT));   // 表示から送信までの経過ms（ボット判定）

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
        + (st.trim() ? '<tr><th>最寄駅</th><td>'+st+'</td></tr>' : '')
        + (d.purpose ? '<tr><th>利用目的</th><td>'+esc(d.purpose)+'</td></tr>' : '');
      html = '<h3 style="margin-top:0">査定結果</h3>'
        + '<p>ご入力の条件に基づく<strong>参考価格</strong>は以下の通りです。</p>'
        + '<div class="fs-price">'+esc(d.low_man)+' 〜 '+esc(d.high_man)+'</div>'
        + '<p class="fs-mid">中央値の目安：'+esc(d.mid_man)+' ／ 使用事例 '+esc(d.sample_size)+'件</p>'
        + '<table class="fs-spec">'+rows+'</table>'
        + '<p class="fs-hint">'+esc(d.reason)+'</p>'
        + (d.caution ? '<div class="fs-caution">'+esc(d.caution).replace(/\n/g,'<br>')+'</div>' : '')
        + disc
        + (d.mail_ok ? '<p class="fs-ok">✓ '+esc(d.email)+' 宛に査定結果をメールで送信しました。</p>'
                     : '<p class="fs-err">メール送信に失敗しました。時間をおいて再度お試しください。</p>');
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
