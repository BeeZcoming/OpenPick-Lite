# OpenPick Lite 部署手册

> 版本 v3 ｜ 1 个域名 · 1 台宝塔 · 1 条 GitHub 工作流 · 1 个 Cloudflare 投稿函数 · Waline 评论
> 前台和管端在同一台服务器；**边缘函数只部署在 Cloudflare**；评论服务端可放 Cloudflare Workers，也可放别处。
> 照 1 → 10 顺序执行，第 5 步「必改 4 项」别跳。

---

## 0. 架构

```
GitHub Actions（唯一工作流 collect.yml）
  ├─ 每天 UTC 01:17：三源采集 → data/incoming.json（差量合并，四层去重）
  ├─ Issue 投稿（submission 标签）：解析 → GET /repos/{n} 补元数据 → incoming.json
  └─ commit [skip ci] → push
                    │
   宝塔定时 git pull（每 5 分钟）
                    ▼
宝塔 1 台 / 1 域名 / 1 个 Nginx 站点
  ├─ /            前台静态站（public/，由 build.php 生成）
  ├─ /admin/      PHP 单文件管端（Basic Auth，无数据库）
  └─ 管端保存 → php build.php → public/ 立即刷新（无需 push / CDN）

投稿：/zh/submit.html → POST Cloudflare Function → 建 GitHub Issue → 回到采集层
      函数没配 / 报错 → 前端自动给出预填的 GitHub Issue 链接（不断路）
评论：详情页点「加载评论与点赞」→ 懒加载 Waline CSS + ES module → Waline 服务端
```

**三条铁律**
1. `data/*.json` 是唯一真相源；`public/` 是构建产物，已 `.gitignore`，不要手改。
2. 后台任何保存都会**立刻重跑 `php build.php`**；纯静态没有热更新，但同机即时生效。
3. 敏感值只在 **Cloudflare Secret / 宝塔 `config.php` / Waline 服务端**，绝不进仓库 JSON。

---

## 1. 推代码到 GitHub

```bash
cd openpick-lite
git init
git remote add origin git@github.com:YOUR_NAME/YOUR_REPO.git
git add -A && git commit -m "init: OpenPick Lite v3"
git push -u origin main
```

仓库 → **Settings → Actions → General → Workflow permissions** → 勾 **Read and write** → Save。
不勾，Actions 提交 `incoming.json` 会 403。

> 不再需要 Discussions / 评论 App、不需要 EdgeOne Token；投稿与评论分别由 Cloudflare 函数和 Waline 服务端承担。

---

## 2. 宝塔装环境

软件商店装：**Nginx**、**PHP 8.3**、**Git**。
不用 MySQL、不用 Composer、不用 pip install（采集端只用 Python 标准库）。

- PHP 8.3 → **配置修改 → 禁用函数**：确认 `exec` 未被禁用（管端「立即重建」要用）
- 站点 → **网站目录 → 防跨站攻击：关闭**（管端要读写上级 `data/`）
- root 指向 `public/`，所以 `data/`、`config.php`、`src/`、`functions/` 天然在 Web 根之外

---

## 3. 克隆 + 本地配置

```bash
cd /www/wwwroot
git clone git@github.com:YOUR_NAME/YOUR_REPO.git openpick-lite
cd openpick-lite
cp config.example.php config.php
php -r "echo password_hash('换成你的强密码', PASSWORD_DEFAULT), PHP_EOL;"
# 把输出的哈希填进 config.php 的 admins.admin
ls /www/server/php/*/bin/php     # 确认 CLI 路径，填进 config.php → php_bin
chown -R www:www /www/wwwroot/openpick-lite
chmod -R 775 /www/wwwroot/openpick-lite/data
php build.php
# ✅ 构建完成：29 个页面 · zh/en · 10 个项目

> **zip 里已附带生成好的 `public/`（29 个 HTML + 2 个 search-index.json + sitemap.xml）**，
> 所以解压后即使服务器还没装 PHP，站点也能立刻打开。之后的任何改动仍以
> `php build.php` 为准（它会覆盖 `public/`）。
>
> 本地没有 PHP 时可用等价生成器重出成品页（与 build.php 同一份数据、同一套 CSS/JS）：
> ```bash
> python3 tools/build_pages.py
> ```
> 生成的页面结构一致；README 双语抓取需要联网，离线时按 build.php 的行为用 description 兜底。
```

