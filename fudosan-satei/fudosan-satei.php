<?php
/**
 * Plugin Name: かんたん不動産AI査定
 * Description: 匿名の不動産価格査定フォーム。国交省「不動産情報ライブラリ」の実成約事例から参考価格レンジを算出し、結果をメール送信＋リード保存。ショートコード [fudosan_satei] をページに貼るだけ。
 * Version: 1.0.0
 * Author: (運営者)
 * License: GPLv2 or later
 * Text Domain: fudosan-satei
 *
 * ★法的注意: 本プラグインが出すのは宅建業の「価格査定（参考価格）」であり、
 *   不動産鑑定士の「鑑定評価」ではない。UI・メール・免責文で明示している。
 *   公開前に弁護士等の確認を推奨。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FS_VER', '1.0.0');
define('FS_OPT', 'fudosan_satei_options');
define('FS_ENDPOINT', 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT001');

/**
 * 自動更新サーバーの update.json の URL。
 * ここに新バージョン情報を置くと、WP管理画面に「更新可能」バッジが出て
 * ワンクリック更新できる（毎回の手動zipアップロード不要）。
 * ※ 置き場が決まったら差し替え（GitHub Raw / 自社サーバー等）。空なら自動更新は無効。
 */
define('FS_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/fudosan-satei-plugin/main/update.json');

/* 自動更新チェッカー（管理画面のみ） */
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
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        email VARCHAR(191) NOT NULL,
        pref VARCHAR(50), city VARCHAR(50), ptype VARCHAR(20),
        area FLOAT, build_year INT, station_min INT,
        low BIGINT, mid BIGINT, high BIGINT,
        sample_size INT, marketing_opt_in TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/* =========================================================================
 * 2. 設定（APIキー・運営者情報）
 * ======================================================================= */
