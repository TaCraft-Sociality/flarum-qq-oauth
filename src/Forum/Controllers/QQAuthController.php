<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Forum\Controllers;

use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use Illuminate\Session\Store;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TacraftSociality\QqOauth\QQ\QQAvatar;
use TacraftSociality\QqOauth\QQ\QQClient;
use TacraftSociality\QqOauth\QQ\QQOAuthException;
use TacraftSociality\QqOauth\Settings;

class QQAuthController implements RequestHandlerInterface
{
    private const PROVIDER = 'qq';
    private const SESSION_STATE = 'qq_oauth_state';
    private const SESSION_RETURN_TO = 'qq_oauth_return_to';

    public const ERROR_PARAM = '_flarum_qq_error';
    public const MARKER_PARAM = '_flarum_qq';

    public function __construct(
        protected ResponseFactory $response,
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute('session');
        $query = $request->getQueryParams();
        $actor = RequestUtil::getActor($request);

        $returnTo = $this->resolveReturnTo($request, $session);

        // Accounts are bound to the provider they signed up with and can never
        // be linked or unlinked afterwards, so a signed in user has nothing to
        // do here.
        if ($actor->exists) {
            return new RedirectResponse($returnTo);
        }

        if (isset($query['error'])) {
            $this->forgetFlow($session);
            return $this->errorResponse($returnTo, 'cancelled');
        }

        $appId = trim((string) $this->settings->get(Settings::APP_ID, ''));
        $appSecret = trim((string) $this->settings->get(Settings::APP_SECRET, ''));

        if ($appId === '' || $appSecret === '') {
            return $this->errorResponse($returnTo, 'not_configured');
        }

        $client = new QQClient($appId, $appSecret);
        $redirectUri = $this->url->to('forum')->route('auth.qq');
        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '') {
            if (isset($query['state']) || isset($query['usercancel'])) {
                $this->forgetFlow($session);
                return $this->errorResponse($returnTo, 'cancelled');
            }

            $state = bin2hex(random_bytes(16));
            $session->put(self::SESSION_STATE, $state);
            $session->put(self::SESSION_RETURN_TO, $returnTo);

            return new RedirectResponse($client->authorizationUrl($redirectUri, $state));
        }

        $expectedState = $session->get(self::SESSION_STATE);
        $state = $query['state'] ?? null;

        $this->forgetFlow($session);

        if (
            ! is_string($expectedState)
            || $expectedState === ''
            || ! is_string($state)
            || ! hash_equals($expectedState, $state)
        ) {
            return $this->errorResponse($returnTo, 'invalid_state');
        }

        try {
            $token = $client->accessToken($code, $redirectUri);
            $identity = $client->identity($token['access_token']);
            $openId = $identity['openid'];
            $unionId = $identity['unionid'];
            $profile = $client->userInfo($token['access_token'], $openId);
        } catch (QQOAuthException $e) {
            $this->logger->warning('[flarum-qq-oauth] QQ login failed: '.$e->reason(), ['exception' => $e]);
            return $this->errorResponse($returnTo, $e->reason());
        }

        $syncAvatar = filter_var(
            $this->settings->get(Settings::SYNC_AVATAR, '1'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? true;

        $isReturning = LoginProvider::query()
            ->where('provider', self::PROVIDER)
            ->where('identifier', $openId)
            ->exists();

        $avatarUrls = $syncAvatar && ! $isReturning ? QQAvatar::urlsFromProfile($profile) : [];

        $response = $this->response->make(
            self::PROVIDER,
            $openId,
            function (Registration $registration) use ($profile, $avatarUrls, $unionId): void {
                $nickname = isset($profile['nickname']) && is_scalar($profile['nickname'])
                    ? trim((string) $profile['nickname'])
                    : '';

                if ($nickname === '') {
                    $nickname = 'user_t_' . bin2hex(random_bytes(3));
                } else {
                    if (mb_strlen($nickname) > 16) {
                        $nickname = mb_substr($nickname, 0, 16);
                    }
                    if (empty($nickname)) {
                        $nickname = 'user_t_' . bin2hex(random_bytes(3));
                    }
                }

                $registration->suggestUsername($nickname);

                // No email is provided on purpose: the virtual address is
                // generated on the server when the account is created, so it
                // never appears in the registration token or in the browser.
                // The unionid (shared by every app of this developer) is what
                // lets the skin site derive the very same address.
                $registration->setPayload([
                    'nickname' => $nickname,
                    'avatar_urls' => $avatarUrls,
                    'unionid' => $unionId,
                ]);
            },
            $this->markReturnTo($returnTo)
        );

        $session->regenerate(true);

        return $response;
    }

    private function resolveReturnTo(ServerRequestInterface $request, Store $session): string
    {
        $query = $request->getQueryParams();
        $candidate = $query['returnTo'] ?? null;

        if (! is_string($candidate) || $candidate === '') {
            $candidate = $session->get(self::SESSION_RETURN_TO);
        }

        if (is_string($candidate) && self::isSafeReturnPath($candidate)) {
            return $candidate;
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path) && self::isSafeReturnPath($path)) {
                return $path;
            }
        }
        return '/';
    }

    public static function isSafeReturnPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '://')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    private function markReturnTo(string $returnTo): string
    {
        return $this->appendQuery($returnTo, self::MARKER_PARAM, '1');
    }

    private function appendQuery(string $url, string $key, string $value): string
    {
        [$path, $hash] = array_pad(explode('#', $url, 2), 2, null);
        $separator = str_contains($path, '?') ? '&' : '?';
        $marked = $path.$separator.rawurlencode($key).'='.rawurlencode($value);
        return $hash === null ? $marked : $marked.'#'.$hash;
    }

    private function errorResponse(string $returnTo, string $reason): RedirectResponse
    {
        return new RedirectResponse($this->appendQuery($returnTo, self::ERROR_PARAM, $reason));
    }

    private function forgetFlow(Store $session): void
    {
        $session->forget(self::SESSION_STATE);
        $session->forget(self::SESSION_RETURN_TO);
    }
}