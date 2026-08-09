<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import MarketingCta from './MarketingCta.vue'
import { useSessionLink } from '@/lib/sessionLink'

// The buyer reads this page on a phone, where the hero's button is a long way
// up by the time they are convinced. The bar follows them down — but only for
// visitors with nothing to sign in to, because asking an owner who is already
// running a salon to register again is noise.
const session = useSessionLink()

// Two sentinels bound the bar's window: it wakes once the hero (#top) has
// scrolled away, and goes back to sleep once the closing CTA (#cta) is on
// screen, because by then the visitor has a full-size Register button in
// front of them and the bar would just sit on top of the footer forever.
const heroPast = ref(false)
const ctaVisible = ref(false)
let heroObserver = null
let ctaObserver = null

const visible = computed(() => heroPast.value && !ctaVisible.value && !session.value)

onMounted(() => {
  if (typeof IntersectionObserver === 'undefined') return

  const hero = document.getElementById('top')
  if (hero) {
    heroObserver = new IntersectionObserver(([entry]) => {
      heroPast.value = !entry.isIntersecting
    })
    heroObserver.observe(hero)
  }

  // Optional: a page that mounts this without a CtaSection just never hides
  // the bar again once it has appeared, which is the old, pre-boundary
  // behaviour rather than a broken one.
  const cta = document.getElementById('cta')
  if (cta) {
    ctaObserver = new IntersectionObserver(([entry]) => {
      ctaVisible.value = entry.isIntersecting
    })
    ctaObserver.observe(cta)
  }
})

onBeforeUnmount(() => {
  heroObserver?.disconnect()
  ctaObserver?.disconnect()
})
</script>

<template>
  <Transition name="sticky-cta">
    <div
      v-if="visible"
      data-sticky-cta
      class="fixed inset-x-0 bottom-0 z-40 border-t border-brand-100 bg-paper/95 px-5 py-3 backdrop-blur-md lg:hidden"
    >
      <MarketingCta to="/register" label="Register free" block />
    </div>
  </Transition>
</template>

<style scoped>
.sticky-cta-enter-active {
  transition: transform 0.2s ease-out;
}

.sticky-cta-leave-active {
  transition: transform 0.15s ease-in;
}

.sticky-cta-enter-from,
.sticky-cta-leave-to {
  transform: translateY(100%);
}

@media (prefers-reduced-motion: reduce) {
  .sticky-cta-enter-active,
  .sticky-cta-leave-active {
    transition: none;
  }
}
</style>
