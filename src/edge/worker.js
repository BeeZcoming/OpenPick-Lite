/**
 * 可选：前台在宝塔、函数单独跑 Cloudflare Worker 时用这个入口。
 *   wrangler deploy src/edge/worker.js --name openpick-submit
 * 然后把后台「投稿 → 函数地址」填成 https://xxx.workers.dev/api/submit
 */
import { handleSubmit, handleOptions } from './submit-core.js';

export default {
  async fetch(request, env) {
    const path = new URL(request.url).pathname;
    if (request.method === 'OPTIONS') return handleOptions(request, env);
    if (path === '/api/submit' || path.endsWith('/api/submit')) {
      return handleSubmit(request, env);
    }
    return new Response('Not found', { status: 404 });
  },
};
