#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
OpenPick Lite · 无 PHP 环境的等价页面生成器

  python3 tools/build_pages.py

与 build.php 使用同一份 data/*.json、同一套 CSS/JS（直接从 build.php 的
heredoc 提取）、同一套 HTML 拼接顺序与广告注入规则，产出一致的 public/。

用途：
  1. 沙盒 / 本地没有 PHP 时生成成品页，解压即可部署；
  2. 上宝塔后仍以 `php build.php` 为准（它会覆盖 public/）。

README 双语探测需要联网；离线时与 build.php 一样用 description 兜底。
"""
import html
import json
import os
import re
import urllib.parse
import urllib.request
import urllib.error
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
D = os.path.join(ROOT, 'data')
OUT = os.path.join(ROOT, 'public')
NOW = datetime.now(timezone.utc)


def load(name):
    p = os.path.join(D, name + '.json')
    if not os.path.isfile(p):
        return {}
    with open(p, encoding='utf-8') as f:
        return json.load(f)


def e(s):
    return html.escape(str(s if s is not None else ''), quote=True)


def jout(v):
    return json.dumps(v, ensure_ascii=False)


CFG = load('settings')
SITE = (CFG.get('site_url') or '').rstrip('/')
LANGS = ['zh', 'en']
PROJECTS = load('projects').get('items', [])
INCOMING_COUNT = len(load('incoming').get('items', []))
ADS = load('ads')
WALINE = load('waline')
PROXIES = load('proxies')
SNAP = load('readmes')          # 本地快照（.gitignore，不入库）；命中即完全离线
DICT = {l: load('i18n/' + l).get('t', {}) for l in LANGS}
EN = DICT['en']


def t(l, k):
    return DICT[l].get(k, EN.get(k, k))


_src = open(os.path.join(ROOT, 'build.php'), encoding='utf-8').read()
CSS = re.search(r"<<<'CSS'\n([\s\S]*?)\nCSS;", _src).group(1)
JS = re.search(r"<<<'JS'\n([\s\S]*?)\nJS;", _src).group(1)

# 投稿页交互：独立 raw 常量，所有文案走 OP_SUBMIT 配置，不在 JS 字符串里拼中文
SUBMIT_JS = r'''(function(){
var f=document.getElementById("sf"),C=window.OP_SUBMIT,m=document.getElementById("sm"),b=document.getElementById("subBtn");
if(!f)return;
function issueURL(repo,reason,cat){var body="### 仓库\n`"+repo+"`\n\n### 推荐理由\n"+reason+"\n\n### 分类\n"+cat;
return "https://github.com/"+C.repo+"/issues/new?title="+encodeURIComponent("提交项目："+repo)+"&body="+encodeURIComponent(body)+"&labels=submission"}
function fallback(repo,reason,cat,msg){var u=issueURL(repo,reason,cat);
m.style.color="var(--warn)";m.innerHTML=msg+" · <a href=\""+u+"\" target=\"_blank\" rel=\"noopener\">"+C.alt+" ↗</a>"}
function token(){try{return window.turnstile?window.turnstile.getResponse():""}catch(e){return ""}}
f.onsubmit=async function(e){e.preventDefault();
var repo=f.repo.value.trim(),reason=f.reason.value.trim(),cat=f.cat.value;
if(!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo)||reason.length<10){m.style.color="var(--warn)";m.textContent=C.bad;return}
var u=issueURL(repo,reason,cat);
var useWorker=C.mode==="worker"||(C.mode==="auto"&&C.endpoint!=="");
if(!useWorker){m.style.color="var(--ok)";m.innerHTML=C.ok+" <a href=\""+u+"\" target=\"_blank\" rel=\"noopener\">↗</a>";window.open(u,"_blank","noopener");return}
b.disabled=true;m.style.color="var(--muted)";m.textContent=C.sending;
try{var r=await fetch(C.endpoint||"/api/submit",{method:"POST",headers:{"Content-Type":"application/json","X-Submit-Key":C.key},
body:JSON.stringify({repo:repo,reason:reason,cat:cat,lang:C.lang,turnstileToken:token()})});
var j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||"HTTP "+r.status);
m.style.color="var(--ok)";m.innerHTML=C.ok+" <a href=\""+(j.issue||u)+"\" target=\"_blank\" rel=\"noopener\">↗</a>"}
catch(err){fallback(repo,reason,cat,C.fail+" ("+err.message+")")}finally{b.disabled=false}}
})();'''

COLORS = {'Python': '#3572A5', 'JavaScript': '#f1e05a', 'TypeScript': '#3178c6',
          'Go': '#00add8', 'Rust': '#dea584', 'Java': '#b07219', 'C++': '#f34b7d',
          'Ruby': '#701516', 'Zig': '#f7a41d'}


def stars(n):
    n = int(n or 0)
    return ('%.1fk' % (n / 1000)) if n >= 1000 else str(n)


def desc(i, l):
    return (i.get('description_zh') or i.get('description') or '') if l == 'zh' else (i.get('description') or '')


def note(i, l):
    return (i.get('editor_note') or '') if l == 'zh' else (i.get('editor_note_en') or i.get('editor_note') or '')


def cat(i, l):
    return (i.get('category') or '') if l == 'zh' else (i.get('category_en') or i.get('category') or '')


def ts(s):
    if not s:
        return 0
    try:
        return int(datetime.fromisoformat(s.replace('Z', '+00:00')).timestamp())
    except Exception:
        return 0


# ------------------------------------------------------------------ 广告
SLOT_IDS = [str(s.get('id')) for s in (ADS.get('slots') or []) if s.get('id')]


def ad_render(slot, l):
    if 'enabled' in ADS and not ADS['enabled']:
        return ''                                  # 全站总开关
    if not any(s.get('id') == slot and s.get('enabled') for s in (ADS.get('slots') or [])):
        return ''                                  # 该位置关闭 → 整段 DOM 不输出
    now = int(NOW.timestamp())
    cands = []
    for c in ADS.get('campaigns', []):
        if c.get('slot') != slot or not c.get('enabled'):
            continue
        if c.get('langs') and l not in c['langs']:
            continue
        end = ts(c.get('end_at'))
        if end and end < now:
            continue                               # 已结束：构建期直接丢弃
        start = ts(c.get('start_at'))
        if start and start > now + 400 * 86400:
            continue
        cands.append(c)
    if not cands:
        return ''
    cands.sort(key=lambda x: -int(x.get('priority') or 0))
    label = '广告' if l == 'zh' else 'Ad'
    variants, any_live = '', False
    for idx, c in enumerate(cands):
        st, en = ts(c.get('start_at')), ts(c.get('end_at'))
        live = (not st or st <= now) and (not en or en >= now)
        if live:
            any_live = True
        txt = ((c.get('i18n') or {}).get(l) or (c.get('i18n') or {}).get('en')
               or (c.get('i18n') or {}).get('zh') or {})
        url = c.get('url') or '#'
        ext = bool(re.match(r'^https?://', url))
        rel = ' rel="noopener nofollow sponsored"' if ext else ''
        tgt = ' target="_blank"' if ext else ''
        hidden = '' if (live and idx == 0) else ' hidden'
        variants += ('<div class="ad-v" data-start="' + e(c.get('start_at') or '') + '" data-end="'
                     + e(c.get('end_at') or '') + '"' + hidden + '><a href="' + e(url) + '"' + tgt + rel + '>'
                     + '<h4>' + e(txt.get('title')) + '</h4><p>' + e(txt.get('text')) + '</p>'
                     + '<span class="ad-cta">' + e(txt.get('cta') or '↗') + '</span></a></div>')
    return ('<aside class="ad" data-slot="' + e(slot) + '"' + ('' if any_live else ' hidden') + '>'
            + '<div class="ad-hd"><span>' + label + '</span></div>' + variants + '</aside>')


def ad_inject(h, l, allowed):
    """与 common.php::ad_inject 一致：按本页允许列表、只替换首个锚点、不兜底插 main。"""
    for slot in SLOT_IDS:
        if slot not in allowed:
            continue
        if 'data-slot="' + slot + '"' in h:
            continue
        block = ad_render(slot, l)
        tag = '<!-- AD:' + slot + ' -->'
        if not block:
            h = h.replace(tag, '')
            continue
        pos = h.find(tag)
        if pos >= 0:
            h = h[:pos] + block + h[pos + len(tag):]
    for slot in SLOT_IDS:
        h = h.replace('<!-- AD:' + slot + ' -->', '')
    return h


LIST_SLOTS = ['home_top', 'side', 'footer']
DETAIL_SLOTS = ['side', 'detail_end', 'footer']

# ------------------------------------------------------------------ 代理 / 下载通道

OFFICIAL = {'id': 'official', 'name': '官方直连', 'prefix': '', 'enabled': True, 'builtin': True}


def proxies_cfg():
    p = PROXIES or {}
    chs = p.get('channels') or [OFFICIAL]
    if not any(c.get('id') == 'official' for c in chs):
        chs = [OFFICIAL] + list(chs)
    if not isinstance(p.get('readme'), dict):
        p['readme'] = {'image_proxy': '', 'whitelist': ['raw.githubusercontent.com', 'github.com'],
                       'timeout': 8, 'max_bytes': 200000}
    p['channels'] = chs
    return p


def proxy_channels():
    p = proxies_cfg()
    chs = [c for c in p['channels'] if c.get('enabled')]
    if not p.get('enabled', True):
        return [c for c in chs if c.get('id') == 'official'] or [OFFICIAL]
    return chs


def proxy_block(l):
    chs = proxy_channels()
    if len(chs) < 2:
        return ''
    default = proxies_cfg().get('default') or 'official'
    label = '下载通道' if l == 'zh' else 'Mirror'
    hint = ('切换后所有下载链接即时改写，文件不变' if l == 'zh'
            else 'All links rewritten instantly; same file')
    opts = ''.join('<option value="' + e(c.get('prefix') or '') + '"'
                   + (' selected' if c.get('id') == default else '') + '>'
                   + e(c.get('name') or c.get('id')) + '</option>' for c in chs)
    return ('<div class="proxy-bar"><label>' + label + '</label><select id="pxSel">' + opts
            + '</select><span class="hint">' + hint + '</span></div>')


def readme_image_url(url, full):
    r = proxies_cfg()['readme']
    url = html.unescape(url)
    if not re.match(r'^https?://', url, re.I):
        url = 'https://raw.githubusercontent.com/' + full + '/HEAD/' + url.lstrip('/')
    host = (urllib.parse.urlparse(url).hostname or '').lower()
    wl = [str(x).lower() for x in (r.get('whitelist') or [])]
    if not host or host not in wl:
        return ''
    px = (r.get('image_proxy') or '').strip()
    return px + urllib.parse.quote(url, safe='') if px else url


# ------------------------------------------------------------------ 评论（Waline）

def waline_block(l, rid, canonical):
    if not WALINE.get('enabled') or not WALINE.get('serverURL'):
        return ''                                  # 关闭 → 整段 DOM 不输出
    server = (WALINE.get('serverURL') or '').rstrip('/')
    opts = {
        'el': '#waline', 'serverURL': server, 'path': '/project/' + rid,
        'lang': 'zh-CN' if l == 'zh' else 'en',
        'dark': 'html[data-theme="dark"]',
        'reaction': bool(WALINE.get('reaction')),
        'pageSize': max(1, int(WALINE.get('pageSize') or 10)),
        'wordLimit': int(WALINE.get('wordLimit') or 0),
        'login': WALINE.get('login') or 'enable',
        'emoji': WALINE.get('emoji') or ['https://unpkg.com/@waline/emojis@1.1.0/weibo'],
    }
    if WALINE.get('turnstile_sitekey'):
        opts['turnstileKey'] = WALINE['turnstile_sitekey']
    cfg = {'js': WALINE.get('client_js') or 'https://unpkg.com/@waline/client@v3/dist/waline.js',
           'css': WALINE.get('client_css') or 'https://unpkg.com/@waline/client@v3/dist/waline.css',
           'options': opts}
    return ('<section class="comm" id="s-comments"><h2>' + e(t(l, 'w.title')) + '</h2>'
            '<p class="comm-hint">' + e(t(l, 'w.hint')) + '</p>'
            '<button id="wLoad" class="btn">' + e(t(l, 'w.join')) + '</button>'
            '<div id="waline"></div><script>window.OP_WALINE=' + jout(cfg) + ';</script></section>')


# ------------------------------------------------------------------ README

_RM_CACHE = {}
_NET_DEAD = False
_SNAP_DIRTY = False
_SRC_FAIL = {'raw': 0, 'jsdelivr': 0}
RM_CANDS = [
    'README.zh-CN.md', 'README.md', 'README.zh.md', 'README.en.md',
    'docs/README.zh-CN.md', 'docs/README.md',
]
GENERIC_RM = {'README.md', 'docs/README.md'}


def readme(i):
    """与 build.php::readme 一致：候选文件名 + 内容启发式分中英桶；网络不可达时 description 兜底。"""
    global _NET_DEAD, _SNAP_DIRTY
    full = i['full_name']
    rid = i['resource_id']
    if full in _RM_CACHE:
        return _RM_CACHE[full]
    # 本地快照优先：命中就完全离线、不受 CDN 波动影响
    hit = SNAP.get(rid) or {}
    if (hit.get('zh') or {}).get('md') or (hit.get('en') or {}).get('md'):
        z = hit.get('zh') or hit.get('en')
        en = hit.get('en') or hit.get('zh')
        _RM_CACHE[full] = {'zh': z, 'en': en}
        return _RM_CACHE[full]
    cfg = proxies_cfg()['readme']
    timeout = max(2, min(20, int(cfg.get('timeout') or 8)))
    max_bytes = max(20000, int(cfg.get('max_bytes') or 200000))
    buckets = {'zh': None, 'en': None}
    probes = 0
    errs = 0
    if not _NET_DEAD:
        for f in RM_CANDS:
            if (buckets['zh'] and buckets['en']) or probes >= 4:
                break
            probes += 1
            # 双源自动切换：raw 优先，jsdelivr 备用；连续失败的源本轮后续直接跳过
            raw = None
            sources = (
                ('raw', 'https://raw.githubusercontent.com/' + full + '/HEAD/' + f),
                ('jsdelivr', 'https://cdn.jsdelivr.net/gh/' + full + '@HEAD/' + f),
            )
            for src, u in sources:
                if _SRC_FAIL[src] >= 2:
                    continue
                try:
                    req = urllib.request.Request(u, headers={'User-Agent': 'OpenPickLite/1.0'})
                    with urllib.request.urlopen(req, timeout=min(timeout, 4)) as r:
                        raw = r.read(max_bytes).decode('utf-8', 'replace')
                    _SRC_FAIL[src] = 0
                    break
                except urllib.error.HTTPError as ex:
                    if ex.code == 404:
                        _SRC_FAIL[src] = 0
                        continue
                    _SRC_FAIL[src] += 1
                    errs += 1
                except Exception:
                    _SRC_FAIL[src] += 1
                    errs += 1
            if raw is None or len(raw) < 20:
                if errs >= 6:
                    _NET_DEAD = True
                continue
            raw = rm_follow(raw, full, f)
            by_name = f not in GENERIC_RM and ('zh' in f.lower() or 'cn' in f.lower())
            head = raw[:4000]
            n = len(re.findall(r'[\u4e00-\u9fa5]', head))
            by_content = n > 12 and n / max(1, len(head)) > 0.22
            k = 'zh' if (by_name or by_content) else 'en'
            if buckets[k] is None:
                buckets[k] = {'md': raw, 'path': f}
    got_real = bool(buckets['zh'] or buckets['en'])
    if not got_real:
        fb = desc(i, 'zh') or desc(i, 'en')
        buckets['zh'] = buckets['en'] = {'md': fb, 'path': 'description'}
    if not buckets['zh']:
        buckets['zh'] = buckets['en']
    if not buckets['en']:
        buckets['en'] = buckets['zh']
    if got_real:
        SNAP[rid] = buckets
        _SNAP_DIRTY = True
    _RM_CACHE[full] = buckets
    return buckets


def rm_follow(raw, full, name):
    """联网补抓时同样跟随「README 只有一行相对路径」的指针。"""
    s = raw.strip()
    if len(s) > 200 or not re.match(r'^[\w@./\-]+\.md$', s):
        return raw
    target = s.lstrip('./')
    base = name.rsplit('/', 1)[0] if '/' in name else ''
    path = (base + '/' + target).lstrip('/') if base else target
    for u in ('https://raw.githubusercontent.com/' + full + '/HEAD/' + path,
              'https://cdn.jsdelivr.net/gh/' + full + '@HEAD/' + path):
        try:
            req = urllib.request.Request(u, headers={'User-Agent': 'OpenPickLite/1.0'})
            with urllib.request.urlopen(req, timeout=3) as r:
                t = r.read(200000).decode('utf-8', 'replace')
            if len(t) > 200:
                return t
        except Exception:
            continue
    return raw


def md_pre(md):
    """与 build.php::md_pre 一致。返回 (清理后的 md, 引用式链接定义表)。"""
    md = html.unescape(md)
    md = md.replace('\r\n', '\n').replace('\r', '\n')
    md = re.sub(r'<(script|style|iframe|object|svg|noscript)\b[\s\S]*?</\1>', '', md, flags=re.I)
    md = re.sub(r'<!--[\s\S]*?-->', '', md)
    def img(m):
        src = re.search(r'\bsrc=["\']([^"\']+)["\']', m.group(0), re.I)
        alt = re.search(r'\balt=["\']([^"\']*)["\']', m.group(0), re.I)
        return '![' + (alt.group(1) if alt else '') + '](' + src.group(1) + ')' if src else ''
    md = re.sub(r'<img\b[^>]*>', img, md, flags=re.I)
    md = re.sub(r'<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)</a>',
                lambda m: '[' + re.sub(r'<[^>]+>', '', m.group(2)).strip() + '](' + m.group(1) + ')',
                md, flags=re.I)
    md = re.sub(r'<br\s*/?>', '\n', md, flags=re.I)
    md = re.sub(r'<source\b[^>]*>', '', md, flags=re.I)
    md = re.sub(r'</?[A-Za-z][^>]*>', '', md)
    refs = {}
    lines = md.split('\n')
    for k, ln in enumerate(lines):
        m = re.match(r'^\s{0,3}\[([^\]]+)\]:\s*(\S+)', ln)
        if m:
            refs[m.group(1).strip().lower()] = m.group(2).strip()
            lines[k] = ''
            continue
        imgs = len(re.findall(r'!\[', ln))
        if imgs:
            probe = re.sub(r'!\[[^\]]*\](?:\([^)]*\)|\[[^\]]*\])', '', ln)
            probe = re.sub(r'\[[^\]]*\](?:\([^)]*\)|\[[^\]]*\])', '', probe)
            probe = re.sub(r'[\[\]()|·\s]+', '', probe).strip()
            if len(probe) < 6 and (imgs >= 2 or re.search(r'\]\[[^\]]+\]', ln)):
                lines[k] = ''
    md = re.sub(r'\n{3,}', '\n\n', '\n'.join(lines))
    return md, refs


