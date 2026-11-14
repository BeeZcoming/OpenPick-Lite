<?php
/**
 * OpenPick Lite · 公共库
 * 只做四件事：读写 JSON、取字典、渲染广告、渲染评论。
 * build.php 与 admin/index.php 都 require 它，没有第二份重复代码。
 */
declare(strict_types=1);

define('OP_ROOT', dirname(__DIR__));

function jread(string $rel): array {
    $p = OP_ROOT . '/data/' . $rel . '.json';
    if (!is_file($p)) return [];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function jwrite(string $rel, array $data): bool {
    $p = OP_ROOT . '/data/' . $rel . '.json';
    @mkdir(dirname($p), 0775, true);
    $tmp = $p . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $p);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function jout($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/** 中英字典，缺键自动回退英文 */
function dict(string $lang): array {
    $d = jread('i18n/' . $lang);
    $en = $lang === 'en' ? [] : jread('i18n/en');
    return array_merge($en['t'] ?? [], $d['t'] ?? []);
}
function t(string $lang, string $key): string {
    static $cache = [];
    if (!isset($cache[$lang])) $cache[$lang] = dict($lang);
    return (string)($cache[$lang][$key] ?? $key);
}
function cfg(): array { return jread('settings'); }
function langs(): array { return ['zh', 'en']; }

/* ------------------------------------------------------------------ 广告 */

function ts(?string $s): int {
    if (!$s) return 0;
    $v = strtotime($s);
    return $v === false ? 0 : $v;
}

/** 返回 '' = 整段 DOM 不输出（关闭 / 无有效创意），不是 display:none */
function ad_render(string $slot, string $lang, ?int $now = null): string {
    $now = $now ?? time();
    $ads = jread('ads');
    // 全局总开关（后台「广告」页第一项）；老配置没有该字段时默认开启，避免升级后全站空白
    if (array_key_exists('enabled', $ads) && empty($ads['enabled'])) return '';
    $enabled = false;
    foreach ($ads['slots'] ?? [] as $s) {
        if (($s['id'] ?? '') === $slot && !empty($s['enabled'])) { $enabled = true; break; }
    }
    if (!$enabled) return '';

    $cands = [];
    foreach ($ads['campaigns'] ?? [] as $c) {
        if (($c['slot'] ?? '') !== $slot || empty($c['enabled'])) continue;
        if (!empty($c['langs']) && !in_array($lang, $c['langs'], true)) continue;
        $end = ts($c['end_at'] ?? null);
        if ($end && $end < $now) continue;                 // 已结束：构建期直接丢弃
        $start = ts($c['start_at'] ?? null);
        if ($start && $start > $now + 400 * 86400) continue; // 太远的未来不预置
        $cands[] = $c;
    }
    if (!$cands) return '';
    usort($cands, function ($a, $b) {
        return (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0);
    });

    $label = $lang === 'zh' ? '广告' : 'Ad';
    $variants = ''; $anyLive = false;
    foreach ($cands as $i => $c) {
        $st = ts($c['start_at'] ?? null); $en = ts($c['end_at'] ?? null);
        $live = (!$st || $st <= $now) && (!$en || $en >= $now);
        if ($live) $anyLive = true;
        $txt = $c['i18n'][$lang] ?? $c['i18n']['en'] ?? $c['i18n']['zh'] ?? [];
        $url = (string)($c['url'] ?? '#');
        $ext = (bool)preg_match('~^https?://~', $url);
        $rel = $ext ? ' rel="noopener nofollow sponsored"' : '';
        $tgt = $ext ? ' target="_blank"' : '';
        $hidden = ($live && $i === 0) ? '' : ' hidden';
        $variants .= '<div class="ad-v" data-start="' . h($c['start_at'] ?? '') . '" data-end="' . h($c['end_at'] ?? '') . '"' . $hidden . '>'
            . '<a href="' . h($url) . '"' . $tgt . $rel . '>'
            . '<h4>' . h($txt['title'] ?? '') . '</h4><p>' . h($txt['text'] ?? '') . '</p>'
            . '<span class="ad-cta">' . h($txt['cta'] ?? '↗') . '</span></a></div>';
    }
    return '<aside class="ad" data-slot="' . h($slot) . '"' . ($anyLive ? '' : ' hidden') . '>'
        . '<div class="ad-hd"><span>' . $label . '</span></div>' . $variants . '</aside>';
}

/**
 * 按 <!-- AD:slot --> 锚点注入。广告位由 data/ads.json 的 slots 动态决定。
 * $allowed = 本页允许出现的广告位（必须传），否则详情页专属位会跑到首页。
 * 规则：只替换首个锚点；锚点不存在就跳过（不兜底插 </main>，避免串页）；
 * 关闭 / 无有效创意时锚点与容器一起删除，整段 DOM 不输出。
 */
function ad_inject(string $html, string $lang, array $allowed): string {
    $all = [];
    foreach (jread('ads')['slots'] ?? [] as $def) {
        $id = (string)($def['id'] ?? '');
        if ($id !== '') $all[] = $id;
    }
    foreach ($all as $s) {
        if (!in_array($s, $allowed, true)) continue;
        if (strpos($html, 'data-slot="' . $s . '"') !== false) continue; // 幂等
        $block = ad_render($s, $lang);
        $tag = '<!-- AD:' . $s . ' -->';
        if ($block === '') { $html = str_replace($tag, '', $html); continue; }
        $pos = strpos($html, $tag);
        if ($pos !== false) $html = substr_replace($html, $block, $pos, strlen($tag));
    }
    // 清掉本页未使用 / 已停用 / 重复锚点的剩余注释，避免源码里留痕
    foreach ($all as $s) $html = str_replace('<!-- AD:' . $s . ' -->', '', $html);
    return $html;
}

/* ------------------------------------------------------- 代理 / 下载通道 */

/** 读取并兜底代理配置；老项目缺 proxies.json 时也能安全运行 */
function proxies_cfg(): array {
    $p = jread('proxies');
    if (empty($p['channels']) || !is_array($p['channels'])) {
        $p['channels'] = [['id' => 'official', 'name' => '官方直连', 'prefix' => '', 'enabled' => true, 'builtin' => true]];
    }
    $official = false;
    foreach ($p['channels'] as $c) if (($c['id'] ?? '') === 'official') $official = true;
    if (!$official) array_unshift($p['channels'], ['id' => 'official', 'name' => '官方直连', 'prefix' => '', 'enabled' => true, 'builtin' => true]);
    if (empty($p['readme']) || !is_array($p['readme'])) {
        $p['readme'] = ['image_proxy' => '', 'whitelist' => ['raw.githubusercontent.com', 'github.com', 'user-images.githubusercontent.com', 'cdn.jsdelivr.net'], 'timeout' => 8, 'max_bytes' => 200000];
    }
    return $p;
}

/** 当前可用通道（按后台顺序）。总开关关闭时只保留官方直连，README 不受影响 */
function proxy_channels(): array {
    $p = proxies_cfg();
    $chs = array_values(array_filter($p['channels'], function ($c) { return !empty($c['enabled']); }));
    if (empty($p['enabled'])) {
        foreach ($chs as $c) if (($c['id'] ?? '') === 'official') return [$c];
        return [['id' => 'official', 'name' => '官方直连', 'prefix' => '', 'enabled' => true, 'builtin' => true]];
    }
    return $chs;
}

/** 下载区通道切换器。切换只改写 href，零网络请求；无 Release 时构建期根本不调用 */
function proxy_block(string $lang): string {
    $chs = proxy_channels();
    if (count($chs) < 2) return '';
    $def = (string)(proxies_cfg()['default'] ?? 'official');
    $label = $lang === 'zh' ? '下载通道' : 'Mirror';
    $hint  = $lang === 'zh' ? '切换后所有下载链接即时改写，文件不变' : 'All links rewritten instantly; same file';
    $opts = '';
    foreach ($chs as $c) {
        $opts .= '<option value="' . h($c['prefix'] ?? '') . '"' . (($c['id'] ?? '') === $def ? ' selected' : '') . '>'
               . h($c['name'] ?? $c['id']) . '</option>';
    }
    return '<div class="proxy-bar"><label>' . $label . '</label><select id="pxSel">' . $opts
         . '</select><span class="hint">' . $hint . '</span></div>';
}

/**
 * README 图片：相对路径 → GitHub 绝对地址 → 白名单校验 → 可选代理前缀。
 * 返回 '' 表示不在白名单，构建期直接拦掉，不让访客加载任意第三方图片。
 */
function readme_image_url(string $url, string $full): string {
    $r = proxies_cfg()['readme'];
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://raw.githubusercontent.com/' . $full . '/HEAD/' . ltrim($url, '/');
    }
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $wl = array_map('strtolower', (array)($r['whitelist'] ?? []));
    if ($host === '' || !in_array($host, $wl, true)) return '';
    $px = trim((string)($r['image_proxy'] ?? ''));
    return $px !== '' ? $px . rawurlencode($url) : $url;
}

/* ---------------------------------------------------------------- Waline */

/**
 * 评论 + 点赞（文章反应）走 Waline。
 * 关闭或没填 serverURL 时返回 ''，构建期整段 DOM 删除，访客侧 0 外部请求。
 * path 固定成 /project/{rid}：中英两页共用同一串评论，不会被语言路径切开。
 */
function waline_block(string $lang, string $rid, string $canonical): string {
    $w = jread('waline');
    if (empty($w['enabled'])) return '';
    $server = rtrim((string)($w['serverURL'] ?? ''), '/');
    if ($server === '') return '';

    // 客户端选项全部在构建期固化；后台只存公开值，敏感密钥在 Waline 服务端
    $options = [
        'el'          => '#waline',
        'serverURL'   => $server,
        'path'        => '/project/' . $rid,
        'lang'        => $lang === 'zh' ? 'zh-CN' : 'en',
        'dark'        => 'html[data-theme="dark"]',   // 跟随站点深浅主题按钮
        'reaction'    => !empty($w['reaction']),      // 点赞 / 表情反应
        'pageSize'    => max(1, (int)($w['pageSize'] ?? 10)),
        'wordLimit'   => (int)($w['wordLimit'] ?? 0),
        'login'       => (string)($w['login'] ?? 'enable'),
        'emoji'       => $w['emoji'] ?? ['https://unpkg.com/@waline/emojis@1.1.0/weibo'],
    ];
    if (!empty($w['turnstile_sitekey'])) $options['turnstileKey'] = (string)$w['turnstile_sitekey'];

    $cfg = [
        'js'      => (string)($w['client_js']  ?? 'https://unpkg.com/@waline/client@v3/dist/waline.js'),
        'css'     => (string)($w['client_css'] ?? 'https://unpkg.com/@waline/client@v3/dist/waline.css'),
        'options' => $options,
    ];
    return '<section class="comm" id="s-comments"><h2>' . h(t($lang, 'w.title')) . '</h2>'
        . '<p class="comm-hint">' . h(t($lang, 'w.hint')) . '</p>'
        . '<button id="wLoad" class="btn">' . h(t($lang, 'w.join')) . '</button>'
        . '<div id="waline"></div>'
        . '<script>window.OP_WALINE=' . jout($cfg) . ';</script>'
        . '</section>';
}
