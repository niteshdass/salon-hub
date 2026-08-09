<script setup>
import { CONTACT_EMAIL } from '@/lib/contact'
import LegalPage from '@/components/legal/LegalPage.vue'
</script>

<template>
  <LegalPage title="Refund Policy" updated="5 August 2026">
    <h2>1. Deposits</h2>
    <p>
      Some salons ask customers to pay a deposit to secure a booking. That deposit is
      taken by the salon through the salon's own SSLCommerz gateway credentials, so the
      funds settle directly to the salon's bank account. Glowhub never holds, receives or
      has access to deposit funds — we only pass the payment through to the salon's
      gateway.
    </p>

    <h2>2. Cancelling a booking</h2>
    <p>
      A customer can cancel or reschedule a changeable booking from their Glowhub
      customer account. When a deposit is paid online, the payment gateway also returns
      the customer to a self-service manage page for that one booking, at a link they can
      keep. Booking confirmation emails do not carry a manage link — they ask the customer
      to reply to the email or call the salon — so a customer with neither an account nor
      a deposit link should contact the salon directly. Whether a cancellation entitles
      the customer to a refund of their deposit is set by the individual salon's own
      cancellation policy. Glowhub does not collect, display or enforce a cancellation
      policy on a salon's behalf, so ask the salon for theirs before you pay a deposit —
      deposit terms vary with the salon's own business.
    </p>

    <h2>3. How refunds are issued</h2>
    <p>
      When a salon approves a refund, the salon issues it from its Glowhub dashboard. The
      refund is processed through the same SSLCommerz gateway the deposit was collected
      through, and funds are returned to the customer's original payment method. Refunds
      typically appear within 5–10 business days, though the exact timing depends on the
      customer's bank or card issuer, not on Glowhub.
    </p>

    <h2>4. Disputes</h2>
    <p>
      A deposit is a payment between you and the salon. Glowhub provides the booking
      software and never holds the funds, so it is the salon — not Glowhub — that can
      issue a refund, and a dispute is between you and them. Contact the salon first; if
      the payment was taken by card, your bank or card issuer is the other route open to
      you. You can tell us about a salon at
      <a :href="`mailto:${CONTACT_EMAIL}`">{{ CONTACT_EMAIL }}</a>, and we will look into
      whether it is still fit to use Glowhub — but we cannot order a refund or decide a
      dispute on your behalf.
    </p>

    <h2>5. Glowhub subscription</h2>
    <p>
      The Glowhub Free plan carries no subscription charge, so there is nothing to
      refund on your Glowhub account itself. This policy covers deposits and payments
      taken by salons from their customers, not Glowhub's own pricing plans.
    </p>
  </LegalPage>
</template>
