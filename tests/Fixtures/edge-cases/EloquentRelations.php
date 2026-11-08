<?php

namespace Edge\Source;

use Edge\Target\BelongsToManyTarget;
use Edge\Target\BelongsToTarget;
use Edge\Target\HasManyTarget;
use Edge\Target\HasManyThroughTarget;
use Edge\Target\HasOneTarget;
use Edge\Target\HasOneThroughTarget;
use Edge\Target\MorphedByManyTarget;
use Edge\Target\MorphManyTarget;
use Edge\Target\MorphOneTarget;
use Edge\Target\MorphToManyTarget;
use Edge\Target\QueryTarget;
use Edge\Target\TypeTarget;
use Edge\Target\UnusedTarget;

// Eloquent relations to another module's model, and look-alikes that are not.
final class EloquentRelations
{
    public function a() { return $this->belongsTo(BelongsToTarget::class); }

    public function b() { return $this->hasOne(HasOneTarget::class); }

    public function c() { return $this->hasMany(HasManyTarget::class); }

    public function d() { return $this->belongsToMany(BelongsToManyTarget::class); }

    public function e() { return $this->hasOneThrough(HasOneThroughTarget::class, self::class); }

    public function f() { return $this->hasManyThrough(HasManyThroughTarget::class, self::class); }

    public function g() { return $this->morphOne(MorphOneTarget::class, 'owner'); }

    public function h() { return $this->morphMany(MorphManyTarget::class, 'owner'); }

    public function i() { return $this->morphToMany(MorphToManyTarget::class, 'taggable'); }

    public function j() { return $this->morphedByMany(MorphedByManyTarget::class, 'taggable'); }

    // Not on $this: not a relation of this model.
    public function query($query) { return $query->belongsTo(QueryTarget::class); }

    /** @return \Edge\Target\DocblockTarget a docblock is never a reference */
    public function typed(TypeTarget $type) {}
}
