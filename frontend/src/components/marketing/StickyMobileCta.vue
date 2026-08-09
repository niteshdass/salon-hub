<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import MarketingCta from './MarketingCta.vue'
import { useSessionLink } from '@/lib/sessionLink'

// The buyer reads this page on a phone, where the hero's button is a long way
// up by the time they are convinced. The bar follows them down — but only for
// visitors with nothing to sign in to, because asking an owner who is already
// running a salon to register again is noise.
const session = useSessionLink()

const past = ref(false)
let observer = null

onMounted(() => {
  const hero = document.getElementById('top')
  if (!hero || typeof IntersectionObserver === 'undefined') return

  observer = new IntersectionObserver(([entry]) => {
    past.value = !entry.isIntersecting
  })
  observer.observe(hero)
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-full"
    enter-to-class="translate-y-0"
    leave-active-class="transition duration-150 ease-in"
    leave-from-class="translate-y-0"
    leave-to-class="translate-y-full"
  >
    <div
      v-if="past && !session"
      data-sticky-cta
      class="fixed inset-x-0 bottom-0 z-40 border-t border-brand-100 bg-paper/95 px-5 py-3 backdrop-blur-md lg:hidden"
    >
      <MarketingCta to="/register" label="Register free" block />
    </div>
  </Transition>
</template>
