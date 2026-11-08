import {
  Home, Store, Factory, Container, ChefHat, UtensilsCrossed,
  ShieldCheck, Hand, Warehouse, Droplets, Wind, ShieldAlert,
  Leaf, Gauge, Lightbulb, Recycle, Award, Sparkles, Zap,
  UserPlus, ShoppingCart, BadgeCheck, Truck,
} from 'lucide-react'

// ---------------------------------------------------------------------------
// Brand configuration
//
// These values are build-time defaults. Set real, authorized values with Vite
// environment variables (see docs/configuration.md) or edit this object before
// building. Do NOT put an unverified third-party trademark, certification,
// price, phone number, address or statistic here.
// ---------------------------------------------------------------------------
const env = (import.meta && import.meta.env) || {}

const value = (key, fallback = '') => {
  const v = env[key]
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : fallback
}

export const SITE = {
  brand: value('VITE_BRAND_NAME', 'LPG Distributor'),
  owner: value('VITE_COMPANY_NAME', 'Your Company Name'),
  tagline: value('VITE_BRAND_TAGLINE', 'LPG supply for homes and businesses.'),
  heroImage: value('VITE_HERO_IMAGE', ''),
  aboutImage: value('VITE_ABOUT_IMAGE', ''),
  contact: {
    phone: value('VITE_CONTACT_PHONE', ''),
    email: value('VITE_CONTACT_EMAIL', ''),
    address: value('VITE_CONTACT_ADDRESS', ''),
    hours: value('VITE_CONTACT_HOURS', ''),
  },
  social: {
    Facebook: value('VITE_SOCIAL_FACEBOOK', ''),
    Instagram: value('VITE_SOCIAL_INSTAGRAM', ''),
    YouTube: value('VITE_SOCIAL_YOUTUBE', ''),
  },
  // Legal pages are templates until a Philippine lawyer reviews them.
  legal: {
    version: value('VITE_LEGAL_VERSION', 'Draft 1'),
    effective: value('VITE_LEGAL_EFFECTIVE', 'To be set before launch'),
    privacyContact: value('VITE_PRIVACY_CONTACT', value('VITE_CONTACT_EMAIL', '')),
  },
}

export const NAV = [
  ['Home', '#home'], ['About Us', '#about'], ['Our Products', '#products'],
  ['Our Branches', '#branches'], ['Applications', '#applications'], ['Safety', '#safety'],
  ['Sustainability', '#sustainability'], ['Contact Us', '#contact'],
]

export const VALUES = [
  { icon: Award, t: 'Dependable supply', d: 'Clear stock records and branch-level accountability help keep supply predictable.' },
  { icon: Sparkles, t: 'Careful service', d: 'Order details, delivery records and balances are kept in one place for customers and staff.' },
  { icon: ShieldCheck, t: 'Safety first', d: 'Safe handling and safe use come first, always. Follow your supplier and the cylinder label.' },
  { icon: Zap, t: 'Practical tools', d: 'Simple digital records replace guesswork in ordering, delivery and stock control.' },
]

// img: optional photo path, e.g. '/images/household.jpg'
export const PRODUCTS = [
  { icon: Home, img: '', t: 'Household LPG', d: 'LPG for everyday cooking and water heating at home.',
    b: ['Convenient for daily cooking', 'Works with common household appliances', 'Ask your branch about available cylinder sizes'] },
  { icon: Store, img: '', t: 'Commercial LPG', d: 'LPG supply for kitchens and businesses that run on demand.',
    b: ['Suited to eateries, cafes, and shops', 'Plan regular deliveries with your branch', 'Scales with your daily demand'] },
  { icon: Factory, img: '', t: 'Industrial LPG', d: 'LPG for heating, process, and production needs.',
    b: ['Useful for heating and process uses', 'Adaptable to different operations', 'Discuss your requirements with your branch'] },
  { icon: Container, img: '', t: 'Bulk LPG Solutions', d: 'Larger-scale supply arrangements for high-demand sites.',
    b: ['Designed for higher-volume users', 'Fewer cylinder changeovers', 'Arranged through your supplier'] },
]

export const APPS = [
  { icon: ChefHat, t: 'Residential cooking', d: 'Everyday meals, from simple stovetop cooking to family gatherings.' },
  { icon: UtensilsCrossed, t: 'Restaurants and hospitality', d: 'Responsive heat for kitchens in restaurants, hotels, and catering.' },
  { icon: Store, t: 'Commercial establishments', d: 'Fuel for bakeries, laundries, and small businesses.' },
  { icon: Factory, t: 'Industrial applications', d: 'Heating, drying, and process uses across many industries.' },
]

