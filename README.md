# OpenPick Lite

一个域名、一台宝塔、一条 GitHub 工作流 + 一个 Cloudflare 投稿函数。

- **采集**：Actions 每天抓 Trending / Search API / Awesome Lists → `data/incoming.json`
- **投稿**：Cloudflare Function 收表单 → 建 GitHub Issue → 同一条工作流自动入队（失败自动回退 Issue 链接）
- **审核 / 管理**：宝塔上的单文件 PHP 管端 `/admin/`，无数据库、Basic Auth
- **发布**：管端点保存即跑 `php build.php`，同机 `public/` 立刻刷新
- **前台**：纯静态，中英双语、榜单、离线搜索、README 中英选版、广告、Waline 评论与点赞

## 后台能管什么

| 页签 | 能做 |
|---|---|
| 待审核 | 通过（填中英推荐语 / 分类 / 难度 / 标签 / 精选）、拒绝（永久排除） |
| 广告 | **全站总开关** + **每个广告位独立开关 / 新增 / 删除** + **创意新增·编辑·删除·启停·起止时间·优先级**；关闭即整段 DOM 不输出 |
| 系统设置 | 站点 / 语言 / 主题 / 每页条数；**Cloudflare 投稿**（方式、函数地址、共享密钥、Turnstile Site Key）；**Waline**（开关、serverURL、点赞 reaction、每页数、字数、登录模式） |

## 文件职责

| 文件 | 作用 |
|---|---|
| `common.php` | JSON 读写 / 字典 / 广告渲染 / Waline 区块 |
| `build.php` | 唯一构建入口，产出 `public/` |
| `admin/index.php` | 单文件管端（审核 + 广告 + 设置） |
| `src/collect.py` | 三源采集 + 四层去重 + Release + Issue 入队（仅标准库） |
| `tools/build_pages.py` | 无 PHP 环境的等价页面生成器，产出同一套 `public/` |
| `.github/workflows/collect.yml` | 唯一工作流：定时采集 + Issue 投稿 |
| `functions/api/submit.ts` | Cloudflare Pages Functions 投稿入口 |
| `functions/_routes.json` | 只让 `/api/*` 走函数 |
| `src/edge/submit-core.js` | 投稿校验 / Turnstile / 建 Issue 共用逻辑 |
| `src/edge/worker.js` | 前台不在 Cloudflare Pages 时的独立 Worker 入口 |
| `data/*.json` | 唯一真相源（settings / ads / waline / projects / incoming / seen / rejected / i18n） |
| `preview.html` | 视觉示意，双击可看；生产页面由 `php build.php` 生成 |
| `DEPLOY.md` | 完整部署说明 |

```bash
php build.php                          # 生成 public/（生产以此为准）
python3 tools/build_pages.py           # 无 PHP 时等价生成（zip 里已附带成品页）
python3 src/collect.py collect         # 本地手动采集
python3 src/collect.py ingest /tmp/issue.json
```

> 采集端四层去重：源内小写唯一键 → 跨源 `sources` 取并集、字段按可信度补齐 → `seen.json` 跨天只追加新候选（TTL 7 天，最多复活 3 次）→ 只读 `projects.json` / `rejected.json`，已收录与已拒绝优先级最高。三源全挂时不清空池；同日重跑不产生 commit。

**部署**：见 [DEPLOY.md](DEPLOY.md)。
