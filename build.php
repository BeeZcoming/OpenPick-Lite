<?php
/**
 * OpenPick Lite · 静态站构建器（唯一生成入口）
 *   php build.php
 * 产出 public/，纯 HTML，0 数据库、0 运行时请求（评论需手动点开）。
 */
declare(strict_types=1);
require_once __DIR__ . '/common.php';

$ROOT = OP_ROOT;
$CFG = cfg();
$SITE = rtrim((string)($CFG['site_url'] ?? ''), '/');
$LANGS = langs();
$NOW = gmdate('Y-m-d\TH:i:s\Z');
$PROJECTS = jread('projects')['items'] ?? [];
$INCOMING_COUNT = count(jread('incoming')['items'] ?? []);

/* ------------------------------------------------------------------ 样式 */

$CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#f7f8fc;--bg2:#eef0fa;--card:#fff;--line:#e6e8f1;--text:#171a2b;--muted:#6b7280;--brand:#5b5bd6;--brand2:#a855f7;--soft:#eeeefe;--ok:#128a4c;--warn:#b7791f;--radius:16px;--shadow:0 1px 2px rgba(24,28,60,.04),0 10px 30px rgba(24,28,60,.07);--shadow-h:0 4px 12px rgba(24,28,60,.07),0 18px 44px rgba(24,28,60,.10)}
html[data-theme=dark]{--bg:#0b0e16;--bg2:#111527;--card:#151a27;--line:#2a3142;--text:#e9edf7;--muted:#98a1b5;--soft:#252a44;--shadow:0 1px 2px rgba(0,0,0,.3),0 10px 30px rgba(0,0,0,.28);--shadow-h:0 4px 12px rgba(0,0,0,.35),0 18px 44px rgba(0,0,0,.4)}
html{-webkit-text-size-adjust:100%}
body{font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;background:var(--bg);color:var(--text);letter-spacing:.01em}
a{color:var(--brand);text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:1160px;margin:auto;padding:0 24px}
/* 顶栏：毛玻璃 */
header{background:color-mix(in srgb,var(--card) 88%,transparent);backdrop-filter:saturate(180%) blur(14px);-webkit-backdrop-filter:saturate(180%) blur(14px);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.hd{height:64px;display:flex;align-items:center;gap:22px}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;color:var(--text);letter-spacing:-.4px}
.logo:hover{text-decoration:none}
.logo i{width:30px;height:30px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#a855f7);display:grid;place-items:center;color:#fff;font-style:normal;font-size:16px;box-shadow:0 4px 12px rgba(99,102,241,.35)}
nav{display:flex;gap:20px;flex:1}nav a{color:var(--muted);font-size:13.5px;font-weight:550;position:relative;padding:4px 0}
nav a:hover{color:var(--brand);text-decoration:none}
.hd-r{display:flex;gap:8px;align-items:center}
#themeBtn{min-width:38px;justify-content:center}
.btn,.lnk{border:1px solid var(--line);background:var(--card);color:var(--text);padding:7px 13px;border-radius:10px;font-size:13px;font-weight:550;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.16s}
.btn:hover,.lnk:hover{border-color:var(--brand);color:var(--brand);text-decoration:none;transform:translateY(-1px)}
.btn-p{background:linear-gradient(135deg,#6366f1,#7c5cf0);color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(99,102,241,.28)}
.btn-p:hover{color:#fff;filter:brightness(1.07)}
.srch{position:relative}.srch input{width:220px;padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--text);font-size:13px;transition:.16s}
.srch input:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px var(--soft);background:var(--card)}
.srch .pop{display:none;position:absolute;top:42px;right:0;width:350px;max-height:390px;overflow:auto;background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow-h);padding:6px;z-index:30}
.srch .pop.on{display:block}.srch .pop a{display:block;padding:9px 11px;border-radius:9px;color:var(--text)}.srch .pop a:hover{background:var(--soft);text-decoration:none}
.srch .pop b{font-size:13px}.srch .pop span{display:block;font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Hero */
.hero{position:relative;padding:48px 0 26px}
.hero:before{content:"";position:absolute;left:-90px;top:-70px;width:520px;height:300px;pointer-events:none;z-index:-1;
 background:radial-gradient(ellipse at center,rgba(99,102,241,.13),rgba(168,85,247,.07) 45%,transparent 72%)}
.hero h1{font-size:36px;line-height:1.25;letter-spacing:-1.2px;font-weight:820;background:linear-gradient(100deg,var(--text) 30%,#5b5bd6 78%,#a855f7);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
html[data-theme=dark] .hero h1{background:linear-gradient(100deg,#fff 25%,#a5a8ff 78%,#d8a8ff);-webkit-background-clip:text;background-clip:text}
.hero p{color:var(--muted);margin-top:10px;font-size:14.5px}
.stats{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
.stats>div{min-width:118px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 16px;box-shadow:var(--shadow);font-size:12px;color:var(--muted)}
.stats b{display:block;font-size:23px;line-height:1.25;color:var(--text);font-weight:780;font-variant-numeric:tabular-nums;letter-spacing:-.5px}
/* 布局 */
.cols{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:24px;padding-bottom:56px;align-items:start}
@media(max-width:900px){.cols{grid-template-columns:minmax(0,1fr)}nav{display:none}}
.grid{display:grid;gap:14px;min-width:0}
.grid.g2{grid-template-columns:repeat(2,minmax(0,1fr))}
@media(max-width:760px){.grid.g2{grid-template-columns:minmax(0,1fr)}}
/* 卡片 */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;box-shadow:var(--shadow);transition:.18s;min-width:0;overflow-wrap:anywhere}
.card:hover{border-color:color-mix(in srgb,var(--brand) 45%,var(--line));box-shadow:var(--shadow-h);transform:translateY(-2px)}
.card h3{font-size:16.5px;font-weight:720;letter-spacing:-.2px}.card h3 a{color:var(--text)}.card h3 a:hover{color:var(--brand)}
.card p{color:var(--muted);font-size:13px;margin:8px 0}
.card .note{display:block;border-left:3px solid var(--brand);background:linear-gradient(90deg,var(--soft),transparent);padding:7px 11px;border-radius:0 8px 8px 0;color:var(--text);font-size:13px;font-weight:600;margin:9px 0}
.meta{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--muted);align-items:center}
.meta .dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px}
.tg{display:inline-block;background:var(--soft);color:var(--brand);border-radius:6px;padding:2px 8px;font-size:11px;font-style:normal;font-weight:600;margin:0 5px 5px 0}
.pill{display:inline-flex;align-items:center;gap:5px;background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:650;color:var(--muted)}
.pill.star{color:var(--warn);background:color-mix(in srgb,#f59e0b 10%,var(--card));border-color:color-mix(in srgb,#f59e0b 25%,var(--line))}
/* 侧栏 */
aside{position:sticky;top:84px;min-width:0}
.side-box{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px 17px;margin-bottom:14px;box-shadow:var(--shadow)}
.side-box h4{font-size:13px;font-weight:720;margin-bottom:12px;display:flex;align-items:center;gap:7px}
.side-box h4:before{content:"";width:3px;height:14px;border-radius:2px;background:linear-gradient(var(--brand),var(--brand2))}
.side-box a{display:block;font-size:12.5px;padding:6px 0;color:var(--muted);border-bottom:1px solid color-mix(in srgb,var(--line) 60%,transparent)}
.side-box a:last-child{border-bottom:0}.side-box a:hover{color:var(--brand);text-decoration:none}
.side-box a span{float:right;color:var(--muted);font-variant-numeric:tabular-nums}
.toc a{display:block;font-size:12.5px;color:var(--muted);padding:4px 0 4px 10px;border-left:2px solid var(--line);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.toc a:hover,.toc a.on{border-left-color:var(--brand);color:var(--brand);text-decoration:none}
.toc a.lv3{padding-left:22px;font-size:12px}
/* Tab / 榜单 */
.tabs{display:flex;gap:7px;flex-wrap:wrap;margin:20px 0}.tabs button{border:1px solid var(--line);background:var(--card);border-radius:10px;padding:7px 14px;font-size:13px;font-weight:550;cursor:pointer;color:var(--muted);transition:.16s}
.tabs button:hover{border-color:var(--brand);color:var(--brand)}.tabs button.on{background:linear-gradient(135deg,#6366f1,#7c5cf0);color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(99,102,241,.25)}
.rank-row{display:flex;align-items:center;gap:15px;background:var(--card);border:1px solid var(--line);border-radius:13px;padding:12px 16px;margin-bottom:9px;box-shadow:var(--shadow);transition:.16s;min-width:0}
.rank-row:hover{border-color:var(--brand);transform:translateX(2px)}
.rank-row .no{width:30px;flex:none;text-align:center;font-weight:800;font-size:14px;color:var(--muted);font-variant-numeric:tabular-nums}
.rank-row .no.g{color:#fff;background:linear-gradient(135deg,#f5b942,#e0951a);border-radius:8px;padding:2px 0}
.rank-row .no.s{color:#fff;background:linear-gradient(135deg,#9aa6b8,#78859a);border-radius:8px;padding:2px 0}
.rank-row .no.b{color:#fff;background:linear-gradient(135deg,#cd8b5b,#a96a3c);border-radius:8px;padding:2px 0}
.rank-row .bd{flex:1;min-width:0}.rank-row .bd b{font-size:14px}.rank-row .bd span{display:block;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-row .st{flex:none;font-size:12.5px;font-weight:700;color:var(--warn)}
/* 广告 */
.ad{background:linear-gradient(135deg,color-mix(in srgb,var(--soft) 55%,var(--card)),var(--card));border:1px dashed color-mix(in srgb,var(--brand) 28%,var(--line));border-radius:var(--radius);padding:14px 17px;margin:14px 0}
.ad[hidden]{display:none}.ad-hd span{font-size:10.5px;letter-spacing:.6px;color:var(--muted);border:1px solid var(--line);border-radius:5px;padding:1px 6px}
.ad-v[hidden]{display:none}.ad-v h4{font-size:14px;margin:8px 0 3px;font-weight:700}.ad-v p{font-size:12.5px;color:var(--muted)}.ad-cta{display:inline-block;margin-top:8px;font-size:12px;font-weight:700}
/* README 排版 */
.rm-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:var(--card);border:1px solid var(--line);border-radius:var(--radius) var(--radius) 0 0;padding:13px 20px;border-bottom:0;margin-top:0}
.rm-bar b{font-size:14px;font-weight:720;display:flex;align-items:center;gap:8px}
.rm-bar b:before{content:"";width:3px;height:15px;border-radius:2px;background:linear-gradient(var(--brand),var(--brand2))}
.md{background:var(--card);border:1px solid var(--line);border-radius:0 0 var(--radius) var(--radius);padding:26px 30px;box-shadow:var(--shadow);line-height:1.85;overflow-wrap:anywhere;word-break:break-word;font-size:14px}
.md-clip .md{border-top:0}
.md h1,.md h2,.md h3,.md h4{margin:24px 0 12px;line-height:1.35;font-weight:750;letter-spacing:-.3px;scroll-margin-top:80px}
.md h1{font-size:27px;margin-top:4px}.md h1:first-child{margin-top:0}
.md h2{font-size:21px;border-bottom:1px solid var(--line);padding-bottom:9px;margin-top:30px}
.md h3{font-size:17px}.md h4{font-size:15px}
.md>p:first-child{margin-top:0}
.md p{margin:11px 0}.md ul,.md ol{padding-left:24px;margin:11px 0}.md li{margin:4px 0}
.md a{text-decoration:underline;text-decoration-color:color-mix(in srgb,var(--brand) 35%,transparent);text-underline-offset:2px}
.md code{background:var(--bg2);border:1px solid var(--line);border-radius:5px;padding:1px 5px;font-size:12.5px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.md pre{background:#111626;color:#e6edf7;border:1px solid #232a3d;border-radius:11px;padding:15px 17px;overflow:auto;margin:14px 0;line-height:1.6}
html[data-theme=light] .md pre{background:#12162a}
.md pre code{border:0;background:none;padding:0;color:inherit;font-size:12.5px}
.md img{max-width:100%;height:auto;border-radius:10px;margin:8px 0}
.md blockquote{border-left:3px solid var(--brand);background:var(--soft);padding:9px 15px;border-radius:0 9px 9px 0;color:var(--muted);margin:13px 0}
.md hr{border:0;border-top:1px solid var(--line);margin:22px 0}
.md table{width:100%;border-collapse:collapse;margin:14px 0;font-size:13px;display:block;overflow-x:auto}
.md th,.md td{border:1px solid var(--line);padding:8px 11px;text-align:left;white-space:nowrap}
.md th{background:var(--bg2);font-weight:700}
.md tr:nth-child(even) td{background:color-mix(in srgb,var(--bg2) 45%,var(--card))}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden}.seg button{border:0;background:var(--card);padding:5px 12px;font-size:12.5px;cursor:pointer;color:var(--muted);font-weight:600}.seg button.on{background:var(--brand);color:#fff}
/* 长文折叠 */
.md-clip{position:relative;margin-top:0}
.md-clip.collapsed>.md{max-height:760px;overflow:hidden}
.md-clip.collapsed:after{content:"";position:absolute;left:1px;right:1px;bottom:38px;height:120px;pointer-events:none;background:linear-gradient(transparent,var(--card))}
.rm-more{display:flex;justify-content:center;border:1px solid var(--line);border-top:0;border-radius:0 0 var(--radius) var(--radius);background:var(--card);padding:11px;position:relative;z-index:2}
.rm-more button{border:0;background:var(--soft);color:var(--brand);font-weight:700;font-size:12.5px;padding:7px 18px;border-radius:9px;cursor:pointer}
/* 下载 / 评论区 */
.proxy-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0 16px;padding:10px 13px;background:var(--bg);border:1px solid var(--line);border-radius:10px}
.proxy-bar label{font-size:12.5px;font-weight:700}
.proxy-bar select{padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--text);font:inherit;font-size:12.5px}
.proxy-bar .hint{font-size:11.5px;color:var(--muted);margin:0}
.md-img{max-width:100%;height:auto}
.asset{display:flex;align-items:center;gap:12px;border:1px solid var(--line);border-radius:11px;padding:12px 15px;margin:9px 0;background:var(--bg)}.asset .f{flex:1;min-width:0;font-size:13px;font-weight:600;overflow-wrap:anywhere}.asset .f small{display:block;color:var(--muted);font-weight:400}
.comm{margin-top:22px;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow)}
.comm h2{font-size:16px;font-weight:720}.comm-hint{font-size:12.5px;color:var(--muted);margin:6px 0 13px}
/* 表单 / 页脚 */
.form{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);max-width:620px}
.form label{display:block;font-size:13px;font-weight:680;margin:16px 0 7px}.form label b{color:var(--warn)}
.form input,.form textarea,.form select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--text);font:inherit;font-size:13.5px;transition:.16s}
.form input:focus,.form textarea:focus,.form select:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px var(--soft);background:var(--card)}
.form textarea{min-height:110px;resize:vertical}.form .hint{font-size:11.5px;color:var(--muted);margin-top:5px}
.form .acts{margin-top:20px;display:flex;gap:10px;flex-wrap:wrap}.form .msg{margin-top:14px;font-size:13px}
footer{border-top:1px solid var(--line);background:var(--card);padding:24px 0;margin-top:20px;font-size:12.5px;color:var(--muted)}
footer .r{float:right;display:flex;gap:16px}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--text);color:var(--bg);padding:9px 16px;border-radius:9px;font-size:13px;opacity:0;pointer-events:none;transition:.2s;z-index:50}
.toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
@media(max-width:640px){.hero h1{font-size:27px}.srch input{width:138px}.srch .pop{width:290px}.wrap{padding:0 16px}.md{padding:18px}.md h1{font-size:22px}.md h2{font-size:18px}footer .r{float:none;margin-top:8px}}
#waline{margin-top:14px}
html[data-theme=dark]{--waline-bg-color:#151a27;--waline-text-color:#e9edf7;--waline-border-color:#2a3142;--waline-primary-color:#8b8bf0;--waline-bg-color-light:#1c2333}
CSS;

/* ------------------------------------------------------------------ 脚本 */

$JS = <<<'JS'
(function(){
var $=function(s){return document.querySelector(s)};
var $$=function(s){return [].slice.call(document.querySelectorAll(s))};
function toast(m){var x=$('#toast');if(!x)return;x.textContent=m;x.classList.add('on');clearTimeout(x._t);x._t=setTimeout(function(){x.classList.remove('on')},1900)}
/* 主题 */
function setTheme(v){document.documentElement.dataset.theme=v;try{localStorage.setItem('op-theme',v)}catch(e){}
 var b=$('#themeBtn');if(b)b.textContent=v==='dark'?'☀':'☾';
 // Waline 由 dark:'html[data-theme="dark"]' 自动跟随，不需要手动同步
}
var tb=$('#themeBtn');if(tb)tb.onclick=function(){setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark')};
try{var v=localStorage.getItem('op-theme');if(v)setTheme(v)}catch(e){}
/* 搜索（索引内联，离线可用） */
var IDX=window.OP_INDEX||[];
function score(it,q){var n=(it.full_name||'').toLowerCase(),d=(it.description||'').toLowerCase(),g=(it.tags||[]).join(' ').toLowerCase(),c=(it.category||'').toLowerCase();
 var parts=q.toLowerCase().split(/\s+/).filter(Boolean),s=0;
 for(var i=0;i<parts.length;i++){var p=parts[i],hit=0;
  if(n.indexOf(p)===0)hit+=12;else if(n.indexOf(p)>=0)hit+=9;
  if(g.indexOf(p)>=0)hit+=7;if(d.indexOf(p)>=0)hit+=3;if(c.indexOf(p)>=0)hit+=2;
  if(!hit)return 0;s+=hit}
 return s}
function render(q,box){if(!box)return;if(!q.trim()){box.classList.remove('on');box.innerHTML='';return}
 var out=IDX.map(function(it){return{it:it,s:score(it,q)}}).filter(function(x){return x.s>0}).sort(function(a,b){return b.s-a.s}).slice(0,8);
 box.innerHTML=out.length?out.map(function(x){return '<a href="'+x.it.url+'"><b>'+x.it.full_name+'</b><span>'+(x.it.description||'')+'</span></a>'}).join('')
  :'<a><b>—</b><span>'+(window.OP_NO_RESULT||'No result')+'</span></a>';
 box.classList.add('on')}
$$('.srch input').forEach(function(inp){var box=inp.parentNode.querySelector('.pop');
 inp.addEventListener('input',function(){render(inp.value,box)});
 inp.addEventListener('focus',function(){if(inp.value)render(inp.value,box)});
 inp.addEventListener('keydown',function(e){if(e.key==='Escape'){box.classList.remove('on');inp.blur()}
  if(e.key==='Enter'){var a=box.querySelector('a[href]');if(a)location.href=a.getAttribute('href')}})});
document.addEventListener('click',function(e){$$('.srch .pop').forEach(function(p){if(!p.parentNode.contains(e.target))p.classList.remove('on')})});
document.addEventListener('keydown',function(e){if((e.metaKey||e.ctrlKey)&&e.key==='k'){e.preventDefault();var i=$('.srch input');if(i){i.focus();i.select()}}});
/* 榜单 Tab */
$$('.tabs button').forEach(function(b){b.onclick=function(){$$('.tabs button').forEach(function(x){x.classList.remove('on')});b.classList.add('on');
 $$('[data-tab]').forEach(function(g){g.style.display=g.dataset.tab===b.dataset.tab?'':'none'});
 try{var u=new URL(location.href);u.searchParams.set('tab',b.dataset.tab);history.replaceState(null,'',u.toString())}catch(e){}}});
var q=new URLSearchParams(location.search).get('tab');if(q){var b=$('.tabs button[data-tab="'+q+'"]');if(b)b.click()}
/* README 双语切换：切整个 clip 容器，折叠状态各自保留 */
$$('#rmSeg button').forEach(function(b){b.onclick=function(){$$('#rmSeg button').forEach(function(x){x.classList.remove('on')});b.classList.add('on');
 var alt=b.dataset.rm==='alt',m=$('#clip-mdBody'),a=$('#clip-mdBodyAlt'),toc=$('#rmToc');
 if(m)m.style.display=alt?'none':'';if(a)a.style.display=alt?'':'none';if(toc)toc.style.display=alt?'none':''}});
/* 下载通道：切换即改写 href，零网络请求；data-official 永远是官方直链 */
var px=$('#pxSel');if(px){var applyPx=function(){var pre=px.value;$$('.dl-link').forEach(function(a){
 var o=a.getAttribute('data-official');if(o)a.href=pre?pre+o:o})};px.onchange=applyPx;applyPx()}
/* README 图片：代理失败自动回退原图；原图也失败则整张隐藏，不显示破图 alt */
$$('.md-img').forEach(function(im){im.addEventListener('error',function(){
 var o=im.getAttribute('data-orig');
 if(o&&im.src!==o){im.src=o;im.removeAttribute('data-orig')}
 else{im.style.display='none'}})});
/* 广告排期：到点自动上/下线（构建期已过滤，这里只做二次校验） */
function adsTick(){var now=Date.now();$$('.ad').forEach(function(el){var vs=[].slice.call(el.querySelectorAll('.ad-v'));if(!vs.length)return;
 var live=vs.filter(function(v){var s=v.dataset.start?Date.parse(v.dataset.start):0,e=v.dataset.end?Date.parse(v.dataset.end):0;
  return (!s||s<=now)&&(!e||e>=now)});
 el.hidden=live.length===0;vs.forEach(function(v){v.hidden=live.indexOf(v)<0})})}
adsTick();setInterval(adsTick,60000);
/* Waline 懒加载：点按钮才注入 CSS + ES module，未点前访客侧 0 外部请求 */
var wl=$('#wLoad');if(wl&&window.OP_WALINE){wl.onclick=function(){
 var c=window.OP_WALINE;
 var l=document.createElement('link');l.rel='stylesheet';l.href=c.css;document.head.appendChild(l);
 var s=document.createElement('script');s.type='module';
 s.textContent='import { init } from '+JSON.stringify(c.js)+'; init('+JSON.stringify(c.options)+');';
 document.body.appendChild(s);wl.remove()}}
/* 长 README 折叠：默认收起，点按钮展开；两个语言版本互不干扰 */
$$('.rm-more button').forEach(function(b){b.onclick=function(){
 var wrap=b.closest('.md-clip'),on=wrap.classList.toggle('collapsed');
 b.textContent=on?(b.dataset.more||'展开全文'):(b.dataset.less||'收起');
 if(on)wrap.scrollIntoView({behavior:'smooth',block:'start'})}});
/* README 目录滚动高亮 */
var toc=$$('.toc a');if(toc.length){var heads=toc.map(function(a){var id=a.getAttribute('href').slice(1);return document.getElementById(id)}).filter(Boolean);
 var tick=function(){var y=window.scrollY+110,cur=0;
  heads.forEach(function(h,k){if(h.getBoundingClientRect().top+window.scrollY<=y)cur=k});
  toc.forEach(function(a,k){a.classList.toggle('on',k===cur)})};
 window.addEventListener('scroll',tick,{passive:true});tick()}
/* 复制 */
document.addEventListener('click',function(e){var c=e.target.closest('[data-copy]');if(c){try{navigator.clipboard.writeText(c.dataset.copy);toast('✓')}catch(x){}}});
window.OP_toast=toast;
})();
JS;

/* ------------------------------------------------------------------ 工具 */

function d($i, $l) { return $l === 'zh' ? ($i['description_zh'] ?? $i['description'] ?? '') : ($i['description'] ?? ''); }
function note($i, $l) { return $l === 'zh' ? ($i['editor_note'] ?? '') : ($i['editor_note_en'] ?? $i['editor_note'] ?? ''); }
function cat($i, $l) { return $l === 'zh' ? ($i['category'] ?? '') : ($i['category_en'] ?? $i['category'] ?? ''); }
function stars($n) { return $n >= 1000 ? round($n / 1000, 1) . 'k' : (string)(int)$n; }
function color($lang) {
    $m = ['Python' => '#3572A5', 'JavaScript' => '#f1e05a', 'TypeScript' => '#3178c6', 'Go' => '#00add8',
          'Rust' => '#dea584', 'Java' => '#b07219', 'C++' => '#f34b7d', 'Ruby' => '#701516', 'Zig' => '#f7a41d'];
    return $m[$lang] ?? '#8d95a3';
}
/** 少数仓库的 README.md 只有一行相对路径（如 biome），顺着抓真正的文档。 */
function rm_follow($raw, $full, $name) {
    $s = trim($raw);
    if (strlen($s) > 200 || !preg_match('~^[\w@./-]+\.md$~', $s)) return $raw;
    $target = ltrim($s, './');
    $base = strpos($name, '/') !== false ? substr($name, 0, strrpos($name, '/')) : '';
    $path = ltrim(($base !== '' ? $base . '/' : '') . $target, '/');
    foreach ([
        'https://raw.githubusercontent.com/' . $full . '/HEAD/' . $path,
        'https://cdn.jsdelivr.net/gh/' . $full . '@HEAD/' . $path,
    ] as $u) {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: OpenPickLite/1.0\r\n"]]);
        $t = @file_get_contents($u, false, $ctx);
        if ($t !== false && strlen($t) > 200) return $t;
    }
    return $raw;
}

/** 很多仓库 README 开头是 <div align=center><picture> 这类 HTML 包装。
 *  先转成等价 Markdown / 纯文本，再统一转义，避免标签被当正文显示；绝不执行原始 HTML。 */
/**
 * README 预处理：把常见 HTML 包装转成等价 Markdown，收集引用式链接定义。
 * 返回 [清理后的 md, refs]。所有原始 HTML 一律不执行，随后统一 h() 转义。
 */
function md_pre($md) {
    $md = html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $md = preg_replace('~<(script|style|iframe|object|svg|noscript)\b[\s\S]*?</\1>~i', '', $md);
    $md = preg_replace('~<!--[\s\S]*?-->~', '', $md);
    $md = preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
        $src = preg_match('~\bsrc=["\']([^"\']+)["\']~i', $m[0], $a) ? $a[1] : '';
        $alt = preg_match('~\balt=["\']([^"\']*)["\']~i', $m[0], $a) ? $a[1] : '';
        return $src !== '' ? '![' . $alt . '](' . $src . ')' : '';
    }, $md);
    $md = preg_replace_callback('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)</a>~i', function ($m) {
        return '[' . trim(preg_replace('~<[^>]+>~', '', $m[2])) . '](' . $m[1] . ')';
    }, $md);
    $md = preg_replace('~<br\s*/?>~i', "\n", $md);
    $md = preg_replace('~<source\b[^>]*>~i', '', $md);     // <picture> 的 srcset 不渲染
    $md = preg_replace('~</?[A-Za-z][^>]*>~', '', $md);    // 其余标签一律剥掉，只留文本
    // 引用式定义 [id]: url —— 收集后从正文移除，避免当成裸文本显示
    $refs = []; $lines = explode("\n", $md);
    foreach ($lines as $k => $ln) {
        if (preg_match('~^\s{0,3}\[([^\]]+)\]:\s*(\S+)~', $ln, $m)) {
            $refs[mb_strtolower(trim($m[1]), 'UTF-8')] = trim($m[2]);
            $lines[$k] = '';
            continue;
        }
        // 徽章行：整行只由 badge 图片 / 引用式链接组成，没有可读正文 → 直接删，避免碎片与破图
        $imgs = preg_match_all('~!\[~', $ln);
        if ($imgs) {
            $probe = preg_replace('~!\[[^\]]*\](?:\([^)]*\)|\[[^\]]*\])~', '', $ln);
            $probe = preg_replace('~\[[^\]]*\](?:\([^)]*\)|\[[^\]]*\])~', '', $probe);
            $probe = trim(preg_replace('~[\[\]()|·\s]+~u', '', $probe));
            if (mb_strlen($probe, 'UTF-8') < 6 && ($imgs >= 2 || preg_match('~\]\[[^\]]+\]~', $ln))) {
                $lines[$k] = '';
            }
        }
    }
    $md = preg_replace('~\n{3,}~', "\n\n", implode("\n", $lines));  // 收敛连续空行
    return [$md, $refs];
}

/** 单个图片：白名单校验，失败静默丢弃（不输出占位文字，避免徽章刷屏）。 */
function md_img($alt, $url, $full) {
    $u = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('~^https?://~i', $u)) {
        $u = 'https://raw.githubusercontent.com/' . $full . '/HEAD/' . ltrim($u, '/');
    }
    $real = readme_image_url($u, $full);
    if ($real === '') return '';
    return '<img class="md-img" src="' . h($real) . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer" data-orig="' . h($u) . '">';
}

/** 行内渲染：$s 已经过 h() 转义。 */
function inline($s, $full, $refs = []) {
    // 1) 徽章链接 [![alt](img)](url) —— 只保留图片，减少视觉噪音
    $s = preg_replace_callback('~\[!\[([^\]]*)\]\(([^)]+)\)\]\(([^)]+)\)~', function ($m) use ($full) {
        return md_img($m[1], $m[2], $full);
    }, $s);
    // 2) 引用式图片 ![alt][id] / ![alt][]
    $s = preg_replace_callback('~!\[([^\]]*)\]\[([^\]]*)\]~', function ($m) use ($full, $refs) {
        $id = mb_strtolower(trim($m[2]) !== '' ? trim($m[2]) : trim($m[1]), 'UTF-8');
        return isset($refs[$id]) ? md_img($m[1], $refs[$id], $full) : '';
    }, $s);
    // 3) 内联图片 ![alt](url)
    $s = preg_replace_callback('~!\[([^\]]*)\]\(([^)\s]+)\)~', function ($m) use ($full) {
        return md_img($m[1], $m[2], $full);
    }, $s);
    // 4) 引用式链接 [text][id] / [text][]
    $s = preg_replace_callback('~\[([^\]]+)\]\[([^\]]*)\]~', function ($m) use ($full, $refs) {
        $id = mb_strtolower(trim($m[2]) !== '' ? trim($m[2]) : trim($m[1]), 'UTF-8');
        $u = $refs[$id] ?? '';
        if ($u === '') return $m[1];
        return '<a href="' . h($u) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);
    // 5) 内联链接：外链 / 页内锚点 / 相对路径重写成 GitHub 绝对地址
    $s = preg_replace_callback('~\[([^\]]*)\]\(([^)\s]*)\)~', function ($m) use ($full) {
        $u = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($u === '') return $m[1];
        if ($u[0] === '#') return '<a href="' . h($u) . '">' . $m[1] . '</a>';
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $u)) {
            $u = 'https://github.com/' . $full . '/blob/HEAD/' . ltrim($u, '/');
        }
        return '<a href="' . h($u) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);
    // 6) 简写引用 [id]
    $s = preg_replace_callback('~\[([A-Za-z0-9_.\- ]{1,40})\](?!\()~', function ($m) use ($refs) {
        $id = mb_strtolower(trim($m[1]), 'UTF-8');
        return isset($refs[$id]) ? '<a href="' . h($refs[$id]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>' : $m[0];
    }, $s);
    // 7) 行内格式
    $s = preg_replace('~`([^`]+)`~', '<code>$1</code>', $s);
    $s = preg_replace('~\*\*([^*]+)\*\*~', '<b>$1</b>', $s);
    $s = preg_replace('~(?<!\w)\*([^*\n]+)\*(?!\w)~', '<i>$1</i>', $s);
    $s = preg_replace('~(?<!\w)_([^_\n]+)_(?!\w)~', '<i>$1</i>', $s);
    $s = preg_replace('~~~([^~]+)~~~', '<s>$1</s>', $s);
    return $s;
}

/** 块级渲染：代码块 / 标题 / 表格 / 分隔线 / 列表（含续行） / 引用 / 段落合并。 */
function md2html($md, $full) {
    [$md, $refs] = md_pre($md);
    $lines = explode("\n", $md);
    $out = ''; $inCode = false; $list = ''; $para = []; $li = '';

    $flushPara = function () use (&$out, &$para, $full, $refs) {
        if (!$para) return;
        $html = inline(h(implode(' ', $para)), $full, $refs);
        $para = [];
        if (trim(strip_tags($html)) !== '' || strpos($html, '<img') !== false) $out .= '<p>' . $html . '</p>';
    };
    $flushList = function () use (&$out, &$list, &$li, $full, $refs) {
        if ($li !== '') { $out .= '<li>' . inline(h($li), $full, $refs) . '</li>'; $li = ''; }
        if ($list !== '') { $out .= '</' . $list . '>'; $list = ''; }
    };
    $flushAll = function () use (&$flushPara, &$flushList) { $flushPara(); $flushList(); };

    for ($i = 0; $i < count($lines); $i++) {
        $raw = $lines[$i];
        $ln  = trim($raw);
        if (preg_match('~^```~', $ln)) {
            $flushAll();
            $out .= $inCode ? '</code></pre>' : '<pre><code>';
            $inCode = !$inCode; continue;
        }
        if ($inCode) { $out .= h($raw) . "\n"; continue; }
        if ($ln === '') { $flushAll(); continue; }
        // 水平线
        if (preg_match('~^(?:-{3,}|\*{3,}|_{3,})$~', $ln)) { $flushAll(); $out .= '<hr>'; continue; }
        // 表格：当前行 + 下一行必须是分隔行
        if (strpos($ln, '|') !== false && isset($lines[$i + 1])
            && preg_match('~^\s*\|?[\s:\-|]+\|?\s*$~', $lines[$i + 1]) && strpos($lines[$i + 1], '-') !== false) {
            $flushAll();
            $cells = function ($r) { return array_map('trim', explode('|', trim(trim($r), "| \t"))); };
            $head = $cells($lines[$i]); $i += 2;
            $out .= '<table><thead><tr>';
            foreach ($head as $c) $out .= '<th>' . inline(h($c), $full, $refs) . '</th>';
            $out .= '</tr></thead><tbody>';
            while ($i < count($lines) && strpos(trim($lines[$i]), '|') !== false && trim($lines[$i]) !== '') {
                $out .= '<tr>';
                foreach ($cells($lines[$i]) as $c) $out .= '<td>' . inline(h($c), $full, $refs) . '</td>';
                $out .= '</tr>'; $i++;
            }
            $i--; $out .= '</tbody></table>'; continue;
        }
        // 标题
        if (preg_match('~^(#{1,6})\s+(.*)$~', $ln, $m)) {
            $flushAll();
            $lv = min(4, strlen($m[1]));
            $title = preg_replace('~!\[[^\]]*\]\([^)]*\)~', '', $m[2]);
            $title = preg_replace('~!\[[^\]]*\]\[[^\]]*\]~', '', $title);
            $title = preg_replace('~\[\s*\]\([^)]*\)~', '', $title);
            $plain = strip_tags(preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $title));
            $id = preg_replace('~[^\w\x{4e00}-\x{9fa5}-]~u', '', $plain);
            $id = function_exists('mb_substr') ? mb_substr($id, 0, 60, 'UTF-8') : substr($id, 0, 60);
            $out .= '<h' . $lv . ' id="' . $id . '">' . inline(h(trim($title)), $full, $refs) . '</h' . $lv . '>';
            continue;
        }
        // 列表（无序 / 有序），续行自动并入上一个 li
        $isUl = preg_match('~^[-*+]\s+(.*)$~', $ln, $mu);
        $isOl = preg_match('~^\d+[.)]\s+(.*)$~', $ln, $mo);
        if ($isUl || $isOl) {
            $flushPara();
            if ($li !== '') { $out .= '<li>' . inline(h($li), $full, $refs) . '</li>'; }
            $want = $isUl ? 'ul' : 'ol';
            if ($list !== $want) { if ($list !== '') $out .= '</' . $list . '>'; $out .= '<' . $want . '>'; $list = $want; }
            $li = $isUl ? $mu[1] : $mo[1];
            continue;
        }
        if ($list !== '' && $li !== '' && $ln !== '' && !preg_match('~^(#{1,6}\s|>|```|\|)~', $ln)) {
            $li .= ' ' . $ln;   // 续行合并，避免 "more." 单独一段
            continue;
        }
        $flushList();
        // 引用
        if (preg_match('~^&gt;\s?(.*)$~', $ln, $m) || preg_match('~^>\s?(.*)$~', $ln, $m)) {
            $flushPara(); $out .= '<blockquote>' . inline(h($m[1]), $full, $refs) . '</blockquote>'; continue;
        }
        $para[] = $ln;
    }
    $flushAll();
    if ($inCode) $out .= '</code></pre>';
    return $out;
}

/** 从渲染后的 HTML 抽 h2/h3 生成目录（详情页侧栏用）。 */
function md_toc($html, $l) {
    preg_match_all('~<h([23]) id="([^"]*)">([\s\S]*?)</h\1>~', $html, $m, PREG_SET_ORDER);
    if (count($m) < 3) return '';
    $a = '<div class="side-box toc" id="rmToc"><h4>' . h(t($l, 'd.toc')) . '</h4>';
    foreach (array_slice($m, 0, 18) as $x) {
        $txt = trim(strip_tags(html_entity_decode($x[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($txt === '') continue;
        $a .= '<a class="' . ($x[1] === '3' ? 'lv3' : '') . '" href="#' . h($x[2]) . '">' . h($txt) . '</a>';
    }
    return $a . '</div>';
}

/** 长 README 折叠包装。$id 区分主/备语言版本，$hidden 为备用版本默认隐藏。 */
function md_wrap($html, $l, $id = 'mdBody', $hidden = false) {
    $long = strlen($html) > 9000;
    $s = '<div class="md-clip' . ($long ? ' collapsed' : '') . '" id="clip-' . $id . '"'
        . ($hidden ? ' style="display:none"' : '') . '><article class="md" id="' . $id . '">' . $html . '</article>';
    if ($long) {
        $s .= '<div class="rm-more"><button data-more="' . h(t($l, 'd.more')) . '" data-less="' . h(t($l, 'd.less')) . '">'
            . h(t($l, 'd.more')) . '</button></div>';
    }
    return $s . '</div>';
}

function zhRatio($s) {
    $s = mb_substr($s, 0, 4000);
    preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $s, $m);
    return [count($m[0]), mb_strlen($s)];
}
/** README 双语探测：先文件名判语言，再用内容兜底。失败返回 description。 */
function readme($i) {
    static $cache = [];
    static $netDead = false;   // 进程级：网络整体不可达时整轮放弃
    static $snap = null;       // 本地快照，命中即完全离线、不受 CDN 波动影响
    if ($snap === null) $snap = jread('readmes');
    $full = $i['full_name'];
    if (isset($cache[$full])) return $cache[$full];   // 中英两站共用同一次探测
    $rid = $i['resource_id'] ?? '';
    if (isset($snap[$rid]['zh']['md']) || isset($snap[$rid]['en']['md'])) {
        $z = $snap[$rid]['zh'] ?? null; $e = $snap[$rid]['en'] ?? null;
        return $cache[$full] = ['zh' => $z ?: $e, 'en' => $e ?: $z];
    }
    $r = proxies_cfg()['readme'];
    $timeout  = max(2, min(20, (int)($r['timeout'] ?? 8)));
    $maxBytes = max(20000, (int)($r['max_bytes'] ?? 200000));
    // 高频名优先，单仓库最多探测 4 次，避免把 CDN 打限流导致整轮降级
    $cands = [
        'README.zh-CN.md', 'README.md', 'README.zh.md', 'README.en.md',
        'docs/README.zh-CN.md', 'docs/README.md',
    ];
    $buckets = ['zh' => null, 'en' => null];
    $probes = 0; $errs = 0;
    static $srcFail = ['raw' => 0, 'jsdelivr' => 0];
    $ctx = stream_context_create(['http' => ['timeout' => min($timeout, 4), 'header' => "User-Agent: OpenPickLite/1.0\r\n"]]);
    foreach ($cands as $f) {
        if ($netDead || ($buckets['zh'] && $buckets['en']) || $probes >= 4) break;
        $probes++;
        // 双源自动切换：raw 优先（稳定），jsdelivr 备用；任一命中即用
        $urls = [
            'raw'      => 'https://raw.githubusercontent.com/' . $full . '/HEAD/' . $f,
            'jsdelivr' => 'https://cdn.jsdelivr.net/gh/' . $full . '@HEAD/' . $f,
        ];
        $raw = false;
        foreach ($urls as $src => $u) {
            if ($srcFail[$src] >= 2) continue;   // 该源连续失败，本轮后续仓库直接跳过
            $http_response_header = [];
            $raw = @file_get_contents($u, false, $ctx);
            if ($raw !== false) { $srcFail[$src] = 0; break; }
            $is404 = false;
            foreach ((array)($http_response_header ?? []) as $hl) {
                if (strpos($hl, ' 404 ') !== false) { $is404 = true; break; }
            }
            if ($is404) { $srcFail[$src] = 0; continue; }  // 404 只是文件不存在
            $srcFail[$src]++; $errs++;
        }
        if ($raw === false) {
            if ($errs >= 6) $netDead = true;
            continue;
        }
        if (strlen($raw) < 20) continue;
        $raw = rm_follow($raw, $full, $f);
        if (strlen($raw) > $maxBytes) {
            $raw = function_exists('mb_strcut') ? mb_strcut($raw, 0, $maxBytes, 'UTF-8') : substr($raw, 0, $maxBytes);
        }
        // 非通用候选才按文件名判语言；README.md 一律靠内容启发式
        $generic = in_array($f, ['README.md', 'readme.md', 'docs/README.md'], true);
        $byName = !$generic && (stripos($f, 'zh') !== false || stripos($f, 'cn') !== false);
        [$n, $len] = zhRatio($raw);
        $byContent = $len > 0 && ($n / $len) > 0.22 && $n > 12;
        $isZh = $byName || $byContent;
        $key = $isZh ? 'zh' : 'en';
        if ($buckets[$key] === null) $buckets[$key] = ['md' => $raw, 'path' => $f];
    }
    $gotReal = (bool)($buckets['zh'] || $buckets['en']);
    if (!$gotReal) {
        $fb = d($i, 'zh') ?: d($i, 'en');
        $buckets['zh'] = $buckets['en'] = ['md' => $fb, 'path' => 'description'];
    }
    if (!$buckets['zh']) $buckets['zh'] = $buckets['en'];
    if (!$buckets['en']) $buckets['en'] = $buckets['zh'];
    if ($gotReal && $rid !== '') {          // 回写本地快照（不入库，仅服务器本地）
        $snap[$rid] = $buckets;
        jwrite('readmes', $snap);
    }
    return $cache[$full] = $buckets;
}

/* ------------------------------------------------------------------ 页面 */

function shell(string $l, string $title, string $body, string $page = 'index', string $extraHead = '', string $extraJs = ''): string {
    global $CFG, $CSS, $JS, $SITE, $PROJECTS, $INCOMING_COUNT;
    $o = $l === 'zh' ? 'en' : 'zh';
    $idx = [];
    foreach ($PROJECTS as $i) {
        $rid = $i['resource_id'];
        $idx[] = ['full_name' => $i['full_name'], 'description' => d($i, $l),
                  'tags' => array_merge($i['topics'] ?? [], $i['tags'] ?? []),
                  'category' => cat($i, $l), 'url' => 'p-' . $rid . '.html'];
    }
    $nav = [['index.html', 'n.home'], ['ranking.html', 'n.rank'], ['search.html', 'n.search'], ['submit.html', 'n.submit']];
    $links = '';
    foreach ($nav as [$u, $k]) $links .= '<a href="' . $u . '">' . h(t($l, $k)) . '</a>';
    $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ' . h($CFG['site_name'] ?? 'OpenPick') . '</title>'
        . '<link rel="canonical" href="' . h($SITE . '/' . $l . '/' . $page . '.html') . '">'
        . '<meta name="description" content="' . h(t($l, 'site.desc')) . '">'
        . '<style>' . $CSS . '</style>' . $extraHead;
    $js = '<script>window.OP_INDEX=' . jout($idx) . ';window.OP_NO_RESULT=' . jout(t($l, 's.none')) . ';</script>'
        . '<script>' . $JS . '</script>' . $extraJs;
    return '<!DOCTYPE html><html lang="' . ($l === 'zh' ? 'zh-CN' : 'en') . '" data-theme="' . h($CFG['default_theme'] ?? 'light') . '"><head>' . $head . '</head><body>'
        . '<header><div class="wrap hd"><a class="logo" href="index.html"><i>✦</i>' . h($CFG['site_name'] ?? 'OpenPick') . '</a>'
        . '<nav>' . $links . '</nav><div class="hd-r">'
        . '<div class="srch"><input type="text" placeholder="' . h(t($l, 's.placeholder')) . '" autocomplete="off"><div class="pop"></div></div>'
        . '<a class="lnk" href="../' . $o . '/' . $page . '.html">' . ($l === 'zh' ? 'EN' : '中文') . '</a>'
        . '<button class="btn" id="themeBtn">☾</button></div></div></header>'
        . $body
        . '<footer><div class="wrap"><span>© ' . date('Y') . ' ' . h($CFG['site_name'] ?? 'OpenPick') . ' · ' . h(t($l, 'f.note')) . '</span>'
        . '<div class="r"><a href="../' . $o . '/index.html">' . ($l === 'zh' ? 'English' : '简体中文') . '</a>'
        . '<a href="https://github.com/' . h($CFG['submit_repo'] ?? '') . '" target="_blank" rel="noopener">GitHub ↗</a></div></div></footer>'
        . '<div class="toast" id="toast"></div>' . $js . '</body></html>';
}

function card(array $i, string $l): string {
    $rid = $i['resource_id'];
    $tags = '';
    foreach (array_slice(array_unique(array_merge($i['topics'] ?? [], $i['tags'] ?? [])), 0, 4) as $x) $tags .= '<i class="tg">#' . h($x) . '</i>';
    $nt = note($i, $l);
    return '<article class="card"><h3><a href="p-' . h($rid) . '.html">' . h($i['full_name']) . '</a></h3>'
        . ($nt ? '<span class="note">' . h($nt) . '</span>' : '')
        . '<p>' . h(d($i, $l)) . '</p><div>' . $tags . '</div>'
        . '<div class="meta"><span class="pill star">★ ' . stars($i['stars'] ?? 0) . '</span>'
        . '<span><i class="dot" style="background:' . color($i['language'] ?? '') . '"></i>' . h($i['language'] ?: '—') . '</span>'
        . '<span>' . h(t($l, 'm.updated')) . ' ' . h(substr($i['pushed_at'] ?? '', 0, 10)) . '</span></div></article>';
}

function side(string $l, string $toc = ''): string {
    global $PROJECTS;
    $langs = []; $topics = [];
    foreach ($PROJECTS as $i) {
        $lg = $i['language'] ?: '—'; $langs[$lg] = ($langs[$lg] ?? 0) + 1;
        foreach ($i['topics'] ?? [] as $x) $topics[$x] = ($topics[$x] ?? 0) + 1;
    }
    arsort($langs); arsort($topics);
    $a = '<div class="side-box"><h4>' . h(t($l, 'side.lang')) . '</h4>';
    foreach (array_slice($langs, 0, 8, true) as $k => $v) $a .= '<a href="ranking.html?tab=lang">' . h($k) . ' <span style="float:right">' . $v . '</span></a>';
    $a .= '</div><div class="side-box"><h4>' . h(t($l, 'side.topic')) . '</h4>';
    foreach (array_slice($topics, 0, 10, true) as $k => $v) $a .= '<a href="search.html?q=' . urlencode($k) . '">#' . h($k) . ' <span style="float:right">' . $v . '</span></a>';
    $a .= '</div><!-- AD:side -->';
    return $toc . $a;
}

function page_home(string $l): string {
    global $PROJECTS, $INCOMING_COUNT, $CFG;
    $sorted = $PROJECTS;
    usort($sorted, function ($a, $b) { return ($b['stars'] ?? 0) <=> ($a['stars'] ?? 0); });
    $featured = array_filter($sorted, function ($x) { return !empty($x['featured']); });
    $list = array_slice($sorted, 0, (int)($CFG['per_page'] ?? 24));
    $cards = '';
    foreach ($list as $i) $cards .= card($i, $l);
    $feat = '';
    foreach (array_slice($featured, 0, 3) as $i) $feat .= card($i, $l);
    $body = '<main class="wrap"><section class="hero"><h1>' . h(t($l, 'home.title')) . '</h1><p>' . h(t($l, 'home.sub')) . '</p>'
        . '<div class="stats"><div><b>' . count($PROJECTS) . '</b>' . h(t($l, 'm.projects')) . '</div>'
        . '<div><b>' . count($langCount = array_unique(array_column($PROJECTS, 'language'))) . '</b>' . h(t($l, 'm.langs')) . '</div>'
        . '<div><b>' . $INCOMING_COUNT . '</b>' . h(t($l, 'm.pending')) . '</div>'
        . '<div><b>' . h(t($l, 'm.daily')) . '</b>' . h(t($l, 'm.updated')) . '</div></div></section>'
        . '<!-- AD:home_top -->'
        . ($feat ? '<div class="tabs"><button class="on" data-tab="all">' . h(t($l, 'home.all')) . '</button></div>' : '')
        . '<div class="cols"><div class="grid g2">' . $cards . '</div><aside>' . side($l) . '</aside></div></main>'
        . '<!-- AD:footer -->';
    return shell($l, t($l, 'home.title'), $body, 'index');
}

function page_ranking(string $l): string {
    global $PROJECTS, $CFG;
    $lim = (int)($CFG['ranking_limit'] ?? 20);
    $sets = [];
    $byStars = $PROJECTS; usort($byStars, function ($a, $b) { return ($b['stars'] ?? 0) <=> ($a['stars'] ?? 0); });
    $sets['stars'] = array_slice($byStars, 0, $lim);
    $byNew = $PROJECTS; usort($byNew, function ($a, $b) { return strcmp($b['accepted_at'] ?? '', $a['accepted_at'] ?? ''); });
    $sets['new'] = array_slice($byNew, 0, $lim);
    $byPush = $PROJECTS; usort($byPush, function ($a, $b) { return strcmp($b['pushed_at'] ?? '', $a['pushed_at'] ?? ''); });
    $sets['recent'] = array_slice($byPush, 0, $lim);
    $trend = array_filter($PROJECTS, function ($x) { foreach ($x['sources'] ?? [] as $s) if (strpos($s, 'trending') === 0) return true; return false; });
    $sets['trend'] = array_slice(array_values($trend), 0, $lim);
    $tabs = [['stars', 'r.stars'], ['trend', 'r.trend'], ['new', 'r.new'], ['recent', 'r.recent'], ['lang', 'r.lang']];
    $btns = '';
    foreach ($tabs as $k => [$id, $key]) $btns .= '<button class="' . ($k === 0 ? 'on' : '') . '" data-tab="' . $id . '">' . h(t($l, $key)) . '</button>';
    $groups = '';
    foreach ($sets as $id => $items) {
        $rows = '';
        foreach ($items as $n => $i) {
            $cls = $n === 0 ? 'g' : ($n === 1 ? 's' : ($n === 2 ? 'b' : ''));
            $rows .= '<div class="rank-row"><span class="no ' . $cls . '">' . ($n + 1) . '</span><div class="bd">'
                . '<b><a href="p-' . h($i['resource_id']) . '.html">' . h($i['full_name']) . '</a></b>'
                . '<span>' . h(d($i, $l)) . '</span></div><span class="meta">★ ' . stars($i['stars'] ?? 0) . '</span></div>';
        }
        $groups .= '<div data-tab="' . $id . '"' . ($id === 'stars' ? '' : ' style="display:none"') . '>' . ($rows ?: '<p>' . h(t($l, 's.none')) . '</p>') . '</div>';
    }
    // 语言榜：按语言分组各取 Top5
    $byLang = [];
    foreach ($PROJECTS as $i) $byLang[$i['language'] ?: '—'][] = $i;
    $lg = '';
    foreach ($byLang as $name => $arr) {
        usort($arr, function ($a, $b) { return ($b['stars'] ?? 0) <=> ($a['stars'] ?? 0); });
        $lg .= '<div class="side-box"><h4>' . h($name) . '</h4>';
        foreach (array_slice($arr, 0, 5) as $n => $i) $lg .= '<a href="p-' . h($i['resource_id']) . '.html">' . ($n + 1) . '. ' . h($i['full_name']) . ' · ★ ' . stars($i['stars'] ?? 0) . '</a>';
        $lg .= '</div>';
    }
    // 外层已有 <aside>side()</aside>，这里不能再来一份，否则 AD:side 锚点重复
    $groups .= '<div data-tab="lang" style="display:none"><div class="cols"><div>' . $lg . '</div></div></div>';
    $body = '<main class="wrap"><section class="hero"><h1>' . h(t($l, 'r.title')) . '</h1><p>' . h(t($l, 'r.sub')) . '</p></section>'
        . '<!-- AD:home_top --><div class="tabs">' . $btns . '</div><div class="cols"><div>' . $groups . '</div><aside>' . side($l) . '</aside></div></main><!-- AD:footer -->';
    return shell($l, t($l, 'r.title'), $body, 'ranking');
}

function page_search(string $l): string {
    global $PROJECTS;
    $cards = '';
    foreach ($PROJECTS as $i) $cards .= '<div data-name="' . h(strtolower($i['full_name'] . ' ' . d($i, $l) . ' ' . implode(' ', $i['topics'] ?? []))) . '">' . card($i, $l) . '</div>';
    $body = '<main class="wrap"><section class="hero"><h1>' . h(t($l, 's.title')) . '</h1><p>' . h(t($l, 's.sub')) . '</p></section>'
        . '<div class="cols"><div><div class="srch" style="margin-bottom:14px"><input id="bigSearch" type="text" placeholder="' . h(t($l, 's.placeholder')) . '" style="width:100%"><div class="pop" style="left:0;right:0;width:auto"></div></div>'
        . '<div class="grid" id="cards">' . $cards . '</div></div><aside>' . side($l) . '</aside></div></main><!-- AD:footer -->';
    $js = '<script>(function(){var b=document.getElementById("bigSearch");if(!b)return;'
        . 'b.addEventListener("input",function(){var q=b.value.trim().toLowerCase();'
        . 'document.querySelectorAll("#cards > div").forEach(function(d){d.style.display=(!q||d.dataset.name.indexOf(q)>=0)?"":"none"})});'
        . 'var p=new URLSearchParams(location.search).get("q");if(p){b.value=p;b.dispatchEvent(new Event("input"))}})();</script>';
    return shell($l, t($l, 's.title'), $body, 'search', '', $js);
}

function page_submit(string $l): string {
    global $CFG;
    $repo = (string)($CFG['submit_repo'] ?? '');
    $sub = $CFG['submit'] ?? [];
    $mode = (string)($sub['mode'] ?? 'auto');              // auto | worker | issue
    $endpoint = (string)($sub['endpoint'] ?? '');         // 留空 = 同源 /api/submit
    $sitekey = (string)($sub['turnstile_sitekey'] ?? '');

    // 只在后台填了 sitekey 时才输出 Turnstile 组件（否则访客侧 0 外部请求）
    $turnstile = $sitekey !== ''
        ? '<div class="cf-turnstile" data-sitekey="' . h($sitekey) . '" data-theme="auto"></div>'
          . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
        : '';

    $body = '<main class="wrap"><section class="hero"><h1>' . h(t($l, 'sub.title')) . '</h1><p>' . h(t($l, 'sub.desc')) . '</p></section>'
        . '<!-- AD:home_top --><div class="cols"><div><form class="form" id="sf">'
        . '<label>' . h(t($l, 'sub.repo')) . ' <b>*</b></label><input name="repo" required placeholder="owner/repo"><div class="hint">' . h(t($l, 'sub.repo.hint')) . '</div>'
        . '<label>' . h(t($l, 'sub.reason')) . ' <b>*</b></label><textarea name="reason" required></textarea><div class="hint">' . h(t($l, 'sub.reason.hint')) . '</div>'
        . '<label>' . h(t($l, 'sub.cat')) . '</label><select name="cat"><option value="tool">Tool / 工具</option><option value="framework">Framework / 框架</option><option value="ai">AI</option><option value="library">Library / 类库</option><option value="other">Other / 其他</option></select>'
        . $turnstile
        . '<div class="acts"><button class="btn btn-p" type="submit" id="subBtn">' . h(t($l, 'sub.btn')) . '</button>'
        . '<a class="btn" href="https://github.com/' . h($repo) . '/issues/new/choose" target="_blank" rel="noopener">' . h(t($l, 'sub.alt')) . ' ↗</a></div>'
        . '<div class="msg" id="sm"></div></form></div><aside>' . side($l) . '</aside></div></main><!-- AD:footer -->';

    $cfg = [
        'mode' => $mode, 'endpoint' => $endpoint,
        'key' => (string)($sub['submit_key'] ?? ''),
        'repo' => $repo, 'lang' => $l,
        'bad' => t($l, 'sub.bad'), 'ok' => t($l, 'sub.ok'), 'alt' => t($l, 'sub.alt'),
        'sending' => t($l, 'sub.sending'), 'fail' => t($l, 'sub.fail'),
    ];
    $js = '<script>window.OP_SUBMIT=' . jout($cfg) . ';</script><script>(function(){'
        . 'var f=document.getElementById("sf"),C=window.OP_SUBMIT,m=document.getElementById("sm"),b=document.getElementById("subBtn");'
        . 'if(!f)return;'
        . 'function issueURL(repo,reason,cat){var body="### 仓库\\n`"+repo+"`\\n\\n### 推荐理由\\n"+reason+"\\n\\n### 分类\\n"+cat;'
        . 'return "https://github.com/"+C.repo+"/issues/new?title="+encodeURIComponent("提交项目："+repo)'
        . '+"&body="+encodeURIComponent(body)+"&labels=submission"}'
        . 'function fallback(repo,reason,cat,msg){var u=issueURL(repo,reason,cat);'
        . 'm.style.color="var(--warn)";m.innerHTML=msg+" · <a href=\""+u+"\" target=\"_blank\" rel=\"noopener\">"+C.alt+" ↗</a>"}'
        . 'function token(){try{return window.turnstile?window.turnstile.getResponse():""}catch(e){return ""}}'
        . 'f.onsubmit=async function(e){e.preventDefault();'
        . 'var repo=f.repo.value.trim(),reason=f.reason.value.trim(),cat=f.cat.value;'
        . 'if(!/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/.test(repo)||reason.length<10){m.style.color="var(--warn)";m.textContent=C.bad;return}'
        . 'var u=issueURL(repo,reason,cat);'
        . 'var useWorker=C.mode==="worker"||(C.mode==="auto"&&C.endpoint!=="");'
        . 'if(!useWorker){m.style.color="var(--ok)";m.innerHTML=C.ok+" <a href=\\""+u+"\\" target=\\"_blank\\" rel=\\"noopener\\">↗</a>";window.open(u,"_blank","noopener");return}'
        . 'b.disabled=true;m.style.color="var(--muted)";m.textContent=C.sending;'
        . 'try{var r=await fetch(C.endpoint||"/api/submit",{method:"POST",headers:{"Content-Type":"application/json","X-Submit-Key":C.key},'
        . 'body:JSON.stringify({repo:repo,reason:reason,cat:cat,lang:C.lang,turnstileToken:token()})});'
        . 'var j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||"HTTP "+r.status);'
        . 'm.style.color="var(--ok)";m.innerHTML=C.ok+" <a href=\\""+(j.issue||u)+"\\" target=\\"_blank\\" rel=\\"noopener\\">↗</a>"}'
        . 'catch(err){fallback(repo,reason,cat,C.fail+" ("+err.message+")")}finally{b.disabled=false}}'
        . '})();</script>';
    return shell($l, t($l, 'sub.title'), $body, 'submit', '', $js);
}

function page_detail(array $i, string $l): string {
    global $SITE, $CFG;
    $rid = $i['resource_id']; $full = $i['full_name'];
    $rm = readme($i);
    $main = $l === 'zh' ? $rm['zh'] : $rm['en'];
    $alt = $l === 'zh' ? $rm['en'] : $rm['zh'];
    $two = $main['path'] !== $alt['path'] && $main['md'] !== $alt['md'];
    $seg = $two ? '<div class="seg" id="rmSeg"><button class="on" data-rm="main">' . ($l === 'zh' ? '简体中文' : 'English') . '</button>'
        . '<button data-rm="alt">' . ($l === 'zh' ? 'English' : '简体中文') . '</button></div>' : '';
    $tags = '';
    foreach (array_unique(array_merge($i['topics'] ?? [], $i['tags'] ?? [])) as $x) $tags .= '<i class="tg">#' . h($x) . '</i>';
    $nt = note($i, $l);
    // Releases：无 assets 则整段不输出
    $rel = $i['release'] ?? null;
    $dl = '';
    if ($rel && !empty($rel['assets'])) {
        $rows = '';
        foreach ($rel['assets'] as $a) {
            $off = ($rel['base'] ?? '') . $a['file'];
            $rows .= '<div class="asset"><div class="f">' . h($a['file']) . '<small>' . h($a['size'] ?? '') . '</small></div>'
                . '<a class="btn dl-link" data-official="' . h($off) . '" href="' . h($off)
                . '" target="_blank" rel="noopener nofollow">' . h(t($l, 'd.dl')) . '</a></div>';
        }
        $dl = '<section class="comm" id="s-download"><h2>' . h(t($l, 'd.release')) . '</h2><p class="comm-hint">' . h(t($l, 'd.release.note')) . ' · '
            . h($rel['version'] ?? '') . ' · ' . h($rel['published_at'] ?? '') . '</p>'
            . proxy_block($l) . $rows
            . '<!-- AD:detail_end --></section>';
    }
    $g = waline_block($l, $rid, $SITE . '/' . $l . '/p-' . $rid . '.html');
    $rmMain = md2html($main['md'], $full);
    $rmAlt  = $two ? md2html($alt['md'], $full) : '';
    $toc    = md_toc($rmMain, $l);
    $body = '<main class="wrap"><div style="padding:26px 0 12px"><a href="../index.html">← ' . h(t($l, 'd.back')) . '</a></div>'
        . '<div class="cols"><div>'
        . '<div class="card" style="margin-bottom:18px;padding:22px 24px"><h3 style="font-size:23px;letter-spacing:-.5px">' . h($full) . '</h3>'
        . ($nt ? '<span class="note">' . h($nt) . '</span>' : '') . '<p>' . h(d($i, $l)) . '</p><div>' . $tags . '</div>'
        . '<div class="meta" style="margin-top:12px"><span class="pill star">★ ' . stars($i['stars'] ?? 0) . '</span><span>' . h($i['language'] ?: '—') . '</span>'
        . '<span>' . h(t($l, 'm.updated')) . ' ' . h(substr($i['pushed_at'] ?? '', 0, 10)) . '</span>'
        . '<span>' . h(t($l, 'd.diff')) . ' ' . h(t($l, 'diff.' . ($i['difficulty'] ?? 'beginner'))) . '</span></div>'
        . '<div class="acts" style="margin-top:16px;display:flex;gap:8px"><a class="btn btn-p" href="https://github.com/' . h($full) . '" target="_blank" rel="noopener">GitHub ↗</a>'
        . '<button class="btn" data-copy="' . h($SITE . '/' . $l . '/p-' . $rid . '.html') . '">' . h(t($l, 'd.copy')) . '</button></div></div>'
        . $dl
        . '<div class="rm-bar"><b>' . h(t($l, 'd.readme')) . '</b>' . $seg . '</div>'
        . md_wrap($rmMain, $l, 'mdBody')
        . ($two ? md_wrap($rmAlt, $l, 'mdBodyAlt', true) : '')
        . ($rel && !empty($rel['assets']) ? '' : '<!-- AD:detail_end -->')
        . $g
        . '</div><aside>' . side($l, $toc) . '</aside></div></main><!-- AD:footer -->';
    return shell($l, $full, $body, 'p-' . $rid);
}

/* ------------------------------------------------------------------ 输出 */

function out(string $rel, string $html): void {
    $p = OP_ROOT . '/public/' . $rel;
    @mkdir(dirname($p), 0775, true);
    file_put_contents($p, $html);
}

$LIST_SLOTS = ['home_top', 'side', 'footer'];      // 列表页
$DETAIL_SLOTS = ['side', 'detail_end', 'footer']; // 详情页
$count = 0;
foreach ($LANGS as $l) {
    out($l . '/index.html', ad_inject(page_home($l), $l, $LIST_SLOTS)); $count++;
    out($l . '/ranking.html', ad_inject(page_ranking($l), $l, $LIST_SLOTS)); $count++;
    out($l . '/search.html', ad_inject(page_search($l), $l, $LIST_SLOTS)); $count++;
    out($l . '/submit.html', ad_inject(page_submit($l), $l, $LIST_SLOTS)); $count++;
    foreach ($PROJECTS as $i) {
        out($l . '/p-' . $i['resource_id'] . '.html', ad_inject(page_detail($i, $l), $l, $DETAIL_SLOTS)); $count++;
    }
    out($l . '/search-index.json', jout(array_map(function ($i) use ($l) {
        return ['full_name' => $i['full_name'], 'description' => d($i, $l),
                'tags' => $i['topics'] ?? [], 'category' => cat($i, $l), 'url' => 'p-' . $i['resource_id'] . '.html'];
    }, $PROJECTS)));
}
// 根跳转 + sitemap
$def = $CFG['default_lang'] ?? 'zh';
out('index.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $def . '/index.html">'
    . '<script>location.replace("' . $def . '/index.html")</script><title>OpenPick</title></head><body></body></html>'); $count++;
$sm = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($LANGS as $l) {
    foreach (array_merge(['index.html', 'ranking.html', 'search.html', 'submit.html'],
        array_map(function ($i) { return 'p-' . $i['resource_id'] . '.html'; }, $PROJECTS)) as $u) {
        $sm .= '<url><loc>' . h($SITE . '/' . $l . '/' . $u) . '</loc></url>';
    }
}
$sm .= '</urlset>';
out('sitemap.xml', $sm);

echo "✅ 构建完成：{$count} 个页面 · " . implode('/', $LANGS) . " · " . count($PROJECTS) . " 个项目 · " . $NOW . "\n";
