#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
OpenPick Lite · 采集端（单文件，仅标准库）

  python3 src/collect.py collect          # 三源采集 → data/incoming.json
  python3 src/collect.py ingest ISSUE.json  # Issue 投稿 → data/incoming.json

三源：GitHub Trending（无官方 API，三通道兜底）/ Search API / Awesome Lists
去重：源内小写唯一键 → 跨源字段按可信度补齐 → seen.json 跨天增量 → 管端状态优先
原则：源挂了不清池；不覆盖人工字段；同日重跑字节级无变化。
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")
TODAY = datetime.now(timezone.utc).strftime("%Y-%m-%d")
TOKEN = os.environ.get("GH_TOKEN", "")
UA = "OpenPickLite/1.0 (+GitHub Actions)"

CONFIG = {
    "ttl_days": 7,
    "max_revive": 3,
    "search_queries": [
        ("newly_hot", "created:>{d} stars:>100"),
        ("ai_tools", "topic:ai topic:llm stars:>1000"),
        ("py_stars", "language:python stars:>5000"),
        ("rs_stars", "language:rust stars:>5000"),
    ],
    "awesome": [
        ("awesome-python", "https://raw.githubusercontent.com/vinta/awesome-python/master/README.md"),
        ("awesome-rust", "https://raw.githubusercontent.com/rust-unofficial/awesome-rust/main/README.md"),
    ],
    "trending_langs": ["", "python", "javascript", "go", "rust", "typescript"],
}


# ------------------------------------------------------------------ 基础工具

def load(name, default):
    p = os.path.join(DATA, name + ".json")
    try:
        with open(p, encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return default


def save(name, obj):
    p = os.path.join(DATA, name + ".json")
    os.makedirs(os.path.dirname(p), exist_ok=True)
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, indent=2)
        f.write("\n")
    os.replace(tmp, p)


def get(url, headers=None, timeout=20, retries=2):
    h = {"User-Agent": UA, "Accept": "application/vnd.github+json"}
    if TOKEN:
        h["Authorization"] = "Bearer " + TOKEN
    if headers:
        h.update(headers)
    for i in range(retries + 1):
        try:
            req = urllib.request.Request(url, headers=h)
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return r.read().decode("utf-8", "replace")
        except Exception as e:
            if i == retries:
                print(f"  ! GET failed {url[:90]}: {e}")
                return None
            time.sleep(2 ** i)
    return None


def get_json(url, **kw):
    s = get(url, **kw)
    if not s:
        return None
    try:
        return json.loads(s)
    except Exception:
        return None


def key(name):
    return str(name or "").strip().lower()


def norm(raw):
    """统一 8 字段契约，缺省兜底；保证幂等（None 与 0 都收敛成同一值）"""
    return {
        "full_name": raw.get("full_name") or "",
        "description": (raw.get("description") or "").strip()[:500],
        "stars": int(raw.get("stars") or 0),
        "language": raw.get("language") or "",
        "topics": sorted(set(raw.get("topics") or [])),
        "pushed_at": raw.get("pushed_at") or "",
        "archived": bool(raw.get("archived")),
        "sources": sorted(set(raw.get("sources") or [])),
    }


def valid(it):
    return (not it["archived"] and "/" in it["full_name"]
            and it["stars"] > 0 and it["description"])


# ------------------------------------------------------------------ 三个数据源

def src_trending():
    """GitHub 没有 Trending 官方 API：第三方 JSON → 官方 HTML 解析 → 上次缓存。"""
    items, healthy = [], False
    for lang in CONFIG["trending_langs"]:
        q = "?since=daily" + ("&language=" + lang if lang else "")
        data = get_json("https://ghapi.huchen.dev/repositories" + q, timeout=15)
        if isinstance(data, list) and data:
            healthy = True
            for it in data:
                items.append({
                    "full_name": f"{it.get('author')}/{it.get('name')}",
                    "description": it.get("description"),
                    "stars": it.get("stars"),
                    "language": it.get("language") or lang,
                    "sources": ["trending:daily"],
                })
    if not items:
        # 通道 2：官方 HTML 兜底
        html = get("https://github.com/trending?since=daily", timeout=20)
        if html:
            for m in re.finditer(r'<h2 class="h3 lh-condensed">\s*<a href="/([^"/]+/[^"]+)"', html):
                items.append({"full_name": m.group(1), "stars": 1, "sources": ["trending:daily"]})
            healthy = bool(items)
    if not items:
        # 通道 3：上次缓存（只用来判 healthy，不新增）
        cached = load("incoming", {}).get("items", [])
        items = [x for x in cached if any(s.startswith("trending") for s in x.get("sources", []))]
        print(f"  trending: 上游为空，沿用缓存 {len(items)} 条")
    print(f"  trending: {len(items)} 条, healthy={healthy}")
    return items, healthy


