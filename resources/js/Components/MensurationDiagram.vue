<script setup>
/**
 * Lightweight SVG diagrams for mensuration match stories.
 */
defineProps({
    diagram: { type: String, default: 'circle_walk' },
});
</script>

<template>
    <svg viewBox="0 0 220 160" class="mx-auto h-36 w-full max-w-xs" role="img">
        <!-- Circle walk / area -->
        <template v-if="diagram === 'circle_walk' || diagram === 'circle_area'">
            <circle cx="110" cy="80" r="48" fill="#eff6ff" stroke="#1e3a8a" stroke-width="3" />
            <circle cx="110" cy="80" r="3" fill="#1e3a8a" />
            <line x1="110" y1="80" x2="158" y2="80" stroke="#1e3a8a" stroke-width="2" />
            <text x="130" y="74" font-size="14" fill="#1e3a8a" font-weight="700">r</text>
            <circle v-if="diagram === 'circle_walk'" cx="110" cy="32" r="5" fill="#b45309" />
            <path v-if="diagram === 'circle_walk'" d="M110 32 A48 48 0 0 1 158 80" fill="none" stroke="#ea580c" stroke-width="2" stroke-dasharray="4 3" marker-end="url(#arrow)" />
            <rect v-if="diagram === 'circle_area'" x="70" y="48" width="80" height="64" fill="#93c5fd" opacity="0.35" />
        </template>

        <!-- Rectangle -->
        <template v-else-if="diagram === 'rectangle_walk' || diagram === 'rectangle_area'">
            <rect x="40" y="40" width="140" height="80" fill="#ecfdf5" stroke="#065f46" stroke-width="3" />
            <text x="100" y="36" font-size="13" fill="#065f46" font-weight="700">l</text>
            <text x="188" y="84" font-size="13" fill="#065f46" font-weight="700">b</text>
            <text x="48" y="56" font-size="12" fill="#065f46" font-weight="700">A</text>
            <circle v-if="diagram === 'rectangle_walk'" cx="40" cy="40" r="4" fill="#b45309" />
            <path v-if="diagram === 'rectangle_walk'" d="M40 40 H180 V120 H40 Z" fill="none" stroke="#ea580c" stroke-width="2" stroke-dasharray="4 3" />
            <rect v-if="diagram === 'rectangle_area'" x="40" y="40" width="140" height="80" fill="#6ee7b7" opacity="0.35" />
        </template>

        <!-- Triangle -->
        <template v-else-if="diagram === 'triangle_walk' || diagram === 'triangle_area'">
            <polygon points="110,30 40,130 180,130" fill="#fff7ed" stroke="#9a3412" stroke-width="3" />
            <text x="60" y="90" font-size="12" fill="#9a3412" font-weight="700">a</text>
            <text x="150" y="90" font-size="12" fill="#9a3412" font-weight="700">b</text>
            <text x="100" y="145" font-size="12" fill="#9a3412" font-weight="700">c</text>
            <line v-if="diagram === 'triangle_area'" x1="110" y1="30" x2="110" y2="130" stroke="#ea580c" stroke-width="2" stroke-dasharray="3 2" />
            <text v-if="diagram === 'triangle_area'" x="116" y="90" font-size="12" fill="#ea580c" font-weight="700">h</text>
            <text v-if="diagram === 'triangle_area'" x="100" y="145" font-size="12" fill="#9a3412" font-weight="700">b</text>
        </template>

        <!-- Parallelogram -->
        <template v-else-if="diagram === 'parallelogram_walk' || diagram === 'parallelogram_area'">
            <polygon points="55,120 30,50 165,50 190,120" fill="#f5f3ff" stroke="#5b21b6" stroke-width="3" />
            <text x="90" y="44" font-size="12" fill="#5b21b6" font-weight="700">a</text>
            <text x="175" y="90" font-size="12" fill="#5b21b6" font-weight="700">b</text>
            <line v-if="diagram === 'parallelogram_area'" x1="165" y1="50" x2="165" y2="120" stroke="#ea580c" stroke-width="2" stroke-dasharray="3 2" />
            <text v-if="diagram === 'parallelogram_area'" x="170" y="90" font-size="12" fill="#ea580c" font-weight="700">h</text>
        </template>

        <!-- Sphere -->
        <template v-else-if="diagram === 'sphere_surface' || diagram === 'sphere_fill'">
            <ellipse cx="110" cy="80" rx="50" ry="50" fill="#e0e7ff" stroke="#3730a3" stroke-width="3" />
            <ellipse cx="110" cy="80" rx="50" ry="18" fill="none" stroke="#6366f1" stroke-width="1.5" />
            <line x1="110" y1="80" x2="160" y2="80" stroke="#3730a3" stroke-width="2" />
            <text x="130" y="74" font-size="13" fill="#3730a3" font-weight="700">r</text>
        </template>

        <!-- Cylinder -->
        <template v-else-if="diagram === 'cylinder_fill'">
            <ellipse cx="110" cy="40" rx="40" ry="14" fill="#cffafe" stroke="#0e7490" stroke-width="2" />
            <path d="M70 40 V120 A40 14 0 0 0 150 120 V40" fill="#a5f3fc" stroke="#0e7490" stroke-width="2" />
            <ellipse cx="110" cy="120" rx="40" ry="14" fill="#67e8f9" stroke="#0e7490" stroke-width="2" />
            <line x1="150" y1="40" x2="150" y2="120" stroke="#ea580c" stroke-width="2" stroke-dasharray="3 2" />
            <text x="156" y="84" font-size="13" fill="#ea580c" font-weight="700">h</text>
            <line x1="110" y1="120" x2="150" y2="120" stroke="#0e7490" stroke-width="2" />
            <text x="122" y="136" font-size="13" fill="#0e7490" font-weight="700">r</text>
        </template>

        <!-- Cube -->
        <template v-else-if="diagram === 'cube_fill'">
            <path d="M60 50 L120 30 L180 50 L180 110 L120 130 L60 110 Z" fill="#fef3c7" stroke="#92400e" stroke-width="2" />
            <path d="M60 50 L120 70 L180 50 M120 70 L120 130" fill="none" stroke="#92400e" stroke-width="2" />
            <text x="130" y="100" font-size="14" fill="#92400e" font-weight="700">a</text>
        </template>

        <!-- Cuboid -->
        <template v-else-if="diagram === 'cuboid_fill'">
            <path d="M40 60 L140 40 L200 60 L200 120 L140 140 L40 120 Z" fill="#dbeafe" stroke="#1e40af" stroke-width="2" />
            <path d="M40 60 L140 80 L200 60 M140 80 L140 140" fill="none" stroke="#1e40af" stroke-width="2" />
            <text x="90" y="55" font-size="12" fill="#1e40af" font-weight="700">l</text>
            <text x="170" y="95" font-size="12" fill="#1e40af" font-weight="700">b</text>
            <text x="145" y="120" font-size="12" fill="#1e40af" font-weight="700">h</text>
        </template>

        <template v-else>
            <rect x="30" y="30" width="160" height="100" rx="8" fill="#f1f5f9" stroke="#64748b" stroke-width="2" />
            <text x="110" y="88" text-anchor="middle" font-size="14" fill="#475569">Figure</text>
        </template>
    </svg>
</template>
