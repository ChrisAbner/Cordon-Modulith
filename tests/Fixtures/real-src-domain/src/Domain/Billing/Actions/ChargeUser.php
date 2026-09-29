<?php

namespace Domain\Billing\Actions;

use Domain\Billing\Exceptions\PaymentFailed;
use Domain\Identity\Contracts\UserDirectory;
use Domain\Identity\Data\UserData;

final class ChargeUser
{
    /** @var class-string */
    private string $model = 'Domain\\Identity\\Models\\User';

    public function __construct(private UserDirectory $users) {}

    /**
     * @param  \Domain\Identity\Models\User  $legacy  docblock types are not dependencies
     */
    public function __invoke(int $userId, mixed $legacy = null): UserData
    {
        return $this->users->find($userId) ?? throw new PaymentFailed;
    }
}
