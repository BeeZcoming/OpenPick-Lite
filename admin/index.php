<?php
/**
 * OpenPick Lite · 管端（单文件）
 * 四个页签：待审核 / 广告 / 加速代理 / 设置。无数据库、无框架、无外部依赖。
 * 保存后直接跑 php build.php，前端在**同一台服务器**立即生效，不需要 git push / CDN。
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/common.php';

$CFG_LOCAL = @include dirname(__DIR__) . '/config.php';
if (!is_array($CFG_LOCAL) || empty($CFG_LOCAL['admins'])) {
    http_response_code(500);
    exit('请先把 config.example.php 复制为 config.php 并填入密码哈希，见 DEPLOY.md 第 4 步。');
}

/* ---------------- 登录：HTTP Basic + session ---------------- */
session_start();
$user = $_SESSION['op_user'] ?? '';
if (!$user || ($_SESSION['op_exp'] ?? 0) < time()) {
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($u === '' || !isset($CFG_LOCAL['admins'][$u]) || !password_verify($p, $CFG_LOCAL['admins'][$u])) {
        header('WWW-Authenticate: Basic realm="OpenPick Admin"');
        http_response_code(401);
        exit('需要登录');
    }
    $user = $u;
    $_SESSION['op_user'] = $u;
}
$_SESSION['op_exp'] = time() + (int)($CFG_LOCAL['session_ttl'] ?? 7200);

