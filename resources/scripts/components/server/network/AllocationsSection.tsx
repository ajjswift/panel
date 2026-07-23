import React, { useEffect, useState } from 'react';
import Spinner from '@/components/elements/Spinner';
import { useFlashKey } from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import AllocationRow from '@/components/server/network/AllocationRow';
import Button from '@/components/elements/Button';
import createServerAllocation from '@/api/server/network/createServerAllocation';
import tw from 'twin.macro';
import Can from '@/components/elements/Can';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import getServerAllocations from '@/api/swr/getServerAllocations';
import isEqual from 'react-fast-compare';
import { useDeepCompareEffect } from '@/plugins/useDeepCompareEffect';

const AllocationsSection = () => {
    const [loading, setLoading] = useState(false);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const allocationLimit = ServerContext.useStoreState((state) => state.server.data!.featureLimits.allocations);
    const allocations = ServerContext.useStoreState((state) => state.server.data!.allocations, isEqual);
    const setServerFromState = ServerContext.useStoreActions((actions) => actions.server.setServerFromState);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('server:network');
    const { data, error, mutate } = getServerAllocations();

    useEffect(() => {
        mutate(allocations);
    }, []);

    useEffect(() => {
        clearAndAddHttpError(error);
    }, [error]);

    useDeepCompareEffect(() => {
        if (data) setServerFromState((state) => ({ ...state, allocations: data }));
    }, [data]);

    const onCreateAllocation = () => {
        clearFlashes();
        setLoading(true);
        createServerAllocation(uuid)
            .then((allocation) => {
                setServerFromState((state) => ({ ...state, allocations: state.allocations.concat(allocation) }));
                return mutate((data || []).concat(allocation), false);
            })
            .catch(clearAndAddHttpError)
            .then(() => setLoading(false));
    };

    if (!data) return <Spinner size={'large'} centered />;

    return (
        <section aria-labelledby={'network-allocations-title'}>
            <div css={tw`mb-5`}>
                <h2 id={'network-allocations-title'} css={tw`text-xl text-neutral-100 font-semibold`}>
                    Allocations and ports
                </h2>
                <p css={tw`text-sm text-neutral-300 mt-1`}>
                    Addresses assigned to this server. Managed hostnames never create, reserve, or remove allocations.
                </p>
            </div>
            {data.map((allocation) => (
                <AllocationRow key={`${allocation.ip}:${allocation.port}`} allocation={allocation} />
            ))}
            {allocationLimit > 0 && (
                <Can action={'allocation.create'}>
                    <SpinnerOverlay visible={loading} />
                    <div css={tw`mt-6 sm:flex items-center justify-end`}>
                        <p css={tw`text-sm text-neutral-300 mb-4 sm:mr-6 sm:mb-0`}>
                            You are using {data.length} of {allocationLimit} allowed allocations.
                        </p>
                        {allocationLimit > data.length && (
                            <Button css={tw`w-full sm:w-auto`} onClick={onCreateAllocation}>
                                Create Allocation
                            </Button>
                        )}
                    </div>
                </Can>
            )}
        </section>
    );
};

export default AllocationsSection;
