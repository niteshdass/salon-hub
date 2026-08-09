<script setup>
import MarketingNav from '@/components/marketing/MarketingNav.vue'
import HeroSection from '@/components/marketing/HeroSection.vue'
import PainSection from '@/components/marketing/PainSection.vue'
import ProductTourSection from '@/components/marketing/ProductTourSection.vue'
import HowItWorksSection from '@/components/marketing/HowItWorksSection.vue'
import PricingSection from '@/components/marketing/PricingSection.vue'
import TrustSection from '@/components/marketing/TrustSection.vue'
import FaqSection from '@/components/marketing/FaqSection.vue'
import ContactSection from '@/components/marketing/ContactSection.vue'
import MarketingFooter from '@/components/marketing/MarketingFooter.vue'
import CtaSection from '@/components/marketing/CtaSection.vue'
import StickyMobileCta from '@/components/marketing/StickyMobileCta.vue'
</script>

<template>
  <div class="bg-paper text-ink min-h-screen">
    <MarketingNav />
    <main>
      <HeroSection />
      <PainSection />
      <ProductTourSection />
      <HowItWorksSection />
      <PricingSection />
      <TrustSection />
      <FaqSection />
      <ContactSection />
      <CtaSection />
    </main>
    <MarketingFooter />
    <StickyMobileCta />
  </div>
</template>
