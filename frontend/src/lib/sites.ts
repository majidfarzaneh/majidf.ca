// One build serves three sites, chosen by hostname:
//   majidf.local → landing · panel.majidf.local → client portal · admin.majidf.local → admin
// On localhost / an IP (no subdomains) everything lives on one host under /portal and /admin.

export type Site = 'main' | 'portal' | 'admin'

const { protocol, hostname, port } = window.location
const portSuffix = port ? `:${port}` : ''

export const splitHosts = hostname !== 'localhost' && !/^[\d.]+$/.test(hostname) && hostname.includes('.')
export const site: Site = !splitHosts ? 'main' : hostname.startsWith('panel.') ? 'portal' : hostname.startsWith('admin.') ? 'admin' : 'main'
const root = site === 'main' ? hostname.replace(/^www\./, '') : hostname.split('.').slice(1).join('.')

const origin = (sub: string) => `${protocol}//${sub ? sub + '.' : ''}${root}${portSuffix}`

function build(sub: 'panel' | 'admin', own: Site, prefix: string, path: string): string {
  if (!splitHosts) return prefix + path || prefix
  const p = path || '/'
  return site === own ? p : origin(sub) + p
}

/** Client portal path, e.g. P('/invoices'). Relative on its own site, absolute from elsewhere. */
export const P = (path = '') => build('panel', 'portal', '/portal', path)
/** Admin path, e.g. A('/clients/2'). */
export const A = (path = '') => build('admin', 'admin', '/admin', path)
/** Sign-in page: each subdomain has its own /login; single-host mode uses /portal/login. */
export const loginPath = () => (!splitHosts ? '/portal/login' : site === 'main' ? P('/login') : '/login')
/** The public landing page. */
export const landingPath = () => (splitHosts && site !== 'main' ? origin('') + '/' : '/')

export const isAbsolute = (url: string) => /^https?:\/\//.test(url)

/** Navigate within the SPA, or do a full page load when the target is on another subdomain. */
export function go(navigate: (to: string, opts?: { replace?: boolean }) => void, url: string, replace = false) {
  if (isAbsolute(url)) {
    if (replace) window.location.replace(url)
    else window.location.assign(url)
  } else {
    navigate(url, { replace })
  }
}
