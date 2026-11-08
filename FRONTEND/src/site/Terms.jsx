import { SITE } from './content'
import { LegalPage } from './Legal'

export default function Terms() {
  return (
    <LegalPage
      title="Terms and Conditions"
      intro={`These terms govern the use of this website and the LPG ordering system operated by ${SITE.owner}.`}
    >
      <section>
        <h2>1. Acceptance</h2>
        <p>
          By creating an account, placing an order, or otherwise using this service,
          you agree to these terms and to our Privacy Policy. If you do not agree,
          do not use the service. If you use the service for a business, you confirm
          that you are authorised to act for that business.
        </p>
      </section>

      <section>
        <h2>2. The service</h2>
        <p>
          The service lets customers order LPG from a branch, and lets authorised
          staff record sales, plant deliveries, stock movements, payments, and
          transfers between branches. It is an ordering and record-keeping tool. It
          does not itself supply gas, transport cylinders, or guarantee that a
          particular size, price, time slot, or delivery area is available.
        </p>
      </section>

      <section>
        <h2>3. Accounts and security</h2>
        <ul>
          <li>Give accurate, current information and keep it up to date.</li>
          <li>Keep your password confidential and do not share your account.</li>
          <li>Tell us promptly if you suspect unauthorised access or a security incident.</li>
          <li>Staff accounts are created by the owner and are limited to the assigned branch and role.</li>
          <li>We may suspend or disable an account to protect customers, staff, or the business.</li>
        </ul>
      </section>

      <section>
        <h2>4. Orders, prices, and confirmation</h2>
        <p>
          Prices and available sizes are set per branch and may change. The price
          recorded with an approved order is the price for that order unless both
          sides agree to a correction. An order is not accepted until the branch
          approves it. We may decline or cancel an order because of stock, delivery
          area, safety concerns, incorrect pricing, suspected fraud, or a credit
          limit.
        </p>
        <p>
          Cut-off times, maximum quantities, and delivery days are branch-specific.
          The app shows the branch values that apply when you order. Pending orders
          may be edited or cancelled before the branch approves or delivers them.
        </p>
      </section>

      <section>
        <h2>5. Payment and credit</h2>
        <p>
          You may pay by cash on delivery or another method the branch accepts.
          Credit is available only when the branch grants it, and only within any
          approved limit. You are responsible for amounts charged to your account.
          Payments are recorded against the branch where they are received. If you
          believe a payment or balance is wrong, contact the branch promptly so it
          can be checked.
        </p>
      </section>

      <section>
        <h2>6. Delivery, cylinders, and returns</h2>
        <ul>
          <li>Provide a safe, accessible delivery address and accurate contact details.</li>
          <li>Make sure an authorised adult can receive the cylinders and any required empties.</li>
          <li>Return empty cylinders as recorded in the delivery. Unreturned empties may remain on your account.</li>
          <li>Do not move, store, or use cylinders in a way that breaks the cylinder label, safety guidance, or local rules.</li>
          <li>Report damage, a leak, or a missing item at the time of delivery, or as soon as you discover it.</li>
        </ul>
      </section>

      <section>
        <h2>7. Safety responsibilities</h2>
        <p>
          LPG must be handled and used safely. You are responsible for using
          appliances correctly, keeping the area ventilated, checking connections,
          and following the cylinder label, your supplier&rsquo;s instructions, and
          applicable regulations. If you smell gas, follow the emergency guidance on
          this website and contact your supplier or local emergency services from a
          safe distance. Do not attempt repairs yourself.
        </p>
      </section>

      <section>
        <h2>8. Acceptable use</h2>
        <p>You must not:</p>
        <ul>
          <li>use the service for unlawful, fraudulent, or harmful activity;</li>
          <li>attempt to access another person&rsquo;s account, another branch&rsquo;s records, or restricted parts of the system;</li>
          <li>interfere with the service, probe it for vulnerabilities without written permission, or introduce malicious code;</li>
          <li>copy, scrape, or resell data from the service without authorisation; or</li>
          <li>impersonate another person, customer, or member of staff.</li>
        </ul>
      </section>

      <section>
        <h2>9. Intellectual property and brand use</h2>
        <p>
          The software, layout, and original content of this service are protected
          by applicable intellectual property laws. Business names, logos, and
          trademarks shown on this site must be authorised by their owners. Do not
          use a third-party trademark or logo unless you have permission.
        </p>
      </section>

      <section>
        <h2>10. Availability and changes</h2>
        <p>
          We aim to keep the service available and accurate, but it may be
          interrupted for maintenance, connectivity, or security reasons. Records
          may be corrected through a reversal or adjustment that preserves an audit
          trail rather than by deleting history. We may change features or these
          terms; the version and effective date at the top of this page show the
          current terms.
        </p>
      </section>

      <section>
        <h2>11. Disclaimers and liability</h2>
        <p>
          To the extent allowed by law, the service is provided &ldquo;as is&rdquo;
          and without implied warranties that are not expressly stated. Nothing in
          these terms excludes liability that cannot lawfully be excluded, including
          liability for death or personal injury caused by negligence, fraud, or
          any statutory consumer right that applies.
        </p>
        <p>
          We are not liable for indirect or consequential loss, or for loss caused
          by events outside our reasonable control. Where liability can be limited,
          it is limited to the amount paid for the affected order. Safety guidance
          on this website is general information; always follow the cylinder label,
          your supplier&rsquo;s instructions, and local regulations.
        </p>
      </section>

      <section>
        <h2>12. Suspension and termination</h2>
        <p>
          We may suspend or end access where these terms are breached, where needed
          to protect people or the service, or where an account is inactive. You may
          stop using the service at any time. Records needed for legal, tax, safety,
          or dispute purposes may be retained as described in our Privacy Policy.
        </p>
      </section>

      <section>
        <h2>13. Governing law and disputes</h2>
        <p>
          These terms are governed by the laws of the Republic of the Philippines.
          Before starting formal proceedings, contact us so we can try to resolve the
          issue. If a dispute cannot be resolved, it may be brought before the
          appropriate Philippine court or agency. Consumer rights under Philippine
          law are not affected.
        </p>
      </section>

      <section>
        <h2>14. Contact</h2>
        <p>
          For questions about these terms, orders, payments, or deliveries, contact{' '}
          {SITE.owner} using the contact details published on this website. For
          privacy requests, see our Privacy Policy.
        </p>
      </section>
    </LegalPage>
  )
}
