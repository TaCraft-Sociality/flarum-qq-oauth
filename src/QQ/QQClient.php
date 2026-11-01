<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\QQ;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

class QQClient
{
    public const AUTHORIZE_ENDPOINT = 'https://graph.qq.com/oauth2.0/authorize';

    public const TOKEN_ENDPOINT = 'https://graph.qq.com/oauth2.0/token';

    public const OPEN_ID_ENDPOINT = 'https://graph.qq.com/oauth2.0/me';

    public const USER_INFO_ENDPOINT = 'https://graph.qq.com/user/get_user_info';

    private const TIMEOUT = 10;

    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        ?ClientInterface $http = null
    ) {
        $this->http = $http ?? new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'get_user_info',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_ENDPOINT.'?'.$query;
    }

    public function accessToken(string $code, string $redirectUri): array
    {
        $data = $this->request(self::TOKEN_ENDPOINT, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'fmt' => 'json',
        ]);

        $accessToken = $data['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new QQOAuthException('token_error', 'QQ did not return an access token.');
        }

        $data['access_token'] = $accessToken;

        return $data;
    }

    /**
     * Resolve the QQ identity behind an access token.
     *
     * The openid is only unique per application: the forum and the skin site
     * use different app ids, so the same player has two different openids
     * there. The unionid is shared by every application of the same developer
     * once they are bound together, which is what keeps one player on one
     * account across both sites.
     *
     * The unionid is mandatory. When QQ does not return one (error 100048
     * "companyid not set", i.e. the app has not been granted the unionid
     * permission) the login is refused: falling back to the per-application
     * openid would silently create a second account for the same player.
     *
     * @return array{openid: string, unionid: string}
     */
    public function identity(string $accessToken): array
    {
        try {
            $data = $this->me($accessToken);
        } catch (QQOAuthException $e) {
            if (str_contains($e->getMessage(), '100048')) {
                throw new QQOAuthException('unionid_required', 'QQ did not return a unionid (100048).', $e);
            }

            throw $e;
        }

        $openId = $data['openid'] ?? null;

        if (! is_string($openId) || $openId === '') {
            throw new QQOAuthException('openid_error', 'QQ did not return an openid.');
        }

        $unionId = $data['unionid'] ?? null;

        if (! is_string($unionId) || $unionId === '') {
            throw new QQOAuthException('unionid_required', 'QQ did not return a unionid.');
        }

        return [
            'openid' => $openId,
            'unionid' => $unionId,
        ];
    }

    public function openId(string $accessToken): string
    {
        return $this->identity($accessToken)['openid'];
    }

    private function me(string $accessToken): array
    {
        return $this->request(self::OPEN_ID_ENDPOINT, [
            'access_token' => $accessToken,
            'fmt' => 'json',
            'unionid' => '1',
        ]);
    }

    public function userInfo(string $accessToken, string $openId): array
    {
        $data = $this->request(self::USER_INFO_ENDPOINT, [
            'access_token' => $accessToken,
            'oauth_consumer_key' => $this->appId,
            'openid' => $openId,
            'fmt' => 'json',
        ]);

        if (isset($data['ret']) && (int) $data['ret'] !== 0) {
            $message = isset($data['msg']) && is_string($data['msg']) ? $data['msg'] : 'QQ rejected the profile request.';

            throw new QQOAuthException('profile_error', $message);
        }

        return $data;
    }

    private function request(string $endpoint, array $query): array
    {
        try {
            $response = $this->http->request('GET', $endpoint, [
                'query' => $query,
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new QQOAuthException('network_error', $e->getMessage(), $e);
        }

        $data = $this->decode((string) $response->getBody());

        if (isset($data['error'])) {
            $message = '';

            if (isset($data['error_description']) && is_scalar($data['error_description'])) {
                $message = (string) $data['error_description'];
            } elseif (is_scalar($data['error'])) {
                $message = (string) $data['error'];
            }

            throw new QQOAuthException('provider_error', $message !== '' ? $message : 'QQ rejected the OAuth request.');
        }

        return $data;
    }

    private function decode(string $body): array
    {
        $body = trim($body);

        if (str_starts_with($body, 'callback')) {
            $start = strpos($body, '(');
            $end = strrpos($body, ')');

            if ($start !== false && $end !== false && $end > $start) {
                $body = trim(substr($body, $start + 1, $end - $start - 1));
            }
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QQOAuthException('invalid_response', 'QQ returned a response that is not valid JSON.', $e);
        }

        if (! is_array($decoded)) {
            throw new QQOAuthException('invalid_response', 'QQ returned an unexpected response.');
        }

        return $decoded;
    }
}