---

## 4. 建 1 个站点

| 项 | 值 |
|---|---|
| 域名 | `YOUR_DOMAIN` |
| 根目录 | `/www/wwwroot/openpick-lite/public` |
| PHP 版本 | PHP 8.3（`/admin/` 用） |
| 数据库 | 不创建 |

「设置 → 配置文件」→ 整段贴 `nginx.conf.example` → 重载配置 → 「SSL」申请证书并开强制 HTTPS。

- 前台 `https://YOUR_DOMAIN/` → 自动跳 `/zh/`
- 管端 `https://YOUR_DOMAIN/admin/` → Basic Auth
- 验证 `https://YOUR_DOMAIN/data/projects.json` 返回 **404**（安全基线）

---

## 5. 必改 4 项（管端「系统设置」，保存即重建）

| # | 字段 | 改成 |
|---|---|---|
| 1 | 正式域名 | `https://YOUR_DOMAIN`（结尾不带斜杠） |
| 2 | 投稿仓库 | `YOUR_NAME/YOUR_REPO` |
| 3 | Waline serverURL | 完成第 7 步后填；未部署前保持关闭 |
| 4 | 投稿 → 函数地址 | 完成第 6 步后填（见该步两种情形） |

其余按需：默认语言、默认主题、每页条数、榜单条数。

---

## 6. 投稿函数：只部署在 Cloudflare

### 6.1 先建 GitHub fine-grained PAT

GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens：
- Repository access：**仅选这一个仓库**
- Permissions：**`Issues: read and write`**（不给 Contents）
- 过期 1 年，到期轮换

### 6.2 两种部署情形，选一个

**A. 前台也在 Cloudflare Pages**（同源，最简）
把仓库连到 Cloudflare Pages，`functions/` 会被自动识别。后台设置：
- 提交方式 = `只用 Cloudflare 函数`（或自动）
- 函数地址 **留空** → 表单 POST `/api/submit`

> `functions/_routes.json` 已限定只有 `/api/*` 走函数，静态页不受影响。

**B. 前台在宝塔，函数独立 Cloudflare Worker**（当前架构）
```bash
npm i -g wrangler
wrangler deploy src/edge/worker.js --name openpick-submit
```
得到 `https://openpick-submit.XXX.workers.dev`，后台设置：
- 提交方式 = `自动` 或 `只用 Cloudflare 函数`
- 函数地址 = `https://openpick-submit.XXX.workers.dev/api/submit`

### 6.3 Cloudflare 环境变量 / Secret（**改完必须重新部署才生效**）

| 名称 | 类型 | 值 |
|---|---|---|
| `GITHUB_REPO` | 变量 | `YOUR_NAME/YOUR_REPO` |
| `GITHUB_TOKEN` | **Secret** | 6.1 的 PAT |
| `ALLOWED_ORIGIN` | 变量 | `https://YOUR_DOMAIN`（B 情形必填，用于 CORS） |
| `SUBMIT_KEY` | **Secret** | 可选随机串；填了则后台「共享密钥」要一致 |
| `TURNSTILE_SECRET` | **Secret** | 可选，启用人机验证时 |
| `ISSUE_LABEL` | 变量 | 可选，默认 `submission` |
| `MIN_REASON` | 变量 | 可选，默认 `10` |

Cloudflare 控制台：Workers & Pages → 项目 → Settings → Variables and Secrets，敏感项选 **Encrypt**。

**Turnstile（可选，推荐）**：Cloudflare Dashboard → Turnstile → 添加站点（域名填 `YOUR_DOMAIN`）→ 拿到
- **Site Key**（公开）→ 后台「投稿 → Turnstile Site Key」
- **Secret Key** → `TURNSTILE_SECRET`

函数侧会做**服务端 siteverify**（`https://challenges.cloudflare.com/turnstile/v0/siteverify`），前端 widget 单独不算数。没配 Turnstile 时建议在 WAF 对 `/api/submit` 加每 IP 10 次/分钟限速。

### 6.4 全链路自检

