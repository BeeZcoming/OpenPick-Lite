/**
 * Cloudflare Pages Functions · POST /api/submit
 * 站点若部署在 Cloudflare Pages，前台表单的 endpoint 留空即走同源 /api/submit。
 */
import { handleSubmit, handleOptions } from '../../src/edge/submit-core.js';

export const onRequestPost = async (context: any) =>
  handleSubmit(context.request, context.env);

export const onRequestOptions = async (context: any) =>
  handleOptions(context.request, context.env);
