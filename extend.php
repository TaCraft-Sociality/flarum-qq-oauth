<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth;

use Flarum\Api\Context;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema\Boolean;
use Flarum\Extend;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\RegisteringFromProvider;
use Flarum\User\Event\Saving;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use TacraftSociality\QqOauth\Api\Controllers\DeleteIconController;
use TacraftSociality\QqOauth\Api\Controllers\QqOnlyStatusController;
use TacraftSociality\QqOauth\Api\Controllers\UploadIconController;
use TacraftSociality\QqOauth\Forum\Controllers\QQAuthController;
use TacraftSociality\OauthCore\Support\FakeEmail;
use TacraftSociality\OauthCore\Support\NotificationGuard;
use TacraftSociality\QqOauth\Listeners\SyncQQAvatar;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('forum'))
        ->get('/auth/qq', 'auth.qq', QQAuthController::class),

    (new Extend\Routes('api'))
        ->post('/qq-oauth/icon', 'qq-oauth.icon.upload', UploadIconController::class)
        ->delete('/qq-oauth/icon', 'qq-oauth.icon.delete', DeleteIconController::class)
        ->get('/qq-oauth/status', 'qq-oauth.status', QqOnlyStatusController::class),

    // Email visibility, the reserved email domains and the notification mail
    // switch live in flarum-oauth-core, so every OAuth extension inherits them.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            // Lets the frontend hide password/email controls without ever
            // exposing the virtual address.
            Boolean::make('isQqOnly')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => $user->exists && FakeEmail::isQqOnly($user)),

            // Any account that can log in through QQ is provider managed: the
            // self-service email/password controls are hidden for it, exactly
            // like the GitHub/Microsoft/Blessing extensions do it.
            Boolean::make('hasQqBinding')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => FakeEmail::hasQqBinding($user)),
        ]),

    (new Extend\Event())
        ->listen(RegisteringFromProvider::class, SyncQQAvatar::class)
        ->listen(Saving::class, function (Saving $event) {
            $user = $event->user;

            if (! $user->exists) {
                $token = null;
                $tokenString = $event->data['attributes']['token'] ?? null;

                if (is_string($tokenString) && $tokenString !== '') {
                    try {
                        $token = RegistrationToken::validOrFail($tokenString);
                    } catch (\Throwable) {
                        $token = null;
                    }
                }

                $viaQq = $token !== null && $token->provider === 'qq';
                $email = (string) $user->email;

                // Notifications are delivered in-site only: keep the email
                // channel off from the very first save, whatever the provider.
                NotificationGuard::disableEmailPreferences($user);

                if ($viaQq) {
                    // QQ signups never carry an email: the address is derived
                    // here, on the server, and stays here.
                    //
                    // It is derived from the unionid, not from the openid: the
                    // openid is unique per application, so the forum and the
                    // skin site (two different QQ apps) would otherwise derive
                    // two different addresses for one player. The unionid is
                    // shared by every app of this developer, which is what makes
                    // both sites resolve to the same account.
                    //
                    // The unionid is mandatory: without it the two sites would
                    // silently end up with two accounts for one player, so the
                    // signup is refused instead of falling back to the openid.
                    if (trim($email) === '' || FakeEmail::isReserved($email)) {
                        $identity = (string) (((array) $token->payload)['unionid'] ?? '');

                        if ($identity === '') {
                            throw new ValidationException([
                                'email' => '当前 QQ 账号没有返回 unionid，无法完成登录。请让管理员在 QQ 互联后台为本站应用申请 unionid 权限后重试。',
                            ]);
                        }

                        $user->email = FakeEmail::assignUnique(FakeEmail::forIdentity('qq', $identity));

                        $user->activate();
                    }

                    return;
                }

                // The whole reserved domain is off limits, not just the
                // virtual prefix: otherwise anybody could sign up with
                // something like "whatever@phoney.tcscraft.com".
                if (FakeEmail::isReserved($email)) {
                    throw new ValidationException([
                        'email' => '您不能使用系统保留的虚拟邮箱。',
                    ]);
                }

                return;
            }

            $isSelf = $event->actor->id === $user->id;
            $attributes = $event->data['attributes'] ?? [];
            $requestedEmail = $attributes['email'] ?? null;

            // Self-service email changes go through requestEmailChange(), which
            // does not touch the email attribute. The only way to spot them is
            // the requested address in the request body.
            if ($isSelf && is_string($requestedEmail) && $requestedEmail !== '') {
                $currentEmail = (string) $user->getOriginal('email');

                if (strcasecmp($requestedEmail, $currentEmail) !== 0) {
                    // Same rule as the GitHub/Microsoft/Blessing extensions:
                    // once a third party manages the account, its email address
                    // is no longer editable from the settings page.
                    if (FakeEmail::hasQqBinding($user)) {
                        throw new ValidationException([
                            'email' => '当前账号通过 QQ 登录，暂不支持自助修改邮箱。',
                        ]);
                    }

                    if (FakeEmail::isReserved($requestedEmail)) {
                        throw new ValidationException([
                            'email' => '您不能使用系统保留的虚拟邮箱。',
                        ]);
                    }
                }
            }

            if ($user->isDirty('email') && ! $event->actor->isAdmin() && FakeEmail::isReserved((string) $user->email)) {
                throw new ValidationException([
                    'email' => '您不能使用系统保留的虚拟邮箱。',
                ]);
            }

            if (
                $isSelf
                && FakeEmail::hasQqBinding($user)
                && (array_key_exists('password', $attributes) || $user->isDirty('password'))
            ) {
                throw new ValidationException([
                    'password' => '当前账号通过 QQ 登录，暂不支持设置登录密码。',
                ]);
            }

            // The email column is hidden from the notification grid, but the
            // API could still flip the preference on: force it back off.
            if ($user->isDirty('preferences')) {
                NotificationGuard::disableEmailPreferences($user);
            }
        }),

    (new Extend\Settings())
        ->default(Settings::APP_ID, '')
        ->default(Settings::APP_SECRET, '')
        ->default(Settings::ICON_PATH, '')
        ->default(Settings::SYNC_AVATAR, '1')
        ->serializeToForum('qqOAuthIconUrl', Settings::ICON_PATH, function ($value): ?string {
            return Settings::iconUrl($value);
        })
        ->serializeToForum('qqOAuthEnabled', Settings::APP_ID, function ($value): bool {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }

            $secret = resolve(SettingsRepositoryInterface::class)->get(Settings::APP_SECRET, '');

            return is_string($secret) && trim($secret) !== '';
        }),
];