```
submit.html → POST /api/submit
  → submit-core.js：Origin / SUBMIT_KEY / 字段格式 / 理由长度 / Turnstile
  → POST GitHub Issues（label: submission，只有 Issues 权限）
  → collect.yml 的 issues 事件 → src/collect.py ingest
       1 次 GET /repos/{n} 补元数据
       已收录 / 已拒绝 / 格式非法 → 跳过，不入队
       已在待审池 → 只追加 community:issue#N 来源，不覆盖采集端字段
  → incoming.json → commit [skip ci] → 宝塔 git pull → 管端「待审核」
```

函数未部署、未配置或报错时，前端**自动给出预填的 GitHub Issue 链接**，不会走进死路；直接提带 `submission` 标签的 Issue 也能入队。

---

## 7. 评论与点赞：Waline

### 7.1 先说清一个事实

Waline 客户端**没有独立的 `like` 选项**；「点赞 / 文章反应」由 `reaction` 提供（默认表情或自定义，最多 9 个）。评论列表本身的赞取决于 Waline 服务端实现。本站把 `reaction` 做成后台开关，标题统一叫「评论与点赞」，不谎称有额外配置项。

其余客户端选项在 **构建期固化**：`path` 固定 `/project/{resource_id}`（中英两页共用同一串评论，不会被语言路径切开）、`lang` 按页面注入 `zh-CN`/`en`、`dark: 'html[data-theme="dark"]'` 跟随站点主题按钮、`pageSize` / `wordLimit` / `login` / `emoji` 从后台读。

点「💬 加载评论与点赞」才注入 CSS + ES module（`unpkg.com/@waline/client@v3`）；**未点击前访客侧 0 外部请求**。后台关闭开关 → 详情页整段 DOM 不输出。

### 7.2 部署 Waline 服务端（二选一）

**A. 全 Cloudflare：Workers + D1（社区实现）**
Waline 官方不直接提供 Workers 服务端，但官方生态页列了社区项目 `Waline_On_Worker`（Worker + D1，兼容 `@waline/client` v3 与 Waline Admin）。粗流程：

```bash
git clone https://github.com/wuyilingwei/Waline_On_Worker.git
cd Waline_On_Worker && pnpm install
wrangler d1 create waline-db          # 把 database_id 填进 wrangler.toml
pnpm run db:init
wrangler secret put JWT_SECRET        # 32 位以上随机串
wrangler secret put TURNSTILE_SECRET  # 可选，与投稿共用一个 Turnstile 站点也可
pnpm run deploy
```

得到 `https://waline-on-worker.XXX.workers.dev`。在 `wrangler.toml` 的 `[vars]` 里把 `SECURE_DOMAINS` 设为 `YOUR_DOMAIN`（不含协议），否则跨域被拦。后台 `/ui` 注册**第一个账号即超级管理员**，随后关闭公开注册、按需开启评论审核。

> 这是社区实现，不是官方支持；上线前建议先 `wrangler d1 export` 验一下备份链路。

**B. 任意 Waline 服务端**
Vercel + Neon、Docker + SQLite/MySQL、宝塔 Node 进程都行。只要拿到完整 `serverURL`，填进后台即可；同样要把本站域名放进服务端的 `SECURE_DOMAINS` 白名单。

### 7.3 后台「系统设置 → 评论与点赞」

| 字段 | 说明 |
|---|---|
| 启用评论 | 关闭时整段 DOM 不渲染 |
| serverURL | 完整地址，**不要以 `/api` 结尾** |
| 开启点赞 / 表情反应 | 对应 `reaction: true` |
| 登录模式 | `enable`（可匿名）/ `disable` / `force` |
| 每页评论数、字数上限 | 直接映射 `pageSize` / `wordLimit` |
| Turnstile Site Key | 公开值；Secret 放 Waline 服务端 `TURNSTILE_SECRET` |

> 服务端密钥（`JWT_SECRET`、`TURNSTILE_SECRET`、数据库凭据）**只放服务端环境变量**，仓库里只有上面这些公开值。

---

## 8. 广告后台（全部可视化，无需改代码）

管端「广告」页四层控制：

