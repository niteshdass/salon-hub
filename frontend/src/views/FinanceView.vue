<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import { monthOptions, payTypeLabel } from '@/lib/payroll'
import Modal from '@/components/Modal.vue'
import PageHeader from '@/components/PageHeader.vue'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const TABS = [
  { key: 'payroll', label: 'Payroll' },
  { key: 'expenses', label: 'Expenses' },
  { key: 'profit', label: 'Profit' },
]
const tab = ref('payroll')

// One banner per tab. A single shared ref meant whichever load finished last
// owned it: onMounted runs loadRuns() then loadExpenses(), and the second
// call's reset wiped a payroll failure before anyone could read it.
const tabError = reactive({ payroll: '', expenses: '', profit: '' })
const error = computed(() => tabError[tab.value])

const runs = ref([])
const activeRun = ref(null)
const loading = ref(false)
// Covers all three payroll mutations at once: while any is in flight none of
// the others should be clickable either.
const saving = ref(false)
const months = monthOptions(12)
const selectedMonth = ref(months[0].value)

function money(value) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value })
    .format(Number(value || 0))
}

async function loadRuns() {
  loading.value = true
  tabError.payroll = ''
  try {
    const { data } = await api.get('/payroll/runs')
    runs.value = data.data
    if (runs.value.length && !activeRun.value) await openRun(runs.value[0].id)
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not load payroll.').message
  } finally {
    loading.value = false
  }
}

async function openRun(id) {
  tabError.payroll = ''
  try {
    const { data } = await api.get(`/payroll/runs/${id}`)
    activeRun.value = data.data
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not load this payroll run.').message
  }
}

async function createRun() {
  if (saving.value) return
  saving.value = true
  tabError.payroll = ''
  try {
    const { data } = await api.post('/payroll/runs', { period_month: selectedMonth.value })
    activeRun.value = data.data
    await loadRuns()
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not open payroll.').message
  } finally {
    saving.value = false
  }
}

// Saves one edited amount and refreshes the run so the header total matches.
async function saveLine(line, field, value) {
  tabError.payroll = ''
  try {
    await api.patch(`/payroll/runs/${activeRun.value.id}/lines/${line.id}`, { [field]: Number(value || 0) })
    await openRun(activeRun.value.id)
    await loadRuns()
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not save that amount.').message
  }
}

async function finalizeRun() {
  if (saving.value) return
  if (!window.confirm(`Finalize ${activeRun.value.period_label} for ${money(activeRun.value.total_amount)}? This locks the run and books it as an expense.`)) return
  saving.value = true
  tabError.payroll = ''
  try {
    const { data } = await api.post(`/payroll/runs/${activeRun.value.id}/finalize`)
    activeRun.value = data.data
    await loadRuns()
    // The run just booked a salary expense, which moves both the log and the
    // net profit. Neither may keep showing the pre-finalize picture.
    await refreshMoneyViews()
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not finalize this run.').message
  } finally {
    saving.value = false
  }
}

async function deleteRun() {
  if (saving.value) return
  if (!window.confirm(`Delete payroll for ${activeRun.value.period_label}? Its salary expense goes with it.`)) return
  saving.value = true
  tabError.payroll = ''
  try {
    await api.delete(`/payroll/runs/${activeRun.value.id}`)
    activeRun.value = null
    await loadRuns()
    await refreshMoneyViews()
  } catch (e) {
    tabError.payroll = parseApiError(e, 'Could not delete this run.').message
  } finally {
    saving.value = false
  }
}

const EXPENSE_CATEGORIES = [
  'rent', 'utilities', 'supplies', 'salary', 'marketing', 'equipment', 'maintenance', 'other',
]

// `salary` is payroll's reserved category: a finalized run books one, and the
// expense's payroll_run_id is what keeps the P&L from counting staff pay
// twice. An owner hand-logging "Salaries — July" beside that run defeats it,
// so salary can be filtered for but not entered.
const LOGGABLE_CATEGORIES = EXPENSE_CATEGORIES.filter((c) => c !== 'salary')