function fs_opt($key, $default = '') {
    $o = get_option(FS_OPT, array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

add_action('admin_menu', function () {
    add_options_page('匿名不動産AI査定', '匿名不動産AI査定', 'manage_options', 'fudosan-satei', 'fs_settings_page');
    add_management_page('査定リード一覧', '査定リード一覧', 'manage_options', 'fudosan-satei-leads', 'fs_leads_page');
});

add_action('admin_init', function () {
    register_setting('fs_group', FS_OPT, 'fs_sanitize_options');
});

function fs_sanitize_options($in) {
    return array(
        'api_key'          => sanitize_text_field($in['api_key'] ?? ''),
        'use_mock'         => !empty($in['use_mock']) ? '1' : '',
        'site_name'        => sanitize_text_field($in['site_name'] ?? 'かんたん不動産AI査定'),
        'operator_name'    => sanitize_text_field($in['operator_name'] ?? ''),
        'operator_contact' => sanitize_text_field($in['operator_contact'] ?? ''),
        'from_email'       => sanitize_email($in['from_email'] ?? get_option('admin_email')),
        'privacy_url'      => esc_url_raw($in['privacy_url'] ?? ''),
        'terms_url'        => esc_url_raw($in['terms_url'] ?? ''),
    );
}

function fs_settings_page() { ?>
    <div class="wrap">
        <h1>かんたん不動産AI査定 設定</h1>
        <p>ページに <code>[fudosan_satei]</code> を貼ると査定フォームが表示されます。</p>
        <form method="post" action="options.php">
            <?php settings_fields('fs_group'); ?>
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
            <?php submit_button(); ?>
        </form>
    </div>
<?php }

function fs_leads_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_satei_leads';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    echo '<div class="wrap"><h1>査定リード一覧（最新200件）</h1><table class="widefat striped"><thead><tr>';
    echo '<th>日時</th><th>メール</th><th>所在地</th><th>種別</th><th>面積</th><th>築年</th><th>レンジ(万円)</th><th>事例数</th><th>営業可</th></tr></thead><tbody>';
    if ($rows) foreach ($rows as $r) {
        printf('<tr><td>%s</td><td>%s</td><td>%s %s</td><td>%s</td><td>%s㎡</td><td>%s</td><td>%s〜%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($r->created_at), esc_html($r->email), esc_html($r->pref), esc_html($r->city),
            esc_html($r->ptype), esc_html($r->area), esc_html($r->build_year ?: '-'),
            $r->low ? number_format($r->low/10000) : '-', $r->high ? number_format($r->high/10000) : '-',
            esc_html($r->sample_size), $r->marketing_opt_in ? '○' : '');
    } else echo '<tr><td colspan="9">まだありません</td></tr>';
    echo '</tbody></table></div>';
}

/* =========================================================================
 * 3. 都道府県・市区町村（デモ用プリセット。全国対応は市区町村一覧API/XIT002へ）
 * ======================================================================= */
function fs_prefs() {
    return array('13' => '東京都', '14' => '神奈川県', '27' => '大阪府', '23' => '愛知県', '40' => '福岡県');
}
function fs_cities() {
    return array(
        '13' => array(array('13101','千代田区'),array('13103','港区'),array('13104','新宿区'),array('13110','目黒区'),array('13112','世田谷区'),array('13113','渋谷区'),array('13115','杉並区'),array('13116','豊島区'),array('13120','練馬区'),array('13201','八王子市'),array('13203','武蔵野市')),
        '14' => array(array('14103','横浜市西区'),array('14104','横浜市中区'),array('14131','川崎市川崎区'),array('14201','横須賀市'),array('14204','鎌倉市'),array('14205','藤沢市')),
        '27' => array(array('27127','大阪市北区'),array('27128','大阪市中央区'),array('27140','堺市堺区'),array('27203','豊中市'),array('27207','吹田市')),
        '23' => array(array('23106','名古屋市昭和区'),array('23113','名古屋市名東区'),array('23201','豊橋市')),
        '40' => array(array('40131','福岡市博多区'),array('40133','福岡市中央区'),array('40203','北九州市門司区')),
    );
}
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

function fs_estimate($records, $ptype, $area, $year) {
    $type = $GLOBALS['FS_PTYPE_MAP'][$ptype] ?? '';
    $same = array_values(array_filter($records, function ($r) use ($type) {
        return ($r['Type'] ?? '') === $type;
    }));

    $filters = array();

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
    $recs = array(); $y = $latest_year; $q = 4;
    for ($i = 0; $i < $quarters_back; $i++) {
        $recs = array_merge($recs, fs_fetch_records($city, $y, $q));
        if (--$q === 0) { $q = 4; $y--; }
    }
    return $recs;
}

function fs_mock_records($city) {
    $seed = ctype_digit(substr($city, -1)) ? intval(substr($city, -1)) : 3;
    $base = 700000 + $seed * 40000;
    $recs = array();
    $man = array(array(70,'令和3年',1.05),array(75,'平成28年',0.98),array(65,'平成22年',0.90),array(80,'令和5年',1.10),
                 array(72,'平成18年',0.85),array(68,'平成30年',1.00),array(85,'令和1年',1.02),array(60,'平成15年',0.80),
                 array(70,'平成27年',0.95),array(78,'令和2年',1.08));
    foreach ($man as $m) {
        $unit = (int)($base * $m[2]);
        $recs[] = array('Type'=>'中古マンション等','TradePrice'=>(string)((int)($m[0]*$unit)),'UnitPrice'=>'','Area'=>(string)$m[0],'BuildingYear'=>$m[1],'FloorPlan'=>'3LDK','Structure'=>'ＲＣ');
    }
    foreach (array(array(110,'令和2年',42000000),array(130,'平成27年',38000000),array(100,'平成20年',33000000),array(150,'令和4年',52000000),array(120,'平成25年',40000000),array(105,'令和1年',36000000)) as $h) {
        $recs[] = array('Type'=>'宅地(土地と建物)','TradePrice'=>(string)$h[2],'UnitPrice'=>'','Area'=>(string)$h[0],'BuildingYear'=>$h[1],'Structure'=>'木造');
    }
    foreach (array(array(120,28000000),array(140,31000000),array(100,24000000),array(165,37000000),array(110,26000000),array(130,30000000)) as $l) {
        $recs[] = array('Type'=>'宅地(土地)','TradePrice'=>(string)$l[1],'UnitPrice'=>'','Area'=>(string)$l[0],'BuildingYear'=>'');
    }
    return $recs;
}

/* =========================================================================
 * 6. メール本文
 * ======================================================================= */
function fs_mail_body($ctx) {
    $site = fs_opt('site_name', 'AI査定');
    $lines = array(
        "【{$site}】査定結果のお知らせ", "",
        "この度はご利用ありがとうございます。ご入力の内容に基づく参考価格は以下の通りです。", "",
        "■ 物件種別 : {$ctx['ptype_label']}",
        "■ 所在地   : {$ctx['pref']} {$ctx['city']}",
        "■ 面積     : {$ctx['area']} ㎡",
    );
    if (!empty($ctx['build_year'])) $lines[] = "■ 築年     : {$ctx['build_year']}年";
    $lines = array_merge($lines, array(
        "",
        "─────────────────────",
        "  参考価格レンジ : {$ctx['low_man']} 〜 {$ctx['high_man']}",
        "  中央値の目安   : {$ctx['mid_man']}",
        "─────────────────────",
        "", $ctx['reason'], "",
        "───────────────────────────────",
        "【重要なご注意】",
        "・本結果はAIによる簡易的な『参考価格（価格査定）』であり、",
        "  不動産鑑定士による『鑑定評価』ではありません。",
        "・過去の周辺取引事例からの機械的な推定値で、実際の売却価格・",
        "  成約価格を保証するものではありません。",
        "・正確な価格は、現地確認を含む個別査定が必要です。",
        "───────────────────────────────",
    ));
    $op = fs_opt('operator_name'); $ct = fs_opt('operator_contact');
    if ($op || $ct) { $lines[] = ""; $lines[] = $op; $lines[] = "お問い合わせ: {$ct}"; }
    return implode("\n", $lines);
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
    $res = fs_estimate($records, $ptype, $area, $byear);

    $pref_name = fs_prefs()[$pref];
    $city_name = fs_city_name($pref, $city);

    // リード保存（事例不足でも保存＝営業価値）
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'fudosan_satei_leads', array(
        'created_at' => current_time('mysql'), 'email' => $email,
        'pref' => $pref_name, 'city' => $city_name, 'ptype' => $ptype,
        'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
        'low' => $res['low'] ?? null, 'mid' => $res['mid'] ?? null, 'high' => $res['high'] ?? null,
        'sample_size' => $res['sample_size'] ?? 0, 'marketing_opt_in' => $mkt ? 1 : 0,
    ));

    $label = $GLOBALS['FS_PTYPE_LABEL'][$ptype];

    if (!$res['ok']) {
        wp_send_json(array('ok' => false, 'insufficient' => true, 'reason' => $res['reason'], 'email' => $email));
    }

    $ctx = array(
        'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name, 'area' => $area, 'build_year' => $byear,
        'low_man' => fs_yen_man($res['low']), 'mid_man' => fs_yen_man($res['mid']), 'high_man' => fs_yen_man($res['high']),
        'reason' => $res['reason'],
    );
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $from = fs_opt('from_email'); $site = fs_opt('site_name', 'AI査定');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $mail_ok = wp_mail($email, "【{$site}】査定結果のお知らせ", fs_mail_body($ctx), $headers);

    wp_send_json(array(
        'ok' => true, 'mail_ok' => (bool)$mail_ok, 'email' => $email,
        'ptype_label' => $label, 'pref' => $pref_name, 'city' => $city_name,
        'area' => $area, 'build_year' => $byear, 'station_min' => $smin,
        'low_man' => $ctx['low_man'], 'mid_man' => $ctx['mid_man'], 'high_man' => $ctx['high_man'],
        'sample_size' => $res['sample_size'], 'reason' => $res['reason'],
    ));
}

