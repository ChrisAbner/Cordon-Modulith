<?php

namespace Domain\Identity\Models;

final class User
{
    public function __construct(public int $id, public string $email) {}
}
