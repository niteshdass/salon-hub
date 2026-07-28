<script setup>
import { reactive, ref } from 'vue'
import api from '@/lib/api'

const form = reactive({ name: '', email: '', message: '' })
const sending = ref(false)
const success = ref(false)
const errors = ref({})
const formError = ref('')

function fieldError(field) {
  const e = errors.value?.[field]
  return Array.isArray(e) ? e[0] : e
}

async function submit() {
  if (sending.value) return
  sending.value = true
  errors.value = {}
  formError.value = ''
  try {
    await api.post('/contact', {
      name: form.name,
      email: form.email,
      message: form.message,
    })
    success.value = true
  } catch (e) {
    const status = e.response?.status
    if (status === 422) {
      errors.value = e.response?.data?.errors || {}
    } else if (status === 429) {
      formError.value = 'Too many messages — please try again shortly.'
    } else {
      formError.value = "Couldn't send just now — please email us directly."
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section id="contact" class="scroll-mt-24 py-20 sm:py-28">
    <div class="mx-auto max-w-6xl px-6 lg:px-8">
      <div class="overflow-hidden rounded-[2rem] border border-brand-100 bg-gradient-to-br from-brand-50 via-white to-rose-50/60 shadow-xl shadow-ink/[0.05]">
        <div class="grid gap-10 p-8 sm:p-12 lg:grid-cols-2 lg:gap-16">
          <!-- Left: form -->
          <div>
            <p class="inline-flex items-center gap-2 text-xs font-semibold tracking-widest text-brand-600 uppercase">
              <span class="h-px w-8 bg-brand-300"></span>
              Contact
            </p>
            <h2 class="mt-4 font-display text-4xl font-semibold tracking-tight text-ink sm:text-5xl">Talk to us.</h2>

            <!-- Success state replaces the form -->
            <div v-if="success" class="mt-8 flex items-start gap-4 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-6">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-500 text-white">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 6 9 17l-5-5" />
                </svg>
              </span>
              <p class="pt-1.5 font-display text-lg font-semibold text-ink">Thanks — we'll be in touch soon.</p>
            </div>

            <form v-else class="mt-8 space-y-5" novalidate @submit.prevent="submit">
              <div>
                <label for="contact-name" class="block text-sm font-medium text-ink/80">Name</label>
                <input
                  id="contact-name"
                  v-model="form.name"
                  type="text"
                  autocomplete="name"
                  :aria-invalid="!!fieldError('name')"
                  :aria-describedby="fieldError('name') ? 'contact-name-error' : undefined"
                  :class="[
                    'mt-1.5 w-full rounded-xl border bg-white px-4 py-3 text-ink placeholder-ink/40 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-200',
                    fieldError('name') ? 'border-rose-400 focus:border-rose-400' : 'border-brand-200 focus:border-brand-400',
                  ]"
                  placeholder="Your name"
                />
                <p v-if="fieldError('name')" id="contact-name-error" class="mt-1.5 text-sm text-rose-600">{{ fieldError('name') }}</p>
              </div>

              <div>
                <label for="contact-email" class="block text-sm font-medium text-ink/80">Email</label>
                <input
                  id="contact-email"
                  v-model="form.email"
                  type="email"
                  autocomplete="email"
                  :aria-invalid="!!fieldError('email')"
                  :aria-describedby="fieldError('email') ? 'contact-email-error' : undefined"
                  :class="[
                    'mt-1.5 w-full rounded-xl border bg-white px-4 py-3 text-ink placeholder-ink/40 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-200',
                    fieldError('email') ? 'border-rose-400 focus:border-rose-400' : 'border-brand-200 focus:border-brand-400',
                  ]"
                  placeholder="you@salon.com"
                />
                <p v-if="fieldError('email')" id="contact-email-error" class="mt-1.5 text-sm text-rose-600">{{ fieldError('email') }}</p>
              </div>

              <div>
                <label for="contact-message" class="block text-sm font-medium text-ink/80">Message</label>
                <textarea
                  id="contact-message"
                  v-model="form.message"
                  rows="4"
                  :aria-invalid="!!fieldError('message')"
                  :aria-describedby="fieldError('message') ? 'contact-message-error' : undefined"
                  :class="[
                    'mt-1.5 w-full resize-y rounded-xl border bg-white px-4 py-3 text-ink placeholder-ink/40 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-200',
                    fieldError('message') ? 'border-rose-400 focus:border-rose-400' : 'border-brand-200 focus:border-brand-400',
                  ]"
                  placeholder="How can we help your salon?"
                ></textarea>
                <p v-if="fieldError('message')" id="contact-message-error" class="mt-1.5 text-sm text-rose-600">{{ fieldError('message') }}</p>
              </div>

              <p v-if="formError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ formError }}</p>

              <button
                type="submit"
                :disabled="sending"
                class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-500 px-7 py-3.5 text-base font-semibold text-white shadow-lg shadow-brand-500/25 transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-paper disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0"
              >
                {{ sending ? 'Sending…' : 'Send message' }}
              </button>
            </form>
          </div>

          <!-- Right: mailto fallback (always works) -->
          <div class="flex flex-col justify-center rounded-2xl border border-brand-100 bg-white/70 p-8">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600">
              <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6.5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <path d="m3.5 6 8.5 6 8.5-6" />
              </svg>
            </span>
            <p class="mt-5 text-lg text-ink/70">Prefer email? Reach us at</p>
            <a
              href="mailto:hello@salonhub.com"
              class="mt-1 inline-block font-display text-2xl font-semibold text-brand-600 underline decoration-brand-200 decoration-2 underline-offset-4 transition-colors hover:text-brand-700 hover:decoration-brand-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-white"
            >
              hello@salonhub.com
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
