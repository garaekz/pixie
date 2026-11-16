<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
defineOptions({ inheritAttrs: false });
defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <svg
        viewBox="0 0 40 40"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            d="M20 2 24.2 15.8 38 20l-13.8 4.2L20 38l-4.2-13.8L2 20l13.8-4.2L20 2Z"
            fill="currentColor"
        />
        <circle cx="31" cy="9" r="3" fill="currentColor" opacity=".55" />
    </svg>
</template>
