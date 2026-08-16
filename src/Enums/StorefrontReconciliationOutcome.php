<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Enums;

/**
 * What a reconciliation pass did to one storefront.
 *
 * Reported rather than inferred: an operator running a converge pass over the
 * estate needs to know which storefronts were touched, and "12 reconciled" says
 * nothing at all when the honest answer is "11 already correct, 1 restarted".
 */
enum StorefrontReconciliationOutcome: string
{
    case InSync = 'in sync';
    case Started = 'started';
    case Stopped = 'stopped';
    case Deployed = 'deployed';
    case Redeployed = 'redeployed';

    /**
     * Whether the runtime was changed to close a gap.
     */
    public function changedAnything(): bool
    {
        return self::InSync !== $this;
    }
}