def md_img(alt, url, full):
    u = html.unescape(url)
    if not re.match(r'^https?://', u, re.I):
        u = 'https://raw.githubusercontent.com/' + full + '/HEAD/' + u.lstrip('/')
    real = readme_image_url(u, full)
    if not real:
        return ''
    return ('<img class="md-img" src="' + e(real) + '" alt="' + alt
            + '" loading="lazy" referrerpolicy="no-referrer" data-orig="' + e(u) + '">')


def strip_tags(s):
    return re.sub(r'<[^>]+>', '', s)


def inline(s, full, refs=None):
    refs = refs or {}
    # 1) 徽章链接
    s = re.sub(r'\[!\[([^\]]*)\]\(([^)]+)\)\]\(([^)]+)\)',
               lambda m: md_img(m.group(1), m.group(2), full), s)
    # 2) 引用式图片
    def ref_img(m):
        key = (m.group(2).strip() or m.group(1).strip()).lower()
        return md_img(m.group(1), refs[key], full) if key in refs else ''
    s = re.sub(r'!\[([^\]]*)\]\[([^\]]*)\]', ref_img, s)
    # 3) 内联图片
    s = re.sub(r'!\[([^\]]*)\]\(([^)\s]+)\)',
               lambda m: md_img(m.group(1), m.group(2), full), s)
    # 4) 引用式链接
    def ref_link(m):
        key = (m.group(2).strip() or m.group(1).strip()).lower()
        return ('<a href="' + e(refs[key]) + '" target="_blank" rel="noopener">' + m.group(1) + '</a>'
                if key in refs else m.group(1))
    s = re.sub(r'\[([^\]]+)\]\[([^\]]*)\]', ref_link, s)
    # 5) 内联链接
    def link(m):
        u = html.unescape(m.group(2))
        if not u:
            return m.group(1)
        if u.startswith('#'):
            return '<a href="' + e(u) + '">' + m.group(1) + '</a>'
        if not re.match(r'^[a-z][a-z0-9+.\-]*:', u, re.I):
            u = 'https://github.com/' + full + '/blob/HEAD/' + u.lstrip('/')
        return '<a href="' + e(u) + '" target="_blank" rel="noopener">' + m.group(1) + '</a>'
    s = re.sub(r'\[([^\]]*)\]\(([^)\s]*)\)', link, s)
    # 6) 简写引用
    def short(m):
        key = m.group(1).strip().lower()
        return ('<a href="' + e(refs[key]) + '" target="_blank" rel="noopener">' + m.group(1) + '</a>'
                if key in refs else m.group(0))
    s = re.sub(r'\[([A-Za-z0-9_.\- ]{1,40})\](?!\()', short, s)
    # 7) 行内格式
    s = re.sub(r'`([^`]+)`', lambda m: '<code>' + m.group(1) + '</code>', s)
    s = re.sub(r'\*\*([^*]+)\*\*', lambda m: '<b>' + m.group(1) + '</b>', s)
    s = re.sub(r'(?<!\w)\*([^*\n]+)\*(?!\w)', lambda m: '<i>' + m.group(1) + '</i>', s)
    s = re.sub(r'(?<!\w)_([^_\n]+)_(?!\w)', lambda m: '<i>' + m.group(1) + '</i>', s)
    s = re.sub(r'~~([^~]+)~~', lambda m: '<s>' + m.group(1) + '</s>', s)
    return s


