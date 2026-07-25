import type { ManagedSubdomain } from '@/api/server/network/managedSubdomains';

export const settingUp = ['pending', 'creating', 'updating'];

type Tone = 'ok' | 'busy' | 'attention';

// Translate a record's raw status into plain-language state a non-technical
// player understands. Reverse-proxied addresses go through extra stages
// (DNS → certificate → live) reported by the node, so they get their own
// friendlier wording. Never relies on color alone — each state has a label.
export const friendlyStatus = (record: ManagedSubdomain): { label: string; tone: Tone; hint: string } => {
    const status = record.status;

    if (record.routingMode === 'reverse_proxy' && !['deleting', 'failed', 'repair_required'].includes(status)) {
        const certificateReady = record.proxyCertStatus === 'active' || record.proxyCertStatus === 'renewing';
        if (record.proxyDnsStatus === 'ok' && certificateReady && record.proxyStatus === 'active') {
            return { label: 'Ready', tone: 'ok', hint: '' };
        }
        if (record.proxyStatus === 'failed' || record.proxyCertStatus === 'failed') {
            return { label: 'Needs attention', tone: 'attention', hint: '' };
        }
        if (record.proxyDnsStatus !== 'ok') {
            if (!record.proxyDnsStatus) {
                return { label: 'Setting up…', tone: 'busy', hint: 'This usually takes a minute or two.' };
            }
            return { label: 'Waiting for DNS…', tone: 'busy', hint: 'DNS changes can take a few minutes to spread.' };
        }
        if (!certificateReady) {
            return { label: 'Getting security certificate…', tone: 'busy', hint: 'This can take a minute or two.' };
        }
        return { label: 'Setting up…', tone: 'busy', hint: 'This usually takes a minute or two.' };
    }

    if (status === 'active') return { label: 'Ready', tone: 'ok', hint: '' };
    if (settingUp.includes(status)) {
        return { label: 'Setting up…', tone: 'busy', hint: 'This usually takes under a minute.' };
    }
    if (status === 'deleting') return { label: 'Removing…', tone: 'busy', hint: '' };
    return { label: 'Needs attention', tone: 'attention', hint: '' };
};
