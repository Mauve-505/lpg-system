# Marketing claims and sources

The public site must not make unverified safety, health, environmental,
financial or brand claims. This document records what the site currently says,
where it came from, and what must happen before launch.

## 1. Rules

1. **No invented facts.** No made-up prices, statistics, certifications,
   awards, customer counts, delivery times, coverage areas or savings.
2. **No third-party brand without written authorisation.** The default brand is
   the generic `LPG Distributor`. Do not add a petroleum company’s name, logo,
   colours or trade dress unless the business has written permission.
3. **No “cleaner” or “greener” superiority claims** unless a specific,
   current, verifiable study supports the exact wording. The old “burns
   cleaner” claim was removed.
4. **Safety guidance must trace to an official or recognised source.** Prefer
   the Philippine Department of Energy (DOE), Bureau of Fire Protection (BFP),
   DTI-Bureau of Philippine Standards (BPS), Department of Health (DOH), WHO,
   or the cylinder/appliance manufacturer’s instructions.
5. **Cite the source next to the claim internally.** Marketing pages may keep a
   short public note; this file keeps the full reference.
6. **Re-check links before launch.** Government pages move. Do not publish a
   deep link you have not opened and read.
7. **Keep claims proportional.** “Designed to”, “can help”, “ask your branch”
   are safer than absolute promises.

## 2. Current public claims

| # | Claim on the site | Where | Basis / source | Status |
|---|-------------------|-------|----------------|--------|
| 1 | “LPG for everyday cooking and water heating at home.” | Products / Household | General product description; no performance claim | Safe, internal |
| 2 | “LPG supply for kitchens and businesses that run on demand.” | Products / Commercial | General product description | Safe, internal |
| 3 | “LPG for heating, process, and production needs.” | Products / Industrial | General product description | Safe, internal |
| 4 | “Larger-scale supply arrangements for high-demand sites.” | Products / Bulk | General service description; no volume/price promise | Safe, internal |
| 5 | “Keep cylinders upright. Lift them, never drag, roll, or drop them.” | Safety | DOE LPG safety guidance; BFP fire safety materials; cylinder manufacturer instructions | Verify current DOE/BFP page before launch |
| 6 | “Check the seal and valve before connecting, and have connections made by a trained person.” | Safety | DOE/BFP safety guidance; manufacturer instructions | Verify; avoid implying the company certifies installers |
| 7 | “Store upright in a well-ventilated area, away from heat, direct sunlight, and ignition sources. Never keep cylinders in basements or enclosed spaces.” | Safety | DOE/BFP LPG and fire safety guidance; NFPA 58 as an industry reference | Verify; local fire code may add requirements |
| 8 | “LPG has a strong added odor. Brush soapy water on joints: bubbles show a leak. Never test for leaks with a flame.” | Safety / leak detection | DOE/BFP safety guidance; LPG odorant/industry practice | Verify exact wording; do not claim a specific odorant concentration |
| 9 | “Use LPG appliances only in well-ventilated spaces. Poor ventilation lets gas build up and can produce carbon monoxide.” | Safety / ventilation | DOE/BFP; WHO household air pollution guidance; appliance instructions | Verify; phrase as risk, not a medical claim |
| 10 | “Know where the cylinder valve is and how to close it. Keep your supplier and local emergency numbers where you can find them.” | Safety / emergency | DOE/BFP emergency guidance | Safe; operational advice |
| 11 | “Do not light flames, smoke, or switch electrical items on or off. If it is safe, close the cylinder valve. Open doors and windows, then leave. Call emergency services or your supplier from a safe distance.” | Safety / smell steps | DOE/BFP LPG emergency guidance | Verify; do not imply the sequence is always safe in every situation |
| 12 | “Cylinder stands upright and cannot tip over”, “connections checked with soapy water”, etc. | Safety checklist | Same as rows 5–10 | Safe as a customer checklist; add “follow your supplier’s instructions” |
| 13 | “Prices, sizes and availability vary by branch.” | FAQ / branches | Internal business fact | Safe only if true; confirm |
| 14 | “You can choose any active branch shown in the app.” | FAQ | System behaviour | True for the implemented app; confirm business policy |
| 15 | “Credit availability is up to the branch.” | FAQ | System/business policy | Confirm credit policy |
| 16 | “Dependable supply”, “careful service”, “safety first”, “practical tools” | Values | Aspirational brand values, not factual claims | Keep as values; do not turn into guarantees |
| 17 | “Responsible energy use”, “operational efficiency”, “practical innovation”, “environmental responsibility” | Sustainability | General principles; no quantified benefit | Safe as principles; do not add “cleaner than…” |
| 18 | “Live branch list from the API” | Our Branches | `GET /api/branches/public` | True; only active branches appear |

