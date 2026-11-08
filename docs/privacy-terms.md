# Privacy Policy and Terms: review guide

The public site ships with plain-language **templates** at `/privacy` and
`/terms`. They are intentionally marked:

> **Review required before launch.** This is a plain-language template. It is
> not legal advice and has not been reviewed for your business, branch
> operations, payment methods or hosting arrangements.

Nothing in this document is legal advice. Have a Philippine lawyer review and
adapt both documents for the actual business before publishing.

## 1. Why review is mandatory

The system processes personal data and supports real commercial transactions.
The templates cannot know:

- the registered business name, address, DPO and privacy contact;
- the lawful basis for each processing purpose;
- actual retention periods;
- the hosting location and any cross-border transfers;
- payment providers actually used and what data they receive;
- credit terms, delivery areas, refunds and cylinder/deposit rules;
- CCTV, call recording, analytics or marketing tools added later;
- the outcome of a Data Protection Impact Assessment, if required;
- whether the business is a personal information controller or processor in
  each relationship.

## 2. Philippine data privacy framework

The Privacy Policy should be reviewed against:

- **RA 10173** — Data Privacy Act of 2012;
- the **DPA Implementing Rules and Regulations (IRR)**;
- relevant **National Privacy Commission (NPC)** circulars and advisories;
- the NPC’s guidance on privacy notices, security measures, breach
  notification and data subject rights.

Official sources:

- National Privacy Commission — <https://privacy.gov.ph/>
- Official Gazette — <https://www.officialgazette.gov.ph/>
- NPC privacy notice and breach resources — verify current pages on
  `privacy.gov.ph` before launch.

Do not copy model clauses from another company without checking that they match
this system’s actual data flows.

## 3. Data the system collects

| Data | Where it is used |
|------|------------------|
| Name | Customer account, orders, sales, delivery records |
| Username | Login |
| Password hash | Login (plaintext is never stored) |
| Mobile number | Account, contact, optional OTP |
| Delivery address | Orders, delivery |
| Order history | Order list, reports, customer account |
| Sales and payment records | Receipts, balances, reports, audit |
| Empties owed / deposits | Customer account, reports |
| Staff name and branch | Assigned operations, audit |
| Login attempts (IP, username, user agent, success) | Rate limiting and security |
| Audit trail (user, action, before/after changes, branch, time) | Accountability |
| Session data | Keeping the user logged in |

The system does **not** currently store card numbers, bank credentials, or
government IDs. If GCash/Maya/bank references are entered, only the reference
number and amount are stored. Do not add card processing without a PCI-DSS
review.

Cookies currently in scope:

- a session cookie (`lpg_session` by default), HttpOnly, SameSite, Secure on
  HTTPS.

No analytics, advertising or third-party tracking scripts are included. If you
add them, update the Privacy Policy and add a cookie notice/consent mechanism
where required.

## 4. Privacy Policy sections to complete

At minimum, cover:

1. **Controller identity** — registered business name, address, contact, DPO
   and privacy email.
2. **Scope** — website, customer accounts, orders, deliveries, payments,
   staff operations.
3. **Data collected** — categories, examples, source (customer, staff, device).
4. **Purposes and lawful basis** — contract, legitimate interests, consent,
   legal obligation, and which basis applies to which purpose.
5. **How data is used** — order fulfilment, credit and payment, stock and
   branch operations, support, security, audit.
6. **Sharing** — staff assigned to the branch, owner, delivery personnel,
   payment providers, hosting, SMS provider, professional advisers, regulators.
   Name actual processors and link to their privacy notices where required.
7. **Cross-border transfers** — hosting region and safeguards if data leaves
   the Philippines.
8. **Retention** — specific periods for accounts, orders, payments, audit and
   backups, and how deletion/anonymisation works.
9. **Security** — access control, hashing, branch scoping, audit, backups;
   avoid promising absolute security.
10. **Data subject rights** — information, objection, access, rectification,
    erasure/blocking, damages, complaint to the NPC, and how to exercise them.
11. **Breach notification** — who decides, who notifies the NPC and affected
    individuals, and the internal timeline.
12. **Children** — state whether the service is for adults; do not collect
    children’s data without a lawful basis.
