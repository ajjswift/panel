import type { ManagedSubdomain } from '@/api/server/network/managedSubdomains';
import { friendlyStatus } from '@/components/server/network/managedSubdomainStatus';

const record = (values: Partial<ManagedSubdomain>): ManagedSubdomain =>
    ({
        routingMode: 'reverse_proxy',
        status: 'active',
        proxyDnsStatus: null,
        proxyCertStatus: null,
        proxyStatus: null,
        ...values,
    } as ManagedSubdomain);

describe('managed subdomain readiness', () => {
    it('waits for DNS even when the panel DNS record and proxy are active', () => {
        expect(
            friendlyStatus(
                record({
                    proxyDnsStatus: 'pending',
                    proxyCertStatus: 'active',
                    proxyStatus: 'active',
                })
            )
        ).toMatchObject({ label: 'Waiting for DNS…', tone: 'busy' });
    });

    it('waits for the certificate after DNS is ready', () => {
        expect(
            friendlyStatus(
                record({
                    proxyDnsStatus: 'ok',
                    proxyCertStatus: 'issuing',
                    proxyStatus: 'pending',
                })
            )
        ).toMatchObject({ label: 'Getting security certificate…', tone: 'busy' });
    });

    it('is ready only when DNS, the certificate, and the proxy are active', () => {
        expect(
            friendlyStatus(
                record({
                    proxyDnsStatus: 'ok',
                    proxyCertStatus: 'active',
                    proxyStatus: 'active',
                })
            )
        ).toMatchObject({ label: 'Ready', tone: 'ok' });
    });

    it('stays ready while an existing certificate is renewing', () => {
        expect(
            friendlyStatus(
                record({
                    proxyDnsStatus: 'ok',
                    proxyCertStatus: 'renewing',
                    proxyStatus: 'active',
                })
            )
        ).toMatchObject({ label: 'Ready', tone: 'ok' });
    });
});
