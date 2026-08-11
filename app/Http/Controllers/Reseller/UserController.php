<?php

namespace Pterodactyl\Http\Controllers\Reseller;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Users\UserUpdateService;
use Pterodactyl\Traits\Helpers\AvailableLanguages;
use Pterodactyl\Services\Resellers\ResellerContext;
use Pterodactyl\Services\Users\UserCreationService;
use Pterodactyl\Services\Users\UserDeletionService;
use Pterodactyl\Http\Requests\Reseller\UserFormRequest;
use Pterodactyl\Services\Resellers\ResellerQuotaService;
use Pterodactyl\Http\Requests\Reseller\NewUserFormRequest;

class UserController extends Controller
{
    use AvailableLanguages;

    public function __construct(
        private AlertsMessageBag $alert,
        private ResellerContext $context,
        private ResellerQuotaService $quota,
        private UserCreationService $creationService,
        private UserDeletionService $deletionService,
        private UserUpdateService $updateService,
    ) {
    }

    public function index(Request $request): View
    {
        $users = $this->context->users()
            ->withCount('servers')
            ->when($request->input('filter.email'), function ($query, $email) {
                $query->where('email', 'LIKE', "%$email%");
            })
            ->orderBy('id')
            ->paginate(50);

        return view('reseller.users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('reseller.users.new', [
            'languages' => $this->getAvailableLanguages(true),
        ]);
    }

    public function view(int $user): View
    {
        return view('reseller.users.view', [
            'user' => $this->context->findUser($user),
            'languages' => $this->getAvailableLanguages(true),
        ]);
    }

    /**
     * @throws \Throwable
     */
    public function store(NewUserFormRequest $request): RedirectResponse
    {
        $reseller = $this->context->reseller();
        $this->quota->assertCanCreateUser($reseller);

        // reseller_id is forced here rather than taken from the payload — the
        // form request strips the key outright.
        $user = $this->creationService->handle(
            array_merge($request->normalize(), ['reseller_id' => $reseller->id])
        );

        $this->alert->success('The account has been created.')->flash();

        return redirect()->route('reseller.users.view', $user->id);
    }

    /**
     * @throws \Throwable
     */
    public function update(UserFormRequest $request, int $user): RedirectResponse
    {
        $model = $this->context->findUser($user);

        $this->updateService
            ->setUserLevel(User::USER_LEVEL_USER)
            ->handle($model, $request->normalize());

        $this->alert->success('The account has been updated.')->flash();

        return redirect()->route('reseller.users.view', $model->id);
    }

    /**
     * @throws \Exception
     * @throws DisplayException
     */
    public function delete(int $user): RedirectResponse
    {
        $model = $this->context->findUser($user);

        // UserDeletionService already refuses to delete an account that owns
        // servers, which is what keeps a reseller from orphaning them.
        $this->deletionService->handle($model);

        $this->alert->success('The account has been deleted.')->flash();

        return redirect()->route('reseller.users');
    }
}