13. **Changes** — versioning and effective-date process.
14. **Contact and complaints** — privacy email, DPO, NPC complaint route.

Set the real values with:

```dotenv
VITE_PRIVACY_CONTACT=privacy@example.com
VITE_LEGAL_VERSION=1.0
VITE_LEGAL_EFFECTIVE=1 January 2027
```

Rebuild after changing them. The review flag is part of the UI and should be
removed only after legal sign-off.

## 5. Terms and Conditions sections to complete

At minimum, cover:

1. **Parties and acceptance** — who operates the site, which entities/branches
   are covered.
2. **Accounts** — accurate information, password responsibility, one account
   per person unless agreed, suspension.
3. **Orders** — an order is an offer; branch acceptance/approval; stock and
   price confirmation; cutoff times; maximum quantities; service areas.
4. **Prices and payment** — currency (PHP), branch-specific prices, deposits,
   cash/credit, payment references, receipt, price-change effect on future
   orders (locked prices on existing orders).
5. **Credit** — approval, limits, due dates, statements, suspension, collection
   and any interest/charges (only if lawful and disclosed).
6. **Delivery** — scheduling, access, failed delivery, risk transfer, proof of
   delivery, recipient authority.
7. **Cylinders, deposits and empties** — ownership, return obligations, deposit
   refund rules, lost/damaged cylinders, safety inspection.
8. **Cancellation, returns and refunds** — customer and branch rights, timing,
   payment method, statutory consumer rights.
9. **Safety and acceptable use** — safe handling, no tampering, no illegal use,
   compliance with supplier instructions and local fire codes.
10. **Availability and liability** — service interruptions, force majeure,
    limitation of liability to the extent permitted by Philippine law, and
    carve-outs that cannot be excluded.
11. **Intellectual property** — site content, brand and logo authorisation,
    prohibited copying.
12. **Privacy** — link to the Privacy Policy.
13. **Suspension and termination** — account and service termination.
14. **Governing law and disputes** — Philippine law, venue, negotiation/ADR
    first, consumer complaints.
15. **Changes** — notice, version, effective date.

The template deliberately avoids promising uninterrupted service, guaranteed
delivery times, certifications, or “cleaner” fuel claims.

## 6. DPA operational checklist

- [ ] Appoint a Data Protection Officer (DPO) and register with the NPC if
      required for your organisation.
- [ ] Publish a privacy notice with real identity and contact details.
- [ ] Document lawful basis and purposes for each data set.
- [ ] Keep a processing inventory / records of processing.
- [ ] Define retention and deletion schedules, including backups.
- [ ] Sign data processing agreements with hosting, SMS, payment and support
      providers.
- [ ] Document cross-border transfer safeguards.
- [ ] Implement a data-subject request procedure and response tracker.
- [ ] Implement a breach response plan with NPC notification timelines.
- [ ] Train staff on privacy, social engineering and password hygiene.
- [ ] Review access rights quarterly and when staff leave.
- [ ] Encrypt backups and restrict who can restore them.
- [ ] Conduct a DPIA if the processing is high-risk or large-scale.
- [ ] Review the Privacy Policy at least annually and after material changes.

## 7. Publishing and versioning

1. Lawyer returns reviewed text.
2. Update `frontend/src/site/Privacy.jsx` and `Terms.jsx` with the approved
   text, or move the content to a CMS/document store.
3. Set `VITE_LEGAL_VERSION` and `VITE_LEGAL_EFFECTIVE`.
4. Remove or replace the “Review required before launch” flag only after
   sign-off.
5. Keep a signed/dated copy of each version.
6. Rebuild and deploy the frontend.
7. Announce material changes to customers where required.

## 8. Sign-off

| Item | Owner | Date | Version |
|------|-------|------|---------|
| Business identity and DPO details confirmed | | | |
| Privacy Policy reviewed by Philippine counsel | | | |
| Terms reviewed by Philippine counsel | | | |
| Retention schedule approved | | | |
| Processors and cross-border transfers documented | | | |
| Breach response plan approved | | | |
| Privacy contact monitored | | | |
| Published version archived | | | |

Until this table is complete, the documents remain templates and the site should
not be considered launch-ready.
