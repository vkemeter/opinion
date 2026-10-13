<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum Source: string
{
    case Frontend = 'frontend';
    case Backend = 'backend';
}