def md2html(md, full):
    """与 build.php::md2html 一致：代码块 / 标题 / 表格 / hr / 列表续行 / 段落合并。"""
    md, refs = md_pre(md)
    lines = md.split('\n')
    out, in_code, list_tag, para, li = '', False, '', [], ''

    def flush_para():
        nonlocal out, para
        if para:
            h = inline(e(' '.join(para)), full, refs)
            para = []
            if strip_tags(h).strip() or '<img' in h:
                out += '<p>' + h + '</p>'

    def flush_list():
        nonlocal out, list_tag, li
        if li:
            out += '<li>' + inline(e(li), full, refs) + '</li>'
            li = ''
        if list_tag:
            out += '</' + list_tag + '>'
            list_tag = ''

    def flush_all():
        flush_para()
        flush_list()

    def cells(row):
        return [c.strip() for c in row.strip().strip('| \t').split('|')]

    k = 0
    while k < len(lines):
        raw, ln = lines[k], lines[k].strip()
        if re.match(r'^```', ln):
            flush_all()
            out += '</code></pre>' if in_code else '<pre><code>'
            in_code = not in_code
            k += 1
            continue
        if in_code:
            out += e(raw) + '\n'
            k += 1
            continue
        if not ln:
            flush_all()
            k += 1
            continue
        if re.match(r'^(?:-{3,}|\*{3,}|_{3,})$', ln):
            flush_all()
            out += '<hr>'
            k += 1
            continue
        # 表格
        if ('|' in ln and k + 1 < len(lines)
                and re.match(r'^\s*\|?[\s:\-|]+\|?\s*$', lines[k + 1]) and '-' in lines[k + 1]):
            flush_all()
            out += '<table><thead><tr>'
            for c in cells(lines[k]):
                out += '<th>' + inline(e(c), full, refs) + '</th>'
            out += '</tr></thead><tbody>'
            k += 2
            while k < len(lines) and '|' in lines[k].strip() and lines[k].strip():
                out += '<tr>'
                for c in cells(lines[k]):
                    out += '<td>' + inline(e(c), full, refs) + '</td>'
                out += '</tr>'
                k += 1
            out += '</tbody></table>'
            continue
        m = re.match(r'^(#{1,6})\s+(.*)$', ln)
        if m:
            flush_all()
            lv = min(4, len(m.group(1)))
            title = re.sub(r'!\[[^\]]*\]\([^)]*\)', '', m.group(2))
            title = re.sub(r'!\[[^\]]*\]\[[^\]]*\]', '', title)
            title = re.sub(r'\[\s*\]\([^)]*\)', '', title)
            plain = strip_tags(re.sub(r'\[([^\]]+)\]\([^)]*\)', r'\1', title))
            anchor = re.sub(r'[^\w\u4e00-\u9fa5-]', '', plain)[:60]
            out += ('<h' + str(lv) + ' id="' + anchor + '">' + inline(e(title.strip()), full, refs)
                    + '</h' + str(lv) + '>')
            k += 1
            continue
        mu = re.match(r'^[-*+]\s+(.*)$', ln)
        mo = re.match(r'^\d+[.)]\s+(.*)$', ln)
        if mu or mo:
            flush_para()
            if li:
                out += '<li>' + inline(e(li), full, refs) + '</li>'
            want = 'ul' if mu else 'ol'
            if list_tag != want:
                if list_tag:
                    out += '</' + list_tag + '>'
                out += '<' + want + '>'
                list_tag = want
            li = (mu or mo).group(1)
            k += 1
            continue
        if list_tag and li and ln and not re.match(r'^(#{1,6}\s|>|```|\|)', ln):
            li += ' ' + ln
            k += 1
            continue
        flush_list()
        mq = re.match(r'^&gt;\s?(.*)$', ln) or re.match(r'^>\s?(.*)$', ln)
        if mq:
            flush_para()
            out += '<blockquote>' + inline(e(mq.group(1)), full, refs) + '</blockquote>'
            k += 1
            continue
        para.append(ln)
        k += 1
    flush_all()
    if in_code:
        out += '</code></pre>'
    return out