### Claims deliberately removed or avoided

- “Burns cleaner” / “cleaner-burning” / “eco-friendly” superiority.
- Any PETRONAS name, logo, colour scheme or affiliation implication.
- Any “No. 1”, “leading”, “largest”, “trusted by thousands” claim.
- Any certification (ISO, DOE accreditation, BFP permit) unless a certificate
  is current and displayed.
- Any guaranteed delivery time, 24/7 availability or nationwide coverage.
- Any savings percentage, price comparison or subsidy claim.
- Any health claim beyond basic safety guidance.
- Any customer testimonial or statistic that cannot be verified.

## 3. Official and recognised sources

Use the **landing page or search** below to find the current document; do not
publish a deep link until it has been opened and archived.

| Source | Domain | Used for | Notes |
|--------|--------|----------|-------|
| Department of Energy (DOE), Philippines | <https://doe.gov.ph/> | LPG safety tips, LPG industry rules, cylinder handling, refilling and retail guidance | Search “LPG safety”, “LPG industry”, “LPG refilling”. DOE materials are the primary Philippine reference for rows 5–11. |
| Bureau of Fire Protection (BFP) | <https://bfp.gov.ph/> | Fire safety, LPG storage and emergency guidance | Use current BFP fire safety bulletins; local fire code may be stricter. |
| DTI – Bureau of Philippine Standards (BPS) | <https://www.bps.dti.gov.ph/> | Philippine Standards for LPG cylinders, valves and regulators | For product/cylinder compliance statements only; do not claim certification without a certificate. |
| Department of Health (DOH) | <https://doh.gov.ph/> | Indoor air quality / carbon monoxide safety | Use for health-risk wording; avoid diagnosis or treatment claims. |
| World Health Organization (WHO) | <https://www.who.int/> | Household air pollution, ventilation and carbon monoxide | International reference; not a Philippine legal source. |
| National Privacy Commission (NPC) | <https://privacy.gov.ph/> | RA 10173, IRR, privacy notices, breach notification | For the Privacy Policy, not marketing. |
| Official Gazette | <https://www.officialgazette.gov.ph/> | RA 10173 and other Philippine statutes | Primary legal text. |
| NFPA | <https://www.nfpa.org/> | NFPA 58 Liquefied Petroleum Gas Code (industry reference) | Not Philippine law; use as technical context. |
| Cylinder/appliance manufacturer instructions | — | Installation, regulator, hose and appliance safety | Keep the exact model/manual on file. |

### Source review record

| Claim # | Source title | URL opened | Date accessed | Reviewer |
|---------|--------------|------------|---------------|----------|
| 5–11 | DOE LPG safety guidance | | | |
| 5–11 | BFP fire safety guidance | | | |
| 8–9 | WHO household air pollution | | | |
| 13–15 | Internal business policy | n/a | | |
| 1–4 | Internal product/service description | n/a | | |

Fill this table before launch. An empty row means the claim is **not yet
cleared**.

## 4. Brand and logo authorisation

The default configuration is:

```dotenv
VITE_BRAND_NAME=LPG Distributor
VITE_COMPANY_NAME=Your Company Name
```

To use a third-party brand:

1. Obtain written authorisation covering the exact mark, logo files, colours,
   territory, channels and duration.
2. Keep the signed approval on file.
3. Add the approved asset and update `frontend/src/site/content.js` or the
   `VITE_*` build variables.
4. Confirm the site does not imply endorsement, certification or affiliation
   beyond what was authorised.
5. Record the authorisation in the source review table.
6. Remove or replace the branding immediately if authorisation ends.

## 5. Sustainability and environmental wording

The current text avoids quantified environmental claims. If the business wants
to say more, require:

- a specific, current, verifiable source (government, peer-reviewed study, or
  certified calculator);
- the exact comparison baseline (compared with what?);
- the scope (per cylinder, per delivery, per year);
- legal review of the wording;
- evidence retained for as long as the claim is published.

Do not use “clean”, “green”, “eco-friendly”, “carbon-neutral” or “sustainable”
as a standalone product claim without substantiation.

## 6. Review sign-off

| Item | Name | Date |
|------|------|------|
| All public claims reviewed | | |
| Source URLs opened and archived | | |
| Brand/logo authorisation on file (or generic branding confirmed) | | |
| “Cleaner/greener” claims absent or substantiated | | |
| Legal review of claim wording | | |
| Site rebuilt and deployed after approval | | |

Until sign-off, treat the marketing copy as draft.