/* ---------------- 动作 ---------------- */
function rebuild(array $c): array {
    $php = (string)($c['php_bin'] ?? 'php');
    if ($php !== 'php' && !is_executable($php)) $php = 'php';
    $cmd = ($php === 'php' ? 'php' : escapeshellarg($php)) . ' '
         . escapeshellarg(OP_ROOT . '/build.php') . ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    return [$code === 0, implode("\n", $out)];
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    /* --- 审核 --- */
    if ($act === 'approve' || $act === 'reject') {
        $name = trim((string)($act === 'approve' ? ($_POST['full_name'] ?? '') : ($_POST['rj_name'] ?? '')));
        $inc = jread('incoming'); $items = $inc['items'] ?? [];
        $found = null;
        foreach ($items as $x) if (($x['full_name'] ?? '') === $name) { $found = $x; break; }
        if ($found) {
            if ($act === 'approve') {
                $pj = jread('projects'); $list = $pj['items'] ?? [];
                $exists = false;
                foreach ($list as $x) if ($x['full_name'] === $name) $exists = true;
                if (!$exists) {
                    $rid = 'gh-' . str_replace('/', '-', strtolower($name));
                    $list[] = [
                        'resource_id' => $rid, 'full_name' => $name,
                        'name' => explode('/', $name)[1] ?? $name,
                        'description' => $found['description'] ?? '',
                        'description_zh' => trim((string)($_POST['description_zh'] ?? '')) ?: ($found['description'] ?? ''),
                        'stars' => (int)($found['stars'] ?? 0),
                        'language' => $found['language'] ?? '', 'topics' => $found['topics'] ?? [],
                        'pushed_at' => $found['pushed_at'] ?? '', 'archived' => false,
                        'sources' => $found['sources'] ?? [],
                        'editor_note' => trim((string)($_POST['editor_note'] ?? '')),
                        'editor_note_en' => trim((string)($_POST['editor_note_en'] ?? '')),
                        'category' => trim((string)($_POST['category'] ?? '工具')),
                        'category_en' => trim((string)($_POST['category_en'] ?? 'Tool')),
                        'difficulty' => $_POST['difficulty'] ?? 'beginner',
                        'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))))),
                        'featured' => !empty($_POST['featured']),
                        'release' => $found['release'] ?? null,
                        'accepted_at' => gmdate('Y-m-d\TH:i:s\Z'), 'accepted_by' => $user,
                    ];
                    $pj['items'] = array_values($list); $pj['count'] = count($list);
                    jwrite('projects', $pj);
                }
            } else {
                $rj = jread('rejected'); $r = $rj['names'] ?? [];
                if (!in_array($name, $r, true)) { $r[] = $name; $rj['names'] = $r; jwrite('rejected', $rj); }
            }
            $inc['items'] = array_values(array_filter($items, function ($x) use ($name) { return ($x['full_name'] ?? '') !== $name; }));
            $inc['count'] = count($inc['items']);
            jwrite('incoming', $inc);
            [$ok, $log] = rebuild($CFG_LOCAL);
            $msg = ($act === 'approve' ? '✅ 已入库' : '✅ 已拒绝') . ($ok ? ' · 站点已重建' : ' · ⚠ 重建失败：' . h($log));
        } else { $msg = '⚠ 待审队列里找不到该项目'; }
    }

    /* --- 广告：总开关 / 单广告位开关 / 创意增删改与启停 --- */
    if ($act === 'ads_master' || $act === 'ad_save' || $act === 'ad_del' || $act === 'ad_toggle'
        || $act === 'slot_toggle' || $act === 'slot_add' || $act === 'slot_del') {
        $ads = jread('ads');
        if ($act === 'ads_master') {
            $ads['enabled'] = empty($ads['enabled']);   // 总开关：关掉后所有位置整段 DOM 不输出
        }
        if ($act === 'ad_save') {
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') $id = 'c_' . substr(md5(uniqid('', true)), 0, 8);
            $c = [
                'id' => $id, 'slot' => $_POST['slot'] ?? 'home_top',
                'enabled' => true,
                'start_at' => trim((string)($_POST['start_at'] ?? '')),
                'end_at' => trim((string)($_POST['end_at'] ?? '')),
                'priority' => (int)($_POST['priority'] ?? 1),
                'url' => trim((string)($_POST['url'] ?? '#')),
                'note' => trim((string)($_POST['note'] ?? '')),
                'i18n' => [
                    'zh' => ['title' => $_POST['zh_title'] ?? '', 'text' => $_POST['zh_text'] ?? '', 'cta' => $_POST['zh_cta'] ?? '查看'],
                    'en' => ['title' => $_POST['en_title'] ?? '', 'text' => $_POST['en_text'] ?? '', 'cta' => $_POST['en_cta'] ?? 'Learn more'],
                ],
            ];
            $list = $ads['campaigns'] ?? []; $done = false;
            foreach ($list as $k => $v) if (($v['id'] ?? '') === $id) { $c['enabled'] = !empty($v['enabled']); $list[$k] = $c; $done = true; break; }
            if (!$done) $list[] = $c;
            $ads['campaigns'] = array_values($list);
        } elseif ($act === 'ad_del') {
            $id = (string)($_POST['id'] ?? '');
            $ads['campaigns'] = array_values(array_filter($ads['campaigns'] ?? [], function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));
        } elseif ($act === 'ad_toggle') {
            $id = (string)($_POST['id'] ?? '');
            foreach ($ads['campaigns'] ?? [] as $k => $v) if (($v['id'] ?? '') === $id) $ads['campaigns'][$k]['enabled'] = empty($v['enabled']);
        } elseif ($act === 'slot_toggle') {
            $sid = (string)($_POST['id'] ?? '');
            foreach ($ads['slots'] ?? [] as $k => $v) if (($v['id'] ?? '') === $sid) $ads['slots'][$k]['enabled'] = empty($v['enabled']);
        } elseif ($act === 'slot_add') {
            $sid = preg_replace('~[^a-z0-9_]+~', '_', strtolower(trim((string)($_POST['new_id'] ?? ''))));
            $sname = trim((string)($_POST['new_name'] ?? ''));
            if ($sid !== '' && $sname !== '') {
                $exists = false;
                foreach ($ads['slots'] ?? [] as $v) if (($v['id'] ?? '') === $sid) $exists = true;
                if (!$exists) $ads['slots'][] = ['id' => $sid, 'name' => $sname, 'enabled' => true];
            }
        } elseif ($act === 'slot_del') {
            $sid = (string)($_POST['id'] ?? '');
            // 模板内置的 4 个锚点不允许删，避免前台出现无处注入的位置
            if (!in_array($sid, ['home_top','side','detail_end','footer'], true)) {
                $ads['slots'] = array_values(array_filter($ads['slots'] ?? [],
                    function ($x) use ($sid) { return ($x['id'] ?? '') !== $sid; }));
            }
        }
        jwrite('ads', $ads);
        [$ok, $log] = rebuild($CFG_LOCAL);
        $msg = '✅ 广告已保存' . ($ok ? ' · 站点已重建' : ' · ⚠ 重建失败：' . h($log));
    }

    /* --- 设置 --- */
    if ($act === 'settings') {
        $s = jread('settings');
        $s['site_name'] = trim((string)($_POST['site_name'] ?? $s['site_name']));
        $s['site_url'] = rtrim(trim((string)($_POST['site_url'] ?? $s['site_url'])), '/');
        $s['submit_repo'] = trim((string)($_POST['submit_repo'] ?? $s['submit_repo']));
        $s['default_lang'] = in_array($_POST['default_lang'] ?? '', ['zh','en'], true) ? $_POST['default_lang'] : 'zh';
        $s['default_theme'] = in_array($_POST['default_theme'] ?? '', ['light','dark'], true) ? $_POST['default_theme'] : 'light';
        $s['per_page'] = max(6, min(100, (int)($_POST['per_page'] ?? 24)));
        $s['ranking_limit'] = max(5, min(100, (int)($_POST['ranking_limit'] ?? 20)));
        // 投稿：只走 Cloudflare Function，失败时前端自动回退 GitHub Issue
        $s['submit'] = [
            'mode' => in_array($_POST['sub_mode'] ?? '', ['auto','worker','issue'], true) ? $_POST['sub_mode'] : 'auto',
            'endpoint' => rtrim(trim((string)($_POST['sub_endpoint'] ?? '')), '/'),
            'submit_key' => trim((string)($_POST['sub_key'] ?? '')),
            'turnstile_sitekey' => trim((string)($_POST['sub_turnstile'] ?? '')),
        ];
        jwrite('settings', $s);

        // 评论：Waline（serverURL 指 Waline 服务端；敏感密钥只在服务端）
        $w = jread('waline');
        $w['enabled'] = !empty($_POST['waline_on']);
        $w['serverURL'] = rtrim(trim((string)($_POST['w_serverURL'] ?? '')), '/');
        $w['reaction'] = !empty($_POST['w_reaction']);          // 点赞 / 表情反应
        $w['pageSize'] = max(1, min(50, (int)($_POST['w_pageSize'] ?? 10)));
        $w['wordLimit'] = max(0, (int)($_POST['w_wordLimit'] ?? 0));
        $w['login'] = in_array($_POST['w_login'] ?? '', ['enable','disable','force'], true) ? $_POST['w_login'] : 'enable';
        $w['turnstile_sitekey'] = trim((string)($_POST['w_turnstile'] ?? ''));
        jwrite('waline', $w);

        [$ok, $log] = rebuild($CFG_LOCAL);
        $msg = '✅ 设置已保存' . ($ok ? ' · 站点已重建' : ' · ⚠ 重建失败：' . h($log));
    }

    /* --- 代理：通道增删改 / 启停 / 排序 / 默认 / README 图片白名单 --- */
    if (in_array($act, ['px_master','px_default','px_add','px_del','px_toggle','px_move','px_readme'], true)) {
        $p = proxies_cfg();
        $chs = $p['channels'];
        if ($act === 'px_master') {
            $p['enabled'] = empty($p['enabled']);   // 关闭后只保留官方直连，README 不受影响
        } elseif ($act === 'px_default') {
            $id = (string)($_POST['id'] ?? '');
            foreach ($chs as $c) if (($c['id'] ?? '') === $id && !empty($c['enabled'])) { $p['default'] = $id; break; }
        } elseif ($act === 'px_add') {
            $name = trim((string)($_POST['px_name'] ?? ''));
            $prefix = trim((string)($_POST['px_prefix'] ?? ''));
            if ($prefix !== '' && !preg_match('~/$~', $prefix)) $prefix .= '/';
            if ($name !== '' && preg_match('~^https?://[^/]+/~i', $prefix)) {
                $chs[] = ['id' => 'px_' . substr(md5(uniqid('', true)), 0, 8),
                          'name' => $name, 'prefix' => $prefix, 'enabled' => true];
            }
        } elseif ($act === 'px_del') {
            $id = (string)($_POST['id'] ?? '');
            if ($id !== 'official') {   // 官方直连不可删除，永远留兜底
                $chs = array_values(array_filter($chs, function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));
            }
            if (($p['default'] ?? '') === $id) $p['default'] = 'official';
        } elseif ($act === 'px_toggle') {
            $id = (string)($_POST['id'] ?? '');
            if ($id !== 'official') {
                foreach ($chs as $k => $c) if (($c['id'] ?? '') === $id) $chs[$k]['enabled'] = empty($c['enabled']);
            }
        } elseif ($act === 'px_move') {
            $id = (string)($_POST['id'] ?? '');
            $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
            foreach ($chs as $k => $c) {
                if (($c['id'] ?? '') === $id) {
                    $j = $k + $dir;
                    if ($j >= 0 && $j < count($chs)) { $tmp = $chs[$k]; $chs[$k] = $chs[$j]; $chs[$j] = $tmp; }
                    break;
                }
            }
            $chs = array_values($chs);
        } elseif ($act === 'px_readme') {
            $p['readme']['image_proxy'] = trim((string)($_POST['px_image_proxy'] ?? ''));
            $wl = preg_split('~[\r\n,]+~', (string)($_POST['px_whitelist'] ?? ''));
            $p['readme']['whitelist'] = array_values(array_unique(array_filter(array_map(function ($x) {
                $x = strtolower(trim((string)$x));
                $x = preg_replace('~^https?://~i', '', $x);
                return rtrim(preg_replace('~/.*$~', '', $x), '/');
            }, (array)$wl))));
            $p['readme']['timeout']   = max(2, min(20, (int)($_POST['px_timeout'] ?? 8)));
            $p['readme']['max_bytes'] = max(20000, min(1000000, (int)($_POST['px_max_bytes'] ?? 200000)));
        }
        $p['channels'] = array_values($chs);
        $okDef = false;
        foreach ($p['channels'] as $c) if (($c['id'] ?? '') === ($p['default'] ?? '') && !empty($c['enabled'])) $okDef = true;
        if (!$okDef) $p['default'] = 'official';
        jwrite('proxies', $p);
        [$ok, $log] = rebuild($CFG_LOCAL);
        $msg = '✅ 代理配置已保存' . ($ok ? ' · 站点已重建' : ' · ⚠ 重建失败：' . h($log));
    }

    /* --- 手动重建 --- */
    if ($act === 'rebuild') {
        [$ok, $log] = rebuild($CFG_LOCAL);
        $msg = $ok ? '✅ ' . h($log) : '⚠ 重建失败：' . h($log);
    }
}

