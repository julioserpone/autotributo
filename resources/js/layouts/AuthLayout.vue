<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-canvas px-6 py-12">
    <div class="w-full max-w-lg space-y-6">
      <div class="flex flex-col items-center gap-2 text-center">
        <span class="grid size-10 place-items-center rounded-control bg-brand font-bold text-white shadow-2xs">
          A
        </span>
        <p class="text-xs font-medium uppercase tracking-[0.2em] text-faint">
          Autotributo
        </p>
      </div>

      <section class="rounded-lg border border-line bg-surface p-6 shadow-2xs dark:shadow-none">
        <h1 class="text-xl font-semibold tracking-tight text-ink">
          {{ title }}
        </h1>
        <p
          v-if="subtitle"
          class="mt-1 text-sm text-muted"
        >
          {{ subtitle }}
        </p>

        <div class="mt-5">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="mt-4"
        >
          <slot name="footer" />
        </div>
      </section>

      <p class="text-center text-2xs text-faint">
        Contabilidad SRI · Ecuador
      </p>
    </div>
  </div>
</template>
