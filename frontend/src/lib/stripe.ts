// Shared Stripe.js setup for the portal's payment and saved-card forms.
import { loadStripe, type Appearance, type Stripe } from '@stripe/stripe-js'

const stripeCache = new Map<string, Promise<Stripe | null>>()
export function getStripe(key: string) {
  if (!stripeCache.has(key)) stripeCache.set(key, loadStripe(key))
  return stripeCache.get(key)!
}

export const appearance: Appearance = {
  theme: 'night',
  variables: {
    colorPrimary: '#0072ee',
    colorBackground: '#09090b',
    colorText: '#fafafa',
    colorTextSecondary: '#a1a1aa',
    colorTextPlaceholder: '#52525b',
    colorDanger: '#e86466',
    fontFamily: "'Helvetica Neue', Helvetica, Arial, sans-serif",
    borderRadius: '4px',
    fontSizeBase: '14px',
  },
  rules: {
    '.Input': { border: '1px solid #27272a', boxShadow: 'none' },
    '.Input:focus': { borderColor: '#0072ee', boxShadow: '0 0 0 3px rgba(0,114,238,.22)' },
    '.Label': { textTransform: 'uppercase', fontSize: '11px', letterSpacing: '.06em', color: '#71717a' },
    '.Tab': { border: '1px solid #27272a', backgroundColor: '#09090b', boxShadow: 'none' },
    '.Tab--selected': { borderColor: '#0072ee' },
  },
}