| 层 | 操作 | 前台效果 |
|---|---|---|
| 全站总开关 | 一键「开 / 关」 | 关 → 所有位置整段 DOM 不输出 |
| 广告位 | 每个位置独立开关；**新增 / 删除**位置 | 只影响该位置；新增位在模板放 `<!-- AD:新ID -->` 锚点即可精确落点，没放则自动追加到 `</main>` 前 |
| 创意 | **新增 / 编辑 / 删除 / 启停 / 复制式回填** | 编辑按钮会把该条全部字段填回表单，改完保存即生效 |
| 排期 | 起止时间（UTC）、优先级、中英文案、链接、内部备注 | 构建期过滤 + 客户端 `Date.now()` 守卫 + 每小时重建；列表实时显示「进行中 / 待上线 N 天 / 已结束 / 已停用」 |

- 起止留空 = 立即 / 长期；已结束的创意构建期直接丢弃，不进 HTML
- 同一位置多条创意按 `priority` 排序，同优先级按排期轮换
- 外链自动 `rel="noopener nofollow sponsored"`，站内链接不开新窗
- 内置 4 位（`home_top` / `side` / `detail_end` / `footer`）不可删除，其余可增删
- 排期精度约 **1 小时**：宝塔加一条每小时 `php build.php` 的计划任务，让爬虫与 CDN 同步

> `preview.html` 里的「广告总开关」是演示控件，生产页面只有「广告」标签，没有任何构建说明或调试文字。

---

## 9. 加速代理与项目说明 README

这两块是站点内容本身，**不随广告开关、不随任何演示控件取消**。

### 9.1 下载通道（Release 加速镜像）

管端「加速代理」页签：

| 操作 | 说明 |
|---|---|
| 镜像总开关 | 关 → 只保留官方直连，README 不受任何影响 |
| 通道增删改 | 填「名称 + 前缀」，如 `https://ghproxy.net/`；保存即重建 |
| 启停 / 上移下移 / 设为默认 | 按后台顺序输出，切换零网络请求 |
| 官方直连 | 内置、不可删除，永远兜底 |

适用 `prefix + 官方直链` 形式的镜像（ghproxy / ghfast / mirror.ghproxy.com）。**不兼容**需要改写路径的 CDN。切换只是改写 `href`，官方与镜像指向同一个文件。

前台只在**有 Releases 且至少 1 个 asset** 的详情页出现「发布与下载」板块和通道切换器；无 Release 时整段（含该处广告）不输出。

### 9.2 README 项目说明

- 构建期按 `README.zh-CN.md → README.md → …` 探测，文件名判语言 + 前 4000 字中文占比兜底，中英各存一桶
- 中文站默认中文、英文站默认英文；只有一份时两站共用；**两份都在**时详情页出现「简体中文 / English」分段控件，切换零请求
- 少数仓库的 `README.md` 只有一行相对路径（如 `biomejs/biome`），构建器会顺着再抓一次真正的文档
- 正文渲染进 HTML：**不执行原始 HTML**，`<div align=center><picture>` 这类包装会先转成等价 Markdown 再统一转义
- 图片只放行 `proxies.json → readme.whitelist` 里的域名；配了 `image_proxy` 才走代理，加载失败自动回退原图；非白名单显示占位标记，不会让访客加载任意第三方图片
- 相对链接改写成 GitHub 绝对地址；单文件体积上限、抓取超时都在后台「加速代理」页里配

### 9.3 本地快照（推荐开启，彻底不受网络波动）

`data/readmes.json` 是**服务器本地快照**：命中就完全离线、构建秒出；缺项才联网补抓，抓到后自动回写。已加入 `.gitignore`，**不入库、不进 Git**；zip 里附带一份初始快照，解压即可复现。

```bash
# 可选：新增项目后预抓，重复运行是增量的（只补缺项）
cd /www/wwwroot/openpick-lite
python3 tools/fetch_readmes.py 4            # 每次最多 4 个、最多 70 秒，可反复跑
python3 tools/fetch_readmes.py 4 --refresh biomejs/biome   # 强制重抓指定仓库
```

放进宝塔计划任务每天跑一次也行。不跑也没关系——`php build.php` 会自己补抓。

### 9.4 无 PHP 时怎么复现

沙盒交付的成品页由 `tools/build_pages.py` 生成，它读同一份 `data/*.json`、直接从 `build.php` 提取同一套 CSS/JS、遵循同一套 README / 代理 / 广告规则：

```bash
python3 tools/build_pages.py     # 重新生成 public/
python3 tools/make_preview.py    # 重新生成唯一一个 preview.html
```

