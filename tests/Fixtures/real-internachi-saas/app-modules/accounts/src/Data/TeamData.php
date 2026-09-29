<?php

namespace Modules\Accounts\Data;

final readonly class TeamData
{
    public function __construct(public int $id, public string $name) {}
}
