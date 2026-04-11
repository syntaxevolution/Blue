<template>
    <!--
        Clash Wars logo mark — stylized "CW" monogram inside a drill-bit
        hexagon. Uses currentColor so callers control the hue via Tailwind
        text-* classes; sized by width/height classes on the wrapper.
    -->
    <svg
        viewBox="0 0 100 100"
        xmlns="http://www.w3.org/2000/svg"
        aria-label="Clash Wars"
    >
        <!-- Outer hex frame -->
        <polygon
            points="50,4 92,27 92,73 50,96 8,73 8,27"
            fill="none"
            stroke="currentColor"
            stroke-width="4"
            stroke-linejoin="round"
        />
        <!-- Inner decorative hex -->
        <polygon
            points="50,16 82,33 82,67 50,84 18,67 18,33"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linejoin="round"
            opacity="0.4"
        />
        <!-- "CC" monogram -->
        <text
            x="50"
            y="64"
            text-anchor="middle"
            font-family="'Courier New', ui-monospace, monospace"
            font-weight="900"
            font-size="38"
            letter-spacing="-2"
            fill="currentColor"
        >
            CW
        </text>
    </svg>
</template>
