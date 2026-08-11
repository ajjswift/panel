<?php

namespace Pterodactyl\Services\Resellers;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Models\Allocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The single source of scoping for the /reseller area.
 *
 * Reseller controllers must resolve every model through this class rather than
 * through route-model binding: a hand-typed ID belonging to another tenant has
 * to 404, and centralising that here means a new controller cannot forget it.
 */
class ResellerContext
{
    private ?Reseller $reseller = null;

    public function __construct(private AuthFactory $auth)
    {
    }

    /**
     * The reseller owned by the authenticated user.
     *
     * @throws AccessDeniedHttpException if there isn't one — every /reseller
     *                                   route sits behind ResellerAuthenticate,
     *                                   so reaching this is a routing bug
     */
    public function reseller(): Reseller
    {
        if (is_null($this->reseller)) {
            /** @var User|null $user */
            $user = $this->auth->guard()->user();
            $this->reseller = $user?->reseller;

            if (is_null($this->reseller) || !$this->reseller->enabled) {
                throw new AccessDeniedHttpException();
            }
        }

        return $this->reseller;
    }

    public function id(): int
    {
        return $this->reseller()->id;
    }

    /**
     * Users belonging to this reseller. Never includes the reseller's own
     * account, which has a null reseller_id by design.
     *
     * @return Builder<User>
     */
    public function users(): Builder
    {
        return User::query()->where('reseller_id', $this->id());
    }

    /** @return Builder<Server> */
    public function servers(): Builder
    {
        return Server::query()->forReseller($this->id());
    }

    /**
     * Nodes this reseller is allowed to deploy to.
     *
     * @return Builder<Node>
     */
    public function nodes(): Builder
    {
        return Node::query()->whereIn('nodes.id', $this->reseller()->nodes()->select('nodes.id'));
    }

    /**
     * Allocations on nodes this reseller may deploy to.
     *
     * @return Builder<Allocation>
     */
    public function allocations(): Builder
    {
        return Allocation::query()->whereIn('allocations.node_id', $this->nodes()->select('nodes.id'));
    }

    /** @throws NotFoundHttpException */
    public function findUser(int|string $id): User
    {
        return $this->firstOrNotFound($this->users()->whereKey($id));
    }

    /** @throws NotFoundHttpException */
    public function findServer(int|string $id): Server
    {
        return $this->firstOrNotFound($this->servers()->whereKey($id));
    }

    /** @throws NotFoundHttpException */
    public function findNode(int|string $id): Node
    {
        return $this->firstOrNotFound($this->nodes()->whereKey($id));
    }

    /** @throws NotFoundHttpException */
    public function findAllocation(int|string $id): Allocation
    {
        return $this->firstOrNotFound($this->allocations()->whereKey($id));
    }

    /**
     * True when the given model belongs to this reseller. Used by the policy;
     * controllers should prefer the find* helpers, which fail closed.
     */
    public function owns(User|Server|Node $model): bool
    {
        return match (true) {
            $model instanceof User => $model->reseller_id === $this->id(),
            $model instanceof Server => $model->user->reseller_id === $this->id(),
            $model instanceof Node => $this->nodes()->whereKey($model->id)->exists(),
        };
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     *
     * @return TModel
     */
    private function firstOrNotFound(Builder $query)
    {
        $model = $query->first();

        if (is_null($model)) {
            // Deliberately a 404 rather than a 403: a reseller should not be
            // able to probe for the existence of another tenant's resources.
            throw new NotFoundHttpException();
        }

        return $model;
    }
}
