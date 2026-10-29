import { lazy, Suspense } from 'react'
import { BrowserRouter, Link, Route, Routes, useLocation } from 'react-router'
import { Loading } from './components/ui'
import { Redirect, RequireRole } from './lib/auth'
import { A, landingPath, P, site, splitHosts } from './lib/sites'
import Landing from './pages/Landing'

const Login = lazy(() => import('./portal/Login'))
const PortalLayout = lazy(() => import('./portal/PortalLayout'))
const Dashboard = lazy(() => import('./portal/Dashboard'))
const Services = lazy(() => import('./portal/Services'))
const Invoices = lazy(() => import('./portal/Invoices'))
const Tickets = lazy(() => import('./portal/Tickets'))
const NewProject = lazy(() => import('./portal/NewProject'))
const Profile = lazy(() => import('./portal/Profile'))

const AdminLayout = lazy(() => import('./admin/AdminLayout'))
const AdminDashboard = lazy(() => import('./admin/AdminDashboard'))
const Clients = lazy(() => import('./admin/Clients'))
const ClientDetail = lazy(() => import('./admin/ClientDetail'))
const AdminServices = lazy(() => import('./admin/Services'))
const AdminInvoices = lazy(() => import('./admin/Invoices'))
const InvoiceEditor = lazy(() => import('./admin/InvoiceEditor'))
const InvoiceDetail = lazy(() => import('./admin/InvoiceDetail'))
const Payments = lazy(() => import('./admin/Payments'))
const AdminTickets = lazy(() => import('./admin/Tickets'))
const Requests = lazy(() => import('./admin/Requests'))

function portalRoutes(base: string) {
  return (
    <Route path={base} element={<RequireRole role="client"><PortalLayout /></RequireRole>}>
      <Route index element={<Dashboard />} />
      <Route path="services" element={<Services />} />
      <Route path="invoices" element={<Invoices />} />
      <Route path="tickets/:number?" element={<Tickets />} />
      <Route path="request" element={<NewProject />} />
      <Route path="profile" element={<Profile />} />
    </Route>
  )
}

function adminRoutes(base: string) {
  return (
    <Route path={base} element={<RequireRole role="admin"><AdminLayout /></RequireRole>}>
      <Route index element={<AdminDashboard />} />
      <Route path="clients" element={<Clients />} />
      <Route path="clients/:id" element={<ClientDetail />} />
      <Route path="services" element={<AdminServices />} />
      <Route path="invoices" element={<AdminInvoices />} />
      <Route path="invoices/new" element={<InvoiceEditor />} />
      <Route path="invoices/:id" element={<InvoiceDetail />} />
      <Route path="invoices/:id/edit" element={<InvoiceEditor />} />
      <Route path="payments" element={<Payments />} />
      <Route path="tickets/:id?" element={<AdminTickets />} />
      <Route path="requests" element={<Requests />} />
    </Route>
  )
}

/** Routes for the site this hostname serves (see lib/sites.ts). */
function SiteRoutes() {
  if (splitHosts && site === 'portal') {
    return <Routes><Route path="/login" element={<Login />} />{portalRoutes('/')}<Route path="*" element={<NotFound />} /></Routes>
  }
  if (splitHosts && site === 'admin') {
    return <Routes><Route path="/login" element={<Login />} />{adminRoutes('/')}<Route path="*" element={<NotFound />} /></Routes>
  }
  if (splitHosts) {
    // Old /portal and /admin links on the main domain move to their subdomains.
    return (
      <Routes>
        <Route path="/" element={<Landing />} />
        <Route path="/portal/*" element={<MoveTo url={P} prefix="/portal" />} />
        <Route path="/admin/*" element={<MoveTo url={A} prefix="/admin" />} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    )
  }
  return (
    <Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/portal/login" element={<Login />} />
      {portalRoutes('/portal')}
      {adminRoutes('/admin')}
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}

function MoveTo({ url, prefix }: { url: (path: string) => string; prefix: string }) {
  const { pathname, search } = useLocation()
  return <Redirect to={url(pathname.slice(prefix.length)) + search} />
}

export default function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<Loading full />}>
        <SiteRoutes />
      </Suspense>
    </BrowserRouter>
  )
}

function NotFound() {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24, textAlign: 'center' }}>
      <span className="mono dim" style={{ fontSize: 12 }}>// 404</span>
      <h1 className="display" style={{ margin: 0, fontWeight: 800, fontSize: 40 }}>Nothing here.</h1>
      <Link to={landingPath()} className="btn btn-ghost">Back home</Link>
    </div>
  )
}
