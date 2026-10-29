import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { A, isAbsolute, loginPath, P } from './sites'
import { Loading } from '../components/ui'
import { ApiError, api } from './api'
import type { Me, Role } from './types'

export const ME_KEY = ['me'] as const

/** The signed-in user, or null for guests. */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async (): Promise<Me | null> => {
      try {
        return await api.get<Me>('/me')
      } catch (err) {
        if (err instanceof ApiError && err.status === 401) return null
        throw err
      }
    },
    staleTime: 5 * 60_000,
  })
}

export function useLogout() {
  const qc = useQueryClient()
  return async () => {
    await api.post('/auth/logout').catch(() => undefined)
    qc.clear()
    qc.setQueryData(ME_KEY, null)
  }
}

export function homeFor(role: Role) {
  return role === 'admin' ? A() : P()
}

/** Gate a route tree on being signed in with the given role. */
export function RequireRole({ role, children }: { role: Role; children: ReactNode }) {
  const { data, isLoading } = useMe()
  const location = useLocation()

  if (isLoading) return <Loading full />
  if (!data) return <Navigate to={loginPath()} replace state={{ from: location.pathname + location.search }} />
  if (data.user.role !== role) return <Redirect to={homeFor(data.user.role)} />
  return <>{children}</>
}

/** <Navigate>, but also handles targets on another subdomain. */
export function Redirect({ to }: { to: string }) {
  if (isAbsolute(to)) {
    window.location.replace(to)
    return <Loading full />
  }
  return <Navigate to={to} replace />
}
