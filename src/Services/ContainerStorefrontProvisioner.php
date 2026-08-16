<?php

declare(strict_types=1);

namespace Misaf\VendraProperty\Services;

use Misaf\VendraContainer\Contracts\ContainerRuntime;
use Misaf\VendraContainer\Exceptions\ContainerRuntimeException;
use Misaf\VendraContainer\Support\ContainerHealthGate;
use Misaf\VendraContainer\ValueObjects\ContainerId;
use Misaf\VendraContainer\ValueObjects\ContainerInfo;
use Misaf\VendraContainer\ValueObjects\ImageReference;
use Misaf\VendraContainer\ValueObjects\RuntimeStatus;
use Misaf\VendraProperty\Contracts\StorefrontProvisioner;
use Misaf\VendraProperty\Support\StorefrontConfigurationValidator;
use Misaf\VendraProperty\Support\StorefrontContainerDefinitionFactory;
use Misaf\VendraProperty\Support\StorefrontObservation;
use Misaf\VendraProperty\Support\StorefrontProvisionRequest;
use Misaf\VendraProperty\Support\StorefrontProvisionResult;
use Misaf\VendraProperty\Support\StorefrontReference;
use Misaf\VendraProperty\Support\StorefrontSettings;
use RuntimeException;

/**
 * Runs each storefront as one container, through whichever runtime is bound.
 *
 * This class is the whole of the property layer's knowledge about containers,
 * and even that is second-hand: it composes a {@see ContainerDefinition} and
 * hands it to {@see ContainerRuntime}. It has no idea whether Docker or Podman
 * answered, because the difference is a binding in `vendra-container` and not a
 * branch anywhere.
 *
 * The platform owns the storefront containers and nothing around them. The
 * network, the reverse proxy, and the TLS material belong to whoever runs the
 * estate, so this reads that environment and refuses to guess at it: an absent
 * network is an error naming the network, not an improvised bridge the proxy is
 * not attached to.
 */
final class ContainerStorefrontProvisioner implements StorefrontProvisioner
{
    public function __construct(
        private readonly ContainerRuntime $runtime,
        private readonly ContainerHealthGate $healthGate,
        private readonly StorefrontConfigurationValidator $validator,
        private readonly StorefrontContainerDefinitionFactory $definitions,
        private readonly StorefrontSettings $settings,
    ) {}

    public function provision(StorefrontProvisionRequest $request): StorefrontProvisionResult
    {
        $this->validator->validate($request, $this->settings->themes);

        $this->assertNetworkExists($this->assertRuntimeReachable());

        $definition = $this->definitions->build($request);

        if ($this->settings->pull) {
            $this->runtime->pull($definition->image);
        }

        /*
         | Replace rather than update: a runtime cannot change the image, labels,
         | or environment of an existing container, and a redeploy always changes
         | at least the encoded configuration. Removing first is also what makes
         | this idempotent, which reconciliation and retry both depend on.
         */
        $this->assertPlatformOwned($definition->id());
        $this->runtime->remove($definition->id());
        $this->runtime->create($definition);
        $this->runtime->start($definition->id());

        $ready = $this->healthGate->awaitDefinition($this->runtime, $definition, $this->settings->healthTimeout);

        return StorefrontProvisionResult::make(
            ready: $ready,
            reference: $definition->name,
            imageDigest: $this->digest($definition->image),
        );
    }

    public function start(StorefrontReference $storefront): void
    {
        $this->runtime->start($this->containerId($storefront));
    }

    public function stop(StorefrontReference $storefront): void
    {
        $this->runtime->stop($this->containerId($storefront));
    }

    public function restart(StorefrontReference $storefront): void
    {
        $this->runtime->restart($this->containerId($storefront));
    }

    public function destroy(StorefrontReference $storefront): void
    {
        $container = $this->containerId($storefront);

        $this->assertPlatformOwned($container);
        $this->runtime->remove($container);
    }

