<?php

use Cordon\Support\Paths;

it('makes paths relative to the base path', function (string $file, string $relative) {
    expect(Paths::relative('/mono/apps/web', $file))->toBe($relative);
})->with([
    'inside' => ['/mono/apps/web/app/X.php', 'app/X.php'],
    'a sibling directory of a monorepo' => ['/mono/packages/billing/src/X.php', '../../packages/billing/src/X.php'],
    'a directory with the same prefix' => ['/mono/apps/website/X.php', '../website/X.php'],
    'another root directory' => ['/srv/shared/X.php', '../../../srv/shared/X.php'],
    'another Windows drive' => ['D:/code/X.php', 'D:/code/X.php'],
]);
