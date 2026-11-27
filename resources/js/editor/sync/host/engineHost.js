/**
 * The sync engine's side of the host: the callbacks it is created with
 * (engineHost()) and the presence strip it reports to (membersChanged()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { late } from './late.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createEngineHost(view, env, h) {
    const {
        metric,
        readOnly,
        resendIfOwed,
        syncState,
        applyFromSync,
        expireMerging,
        mergeOrConflict,
        offerNextPark,
        accessFromPoll,
    } = late(h, [
        'metric',
        'readOnly',
        'resendIfOwed',
        'syncState',
        'applyFromSync',
        'expireMerging',
        'mergeOrConflict',
        'offerNextPark',
        'accessFromPoll',
    ]);

    /**
     * The presence strip is rendered by Livewire; only ask it to re-render
     * when the set of people actually changed.
     *
     * @param {{id: number|string}[]} members
     */
    function membersChanged(members) {
        const key = members.map((member) => member.id).join(',');
        if (key === view.memberKey) {
            return;
        }

        const first = view.memberKey === '';
        view.memberKey = key;
        // The first report matches what the page was rendered with unless
        // somebody else is already here.
        if (first && members.length <= 1) {
            return;
        }

        env.refreshPresence();
    }

    /**
     * The callbacks the sync engine is created with (its `host` option).
     * The engine keeps them for as long as it lives, so they stay bound to
     * the data object that started it, as the page's own handlers do.
     */
    function engineHost() {
        return {
            state: () => syncState(),
            // The role that rides on every poll (see accessFromPoll()).
            onAccess: (canEdit) => accessFromPoll(canEdit),
            applyRemote: (remote) => applyFromSync(remote),
            // A newer document while this tab holds unsaved typing: merged,
            // or the notice when that cannot be done.
            onConflict: (remote) => mergeOrConflict(remote),
            onRefused: () => {
                view.syncNotice = 'A newer version could not be opened here. Reload the page.';
            },
            // Every answered poll is also the moment to send a save that
            // never answered.
            onMembers: (members) => {
                // Who else is here, for the counters: the people other than this one (the list holds every
                // distinct person with the document open, this one included).
                metric('peers', { others: Math.max(0, members.length - 1) });
                membersChanged(members);
                resendIfOwed();
            },
            // And so is every poll that completed without an answer from
            // the application (a firewall's 403, a 5xx, no network): saving
            // must not depend on the follow loop being healthy. On an
            // answered poll this is the second call; by then the save is in
            // the air or nothing is owed, so it does nothing.
            // A fetch for a merge that has not answered in fifteen seconds ends
            // in the notice (the owed save goes out after the writer's choice).
            // Judged HERE and not in resendIfOwed(): the tick comes after the
            // poll has delivered its document, so a poll that brings the awaited
            // document late is merged, not answered with the notice.
            onPolled: () => {
                expireMerging();
                resendIfOwed();
                // An offer of a kept park that was held back by a conflict, a merge or a stop reason.
                if (view.parkOfferPending) {
                    offerNextPark();
                }
            },
            onStopped: (reason) => {
                view.syncNotice =
                    {
                        'signed-out': readOnly(env.handle())
                            ? 'You have been signed out. Reload the page to sign in again.'
                            : 'You have been signed out. Reload the page to keep editing.',
                        forbidden: 'You no longer have access to this document.',
                        gone: 'This document no longer exists.',
                    }[reason] || 'This page has stopped updating. Reload it.';
                // Nothing is waiting to be saved on a read only page, so it
                // does not claim a save failed.
                env.report('danger', readOnly(env.handle()) ? 'Not updating' : 'Not saved');
            },
        };
    }

    return { membersChanged, engineHost };
}