    /**
     * The runtime is pinged first, and deliberately so.
     *
     * `find()` cannot tell "no such container" from "the daemon answered with an
     * error" — both decode to null. Reconciliation reads a null as an absent
     * storefront and rebuilds it, so a momentarily confused daemon would cost the
     * estate a redeploy of everything it could not describe. Establishing the
     * runtime is answering first turns that into a named failure.
     */
    public function observe(StorefrontReference $storefront): StorefrontObservation
    {
        $this->assertRuntimeReachable();

        return StorefrontObservation::fromContainer(
            $this->runtime->find($this->containerId($storefront)),
        );
    }

    public function logs(StorefrontReference $storefront, int $lines = 200): string
    {
        return $this->runtime->logs($this->containerId($storefront), $lines)->output;
    }

    private function containerId(StorefrontReference $storefront): ContainerId
    {
        return new ContainerId($this->settings->containerName($storefront->slug));
    }

    /**
     * Fail before anything is placed when the runtime is not answering.
     *
     * `ping()` reports rather than throws, so the message an operator sees names
     * the endpoint and whether it was the API version that was refused.
     */
    private function assertRuntimeReachable(): RuntimeStatus
    {
        $status = $this->runtime->ping();

        if ( ! $status->reachable) {
            throw new RuntimeException($status->message ?? 'The container runtime is not reachable.');
        }

        return $status;
    }

    /**
     * An absent network is reported against the daemon that was actually asked.
     *
     * Networks are per-daemon, so "it does not exist" and "you are talking to a
     * different daemon than you think" produce the identical symptom. Naming the
     * endpoint and what answered there separates them without the operator
     * having to go looking, and a configured/reported engine mismatch is called
     * out because it is the likeliest way to arrive here with the network sitting
     * in front of you on the other runtime.
     */
    private function assertNetworkExists(RuntimeStatus $status): void
    {
        if (null !== $this->runtime->findNetwork($this->settings->network)) {
            return;
        }

        $message = sprintf(
            'The container network [%s] does not exist on %s. The platform manages storefront containers only — '
            . 'create the network with the rest of the estate before provisioning.',
            $this->settings->network,
            $status->describeDaemon(),
        );

        if ($status->engineMismatch()) {
            $message .= sprintf(
                ' That endpoint is serving %s while CONTAINER_RUNTIME is set to %s, so this may be the wrong daemon '
                . 'rather than a missing network.',
                $status->reportedEngine()?->value,
                $status->runtime,
            );
        }

        throw new RuntimeException($message);
    }

    /**
     * Refuse to touch a container the platform did not place.
     *
     * Container names are chosen by an operator-configurable prefix, so a
     * collision with something else on the same runtime is possible. Removing
     * somebody else's container because it happened to answer to the name we
     * wanted is not a recoverable mistake, so ownership is checked first.
     */
    private function assertPlatformOwned(ContainerId $container): void
    {
        $existing = $this->runtime->find($container);

        if (null === $existing || $this->isPlatformOwned($existing)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The container [%s] already exists and was not placed by the platform. '
            . 'Rename it, or change STOREFRONT_NAME_PREFIX, before deploying this storefront.',
            $container,
        ));
    }

    private function isPlatformOwned(ContainerInfo $container): bool
    {
        return $container->hasLabel(
            StorefrontContainerDefinitionFactory::MANAGED_BY_LABEL,
            StorefrontContainerDefinitionFactory::MANAGED_BY,
        );
    }

    /**
     * Resolve the digest actually deployed.
     *
     * A pinned reference already carries it. Otherwise the pulled image is
     * inspected, so a tag-based deployment still records exactly what ran — which
     * a reference alone cannot tell you once the tag moves.
     */
    private function digest(ImageReference $image): ?string
    {
        if ($image->isPinned()) {
            return $image->digest;
        }

        try {
            return $this->runtime->inspectImage($image)?->digest;
        } catch (ContainerRuntimeException) {
            // A missing digest is not worth failing a deployment that is already
            // running; the reference is still recorded.
            return null;
        }
    }
}
