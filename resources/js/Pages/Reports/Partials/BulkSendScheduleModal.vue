<script setup>
/**
 * Reports -> Faculty Schedule -> "Send All" (spec section 15/16).
 * Confirms before firing, since this can queue many emails at once —
 * scoped to whatever facultyIds the Reports page currently has
 * filtered/selected (empty means every Active faculty for the term).
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  show: { type: Boolean, default: false },
  facultyIds: { type: Array, default: () => [] }, // empty = every Active faculty (or College-scoped, see collegeId)
  collegeId: { type: [String, Number, null], default: null }, // Reports' College/Program filter — only applied server-side when facultyIds is empty
  academicTerm: { type: Object, default: null }, // { id, label }
  scopeLabel: { type: String, default: '' }, // e.g. "College of Computer Studies" or "3 selected faculty"
})

const emit = defineEmits(['close', 'sent'])

const sending = ref(false)
const serverError = ref('')

function close() {
  if (sending.value) return
  serverError.value = ''
  emit('close')
}

function confirmSend() {
  if (sending.value || !props.academicTerm) return

  sending.value = true
  serverError.value = ''

  router.post('/reports/faculty-schedule/bulk-send', {
    academic_term_id: props.academicTerm.id,
    faculty_ids: props.facultyIds,
    college_id: props.collegeId,
  }, {
    preserveScroll: true,
    onSuccess: () => emit('sent'),
    onError: (errors) => {
      serverError.value = Object.values(errors)[0] || 'Failed to send faculty schedules.'
    },
    onFinish: () => { sending.value = false },
  })
}
</script>

<template>
  <div v-if="show" class="modal-backdrop" @click.self="close">
    <div class="modal-card">
      <h3 class="modal-title">Send Faculty Schedules</h3>

      <div class="modal-body">
        <div class="row">
          <span class="label">Scope:</span>
          <span>{{ scopeLabel || 'All Active Faculty' }}</span>
        </div>
        <div class="row"><span class="label">Academic Term:</span><span>{{ academicTerm?.label }}</span></div>

        <div class="banner banner-info">
          <p>
            Only faculty with a <strong>finalized</strong> schedule and a
            <strong>valid email address</strong> will receive a schedule.
            Anyone else in scope will be skipped automatically — nothing
            fails the whole send.
          </p>
          <p v-if="collegeId && facultyIds.length === 0" style="margin-top: 8px;">
            No individual faculty are hand-picked, so this send is scoped to
            the <strong>College/Program filter</strong> shown above — not
            the entire school.
          </p>
        </div>

        <div v-if="serverError" class="banner banner-error">{{ serverError }}</div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" :disabled="sending" @click="close">Cancel</button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="sending || !academicTerm"
          @click="confirmSend"
        >
          {{ sending ? 'Queuing...' : 'Send All' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 27, 61, 0.45); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal-card { background: #fff; border-radius: 10px; padding: 24px; width: 440px; max-width: 92vw; }
.modal-title { margin: 0 0 16px; font-size: 18px; font-weight: 700; }
.row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 14px; }
.row > span:last-child { min-width: 0; text-align: right; overflow-wrap: anywhere; }
.label { color: #6b7280; flex-shrink: 0; }
.banner { margin-top: 14px; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.banner-info { background: #eef4ff; border: 1px solid #bcd3ff; color: #1e3a8a; }
.banner-error { background: #fdecec; border: 1px solid #f5b5b5; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.btn { padding: 8px 16px; border-radius: 12px; font-size: 14px; border: none; cursor: pointer; }
.btn-secondary { background: #f1f3f9; color: #1f2937; }
.btn-primary { background: #16a34a; color: #fff; }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>