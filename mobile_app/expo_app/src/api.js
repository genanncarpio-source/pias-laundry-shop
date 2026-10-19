export async function apiRequest(baseUrl, endpoint, { method = 'GET', body, token, timeoutMs = 8000 } = {}) {
  if (!baseUrl) {
    const error = new Error('The shop connection is not configured for this build.');
    error.status = 400;
    throw error;
  }
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  let response;
  let text;
  try {
    response = await fetch(baseUrl.replace(/\/+$/, '') + '/' + endpoint, {
      method, headers, signal: controller.signal,
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    text = await response.text();
  } catch {
    const error = new Error('The shop cannot be reached. Check your internet connection and try again.');
    throw error;
  } finally { clearTimeout(timer); }
  let data;
  try { data = JSON.parse(text); } catch {
    const error = new Error('The shop returned an unreadable response. Check the connection and try again.');
    error.status = response.ok ? 502 : response.status;
    throw error;
  }
  if (!response.ok || data.success !== true) {
    const error = new Error(data.error || 'The request could not be completed.');
    error.status = response.ok ? 422 : response.status;
    error.code = data.code;
    throw error;
  }
  return data;
}
