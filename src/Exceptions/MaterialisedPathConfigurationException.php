<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPathDefaults;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;

/**
 * Thrown when a materialised-path declaration is malformed:
 *
 *  - {@see NestedSetMaterialisedPath}
 *    declaring zero or more than one source (key / attribute / slug).
 *  - Two {@see NestedSetMaterialisedPathDefaults}
 *    on a single class.
 *  - A {@see MaterialisedPath} value
 *    object built with empty column / source name.
 *  - Conflicting attribute + method-form definitions where both
 *    declare the same column with incompatible source kinds (resolved
 *    at registry build time).
 *
 * Extends LogicException because misdeclaration is a programmer error;
 * the registry surfaces it once at boot time so failure is immediate
 * rather than runtime-only.
 */
final class MaterialisedPathConfigurationException extends LogicException implements NestedSetException {}
