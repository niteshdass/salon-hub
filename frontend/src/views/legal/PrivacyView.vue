<script setup>
import LegalPage from '@/components/legal/LegalPage.vue'
</script>

<template>
  <LegalPage title="Privacy Policy" updated="5 August 2026">
    <h2>1. What we collect</h2>
    <p>
      When a salon signs up, we collect the salon's account details: business name,
      address, owner name, email and phone number. When the salon or its customers use
      SalonHub to make a booking, we collect the customer's name, phone number, email
      address and any booking notes the salon enters — for example a preferred stylist or
      an allergy note. When a deposit is taken online, we store a reference to that
      payment transaction. We never see or store card numbers; those are handled entirely
      by the payment gateway.
    </p>

    <h2>2. Why we collect it</h2>
    <p>
      We use this information to operate the booking service: creating and managing
      appointments, emailing booking confirmations and changes, sending appointment
      reminders by SMS or WhatsApp to customers who gave a phone number, and providing
      customer support to the salon and its customers. We do not
      use booking data to build advertising profiles, and we do not use it for any purpose
      the salon has not asked us to perform.
    </p>

    <h2>3. Who we share it with</h2>
    <ul>
      <li>The salon that owns the record — it is their customer data, and we process it on their behalf.</li>
      <li>
        Our email delivery provider, to send booking confirmations, reschedules and
        cancellations, customer sign-in codes, and account email such as password resets
        and address verification.
      </li>
      <li>Our SMS/WhatsApp provider, only when a salon has reminders enabled for that channel.</li>
      <li>The relevant payment gateway, only when a deposit is taken for a booking.</li>
      <li>
        Our error-monitoring provider, Sentry, when the application hits a technical error.
        Those reports identify the salon whose request failed and can contain data from the
        request that failed.
      </li>
      <li>
        The hosting and network providers that run the service — the server SalonHub is
        installed on, and Cloudflare, which every request to salonhub.com passes through.
      </li>
    </ul>
    <p>We do not sell customer or salon data, and we do not share it for advertising.</p>

    <h2>4. How long we keep it</h2>
    <p>
      We keep account and booking data for the life of the salon's SalonHub account. There
      is no self-service delete: to close an account and have its data removed, write to us
      at the address in section 7. We handle those requests by hand and aim to complete
      them within 30 days — except where we are required by law to retain particular
      records, such as payment references, for longer.
    </p>

    <h2>5. Your rights</h2>
    <p>
      If you are a customer of a salon that uses SalonHub, the salon is the holder of your
      booking record and the first point of contact for accessing, correcting or deleting
      it. If a salon is unable to help, or you have a question about how SalonHub itself
      handles data, contact us directly at
      <a href="mailto:hello@salonhub.com">hello@salonhub.com</a>.
    </p>

    <h2>6. Cookies</h2>
    <p>
      SalonHub stores first-party authentication tokens in your browser's local storage to
      keep you signed in between visits — one for a salon's staff dashboard and one for a
      customer account. We do not use advertising cookies, and
      we do not embed third-party tracking scripts on the dashboard or booking pages.
    </p>

    <h2>7. Contact</h2>
    <p>Questions about this policy: <a href="mailto:hello@salonhub.com">hello@salonhub.com</a>.</p>
  </LegalPage>
</template>
