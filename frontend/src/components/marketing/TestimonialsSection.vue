<script setup>
// Illustrative social proof — fictional but realistic (per brief). Marked as
// such below the cards so it never reads as a verified claim.
const testimonials = [
  {
    quote: 'We cut no-shows almost in half in the first month. The deposit feature alone paid for itself.',
    name: 'Mara V.',
    salon: 'Bloom & Braid',
    initials: 'MV',
  },
  {
    quote: 'My clients book at midnight now. I stopped playing phone tag and got my evenings back.',
    name: 'Devan R.',
    salon: 'Sharp & Co. Barbers',
    initials: 'DR',
  },
  {
    quote: "Running three locations used to mean three calendars. Now it's one screen.",
    name: 'Priya S.',
    salon: 'Lotus Spa Group',
    initials: 'PS',
  },
]
</script>

<template>
  <section class="py-20 sm:py-28">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">
      <div class="mx-auto max-w-2xl text-center">
        <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
          <span class="h-px w-8 bg-brand-300"></span>
          Testimonials
          <span class="h-px w-8 bg-brand-300"></span>
        </p>
        <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
          Loved by salons like yours.
        </h2>
      </div>

      <div class="mt-14 grid gap-6 md:grid-cols-3">
        <figure
          v-for="t in testimonials"
          :key="t.name"
          class="relative flex flex-col rounded-3xl border border-brand-100 bg-white p-8 shadow-sm shadow-ink/[0.03] transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-ink/[0.06]"
        >
          <span aria-hidden="true" class="font-display text-6xl leading-none text-brand-200">&ldquo;</span>
          <blockquote class="-mt-4 flex-1 text-lg leading-relaxed text-ink/80">
            {{ t.quote }}
          </blockquote>
          <figcaption class="mt-6 flex items-center gap-3 border-t border-brand-50 pt-5">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-100 to-brand-200 font-display text-sm font-semibold text-brand-700">
              {{ t.initials }}
            </span>
            <span class="leading-tight">
              <span class="block font-semibold text-ink">{{ t.name }}</span>
              <span class="block text-sm text-ink/55">{{ t.salon }}</span>
            </span>
          </figcaption>
        </figure>
      </div>

      <p class="mt-8 text-center text-xs text-ink/40">Illustrative stories, representative of results salons see with SalonHub.</p>
    </div>
  </section>
</template>
