import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import DashboardLayout from '../layouts/DashboardLayout.vue'
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
      // Authenticated app shell — every child renders inside DashboardLayout.
      path: '/',
      component: DashboardLayout,
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: '/dashboard' },
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
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
          meta: { requiresAuth: true, ownerOnly: true },
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
  if (to.meta.ownerOnly) {
    // On a hard refresh the role is not loaded yet; fetch it before
    // deciding, otherwise an owner would bounce off their own page.
    if (!authStore.user) {
      try {
        await authStore.fetchMe()
      } catch {
        return '/login'
      }
    }
    if (!authStore.isOwner) {
      return '/dashboard'
    }
  }
  if ((to.path === '/login' || to.path === '/register') && authStore.isAuthenticated) {
    return '/dashboard'
  }
  return true
})

export default router
