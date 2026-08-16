<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Exceptions;

use DomainException;
use Misaf\VendraProperty\Enums\StorefrontDeploymentStatus;

/**
 * A storefront deployment was asked to move to a status its current one does not
 * allow — for example back to Failed while the queue is still retrying it.
 */
final class InvalidStorefrontTransitionException extends DomainException
{
    public static function between(StorefrontDeploymentStatus $from, StorefrontDeploymentStatus $to): self
    {
        return new self(sprintf(
            'A storefront deployment cannot move from [%s] to [%s].',
            $from->value,
            $to->value,
        ));
    }
}
