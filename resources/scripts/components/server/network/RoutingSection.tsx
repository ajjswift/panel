import React from 'react';
import tw from 'twin.macro';

const RoutingSection = () => (
    <section aria-labelledby={'network-routing-title'}>
        <h2 id={'network-routing-title'} css={tw`text-xl text-neutral-100 font-semibold`}>
            Routing
        </h2>
        <div css={tw`mt-5 rounded-lg border border-neutral-600 bg-neutral-700 p-5`}>
            <p css={tw`text-neutral-100 font-medium`}>Direct DNS is available</p>
            <p css={tw`text-sm text-neutral-300 mt-2`}>
                A, AAAA, CNAME, and supported SRV records can be managed from the Subdomains section. DNS does not
                forward arbitrary traffic to a selected port.
            </p>
        </div>
        <div css={tw`mt-4 rounded-lg border border-dashed border-neutral-500 p-5`}>
            <p css={tw`text-neutral-200 font-medium`}>Reverse proxy — not configured</p>
            <p css={tw`text-sm text-neutral-400 mt-2`}>
                The data model reserves a future reverse-proxy routing mode, but no gateway or proxy is installed or
                changed by this feature.
            </p>
        </div>
    </section>
);

export default RoutingSection;
