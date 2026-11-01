<?php

declare(strict_types=1);

namespace TacraftSociality\QqOauth\Api\Controllers;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TacraftSociality\OauthCore\Support\FakeEmail;

class QqOnlyStatusController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        return new JsonResponse([
            'isQqOnly' => FakeEmail::isQqOnly($actor),
        ]);
    }
}
