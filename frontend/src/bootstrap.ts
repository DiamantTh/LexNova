export interface BootstrapData {
  page: string;
  locale?: string;
  title?: string;
  translations?: Record<string, string>;
  [key: string]: unknown;
}

export function readBootstrap(): BootstrapData {
  const element = document.getElementById('lexnova-bootstrap');
  if (!(element instanceof HTMLScriptElement)) {
    throw new Error('LexNova bootstrap data is missing.');
  }

  const parsed: unknown = JSON.parse(element.textContent ?? '{}');
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    throw new Error('LexNova bootstrap data is invalid.');
  }

  const data = parsed as Record<string, unknown>;
  if (typeof data.page !== 'string' || data.page.length === 0) {
    throw new Error('LexNova page identifier is missing.');
  }

  return data as BootstrapData;
}
