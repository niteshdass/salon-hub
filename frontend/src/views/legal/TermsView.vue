<script setup>
import { CONTACT_EMAIL } from '@/lib/contact'
import LegalPage from '@/components/legal/LegalPage.vue'
</script>

<template>
  <LegalPage title="Terms of Service" updated="5 August 2026">
    <h2>1. Who we are</h2>
    <p>
      Glowhub is a booking and salon-management service. These terms govern your use of
      the Glowhub dashboard, the booking website we host for your salon, and the
      Glowhub marketing site.
    </p>

    <h2>2. Your account</h2>
    <p>
      You must give accurate registration details and keep your password secure. You are
      responsible for everything done under your account, including actions by staff
      members you invite.
    </p>

    <h2>3. Acceptable use</h2>
    <ul>
      <li>Do not use Glowhub to send unsolicited marketing to your customers.</li>
      <li>Do not upload content you do not hold the rights to.</li>
      <li>Do not attempt to access another salon's data.</li>
    </ul>

    <h2>4. Your customers' data</h2>
    <p>
      Customer records you create in Glowhub belong to you. We process them on your
      behalf as described in our Privacy Policy, and we do not sell them or use them to
      market to your customers.
    </p>

    <h2>5. Plans and payment</h2>
    <p>
      The Free plan is offered at no cost and is limited to one branch and ten staff
      members. Deposits your customers pay online are collected through your own payment
      gateway credentials and settle to you, not to Glowhub.
    </p>

    <h2>6. Availability</h2>
    <p>
      We aim to keep Glowhub available continuously but do not guarantee uninterrupted
      service. We may suspend access for maintenance or where an account breaches
      these terms.
    </p>

    <h2>7. Ending your account</h2>
    <p>
      You may close your account at any time by contacting us — there is no self-service
      close button, so it goes through the address in section 9. We remove your
      organization's data by hand and aim to complete it within 30 days, except where we
      must retain records by law.
    </p>

    <h2>8. Changes</h2>
    <p>
      We may update these terms. The "Last updated" date at the top of this page changes
      whenever we do, so please check it from time to time.
    </p>

    <h2>9. Contact</h2>
    <p>Questions about these terms: <a :href="`mailto:${CONTACT_EMAIL}`">{{ CONTACT_EMAIL }}</a>.</p>
  </LegalPage>
</template>
