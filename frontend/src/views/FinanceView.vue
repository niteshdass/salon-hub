<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
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

const runs = ref([])
const activeRun = ref(null)
const loading = ref(false)
const error = ref('')
const months = monthOptions(12)
const selectedMonth = ref(months[0].value)

function money(value) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value })
    .format(Number(value || 0))
}

async function loadRuns() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/payroll/runs')
    runs.value = data.data
    if (runs.value.length && !activeRun.value) await openRun(runs.value[0].id)
  } catch (e) {
    error.value = parseApiError(e, 'Could not load payroll.').message
  } finally {
    loading.value = false
  }
}

async function openRun(id) {
  error.value = ''
  try {
    const { data } = await api.get(`/payroll/runs/${id}`)
    activeRun.value = data.data
  } catch (e) {
    error.value = parseApiError(e, 'Could not load this payroll run.').message
  }
}

async function createRun() {
  error.value = ''
  try {
    const { data } = await api.post('/payroll/runs', { period_month: selectedMonth.value })
    activeRun.value = data.data
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not open payroll.').message
  }
}

// Saves one edited amount and refreshes the run so the header total matches.
async function saveLine(line, field, value) {
  error.value = ''
  try {
    await api.patch(`/payroll/runs/${activeRun.value.id}/lines/${line.id}`, { [field]: Number(value || 0) })
    await openRun(activeRun.value.id)
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not save that amount.').message
  }
}

async function finalizeRun() {
  if (!window.confirm(`Finalize ${activeRun.value.period_label} for ${money(activeRun.value.total_amount)}? This locks the run and books it as an expense.`)) return
  error.value = ''
  try {
    const { data } = await api.post(`/payroll/runs/${activeRun.value.id}/finalize`)
    activeRun.value = data.data
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not finalize this run.').message
  }
}

async function deleteRun() {
  if (!window.confirm(`Delete payroll for ${activeRun.value.period_label}? Its salary expense goes with it.`)) return
  error.value = ''
  try {
    await api.delete(`/payroll/runs/${activeRun.value.id}`)
    activeRun.value = null
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not delete this run.').message
  }
}

const EXPENSE_CATEGORIES = [
  'rent', 'utilities', 'supplies', 'salary', 'marketing', 'equipment', 'maintenance', 'other',
]

const expenses = ref([])
const expenseFilters = reactive({ from: '', to: '', category: '' })
const expenseModalOpen = ref(false)
const editingExpenseId = ref(null)
const expenseForm = reactive({ category: 'supplies', expense_date: '', amount: '', note: '' })
const expenseErrors = ref({})

function startOfMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

async function loadExpenses() {
  error.value = ''
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
    error.value = parseApiError(e, 'Could not load expenses.').message
  }
}

function openExpenseModal(expense = null) {
  expenseErrors.value = {}
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
    await loadExpenses()
  } catch (e) {
    // A 422 carries per-field errors; anything else (including the "this came
    // from payroll" refusal) only has a sentence, so show it in the banner.
    const parsed = parseApiError(e, 'Could not save this expense.')
    expenseErrors.value = parsed.errors
    if (!Object.keys(parsed.errors).length) error.value = parsed.message
  }
}

async function deleteExpense(expense) {
  if (!window.confirm('Delete this expense?')) return
  try {
    await api.delete(`/expenses/${expense.id}`)
    await loadExpenses()
  } catch (e) {
    error.value = parseApiError(e, 'Could not delete this expense.').message
  }
}

const expenseTotal = computed(() =>
  expenses.value.reduce((sum, row) => sum + Number(row.amount || 0), 0)
)

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
        <button class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700" @click="createRun">
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
              class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
              @click="finalizeRun"
            >
              Finalize
            </button>
            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="deleteRun">
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
                <span v-if="expense.is_locked" class="text-xs text-slate-400">From payroll</span>
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

    <Modal
      v-if="expenseModalOpen"
      :title="editingExpenseId ? 'Edit expense' : 'Add expense'"
      @close="expenseModalOpen = false"
    >
      <div class="space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select v-model="expenseForm.category" class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
            <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
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
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="saveExpense">Save</button>
      </template>
    </Modal>
  </div>
</template>
