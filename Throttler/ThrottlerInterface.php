<?php

namespace Pluk77\SymfonySphinxBundle\Throttler;

interface ThrottlerInterface
{
    public function wait(): void;
}