<?php

namespace Modules\Accounts\Contracts;

use Modules\Accounts\Data\TeamData;

interface FindsTeams
{
    public function find(int $id): TeamData;
}