def md_toc(h, l):
    m = list(re.finditer(r'<h([23]) id="([^"]*)">([\s\S]*?)</h\1>', h))
    if len(m) < 3:
        return ''
    a = '<div class="side-box toc" id="rmToc"><h4>' + e(t(l, 'd.toc')) + '</h4>'
    for x in m[:18]:
        txt = html.unescape(strip_tags(x.group(3))).strip()
        if txt:
            a += ('<a class="' + ('lv3' if x.group(1) == '3' else '') + '" href="#'
                  + e(x.group(2)) + '">' + e(txt) + '</a>')
    return a + '</div>'


def md_wrap(h, l, id_='mdBody', hidden=False):
    long = len(h) > 9000
    s = ('<div class="md-clip' + (' collapsed' if long else '') + '" id="clip-' + id_ + '"'
         + (' style="display:none"' if hidden else '') + '><article class="md" id="' + id_ + '">' + h + '</article>')
    if long:
        s += ('<div class="rm-more"><button data-more="' + e(t(l, 'd.more')) + '" data-less="'
              + e(t(l, 'd.less')) + '">' + e(t(l, 'd.more')) + '</button></div>')
    return s + '</div>'


# ------------------------------------------------------------------ 页面骨架

def shell(l, title, body, page='index', extra_head='', extra_js=''):
    other = 'en' if l == 'zh' else 'zh'
    idx = [{'full_name': i['full_name'], 'description': desc(i, l),
            'tags': (i.get('topics') or []) + (i.get('tags') or []), 'category': cat(i, l),
            'url': 'p-' + i['resource_id'] + '.html'} for i in PROJECTS]
    nav = [('index.html', 'n.home'), ('ranking.html', 'n.rank'),
           ('search.html', 'n.search'), ('submit.html', 'n.submit')]
    links = ''.join('<a href="' + u + '">' + e(t(l, k)) + '</a>' for u, k in nav)
    head = ('<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>' + e(title) + ' · ' + e(CFG.get('site_name', 'OpenPick')) + '</title>'
            '<link rel="canonical" href="' + e(SITE + '/' + l + '/' + page + '.html') + '">'
            '<meta name="description" content="' + e(t(l, 'site.desc')) + '">'
            '<style>' + CSS + '</style>' + extra_head)
    js = ('<script>window.OP_INDEX=' + jout(idx) + ';window.OP_NO_RESULT=' + jout(t(l, 's.none'))
          + ';</script><script>' + JS + '</script>' + extra_js)
    return ('<!DOCTYPE html><html lang="' + ('zh-CN' if l == 'zh' else 'en') + '" data-theme="'
            + e(CFG.get('default_theme', 'light')) + '"><head>' + head + '</head><body>'
            '<header><div class="wrap hd"><a class="logo" href="index.html"><i>✦</i>'
            + e(CFG.get('site_name', 'OpenPick')) + '</a><nav>' + links + '</nav><div class="hd-r">'
            '<div class="srch"><input type="text" placeholder="' + e(t(l, 's.placeholder'))
            + '" autocomplete="off"><div class="pop"></div></div>'
            '<a class="lnk" href="../' + other + '/' + page + '.html">' + ('EN' if l == 'zh' else '中文') + '</a>'
            '<button class="btn" id="themeBtn">☾</button></div></div></header>' + body
            + '<footer><div class="wrap"><span>© ' + str(NOW.year) + ' '
            + e(CFG.get('site_name', 'OpenPick')) + ' · ' + e(t(l, 'f.note')) + '</span>'
            '<div class="r"><a href="../' + other + '/index.html">' + ('English' if l == 'zh' else '简体中文')
            + '</a><a href="https://github.com/' + e(CFG.get('submit_repo', ''))
            + '" target="_blank" rel="noopener">GitHub ↗</a></div></div></footer>'
            '<div class="toast" id="toast"></div>' + js + '</body></html>')