上宝塔后以 `php build.php` 为准，两个 py 脚本只作离线兜底与验收对照。

### 9.5 视觉与 README 渲染（本轮重做）

**整体皮肤**：毛玻璃顶栏、蓝紫渐变 logo / 主按钮、Hero 渐变标题与光晕、统计条改成小卡、首页卡片流两列、卡片悬浮微位移、榜单前三名渐变名次块、侧栏粘性定位 + 小色条标题、深浅主题各自完整配色。响应式 900 / 760 / 640 三个断点，正文一律 `overflow-wrap:anywhere`，长链接和长文件名不会撑破布局。

**详情页渲染修复**（之前"显示有问题"的根因）：

| 问题 | 处理 |
|---|---|
| 引用式链接 `[text][id]` / `[id]: url` 定义行当裸文本显示 | 预处理收集定义表，正文里全部还原成真链接，定义行移除 |
| 相对链接 / 页内锚点失效 | 相对路径重写成 GitHub 绝对地址，`#anchor` 保留页内跳转 |
| Markdown 表格、水平线、有序列表、列表续行不渲染 | 块级渲染器补齐：表格带横向滚动、`hr`、`<ol>`、续行自动并入上一个 `li` |
| 一行一句被拆成几十个 `<p>` | 连续普通行合并成段落，空行才断段 |
| 徽章行刷屏、非白名单图片占位文字 | 纯 badge 行整行收敛；图片失败静默丢弃，代理失败回退原图，原图也失败则隐藏，不显示破图 alt |
| 超长 README（如 awesome-python 11 万字）把页面撑到 3.6 万像素 | 默认折叠到 760px + 渐隐遮罩，点「展开全文 ↓ / 收起 ↑」切换，两个语言版本各自保留折叠态 |
| 找不到想看的章节 | 侧栏自动从 h2/h3 生成「本页目录」，滚动高亮，≥3 个标题才出现 |

README 依旧**不执行原始 HTML**：`<div align=center><picture>` 这类包装先转成等价 Markdown 再统一转义；`<script> / <iframe> / <svg>` 等整段剥离。中英双版本切换改切整个容器，目录随之切换。

---

## 10. 两条 crontab（宝塔计划任务）

| 周期 | 脚本 | 作用 |
|---|---|---|
| 每 5 分钟 | `cd /www/wwwroot/openpick-lite && git pull --ff-only -q 2>&1 \| tail -3` | 管端看到新采集 / 新投稿 |
| 每小时 | `cd /www/wwwroot/openpick-lite && php build.php >/dev/null 2>&1` | 广告按排期自动上下线 |

管端自己保存时也会立刻重建，不依赖这两条。

**可选**：想让管端保存把 JSON 同步回 GitHub 做备份，给 `www` 用户配 SSH deploy key（勾 `Allow write access`），之后手动 `git push`。不配也完全能跑。

---

## 11. 验收清单

- [ ] `https://YOUR_DOMAIN/` 自动跳 `/zh/`，右上切 EN、深浅主题正常
- [ ] `Ctrl+K` 搜索，中文子串 / 英文前缀 / 多词都有结果（索引内联，离线可用）
- [ ] `/zh/ranking.html` 五个 Tab 可切，`?tab=stars` 可深链
- [ ] 详情页：**有 Releases** 才显示「发布与下载」，无则整段不出现
- [ ] 广告总开关关掉 → 所有 `.ad` DOM 数 **0**；单个位关掉 → 只该位消失
- [ ] 创意「编辑」能回填、改完保存后前台文案变化；起止时间到点自动上下线
- [ ] 管端 `/admin/` Basic Auth 生效；`/data/projects.json` 返回 **404**
- [ ] 管端「立即重建」成功（`php_bin` 配对、`exec` 未禁）
- [ ] 投稿表单提交 → GitHub 出现带 `submission` 标签的 Issue → 几分钟后管端「待审核」多一条
- [ ] 把函数地址清空 → 表单自动回退成预填 GitHub Issue 链接
- [ ] Waline：点「加载评论与点赞」后出现评论框与表情反应；中英两页是同一串评论
- [ ] 未点评论按钮前，浏览器 Network 里**没有任何第三方请求**（无 Waline、无 CDN、无统计脚本）
- [ ] `sitemap.xml`、`/zh/search-index.json` 可访问
- [ ] 页面 `grep` 不到「构建期已静态渲染」「演示：广告开关」「build.php →」
- [ ] 首页卡片流两列、深浅主题切换后配色完整、窄屏无横向滚动条
- [ ] 详情页 README 无裸 `[id]:` 定义行、无「[图片：非白名单域名]」碎片、表格/代码块/列表正常
- [ ] 长文详情页默认折叠，点「展开全文 ↓」后正文变长、按钮变「收起 ↑」
- [ ] 侧栏「本页目录」能点、滚动会高亮；切到另一语言版本时目录一起切
- [ ] 有 Release 的详情页：通道切换器能改 `href` 且**官方/镜像指向同一文件**；无 Release 的详情页没有下载区
- [ ] 详情页「项目说明」显示真实 README（标题、列表、代码块、链接），不是一行描述、不是原始 HTML 标签
- [ ] `biomejs/biome` 这类指针式 README 也显示正文；图片只来自白名单域名
- [ ] 管端「加速代理」能增删改启停通道、改图片白名单，保存后前台立即生效

