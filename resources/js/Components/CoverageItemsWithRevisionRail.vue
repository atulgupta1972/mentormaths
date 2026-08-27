<script setup>
import CoverageSetItemCard from '@/Components/CoverageSetItemCard.vue';

defineProps({
    items: { type: Array, default: () => [] },
    revisionItems: { type: Array, default: () => [] },
    groupKey: { type: String, default: '' },
    revisionGroupKey: { type: String, default: 'revisions' },
    itemKeyPrefix: { type: String, default: 'item' },
    isStudentView: { type: Boolean, default: false },
    canStaffAssign: { type: Boolean, default: false },
    assigningWorksheetId: { type: [Number, String, null], default: null },
    pendingAssignKey: { type: String, default: null },
    staffAssignForm: { type: Object, default: null },
});

defineEmits([
    'self-assign',
    'start-correction',
    'start-revision',
    'open-staff-assign',
    'confirm-staff-assign',
    'cancel-staff-assign',
]);

const cardKey = (item, prefix) =>
    `${prefix}-${item.assignment_id || item.worksheet_id || item.short_label}`;
</script>

<template>
    <div class="mt-1.5 flex flex-col gap-2 sm:flex-row sm:items-stretch">
        <div class="flex min-w-0 flex-1 flex-wrap content-start gap-1.5">
            <CoverageSetItemCard
                v-for="item in items"
                :key="cardKey(item, itemKeyPrefix)"
                :item="item"
                :group-key="groupKey"
                :is-student-view="isStudentView"
                :can-staff-assign="canStaffAssign"
                :assigning-worksheet-id="assigningWorksheetId"
                :pending-assign-key="pendingAssignKey"
                :staff-assign-form="staffAssignForm"
                @self-assign="$emit('self-assign', $event)"
                @start-correction="$emit('start-correction', $event)"
                @start-revision="$emit('start-revision', $event)"
                @open-staff-assign="(item, key) => $emit('open-staff-assign', item, key)"
                @confirm-staff-assign="$emit('confirm-staff-assign', $event)"
                @cancel-staff-assign="$emit('cancel-staff-assign')"
            />
        </div>

        <div
            v-if="revisionItems?.length"
            class="flex shrink-0 gap-2 border-t-2 border-indigo-600 pt-2 sm:max-w-[42%] sm:border-l-2 sm:border-t-0 sm:pl-2.5 sm:pt-0"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <p class="text-[9px] font-extrabold uppercase tracking-wide text-indigo-800 leading-none">
                    Revision
                </p>
                <div class="flex flex-wrap content-start gap-1.5">
                    <CoverageSetItemCard
                        v-for="item in revisionItems"
                        :key="cardKey(item, `${itemKeyPrefix}-rev`)"
                        :item="item"
                        :group-key="revisionGroupKey"
                        :is-student-view="isStudentView"
                        :can-staff-assign="canStaffAssign"
                        :assigning-worksheet-id="assigningWorksheetId"
                        :pending-assign-key="pendingAssignKey"
                        :staff-assign-form="staffAssignForm"
                        @self-assign="$emit('self-assign', $event)"
                        @start-correction="$emit('start-correction', $event)"
                        @start-revision="$emit('start-revision', $event)"
                        @open-staff-assign="(item, key) => $emit('open-staff-assign', item, key)"
                        @confirm-staff-assign="$emit('confirm-staff-assign', $event)"
                        @cancel-staff-assign="$emit('cancel-staff-assign')"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