/* ---------------- 数据 ---------------- */
$tab = $_GET['tab'] ?? 'review';
$inc = jread('incoming')['items'] ?? [];
$projects = jread('projects')['items'] ?? [];
$existing = array_column($projects, 'full_name');
$ads = jread('ads'); $settings = jread('settings'); $waline = jread('waline');
$proxies = proxies_cfg(); $pxChannels = $proxies['channels']; $pxReadme = $proxies['readme'];
$submitCfg = $settings['submit'] ?? [];
$slots = $ads['slots'] ?? []; $camps = $ads['campaigns'] ?? [];

function statusBadge(array $c): string {
    if (empty($c['enabled'])) return '<span class="b gray">已停用</span>';
    $n = time(); $s = ts($c['start_at'] ?? null); $e = ts($c['end_at'] ?? null);
    if ($e && $e < $n) return '<span class="b red">已结束</span>';
    if ($s && $s > $n) return '<span class="b amber">待上线 ' . ceil(($s - $n) / 86400) . ' 天</span>';
    return '<span class="b green">进行中</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>管端 · <?=h($settings['site_name'] ?? 'OpenPick')?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:14px/1.6 -apple-system,"Segoe UI","PingFang SC",sans-serif;background:#f6f8fa;color:#1f2328}
header{background:#24292e;color:#fff;padding:13px 22px;display:flex;justify-content:space-between;align-items:center}
header b{font-size:16px}header span{font-size:12px;color:#9aa3ad}
header form{display:inline}header button{border:1px solid #57606a;background:none;color:#fff;border-radius:7px;padding:5px 11px;cursor:pointer;font-size:12px}
.wrap{max-width:1180px;margin:20px auto;padding:0 20px}
.tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.tabs a{border:1px solid #d1d9e0;background:#fff;padding:7px 15px;border-radius:9px;color:#57606a;font-size:13px}
.tabs a.on{background:#5b5bd6;color:#fff;border-color:#5b5bd6}
.msg{background:#dafbe1;border:1px solid #a6e3b5;padding:10px 14px;border-radius:9px;margin-bottom:16px;font-size:13px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:11px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);font-size:13px}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #eaeef2;vertical-align:top}th{background:#f6f8fa;font-weight:650;color:#57606a;font-size:12px}
tr:last-child td{border-bottom:0}
.fn{font-weight:650;color:#0969da}.src{display:inline-block;background:#eef;color:#4267b2;border-radius:4px;padding:1px 6px;font-size:10.5px;margin:1px}
.btn{cursor:pointer;border:1px solid #d1d9e0;background:#fff;padding:5px 11px;border-radius:7px;font-size:12px}
.btn:hover{border-color:#5b5bd6}.btn.ok:hover{background:#1a7f37;color:#fff}.btn.no:hover{background:#cf222e;color:#fff}
.btn.p{background:#5b5bd6;color:#fff;border-color:#5b5bd6}
details{background:#fff;border:1px solid #d1d9e0;border-radius:9px;padding:10px 13px;margin-bottom:9px}
summary{cursor:pointer;font-weight:600;font-size:13px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:12px}@media(max-width:720px){.grid{grid-template-columns:1fr}}
label{display:block;font-size:12px;font-weight:600;margin:9px 0 4px}
input,select,textarea{width:100%;padding:7px 10px;border:1px solid #d1d9e0;border-radius:7px;font:inherit;font-size:13px;background:#fff}
textarea{min-height:56px;resize:vertical}
.b{display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px}.green{background:#dafbe1;color:#116329}.red{background:#ffebe9;color:#a40e26}.amber{background:#fff8c5;color:#7d4e00}.gray{background:#eaeef2;color:#57606a}
.card{background:#fff;border:1px solid #d1d9e0;border-radius:11px;padding:18px;margin-bottom:14px}
.card h3{font-size:14px;margin-bottom:12px}
.hint{font-size:11.5px;color:#656d76;line-height:1.7;margin-top:8px}
code{background:#f6f8fa;border-radius:4px;padding:1px 5px;font-size:11.5px}
</style></head><body>
<header><div><b>✦ <?=h($settings['site_name'] ?? 'OpenPick')?> 管端</b> &nbsp; <span>👤 <?=h($user)?> · 待审 <?=count($inc)?> · 已收录 <?=count($projects)?></span></div>
<form method="post"><input type="hidden" name="act" value="rebuild"><button type="submit">🔄 立即重建</button></form></header>
<div class="wrap">
<?php if ($msg): ?><div class="msg"><?=$msg?></div><?php endif; ?>
<div class="tabs">
  <a class="<?=($tab==='review'?'on':'')?>" href="?tab=review">待审核 (<?=count($inc)?>)</a>
  <a class="<?=($tab==='ads'?'on':'')?>" href="?tab=ads">广告 (<?=count($camps)?>)</a>
  <a class="<?=($tab==='proxy'?'on':'')?>" href="?tab=proxy">加速代理</a>
  <a class="<?=($tab==='settings'?'on':'')?>" href="?tab=settings">系统设置</a>
  <a href="../index.html" target="_blank" rel="noopener">查看前台 ↗</a>
</div>

<?php if ($tab === 'review'): ?>
  <?php if (!$inc): ?><div class="card">🎉 没有待审核项目，等下一次采集。</div><?php else: ?>
  <table><thead><tr><th style="width:20%">项目</th><th style="width:26%">简介 / 投稿理由</th><th style="width:12%">来源</th><th style="width:8%">Stars</th><th style="width:34%">审核</th></tr></thead><tbody>
  <?php foreach ($inc as $x): $n=$x['full_name']; ?>
  <tr><td><a class="fn" href="https://github.com/<?=h($n)?>" target="_blank" rel="noopener"><?=h($n)?></a>
      <div style="font-size:11px;color:#656d76"><?=h($x['language']?:'—')?> · <?=h(substr($x['pushed_at']??'',0,10))?></div></td>
    <td><?=h($x['description']??'')?><?php if(!empty($x['suggested_reason'])): ?><div style="margin-top:6px;background:#fff8c5;padding:6px 8px;border-radius:6px;font-size:11.5px">💬 <?=h($x['suggested_reason'])?></div><?php endif; ?></td>
    <td><?php foreach ($x['sources']??[] as $s): ?><span class="src"><?=h($s)?></span><?php endforeach; ?></td>
    <td>★ <?=number_format((int)($x['stars']??0))?></td>
    <td>
      <details><summary>填写并入库</summary>
      <form method="post"><input type="hidden" name="act" value="approve"><input type="hidden" name="full_name" value="<?=h($n)?>">
      <div class="grid">
        <div><label>中文推荐语</label><input name="editor_note" placeholder="一句话说明为什么值得收录"></div>
        <div><label>English note</label><input name="editor_note_en"></div>
        <div><label>中文简介（留空用原描述）</label><input name="description_zh"></div>
        <div><label>分类 / Category</label><div style="display:flex;gap:6px"><input name="category" value="工具"><input name="category_en" value="Tool"></div></div>
        <div><label>难度</label><select name="difficulty"><option value="beginner">入门</option><option value="intermediate">进阶</option><option value="advanced">高级</option></select></div>
        <div><label>标签（逗号分隔）</label><input name="tags" placeholder="cli, rust"></div>
      </div>
      <label style="display:flex;gap:6px;align-items:center;margin-top:10px"><input type="checkbox" name="featured" value="1" style="width:auto"> 设为编辑精选</label>
      <div style="margin-top:11px;display:flex;gap:8px"><button class="btn p" type="submit">✓ 通过并重建</button></div></form></details>
      <form method="post" style="margin-top:6px"><input type="hidden" name="act" value="reject"><input type="hidden" name="rj_name" value="<?=h($n)?>"><button class="btn no" type="submit">✕ 拒绝（不再出现）</button></form>
    </td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <div class="hint">采集端每天写 <code>data/incoming.json</code>，宝塔定时 <code>git pull</code> 后这里自动更新。拒绝写入 <code>rejected.json</code>，采集端永久排除。</div>

<?php elseif ($tab === 'ads'): ?>
  <div class="card"><h3>广告总开关</h3>
  <form method="post" style="display:inline"><input type="hidden" name="act" value="ads_master">
    <button class="btn <?=(!empty($ads['enabled'])?'p':'')?>" type="submit">全站广告 · <?=(!empty($ads['enabled'])?'开':'关')?></button></form>
  <div class="hint">关闭后所有位置构建期<b>整段 DOM 不输出</b>（不是 <code>display:none</code>）；下面每个位置还能独立开关。</div></div>

  <div class="card"><h3>广告位（<?=count($slots)?>）· 逐个控制前台展示</h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <?php foreach ($slots as $s): ?>
    <form method="post" style="display:inline"><input type="hidden" name="act" value="slot_toggle"><input type="hidden" name="id" value="<?=h($s['id'])?>">
    <button class="btn <?=(!empty($s['enabled'])?'p':'')?>" type="submit" title="<?=h($s['id'])?>"><?=h($s['name'])?> · <?=(!empty($s['enabled'])?'开':'关')?></button></form>
    <?php if(!in_array($s['id'],['home_top','side','detail_end','footer'],true)): ?>
    <form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm('删除这个广告位？')"><input type="hidden" name="act" value="slot_del"><input type="hidden" name="id" value="<?=h($s['id'])?>"><button class="btn no" type="submit">✕</button></form>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
  <form method="post" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="act" value="slot_add">
    <div style="flex:1;min-width:150px"><label>新位置 ID（小写/下划线）</label><input name="new_id" placeholder="rank_top"></div>
    <div style="flex:2;min-width:190px"><label>显示名称</label><input name="new_name" placeholder="榜单顶部横幅"></div>
    <button class="btn" type="submit">＋ 添加广告位</button>
  </form>
  <div class="hint">新增位置后，在模板放一个 <code>&lt;!-- AD:新ID --&gt;</code> 锚点即可精确控制落点；没放锚点时构建器自动追加到 <code>&lt;/main&gt;</code> 前。内置 4 位不可删除。</div></div>

  <div class="card"><h3 id="adFormTitle">＋ 新增创意</h3>
  <form method="post" id="adForm"><input type="hidden" name="act" value="ad_save"><input type="hidden" name="id" id="adId" value="">
  <div class="grid">
    <div><label>投放位置</label><select name="slot"><?php foreach($slots as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['name'])?></option><?php endforeach; ?></select></div>
    <div><label>链接</label><input name="url" placeholder="https://… 或站内 ranking.html"></div>
    <div><label>开始时间（UTC，留空=立即）</label><input name="start_at" placeholder="2026-12-01T00:00:00Z"></div>
    <div><label>结束时间（UTC，留空=长期）</label><input name="end_at" placeholder="2026-12-31T23:59:00Z"></div>
    <div><label>优先级（大的先投）</label><input name="priority" type="number" value="1"></div>
    <div><label>内部备注</label><input name="note" placeholder="仅后台可见"></div>
  </div>
  <div class="grid" style="margin-top:14px">
    <div><label>中文标题</label><input name="zh_title"><label>中文文案</label><input name="zh_text"><label>中文按钮</label><input name="zh_cta" value="查看"></div>
    <div><label>English title</label><input name="en_title"><label>English text</label><input name="en_text"><label>English CTA</label><input name="en_cta" value="Learn more"></div>
  </div>
  <div style="margin-top:14px;display:flex;gap:8px"><button class="btn p" type="submit">保存并重建</button><button class="btn" type="button" id="adCancel" style="display:none">取消编辑</button></div></form>
  <div class="hint">排期靠「构建期过滤 + 客户端 <code>Date.now()</code> 守卫 + 宝塔每小时 <code>php build.php</code>」三层实现，精度约 1 小时。外链自动加 <code>rel="noopener nofollow sponsored"</code>。</div></div>

  <div class="card"><h3>创意列表（<?=count($camps)?>）</h3>
  <?php if(!$camps): ?><div class="hint">还没有创意。</div><?php else: ?>
  <table><thead><tr><th>标题</th><th>位置</th><th>排期</th><th>状态</th><th>操作</th></tr></thead><tbody>
  <?php foreach($camps as $c): ?>
  <tr><td><b><?=h($c['i18n']['zh']['title']??'')?></b><div style="font-size:11px;color:#656d76"><?=h($c['i18n']['en']['title']??'')?></div></td>
    <td><?=h($c['slot']??'')?></td>
    <td style="font-size:11.5px"><?=h($c['start_at']?:'不限')?><br>→ <?=h($c['end_at']?:'长期')?></td>
    <td><?=statusBadge($c)?></td>
    <td style="white-space:nowrap">
      <button class="btn adEdit" type="button" data-c="<?=h(json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?>">编辑</button>
      <form method="post" style="display:inline"><input type="hidden" name="act" value="ad_toggle"><input type="hidden" name="id" value="<?=h($c['id'])?>"><button class="btn" type="submit"><?=(!empty($c['enabled'])?'停用':'启用')?></button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')"><input type="hidden" name="act" value="ad_del"><input type="hidden" name="id" value="<?=h($c['id'])?>"><button class="btn no" type="submit">删除</button></form>
    </td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?></div>
  <script>
  (function(){
    var f=document.getElementById('adForm');if(!f)return;
    function set(n,v){var el=f.elements[n];if(el)el.value=(v==null?'':v)}
    document.querySelectorAll('.adEdit').forEach(function(b){
      b.onclick=function(){
        var c=JSON.parse(b.dataset.c),z=(c.i18n&&c.i18n.zh)||{},e=(c.i18n&&c.i18n.en)||{};
        document.getElementById('adId').value=c.id||'';
        set('slot',c.slot);set('url',c.url);set('start_at',c.start_at);set('end_at',c.end_at);
        set('priority',c.priority);set('note',c.note);
        set('zh_title',z.title);set('zh_text',z.text);set('zh_cta',z.cta);
        set('en_title',e.title);set('en_text',e.text);set('en_cta',e.cta);
        document.getElementById('adFormTitle').textContent='✎ 编辑创意 · '+(c.id||'');
        document.getElementById('adCancel').style.display='';
        f.scrollIntoView({behavior:'smooth',block:'center'});
      }});
    document.getElementById('adCancel').onclick=function(){
      f.reset();document.getElementById('adId').value='';
      document.getElementById('adFormTitle').textContent='＋ 新增创意';this.style.display='none';
    };
  })();
  </script>

<?php else: ?>
  <div class="card"><h3>系统设置</h3>
  <form method="post"><input type="hidden" name="act" value="settings">
  <div class="grid">
    <div><label>站点名</label><input name="site_name" value="<?=h($settings['site_name']??'OpenPick')?>"></div>
    <div><label>正式域名（无结尾斜杠）</label><input name="site_url" value="<?=h($settings['site_url']??'')?>"></div>
    <div><label>投稿仓库 owner/repo</label><input name="submit_repo" value="<?=h($settings['submit_repo']??'')?>"></div>
    <div><label>默认语言</label><select name="default_lang"><option value="zh" <?=(($settings['default_lang']??'zh')==='zh'?'selected':'')?>>简体中文</option><option value="en" <?=(($settings['default_lang']??'')==='en'?'selected':'')?>>English</option></select></div>
    <div><label>默认主题</label><select name="default_theme"><option value="light" <?=(($settings['default_theme']??'light')==='light'?'selected':'')?>>浅色</option><option value="dark" <?=(($settings['default_theme']??'')==='dark'?'selected':'')?>>深色</option></select></div>
    <div><label>首页每页条数</label><input name="per_page" type="number" value="<?=h($settings['per_page']??24)?>"></div>
    <div><label>榜单条数</label><input name="ranking_limit" type="number" value="<?=h($settings['ranking_limit']??20)?>"></div>
  </div>
  <h3 style="margin-top:20px">投稿 · Cloudflare Function</h3>
  <div class="grid">
    <div><label>提交方式</label><select name="sub_mode">
      <option value="auto" <?=((($submitCfg['mode']??'auto')==='auto')?'selected':'')?>>自动（填了地址走函数，没填走 GitHub Issue）</option>
      <option value="worker" <?=((($submitCfg['mode']??'')==='worker')?'selected':'')?>>只用 Cloudflare 函数</option>
      <option value="issue" <?=((($submitCfg['mode']??'')==='issue')?'selected':'')?>>只用 GitHub Issue</option></select></div>
    <div><label>函数地址（留空 = 同源 /api/submit）</label><input name="sub_endpoint" value="<?=h($submitCfg['endpoint']??'')?>" placeholder="https://xxx.workers.dev/api/submit"></div>
    <div><label>共享密钥（可选，与 Secret SUBMIT_KEY 一致）</label><input name="sub_key" value="<?=h($submitCfg['submit_key']??'')?>"></div>
    <div><label>Turnstile Site Key（可选，公开值）</label><input name="sub_turnstile" value="<?=h($submitCfg['turnstile_sitekey']??'')?>"></div>
  </div>
  <div class="hint">函数只部署在 <b>Cloudflare</b>：<code>functions/api/submit.ts</code>。<code>GITHUB_TOKEN</code>（fine-grained，仅 Issues 写）、<code>TURNSTILE_SECRET</code>、<code>SUBMIT_KEY</code>、<code>ALLOWED_ORIGIN</code> 全部放 Cloudflare Secret，绝不写进仓库 JSON。函数失败时前端会自动给出 GitHub Issue 兜底链接。</div>

  <h3 style="margin-top:20px">评论与点赞 · Waline</h3>
  <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" name="waline_on" value="1" style="width:auto" <?=(!empty($waline['enabled'])?'checked':'')?>> 启用评论（关闭时详情页整段 DOM 不渲染）</label>
  <div class="grid">
    <div><label>Waline 服务端地址 serverURL</label><input name="w_serverURL" value="<?=h($waline['serverURL']??'')?>" placeholder="https://waline-xxx.workers.dev"></div>
    <div><label>登录模式</label><select name="w_login">
      <?php foreach(['enable'=>'允许登录（可匿名）','disable'=>'禁用登录','force'=>'强制登录'] as $k=>$v): ?>
      <option value="<?=$k?>" <?=((($waline['login']??'enable')===$k)?'selected':'')?>><?=$v?></option><?php endforeach; ?></select></div>
    <div><label>每页评论数</label><input name="w_pageSize" type="number" value="<?=h($waline['pageSize']??10)?>"></div>
    <div><label>字数上限（0=不限）</label><input name="w_wordLimit" type="number" value="<?=h($waline['wordLimit']??0)?>"></div>
    <div><label>Turnstile Site Key（可选，公开值）</label><input name="w_turnstile" value="<?=h($waline['turnstile_sitekey']??'')?>"></div>
  </div>
  <label style="display:flex;gap:7px;align-items:center;margin-top:12px"><input type="checkbox" name="w_reaction" value="1" style="width:auto" <?=(!empty($waline['reaction'])?'checked':'')?>> 开启点赞 / 表情反应（reaction）</label>
  <div class="hint">客户端选项构建期固化：<code>path</code> 固定 <code>/project/{id}</code>（中英共用同一串评论）、<code>lang</code> 按页面、<code>dark</code> 跟随站点主题按钮。点「加载评论」才注入 CSS + ES module，之前访客侧 0 外部请求。服务端可用 Cloudflare Workers + D1 的社区实现或任意 Waline 服务端，把本站域名填进服务端 <code>SECURE_DOMAINS</code>。</div>
  <div style="margin-top:16px"><button class="btn p" type="submit">保存并重建</button></div></form></div>

<?php elseif ($tab === 'proxy'): ?>
<div class="card">
  <h3>下载通道 · Release 加速镜像</h3>
  <div class="hint">切换只改写链接前缀，<b>官方 / 镜像指向同一个文件</b>；构建期写进 HTML，运行时零请求。官方直连不可删除。关闭镜像开关后只保留官方直连，README 项目说明不受影响。</div>
  <form method="post" style="margin:12px 0">
    <input type="hidden" name="act" value="px_master">
    <button class="btn <?=(!empty($proxies['enabled'])?'p':'')?>" type="submit">镜像通道 · <?=(!empty($proxies['enabled'])?'开':'关')?></button>
    <span class="hint" style="margin-left:8px">默认通道：<b><?=h($proxies['default']??'official')?></b></span>
  </form>
  <table><thead><tr><th style="width:20%">名称</th><th style="width:32%">前缀 prefix</th><th style="width:10%">状态</th><th style="width:38%">操作</th></tr></thead><tbody>
  <?php foreach ($pxChannels as $c): $cid=(string)($c['id']??''); ?>
  <tr>
    <td><b><?=h($c['name']??$cid)?></b><div style="font-size:11px;color:#656d76"><?=h($cid)?><?=($cid===($proxies['default']??'')?' · 默认':'')?></div></td>
    <td><code><?=h(($c['prefix']??'')!==''?$c['prefix']:'（官方直链）')?></code></td>
    <td><?=(!empty($c['enabled'])?'<span class="b green">启用</span>':'<span class="b gray">停用</span>')?></td>
    <td>
      <form method="post" style="display:inline"><input type="hidden" name="act" value="px_default"><input type="hidden" name="id" value="<?=h($cid)?>"><button class="btn" type="submit">设为默认</button></form>
      <form method="post" style="display:inline"><input type="hidden" name="act" value="px_move"><input type="hidden" name="id" value="<?=h($cid)?>"><input type="hidden" name="dir" value="up"><button class="btn" type="submit">↑</button></form>
      <form method="post" style="display:inline"><input type="hidden" name="act" value="px_move"><input type="hidden" name="id" value="<?=h($cid)?>"><input type="hidden" name="dir" value="down"><button class="btn" type="submit">↓</button></form>
      <?php if ($cid !== 'official'): ?>
      <form method="post" style="display:inline"><input type="hidden" name="act" value="px_toggle"><input type="hidden" name="id" value="<?=h($cid)?>"><button class="btn" type="submit"><?=(!empty($c['enabled'])?'停用':'启用')?></button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('删除该通道？')"><input type="hidden" name="act" value="px_del"><input type="hidden" name="id" value="<?=h($cid)?>"><button class="btn" type="submit">删除</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>

  <h3 style="margin-top:20px">新增通道</h3>
  <form method="post" class="grid">
    <input type="hidden" name="act" value="px_add">
    <div><label>名称</label><input name="px_name" placeholder="GHProxy 镜像"></div>
    <div><label>前缀（会自动补 /）</label><input name="px_prefix" placeholder="https://ghproxy.net/"></div>
    <div style="align-self:end"><button class="btn p" type="submit">添加通道</button></div>
  </form>
  <div class="hint">适用于 <code>prefix + 官方直链</code> 形式的镜像（ghproxy / ghfast / mirror.ghproxy.com 等）。不兼容 jsDelivr 之类需要改写路径的 CDN。</div>

  <h3 style="margin-top:20px">README 项目说明 · 图片代理</h3>
  <div class="hint">README 正文在构建期抓取、中英自动选版并渲染进 HTML，<b>运行时不请求外部脚本</b>。图片只放行白名单域名；配了图片代理才走代理，加载失败自动回退原图。</div>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="act" value="px_readme">
    <div class="grid">
      <div><label>图片代理前缀（留空 = 直连）</label><input name="px_image_proxy" value="<?=h($pxReadme['image_proxy']??'')?>" placeholder="https://images.example.workers.dev/?url="></div>
      <div><label>抓取超时（秒）</label><input name="px_timeout" type="number" value="<?=h($pxReadme['timeout']??8)?>"></div>
      <div><label>单文件体积上限（字节）</label><input name="px_max_bytes" type="number" value="<?=h($pxReadme['max_bytes']??200000)?>"></div>
    </div>
    <label style="display:block;margin-top:12px">图片域名白名单（一行一个）</label>
    <textarea name="px_whitelist" style="width:100%;min-height:110px;padding:9px 11px;border:1px solid #d1d9e0;border-radius:9px;font:inherit;font-size:13px"><?=h(implode("\n", (array)($pxReadme['whitelist']??[])))?></textarea>
    <div style="margin-top:14px"><button class="btn p" type="submit">保存并重建</button></div>
  </form>
</div>
<?php endif; ?>
</div></body></html>
