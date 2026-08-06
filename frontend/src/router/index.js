import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCustomerAuthStore } from '@/stores/customerAuth'
import { resolveSlugFromHost } from '@/lib/tenantHost'
import { parseApiError } from '@/lib/errors'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import DashboardLayout from '../layouts/DashboardLayout.vue'
import CustomerLayout from '../layouts/CustomerLayout.vue'
import DashboardView from '../views/DashboardView.vue'
import BranchesView from '../views/BranchesView.vue'
import ServicesView from '../views/ServicesView.vue'
import StaffView from '../views/StaffView.vue'
import AppointmentsView from '../views/AppointmentsView.vue'
import CustomersView from '../views/CustomersView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/register',
      name: 'register',
      component: RegisterView,
    },
    {
      path: '/account/login',
      name: 'customer-login',
      component: () => import('@/views/CustomerLoginView.vue'),
    },
    {
      path: '/account',
      component: CustomerLayout,
      children: [
        {
          path: '',
          name: 'customer-dashboard',
          component: () => import('@/views/CustomerDashboardView.vue'),
          meta: { requiresCustomerAuth: true },
        },
      ],
    },
    {
      // Emailed-link landing pages. Reachable signed-out by design: the
      // link is usually opened in a browser with no session.
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('@/views/VerifyEmailView.vue'),
    },
    {
      // The salon's own shopfront: story, services, team, gallery, address.
      path: '/salon/:slug',
      name: 'salon-site',
      component: () => import('@/views/SalonSiteView.vue'),
    },
    {
      // Public, standalone customer booking page (no auth, no dashboard shell).
      path: '/book/:slug',
      name: 'public-booking',
      component: () => import('@/views/PublicBookingView.vue'),
    },
    {
      // Public self-service page to view / reschedule / cancel a booking.
      path: '/book/:slug/manage/:token',
      name: 'manage-booking',
      component: () => import('@/views/ManageBookingView.vue'),
    },
    {
      // On a salon subdomain, `/` is that salon's shopfront. On the apex it is
      // the public SaaS marketing home. Resolved lazily, at the moment the
      // route is entered: the host cannot change without a full page load, so
      // one answer per page view is all there is.
      //
      // Declared before the DashboardLayout record so bare `/` renders this,
      // not the authenticated shell.
      path: '/',
      name: 'landing',
      component: () =>
        resolveSlugFromHost() ? import('@/views/SalonSiteView.vue') : import('@/views/LandingView.vue'),
    },
    {
      path: '/terms',
      name: 'terms',
      component: () => import('@/views/legal/TermsView.vue'),
    },
    {
      path: '/privacy',
      name: 'privacy',
      component: () => import('@/views/legal/PrivacyView.vue'),
    },
    {
      path: '/refund',
      name: 'refund',
      component: () => import('@/views/legal/RefundView.vue'),
    },
    {
      // Authenticated app shell — every child renders inside DashboardLayout.
      path: '/',
      component: DashboardLayout,
      meta: { requiresAuth: true },
      children: [
        {
          path: 'dashboard',
          name: 'dashboard',
          component: DashboardView,
          meta: { requiresAuth: true },
        },
        {
          path: 'appointments',
          name: 'appointments',
          component: AppointmentsView,
          meta: { requiresAuth: true },
        },
        {
          path: 'calendar',
          name: 'calendar',
          component: () => import('@/views/CalendarView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'branches',
          name: 'branches',
          component: BranchesView,
          meta: { requiresAuth: true },
        },
        {
          path: 'services',
          name: 'services',
          component: ServicesView,
          meta: { requiresAuth: true },
        },
        {
          path: 'staff',
          name: 'staff',
          component: StaffView,
          meta: { requiresAuth: true },
        },
        {
          path: 'customers',
          name: 'customers',
          component: CustomersView,
          meta: { requiresAuth: true },
        },
        {
          path: 'reports',
          name: 'reports',
          component: () => import('@/views/ReportsView.vue'),
          meta: { requiresAuth: true, roles: ['owner', 'manager'] },
        },
        {
          path: 'reviews',
          name: 'reviews',
          component: () => import('@/views/ReviewsView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'gallery',
          name: 'gallery',
          component: () => import('@/views/GalleryView.vue'),
          meta: { requiresAuth: true, roles: ['owner', 'manager'] },
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
          meta: { requiresAuth: true, roles: ['owner'] },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  // Instantiate the store inside the guard — pinia is active by the time
  // navigation runs (main.js registers it before mounting the router).
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return '/login'
  }
  // A token in localStorage is not a live session. The organization behind it
  // may have been suspended, deactivated or removed since it was issued, and
  // /auth/me is the endpoint that says so (it carries the `tenant`
  // middleware). Resolve it once, for EVERY authenticated route rather than
  // only the role-gated ones, before the dashboard shell mounts — otherwise a
  // refused member is admitted to the shell and meets a 403 on each panel in
  // turn, which reads as "the app is broken" rather than "your salon is
  // suspended".
  if (to.meta.requiresAuth && !authStore.user) {
    try {
      await authStore.fetchMe()
    } catch (err) {
      // 401 is a plain expiry and the api interceptor already redirects; say
      // nothing extra about it. Anything else — 403 with a reason from
      // ResolveTenant, or a server fault — carries a message worth showing.
      const status = err?.response?.status
      authStore.endSession(
        status && status !== 401 ? parseApiError(err).message : ''
      )
      return '/login'
    }
  }
  // `meta.roles` mirrors the policy behind the page; no list means any
  // authenticated member may look.
  if (to.meta.roles && !to.meta.roles.includes(authStore.role)) {
    return '/dashboard'
  }
  // On the apex, `/` is the marketing home and a signed-in member belongs on
  // their dashboard. On a salon subdomain `/` is that salon's public
  // shopfront, so a member who happens to be signed in on that origin must
  // still see the shopfront rather than be bounced away from it.
  if (
    ((to.name === 'landing' && !resolveSlugFromHost()) ||
      to.path === '/login' ||
      to.path === '/register') &&
    authStore.isAuthenticated
  ) {
    return '/dashboard'
  }
  if (to.meta.requiresCustomerAuth) {
    const customerAuth = useCustomerAuthStore()
    if (!customerAuth.isAuthenticated) {
      return '/account/login'
    }
  }
  if (to.path === '/account/login' && useCustomerAuthStore().isAuthenticated) {
    return '/account'
  }
  return true
})

export default router
