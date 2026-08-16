<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Support;

use Illuminate\Database\Eloquent\Model;
use Misaf\VendraSubscription\Contracts\SubscriptionSubscriber;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;

final class PropertyQuota
{
    /**
     * Whether the subscriber may create another property right now.
     *
     * @param  Model&SubscriptionSubscriber  $subscriber
     */
    public function canCreateProperty(SubscriptionSubscriber $subscriber): bool
    {
        if ( ! $subscriber->isSubscriptionActive()) {
            return false;
        }

        $plan = $subscriber->activeSubscription()?->plan;

        if (null === $plan) {
            return false;
        }

        return $subscriber->subscribedPropertyCount() < $plan->max_units;
    }

    /**
     * The number of additional properties the subscriber may still create.
     *
     * @param  Model&SubscriptionSubscriber  $subscriber
     */
    public function remainingProperties(SubscriptionSubscriber $subscriber): int
    {
        if ( ! $subscriber->isSubscriptionActive()) {
            return 0;
        }

        $plan = $subscriber->activeSubscription()?->plan;

        if (null === $plan) {
            return 0;
        }

        return max(0, $plan->max_units - $subscriber->subscribedPropertyCount());
    }

    /**
     * @param  Model&SubscriptionSubscriber  $subscriber
     *
     * @throws SubscriptionLimitException when the subscriber may not create a property
     */
    public function assertCanCreateProperty(SubscriptionSubscriber $subscriber): void
    {
        if ( ! $subscriber->isSubscriptionActive()) {
            throw SubscriptionLimitException::resellerInactive($subscriber);
        }

        $plan = $subscriber->activeSubscription()?->plan;

        if (null === $plan) {
            throw SubscriptionLimitException::noActiveSubscription($subscriber);
        }

        if ($subscriber->subscribedPropertyCount() >= $plan->max_units) {
            throw SubscriptionLimitException::propertyQuotaReached($subscriber, $plan->max_units);
        }
    }
}
