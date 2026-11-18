<?php
/**
 * スマホ下固定CTA（匿名査定／査定書の作成）
 *
 * 記事を読んでいる途中の読者に、画面下から査定の入口を出す。
 * PCは既存のヘッダーボタンとサイドバーのCTAに任せるため、781px以下でだけ出す。
 *
 * ★設計上の決めごと:
 *   - 出す条件はPHPで判定し、HTMLは wp_footer に1回だけ出す。
 *     JSは「出す・引っ込める」の切り替えだけを行う（キャッシュと干渉しない）。
 *   - 色・文言・遷移先は fs_spcta_config フィルターで差し替えられる。
 *   - 記事下のCTAやフッターが画面に入ったら引っ込める。同じ案内を二重に出さない。
 *   - 端末側で「視差効果を減らす」を入れている方には動かさない。
 */

if (!defined('ABSPATH')) exit;

/**
 * 設定。テーマの functions.php から fs_spcta_config で上書きできる。
 */
function fs_spcta_config() {
    return apply_filters('fs_spcta_config', array(
        'enabled'    => true,
        'main_url'   => home_url('/tokumei-satei/'),
        'main_title' => '匿名査定',
        'main_badge' => '無料',
        'main_note'  => '氏名・電話番号なし',
        'main_color' => '#ca4409',
        'sub_url'    => home_url('/sateisho-sakusei/'),
        'sub_title'  => '査定書の作成',
        'sub_note'   => '離婚・相続の資料に',
        'sub_color'  => '#0652c9',
        /* 画面をこれだけ下げたら出す（px） */
        'show_after' => 400,
        /* この幅以下でだけ出す（px） */
        'max_width'  => 781,
        /* バーの高さぶん、本文の下に空ける余白（px） */
        'reserve'    => 86,
        /* 出さないページのスラッグ。いま開いているページへ誘わないため */
        'exclude'    => array('tokumei-satei', 'sateisho-sakusei', 'contact', 'privacy-policy', 'terms'),
        /* これが画面に入ったら引っ込める */
        'hide_near'  => '.sb-cta, .ftl-cta, .diver_cta, #default-footer, .l-footer, footer.footer',
    ));
}

/**
 * このページで出すかどうか。
 */
function fs_spcta_should_show() {
    $c = fs_spcta_config();
    if (empty($c['enabled']))              return false;
    if (is_admin() || is_feed() || is_404()) return false;
    if (!is_singular() || is_front_page())  return false;

    $post = get_queried_object();
    if ($post instanceof WP_Post && in_array($post->post_name, (array) $c['exclude'], true)) {
        return false;
    }
    return (bool) apply_filters('fs_spcta_should_show', true, $post);
}

/**
 * バーの高さぶんの余白を本文の下に足す目印。
 */
add_filter('body_class', function ($classes) {
    if (fs_spcta_should_show()) $classes[] = 'fs-spcta-on';
    return $classes;
});

/**
 * 見た目。
 */
