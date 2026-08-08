<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import { monthOptions, payTypeLabel } from '@/lib/payroll'
import Modal from '@/components/Modal.vue'

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

onMounted(async () => {
  await loadRuns()
  await loadExpenses()
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Finance</h1>
      <p class="mt-1 text-sm text-slate-500">Staff pay, costs, and what the salon actually keeps.</p>
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button
        v-for="item in TABS"
        :key="item.key"
        class="border-b-2 px-4 py-2 text-sm font-medium transition"
        :class="tab === item.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <section v-if="tab === 'payroll'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Month</label>
          <select v-model="selectedMonth" class="rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <button
          :disabled="saving"
          class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          @click="createRun"
        >
          Open payroll
        </button>
      </div>

      <div v-if="runs.length" class="flex flex-wrap gap-2">
        <button
          v-for="run in runs"
          :key="run.id"
          class="rounded-full border px-3 py-1 text-sm"
          :class="activeRun?.id === run.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-300 text-slate-600'"
          @click="openRun(run.id)"
        >
          {{ run.period_label }}
          <span v-if="run.status === 'finalized'" class="ml-1 text-xs text-emerald-600">✓</span>
        </button>
      </div>

      <p v-if="loading" class="text-sm text-slate-500">Loading…</p>
      <p v-else-if="!runs.length" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
        No payroll yet. Pick a month and open it.
      </p>

      <div v-if="activeRun" class="overflow-hidden rounded-xl border border-slate-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
          <div>
            <p class="text-sm font-semibold text-slate-900">{{ activeRun.period_label }}</p>
            <p class="text-xs text-slate-500">
              <span v-if="activeRun.status === 'finalized'">Finalized {{ new Date(activeRun.finalized_at).toLocaleDateString() }}</span>
              <span v-else>Draft — amounts can still be edited</span>
            </p>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-slate-900">{{ money(activeRun.total_amount) }}</span>
            <button
              v-if="activeRun.status === 'draft'"
              :disabled="saving"
              class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
              @click="finalizeRun"
            >
              Finalize
            </button>
            <button
              :disabled="saving"
              class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
              @click="deleteRun"
            >
              Delete
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-white text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-4 py-2">Staff</th>
                <th class="px-4 py-2">Rule</th>
                <th class="px-4 py-2 text-right">Bookings</th>
                <th class="px-4 py-2 text-right">Earned</th>
                <th class="px-4 py-2 text-right">Salary</th>
                <th class="px-4 py-2 text-right">Commission</th>
                <th class="px-4 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="line in activeRun.lines" :key="line.id">
                <td class="px-4 py-2 font-medium text-slate-900">{{ line.staff_name }}</td>
                <td class="px-4 py-2 text-slate-500">
                  {{ payTypeLabel(line.pay_type) }}
                  <span v-if="Number(line.commission_rate) > 0" class="text-xs">({{ line.commission_rate }}%)</span>
                </td>
                <td class="px-4 py-2 text-right">{{ line.bookings }}</td>
                <td class="px-4 py-2 text-right">{{ money(line.earned_revenue) }}</td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.salary_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-right"
                    @change="saveLine(line, 'salary_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.salary_amount) }}</span>
                </td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.commission_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-right"
                    @change="saveLine(line, 'commission_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.commission_amount) }}</span>
                </td>
                <td class="px-4 py-2 text-right font-semibold text-slate-900">{{ money(line.total_amount) }}</td>
              </tr>
              <tr v-if="!activeRun.lines.length">
                <td colspan="7" class="px-4 py-6 text-center text-slate-500">
                  No staff have a pay rule yet. Set one on the Staff page.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section v-if="tab === 'expenses'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">From</label>
          <input v-model="expenseFilters.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">To</label>
          <input v-model="expenseFilters.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select v-model="expenseFilters.category" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses">
            <option value="">All</option>
            <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <button class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700" @click="openExpenseModal()">
          Add expense
        </button>
      </div>

      <div class="overflow-hidden rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2">Date</th>
              <th class="px-4 py-2">Category</th>
              <th class="px-4 py-2">Note</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="expense in expenses" :key="expense.id">
              <td class="px-4 py-2">{{ expense.expense_date }}</td>
              <td class="px-4 py-2 capitalize">{{ expense.category }}</td>
              <td class="px-4 py-2 text-slate-500">{{ expense.note || '—' }}</td>
              <td class="px-4 py-2 text-right">{{ money(expense.amount) }}</td>
              <td class="px-4 py-2 text-right">
                <button
                  v-if="expense.is_locked"
                  class="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-indigo-700 hover:underline"
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
                  <button class="text-sm text-indigo-600 hover:underline" @click="openExpenseModal(expense)">Edit</button>
                  <button class="ml-3 text-sm text-rose-600 hover:underline" @click="deleteExpense(expense)">Delete</button>
                </template>
              </td>
            </tr>
            <tr v-if="!expenses.length">
              <td colspan="5" class="px-4 py-6 text-center text-slate-500">No expenses in this range.</td>
            </tr>
          </tbody>
          <tfoot v-if="expenses.length" class="bg-slate-50">
            <tr>
              <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-slate-600">Total</td>
              <td class="px-4 py-2 text-right text-sm font-semibold text-slate-900">{{ money(expenseTotal) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <section v-if="tab === 'profit'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">From</label>
          <input v-model="profitRange.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadProfit" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">To</label>
          <input v-model="profitRange.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadProfit" />
        </div>
      </div>

      <div v-if="profit" class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Earned</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ money(profit.earned) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Expenses</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ money(profit.expenses_total) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Net profit</p>
          <p class="mt-1 text-2xl font-semibold" :class="profit.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">
            {{ money(profit.net_profit) }}
          </p>
        </div>
      </div>

      <div v-if="profit?.expenses_by_category.length" class="overflow-hidden rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2">Category</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2 text-right">Share</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in profit.expenses_by_category" :key="row.category">
              <td class="px-4 py-2 capitalize">{{ row.category }}</td>
              <td class="px-4 py-2 text-right">{{ money(row.amount) }}</td>
              <td class="px-4 py-2 text-right text-slate-500">{{ row.share_pct }}%</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="profit" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
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
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select v-model="expenseForm.category" class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
            <option v-for="c in modalCategories" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Date</label>
          <input v-model="expenseForm.expense_date" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
          <p v-if="expenseErrors.expense_date" class="mt-1 text-sm text-rose-600">{{ expenseErrors.expense_date[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Amount</label>
          <input v-model="expenseForm.amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
          <p v-if="expenseErrors.amount" class="mt-1 text-sm text-rose-600">{{ expenseErrors.amount[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Note</label>
          <input v-model="expenseForm.note" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
        </div>
      </div>
      <template #footer>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm" @click="expenseModalOpen = false">Cancel</button>
        <button
          :disabled="savingExpense"
          class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          @click="saveExpense"
        >
          Save
        </button>
      </template>
    </Modal>
  </div>
</template>
