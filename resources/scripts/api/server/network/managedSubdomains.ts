import http, { FractalResponseData } from '@/api/http';

export interface ManagedDomainOption {
    uuid: string;
    name: string;
    domain: string;
    description: string | null;
}

export interface SubdomainPolicy {
    enabled: boolean;
    canView: boolean;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canRepair: boolean;
    limit: number;
    used: number;
    remaining: number;
    policySource: string;
    serviceProfile: {
        uuid: string;
        name: string;
        protocol: string;
        defaultPort: number | null;
        supportsDirectDns: boolean;
        supportsSrv: boolean;
    } | null;
    eligibleDomains: ManagedDomainOption[];
    disabledReason: string | null;
    disabledReasonCode: string | null;
    warnings: string[];
}

export interface NetworkOverview {
    policy: SubdomainPolicy;
    primaryAllocation: { id: number; ip: string; alias: string | null; port: number } | null;
    allocationCount: number;
    hostnameCount: number | null;
    attentionCount: number | null;
    dnsHealth: string | null;
    activeGameSlot: string | null;
    reverseProxyAvailable: boolean;
}

export interface DnsRecordPreview {
    records: Array<Record<string, string | number | boolean>>;
    connectionAddress: string;
    portDiscoverable: boolean;
    explanation: string;
    warnings: string[];
}

export interface ManagedSubdomainPreview {
    label: string;
    fqdn: string;
    allocation: { id: number; ip: string; port: number; display: string };
    publicTarget: { type: string; value: string; source: string };
    serviceProfile: { id: number; name: string; detectionSource: string; supportsSrv: boolean };
    recordPlan: DnsRecordPreview;
}

export interface ManagedSubdomain {
    uuid: string;
    fqdn: string;
    label: string;
    routingMode: 'direct_dns' | 'reverse_proxy';
    status: string;
    allocation: { id: number; ip: string; alias: string | null; port: number } | null;
    domain: { uuid: string; name: string; domain: string };
    detectedService: string;
    serviceDetectionSource: string;
    connectionAddress: string;
    publicTarget: { type: string; value: string };
    targetPort: number;
    recordPlan: DnsRecordPreview;
    recordCount: number;
    lastSynchronizedAt: string | null;
    lastErrorCode: string | null;
    errorMessage: string | null;
    driftDetectedAt: string | null;
    createdAt: string;
    updatedAt: string;
}

const policy = (data: any): SubdomainPolicy => ({
    enabled: data.enabled,
    canView: data.can_view,
    canCreate: data.can_create,
    canUpdate: data.can_update,
    canDelete: data.can_delete,
    canRepair: data.can_repair,
    limit: data.limit,
    used: data.used,
    remaining: data.remaining,
    policySource: data.policy_source,
    serviceProfile: data.service_profile
        ? {
              uuid: data.service_profile.uuid,
              name: data.service_profile.name,
              protocol: data.service_profile.protocol,
              defaultPort: data.service_profile.default_port,
              supportsDirectDns: data.service_profile.supports_direct_dns,
              supportsSrv: data.service_profile.supports_srv,
          }
        : null,
    eligibleDomains: data.eligible_domains || [],
    disabledReason: data.disabled_reason,
    disabledReasonCode: data.disabled_reason_code,
    warnings: data.warnings || [],
});

const subdomain = ({ attributes: data }: FractalResponseData): ManagedSubdomain => ({
    uuid: data.uuid,
    fqdn: data.fqdn,
    label: data.label,
    routingMode: data.routing_mode,
    status: data.status,
    allocation: data.allocation,
    domain: data.domain,
    detectedService: data.detected_service,
    serviceDetectionSource: data.service_detection_source,
    connectionAddress: data.connection_address,
    publicTarget: data.public_target,
    targetPort: data.target_port,
    recordPlan: {
        records: data.record_plan.records,
        connectionAddress: data.record_plan.connection_address,
        portDiscoverable: data.record_plan.port_discoverable,
        explanation: data.record_plan.explanation,
        warnings: data.record_plan.warnings || [],
    },
    recordCount: data.record_count,
    lastSynchronizedAt: data.last_synchronized_at,
    lastErrorCode: data.last_error_code,
    errorMessage: data.error_message,
    driftDetectedAt: data.drift_detected_at,
    createdAt: data.created_at,
    updatedAt: data.updated_at,
});

export const getNetworkOverview = async (server: string): Promise<NetworkOverview> => {
    const { data } = await http.get(`/api/client/servers/${server}/network/overview`);

    return {
        policy: policy(data.policy),
        primaryAllocation: data.primary_allocation,
        allocationCount: data.allocation_count,
        hostnameCount: data.hostname_count,
        attentionCount: data.attention_count,
        dnsHealth: data.dns_health,
        activeGameSlot: data.active_game_slot,
        reverseProxyAvailable: data.reverse_proxy_available,
    };
};

export const getManagedSubdomains = async (server: string): Promise<ManagedSubdomain[]> => {
    const { data } = await http.get(`/api/client/servers/${server}/network/subdomains`);

    return (data.data || []).map(subdomain);
};

export const previewManagedSubdomain = async (
    server: string,
    values: { label: string; domainUuid: string; allocationId: number }
): Promise<ManagedSubdomainPreview> => {
    const { data } = await http.post(`/api/client/servers/${server}/network/subdomains/preview`, {
        label: values.label,
        domain_uuid: values.domainUuid,
        allocation_id: values.allocationId,
    });

    return {
        label: data.label,
        fqdn: data.fqdn,
        allocation: data.allocation,
        publicTarget: data.public_target,
        serviceProfile: {
            id: data.service_profile.id,
            name: data.service_profile.name,
            detectionSource: data.service_profile.detection_source,
            supportsSrv: data.service_profile.supports_srv,
        },
        recordPlan: {
            records: data.record_plan.records,
            connectionAddress: data.record_plan.connection_address,
            portDiscoverable: data.record_plan.port_discoverable,
            explanation: data.record_plan.explanation,
            warnings: data.record_plan.warnings || [],
        },
    };
};

export const createManagedSubdomain = async (
    server: string,
    values: { label: string; domainUuid: string; allocationId: number }
): Promise<ManagedSubdomain> => {
    const { data } = await http.post(`/api/client/servers/${server}/network/subdomains`, {
        label: values.label,
        domain_uuid: values.domainUuid,
        allocation_id: values.allocationId,
    });

    return subdomain(data);
};

export const deleteManagedSubdomain = (server: string, uuid: string) =>
    http.delete(`/api/client/servers/${server}/network/subdomains/${uuid}`);

export const repairManagedSubdomain = (server: string, uuid: string) =>
    http.post(`/api/client/servers/${server}/network/subdomains/${uuid}/repair`);

export const refreshManagedSubdomain = (server: string, uuid: string) =>
    http.post(`/api/client/servers/${server}/network/subdomains/${uuid}/refresh`);

export const reassignManagedSubdomain = (server: string, uuid: string, allocationId: number) =>
    http.patch(`/api/client/servers/${server}/network/subdomains/${uuid}/allocation`, {
        allocation_id: allocationId,
    });

export const updateManagedSubdomain = (server: string, uuid: string, label: string) =>
    http.patch(`/api/client/servers/${server}/network/subdomains/${uuid}`, { label });
