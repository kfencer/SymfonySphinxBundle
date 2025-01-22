<?php

namespace Pluk77\SymfonySphinxBundle\Throttler;

interface ThrottlerFabricInterface
{
    public function getThrottler(array $indexes, bool $isReadQuery): ?ThrottlerInterface;
}