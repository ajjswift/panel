<?php

namespace Pterodactyl\Services\Resellers;

use Illuminate\Support\Str;
use Pterodactyl\Models\ResellerDomain;

/**
 * Proves a reseller controls a hostname before its branding is served on it.
 *
 * The panel deliberately owns identity only: pointing the hostname at the panel
 * and terminating TLS for it is done outside (wildcard certificate, a proxy, or
 * an nginx server_name). This check answers "may this host claim that identity",
 * nothing else.
 */
class DomainVerificationService
{
    public function __construct(private ResellerBrandingResolver $resolver)
    {
    }

    public static function generateToken(): string
    {
        return 'solstice-verify-' . Str::random(32);
    }

    /**
     * Look for the expected TXT record. Returns true and stamps `verified_at`
     * on success; records the failure reason otherwise.
     */
    public function verify(ResellerDomain $domain): bool
    {
        $found = $this->lookupTxtRecords($domain->verificationRecordName());
        $matched = in_array($domain->verification_token, $found, true);

        $domain->forceFill([
            'last_checked_at' => now(),
            'verified_at' => $matched ? ($domain->verified_at ?? now()) : null,
            'last_check_error' => $matched
                ? null
                : ($found === []
                    ? 'No TXT record found at ' . $domain->verificationRecordName()
                    : 'A TXT record exists but does not match the expected token.'),
        ])->save();

        // The resolver caches host => reseller, including negative results, so
        // a newly verified domain has to invalidate its own entry.
        $this->resolver->forgetHost($domain->hostname);

        return $matched;
    }

    /**
     * @return array<int, string>
     */
    private function lookupTxtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return collect($records)
            ->pluck('txt')
            ->filter()
            ->map(fn ($value) => trim((string) $value, '"'))
            ->values()
            ->all();
    }
}
