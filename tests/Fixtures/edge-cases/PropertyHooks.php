<?php

namespace Edge\Source;

use Edge\Target\HookBody;
use Edge\Target\HookParam;
use Edge\Target\HookType;
use Edge\Target\PromotedHookBody;
use Edge\Target\PromotedHookType;

// PHP 8.4 property hooks. Parsed by nikic/php-parser 5.2+, with names resolved inside hooks since 5.3.
final class PropertyHooks
{
    public HookType $value {
        set(HookParam $input) {
            $this->value = new HookBody($input);
        }
    }

    public function __construct(
        public PromotedHookType $promoted {
            get => $this->promoted ?? PromotedHookBody::fallback();
        },
    ) {}
}
