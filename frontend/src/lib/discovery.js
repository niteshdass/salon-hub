import api from '@/lib/api'

/**
 * Salons a customer can book, across every tenant.
 *
 * The endpoint is public and cross-tenant, so no salon slug and no tenant host
 * are involved — unlike every other public call in this app.
 */
export async function searchSalons({ q = '', page = 1, city = '', service = '', sort = '' } = {}) {
  const { data } = await api.get('/discover/salons', {
    params: {
      q: q || undefined,
      page,
      city: city || undefined,
      service: service || undefined,
      sort: sort || undefined,
    },
  })

  return { data: data.data, meta: data.meta }
}
