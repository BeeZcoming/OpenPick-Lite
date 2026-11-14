#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
可选：把已收录项目的 README 预抓到单文件本地快照 data/readmes.json。

  python3 tools/fetch_readmes.py

- build.php / tools/build_pages.py 会优先读这份快照，命中就完全离线、秒出；
- 缺项才在构建期联网补抓，抓到后回写快照；
- 快照加入 .gitignore，不入库、不进 Git，只留在服务器本地（宝塔）。
- 重复运行是增量的：已有且非空的条目直接跳过。
"""
import json
import os
import re
import sys
import time
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
SNAP = os.path.join(DATA, 'readmes.json')
PROJECTS = os.path.join(DATA, 'projects.json')
SLEEP = 0.1
UA = 'OpenPickLite/1.0'
# 单次运行最多处理 N 个缺失项、最多跑 N 秒；重复运行即可分批补齐
ARGS = sys.argv[1:]
LIMIT = int(ARGS[0]) if ARGS and ARGS[0].isdigit() else 4
REFRESH = set()
if '--refresh' in ARGS:
    j = ARGS.index('--refresh')
    if j + 1 < len(ARGS):
        REFRESH = {x.strip() for x in ARGS[j + 1].split(',') if x.strip()}
DEADLINE = time.time() + 70

CANDS = ['README.zh-CN.md', 'README.md', 'README.en.md']
GENERIC = {'README.md'}
SOURCES = (
    ('raw', lambda full, f: 'https://raw.githubusercontent.com/' + full + '/HEAD/' + f),
    ('jsdelivr', lambda full, f: 'https://cdn.jsdelivr.net/gh/' + full + '@HEAD/' + f),
)


def load(path, default):
    if not os.path.isfile(path):
        return default
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except Exception:
        return default


def get(url, timeout=3, limit=200000):
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read(limit).decode('utf-8', 'replace')


def follow_pointer(raw, full, name):
    """有些仓库的 README.md 只有一行相对路径（如 biome），顺着再抓一次真正的文档。"""
    s = raw.strip()
    if len(s) > 200 or not re.match(r'^[\w@./\-]+\.md$', s):
        return raw
    target = s.lstrip('./')
    base = name.rsplit('/', 1)[0] if '/' in name else ''
    path = (base + '/' + target).lstrip('/') if base else target
    for _, mk in SOURCES:
        try:
            t = get(mk(full, path))
            if len(t) > 200:
                return t
        except Exception:
            continue
    return raw


def is_zh(text, name):
    if name not in GENERIC and ('zh' in name.lower() or 'cn' in name.lower()):
        return True
    head = text[:4000]
    n = len(re.findall(r'[\u4e00-\u9fa5]', head))
    return n > 12 and n / max(1, len(head)) > 0.22


def main():
    projects = load(PROJECTS, {}).get('items', [])
    snap = load(SNAP, {})
    added = 0
    for p in projects:
        rid = p.get('resource_id')
        full = p.get('full_name', '')
        if not rid or not full:
            continue
        if full in REFRESH:
            snap.pop(rid, None)   # --refresh：强制重抓指定仓库（修指针/内容更新时用）
        cur = snap.get(rid) or {}
        if cur.get('zh', {}).get('md') or cur.get('en', {}).get('md'):
            continue
        if added >= LIMIT or time.time() > DEADLINE:
            break
        buckets = {'zh': None, 'en': None}
        for name in CANDS:
            if (buckets['zh'] and buckets['en']) or time.time() > DEADLINE:
                break
            for src, mk in SOURCES:
                try:
                    raw = get(mk(full, name))
                except Exception:
                    continue
                if len(raw) < 20:
                    continue
                raw = follow_pointer(raw, full, name)
                k = 'zh' if is_zh(raw, name) else 'en'
                if buckets[k] is None:
                    buckets[k] = {'md': raw, 'path': name}
                break
            time.sleep(SLEEP)
        if not buckets['zh'] and not buckets['en']:
            print('  - 跳过（网络不可达）:', full)
            continue
        if not buckets['zh']:
            buckets['zh'] = buckets['en']
        if not buckets['en']:
            buckets['en'] = buckets['zh']
        snap[rid] = buckets
        added += 1
        print('  + %s  zh=%s en=%s' % (full, buckets['zh']['path'], buckets['en']['path']))
    os.makedirs(DATA, exist_ok=True)
    tmp = SNAP + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(snap, fh, ensure_ascii=False, indent=1)
    os.replace(tmp, SNAP)
    print('✅ 快照 %s：新增 %d，共 %d 个项目' % (SNAP, added, len(snap)))


if __name__ == '__main__':
    main()