def src_search():
    items, healthy = [], False
    d30 = datetime.fromtimestamp(time.time() - 30 * 86400, timezone.utc).strftime("%Y-%m-%d")
    for label, q in CONFIG["search_queries"]:
        query = q.replace("{d}", d30)
        url = ("https://api.github.com/search/repositories?q="
               + urllib.parse.quote(query) + "&sort=stars&order=desc&per_page=30")
        data = get_json(url, timeout=25)
        if not data or "items" not in data:
            print(f"  search[{label}]: 失败")
            continue
        healthy = True
        for it in data["items"]:
            items.append({
                "full_name": it["full_name"], "description": it.get("description"),
                "stars": it.get("stargazers_count"), "language": it.get("language"),
                "topics": it.get("topics") or [], "pushed_at": it.get("pushed_at"),
                "archived": it.get("archived", False), "sources": ["search:" + label],
            })
        print(f"  search[{label}]: {len(data['items'])} 条")
        time.sleep(1)
    return items, healthy


LINK_RE = re.compile(r"\[([^\]]+)\]\((https?://github\.com/([^/\)]+/[^/\)#]+))\)")


def src_awesome():
    items, healthy = [], False
    for label, url in CONFIG["awesome"]:
        txt = get(url, timeout=20)
        if not txt:
            print(f"  awesome[{label}]: 失败")
            continue
        healthy = True
        n = 0
        for line in txt.splitlines():
            m = LINK_RE.search(line)
            if not m:
                continue
            full = m.group(3).rstrip("/")
            if full.count("/") != 1:
                continue
            desc = line[m.end():].lstrip(" -–:：").strip()[:300]
            items.append({"full_name": full, "description": desc, "sources": ["awesome:" + label]})
            n += 1
        print(f"  awesome[{label}]: {n} 条")
    return items, healthy


def enrich(it):
    """仅对字段不全的仓库调 1 次 /repos/{n}，顺手抓 latest release。"""
    need = not (it["stars"] and it["description"] and it["pushed_at"] and it.get("release") is not None)
    if not need:
        return it
    d = get_json("https://api.github.com/repos/" + it["full_name"], timeout=20)
    if not d or "full_name" not in d:
        return it
    it.update({
        "description": it["description"] or d.get("description") or "",
        "stars": it["stars"] or d.get("stargazers_count") or 0,
        "language": it["language"] or d.get("language") or "",
        "topics": it["topics"] or d.get("topics") or [],
        "pushed_at": it["pushed_at"] or d.get("pushed_at") or "",
        "archived": d.get("archived", False),
    })
    rel = get_json("https://api.github.com/repos/" + it["full_name"] + "/releases/latest", timeout=20)
    if rel and "tag_name" in rel:
        assets = rel.get("assets") or []
        it["release"] = {
            "version": rel.get("tag_name"), "published_at": rel.get("published_at", "")[:10],
            "url": rel.get("html_url", ""),
            "base": "https://github.com/" + it["full_name"] + "/releases/download/" + rel["tag_name"] + "/",
            "assets": [{"file": a["name"], "size": "%.1f MB" % (a.get("size", 0) / 1048576),
                        "downloads": a.get("download_count", 0)} for a in assets],
        }
    else:
        it["release"] = None
    return it


# ------------------------------------------------------------------ 合并与状态机

TRUST = {"search": 3, "trending": 2, "awesome": 1}


def merge(existing, incoming):
    """跨源合并：sources 取并集，字段按可信度补齐，低可信不覆盖高可信。"""
    out = {key(x["full_name"]): dict(x) for x in existing}
    for raw in incoming:
        k = key(raw.get("full_name"))
        if not k:
            continue
        src = (raw.get("sources") or ["unknown"])[0].split(":")[0]
        trust = TRUST.get(src, 0)
        if k not in out:
            out[k] = {"full_name": raw["full_name"], "_trust": trust, "release": raw.get("release")}
            cur = out[k]
        else:
            cur = out[k]
        cur["sources"] = sorted(set(cur.get("sources", []) + (raw.get("sources") or [])))
        old_trust = cur.get("_trust", 0)
        for f in ("description", "stars", "language", "topics", "pushed_at", "archived"):
            v = raw.get(f)
            if v in (None, "", [], 0):
                continue
            if not cur.get(f) or trust >= old_trust:
                cur[f] = v
        cur["_trust"] = max(old_trust, trust)
        if raw.get("release") and not cur.get("release"):
            cur["release"] = raw["release"]
    return out


