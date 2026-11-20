<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

/**
 * Processing status set by administrators, independent of the delivery status.
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