/* =========================================================================
 * 8. ショートコード [fudosan_satei]
 * ======================================================================= */
add_shortcode('fudosan_satei', 'fs_shortcode');
function fs_shortcode() {
    $prefs  = fs_prefs();
    $cities = fs_cities();
    $nonce  = wp_create_nonce('fudosan_satei');
    $ajax   = admin_url('admin-ajax.php');
    $year   = intval(date('Y'));
    $privacy = fs_opt('privacy_url');
    $terms   = fs_opt('terms_url');

    $pref_options = '<option value="">選択</option>';
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

    $cities_json = wp_json_encode($cities);

    ob_start(); ?>
<div class="fs-wrap">
  <style>
    .fs-wrap{--fs-brand:#1f6feb;--fs-ink:#1a1f36;--fs-muted:#6b7280;--fs-line:#e5e7eb;max-width:640px;margin:0 auto;color:var(--fs-ink);font-family:inherit;line-height:1.7}
    .fs-card{background:#fff;border:1px solid var(--fs-line);border-radius:14px;padding:22px 20px}
    .fs-wrap label{display:block;font-weight:600;margin:14px 0 5px;font-size:.93rem}
    .fs-req{color:#c0392b;font-size:.8rem;margin-left:4px}
    .fs-wrap input,.fs-wrap select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:1rem;background:#fff;box-sizing:border-box}
    .fs-row{display:flex;gap:12px}.fs-row>div{flex:1}
    .fs-hint{color:var(--fs-muted);font-size:.8rem;margin-top:4px}
    .fs-check{display:flex;gap:9px;align-items:flex-start;margin-top:14px}
    .fs-check input{width:auto;margin-top:5px}.fs-check label{margin:0;font-weight:400;font-size:.9rem}
    .fs-wrap button{margin-top:22px;width:100%;background:var(--fs-brand);color:#fff;border:0;border-radius:10px;padding:15px;font-size:1.05rem;font-weight:700;cursor:pointer}
    .fs-wrap button:disabled{opacity:.6;cursor:wait}
    .fs-disc{background:#fff8e6;border:1px solid #f0e0a8;border-radius:10px;padding:14px 16px;font-size:.82rem;color:#6b5a12;margin-top:18px}
    .fs-err{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;padding:10px 12px;border-radius:9px;margin-bottom:10px;font-size:.9rem}
    .fs-price{font-size:1.9rem;font-weight:800;color:var(--fs-brand);text-align:center;margin:6px 0}
    .fs-mid{text-align:center;color:var(--fs-muted);font-size:.9rem}
    .fs-spec{width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem}
    .fs-spec th,.fs-spec td{border-bottom:1px solid var(--fs-line);padding:8px 6px;text-align:left}
    .fs-spec th{color:var(--fs-muted);font-weight:600;width:38%}
    .fs-ok{color:#0a7d33;font-weight:600}
  </style>

  <div class="fs-card" id="fs-form-card">
    <div id="fs-errors"></div>
    <form id="fs-form">
      <label>物件種別<span class="fs-req">必須</span></label>
      <select name="ptype" required><?php echo $ptype_options; ?></select>

      <div class="fs-row">
        <div>
          <label>都道府県<span class="fs-req">必須</span></label>
          <select name="pref_code" id="fs-pref" required><?php echo $pref_options; ?></select>
        </div>
        <div>
          <label>市区町村<span class="fs-req">必須</span></label>
          <select name="city_code" id="fs-city" required><option value="">先に都道府県を選択</option></select>
        </div>
      </div>

      <div class="fs-row">
        <div>
          <label>面積（㎡）<span class="fs-req">必須</span></label>
          <input type="number" name="area" step="0.01" min="1" placeholder="例：70" required>
          <div class="fs-hint">マンション・戸建は専有/延床、土地は敷地面積</div>
        </div>
        <div>
          <label>築年（西暦）</label>
          <input type="number" name="build_year" min="1950" max="<?php echo $year; ?>" placeholder="例：2015">
          <div class="fs-hint">土地の場合は不要</div>
        </div>
      </div>

      <label>最寄駅まで徒歩（分）<span class="fs-hint" style="font-weight:400">任意</span></label>
      <input type="number" name="station_min" min="0" max="60" placeholder="例：8">

      <label>結果をお届けするメールアドレス<span class="fs-req">必須</span></label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <div class="fs-check">
        <input type="checkbox" name="agree" id="fs-agree" value="1" required>
        <label for="fs-agree"><?php echo $agree_label; ?></label>
      </div>
      <div class="fs-check">
        <input type="checkbox" name="marketing" id="fs-mkt" value="1">
        <label for="fs-mkt">売却に関するご提案・お役立ち情報のメール受け取りを希望します（任意）</label>
      </div>

      <button type="submit" id="fs-submit">無料で査定結果を受け取る</button>
    </form>

    <div class="fs-disc">
      本サービスの結果はAIによる簡易的な<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。実際の売却価格を保証するものではありません。
    </div>
  </div>

  <div class="fs-card" id="fs-result" style="display:none"></div>
</div>

<script>
(function(){
  var CITIES = <?php echo $cities_json; ?>;
  var AJAX = <?php echo wp_json_encode($ajax); ?>;
  var NONCE = <?php echo wp_json_encode($nonce); ?>;
  var wrap = document.currentScript.closest('.fs-wrap');
  var pref = wrap.querySelector('#fs-pref'), city = wrap.querySelector('#fs-city');
  var form = wrap.querySelector('#fs-form'), errBox = wrap.querySelector('#fs-errors');
  var formCard = wrap.querySelector('#fs-form-card'), resultCard = wrap.querySelector('#fs-result');
  var btn = wrap.querySelector('#fs-submit');

  pref.addEventListener('change', function(){
    var list = CITIES[pref.value] || [];
    city.innerHTML = '<option value="">選択してください</option>' +
      list.map(function(c){ return '<option value="'+c[0]+'">'+c[1]+'</option>'; }).join('');
  });

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  form.addEventListener('submit', function(e){
    e.preventDefault();
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
      var rows = '<tr><th>物件種別</th><td>'+esc(d.ptype_label)+'</td></tr>'
        + '<tr><th>所在地</th><td>'+esc(d.pref)+' '+esc(d.city)+'</td></tr>'
        + '<tr><th>面積</th><td>'+esc(d.area)+' ㎡</td></tr>'
        + (d.build_year ? '<tr><th>築年</th><td>'+esc(d.build_year)+'年</td></tr>' : '')
        + (d.station_min ? '<tr><th>最寄駅</th><td>徒歩'+esc(d.station_min)+'分</td></tr>' : '');
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
})();
</script>
<?php
    return ob_get_clean();
}
