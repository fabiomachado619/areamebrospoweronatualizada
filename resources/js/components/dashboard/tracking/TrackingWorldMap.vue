<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import World from '@svg-maps/world';
import { formatBRL } from '@/composables/useTrackingPanel';

const props = defineProps({
    countries: { type: Array, default: () => [] },
    highlightCode: { type: String, default: null },
});

const FULL = { x: 0, y: 0, w: 1010, h: 666 };
const SPREAD_THRESHOLD = 320;
const MIN_ZOOM = { w: 140, h: 95 };

const page = usePage();
const mapRef = ref(null);
const svgRef = ref(null);
const hovered = ref(null);
const tooltipPos = ref({ x: 0, y: 0 });
const viewBoxState = ref({ ...FULL });
const isPanning = ref(false);
const panStart = ref({ clientX: 0, clientY: 0, vbX: 0, vbY: 0 });

const isDark = computed(() =>
    typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
);

const primaryColor = computed(() => page.props.appSettings?.theme_primary || '#74d909');

const isSales = computed(() => props.countries.some((c) => Number(c.total) > 0));

const viewBoxString = computed(
    () => `${viewBoxState.value.x} ${viewBoxState.value.y} ${viewBoxState.value.w} ${viewBoxState.value.h}`
);

const strokeScale = computed(() => Math.max(0.35, viewBoxState.value.w / FULL.w));

/** @type {import('vue').ComputedRef<Record<string, object>>} */
const dataByCode = computed(() => {
    const map = {};
    for (const c of props.countries) {
        if (!c.country_code) continue;
        map[String(c.country_code).toLowerCase()] = c;
    }
    return map;
});

const activeCount = computed(() => Object.keys(dataByCode.value).length);

function resolveFocusCodes() {
    const codes = Object.keys(dataByCode.value);
    if (!codes.length) return [];

    const boxes = codes
        .map((code) => ({ code, box: getPathBox(code) }))
        .filter((b) => b.box);

    if (!boxes.length) return codes;

    let minX = Infinity;
    let maxX = -Infinity;
    for (const { box } of boxes) {
        minX = Math.min(minX, box.x);
        maxX = Math.max(maxX, box.x + box.width);
    }

    const spread = maxX - minX;
    if (spread > SPREAD_THRESHOLD) {
        const leader = normCode(props.highlightCode);
        if (leader && dataByCode.value[leader]) {
            return [leader];
        }
        const top = [...props.countries].sort(
            (a, b) => (b.percent ?? 0) - (a.percent ?? 0) || (b.count ?? 0) - (a.count ?? 0)
        )[0];
        if (top?.country_code) {
            return [String(top.country_code).toLowerCase()];
        }
    }

    return codes;
}

function normCode(code) {
    return code ? String(code).toLowerCase() : '';
}

function countryData(locationId) {
    return dataByCode.value[locationId] ?? null;
}

function isLeader(locationId) {
    if (!props.highlightCode) return false;
    return normCode(props.highlightCode) === locationId;
}

function getPathBox(code) {
    const svg = svgRef.value;
    if (!svg) return null;
    const el = svg.querySelector(`[data-country="${code}"]`);
    if (!el) return null;
    try {
        const b = el.getBBox();
        if (!b.width && !b.height) return null;
        return b;
    } catch {
        return null;
    }
}

function clampViewBox(vb) {
    let { x, y, w, h } = vb;
    w = Math.max(MIN_ZOOM.w, Math.min(w, FULL.w));
    h = Math.max(MIN_ZOOM.h, Math.min(h, FULL.h));
    x = Math.max(FULL.x, Math.min(x, FULL.x + FULL.w - w));
    y = Math.max(FULL.y, Math.min(y, FULL.y + FULL.h - h));
    return { x, y, w, h };
}

function fitToActiveCountries() {
    const codes = resolveFocusCodes();
    if (!codes.length) {
        viewBoxState.value = { ...FULL };
        return;
    }

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    let found = 0;

    for (const code of codes) {
        const box = getPathBox(code);
        if (!box) continue;
        minX = Math.min(minX, box.x);
        minY = Math.min(minY, box.y);
        maxX = Math.max(maxX, box.x + box.width);
        maxY = Math.max(maxY, box.y + box.height);
        found++;
    }

    if (!found) {
        viewBoxState.value = { ...FULL };
        return;
    }

    let w = maxX - minX;
    let h = maxY - minY;
    const cx = (minX + maxX) / 2;
    const cy = (minY + maxY) / 2;
    const pad = codes.length === 1 ? 0.55 : 0.4;
    w *= 1 + pad;
    h *= 1 + pad;

    const el = mapRef.value;
    const aspect = el && el.clientHeight > 0 ? el.clientWidth / el.clientHeight : FULL.w / FULL.h;
    const bboxAspect = w / h;
    if (bboxAspect < aspect) {
        w = h * aspect;
    } else {
        h = w / aspect;
    }

    viewBoxState.value = clampViewBox({
        x: cx - w / 2,
        y: cy - h / 2,
        w,
        h,
    });
}

function fillFor(locationId) {
    const data = countryData(locationId);
    if (!data) {
        return isDark.value ? '#3f3f46' : '#e4e4e7';
    }
    if (isLeader(locationId)) {
        return primaryColor.value;
    }
    return primaryColor.value;
}

function fillOpacityFor(locationId) {
    const data = countryData(locationId);
    if (!data) return 1;
    if (isLeader(locationId)) return 1;
    const pct = Math.min(100, data.percent ?? 0);
    return 0.45 + (pct / 100) * 0.55;
}

