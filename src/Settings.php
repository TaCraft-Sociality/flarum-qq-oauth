<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth;

use Illuminate\Contracts\Filesystem\Factory;

final class Settings
{
    public const PREFIX = 'tacraft-sociality-qq-oauth.';

    public const APP_ID = self::PREFIX.'app_id';

    public const APP_SECRET = self::PREFIX.'app_secret';

    public const ICON_PATH = self::PREFIX.'login_icon_path';

    public const SYNC_AVATAR = self::PREFIX.'sync_avatar';

    public const ASSETS_DISK = 'flarum-assets';

    public static function iconUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return resolve(Factory::class)->disk(self::ASSETS_DISK)->url($path);
    }
}
