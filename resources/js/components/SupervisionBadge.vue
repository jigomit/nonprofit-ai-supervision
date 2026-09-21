<script setup lang="ts">
import { computed } from 'vue';

/**
 * The supervision level is the one thing on a skill card that has to read at a
 * glance, so it carries its own colour rather than reusing the badge variants.
 */
const props = withDefaults(
    defineProps<{
        level: string;
        label: string;
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

const tone = computed(() => {
    switch (props.level) {
        case 'unsupervised':
            return 'border-emerald-600/25 bg-emerald-500/10 text-emerald-700 dark:border-emerald-400/25 dark:text-emerald-300';
        case 'expert-required':
            return 'border-rose-600/25 bg-rose-500/10 text-rose-700 dark:border-rose-400/25 dark:text-rose-300';
        default:
            return 'border-amber-600/25 bg-amber-500/10 text-amber-700 dark:border-amber-400/25 dark:text-amber-300';
    }
});

const sizing = computed(() =>
    props.size === 'md' ? 'px-2.5 py-1 text-xs' : 'px-2 py-0.5 text-[11px]',
);
</script>

<template>
    <span
        :class="[
            'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full border font-medium whitespace-nowrap',
            tone,
            sizing,
        ]"
    >
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ label }}
    </span>
</template>
