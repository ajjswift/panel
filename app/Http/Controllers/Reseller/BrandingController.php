<?php

namespace Pterodactyl\Http\Controllers\Reseller;

use Illuminate\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\ResellerDomain;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Resellers\ResellerContext;
use Pterodactyl\Services\Resellers\ColorRampGenerator;
use Pterodactyl\Http\Requests\Reseller\DomainFormRequest;
use Pterodactyl\Http\Requests\Reseller\BrandingFormRequest;
use Pterodactyl\Services\Resellers\ResellerBrandingResolver;
use Pterodactyl\Services\Resellers\DomainVerificationService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BrandingController extends Controller
{
    /**
     * Uploads live on the public disk so they can be served without an
     * authenticated request — the login page on a reseller's domain needs the
     * logo before anyone has signed in.
     */
    private const UPLOAD_DIRECTORY = 'reseller-branding';

    public function __construct(
        private AlertsMessageBag $alert,
        private ResellerContext $context,
        private ColorRampGenerator $generator,
        private ResellerBrandingResolver $resolver,
        private DomainVerificationService $verification,
    ) {
    }

    public function index(): View
    {
        $reseller = $this->context->reseller();

        return view('reseller.branding.index', [
            'domains' => $reseller->domains()->orderBy('hostname')->get(),
            'preview' => [
                'brand' => $this->generator->ramp($this->generator->normalizeHex($reseller->brand_color) ?? '#a855f7'),
                'accent' => $this->generator->ramp($this->generator->normalizeHex($reseller->accent_color) ?? '#ff8a3d'),
            ],
        ]);
    }

    public function update(BrandingFormRequest $request): RedirectResponse
    {
        $reseller = $this->context->reseller();

        $attributes = [
            'app_name' => $request->input('app_name') ?: null,
            'brand_color' => $this->generator->normalizeHex($request->input('brand_color')),
            'accent_color' => $this->generator->normalizeHex($request->input('accent_color')),
            'theme_updated_at' => now(),
        ];

        foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $input => $column) {
            if ($request->boolean('remove_' . $input)) {
                $this->deleteUpload($reseller->{$column});
                $attributes[$column] = null;
                continue;
            }

            $file = $request->file($input);
            if ($file instanceof UploadedFile) {
                $this->deleteUpload($reseller->{$column});
                $attributes[$column] = $file->store(self::UPLOAD_DIRECTORY, 'public');
            }
        }

        $reseller->forceFill($attributes)->save();

        $this->alert->success('Your branding has been updated.')->flash();

        return redirect()->route('reseller.branding');
    }

    public function storeDomain(DomainFormRequest $request): RedirectResponse
    {
        $domain = new ResellerDomain();
        $domain->forceFill([
            'reseller_id' => $this->context->id(),
            'hostname' => $request->input('hostname'),
            'verification_token' => DomainVerificationService::generateToken(),
        ])->save();

        $this->alert->success('Add the TXT record shown below, then click Verify.')->flash();

        return redirect()->route('reseller.branding');
    }

    public function verifyDomain(int $domain): RedirectResponse
    {
        $model = $this->findDomain($domain);

        if ($this->verification->verify($model)) {
            $this->alert->success($model->hostname . ' has been verified.')->flash();
        } else {
            $this->alert->danger($model->refresh()->last_check_error ?? 'Verification failed.')->flash();
        }

        return redirect()->route('reseller.branding');
    }

    public function deleteDomain(int $domain): RedirectResponse
    {
        $model = $this->findDomain($domain);
        $hostname = $model->hostname;

        $model->delete();
        $this->resolver->forgetHost($hostname);

        $this->alert->success($hostname . ' has been removed.')->flash();

        return redirect()->route('reseller.branding');
    }

    /**
     * @throws NotFoundHttpException
     */
    private function findDomain(int $domain): ResellerDomain
    {
        $model = $this->context->reseller()->domains()->whereKey($domain)->first();

        if (is_null($model)) {
            throw new NotFoundHttpException();
        }

        return $model;
    }

    private function deleteUpload(?string $path): void
    {
        // Guard the prefix so a stale or hand-edited column value can never make
        // this delete something outside the branding directory.
        if ($path && str_starts_with($path, self::UPLOAD_DIRECTORY . '/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
