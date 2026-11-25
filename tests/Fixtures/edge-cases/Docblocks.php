<?php

namespace Edge\Source;

use Edge\Target\Aliased as Renamed;
use Edge\Target\Imported;
use Edge\Target\{GroupedA, GroupedB};

/**
 * @template TModel of \Edge\Target\TemplateBound
 * @template TFree
 * @extends \Edge\Target\ExtendsBase<TModel, \Edge\Target\ExtendsArgument>
 * @implements \Edge\Target\ImplementsContract<Imported>
 * @mixin \Edge\Target\MixinTarget
 * @property \Edge\Target\PropertyTag $magic
 * @property-read Renamed $readable
 * @property-write GroupedA $writable
 * @method \Edge\Target\MethodReturn find(\Edge\Target\MethodParameter $id)
 * @method static GroupedB make()
 * @phpstan-type ShapeOfThings array{id: int, item: \Edge\Target\ShapeItem}
 */
final class Docblocks
{
    /** @use \Edge\Target\UsedGeneric<TModel> */
    use LocalDocTrait;

    /** @var \Edge\Target\PropertyVar */
    private mixed $plain = null;

    /** @var array<string, list<\Edge\Target\NestedGeneric>> */
    private array $nested = [];

    /** @var Imported|Renamed|null */
    private mixed $union = null;

    /** @var class-string<\Edge\Target\ClassStringTarget> */
    private string $name = '';

    /** @var Sibling */
    private mixed $sibling = null;

    /** @var Sub\Thing */
    private mixed $subNamespace = null;

    /** @var namespace\Sub\Relative */
    private mixed $relative = null;

    /** @var TModel|TFree */
    private mixed $templates = null;

    /** @var array-key|positive-int|non-empty-string|class-string|static|self|$this */
    private mixed $pseudo = null;

    /**
     * @template TMethod of \Edge\Target\MethodTemplateBound
     *
     * @param  TMethod  $a
     * @param  TFree  $b
     * @param  \Edge\Target\ParamIntersection&\Countable  $c
     * @param  callable(\Edge\Target\CallableParam): \Edge\Target\CallableReturn  $callback
     * @param  array{name: string, owner: \Edge\Target\ShapeOwner}  $shape
     * @param  \Edge\Target\ListItem[]  $items
     * @param  ShapeOfThings  $alias
     * @param  list<int>  $numbers
     * @param  int-mask<1, 2>  $mask
     * @return ($a is \Edge\Target\ConditionalSubject ? int : string)
     *
     * @throws \Edge\Target\ThrownException|\Edge\Target\OtherThrown
     */
    public function run(mixed $a, mixed $b, mixed $c, mixed $callback, mixed $shape, mixed $items, mixed $alias, mixed $numbers, mixed $mask): int|string
    {
        /** @var \Edge\Target\InlineVar $value */
        $value = $this->plain;

        return 1;
    }

    /**
     * @return static
     */
    public function fluent(): static
    {
        return $this;
    }
}

/** @param \Edge\Target\FunctionParam $x */
function documented($x): void {}
