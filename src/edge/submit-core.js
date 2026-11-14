/**
 * OpenPick Lite · 投稿边缘函数共用逻辑（标准 Web API，无第三方依赖）
 * Cloudflare Pages Functions 与独立 Worker 都 import 这里。
 *
 * 环境变量 / Secret（全部在 Cloudflare 侧配置，绝不进仓库 JSON）：
 *   GITHUB_REPO       必填  owner/repo
 *   GITHUB_TOKEN      必填  fine-grained PAT，仅该仓库 Issues: read+write
 *   ALLOWED_ORIGIN    可选  允许的前端域名，多个逗号分隔；同源可不填
 *   SUBMIT_KEY        可选  共享密钥，填了则前端 X-Submit-Key 必须一致
 *   TURNSTILE_SECRET  可选  开启 Turnstile 时填；对应前端 sitekey
 *   ISSUE_LABEL       可选  默认 submission
 *   MIN_REASON        可选  默认 10
 */

function cors(origin, env) {
  const allow = String(env.ALLOWED_ORIGIN || '')
    .split(',').map((s) => s.trim()).filter(Boolean);
  const permitted = !allow.length || !origin || allow.includes(origin);
  return {
    permitted,
    headers: {
      'Access-Control-Allow-Origin': permitted ? (origin || '*') : '*',
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, X-Submit-Key',
      'Access-Control-Max-Age': '86400',
      Vary: 'Origin',
    },
  };
}

function reply(obj, status, headers) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers },
  });
}

export async function handleOptions(request, env) {
  const c = cors(request.headers.get('Origin') || '', env || {});
  return new Response(null, { status: c.permitted ? 204 : 403, headers: c.headers });
}

export async function handleSubmit(request, env) {
  env = env || {};
  const origin = request.headers.get('Origin') || '';
  const c = cors(origin, env);
  if (!c.permitted) return reply({ ok: false, error: 'origin not allowed' }, 403, c.headers);

  if (request.method !== 'POST') {
    return reply({ ok: false, error: 'method not allowed' }, 405, c.headers);
  }
  if (env.SUBMIT_KEY && request.headers.get('X-Submit-Key') !== env.SUBMIT_KEY) {
    return reply({ ok: false, error: 'unauthorized' }, 401, c.headers);
  }

  let body;
  try { body = await request.json(); } catch (_) {
    return reply({ ok: false, error: 'bad json' }, 400, c.headers);
  }
  const repo = String(body.repo || '').trim();
  const reason = String(body.reason || '').trim();
  const cat = String(body.cat || 'other').trim();
  const lang = String(body.lang || 'zh');
  const minReason = Math.max(1, parseInt(env.MIN_REASON || '10', 10) || 10);

  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo)) {
    return reply({ ok: false, error: 'repo must be owner/name' }, 400, c.headers);
  }
  if (reason.length < minReason) {
    return reply({ ok: false, error: 'reason too short' }, 400, c.headers);
  }

  // Turnstile：必须服务端 siteverify，前端 widget 单独不算数
  if (env.TURNSTILE_SECRET) {
    const token = String(body.turnstileToken || '');
    if (!token) return reply({ ok: false, error: 'captcha required' }, 400, c.headers);
    const params = new URLSearchParams({
      secret: String(env.TURNSTILE_SECRET), response: token,
      remoteip: request.headers.get('CF-Connecting-IP') || '',
    });
    try {
      const vr = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
        method: 'POST', body: params,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      });
      const v = await vr.json();
      if (!v.success) return reply({ ok: false, error: 'captcha failed' }, 403, c.headers);
    } catch (e) {
      return reply({ ok: false, error: 'captcha verify error' }, 502, c.headers);
    }
  }

  const ghRepo = String(env.GITHUB_REPO || '').trim();
  if (!ghRepo || !env.GITHUB_TOKEN) {
    return reply({ ok: false, error: 'function not configured' }, 500, c.headers);
  }

  // 只创建 Issue，不碰 contents；去重/元数据补齐由 collect.yml → collect.py ingest 完成
  const issueBody = [
    '## 项目提交',
    '',
    `- 仓库：\`${repo}\``,
    `- 分类：${cat}`,
    `- 来源语言：${lang}`,
    '',
    '### 推荐理由',
    '',
    reason,
    '',
    '---',
    '由 Cloudflare Function 自动创建，等待人工审核。',
  ].join('\n');

  try {
    const res = await fetch(`https://api.github.com/repos/${ghRepo}/issues`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${env.GITHUB_TOKEN}`,
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'Content-Type': 'application/json',
        'User-Agent': 'openpick-submit-worker',
      },
      body: JSON.stringify({
        title: `提交项目：${repo}`,
        body: issueBody,
        labels: [String(env.ISSUE_LABEL || 'submission')],
      }),
    });
    if (!res.ok) {
      const txt = await res.text();
      return reply({ ok: false, error: 'github api failed', detail: txt.slice(0, 200) }, 502, c.headers);
    }
    const data = await res.json();
    return reply({ ok: true, issue: data.html_url }, 200, c.headers);
  } catch (e) {
    return reply({ ok: false, error: 'github api error' }, 502, c.headers);
  }
}
