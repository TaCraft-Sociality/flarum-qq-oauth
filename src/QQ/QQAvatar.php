<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\QQ;

use GuzzleHttp\Client;
use Throwable;

class QQAvatar
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_PIXELS = 4_000_000;

    private const URL_KEYS = ['figureurl_qq_2', 'figureurl_qq_1', 'figureurl_2', 'figureurl_1'];

    public static function urlsFromProfile(array $profile): array
    {
        $fallbacks = [];
        $uin = null;

        foreach (self::URL_KEYS as $key) {
            $value = $profile[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $url = preg_replace('#^http://#i', 'https://', trim($value));

            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $fallbacks[] = $url;

            if ($uin === null) {
                $uin = self::uinFromUrl($url);
            }
        }

        $urls = [];

        if (is_string($uin) && $uin !== '') {
            $urls[] = "https://q1.qlogo.cn/g?b=qq&nk={$uin}&s=640";
            $urls[] = "https://q1.qlogo.cn/g?b=qq&nk={$uin}&s=100";
        }

        $email = $profile['email'] ?? null;

        if (is_string($email) && preg_match('/^[^@\s]+@qq\.com$/i', $email) === 1) {
            $urls[] = 'https://q2.qlogo.cn/headimg_dl?dst_uin='.rawurlencode($email).'&spec=640';
        }

        foreach ($fallbacks as $url) {
            if (preg_match('#^(.*)/\d+$#', $url, $matches) === 1) {
                $urls[] = $matches[1].'/640';
                break;
            }
        }

        foreach ($fallbacks as $url) {
            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    public static function download(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            try {
                $response = (new Client([
                    'timeout' => 2,
                    'connect_timeout' => 2,
                    'http_errors' => false,
                    'allow_redirects' => ['max' => 3],
                ]))->get($url, ['headers' => ['Accept' => 'image/*']]);
            } catch (Throwable) {
                return null;
            }

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            if (! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'image/')) {
                continue;
            }

            $body = (string) $response->getBody();

            if (self::isImage($body)) {
                return $body;
            }
        }

        return null;
    }

    private static function uinFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);

        $uin = $params['nk'] ?? null;

        return is_string($uin) && preg_match('/^\d{5,12}$/', $uin) === 1 ? $uin : null;
    }

    private static function isImage(string $body): bool
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return false;
        }

        $size = @getimagesizefromstring($body);

        return $size !== false
            && $size[0] > 0
            && $size[1] > 0
            && $size[0] * $size[1] <= self::MAX_PIXELS;
    }
}
