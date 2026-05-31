<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;

/**
 * Declares a materialised-path column on a nested-set model.
 *
 * Repeatable so a single model may carry several columns, each derived
 * from a different source attribute or formatted differently:
 *
 *     #[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]
 *     #[NestedSetMaterialisedPath(column: 'crumb_path', attribute: 'display_name', separator: ' › ', wrap: false)]
 *
 * Exactly one of `key` / `attribute` / `slug` must be set; passing none
 * or several throws {@see MaterialisedPathConfigurationException} when
 * the registry resolves declarations. Nullable formatting fields
 * (`separator`, `wrap`, `maxLength`, `rejectSeparatorInSegment`,
 * `uniquePerParent`) signal "fall through to the defaults chain" — the
 * registry fills them via the five-layer merge before they reach the
 * lifecycle concern.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class NestedSetMaterialisedPath
{
    public function __construct(
        public string $column,
        public bool $key = false,
        public ?string $attribute = null,
        public ?string $slug = null,
        public ?string $separator = null,
        public ?bool $wrap = null,
        public ?int $maxLength = null,
        public ?bool $rejectSeparatorInSegment = null,
        public ?bool $uniquePerParent = null,
    ) {}

    /**
     * Materialises this declaration as a {@see MaterialisedPath} value
     * object. Throws when zero or several source kinds are declared.
     */
    public function toValueObject(): MaterialisedPath
    {
        if ($this->column === '') {
            throw new MaterialisedPathConfigurationException(
                'NestedSetMaterialisedPath: `column` must not be empty.',
            );
        }

        $sources = ($this->key ? 1 : 0)
            + ($this->attribute !== null ? 1 : 0)
            + ($this->slug !== null ? 1 : 0);

        if ($sources === 0) {
            throw new MaterialisedPathConfigurationException(sprintf(
                'NestedSetMaterialisedPath for column "%s": no source declared. '
                .'Provide exactly one of key: true, attribute: "…", or slug: "…".',
                $this->column,
            ));
        }

        if ($sources > 1) {
            throw new MaterialisedPathConfigurationException(sprintf(
                'NestedSetMaterialisedPath for column "%s": multiple sources declared. '
                .'Provide exactly one of key: true, attribute: "…", or slug: "…".',
                $this->column,
            ));
        }

        $path = match (true) {
            $this->key => MaterialisedPath::key(),
            $this->attribute !== null => MaterialisedPath::attribute($this->attribute),
            $this->slug !== null => MaterialisedPath::slug($this->slug),
            default => throw new MaterialisedPathConfigurationException(
                'Unreachable: source validation passed but no source kind matched.',
            ),
        };

        if ($this->separator !== null) {
            $path = $path->separator($this->separator);
        }
        if ($this->wrap !== null) {
            $path = $path->wrap($this->wrap);
        }
        if ($this->maxLength !== null) {
            $path = $path->maxLength($this->maxLength);
        }
        if ($this->rejectSeparatorInSegment !== null) {
            $path = $path->rejectSeparatorInSegment($this->rejectSeparatorInSegment);
        }
        if ($this->uniquePerParent !== null) {
            return $path->uniquePerParent($this->uniquePerParent);
        }

        return $path;
    }
}
