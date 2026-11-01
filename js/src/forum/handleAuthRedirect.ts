import app from 'flarum/forum/app';
import { AUTH_ERROR_PARAM, AUTH_MARKER_PARAM, AUTH_TOKEN_PARAM, REGISTRATION_TOKEN_ENDPOINT } from '../common/constants';

const ERROR_CODES = [
  'not_configured',
  'cancelled',
  'invalid_state',
  'login_required',
  'already_linked',
  'expired_token',
  'token_error',
  'openid_error',
  'unionid_required',
  'profile_error',
  'provider_error',
  'network_error',
  'invalid_response',
  'unknown',
] as const;

type ErrorCode = (typeof ERROR_CODES)[number];

interface RegistrationTokenPayload {
  username?: string | null;
  provided?: string[] | null;
}

export async function handleAuthRedirect(): Promise<void> {
  const params = new URLSearchParams(window.location.search);
  const token = params.get(AUTH_TOKEN_PARAM);
  const error = params.get(AUTH_ERROR_PARAM);
  const marker = params.get(AUTH_MARKER_PARAM);

  if (error) {
    params.delete(AUTH_ERROR_PARAM);
    stripQueryFromUrl(params);
    showError(error);

    return;
  }

  if (token && marker) {
    params.delete(AUTH_TOKEN_PARAM);
    params.delete(AUTH_MARKER_PARAM);
    stripQueryFromUrl(params);

    await openSignUpModal(token);

    return;
  }

  if (marker) {
    params.delete(AUTH_MARKER_PARAM);
    stripQueryFromUrl(params);
  }
}

async function openSignUpModal(token: string): Promise<void> {
  let attrs: Record<string, unknown> = { token };

  try {
    const data = await app.request<RegistrationTokenPayload>({
      method: 'POST',
      url: `${app.forum.attribute<string>('apiUrl')}${REGISTRATION_TOKEN_ENDPOINT}`,
      body: { token },
      errorHandler: () => undefined,
    });

    attrs = {
      token,
      // Tells the SignUpModal extension to hide the email field and to keep it
      // out of the registration payload. The virtual email address is
      // generated on the server and never reaches the browser.
      qqOAuth: true,
      username: data?.username ?? '',
      provided: Array.isArray(data?.provided) ? data.provided : [],
    };
  } catch {
    showError('expired_token');

    return;
  }

  setTimeout(() => {
    app.modal.show(() => import('flarum/forum/components/SignUpModal'), attrs);
  }, 0);
}

function stripQueryFromUrl(params: URLSearchParams): void {
  const search = params.toString();
  const url = `${window.location.pathname}${search ? `?${search}` : ''}${window.location.hash}`;

  window.history.replaceState(window.history.state, '', url);
}

function showError(code: string): void {
  const key: ErrorCode = (ERROR_CODES as readonly string[]).includes(code) ? (code as ErrorCode) : 'unknown';

  setTimeout(() => {
    app.alerts.show({ type: 'error' }, app.translator.trans(`tacraft-sociality-qq-oauth.forum.errors.${key}`));
  }, 0);
}
