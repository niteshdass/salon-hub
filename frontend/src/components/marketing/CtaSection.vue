<script setup>
import MarketingCta from './MarketingCta.vue'
</script>

<template>
  <section class="bg-ink py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 text-center lg:px-8">
      <h2 class="font-display text-[2rem] leading-[1.08] font-semibold tracking-tight text-paper sm:text-4xl lg:text-5xl">
        Your booking page is ten minutes away.
      </h2>
      <p class="mt-5 text-lg leading-relaxed text-paper/60">
        Free to start. No card. Nothing to install.
      </p>
      <div class="mt-9 flex justify-center">
        <MarketingCta to="/register" label="Register your salon" />
      </div>
    </div>
  </section>
</template>
