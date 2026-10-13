<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

interface ConfigurationProviderInterface
{
    public function getConfiguration(): OpinionConfiguration;
}