def collect():
    os.makedirs(DATA, exist_ok=True)
    incoming = load("incoming", {})
    pool = incoming.get("items", [])
    seen = load("seen", {})
    projects = load("projects", {}).get("items", [])
    rejected = set(load("rejected", {}).get("names", []))
    approved = {key(x["full_name"]) for x in projects}

    raw = []
    a, h1 = src_trending()
    b, h2 = src_search()
    c, h3 = src_awesome()
    raw = a + b + c
    healthy = [h1, h2, h3]

    # 只对「本轮新增且字段不全」的仓库补元数据，省配额
    known = {key(x["full_name"]) for x in pool}
    new_keys = {key(x.get("full_name")) for x in raw} - known - approved - rejected
    for k in list(new_keys)[:40]:
        for x in raw:
            if key(x.get("full_name")) == k:
                enrich(x)
                break
        time.sleep(0.2)

    merged = merge(pool, raw)
    hit = {key(x.get("full_name")) for x in raw}   # 本轮真实命中的集合（TTL 必须看它，不能看合并后的池）

    # 状态机：只追加新候选；池内条目仅更新机器字段
    if all(healthy):
        # 只有所有源都健康才衰减，否则一次网络抖动就会把待审池清空
        for k, st in seen.items():
            if st.get("status") != "pending" or k in hit or k in approved or k in rejected:
                continue
            st["days_absent"] = st.get("days_absent", 0) + 1
            if st["days_absent"] >= CONFIG["ttl_days"]:
                st["status"] = "expired"

    now_items = []
    for k, v in merged.items():
        if k in approved or k in rejected or not valid(norm(v)):
            continue
        st = seen.get(k, {"first_seen": TODAY, "last_seen": TODAY, "seen_dates": [],
                          "days_absent": 0, "revives": 0, "status": "pending"})
        if k in hit:
            # 重新命中：过期条目复活，最多 3 次防抖动
            if st.get("status") == "expired":
                if st.get("revives", 0) >= CONFIG["max_revive"]:
                    continue
                st["revives"] = st.get("revives", 0) + 1
            st["status"] = "pending"
            st["days_absent"] = 0
            st["last_seen"] = TODAY
            st["seen_dates"] = sorted(set((st.get("seen_dates") or []) + [TODAY]))
        elif st.get("status") != "pending":
            continue   # 已过期且本轮未命中 → 不进池
        seen[k] = st
        item = norm(v)
        item["release"] = v.get("release")
        for extra in ("suggested_reason", "suggested_by"):
            if v.get(extra):
                item[extra] = v[extra]
        now_items.append(item)

    now_items.sort(key=lambda x: x["stars"], reverse=True)
    save("incoming", {"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                      "count": len(now_items), "items": now_items})
    save("seen", seen)
    print(f"✅ collect: 池内 {len(now_items)} 条，新增 {len(new_keys)}，源健康 {healthy}")


# ------------------------------------------------------------------ Issue 投稿

def ingest(issue_path):
    with open(issue_path, encoding="utf-8") as f:
        issue = json.load(f)
    body = issue.get("body") or ""
    m = re.search(r"`([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)`", body)
    if not m:
        print("  Issue 里没找到 owner/repo，跳过")
        return
    full = m.group(1)
    reason = ""
    rm = re.search(r"###\s*推荐理由\s*\n(.+?)(?=\n###|\Z)", body, re.S)
    if rm:
        reason = rm.group(1).strip()[:500]
    if len(reason) < 10:
        print("  推荐理由不足 10 字，跳过")
        return

    incoming = load("incoming", {})
    pool = incoming.get("items", [])
    k = key(full)
    if k in {key(x["full_name"]) for x in load("projects", {}).get("items", [])}:
        print("  已收录，跳过")
        return
    if k in set(load("rejected", {}).get("names", [])):
        print("  已拒绝，跳过")
        return

    tag = "community:issue#%s" % issue.get("number", "?")
    for x in pool:
        if key(x["full_name"]) == k:
            if tag not in x["sources"]:
                x["sources"] = sorted(set(x["sources"] + [tag]))
                x["suggested_reason"] = reason
                save("incoming", {"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                                  "count": len(pool), "items": pool})
                print("  已在待审池，仅追加来源")
            return

    d = get_json("https://api.github.com/repos/" + full, timeout=20)
    if not d or "full_name" not in d:
        print("  仓库不存在或已私有，跳过")
        return
    it = {
        "full_name": full, "description": d.get("description") or "",
        "stars": d.get("stargazers_count") or 0, "language": d.get("language") or "",
        "topics": d.get("topics") or [], "pushed_at": d.get("pushed_at") or "",
        "archived": d.get("archived", False), "sources": [tag],
        "suggested_reason": reason, "suggested_by": (issue.get("user") or {}).get("login", ""),
    }
    it = enrich(it)
    if not valid(norm(it)):
        print("  仓库不合格（已归档/无星标/无描述），跳过")
        return
    pool.append(norm(it))
    pool[-1]["release"] = it.get("release")
    pool[-1]["suggested_reason"] = reason
    pool[-1]["suggested_by"] = it.get("suggested_by", "")
    save("incoming", {"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                      "count": len(pool), "items": pool})
    print(f"✅ ingest: {full} 已入队")


if __name__ == "__main__":
    mode = sys.argv[1] if len(sys.argv) > 1 else "collect"
    if mode == "collect":
        collect()
    elif mode == "ingest":
        ingest(sys.argv[2])
    else:
        print(__doc__)
        sys.exit(1)
