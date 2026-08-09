<script setup>
import SectionHeading from './SectionHeading.vue'
import RuleList from './RuleList.vue'
import SalonPageMock from './mocks/SalonPageMock.vue'
import RemindersMock from './mocks/RemindersMock.vue'
import MoneyMock from './mocks/MoneyMock.vue'

// Three claims, each backed by shipped code: the tenant microsite,
// AppointmentReminderService over Twilio, and SslcommerzGateway + ReportService.
// Nothing here may promise something the API cannot do.
const blocks = [
  {
    n: '01',
    title: 'A booking page of your own',
    body: "Live the minute you register. Your services, your prices, your stylists, your photos. Share the link in your bio and clients book themselves.",
    mock: SalonPageMock,
  },
  {
    n: '02',
    title: 'Reminders that get read',
    body: 'An automatic SMS or WhatsApp the day before, from your own number. The single biggest thing you can do about no-shows.',
    mock: RemindersMock,
  },
  {
    n: '03',
    title: 'Money you can see',
    body: 'Take an advance at booking by card or mobile banking, and see what the week actually made — bookings, revenue, no-shows, staff.',
    mock: MoneyMock,
  },
]

const alsoIncluded = [
  {
    term: 'Also included',
    text: 'Staff schedules & time off · Reviews from real visits · Calendar · Customer list · Expenses · Payroll',
  },
]
</script>

<template>
  <section id="features" class="scroll-mt-24 py-16 sm:py-24">
    <div class="mx-auto max-w-6xl px-5 lg:px-8">
      <SectionHeading eyebrow="What you get" title="Three things, done properly." />

      <div class="mt-14 space-y-16 lg:space-y-24">
        <div
          v-for="(b, i) in blocks"
          :key="b.n"
          data-tour-block
          class="grid items-center gap-8 lg:grid-cols-2 lg:gap-16"
        >
          <div :class="i % 2 === 1 ? 'lg:order-2' : ''">
            <p class="font-display text-2xl font-semibold text-brand-400">{{ b.n }}</p>
            <h3 class="mt-2 font-display text-2xl font-semibold tracking-tight text-ink sm:text-3xl">
              {{ b.title }}
            </h3>
            <p class="mt-4 text-lg leading-relaxed text-ink/65">{{ b.body }}</p>
          </div>
          <div :class="i % 2 === 1 ? 'lg:order-1' : ''">
            <component :is="b.mock" />
          </div>
        </div>
      </div>

      <div class="mt-16">
        <RuleList :items="alsoIncluded" />
      </div>
    </div>
  </section>
</template>
