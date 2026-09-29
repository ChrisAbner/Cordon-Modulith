<?php

namespace Domain\Identity\Data;

final readonly class UserData
{
    public function __construct(public int $id, public string $email) {}
}