add_action('wp_head', function () {
    if (!fs_spcta_should_show()) return;
    $c  = fs_spcta_config();
    $mw = (int) $c['max_width'];
    $rv = (int) $c['reserve'];
    $ease = 'cubic-bezier(.22,.7,.3,1)';
    ?>
<style id="fs-spcta-css">
.fs-spcta{position:fixed;left:0;right:0;bottom:0;z-index:9000;display:none;gap:8px;
 box-sizing:border-box;padding:9px 10px calc(11px + env(safe-area-inset-bottom,0px));
 background:rgba(255,255,255,.98);border-top:1px solid #d9dde3;
 box-shadow:0 -6px 18px rgba(16,24,40,.12);
 opacity:0;transform:translateY(20px);
 transition:opacity .14s <?php echo $ease; ?>,transform .14s <?php echo $ease; ?>}
.fs-spcta.is-in{opacity:1;transform:none;
 transition-duration:.24s}
.fs-spcta a{text-decoration:none;display:block;border-radius:10px;line-height:1.3;
 opacity:0;transform:translateY(8px);
 transition:opacity .34s <?php echo $ease; ?>,transform .34s <?php echo $ease; ?>}
.fs-spcta.is-in a{opacity:1;transform:none}
.fs-spcta.is-in .fs-spcta__main{transition-delay:.06s}
.fs-spcta.is-in .fs-spcta__sub{transition-delay:.13s}
.fs-spcta.is-in a:active{transform:scale(.985);transition-duration:.08s;transition-delay:0s}
.fs-spcta__main{flex:1.12 1 0;min-width:0;padding:9px 11px;
 background:<?php echo esc_html($c['main_color']); ?>;color:#fff}
.fs-spcta__sub{flex:1 1 0;min-width:0;padding:8px 10px;background:#fff;
 color:<?php echo esc_html($c['sub_color']); ?>;
 border:1px solid <?php echo esc_html($c['sub_color']); ?>}
.fs-spcta__row{display:flex;align-items:center;justify-content:space-between;gap:6px}
.fs-spcta__ttl{display:flex;align-items:center;gap:5px;min-width:0;
 font-size:15px;font-weight:800}
.fs-spcta__badge{flex:0 0 auto;background:#fff;
 color:<?php echo esc_html($c['main_color']); ?>;
 font-size:10px;font-weight:800;border-radius:4px;padding:1px 4px}
.fs-spcta__arw{font-size:18px;opacity:.85;font-style:normal}
.fs-spcta__note{display:block;font-size:11px;line-height:1.5;margin-top:2px}
.fs-spcta__main .fs-spcta__note{color:rgba(255,255,255,.95)}
.fs-spcta__sub .fs-spcta__note{color:#41536a}
@keyframes fsSpctaArw{0%,62%{transform:translateX(0)}74%{transform:translateX(4px)}86%,100%{transform:translateX(0)}}
@media (max-width:<?php echo $mw; ?>px){
 .fs-spcta{display:flex}
 body.fs-spcta-on{padding-bottom:<?php echo $rv; ?>px}
 /* ページトップへ戻るボタンをバーの上へ逃がす */
 body.fs-spcta-on .rd-sticky-action{margin-bottom:<?php echo $rv - 10; ?>px}
 .fs-spcta.is-in .fs-spcta__arw{animation:fsSpctaArw 1.5s ease-in-out .5s 2 both}
}
@media (prefers-reduced-motion:reduce){
 .fs-spcta,.fs-spcta a{transition:none}
 .fs-spcta.is-in .fs-spcta__arw{animation:none}
}
</style>
    <?php
}, 99);

/**
 * バー本体と、出し入れの切り替え。
 */
add_action('wp_footer', function () {
    if (!fs_spcta_should_show()) return;
    $c = fs_spcta_config();
    ?>
<div class="fs-spcta" id="fs-spcta" aria-hidden="true"
     data-after="<?php echo (int) $c['show_after']; ?>"
     data-hide-near="<?php echo esc_attr($c['hide_near']); ?>">
  <a class="fs-spcta__main" data-cta="tokumei_satei" href="<?php echo esc_url($c['main_url']); ?>">
    <span class="fs-spcta__row">
      <span class="fs-spcta__ttl"><?php echo esc_html($c['main_title']); ?><?php
        if (!empty($c['main_badge'])) { ?><span class="fs-spcta__badge"><?php echo esc_html($c['main_badge']); ?></span><?php }
      ?></span>
      <i class="fs-spcta__arw" aria-hidden="true">›</i>
    </span>
    <span class="fs-spcta__note"><?php echo esc_html($c['main_note']); ?></span>
  </a>
  <a class="fs-spcta__sub" data-cta="sateisho_sakusei" href="<?php echo esc_url($c['sub_url']); ?>">
    <span class="fs-spcta__row">
      <span class="fs-spcta__ttl"><?php echo esc_html($c['sub_title']); ?></span>
      <i class="fs-spcta__arw" aria-hidden="true">›</i>
    </span>
    <span class="fs-spcta__note"><?php echo esc_html($c['sub_note']); ?></span>
  </a>
</div>
<script id="fs-spcta-js">
(function () {
  var bar = document.getElementById('fs-spcta');
  if (!bar) return;

  var after = parseInt(bar.getAttribute('data-after'), 10) || 400;
  var shown = false;
  var blocked = false;

  function apply() {
    var want = (window.pageYOffset || document.documentElement.scrollTop || 0) > after && !blocked;
    if (want === shown) return;
    shown = want;
    bar.classList.toggle('is-in', want);
    bar.setAttribute('aria-hidden', want ? 'false' : 'true');
  }

  /* 記事下のCTAやフッターが見えているあいだは引っ込める */
  var sel = bar.getAttribute('data-hide-near') || '';
  if (sel && window.IntersectionObserver) {
    var near = document.querySelectorAll(sel);
    if (near.length) {
      var hit = 0;
      var seen = [];
      var io = new IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          var e = entries[i];
          var at = seen.indexOf(e.target);
          if (e.isIntersecting && at < 0) { seen.push(e.target); }
          else if (!e.isIntersecting && at >= 0) { seen.splice(at, 1); }
        }
        hit = seen.length;
        blocked = hit > 0;
        apply();
      }, { rootMargin: '0px 0px -40% 0px' });
      for (var j = 0; j < near.length; j++) { io.observe(near[j]); }
    }
  }

  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    (window.requestAnimationFrame || window.setTimeout)(function () {
      ticking = false;
      apply();
    }, 16);
  }, { passive: true });

  /* どちらのボタンが押されたかをGA4で分けて数える */
  bar.addEventListener('click', function (ev) {
    var a = ev.target;
    while (a && a !== bar && !(a.getAttribute && a.getAttribute('data-cta'))) { a = a.parentNode; }
    if (!a || a === bar) return;
    if (typeof window.gtag === 'function') {
      window.gtag('event', 'sp_cta_click', { cta_name: a.getAttribute('data-cta') });
    }
  });

  apply();
})();
</script>
    <?php
}, 99);
