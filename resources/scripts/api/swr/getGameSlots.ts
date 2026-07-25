import useSWR from 'swr';
import { getGameSlots } from '@/api/server/gameSlots';
import { GameSlotOverview } from '@/api/server/gameSlots/types';
import { ServerContext } from '@/state/server';

/**
 * Loads the game-slot overview for the current server. When a switch operation
 * is active the data refreshes on an interval so progress updates live; the
 * backend is the source of truth, so leaving and returning to the page always
 * resumes correctly.
 */
// Poll only while a switch is actually in progress; the caller flips `poll` off
// once the operation reaches a terminal state so a completed or failed switch is
// always reflected without hammering the API.
export default (poll = false) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    return useSWR<GameSlotOverview>(['server:game-slots', uuid], () => getGameSlots(uuid), {
        refreshInterval: poll ? 2500 : 0,
    });
};