def card(i, l):
    rid = i['resource_id']
    tags = list(dict.fromkeys((i.get('topics') or []) + (i.get('tags') or [])))[:4]
    tg = ''.join('<i class="tg">#' + e(x) + '</i>' for x in tags)
    nt = note(i, l)
    return ('<article class="card"><h3><a href="p-' + e(rid) + '.html">' + e(i['full_name']) + '</a></h3>'
            + ('<span class="note">' + e(nt) + '</span>' if nt else '')
            + '<p>' + e(desc(i, l)) + '</p><div>' + tg + '</div>'
            '<div class="meta"><span class="pill star">★ ' + stars(i.get('stars')) + '</span>'
            '<span><i class="dot" style="background:'
            + COLORS.get(i.get('language'), '#8d95a3') + '"></i>' + e(i.get('language') or '—')
            + '</span><span>' + e(t(l, 'm.updated'))
            + ' ' + e((i.get('pushed_at') or '')[:10]) + '</span></div></article>')


def side(l, toc=''):
    langs, topics = {}, {}
    for i in PROJECTS:
        k = i.get('language') or '—'
        langs[k] = langs.get(k, 0) + 1
        for x in (i.get('topics') or []):
            topics[x] = topics.get(x, 0) + 1
    a = '<div class="side-box"><h4>' + e(t(l, 'side.lang')) + '</h4>'
    for k, v in sorted(langs.items(), key=lambda x: -x[1])[:8]:
        a += '<a href="ranking.html?tab=lang">' + e(k) + ' <span style="float:right">' + str(v) + '</span></a>'
    a += '</div><div class="side-box"><h4>' + e(t(l, 'side.topic')) + '</h4>'
    for k, v in sorted(topics.items(), key=lambda x: -x[1])[:10]:
        a += '<a href="search.html?q=' + urllib.parse.quote(k) + '">#' + e(k) + ' <span style="float:right">' + str(v) + '</span></a>'
    return toc + a + '</div><!-- AD:side -->'


