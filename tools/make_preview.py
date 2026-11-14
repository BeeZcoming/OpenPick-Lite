#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""从 public/ 的真实成品页生成唯一一个 preview.html（双击可看，无需起服务）。"""
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUB = os.path.join(ROOT, 'public')
OUT = os.path.join(ROOT, 'preview.html')

PAGES = {
    'home': 'index.html',
    'ranking': 'ranking.html',
    'submit': 'submit.html',
    'detail': 'p-gh-facebook-react.html',       # 有 Release → 显示下载区 + 通道切换
    'nodl': 'p-gh-astral-sh-uv.html',           # 无 Release → 下载区整段不渲染
}


def read(lang, name):
    with open(os.path.join(PUB, lang, name), encoding='utf-8') as fh:
        return fh.read()


def js_safe(s):
    # 避免成品页里的 </script> 提前关闭外层脚本
    return s.replace('</script', '<\\/script')


views = {}
for lang in ('zh', 'en'):
    views[lang] = {k: js_safe(read(lang, v)) for k, v in PAGES.items()}

data = json.dumps(views, ensure_ascii=False, separators=(',', ':'))

html = '''<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OpenPick Lite · 视觉预览</title>
<style>*{box-sizing:border-box}html,body{margin:0;height:100%;overflow:hidden}
#frame{width:100%;height:100%;border:0;display:block}</style></head><body>
<div id="opBar" style="position:fixed;bottom:12px;left:12px;z-index:99;background:#fff;border:1px solid #d1d9e0;border-radius:12px;padding:8px 10px;box-shadow:0 8px 24px rgba(16,24,40,.14);display:flex;gap:6px;align-items:center;flex-wrap:wrap;max-width:94vw;font:12px -apple-system,'Segoe UI',sans-serif;color:#24292e">
<b style="color:#5b5bd6">预览工具 · 非站点内容</b>
<select id="vSel"><option value="home">首页</option><option value="ranking">榜单</option><option value="submit">投稿页</option><option value="detail">详情·有Release</option><option value="nodl">详情·无Release</option></select>
<select id="lSel"><option value="zh">中文</option><option value="en">English</option></select>
<select id="tSel"><option value="light">浅色</option><option value="dark">深色</option></select>
<button id="adBtn" style="border:1px solid #d1d9e0;background:#fff;border-radius:7px;padding:4px 10px;cursor:pointer">广告：开</button>
</div>
<iframe id="frame" title="OpenPick 预览"></iframe>
<script>
var VIEWS=__DATA__,adOn=true;
function stripAds(h){return h.replace(/<aside class="ad"[\\s\\S]*?<\\/aside>/g,'')}
function render(){
 var v=document.getElementById('vSel').value,l=document.getElementById('lSel').value,th=document.getElementById('tSel').value;
 var h=VIEWS[l][v];
 h=h.replace(/data-theme="(light|dark)"/,'data-theme="'+th+'"');
 if(!adOn)h=stripAds(h);
 document.getElementById('frame').srcdoc=h;
}
['vSel','lSel','tSel'].forEach(function(id){document.getElementById(id).onchange=render});
document.getElementById('adBtn').onclick=function(){adOn=!adOn;this.textContent='广告：'+(adOn?'开':'关');render()};
render();
</script></body></html>
'''.replace('__DATA__', data)

with open(OUT, 'w', encoding='utf-8') as fh:
    fh.write(html)
print('✅ %s 已生成（%d 个真实成品页内嵌，%.0f KB）' % (OUT, len(views) * len(PAGES), len(html) / 1024))
