<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;

/**
 * Class-level defaults applied to every {@see NestedSetMaterialisedPath}
 * declared on the same class (or inherited from a parent class). Sits
 * between per-path explicit values and `class_defaults` config — the
 * five-layer resolution lives in
 * {@see MaterialisedPathRegistry}.
 *
 * Non-repeatable: two of these on a single class throws
 * {@see MaterialisedPathConfigurationException}
 * at registry-resolve time.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class NestedSetMaterialisedPathDefaults
{
    public function __construct(
        public ?string $separator = null,
        public ?bool $wrap = null,
        public ?int $maxLength = null,
        public ?bool $rejectSeparatorInSegment = null,
        public ?bool $uniquePerParent = null,
    ) {}

    /**
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->separator !== null) {
            $out['separator'] = $this->separator;
        }
        if ($this->wrap !== null) {
            $out['wrap'] = $this->wrap;
        }
        if ($this->maxLength !== null) {
            $out['maxLength'] = $this->maxLength;
        }
        if ($this->rejectSeparatorInSegment !== null) {
            $out['rejectSeparatorInSegment'] = $this->rejectSeparatorInSegment;
        }
        if ($this->uniquePerParent !== null) {
            $out['uniquePerParent'] = $this->uniquePerParent;
        }

        return $out;
    }
}
