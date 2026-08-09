<script setup>
import { reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { CONTACT_EMAIL } from '@/lib/contact'
import api from '@/lib/api'

// The contact form used to own a full-height section between the FAQ and the
// footer, which put a form in front of a reader on their way to the closing
// ask. It lives here now, at the bottom, where someone who wants to write to
// us is already looking.
const productLinks = [
  { label: 'Features', href: '#features' },
  { label: 'How it works', href: '#how-it-works' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'FAQ', href: '#faq' },
]

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
    await api.post('/contact', { name: form.name, email: form.email, message: form.message })
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

const fieldClass = (field) => [
  'mt-1.5 w-full rounded-xl border bg-white/5 px-4 py-3 text-paper placeholder-paper/30 transition-colors focus:ring-2 focus:ring-brand-400/40 focus:outline-none',
  fieldError(field) ? 'border-rose-400' : 'border-paper/15 focus:border-brand-400',
]
</script>

<template>
  <footer class="bg-ink text-paper/70">
    <div class="mx-auto max-w-6xl px-5 py-14 lg:px-8 lg:py-16">
      <div class="grid gap-12 lg:grid-cols-[1.1fr_1fr]">
        <!-- Brand + write to us -->
        <div>
          <!--
            Same droplet + lowercase two-tone wordmark as MarketingNav, but on
            this bg-ink ground brand-600 (the nav's shade) only holds a
            2.89:1 contrast ratio against ink and reads muddy. brand-300
            clears 7.75:1 here, so the terracotta half is lifted for this
            ground rather than reused verbatim. See MarketingNav.vue for the
            paper-ground version.
          -->
          <div class="flex items-center gap-2.5">
            <svg
              viewBox="0 0 24 24"
              class="h-7 w-7 shrink-0 text-brand-300"
              fill="none"
              stroke="currentColor"
              stroke-width="1.75"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
            </svg>
            <span class="font-display text-xl font-semibold tracking-tight"><span class="text-paper">glow</span><span class="text-brand-300">hub</span></span>
          </div>
          <p class="mt-4 max-w-xs leading-relaxed text-paper/55">Booking software for salons in Bangladesh.</p>
          <a
            :href="`mailto:${CONTACT_EMAIL}`"
            class="mt-4 inline-flex min-h-11 items-center text-sm text-paper/70 underline decoration-paper/20 underline-offset-4 transition-colors hover:text-paper hover:decoration-paper/50"
          >
            {{ CONTACT_EMAIL }}
          </a>

          <div v-if="success" class="mt-8 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-5">
            <p class="font-display text-lg font-semibold text-paper">Thanks — we'll be in touch soon.</p>
          </div>

          <form v-else class="mt-8 max-w-sm space-y-4" novalidate @submit.prevent="submit">
            <div>
              <label for="footer-contact-name" class="block text-sm font-medium text-paper/70">Name</label>
              <input
                id="footer-contact-name"
                v-model="form.name"
                type="text"
                autocomplete="name"
                :aria-invalid="!!fieldError('name')"
                :aria-describedby="fieldError('name') ? 'footer-contact-name-error' : undefined"
                :class="fieldClass('name')"
                placeholder="Your name"
              />
              <p v-if="fieldError('name')" id="footer-contact-name-error" class="mt-1.5 text-sm text-rose-300">{{ fieldError('name') }}</p>
            </div>

            <div>
              <label for="footer-contact-email" class="block text-sm font-medium text-paper/70">Email</label>
              <input
                id="footer-contact-email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                :aria-invalid="!!fieldError('email')"
                :aria-describedby="fieldError('email') ? 'footer-contact-email-error' : undefined"
                :class="fieldClass('email')"
                placeholder="you@salon.com"
              />
              <p v-if="fieldError('email')" id="footer-contact-email-error" class="mt-1.5 text-sm text-rose-300">{{ fieldError('email') }}</p>
            </div>

            <div>
              <label for="footer-contact-message" class="block text-sm font-medium text-paper/70">Message</label>
              <textarea
                id="footer-contact-message"
                v-model="form.message"
                rows="3"
                :aria-invalid="!!fieldError('message')"
                :aria-describedby="fieldError('message') ? 'footer-contact-message-error' : undefined"
                :class="fieldClass('message')"
                placeholder="How can we help your salon?"
              ></textarea>
              <p v-if="fieldError('message')" id="footer-contact-message-error" class="mt-1.5 text-sm text-rose-300">{{ fieldError('message') }}</p>
            </div>

            <p v-if="formError" class="rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
              {{ formError }}
            </p>

            <button
              type="submit"
              :disabled="sending"
              class="inline-flex min-h-11 items-center justify-center rounded-xl bg-paper px-6 py-3 text-sm font-semibold text-ink transition-colors hover:bg-white focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-ink focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ sending ? 'Sending…' : 'Send message' }}
            </button>
          </form>
        </div>

        <!-- Link columns -->
        <div class="grid gap-8 sm:grid-cols-3">
          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Product</p>
            <ul class="mt-4">
              <li v-for="link in productLinks" :key="link.href">
                <a :href="link.href" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">{{
                  link.label
                }}</a>
              </li>
            </ul>
          </div>

          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Account</p>
            <ul class="mt-4">
              <li><RouterLink to="/register" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Register free</RouterLink></li>
              <li><RouterLink to="/login" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Salon log in</RouterLink></li>
              <li><RouterLink to="/salons" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Find a salon</RouterLink></li>
              <li><RouterLink to="/account/login" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">My bookings</RouterLink></li>
            </ul>
          </div>

          <div>
            <p class="text-xs font-semibold tracking-widest text-paper/40 uppercase">Legal</p>
            <ul class="mt-4">
              <li><RouterLink to="/terms" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Terms of Service</RouterLink></li>
              <li><RouterLink to="/privacy" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Privacy Policy</RouterLink></li>
              <li><RouterLink to="/refund" class="flex min-h-11 items-center text-paper/70 transition-colors hover:text-paper">Refund Policy</RouterLink></li>
            </ul>
          </div>
        </div>
      </div>

      <p class="mt-12 border-t border-paper/10 pt-8 text-sm text-paper/50">© 2026 Glowhub. All rights reserved.</p>
    </div>
  </footer>
</template>
