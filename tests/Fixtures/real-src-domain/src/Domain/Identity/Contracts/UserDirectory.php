<?php

namespace Domain\Identity\Contracts;

use Domain\Identity\Data\UserData;

interface UserDirectory
{
    /**
     * @return list<\Domain\Identity\Models\User>
     */
    public function all(): array;

    public function find(int $id): ?UserData;
}