const expenses = ref([])
const expenseFilters = reactive({ from: '', to: '', category: '' })
const expenseModalOpen = ref(false)
const editingExpenseId = ref(null)
const expenseForm = reactive({ category: 'supplies', expense_date: '', amount: '', note: '' })
const expenseErrors = ref({})
const savingExpense = ref(false)

// A row logged as `salary` before that category was withdrawn stays editable
// rather than having its category silently blanked when the modal opens.
const modalCategories = computed(() =>
  LOGGABLE_CATEGORIES.includes(expenseForm.category)
    ? LOGGABLE_CATEGORIES
    : [...LOGGABLE_CATEGORIES, expenseForm.category]
)

function startOfMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

async function loadExpenses() {
  tabError.expenses = ''
  try {
    const { data } = await api.get('/expenses', {
      params: {
        from: expenseFilters.from || startOfMonth(),
        to: expenseFilters.to || today(),
        category: expenseFilters.category || undefined,
      },
    })
    expenses.value = data.data
  } catch (e) {
    tabError.expenses = parseApiError(e, 'Could not load expenses.').message
  }
}

/**
 * Anything that moves money re-reads the log and throws away the cached
 * profit, so the Profit tab recomputes on its next visit instead of showing
 * the figure from before the change.
 */
async function refreshMoneyViews() {
  profit.value = null
  await loadExpenses()
}

function openExpenseModal(expense = null) {
  expenseErrors.value = {}
  // A leftover banner from an earlier failure has nothing to say about the
  // form now on screen.
  tabError.expenses = ''
  editingExpenseId.value = expense?.id ?? null
  Object.assign(expenseForm, {
    category: expense?.category ?? 'supplies',
    expense_date: expense?.expense_date ?? today(),
    amount: expense?.amount ?? '',
    note: expense?.note ?? '',
  })
  expenseModalOpen.value = true
}

async function saveExpense() {
  if (savingExpense.value) return
  savingExpense.value = true
  expenseErrors.value = {}
  const payload = {
    category: expenseForm.category,
    expense_date: expenseForm.expense_date,
    amount: Number(expenseForm.amount || 0),
    note: expenseForm.note || null,
  }
  try {
    if (editingExpenseId.value) {
      await api.patch(`/expenses/${editingExpenseId.value}`, payload)
    } else {
      await api.post('/expenses', payload)
    }
    expenseModalOpen.value = false
    await refreshMoneyViews()
  } catch (e) {
    // A 422 carries per-field errors; anything else (including the "this came
    // from payroll" refusal) only has a sentence, so show it in the banner.
    const parsed = parseApiError(e, 'Could not save this expense.')
    expenseErrors.value = parsed.errors
    if (!Object.keys(parsed.errors).length) tabError.expenses = parsed.message
  } finally {
    savingExpense.value = false
  }
}

async function deleteExpense(expense) {
  if (!window.confirm('Delete this expense?')) return
  try {
    await api.delete(`/expenses/${expense.id}`)
    await refreshMoneyViews()
  } catch (e) {
    tabError.expenses = parseApiError(e, 'Could not delete this expense.').message
  }
}

/** From a locked expense row to the run that owns it. */
async function openRunFromExpense(expense) {
  if (!expense.payroll_run_id) return
  tab.value = 'payroll'
  await openRun(expense.payroll_run_id)
}

const expenseTotal = computed(() =>
  expenses.value.reduce((sum, row) => sum + Number(row.amount || 0), 0)
)

const profit = ref(null)
const profitRange = reactive({ from: startOfMonth(), to: today() })

async function loadProfit() {
  tabError.profit = ''
  try {
    const { data } = await api.get('/reports', { params: { from: profitRange.from, to: profitRange.to } })
    profit.value = data.data.profit
  } catch (e) {
    tabError.profit = parseApiError(e, 'Could not load profit.').message
  }
}