def page_home(l):
    ps = sorted(PROJECTS, key=lambda x: -(x.get('stars') or 0))
    lim = int(CFG.get('per_page') or 24)
    feat = [x for x in ps if x.get('featured')]
    cards = ''.join(card(i, l) for i in ps[:lim])
    body = ('<main class="wrap"><section class="hero"><h1>' + e(t(l, 'home.title')) + '</h1><p>'
            + e(t(l, 'home.sub')) + '</p><div class="stats"><div><b>' + str(len(PROJECTS)) + '</b>'
            + e(t(l, 'm.projects')) + '</div><div><b>' + str(len(set(i.get('language') for i in PROJECTS)))
            + '</b>' + e(t(l, 'm.langs')) + '</div><div><b>' + str(INCOMING_COUNT) + '</b>'
            + e(t(l, 'm.pending')) + '</div><div><b>' + e(t(l, 'm.daily')) + '</b>'
            + e(t(l, 'm.updated')) + '</div></div></section><!-- AD:home_top -->'
            + ('<div class="tabs"><button class="on" data-tab="all">' + e(t(l, 'home.all')) + '</button></div>' if feat else '')
            + '<div class="cols"><div class="grid g2">' + cards + '</div><aside>' + side(l) + '</aside></div></main>'
            '<!-- AD:footer -->')
    return shell(l, t(l, 'home.title'), body, 'index')