export const SAFETY = [
  { icon: Hand, t: 'Handle cylinders with care',
    d: 'Keep cylinders upright. Lift them, never drag, roll, or drop them. Check the seal and valve before connecting, and have connections made by a trained person.' },
  { icon: Warehouse, t: 'Store them safely',
    d: 'Store upright in a well-ventilated area, away from heat, direct sunlight, and ignition sources. Never keep cylinders in basements or enclosed spaces.' },
  { icon: Droplets, t: 'Know how to spot a leak',
    d: 'LPG has a strong added odor. Brush soapy water on joints: bubbles show a leak. Never test for leaks with a flame.' },
  { icon: Wind, t: 'Keep the air moving',
    d: 'Use LPG appliances only in well-ventilated spaces. Poor ventilation lets gas build up and can produce carbon monoxide.' },
  { icon: ShieldAlert, t: 'Be ready for emergencies',
    d: 'Know where the cylinder valve is and how to close it. Keep your supplier and local emergency numbers where you can find them.' },
]

export const SMELL_STEPS = [
  'Do not light flames, smoke, or switch electrical items on or off.',
  'If it is safe to do so, close the cylinder valve.',
  'Open doors and windows, then leave the area.',
  'Call your local emergency number or your supplier from a safe distance.',
]

export const PILLARS = [
  { icon: Recycle, t: 'Responsible energy use', d: 'Use only what you need, keep appliances and connections well maintained, and choose the right cylinder size.' },
  { icon: Gauge, t: 'Operational efficiency', d: 'Careful delivery and stock management reduces waste and keeps supply dependable.' },
  { icon: Lightbulb, t: 'Practical innovation', d: 'Better tools, safer practices, and clearer records shape how energy reaches people.' },
  { icon: Leaf, t: 'Environmental responsibility', d: 'Responsible handling and maintenance help protect people, property, and surroundings.' },
]

export const MARQUEE = ['Dependable', 'Careful', 'Practical', 'Safety-first', 'Transparent', 'Responsible']

export const FIND = [
  { who: 'I cook at home', icon: Home, t: 'Household LPG',
    pitch: 'Everyday cooking and water heating, kept simple.',
    b: ['Convenient for daily meals', 'Works with common household appliances', 'Ask your branch about the right cylinder size'] },
  { who: 'I run a food business', icon: UtensilsCrossed, t: 'Commercial LPG',
    pitch: 'Responsive heat for kitchens that cannot afford to stop.',
    b: ['Suited to eateries, cafes, and bakeries', 'Supports busy daily demand', 'Plan regular deliveries with your branch'] },
  { who: 'I run a plant or workshop', icon: Factory, t: 'Industrial LPG',
    pitch: 'LPG for heating and process needs.',
    b: ['Adaptable to different operations', 'Useful for heating and process uses', 'Discuss your requirements with your branch'] },
  { who: 'I need larger volumes', icon: Container, t: 'Bulk LPG Solutions',
    pitch: 'Supply arrangements built for higher-demand sites.',
    b: ['Designed for higher-volume users', 'Fewer cylinder changeovers', 'Arranged directly with your supplier'] },
]

export const STEPS = [
  { icon: UserPlus, t: 'Create your account', d: 'Sign up with your name, mobile number, and delivery address. It takes about a minute.' },
  { icon: ShoppingCart, t: 'Place your order', d: 'Choose a branch, cylinder size and quantity, then pick cash on delivery or credit where available.' },
  { icon: BadgeCheck, t: 'The branch confirms it', d: 'Branch staff review the order and approve it, or contact you if something needs sorting out.' },
  { icon: Truck, t: 'Delivered to your door', d: 'Once delivered, your account shows what you have paid, what you owe, and any empty cylinders to return.' },
]

export const CHECKS = [
  'Cylinder stands upright and cannot tip over',
  'Stored in a well-ventilated area, away from heat and flames',
  'Connections checked with soapy water (no bubbles)',
  'Hose and regulator look undamaged',
  'I know where the cylinder valve is and how to close it',
  'My supplier and emergency numbers are saved',
]

export const FAQ = [
  ['How do I place an order?', 'Create an account, choose a branch, cylinder size and quantity, and send your order. The branch confirms it and arranges delivery.'],
  ['Can I order from another branch?', 'You can choose any active branch shown in the app. Availability, prices and delivery areas can differ between branches.'],
  ['Can I pay on credit?', 'You can choose credit when you order. Whether credit is available for your account is up to the branch, who will confirm it.'],
  ['Which cylinder size should I choose?', 'It depends on how much you cook or use. Your branch can advise you. Sizes and availability vary by location.'],
  ['Where should I keep my cylinder?', 'Keep it upright in a well-ventilated area, away from heat, direct sunlight, and ignition sources. Never store cylinders in basements or enclosed spaces.'],
  ['What should I do if I smell gas?', 'Do not light flames or use electrical switches. If it is safe, close the cylinder valve, open doors and windows, leave the area, and call your supplier or local emergency number from a safe distance.'],
]

export const BRANCH_EMPTY = 'Published branch details will appear here once they are available.'
