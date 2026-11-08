import { SITE } from './content'
import { LegalPage } from './Legal'

const contact = SITE.legal.privacyContact || SITE.contact.email

export default function Privacy() {
  return (
    <LegalPage
      title="Privacy Policy"
      intro={`This policy explains how ${SITE.owner} handles personal information when you use this website and the LPG ordering system.`}
    >
      <section>
        <h2>1. Who we are</h2>
        <p>
          {SITE.owner} operates an LPG distribution business through one or more
          branches. In this policy, &ldquo;we&rdquo;, &ldquo;us&rdquo; and
          &ldquo;our&rdquo; refer to {SITE.owner}. &ldquo;You&rdquo; means a
          customer, a staff member, or a visitor to this website.
        </p>
        <p>
          This policy is written with the Philippine Data Privacy Act of 2012
          (Republic Act No. 10173), its implementing rules, and the issuances of
          the National Privacy Commission (NPC) in mind. It does not replace advice
          from your own lawyer or data protection officer.
        </p>
      </section>

      <section>
        <h2>2. What we collect</h2>
        <p>Depending on how you use the service, we may collect:</p>
        <ul>
          <li><strong>Account details</strong> — your name, username, mobile number, delivery address, and password (stored only as a one-way hash).</li>
          <li><strong>Order and delivery details</strong> — branch, cylinder sizes and quantities, requested date and time slot, delivery notes, order status, and the staff member who handled the order.</li>
          <li><strong>Payment and balance records</strong> — amounts paid or charged to credit, payment method and reference, and the running balance on your account.</li>
          <li><strong>Stock and branch records</strong> — deliveries received, transfers between branches, stock adjustments, and empty-cylinder returns.</li>
          <li><strong>Staff records</strong> — work contact details, assigned branch, role, account status, and audit entries for actions taken in the system.</li>
          <li><strong>Technical and security data</strong> — session identifier, IP address, browser user agent, and timestamps recorded in security logs and the audit trail.</li>
          <li><strong>Local browser storage</strong> — your language preference and, for owners, the selected branch view. These are stored on your device, not sent to third parties.</li>
        </ul>
        <p>
          We do not ask for government ID numbers, financial account numbers, or
          health information through this system. Please do not send those details in
          order notes.
        </p>
      </section>

      <section>
        <h2>3. Why we use it</h2>
        <ul>
          <li>To create and secure your account, and to verify who is using it.</li>
          <li>To accept, approve, prepare, deliver, and record LPG orders.</li>
          <li>To keep accurate branch stock, sales, payment, and credit records.</li>
          <li>To contact you about an order, a delivery, a balance, or a safety issue.</li>
          <li>To prevent fraud, abuse, and unauthorised access, and to investigate incidents.</li>
          <li>To produce branch and management reports, using aggregated or de-identified data where practicable.</li>
          <li>To comply with tax, accounting, safety, and other legal obligations that apply to the business.</li>
        </ul>
      </section>

      <section>
        <h2>4. Legal bases</h2>
        <p>
          We rely on the bases available under the Data Privacy Act, which may
          include performing a contract with you (for example, fulfilling an order),
          complying with a legal obligation, protecting legitimate business
          interests such as security and fraud prevention, and your consent where
          consent is the appropriate basis. Where we rely on consent, you may
          withdraw it, subject to legal and contractual limits.
        </p>
      </section>

      <section>
        <h2>5. Who can see your information</h2>
        <p>
          Access is limited to people who need it for their work: branch staff
          handling your orders, authorised delivery personnel, and owners or
          administrators who oversee operations. We do not sell personal
          information.
        </p>
        <p>
          We may share information with service providers that help us run the
          business — for example, hosting, SMS or email delivery, or payment
          processing — under appropriate confidentiality and security terms. We may
          also disclose information when required by law, a court, a regulator, or a
          lawful request from the NPC or another competent authority.
        </p>
        <p>
          If a service provider processes data outside the Philippines, we remain
          responsible for ensuring appropriate safeguards are in place, consistent
          with the Data Privacy Act.
        </p>
      </section>

      <section>
        <h2>6. How long we keep it</h2>
        <p>
          We keep personal information only for as long as needed for the purposes
          above, including the period required to resolve orders, balances, and
          disputes. Transaction and accounting records may be kept for the period
          required by Philippine tax and business-record rules. Security logs and
          audit entries are kept for a limited period for investigation and
          accountability. When information is no longer needed, we delete it or
          make it anonymous in a way that cannot reasonably be reversed.
        </p>
      </section>

      <section>
        <h2>7. How we protect it</h2>
        <p>
          We use measures appropriate to the risk, including one-way password
          hashing, role-based access, branch-level access limits, CSRF protection,
          secure session handling, login rate limiting, security headers, and
          audit logging. No system is completely secure. If you believe your account
          or information has been compromised, contact us immediately so we can
          investigate and, where required, notify the NPC and affected people.
        </p>
      </section>

      <section>
        <h2>8. Your rights</h2>
        <p>Subject to the Data Privacy Act and its exceptions, you have the right to:</p>
        <ul>
          <li>be informed about how your personal information is processed;</li>
          <li>request access to the personal information we hold about you;</li>
          <li>ask us to correct inaccurate or incomplete information;</li>
          <li>object to processing, or withdraw consent where consent is the basis;</li>
          <li>request erasure or blocking when the information is no longer necessary, unlawfully obtained, or used for unauthorised purposes;</li>
          <li>request data portability in a structured, commonly used electronic format, where applicable;</li>
          <li>complain to the National Privacy Commission.</li>
        </ul>
        <p>
          We may need to verify your identity before acting on a request. Some
          records must be retained for legal or accounting reasons even after an
          erasure request.
        </p>
      </section>

      <section>
        <h2>9. Children</h2>
        <p>
          The ordering system is intended for adults acting for themselves or a
          business. It is not directed to children, and we do not knowingly collect
          personal information from children. If you believe a child has provided
          information, contact us so we can remove it.
        </p>
      </section>

      <section>
        <h2>10. Cookies and local storage</h2>
        <p>
          This site uses a session cookie so you can stay logged in, and a security
          token to protect forms from cross-site request forgery. We also store your
          language choice and branch view in your browser. We do not use advertising
          or cross-site tracking cookies. Blocking the session cookie will prevent
          you from logging in.
        </p>
      </section>

      <section>
        <h2>11. Changes and contact</h2>
        <p>
          We may update this policy when our practices or the law change. The
          version and effective date at the top of this page show which version
          applies. If a change materially affects your rights, we will take
          reasonable steps to let you know.
        </p>
        <p>
          For privacy questions or requests, contact{' '}
          {contact ? <a href={`mailto:${contact}`}>{contact}</a> : `${SITE.owner} using the contact details published on this website`}.
          You may also contact the National Privacy Commission through its official
          channels.
        </p>
      </section>
    </LegalPage>
  )
}
