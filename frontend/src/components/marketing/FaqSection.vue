<script setup>
import { ref } from 'vue'
import SectionHeading from './SectionHeading.vue'

const faqs = [
  {
    q: 'My clients only use Messenger — will they use this?',
    a: "They don't need an account or an app. You send them a link, they pick a time, they're booked. Most people book in under a minute.",
  },
  {
    q: 'Do I need a website already?',
    a: 'No. Glowhub gives every salon its own booking page the moment you register.',
  },
  {
    q: 'Do I need a card to sign up?',
    a: 'No. The free plan needs no card, and there is nothing to cancel.',
  },
  {
    q: 'Can I take an advance payment?',
    a: 'Yes. Turn on advances and a client pays part of the price to hold the slot, by card or mobile banking through SSLCommerz.',
  },
  {
    q: 'What happens when it stops being free?',
    a: "It doesn't. Paid plans will add more branches and staff; what you can do today on Free stays free, and we will tell you before anything changes.",
  },
  {
    q: 'Who owns my client list?',
    a: 'You do. Export every client, booking and note whenever you want, and take it with you if you leave.',
  },
  {
    q: 'Can I run more than one branch?',
    a: 'One branch and up to ten staff on Free today. More branches are what the paid plans will be for.',
  },
]

const openIndex = ref(0)

function toggle(i) {
  openIndex.value = openIndex.value === i ? null : i
}
</script>

<template>
  <section id="faq" class="scroll-mt-24 py-16 sm:py-24">
    <div class="mx-auto max-w-3xl px-5 lg:px-8">
      <SectionHeading eyebrow="FAQ" title="Questions, answered." align="center" />

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