def page_ranking(l):
    lim = int(CFG.get('ranking_limit') or 20)
    by_stars = sorted(PROJECTS, key=lambda x: -(x.get('stars') or 0))[:lim]
    by_new = sorted(PROJECTS, key=lambda x: x.get('accepted_at') or '', reverse=True)[:lim]
    by_push = sorted(PROJECTS, key=lambda x: x.get('pushed_at') or '', reverse=True)[:lim]
    trend = [x for x in PROJECTS if any(s.startswith('trending') for s in (x.get('sources') or []))][:lim]
    tabs = [('stars', 'r.stars'), ('trend', 'r.trend'), ('new', 'r.new'),
            ('recent', 'r.recent'), ('lang', 'r.lang')]
    btns = ''.join('<button class="' + ('on' if n == 0 else '') + '" data-tab="' + k + '">' + e(t(l, tk)) + '</button>'
                   for n, (k, tk) in enumerate(tabs))
    groups = ''
    for k, items in [('stars', by_stars), ('trend', trend), ('new', by_new), ('recent', by_push)]:
        rows = ''
        for n, i in enumerate(items):
            cls = 'g' if n == 0 else ('s' if n == 1 else ('b' if n == 2 else ''))
            rows += ('<div class="rank-row"><span class="no ' + cls + '">' + str(n + 1)
                     + '</span><div class="bd"><b><a href="p-' + e(i['resource_id']) + '.html">'
                     + e(i['full_name']) + '</a></b><span>' + e(desc(i, l)) + '</span></div>'
                     '<span class="meta">★ ' + stars(i.get('stars')) + '</span></div>')
        groups += '<div data-tab="' + k + '"' + ('' if k == 'stars' else ' style="display:none"') + '>' + (rows or '<p>' + e(t(l, 's.none')) + '</p>') + '</div>'
    by_lang = {}
    for i in PROJECTS:
        by_lang.setdefault(i.get('language') or '—', []).append(i)
    lg = ''
    for name, arr in by_lang.items():
        arr = sorted(arr, key=lambda x: -(x.get('stars') or 0))
        lg += '<div class="side-box"><h4>' + e(name) + '</h4>'
        for n, i in enumerate(arr[:5]):
            lg += ('<a href="p-' + e(i['resource_id']) + '.html">' + str(n + 1) + '. ' + e(i['full_name'])
                   + ' · ★ ' + stars(i.get('stars')) + '</a>')
        lg += '</div>'
    # 外层已有 aside，语言榜内不再重复 side()，否则 AD:side 锚点出现两次
    groups += '<div data-tab="lang" style="display:none"><div class="cols"><div>' + lg + '</div></div></div>'
    body = ('<main class="wrap"><section class="hero"><h1>' + e(t(l, 'r.title')) + '</h1><p>'
            + e(t(l, 'r.sub')) + '</p></section><!-- AD:home_top --><div class="tabs">' + btns
            + '</div><div class="cols"><div>' + groups + '</div><aside>' + side(l)
            + '</aside></div></main><!-- AD:footer -->')
    return shell(l, t(l, 'r.title'), body, 'ranking')


def page_search(l):
    cards = ''
    for i in PROJECTS:
        hay = (i['full_name'] + ' ' + desc(i, l) + ' ' + ' '.join(i.get('topics') or [])).lower()
        cards += '<div data-name="' + e(hay) + '">' + card(i, l) + '</div>'
    body = ('<main class="wrap"><section class="hero"><h1>' + e(t(l, 's.title')) + '</h1><p>'
            + e(t(l, 's.sub')) + '</p></section><div class="cols"><div><div class="srch" style="margin-bottom:14px">'
            '<input id="bigSearch" type="text" placeholder="' + e(t(l, 's.placeholder'))
            + '" style="width:100%"><div class="pop" style="left:0;right:0;width:auto"></div></div>'
            '<div class="grid" id="cards">' + cards + '</div></div><aside>' + side(l)
            + '</aside></div></main><!-- AD:footer -->')
    js = ('<script>(function(){var b=document.getElementById("bigSearch");if(!b)return;'
          'b.addEventListener("input",function(){var q=b.value.trim().toLowerCase();'
          'document.querySelectorAll("#cards > div").forEach(function(d){d.style.display=(!q||d.dataset.name.indexOf(q)>=0)?"":"none"})});'
          'var p=new URLSearchParams(location.search).get("q");if(p){b.value=p;b.dispatchEvent(new Event("input"))}})();</script>')
    return shell(l, t(l, 's.title'), body, 'search', '', js)


def page_submit(l):
    repo = CFG.get('submit_repo') or ''
    sub = CFG.get('submit') or {}
    mode = sub.get('mode') or 'auto'
    endpoint = sub.get('endpoint') or ''
    sitekey = sub.get('turnstile_sitekey') or ''
    turnstile = (('<div class="cf-turnstile" data-sitekey="' + e(sitekey) + '" data-theme="auto"></div>'
                  '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>')
                 if sitekey else '')
    body = ('<main class="wrap"><section class="hero"><h1>' + e(t(l, 'sub.title')) + '</h1><p>'
            + e(t(l, 'sub.desc')) + '</p></section><!-- AD:home_top --><div class="cols"><div>'
            '<form class="form" id="sf"><label>' + e(t(l, 'sub.repo')) + ' <b>*</b></label>'
            '<input name="repo" required placeholder="owner/repo"><div class="hint">' + e(t(l, 'sub.repo.hint')) + '</div>'
            '<label>' + e(t(l, 'sub.reason')) + ' <b>*</b></label><textarea name="reason" required></textarea>'
            '<div class="hint">' + e(t(l, 'sub.reason.hint')) + '</div><label>' + e(t(l, 'sub.cat')) + '</label>'
            '<select name="cat"><option value="tool">Tool / 工具</option><option value="framework">Framework / 框架</option>'
            '<option value="ai">AI</option><option value="library">Library / 类库</option><option value="other">Other / 其他</option></select>'
            + turnstile
            + '<div class="acts"><button class="btn btn-p" type="submit" id="subBtn">' + e(t(l, 'sub.btn')) + '</button>'
            '<a class="btn" href="https://github.com/' + e(repo) + '/issues/new/choose" target="_blank" rel="noopener">'
            + e(t(l, 'sub.alt')) + ' ↗</a></div><div class="msg" id="sm"></div></form></div><aside>'
            + side(l) + '</aside></div></main><!-- AD:footer -->')
    cfg = {'mode': mode, 'endpoint': endpoint, 'key': sub.get('submit_key') or '',
           'repo': repo, 'lang': l, 'bad': t(l, 'sub.bad'), 'ok': t(l, 'sub.ok'),
           'sending': t(l, 'sub.sending'), 'fail': t(l, 'sub.fail'), 'alt': t(l, 'sub.alt')}
    js = ('<script>window.OP_SUBMIT=' + jout(cfg) + ';</script>'
          '<script>' + SUBMIT_JS + '</script>')
    return shell(l, t(l, 'sub.title'), body, 'submit', '', js)


