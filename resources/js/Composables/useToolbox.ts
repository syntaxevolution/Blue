import { reactive, readonly } from 'vue';

/**
 * Shared toolbox state — placement mode and a tiny event bus that lets
 * the floating ToolboxDock tell Map.vue "I want to plant item X".
 *
 * Why a module-level singleton instead of Pinia:
 *   - The project deliberately avoids Pinia for anything that isn't
 *     ephemeral UI state, and even then very sparingly (per CLAUDE.md).
 *   - The toolbox is UI state only. Persistent truth lives in the
 *     page props Inertia ships on every /map render.
 *   - A module ref is 15 lines, has no store boilerplate, and is
 *     reactive for free.
 *
 * The placement mode is intentionally global: opening the Toolbox on
 * any page other than the map and clicking "Place" still flips the
 * mode. Map.vue owns the side effect of listening for the flag and
 * turning clicks on the drill grid into plant actions — other pages
 * show a helper banner telling the player to go to an oil field.
 */

interface ToolboxState {
    placementActive: boolean;
    placementDeviceKey: string | null;
    placementDeviceName: string | null;
    /**
     * Bumped every time the user clicks "Place" in the dock, even if
     * the device is the same as last time. Components can watch this
     * to re-trigger enter-placement side effects when a user re-enters
     * placement mode after a cancellation without toggling the
     * deviceKey ref.
     */
    placementCounter: number;
}

const state = reactive<ToolboxState>({
    placementActive: false,
    placementDeviceKey: null,
    placementDeviceName: null,
    placementCounter: 0,
});

export function enterPlacementMode(deviceKey: string, deviceName: string): void {
    state.placementActive = true;
    state.placementDeviceKey = deviceKey;
    state.placementDeviceName = deviceName;
    state.placementCounter += 1;
}

export function exitPlacementMode(): void {
    state.placementActive = false;
    state.placementDeviceKey = null;
    state.placementDeviceName = null;
}

export function useToolbox() {
    return {
        state: readonly(state),
        enter: enterPlacementMode,
        exit: exitPlacementMode,
    };
}
