<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum Category: string
{
    case Bug = 'bug';
    case Content = 'content';
    case Idea = 'idea';
    case Other = 'other';
}
