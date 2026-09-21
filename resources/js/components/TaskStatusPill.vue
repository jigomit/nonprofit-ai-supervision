<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    status: string;
    label: string;
    /** Released, but without a credentialed sign-off. */
    flagged?: boolean;
}>();

const tone = computed(() => {
    if (props.flagged) {
        return 'border-rose-600/30 bg-rose-500/10 text-rose-700 dark:border-rose-400/25 dark:text-rose-300';
    }

    switch (props.status) {
        case 'released':
            return 'border-emerald-600/25 bg-emerald-500/10 text-emerald-700 dark:border-emerald-400/25 dark:text-emerald-300';
        case 'awaiting_review':
        case 'awaiting_expert':
            return 'border-amber-600/25 bg-amber-500/10 text-amber-700 dark:border-amber-400/25 dark:text-amber-300';
        case 'failed':
        case 'rejected':
            return 'border-rose-600/25 bg-rose-500/10 text-rose-700 dark:border-rose-400/25 dark:text-rose-300';
        default:
            return 'border-sidebar-border/70 bg-muted text-muted-foreground dark:border-sidebar-border';
    }
});

const text = computed(() =>
    props.flagged ? 'Released without expert' : props.label,
);
</script>

<template>
    <span
        :class="[
            'inline-flex w-fit shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
            tone,
        ]"
    >
        {{ text }}
    </span>
</template>
