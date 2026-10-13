<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Authentication;

use Psr\Http\Message\ServerRequestInterface;

interface AdminRequestCheckerInterface
{
    public function isAdminRequest(ServerRequestInterface $request): bool;
}