def page_detail(i, l):
    rid, full = i['resource_id'], i['full_name']
    tags = ''.join('<i class="tg">#' + e(x) + '</i>' for x in dict.fromkeys((i.get('topics') or []) + (i.get('tags') or [])))
    nt = note(i, l)
    rel = i.get('release')
    dl = ''
    if rel and rel.get('assets'):
        rows = ''
        for a in rel['assets']:
            off = (rel.get('base') or '') + a['file']
            rows += ('<div class="asset"><div class="f">' + e(a['file']) + '<small>' + e(a.get('size'))
                     + '</small></div><a class="btn dl-link" data-official="' + e(off) + '" href="' + e(off)
                     + '" target="_blank" rel="noopener nofollow">' + e(t(l, 'd.dl')) + '</a></div>')
        dl = ('<section class="comm" id="s-download"><h2>' + e(t(l, 'd.release')) + '</h2><p class="comm-hint">'
              + e(t(l, 'd.release.note')) + ' · ' + e(rel.get('version')) + ' · ' + e(rel.get('published_at'))
              + '</p>' + proxy_block(l) + rows + '<!-- AD:detail_end --></section>')
    g = waline_block(l, rid, SITE + '/' + l + '/p-' + rid + '.html')
    rm = readme(i)
    main_rm = rm['zh'] if l == 'zh' else rm['en']
    alt_rm = rm['en'] if l == 'zh' else rm['zh']
    two = main_rm['path'] != alt_rm['path'] and main_rm['md'] != alt_rm['md']
    seg = ('<div class="seg" id="rmSeg"><button class="on" data-rm="main">'
           + ('简体中文' if l == 'zh' else 'English') + '</button><button data-rm="alt">'
           + ('English' if l == 'zh' else '简体中文') + '</button></div>') if two else ''
    rm_main = md2html(main_rm['md'], full)
    rm_alt = md2html(alt_rm['md'], full) if two else ''
    toc = md_toc(rm_main, l)
    body = ('<main class="wrap"><div style="padding:26px 0 12px"><a href="../index.html">← '
            + e(t(l, 'd.back')) + '</a></div><div class="cols"><div>'
            '<div class="card" style="margin-bottom:18px;padding:22px 24px"><h3 style="font-size:23px;letter-spacing:-.5px">'
            + e(full) + '</h3>'
            + ('<span class="note">' + e(nt) + '</span>' if nt else '') + '<p>' + e(desc(i, l)) + '</p><div>' + tags + '</div>'
            '<div class="meta" style="margin-top:12px"><span class="pill star">★ ' + stars(i.get('stars')) + '</span><span>'
            + e(i.get('language') or '—') + '</span><span>' + e(t(l, 'm.updated')) + ' '
            + e((i.get('pushed_at') or '')[:10]) + '</span><span>' + e(t(l, 'd.diff')) + ' '
            + e(t(l, 'diff.' + (i.get('difficulty') or 'beginner'))) + '</span></div>'
            '<div class="acts" style="margin-top:16px;display:flex;gap:8px"><a class="btn btn-p" href="https://github.com/'
            + e(full) + '" target="_blank" rel="noopener">GitHub ↗</a><button class="btn" data-copy="'
            + e(SITE + '/' + l + '/p-' + rid + '.html') + '">' + e(t(l, 'd.copy')) + '</button></div></div>' + dl
            + '<div class="rm-bar"><b>' + e(t(l, 'd.readme')) + '</b>' + seg + '</div>'
            + md_wrap(rm_main, l, 'mdBody')
            + (md_wrap(rm_alt, l, 'mdBodyAlt', True) if two else '')
            + ('' if (rel and rel.get('assets')) else '<!-- AD:detail_end -->') + g
            + '</div><aside>' + side(l, toc) + '</aside></div></main><!-- AD:footer -->')
    return shell(l, full, body, 'p-' + rid)


# ------------------------------------------------------------------ 输出

def out(rel, content):
    p = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'w', encoding='utf-8') as f:
        f.write(content)


def main():
    count = 0
    for l in LANGS:
        out(l + '/index.html', ad_inject(page_home(l), l, LIST_SLOTS)); count += 1
        out(l + '/ranking.html', ad_inject(page_ranking(l), l, LIST_SLOTS)); count += 1
        out(l + '/search.html', ad_inject(page_search(l), l, LIST_SLOTS)); count += 1
        out(l + '/submit.html', ad_inject(page_submit(l), l, LIST_SLOTS)); count += 1
        for i in PROJECTS:
            out(l + '/p-' + i['resource_id'] + '.html', ad_inject(page_detail(i, l), l, DETAIL_SLOTS)); count += 1
        out(l + '/search-index.json', jout([{
            'full_name': i['full_name'], 'description': desc(i, l),
            'tags': i.get('topics') or [], 'category': cat(i, l),
            'url': 'p-' + i['resource_id'] + '.html'} for i in PROJECTS]))
    def_lang = CFG.get('default_lang', 'zh')
    out('index.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
        + def_lang + '/index.html"><script>location.replace("' + def_lang
        + '/index.html")</script><title>OpenPick</title></head><body></body></html>')
    count += 1
    sm = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    for l in LANGS:
        urls = ['index.html', 'ranking.html', 'search.html', 'submit.html'] + \
               ['p-' + i['resource_id'] + '.html' for i in PROJECTS]
        for u in urls:
            sm += '<url><loc>' + e(SITE + '/' + l + '/' + u) + '</loc></url>'
    sm += '</urlset>'
    out('sitemap.xml', sm)
    if _SNAP_DIRTY:
        p = os.path.join(D, 'readmes.json')
        tmp = p + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as fh:
            json.dump(SNAP, fh, ensure_ascii=False, indent=1)
        os.replace(tmp, p)
        print('   README 本地快照已补齐：%d 个项目（不入库）' % len(SNAP))
    print('✅ 生成完成：%d 个页面 · %s · %d 个项目 · public/' %
          (count, '/'.join(LANGS), len(PROJECTS)))


if __name__ == '__main__':
    main()