function strokeFor(locationId) {
    const data = countryData(locationId);
    if (data) return isLeader(locationId) ? primaryColor.value : `${primaryColor.value}88`;
    return isDark.value ? '#52525b' : '#a1a1aa';
}

function pathClass(locationId) {
    const data = countryData(locationId);
    return data ? 'cursor-pointer transition-[fill,fill-opacity] duration-150' : 'pointer-events-none';
}

function updateTooltipPos(evt) {
    const el = mapRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const x = evt.clientX - rect.left;
    const y = evt.clientY - rect.top;
    tooltipPos.value = {
        x: Math.min(Math.max(x, 72), rect.width - 72),
        y: Math.max(y - 12, 40),
    };
}

function onPathEnter(location, evt) {
    if (isPanning.value) return;
    const data = countryData(location.id);
    if (!data) {
        hovered.value = null;
        return;
    }
    hovered.value = {
        name: data.country_name || location.name,
        count: data.count ?? 0,
        percent: data.percent ?? 0,
        total: data.total ?? 0,
    };
    updateTooltipPos(evt);
}

function onPathMove(evt) {
    if (isPanning.value) return;
    if (hovered.value) updateTooltipPos(evt);
}

function onPathLeave() {
    if (!isPanning.value) hovered.value = null;
}

function onMapPointerDown(evt) {
    if (evt.button !== 0) return;
    isPanning.value = true;
    hovered.value = null;
    panStart.value = {
        clientX: evt.clientX,
        clientY: evt.clientY,
        vbX: viewBoxState.value.x,
        vbY: viewBoxState.value.y,
    };
    mapRef.value?.setPointerCapture(evt.pointerId);
}

function onMapPointerMove(evt) {
    if (!isPanning.value) return;
    const el = mapRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const scaleX = viewBoxState.value.w / rect.width;
    const scaleY = viewBoxState.value.h / rect.height;
    const dx = evt.clientX - panStart.value.clientX;
    const dy = evt.clientY - panStart.value.clientY;
    viewBoxState.value = clampViewBox({
        ...viewBoxState.value,
        x: panStart.value.vbX - dx * scaleX,
        y: panStart.value.vbY - dy * scaleY,
    });
}

function endPan(evt) {
    if (!isPanning.value) return;
    isPanning.value = false;
    if (mapRef.value?.hasPointerCapture(evt.pointerId)) {
        mapRef.value.releasePointerCapture(evt.pointerId);
    }
}

let resizeObserver = null;

async function refreshFit() {
    await nextTick();
    fitToActiveCountries();
}

watch(
    () => [props.countries, props.highlightCode],
    () => refreshFit(),
    { deep: true }
);

onMounted(() => {
    refreshFit();
    resizeObserver = new ResizeObserver(() => refreshFit());
    if (mapRef.value) resizeObserver.observe(mapRef.value);
});

onUnmounted(() => {
    resizeObserver?.disconnect();
});
</script>

<template>
    <div
        ref="mapRef"
        class="relative h-full w-full touch-none select-none"
        :class="isPanning ? 'cursor-grabbing' : 'cursor-grab'"
        @pointerdown="onMapPointerDown"
        @pointermove="onMapPointerMove"
        @pointerup="endPan"
        @pointercancel="endPan"
    >
        <svg
            ref="svgRef"
            :viewBox="viewBoxString"
            class="block h-full w-full"
            preserveAspectRatio="xMidYMid meet"
            role="img"
            aria-label="Mapa mundial de vendas por país"
        >
            <path
                v-for="location in World.locations"
                :key="location.id"
                :data-country="location.id"
                :d="location.path"
                :fill="fillFor(location.id)"
                :fill-opacity="fillOpacityFor(location.id)"
                :stroke="strokeFor(location.id)"
                :stroke-width="0.9 * strokeScale"
                stroke-linejoin="round"
                :class="pathClass(location.id)"
                @mouseenter="onPathEnter(location, $event)"
                @mousemove="onPathMove"
                @mouseleave="onPathLeave"
            />
        </svg>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="hovered && !isPanning"
                class="pointer-events-none absolute z-20 min-w-[120px] -translate-x-1/2 -translate-y-full rounded-lg border border-zinc-700/80 bg-zinc-900/95 px-3 py-2 text-center shadow-lg backdrop-blur-sm"
                :style="{ left: `${tooltipPos.x}px`, top: `${tooltipPos.y}px` }"
            >
                <p class="text-xs font-semibold text-white">{{ hovered.name }}</p>
                <p class="mt-0.5 text-[11px] text-zinc-300">
                    {{ hovered.count }}
                    {{ isSales ? (hovered.count === 1 ? 'venda' : 'vendas') : (hovered.count === 1 ? 'visita' : 'visitas') }}
                </p>
                <p class="text-[11px] font-medium" :style="{ color: primaryColor }">
                    {{ hovered.percent }}%
                    <span v-if="isSales && hovered.total > 0" class="text-zinc-400">
                        · {{ formatBRL(hovered.total) }}
                    </span>
                </p>
            </div>
        </Transition>

        <p
            class="pointer-events-none absolute bottom-0 left-0 right-0 pb-0.5 text-center text-[10px] text-zinc-500/90"
        >
            Arraste para explorar · passe o mouse no país
            <span v-if="activeCount"> · {{ activeCount }} país(es)</span>
        </p>
    </div>
</template>