// Load it when the tab is first opened rather than on mount — the reports
// endpoint is the heaviest call on this screen. refreshMoneyViews() nulls the
// cache whenever a cost changes, so this re-runs instead of showing a figure
// that predates the change.
watch(tab, (value) => {
  if (value === 'profit' && !profit.value) loadProfit()
})

// Each tab covers a different window — the profit range, the expense filters,
// or a month picked from a select — so one sentence cannot describe all three.
// The header follows the tab rather than naming a period the figures below it
// do not cover.
const subtitle = computed(() => {
  if (tab.value === 'expenses') {
    // The same fallbacks loadExpenses() sends, so the sentence can never
    // disagree with the request it describes.
    return `Costs logged from ${expenseFilters.from || startOfMonth()} to ${expenseFilters.to || today()}.`
  }
  if (tab.value === 'profit') {
    return `Staff pay, costs, and profit from ${profitRange.from} to ${profitRange.to}.`
  }
  // Payroll's window is the month selected below, and it is named there — a
  // date range in the header would only ever be a second, staler answer.
  return 'Staff pay for the month you select below.'
})

onMounted(async () => {
  await loadRuns()
  await loadExpenses()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Finance" :subtitle="subtitle" />

    <div class="flex gap-1 border-b border-ink/10">
      <button
        v-for="item in TABS"
        :key="item.key"
        class="border-b-2 px-4 py-2 text-sm font-medium transition"
        :class="tab === item.key ? 'border-accent-500 text-ink' : 'border-transparent text-ink/55 hover:text-ink'"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">{{ error }}</p>

    <section v-if="tab === 'payroll'" class="space-y-4">
      <div class="sh-card flex flex-wrap items-end gap-3 p-4">
        <div class="w-52">
          <label class="sh-label">Month</label>
          <select v-model="selectedMonth" class="sh-input">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <button :disabled="saving" class="sh-btn sh-btn-primary" @click="createRun">
          Open payroll
        </button>
      </div>

      <div v-if="runs.length" class="flex flex-wrap gap-2">
        <button
          v-for="run in runs"
          :key="run.id"
          class="rounded-full border px-3 py-1 text-sm transition"
          :class="activeRun?.id === run.id
            ? 'border-accent-500 bg-accent-50 text-accent-700'
            : 'border-ink/15 text-ink/60 hover:bg-paper'"
          @click="openRun(run.id)"
        >
          {{ run.period_label }}
          <span v-if="run.status === 'finalized'" class="ml-1 text-xs text-emerald-600">✓</span>
        </button>
      </div>

      <p v-if="loading" class="text-sm text-ink/60">Loading…</p>
      <p v-else-if="!runs.length" class="sh-empty">
        No payroll yet. Pick a month and open it.
      </p>

      <div v-if="activeRun" class="space-y-3">
        <div class="sh-card flex flex-wrap items-center justify-between gap-3 p-4">
          <div>
            <p class="font-display text-lg text-ink">{{ activeRun.period_label }}</p>
            <p class="text-xs text-ink/50">
              <span v-if="activeRun.status === 'finalized'">Finalized {{ new Date(activeRun.finalized_at).toLocaleDateString() }}</span>
              <span v-else>Draft — amounts can still be edited</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <span class="font-display text-2xl text-ink">{{ money(activeRun.total_amount) }}</span>
            <button
              v-if="activeRun.status === 'draft'"
              :disabled="saving"
              class="sh-btn sh-btn-primary"
              @click="finalizeRun"
            >
              Finalize
            </button>
            <button
              :disabled="saving"
              class="sh-btn text-rose-600 hover:bg-rose-50"
              @click="deleteRun"
            >
              Delete
            </button>
          </div>
        </div>

        <!-- Eight columns and two editable amounts do not fit a phone, so below
             md the table is replaced by a stacked card list rather than left to
             scroll sideways behind an overlay scrollbar that renders no
             affordance. Every field and every control appears in both branches. -->
        <div class="sh-card hidden overflow-x-auto md:block">
          <table class="sh-table">
            <thead>
              <tr>
                <th>Staff</th>
                <th>Rule</th>
                <th class="text-right">Bookings</th>
                <th class="text-right">Earned</th>
                <th class="text-right">Salary</th>
                <th class="text-right">Commission</th>
                <th class="text-right">Tips</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in activeRun.lines" :key="line.id">
                <td class="font-medium text-ink">{{ line.staff_name }}</td>
                <td class="text-ink/60">
                  {{ payTypeLabel(line.pay_type) }}
                  <span v-if="Number(line.commission_rate) > 0" class="text-xs">({{ line.commission_rate }}%)</span>
                </td>
                <td class="text-right">{{ line.bookings }}</td>
                <td class="text-right">{{ money(line.earned_revenue) }}</td>
                <td class="text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.salary_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="sh-input w-24 px-2 py-1 text-right"
                    @change="saveLine(line, 'salary_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.salary_amount) }}</span>
                </td>
                <td class="text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.commission_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="sh-input w-24 px-2 py-1 text-right"
                    @change="saveLine(line, 'commission_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.commission_amount) }}</span>
                </td>
                <!-- Recorded at the counter, not edited here: a tip is 100% the staff
                     member's and never enters the commission base. -->
                <td class="text-right">{{ money(line.tips_amount) }}</td>
                <td class="text-right font-semibold text-ink">{{ money(line.total_amount) }}</td>
              </tr>
              <tr v-if="!activeRun.lines.length">
                <td colspan="8" class="py-6 text-center text-ink/60">
                  No staff have a pay rule yet. Set one on the Staff page.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Same lines, same saveLine() calls, stacked so nothing sits off the
             right edge of a 390px viewport. -->
        <div class="space-y-3 md:hidden">
          <div v-for="line in activeRun.lines" :key="line.id" class="sh-card p-5">
            <p class="font-medium text-ink">{{ line.staff_name }}</p>
            <p class="text-sm text-ink/60">
              {{ payTypeLabel(line.pay_type) }}
              <span v-if="Number(line.commission_rate) > 0" class="text-xs">({{ line.commission_rate }}%)</span>
            </p>

            <dl class="mt-3 space-y-2 text-sm">
              <div class="flex items-center justify-between gap-3">
                <dt class="text-ink/40">Bookings</dt>
                <dd class="text-ink">{{ line.bookings }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3">
                <dt class="text-ink/40">Earned</dt>
                <dd class="text-ink">{{ money(line.earned_revenue) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3">
                <dt class="text-ink/40">Salary</dt>
                <dd>
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.salary_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="sh-input w-28 px-2 py-1 text-right"
                    @change="saveLine(line, 'salary_amount', $event.target.value)"
                  />
                  <span v-else class="text-ink">{{ money(line.salary_amount) }}</span>
                </dd>
              </div>
              <div class="flex items-center justify-between gap-3">
                <dt class="text-ink/40">Commission</dt>
                <dd>
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.commission_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="sh-input w-28 px-2 py-1 text-right"
                    @change="saveLine(line, 'commission_amount', $event.target.value)"
                  />
                  <span v-else class="text-ink">{{ money(line.commission_amount) }}</span>
                </dd>
              </div>
              <div class="flex items-center justify-between gap-3">
                <dt class="text-ink/40">Tips</dt>
                <dd class="text-ink">{{ money(line.tips_amount) }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3 border-t border-ink/10 pt-2">
                <dt class="text-ink/40">Total</dt>
                <dd class="font-semibold text-ink">{{ money(line.total_amount) }}</dd>
              </div>
            </dl>
          </div>

          <p v-if="!activeRun.lines.length" class="sh-empty">
            No staff have a pay rule yet. Set one on the Staff page.
          </p>
        </div>
      </div>
    </section>

    <section v-if="tab === 'expenses'" class="space-y-4">
      <div class="sh-card flex flex-wrap items-end gap-3 p-4">
        <div class="w-44">
          <label class="sh-label">From</label>
          <input v-model="expenseFilters.from" type="date" class="sh-input" @change="loadExpenses" />
        </div>
        <div class="w-44">
          <label class="sh-label">To</label>
          <input v-model="expenseFilters.to" type="date" class="sh-input" @change="loadExpenses" />
        </div>
        <div class="w-44">
          <label class="sh-label">Category</label>
          <select v-model="expenseFilters.category" class="sh-input" @change="loadExpenses">
            <option value="">All</option>
            <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <button class="sh-btn sh-btn-primary" @click="openExpenseModal()">
          Add expense
        </button>
      </div>

      <!-- Five columns and the row actions do not fit a phone, so below md the
           table is replaced by a stacked card list rather than left to scroll
           sideways behind an overlay scrollbar that renders no affordance.
           Every field and every control appears in both branches. -->
      <template v-if="expenses.length">
        <div class="sh-card hidden overflow-x-auto md:block">
          <table class="sh-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Note</th>
                <th class="text-right">Amount</th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="expense in expenses" :key="expense.id">
                <td class="whitespace-nowrap">{{ expense.expense_date }}</td>
                <td class="capitalize">{{ expense.category }}</td>
                <td class="text-ink/60">{{ expense.note || '—' }}</td>
                <td class="text-right">{{ money(expense.amount) }}</td>
                <td class="text-right whitespace-nowrap">
                  <button
                    v-if="expense.is_locked"
                    class="sh-btn sh-btn-ghost px-2.5 py-1 text-xs"
                    title="Open the payroll run that booked this expense"
                    @click="openRunFromExpense(expense)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                      <path
                        fill-rule="evenodd"
                        d="M10 1a4 4 0 0 0-4 4v3H5.5A1.5 1.5 0 0 0 4 9.5v7A1.5 1.5 0 0 0 5.5 18h9a1.5 1.5 0 0 0 1.5-1.5v-7A1.5 1.5 0 0 0 14.5 8H14V5a4 4 0 0 0-4-4Zm2.5 7V5a2.5 2.5 0 0 0-5 0v3h5Z"
                        clip-rule="evenodd"
                      />
                    </svg>
                    From payroll
                  </button>
                  <span v-else class="inline-flex items-center gap-1">
                    <button class="sh-btn px-2.5 py-1 text-xs" @click="openExpenseModal(expense)">Edit</button>
                    <button
                      class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                      @click="deleteExpense(expense)"
                    >
                      Delete
                    </button>
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="px-4 py-3 text-right text-sm font-medium text-ink/60">Total</td>
                <td class="px-4 py-3 text-right text-sm font-semibold text-ink">{{ money(expenseTotal) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Same rows, same handlers, stacked for a 390px viewport. -->
        <div class="space-y-3 md:hidden">
          <div v-for="expense in expenses" :key="expense.id" class="sh-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-medium capitalize text-ink">{{ expense.category }}</p>
                <p class="text-xs text-ink/50">{{ expense.expense_date }}</p>
              </div>
              <p class="font-display text-2xl text-ink">{{ money(expense.amount) }}</p>
            </div>

            <p class="mt-2 text-sm text-ink/60">{{ expense.note || '—' }}</p>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-1 border-t border-ink/10 pt-4">
              <button
                v-if="expense.is_locked"
                class="sh-btn sh-btn-ghost px-2.5 py-1 text-xs"
                title="Open the payroll run that booked this expense"
                @click="openRunFromExpense(expense)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path
                    fill-rule="evenodd"
                    d="M10 1a4 4 0 0 0-4 4v3H5.5A1.5 1.5 0 0 0 4 9.5v7A1.5 1.5 0 0 0 5.5 18h9a1.5 1.5 0 0 0 1.5-1.5v-7A1.5 1.5 0 0 0 14.5 8H14V5a4 4 0 0 0-4-4Zm2.5 7V5a2.5 2.5 0 0 0-5 0v3h5Z"
                    clip-rule="evenodd"
                  />
                </svg>
                From payroll
              </button>
              <template v-else>
                <button class="sh-btn px-2.5 py-1 text-xs" @click="openExpenseModal(expense)">Edit</button>
                <button
                  class="sh-btn px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50"
                  @click="deleteExpense(expense)"
                >
                  Delete
                </button>
              </template>
            </div>
          </div>

          <div class="sh-card flex items-center justify-between gap-3 p-5">
            <p class="text-sm font-medium text-ink/60">Total</p>
            <p class="font-display text-2xl text-ink">{{ money(expenseTotal) }}</p>
          </div>
        </div>
      </template>

      <p v-else class="sh-empty">No expenses in this range.</p>
    </section>

    <section v-if="tab === 'profit'" class="space-y-4">
      <div class="sh-card flex flex-wrap items-end gap-3 p-4">
        <div class="w-44">
          <label class="sh-label">From</label>
          <input v-model="profitRange.from" type="date" class="sh-input" @change="loadProfit" />
        </div>
        <div class="w-44">
          <label class="sh-label">To</label>
          <input v-model="profitRange.to" type="date" class="sh-input" @change="loadProfit" />
        </div>
      </div>

      <div v-if="profit" class="grid gap-4 sm:grid-cols-3">
        <div class="sh-card p-5">
          <p class="text-xs uppercase tracking-wider text-ink/50">Earned</p>
          <p class="mt-1 font-display text-2xl text-ink">{{ money(profit.earned) }}</p>
        </div>
        <div class="sh-card p-5">
          <p class="text-xs uppercase tracking-wider text-ink/50">Expenses</p>
          <p class="mt-1 font-display text-2xl text-ink">{{ money(profit.expenses_total) }}</p>
        </div>
        <div class="sh-card p-5">
          <p class="text-xs uppercase tracking-wider text-ink/50">Net profit</p>
          <p class="mt-1 font-display text-2xl" :class="profit.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">
            {{ money(profit.net_profit) }}
          </p>
        </div>
      </div>

      <!-- Three read-only columns fit a 390px card, so this breakdown keeps a
           single table branch and scrolls inside its own box. -->
      <div v-if="profit?.expenses_by_category.length" class="sh-card overflow-x-auto">
        <table class="sh-table">
          <thead>
            <tr>
              <th>Category</th>
              <th class="text-right">Amount</th>
              <th class="text-right">Share</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in profit.expenses_by_category" :key="row.category">
              <td class="capitalize">{{ row.category }}</td>
              <td class="text-right">{{ money(row.amount) }}</td>
              <td class="text-right text-ink/60">{{ row.share_pct }}%</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="profit" class="sh-empty">
        No expenses in this range — net profit is everything earned.
      </p>
    </section>

    <Modal
      v-if="expenseModalOpen"
      :title="editingExpenseId ? 'Edit expense' : 'Add expense'"
      @close="expenseModalOpen = false"
    >
      <div class="space-y-4">
        <div>
          <label class="sh-label">Category</label>
          <select v-model="expenseForm.category" class="sh-input">
            <option v-for="c in modalCategories" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div>
          <label class="sh-label">Date</label>
          <input v-model="expenseForm.expense_date" type="date" class="sh-input" />
          <p v-if="expenseErrors.expense_date" class="sh-error">{{ expenseErrors.expense_date[0] }}</p>
        </div>
        <div>
          <label class="sh-label">Amount</label>
          <input v-model="expenseForm.amount" type="number" min="0" step="0.01" class="sh-input" />
          <p v-if="expenseErrors.amount" class="sh-error">{{ expenseErrors.amount[0] }}</p>
        </div>
        <div>
          <label class="sh-label">Note</label>
          <input v-model="expenseForm.note" type="text" class="sh-input" />
        </div>
      </div>
      <template #footer>
        <button class="sh-btn" @click="expenseModalOpen = false">Cancel</button>
        <button
          :disabled="savingExpense"
          class="sh-btn sh-btn-primary"
          @click="saveExpense"
        >
          Save
        </button>
      </template>
    </Modal>
  </div>
</template>