---

## 12. 故障排查

| 现象 | 处理 |
|---|---|
| Actions 提交 403 | Settings → Actions → General → Read and write |
| 管端「立即重建」失败 | `config.php → php_bin` 填实际 CLI；查 PHP 禁用函数里的 `exec` |
| 管端看不到新待审 | 第 10 步的定时 `git pull` 没配 |
| 前台 404 | 没跑 `php build.php`，或站点 root 没指 `public/` |
| `/admin/` 502 | `fastcgi_pass` 的 sock 与 PHP 版本不符 |
| 投稿 404 / CORS 报错 | 函数没部署；`endpoint` 填错；`ALLOWED_ORIGIN` 没放行前端域名 |
| 投稿 401 | `SUBMIT_KEY` 与后台「共享密钥」不一致 |
| 投稿 400 captcha | 前端 Site Key 与函数 `TURNSTILE_SECRET` 不是同一 Turnstile 站点 |
| 投稿成功但没进待审 | 看 Actions 日志；Issue 是否带 `submission` 标签、仓库是否用反引号包了 |
| 评论区空白 / 跨域 | Waline 服务端 `SECURE_DOMAINS` 没填本站域名；`serverURL` 结尾别带 `/api` |
| 中英评论分裂成两串 | 本站已固定 `path=/project/{id}`；若改过 `build.php` 请改回 |
| 点赞表情不出现 | 后台「开启点赞 / 表情反应」没勾（对应 `reaction`） |
| 关广告后还有痕迹 | 浏览器缓存；强刷即可（无 CDN） |
| 广告到点没变 | 等每小时重建，或管端点「立即重建」 |
| 下载区整块不见 | 该项目无 Releases 或无 asset，属预期行为；不是广告开关导致 |
| 通道切换后链接没变 | 强刷；或 `proxies.json` 只启用了 1 个通道（切换器需 ≥2 个才出现） |
| README 只有一行路径 / 一片原始标签 | 跑 `python3 tools/fetch_readmes.py 4 --refresh owner/repo` 强制重抓，再 `php build.php` |
| README 显示项目描述而非正文 | 快照缺项且当轮联网失败；重跑 `tools/fetch_readmes.py` 补齐 `data/readmes.json` |
| 图片不显示 | 域名不在 `proxies.json → readme.whitelist`；加进去保存即重建 |
| README 排版乱 / 出现裸方括号 | 重跑 `php build.php`；旧的 `public/` 是上一版渲染器产物 |
| 页面长得离谱 | 长 README 默认折叠是预期；不想折叠可在 `md_wrap()` 把 9000 阈值调大 |
| 深色主题某块发白 | 强刷；`--card` / `--bg2` 等变量在 CSS 顶部 `:root` 与 `html[data-theme=dark]` 两处成对定义 |
| 首页还是一列 | 浏览器宽度 ≤760px 会自动降一列，属响应式预期 |

---

## 13. 安全清单

