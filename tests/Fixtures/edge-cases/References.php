<?php

namespace Edge\Source;

use Edge\Target\AnonymousBase;
use Edge\Target\AttributeArgument;
use Edge\Target\CallableTarget;
use Edge\Target\CaughtA;
use Edge\Target\CaughtB;
use Edge\Target\ClassConstant;
use Edge\Target\ClosureParameter;
use Edge\Target\DnfA;
use Edge\Target\DnfB;
use Edge\Target\DnfC;
use Edge\Target\EnumInterface;
use Edge\Target\InstanceOfTarget;
use Edge\Target\IntersectionA;
use Edge\Target\IntersectionB;
use Edge\Target\MarkerAttribute;
use Edge\Target\MatchTarget;
use Edge\Target\NewInInitializer;
use Edge\Target\ConstantFetchTarget;
use Edge\Target\StaticPropertyTarget;
use Edge\Target\PromotedProperty;
use Edge\Target\SomeTrait;
use Edge\Target\StaticClosureReturn;
use Edge\Target\UnionA;
use Edge\Target\UnionB;
use Edge\Target\UnusedImport;

use function Edge\Target\helper;

use const Edge\Target\SOME_CONSTANT;

enum Status: string implements EnumInterface
{
    case Active = 'active';
}

trait LocalTrait
{
    use SomeTrait;
}

#[MarkerAttribute(AttributeArgument::class)]
final class References
{
    public function __construct(private PromotedProperty $promoted) {}

    public function anonymous(): object
    {
        return new class extends AnonymousBase {};
    }

    public function firstClassCallable(): \Closure
    {
        return CallableTarget::make(...);
    }

    public function matches(object $value): string
    {
        return match (true) {
            $value instanceof MatchTarget => 'match',
            default => 'other',
        };
    }

    public function check(object $value): bool
    {
        return $value instanceof InstanceOfTarget;
    }

    public function catches(): void
    {
        try {
            helper();
        } catch (CaughtA|CaughtB) {
        }
    }

    public function union(UnionA|UnionB $value): void {}

    public function intersection(IntersectionA&IntersectionB $value): void {}

    public function dnf((DnfA&DnfB)|DnfC|null $value): void {}

    public function classConstant(): string
    {
        return ClassConstant::class;
    }

    public function staticMembers(): array
    {
        return [StaticPropertyTarget::$value, ConstantFetchTarget::VALUE];
    }

    public function initializer(object $value = new NewInInitializer): object
    {
        return $value;
    }

    public function staticClosure(): \Closure
    {
        return static fn (ClosureParameter $parameter): StaticClosureReturn => $parameter->get();
    }
}
