<script setup>
import { ref } from 'vue'

const faqs = [
  {
    q: 'Do I need my own website?',
    a: 'No. SalonHub gives every salon its own booking page the moment you register.',
  },
  {
    q: 'Can clients pay a deposit?',
    a: 'Yes — turn on deposits and clients pay to confirm their slot, cutting no-shows.',
  },
  {
    q: 'Does it send reminders?',
    a: 'Yes — connect a Twilio account in Settings and SMS or WhatsApp reminders go out before each appointment, to clients who left a phone number.',
  },
  {
    q: 'Can I manage more than one location?',
    a: 'The Free plan covers one branch and up to ten staff today. Support for more locations is on the way.',
  },
  {
    q: 'How do I get started?',
    a: 'Register your salon, add your services and staff, and share your booking link. It takes minutes.',
  },
]

const openIndex = ref(0)

function toggle(i) {
  openIndex.value = openIndex.value === i ? null : i
}
</script>

<template>
  <section id="faq" class="scroll-mt-24 py-20 sm:py-28">
    <div class="mx-auto max-w-3xl px-6 lg:px-8">
      <div class="text-center">
        <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
          <span class="h-px w-8 bg-brand-300"></span>
          FAQ
          <span class="h-px w-8 bg-brand-300"></span>
        </p>
        <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
          Questions, answered.
        </h2>
      </div>

      <div class="mt-12 space-y-3">
        <div
          v-for="(item, i) in faqs"
          :key="i"
          :class="[
            'overflow-hidden rounded-2xl border bg-white transition-colors duration-200',
            openIndex === i ? 'border-brand-200 shadow-lg shadow-ink/[0.05]' : 'border-brand-100',
          ]"
        >
          <h3>
            <button
              :id="`faq-trigger-${i}`"
              type="button"
              class="flex w-full items-center justify-between gap-4 px-6 py-5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-400"
              :aria-expanded="openIndex === i"
              :aria-controls="`faq-panel-${i}`"
              @click="toggle(i)"
            >
              <span class="font-display text-lg font-semibold text-ink">{{ item.q }}</span>
              <span
                :class="[
                  'grid h-7 w-7 shrink-0 place-items-center rounded-full transition-all duration-300',
                  openIndex === i ? 'rotate-45 bg-brand-500 text-white' : 'bg-brand-50 text-brand-600',
                ]"
              >
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M12 5v14M5 12h14" />
                </svg>
              </span>
            </button>
          </h3>
          <div
            :id="`faq-panel-${i}`"
            role="region"
            :aria-labelledby="`faq-trigger-${i}`"
            :class="[
              'grid transition-all duration-300 ease-out',
              openIndex === i ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
            ]"
          >
            <div class="overflow-hidden">
              <p class="px-6 pb-5 leading-relaxed text-ink/65">{{ item.a }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
