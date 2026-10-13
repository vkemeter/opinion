<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
