<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-default px-6 py-12">
    <div
      aria-hidden="true"
      class="pointer-events-none absolute -top-40 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-primary/10 blur-3xl"
    />

    <div class="relative w-full max-w-lg space-y-6">
      <div class="flex flex-col items-center gap-2 text-center">
        <span class="grid size-11 place-items-center rounded-full bg-primary text-lg font-bold text-inverted">
          A
        </span>
        <p class="text-2xs font-semibold uppercase tracking-[0.24em] text-dimmed">
          Autotributo
        </p>
      </div>

      <section class="rounded-lg border border-default bg-elevated/50 p-6 shadow-sm sm:p-8">
        <h1 class="text-xl font-semibold tracking-tight text-highlighted">
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

      <p class="text-center text-2xs text-dimmed">
        Contabilidad SRI · Ecuador
      </p>
    </div>
  </div>
</template>
