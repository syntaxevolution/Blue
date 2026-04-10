<script setup lang="ts">
/*
 * Reusable tile icon — one inline SVG per tile type, styled via
 * currentColor so callers control the hue with Tailwind text-* classes.
 *
 * Size is controlled by the standard w-* / h-* classes on the wrapping
 * element. All icons share a 48×48 viewBox so they align visually.
 *
 * Tile types are the values of the tiles.type enum:
 *   base | oil_field | post | wasteland | landmark | auction | ruin
 */

defineProps<{
    type: string;
}>();
</script>

<template>
    <!-- Player base — fortified compound with battlements -->
    <svg
        v-if="type === 'base'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Base"
    >
        <!-- Outer wall with battlements -->
        <path d="M6 18 L6 14 L10 14 L10 10 L14 10 L14 14 L18 14 L18 10 L22 10 L22 14 L26 14 L26 10 L30 10 L30 14 L34 14 L34 10 L38 10 L38 14 L42 14 L42 18" />
        <path d="M6 18 L6 40 L42 40 L42 18" />
        <!-- Door -->
        <path d="M20 40 L20 28 L28 28 L28 40" />
        <!-- Windows -->
        <rect x="10" y="22" width="4" height="4" />
        <rect x="34" y="22" width="4" height="4" />
    </svg>

    <!-- Oil field — pumpjack / derrick silhouette -->
    <svg
        v-else-if="type === 'oil_field'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Oil field"
    >
        <!-- Ground line -->
        <path d="M4 40 L44 40" />
        <!-- Derrick tower (triangular frame) -->
        <path d="M16 40 L24 10 L32 40" />
        <path d="M18 32 L30 32" />
        <path d="M19 26 L29 26" />
        <path d="M20 20 L28 20" />
        <!-- Cross bracing -->
        <path d="M16 40 L28 20" opacity="0.5" />
        <path d="M32 40 L20 20" opacity="0.5" />
        <!-- Pumpjack arm -->
        <path d="M24 10 L38 14" />
        <circle cx="24" cy="10" r="1.5" fill="currentColor" />
    </svg>

    <!-- Post — trading hut with sign -->
    <svg
        v-else-if="type === 'post'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Post"
    >
        <!-- Roof -->
        <path d="M6 22 L24 10 L42 22" />
        <!-- Walls -->
        <path d="M10 22 L10 40 L38 40 L38 22" />
        <!-- Door -->
        <path d="M20 40 L20 28 L28 28 L28 40" />
        <!-- Hanging sign -->
        <path d="M38 24 L44 24" />
        <rect x="40" y="24" width="6" height="6" />
    </svg>

    <!-- Wasteland — cracked earth lines -->
    <svg
        v-else-if="type === 'wasteland'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Wasteland"
    >
        <!-- Horizon line -->
        <path d="M4 32 L44 32" />
        <!-- Crack lines -->
        <path d="M10 32 L14 38 L12 44" />
        <path d="M20 32 L22 40" />
        <path d="M28 32 L26 38 L30 44" />
        <path d="M36 32 L40 40" />
        <!-- Small rock/tumbleweed -->
        <circle cx="24" cy="24" r="3" />
        <!-- Sun/haze -->
        <circle cx="38" cy="14" r="4" opacity="0.5" />
    </svg>

    <!-- Landmark — obelisk / monolith -->
    <svg
        v-else-if="type === 'landmark'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Landmark"
    >
        <!-- Ground -->
        <path d="M4 42 L44 42" />
        <!-- Obelisk body -->
        <path d="M20 42 L20 12 L24 6 L28 12 L28 42" />
        <!-- Horizontal bands -->
        <path d="M20 18 L28 18" />
        <path d="M20 28 L28 28" />
        <path d="M20 38 L28 38" />
        <!-- Base platform -->
        <path d="M14 42 L14 38 L34 38 L34 42" />
    </svg>

    <!-- Auction — gavel and block -->
    <svg
        v-else-if="type === 'auction'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Auction house"
    >
        <!-- Gavel head -->
        <rect x="22" y="8" width="18" height="10" rx="1" transform="rotate(30 31 13)" />
        <!-- Gavel handle -->
        <path d="M18 22 L8 38" />
        <!-- Sound block -->
        <rect x="10" y="36" width="20" height="6" rx="1" />
        <!-- Impact lines -->
        <path d="M32 26 L38 22" opacity="0.5" />
        <path d="M36 30 L42 28" opacity="0.5" />
    </svg>

    <!-- Ruin — broken walls / rubble -->
    <svg
        v-else-if="type === 'ruin'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-label="Ruin"
    >
        <!-- Ground -->
        <path d="M4 40 L44 40" />
        <!-- Broken left wall -->
        <path d="M8 40 L8 20 L14 20 L14 28 L18 28 L18 16 L22 16" />
        <!-- Collapsed middle -->
        <path d="M22 16 L26 24 L30 16" opacity="0.6" />
        <!-- Broken right wall -->
        <path d="M30 16 L34 16 L34 32 L40 32 L40 40" />
        <!-- Rubble -->
        <circle cx="16" cy="38" r="1.5" fill="currentColor" />
        <circle cx="26" cy="38" r="1.5" fill="currentColor" />
        <circle cx="36" cy="38" r="1.5" fill="currentColor" />
    </svg>

    <!-- Fallback: question mark in a circle -->
    <svg
        v-else
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-label="Unknown tile"
    >
        <circle cx="24" cy="24" r="18" />
        <text
            x="24"
            y="32"
            text-anchor="middle"
            font-family="monospace"
            font-size="22"
            font-weight="bold"
            fill="currentColor"
            stroke="none"
        >
            ?
        </text>
    </svg>
</template>
