import http from '@/api/http';
import { getNetworkOverview, previewManagedSubdomain } from '@/api/server/network/managedSubdomains';

jest.mock('@/api/http', () => ({
    __esModule: true,
    default: {
        get: jest.fn(),
        post: jest.fn(),
        patch: jest.fn(),
        delete: jest.fn(),
    },
}));

const mockedHttp = http as jest.Mocked<typeof http>;

describe('managed subdomain API', () => {
    it('normalizes policy and overview fields', async () => {
        mockedHttp.get.mockResolvedValueOnce({
            data: {
                policy: {
                    enabled: true,
                    can_view: true,
                    can_create: false,
                    can_update: true,
                    can_delete: true,
                    can_repair: true,
                    limit: 2,
                    used: 2,
                    remaining: 0,
                    policy_source: 'server_override',
                    service_profile: null,
                    eligible_domains: [],
                    disabled_reason: 'This server is using all 2 of its available managed subdomains.',
                    disabled_reason_code: 'limit_reached',
                    warnings: [],
                },
                primary_allocation: { id: 1, ip: '1.1.1.1', alias: null, port: 27015 },
                allocation_count: 2,
                hostname_count: 2,
                attention_count: 0,
                dns_health: 'healthy',
                active_game_slot: null,
                reverse_proxy_available: false,
            },
        } as never);

        const overview = await getNetworkOverview('server-id');

        expect(overview.policy.canCreate).toBe(false);
        expect(overview.policy.disabledReasonCode).toBe('limit_reached');
        expect(overview.primaryAllocation?.port).toBe(27015);
    });

    it('preserves the honest generic hostname and port preview', async () => {
        mockedHttp.post.mockResolvedValueOnce({
            data: {
                label: 'game',
                fqdn: 'game.example.com',
                allocation: { id: 1, ip: '1.1.1.1', port: 27015, display: '1.1.1.1:27015' },
                public_target: { type: 'A', value: '1.1.1.1', source: 'allocation_ip' },
                service_profile: {
                    id: 2,
                    name: 'Generic TCP service',
                    detection_source: 'egg_mapping',
                    supports_srv: false,
                },
                record_plan: {
                    records: [{ type: 'A', name: 'game.example.com', content: '1.1.1.1' }],
                    connection_address: 'game.example.com:27015',
                    port_discoverable: false,
                    explanation: 'DNS resolves the host, but players must include the port.',
                    warnings: ['Players must include the selected port when connecting.'],
                },
            },
        } as never);

        const preview = await previewManagedSubdomain('server-id', {
            label: 'game',
            domainUuid: 'domain-id',
            allocationId: 1,
        });

        expect(preview.recordPlan.portDiscoverable).toBe(false);
        expect(preview.recordPlan.connectionAddress).toBe('game.example.com:27015');
        expect(mockedHttp.post).toHaveBeenCalledWith(
            '/api/client/servers/server-id/network/subdomains/preview',
            expect.objectContaining({ domain_uuid: 'domain-id', allocation_id: 1 })
        );
    });
});
