<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum Severity: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