- `config.php` 在 `.gitignore`，密码是 `password_hash` 哈希，session 2 小时过期
- 站点 root 指向 `public/`；Nginx 对 `.php/.json/.md/.py` 在非 `/admin/` 一律 404
- `/admin/` 建议加 IP 白名单（`nginx.conf.example` 里有注释模板）
- 投稿 PAT 是 fine-grained：**仅该仓库、仅 Issues 写权限**；函数侧 `GITHUB_TOKEN` / `TURNSTILE_SECRET` / `SUBMIT_KEY` 全是 Cloudflare Secret
- Turnstile 做了服务端 siteverify；Waline 服务端的 `TURNSTILE_SECRET` 与投稿函数各自配置
- Waline 服务端关闭公开注册、开启评论审核，定期导出 D1 / 数据库备份
- 广告外链一律 `noopener nofollow sponsored`
- `GITHUB_TOKEN`（Actions 自动注入）不需要你手动创建
- 无数据库 → 无 SQL 注入面；访客侧默认 0 外部请求

---

## 附录 A · 目录（30 个源文件，`public/` 不入库但随 zip 附带）

```
.github/workflows/collect.yml        唯一工作流
.github/ISSUE_TEMPLATE/submit.yml   投稿 Issue 表单
common.php                          JSON / 字典 / 广告 / 代理通道 / Waline 区块
build.php                           唯一构建入口（README 双语 + 图片白名单 + 下载通道）
admin/index.php                     单文件管端（审核 / 广告 / 加速代理 / 设置）
src/collect.py                      采集 + 四层去重 + Release + Issue 入队
tools/build_pages.py                无 PHP 时的等价页面生成器（兜底）
tools/fetch_readmes.py              README 本地快照预抓（增量、可 --refresh）
tools/make_preview.py               从真实成品页生成唯一 preview.html
functions/api/submit.ts             Cloudflare Pages Functions 入口
functions/_routes.json              只让 /api/* 走函数
src/edge/submit-core.js             投稿校验 / Turnstile / 建 Issue
src/edge/worker.js                  独立 Worker 入口（前台在宝塔时用）
config.example.php                  → 复制为 config.php
nginx.conf.example                  单站点 Nginx 配置
data/settings.json  ads.json  proxies.json  waline.json  i18n/{zh,en}.json
data/projects.json  incoming.json  seen.json  rejected.json
data/readmes.json                   README 本地快照（.gitignore，不入库，随 zip 附带）
preview.html                        唯一视觉预览（内嵌真实成品页，双击可看）
README.md  DEPLOY.md  .gitignore
public/                             构建产物（.gitignore，但随 zip 附带成品页）
```

## 附录 B · 变量总表

| 位置 | 变量 / 字段 | 性质 |
|---|---|---|
| Cloudflare Secret | `GITHUB_TOKEN` `TURNSTILE_SECRET` `SUBMIT_KEY` | **私密** |
| Cloudflare 变量 | `GITHUB_REPO` `ALLOWED_ORIGIN` `ISSUE_LABEL` `MIN_REASON` | 仅服务端 |
| `data/settings.json → submit` | `mode` `endpoint` `submit_key` `turnstile_sitekey` | **公开，会进静态页** |
| `data/proxies.json` | `channels[].prefix` 镜像地址、`default`、`readme.image_proxy` / `whitelist` / `timeout` / `max_bytes` | **公开，会进静态页**；镜像地址不是密钥 |
| `data/waline.json` | `serverURL` `reaction` `pageSize` `wordLimit` `login` `turnstile_sitekey` | **公开** |
| Waline 服务端 | `JWT_SECRET` `TURNSTILE_SECRET` `SECURE_DOMAINS` 数据库凭据 | **私密** |
| `config.php` | `admins` `php_bin` `session_ttl` | **服务器本地，不入库** |

> `submit_key` 是共享串而不是秘密：它会随 HTML 下发。真正的防线是 Cloudflare WAF 限速 + Turnstile；不要把它当认证凭据。

## 附录 C · 本地命令

```bash
php build.php                          # 生产构建（唯一入口）
python3 src/collect.py collect         # 手动采集
python3 src/collect.py ingest /tmp/issue.json
node --check <(cat src/edge/submit-core.js)   # 函数逻辑语法自检
```

`preview.html` 只是视觉示意，**不代替 `php build.php`**；不需要（也不会）为每个项目生成一堆模拟 HTML。
