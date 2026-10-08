<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Applies one deterministic rule without rebuilding activity summaries. */
final readonly class PreservingActivityRepairer
{
    public const string FILE_ID_FIRST = 'file_id_first';
    public const string SESSION_INDEX = 'session_message_index';
    public const string LAP_INDEX = 'lap_message_index';
    public const string SESSION_LAP_REFERENCES = 'session_lap_references';
    public const string MISSING_LAP = 'missing_session_lap';
    public const string LOCAL_TIMESTAMP = 'activity_local_timestamp';
    public const string PAUSED_LAP_START = PausedLapStartRepairer::RULE;
    public const string RECORDED_SPAN_START = RecordedSpanStartRepairer::RULE;
    public const string RECORDED_LAP_BOUNDARY = RecordedLapBoundaryRepairer::RULE;
    public const string RECORDED_LAP_PARTITION_START = RecordedLapPartitionStartRepairer::RULE;
    public const string PAUSED_LAP_PARTITION_START = RecordedLapPartitionStartRepairer::PAUSED_RULE;
    public const string FRONT_SUMMARY_CLOCKS = RecordedLapPartitionStartRepairer::FRONT_RULE;
    public const string RECORDED_LAP_END = RecordedLapEndRepairer::RULE;
    public const string SINGLE_LAP_DURATIONS = SingleLapDurationRepairer::RULE;
    public const string SINGLE_LAP_START = SingleLapDurationRepairer::START_RULE;
    public const string IMPOSSIBLE_SINGLE_LAP_START = SingleLapDurationRepairer::IMPOSSIBLE_START_RULE;
    public const string SEGMENT_LAP_ELAPSED = SegmentLapElapsedRepairer::RULE;
    public const string RECORDED_SUMMARY_TIMESTAMPS = RecordedSummaryTimestampRepairer::RULE;
    public const string ONE_BASED_LAP_INDEXES = OneBasedLapIndexRepairer::RULE;
    public const string REPEATED_LAP_INDEXES = RepeatedLapIndexRepairer::RULE;
    public const string RESERVED_SESSION_INDEX = ReservedSessionIndexRepairer::RULE;
    public const string SESSION_ELAPSED_FROM_LAPS = SessionElapsedFromLapsRepairer::RULE;
    public const string UNPAUSED_LAP_TIMER = UnpausedLapTimerRepairer::RULE;
    public const string UNPAUSED_FINAL_LAP = UnpausedFinalLapRepairer::RULE;
    public const string RECORDED_OBSERVATION_ORDER = RecordedObservationOrderRepairer::RULE;
    public const string RECORDED_LENGTH_LAPS = RecordedLengthLapRepairer::RULE;
    public const string RECORDED_LENGTH_BOUNDARIES = RecordedLengthBoundaryRepairer::RULE;
    public const string RECORDED_TIMER_START = 'timer_start_from_recorded_session';
    public const string SUMMARY_TIMER_START = 'timer_start_from_matching_native_summary_starts';
    public const string RECONCILED_SUMMARY_CLOCKS = SummaryClockReconciler::RULE;

    /** @param array<string, mixed> $validation */
    public function ruleForFailure(array $validation, ?int $localTimeOffset): ?string
    {
        return $this->rulesForFailure($validation, $localTimeOffset)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $validation
     *
     * @return list<string> preferred rule followed by safe prerequisites
     */
    public function rulesForFailure(array $validation, ?int $localTimeOffset, bool $reconcileSummaryClocks = false): array
    {
        $rules = $this->preservingRulesForFailure($validation, $localTimeOffset);
        if ($reconcileSummaryClocks && 'FAILED' === ($validation['status'] ?? null)) {
            $errors = $validation['errors'] ?? [];
            $last = is_array($errors) ? end($errors) : false;
            $message = is_array($last) && is_string($last['message'] ?? null) ? $last['message'] : '';
            if (str_contains($message, 'pool_length') || str_contains($message, 'does not abut it:') || str_contains($message, 'got missing')) {
                $rules[] = RecordedPoolPartitionRepairer::RULE;
            }
            if (str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'has no usable start_time field.')) {
                $rules[] = RecordedLapReferenceRepairer::SAVED_BOUNDARIES_RULE;
            }
            if (str_contains($message, '"Lap Message Exists"')) {
                $rules[] = MissingSessionLapRepairer::NATIVE_SUMMARY_RULE;
            }
            if (str_contains($message, '"Activity Message Exists"')) {
                $rules[] = RecordedActivitySummaryRepairer::SUMMARY_ONLY_RULE;
            }
            if (str_contains($message, 'does not abut it:') || str_contains($message, 'got missing')) {
                $rules[] = RecordedSessionLapPartitionRepairer::RULE;
            }
            if (str_contains($message, 'does not abut it:') || str_contains($message, 'before previous') || str_contains($message, 'Timestamps')) {
                $rules[] = RecordedObservationOrderRepairer::MULTI_SESSION_RULE;
            }
            if (str_contains($message, 'FIT segment_lap at sequence ') && str_contains($message, 'has no usable total_elapsed_time field.')) {
                $rules[] = SegmentLapElapsedRepairer::CLOCK_RULE;
            }
            if (str_contains($message, 'got missing') || str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"')) {
                $rules[] = RecordedLapReferenceRepairer::RULE;
            }
            if ((str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'is not sequential with the previous lap or does not abut it:'))
                || str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"')
                || str_contains($message, '"Lap Message Start Time and Timestamp are Valid"')) {
                $rules[] = self::RECONCILED_SUMMARY_CLOCKS;
                $rules[] = SummaryClockReconciler::JOINT_RULE;
            }
        }

        if ('FAILED' === ($validation['status'] ?? null)) {
            $errors = $validation['errors'] ?? [];
            $last = is_array($errors) ? end($errors) : false;
            $message = is_array($last) && is_string($last['message'] ?? null) ? $last['message'] : '';
            if ((str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'is not sequential with the previous lap or does not abut it:'))
                || str_contains($message, '"Lap Message Start Time and Timestamp are Valid"')
                || ((str_contains($message, '"Session Message Valid Message Index"') || str_contains($message, '"Lap Message Valid Message Index"')) && str_contains($message, 'got missing'))
                || str_contains($message, '"Session Message First Lap Index and Num Laps are Valid"')) {
                $rules[] = RecordedLapPartitionStartRepairer::NATIVE_RULE;
            }
            if ($reconcileSummaryClocks && (str_contains($message, '"Lap Message Exists"') || str_contains($message, '"Lap Message Valid Message Index"') || str_contains($message, 'does not abut it:'))) {
                $rules[] = RecordedMissingSetLapRepairer::RULE;
            }
            if (str_contains($message, 'FIT session at sequence ') && str_contains($message, 'is not sequential with the previous session or does not abut it:')) {
                $rules[] = RecordedSummaryDurationReconciler::RULE;
            }
            if ((str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'is not sequential with the previous lap or does not abut it:'))
                || str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"')
                || str_contains($message, '"Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values"')
                || str_contains($message, '"Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values"')
                || str_contains($message, 'Final activity session cannot finish before the latest event.')
                || str_contains($message, 'Final activity session cannot finish before the latest activity detail.')
                || (str_starts_with($message, 'FIT session total_timer_time (') && str_contains($message, ') cannot exceed total_elapsed_time ('))) {
                $rules[] = RecordedSummaryDurationReconciler::RULE;
                $rules[] = RecordedLengthBoundaryRepairer::RULE;
            }
        }

        if ('FAILED' === ($validation['status'] ?? null)) {
            $errors = $validation['errors'] ?? [];
            $last = is_array($errors) ? end($errors) : false;
            $message = is_array($last) && is_string($last['message'] ?? null) ? $last['message'] : '';
            $missingCounter = (str_contains($message, 'FIT lap at sequence ') || str_contains($message, 'FIT session at sequence '))
                && (str_contains($message, 'has no usable total_elapsed_time field.') || str_contains($message, 'has no usable total_timer_time field.'));
            if ($missingCounter) {
                $rules[] = RecordedSummaryDurationReconciler::RULE;
                if (str_contains($message, 'has no usable total_timer_time field.')) {
                    $rules[] = RecordedMovingTimerRepairer::RULE;
                }
            }
            if (str_contains($message, '"Lap Message Valid Message Index"')) {
                $rules[] = RecordedSummaryDurationReconciler::RULE;
            }
            $missingClock = (str_contains($message, 'FIT lap at sequence ') || str_contains($message, 'FIT session at sequence ') || str_contains($message, 'FIT activity at sequence '))
                && (str_contains($message, 'has no usable start_time field.') || str_contains($message, 'has no usable timestamp field.'));
            if ($reconcileSummaryClocks && ($missingCounter || $missingClock || in_array(RecordedSummaryDurationReconciler::RULE, $rules, true)
                || str_contains($message, '"Lap Message Start Time and Timestamp are Valid"'))) {
                $rules[] = RecordedSummaryDurationReconciler::CLOCK_RULE;
            }
            if (str_contains($message, 'does not abut it:') || str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"')
                || str_contains($message, '"Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values"')) {
                $rules[] = RecordedLapElapsedRepairer::RULE;
                if ($reconcileSummaryClocks) {
                    $rules[] = RecordedLapElapsedRepairer::CLOCK_RULE;
                }
            }
            if ($missingClock || $missingCounter || (str_contains($message, 'got missing')
                    && (str_contains($message, '"Session Message Valid Message Index"') || str_contains($message, '"Lap Message Valid Message Index"')))
                || str_contains($message, '"Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values"')) {
                $rules[] = RecordedSingleLapFieldsRepairer::RULE;
            }
            if (str_contains($message, '"Activity Message Exists"') || str_contains($message, '"Session Message Exists"') || $missingCounter) {
                $rules[] = RecordedActivitySummaryRepairer::RULE;
                if ($reconcileSummaryClocks) {
                    $rules[] = RecordedActivitySummaryRepairer::PREFIX_RULE;
                }
            }
            if (str_contains($message, '"Session Message Sport Exists"')) {
                $rules[] = RecordedSportRepairer::RULE;
                $rules[] = RecordedSportRepairer::UNKNOWN_RULE;
            }
            if (str_contains($message, '"Lap Message Exists"') || (str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'has no usable timestamp field.'))) {
                $rules[] = RecordedSummarySaveRepairer::RULE;
            }
            if ($reconcileSummaryClocks && str_contains($message, 'cannot exceed total_elapsed_time')) {
                $rules[] = RecordedSummarySaveRepairer::MINIMUM_RULE;
            }
            if ($reconcileSummaryClocks && str_contains($message, 'FIT event at sequence ') && str_contains($message, 'has no usable timestamp field.')) {
                $rules[] = self::SUMMARY_TIMER_START;
            }
            if ($reconcileSummaryClocks && (str_contains($message, 'Observation cannot be earlier than the latest activity event.')
                || str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"'))) {
                $rules[] = RecordedRecordClockRepairer::DATE_RULE;
                $rules[] = RecordedRecordClockRepairer::PREFIX_RULE;
                $rules[] = RecordedSingleLapFieldsRepairer::EXTENT_RULE;
            }
            if ($reconcileSummaryClocks && $missingCounter) {
                $rules[] = RecordedActivitySummaryRepairer::OBSERVED_SESSION_RULE;
                $rules[] = RecordedSingleLapFieldsRepairer::STOP_TRACE_RULE;
                $rules[] = RecordedActivitySummaryRepairer::SESSION_TIMERS_RULE;
            }
            if (str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"') || str_contains($message, 'does not abut it:')
                || $missingCounter || str_contains($message, '"Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values"')) {
                $rules[] = RecordedObservationOrderRepairer::RULE;
            }
        }

        if ($reconcileSummaryClocks && in_array(RecordedObservationOrderRepairer::RULE, $rules, true)) {
            $rules[] = RecordedObservationOrderRepairer::MULTI_SESSION_RULE;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $validation
     *
     * @return list<string>
     */
    private function preservingRulesForFailure(array $validation, ?int $localTimeOffset): array
    {
        if ('FAILED' !== ($validation['status'] ?? null)) {
            return [];
        }

        $errors = $validation['errors'] ?? [];
        $error = is_array($errors) ? end($errors) : false;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : '';

        if ((str_contains($message, 'FIT length at sequence ') && str_contains($message, 'has no usable timestamp field.'))
            || (str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"') && str_contains($message, 'first Session start_time and first Record timestamp are required.'))) {
            return [RecordedPoolTimestampRepairer::RULE];
        }

        if (str_contains($message, '"Session Message Valid Message Index"')
            && 1 === preg_match('/expected message_index=0; got ([0-9]+)\./', $message, $match)
            && 0 < (int) $match[1] && 0x7000 >= (int) $match[1] && 0 === ((int) $match[1] & 0x0FFF)) {
            return [self::RESERVED_SESSION_INDEX];
        }

        foreach ([self::SESSION_INDEX => 'Session Message Valid Message Index', self::LAP_INDEX => 'Lap Message Valid Message Index'] as $rule => $check) {
            if (str_contains($message, '"'.$check.'"') && str_contains($message, 'got missing')) {
                return self::SESSION_INDEX === $rule ? [$rule, self::ONE_BASED_LAP_INDEXES, self::SESSION_LAP_REFERENCES, self::UNPAUSED_FINAL_LAP, self::IMPOSSIBLE_SINGLE_LAP_START] : [$rule, self::SESSION_LAP_REFERENCES, self::IMPOSSIBLE_SINGLE_LAP_START];
            }
        }

        if (str_contains($message, '"Lap Message Valid Message Index"')) {
            return [self::ONE_BASED_LAP_INDEXES, self::REPEATED_LAP_INDEXES];
        }

        if (str_contains($message, '"FileId Message Is First"')) {
            return [self::FILE_ID_FIRST];
        }

        if (str_contains($message, '"Activity Message Exists"')) {
            return [MissingSessionLapRepairer::ACTIVITY_RULE];
        }

        if (str_contains($message, '"Lap Message Exists"')) {
            return [self::MISSING_LAP];
        }

        if (str_contains($message, '"Session Message First Lap Index and Num Laps are Valid"')) {
            return [self::SESSION_LAP_REFERENCES, self::ONE_BASED_LAP_INDEXES, self::IMPOSSIBLE_SINGLE_LAP_START];
        }

        if (str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'is not sequential with the previous lap or does not abut it:')) {
            return [self::RECORDED_LAP_PARTITION_START, self::PAUSED_LAP_PARTITION_START, self::PAUSED_LAP_START, self::RECORDED_LAP_END, self::UNPAUSED_FINAL_LAP, self::RECORDED_LENGTH_LAPS, self::FRONT_SUMMARY_CLOCKS];
        }

        if (str_contains($message, 'Observation cannot be earlier than the latest activity event.')) {
            return [self::RECORDED_OBSERVATION_ORDER];
        }

        if (str_contains($message, 'Activity details in sequence pool_length cannot overlap beyond their timeline resolution.')) {
            return [self::RECORDED_LENGTH_BOUNDARIES];
        }

        if (str_contains($message, '"Record Message Timestamps Fall Within Session Message Times"') && str_contains($message, 'first Record timestamp=')) {
            return [self::RECORDED_SPAN_START];
        }

        if (str_contains($message, '"Lap Message Start Time and Timestamp are Valid"') && (str_contains($message, 'end delta=2 seconds') || str_contains($message, 'end delta=3 seconds'))) {
            return [self::RECORDED_LAP_PARTITION_START, self::PAUSED_LAP_PARTITION_START, self::RECORDED_LAP_BOUNDARY, self::RECORDED_LAP_END, self::FRONT_SUMMARY_CLOCKS];
        }

        if (str_contains($message, '"Lap Message Start Time and Timestamp are Valid"')) {
            return [self::FRONT_SUMMARY_CLOCKS, self::IMPOSSIBLE_SINGLE_LAP_START];
        }

        if (str_contains($message, 'FIT segment_lap at sequence ') && str_contains($message, 'has no usable total_elapsed_time field.')) {
            return [self::SEGMENT_LAP_ELAPSED];
        }

        if (str_contains($message, 'FIT lap at sequence ') && str_contains($message, 'has no usable start_time field.')) {
            return [self::SINGLE_LAP_START];
        }

        if (str_contains($message, 'FIT lap at sequence ') && (str_contains($message, 'has no usable total_elapsed_time field.') || str_contains($message, 'has no usable total_timer_time field.'))) {
            return [self::SINGLE_LAP_DURATIONS];
        }

        if (str_contains($message, '"Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values"')) {
            return [self::SESSION_ELAPSED_FROM_LAPS, self::UNPAUSED_FINAL_LAP, self::RECORDED_LENGTH_LAPS];
        }

        if (str_contains($message, '"Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values"')) {
            return [self::UNPAUSED_LAP_TIMER, self::RECORDED_LENGTH_LAPS];
        }

        if (str_starts_with($message, 'FIT session total_timer_time (') && str_contains($message, ') cannot exceed total_elapsed_time (')) {
            return [self::UNPAUSED_LAP_TIMER];
        }

        if (str_contains($message, 'has no usable timestamp field.')
            && (str_contains($message, 'FIT lap at sequence ') || str_contains($message, 'FIT session at sequence ') || str_contains($message, 'FIT activity at sequence '))) {
            return [self::RECORDED_SUMMARY_TIMESTAMPS];
        }

        if (str_contains($message, 'FIT event at sequence ') && str_contains($message, 'has no usable timestamp field.')) {
            return [self::RECORDED_TIMER_START];
        }

        return null !== $localTimeOffset && str_contains($message, '"Activity Message Local Timestamp is Valid"') && str_contains($message, 'Local Timestamp is null')
            ? [self::LOCAL_TIMESTAMP] : [];
    }

    public function repair(string $bytes, string $rule, ?int $localTimeOffset = null): RepairResult
    {
        if (null !== $localTimeOffset && (-43_200 > $localTimeOffset || 50_400 < $localTimeOffset)) {
            throw new RepairFailed('Local time offset must be in -43200..50400 seconds.');
        }

        if (RecordedPoolPartitionRepairer::RULE === $rule) {
            return (new RecordedPoolPartitionRepairer())->repair($bytes);
        }
        if (MissingSessionLapRepairer::NATIVE_SUMMARY_RULE === $rule) {
            return (new MissingSessionLapRepairer())->repair(EditableFitFile::read($bytes), true);
        }
        if (RecordedLapReferenceRepairer::SAVED_BOUNDARIES_RULE === $rule) {
            return (new RecordedLapReferenceRepairer())->repair($bytes, true);
        }
        if (RecordedActivitySummaryRepairer::SUMMARY_ONLY_RULE === $rule) {
            return (new RecordedActivitySummaryRepairer())->repairSummaryOnly($bytes);
        }
        if (RecordedSessionLapPartitionRepairer::RULE === $rule) {
            return (new RecordedSessionLapPartitionRepairer())->repair($bytes);
        }
        if (RecordedObservationOrderRepairer::MULTI_SESSION_RULE === $rule) {
            return (new RecordedObservationOrderRepairer())->repair($bytes, true);
        }
        if (SegmentLapElapsedRepairer::CLOCK_RULE === $rule) {
            return (new SegmentLapElapsedRepairer())->repair($bytes, true);
        }
        if (RecordedLapReferenceRepairer::RULE === $rule) {
            return (new RecordedLapReferenceRepairer())->repair($bytes);
        }
        if (RecordedMissingSetLapRepairer::RULE === $rule) {
            return (new RecordedMissingSetLapRepairer())->repair($bytes);
        }
        if (RecordedLapElapsedRepairer::RULE === $rule) {
            return (new RecordedLapElapsedRepairer())->repair($bytes);
        }
        if (RecordedLapElapsedRepairer::CLOCK_RULE === $rule) {
            return (new RecordedLapElapsedRepairer())->repair($bytes, true);
        }
        if (RecordedSingleLapFieldsRepairer::RULE === $rule) {
            return (new RecordedSingleLapFieldsRepairer())->repair($bytes);
        }
        if (RecordedMovingTimerRepairer::RULE === $rule) {
            return (new RecordedMovingTimerRepairer())->repair($bytes);
        }
        if (RecordedActivitySummaryRepairer::RULE === $rule) {
            return (new RecordedActivitySummaryRepairer())->repair($bytes);
        }
        if (RecordedSportRepairer::RULE === $rule) {
            return (new RecordedSportRepairer())->repair($bytes);
        }
        if (RecordedSportRepairer::UNKNOWN_RULE === $rule) {
            return (new RecordedSportRepairer())->repair($bytes, true);
        }
        if (RecordedActivitySummaryRepairer::PREFIX_RULE === $rule) {
            return (new RecordedActivitySummaryRepairer())->repair($bytes, true);
        }
        if (RecordedSingleLapFieldsRepairer::STOP_TRACE_RULE === $rule) {
            return (new RecordedSingleLapFieldsRepairer())->repair($bytes, true);
        }
        if (RecordedSingleLapFieldsRepairer::EXTENT_RULE === $rule) {
            return (new RecordedSingleLapFieldsRepairer())->repair($bytes, observedExtent: true);
        }
        if (RecordedActivitySummaryRepairer::SESSION_TIMERS_RULE === $rule) {
            return (new RecordedActivitySummaryRepairer())->repairSessionTimers($bytes);
        }
        if (RecordedActivitySummaryRepairer::OBSERVED_SESSION_RULE === $rule) {
            return (new RecordedActivitySummaryRepairer())->repairObservedSessionTimer($bytes);
        }
        if (RecordedRecordClockRepairer::DATE_RULE === $rule || RecordedRecordClockRepairer::PREFIX_RULE === $rule) {
            return (new RecordedRecordClockRepairer())->repair($bytes, RecordedRecordClockRepairer::PREFIX_RULE === $rule);
        }
        if (RecordedSummarySaveRepairer::RULE === $rule || RecordedSummarySaveRepairer::MINIMUM_RULE === $rule) {
            return (new RecordedSummarySaveRepairer())->repair($bytes, RecordedSummarySaveRepairer::MINIMUM_RULE === $rule);
        }

        if (RecordedPoolTimestampRepairer::RULE === $rule) {
            return (new RecordedPoolTimestampRepairer())->repair($bytes);
        }

        if (RecordedSummaryDurationReconciler::CLOCK_RULE === $rule) {
            return (new RecordedSummaryDurationReconciler())->repair($bytes, true);
        }

        if (RecordedSummaryDurationReconciler::RULE === $rule) {
            return (new RecordedSummaryDurationReconciler())->repair($bytes);
        }

        if (RecordedLapPartitionStartRepairer::NATIVE_RULE === $rule) {
            return (new RecordedLapPartitionStartRepairer())->repairNativeSummaries($bytes);
        }

        if (SummaryClockReconciler::JOINT_RULE === $rule) {
            return (new SummaryClockReconciler())->repair($bytes, true);
        }

        if (self::RECONCILED_SUMMARY_CLOCKS === $rule) {
            return (new SummaryClockReconciler())->repair($bytes);
        }

        if (self::RECORDED_LAP_PARTITION_START === $rule) {
            return (new RecordedLapPartitionStartRepairer())->repair($bytes);
        }

        if (self::PAUSED_LAP_PARTITION_START === $rule) {
            return (new RecordedLapPartitionStartRepairer())->repair($bytes, true);
        }

        if (self::RECORDED_LENGTH_LAPS === $rule) {
            return (new RecordedLengthLapRepairer())->repair($bytes);
        }

        if (self::RECORDED_LENGTH_BOUNDARIES === $rule) {
            return (new RecordedLengthBoundaryRepairer())->repair($bytes);
        }

        if (self::PAUSED_LAP_START === $rule) {
            return (new PausedLapStartRepairer())->repair($bytes);
        }

        if (self::RECORDED_SPAN_START === $rule) {
            return (new RecordedSpanStartRepairer())->repair($bytes);
        }

        if (self::RECORDED_LAP_BOUNDARY === $rule) {
            return (new RecordedLapBoundaryRepairer())->repair($bytes);
        }

        if (self::RECORDED_LAP_END === $rule) {
            return (new RecordedLapEndRepairer())->repair($bytes);
        }

        if (self::SEGMENT_LAP_ELAPSED === $rule) {
            return (new SegmentLapElapsedRepairer())->repair($bytes);
        }

        if (self::SINGLE_LAP_DURATIONS === $rule) {
            return (new SingleLapDurationRepairer())->repair($bytes);
        }

        if (self::IMPOSSIBLE_SINGLE_LAP_START === $rule) {
            return (new SingleLapDurationRepairer())->repair($bytes, recoverImpossibleStart: true);
        }

        if (self::SINGLE_LAP_START === $rule) {
            return (new SingleLapDurationRepairer())->repair($bytes, recoverStart: true);
        }

        if (self::REPEATED_LAP_INDEXES === $rule) {
            return (new RepeatedLapIndexRepairer())->repair($bytes);
        }

        if (self::ONE_BASED_LAP_INDEXES === $rule) {
            return (new OneBasedLapIndexRepairer())->repair($bytes);
        }

        if (self::RESERVED_SESSION_INDEX === $rule) {
            return (new ReservedSessionIndexRepairer())->repair($bytes);
        }

        if (self::RECORDED_SUMMARY_TIMESTAMPS === $rule) {
            return (new RecordedSummaryTimestampRepairer())->repair($bytes);
        }

        if (self::FRONT_SUMMARY_CLOCKS === $rule) {
            return (new RecordedLapPartitionStartRepairer())->repairFrontSummaries($bytes);
        }

        if (self::SESSION_ELAPSED_FROM_LAPS === $rule) {
            return (new SessionElapsedFromLapsRepairer())->repair($bytes);
        }

        if (self::UNPAUSED_LAP_TIMER === $rule) {
            return (new UnpausedLapTimerRepairer())->repair($bytes);
        }

        if (self::UNPAUSED_FINAL_LAP === $rule) {
            return (new UnpausedFinalLapRepairer())->repair($bytes);
        }

        if (self::RECORDED_OBSERVATION_ORDER === $rule) {
            return (new RecordedObservationOrderRepairer())->repair($bytes);
        }

        $file = EditableFitFile::read($bytes);

        if (MissingSessionLapRepairer::ACTIVITY_RULE === $rule) {
            return (new MissingSessionLapRepairer())->repairActivity($file);
        }

        if (self::FILE_ID_FIRST === $rule) {
            return (new FileIdOrderingRepairer())->repair($file);
        }

        if (self::MISSING_LAP === $rule) {
            return (new MissingSessionLapRepairer())->repair($file);
        }

        $changes = match ($rule) {
            self::SESSION_INDEX => $this->indexes($file, 18),
            self::LAP_INDEX => $this->indexes($file, 19),
            self::SESSION_LAP_REFERENCES => $this->lapReferences($file),
            self::LOCAL_TIMESTAMP => $this->localTimestamp($file, $localTimeOffset),
            self::RECORDED_TIMER_START => $this->timerStart($file),
            self::SUMMARY_TIMER_START => $this->timerStart($file, true),
            default => throw new RepairFailed('Unsupported repair rule: '.$rule),
        };
        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => 'preserve',
            'rule' => $rule,
            'input_sha256' => hash('sha256', $bytes),
            'output_sha256' => hash('sha256', $output),
            'changes' => $changes,
            ...(self::RECORDED_TIMER_START === $rule ? ['evidence' => [
                'source' => 'identical Session.start_time, Lap.start_time and first Record timestamp',
                'corroboration' => 'same-group uninterrupted timer STOP, final Record, all three summary timestamps, exact recorded elapsed and timer durations',
            ]] : []),
            ...(self::SUMMARY_TIMER_START === $rule ? ['inference' => [
                'historical_accuracy' => 'not_proven', 'source' => 'identical_native_lap_and_session_starts',
                'policy' => 'fill only a missing timer START clock from both native summary starts; original STOP, Records and all duration counters retained; counter/clock differences are not erased',
            ]] : []),
            'preservation' => 'verified',
            'activity_validation' => 'not_performed',
        ]);
    }

    /** @return list<array{message: int, field: int, type: int, value: int}> */
    private function timerStart(EditableFitFile $file, bool $sharedSummaryStarts = false): array
    {
        $summaries = $events = [];
        $first = $last = $firstSequence = $lastSequence = null;
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], 0);
            $types = match ($message->number) {
                0 => [0 => [0x00, 0x02]],
                18 => [2 => [0x86], 7 => [0x86], 8 => [0x86], 253 => [0x86], 254 => [0x84], 25 => [0x84], 26 => [0x84]],
                19 => [2 => [0x86], 7 => [0x86], 8 => [0x86], 253 => [0x86], 254 => [0x84]],
                20 => [253 => [0x86]],
                21 => [0 => [0x00, 0x02], 1 => [0x00, 0x02], 4 => [0x02], 253 => [0x86]],
                34 => [0 => [0x86], 1 => [0x84], 253 => [0x86]],
                default => [],
            };
            $seen = [];
            foreach ($message->fields as $field) {
                $number = $field->definition->fieldNumber;
                if (!isset($types[$number])) {
                    continue;
                }
                $size = 0x86 === $types[$number][0] ? 4 : (0x84 === $types[$number][0] ? 2 : 1);
                if (isset($seen[$number]) || $field->definition->size !== $size
                    || !in_array($field->definition->baseType->definitionByte(), $types[$number], true)) {
                    throw new RepairFailed('Timer START evidence requires unique scalar fields of the recorded physical type.');
                }
                $seen[$number] = true;
            }
            if (0 === $message->number) {
                ++$fileIds;
            }
            if (in_array($message->number, [18, 19, 34], true)) {
                if (isset($summaries[$message->number])) {
                    throw new RepairFailed('Timer START recovery requires one Lap, Session and Activity.');
                }
                $summaries[$message->number] = $message;
            } elseif (21 === $message->number) {
                if (null === $message->integer(0)) {
                    throw new RepairFailed('A missing Event kind makes the timer trace ambiguous.');
                }
                if (0 === $message->integer(0)) {
                    $events[$index] = $message;
                }
            } elseif (20 === $message->number) {
                $timestamp = $message->integer(253);
                if (null === $timestamp || $timestamp < 0x10000000 || (null !== $last && $timestamp < $last)) {
                    throw new RepairFailed('Timer START recovery requires ordered absolute Record timestamps.');
                }
                $first ??= $timestamp;
                $firstSequence ??= $message->sourceSequence;
                $last = $timestamp;
                $lastSequence = $message->sourceSequence;
            }
        }
        if (1 !== $fileIds || 3 !== count($summaries) || 2 !== count($events) || null === $first || $last <= $first
            || 0 !== $file->messages[0]['raw']->globalMessageNumber()
            || 4 !== PhysicalMessage::fromRaw($file->messages[0]['raw'], 0)->integer(0)) {
            throw new RepairFailed('Timer START recovery requires a complete single-Lap Activity and one timer pair.');
        }
        $lap = $summaries[19];
        $session = $summaries[18];
        $activity = $summaries[34];
        [$startEvent, $stopEvent] = array_values($events);
        $start = $session->integer(2);
        $finish = $stopEvent->integer(253);
        $elapsed = $session->integer(7);
        if (null === $start || $start < 0x10000000 || null === $finish || null === $elapsed || $elapsed <= 0
            || 0 !== $elapsed % 1000 || ($sharedSummaryStarts ? ($finish - $start) * 1000 < $elapsed : ($finish - $start) * 1000 !== $elapsed)
            || $lap->integer(2) !== $start || ($sharedSummaryStarts ? $first < $start || $first > $finish : $first !== $start) || $last !== $finish
            || $lap->integer(7) !== $elapsed || $lap->integer(8) !== $elapsed
            || $session->integer(8) !== $elapsed || $activity->integer(0) !== $elapsed
            || $lap->integer(253) !== $finish || $session->integer(253) !== $finish || $activity->integer(253) !== $finish
            || 0 !== $lap->integer(254) || 0 !== $session->integer(254)
            || 0 !== $session->integer(25) || 1 !== $session->integer(26) || 1 !== $activity->integer(1)
            || null !== $startEvent->integer(253) || 0 !== $startEvent->integer(1)
            || !in_array($stopEvent->integer(1), [1, 4], true)
            || null === $startEvent->integer(4) || $startEvent->integer(4) !== $stopEvent->integer(4)
            || $startEvent->sourceSequence >= $firstSequence || $lastSequence >= $stopEvent->sourceSequence
            || $stopEvent->sourceSequence >= $lap->sourceSequence || $lap->sourceSequence >= $session->sourceSequence
            || $session->sourceSequence >= $activity->sourceSequence) {
            throw new RepairFailed('Only a missing START clock corroborated by both recorded starts, endpoint Records, STOP and exact unchanged durations can be copied.');
        }

        return [['message' => array_key_first($events), 'field' => 253, 'type' => 0x86, 'value' => $start]];
    }

    /** @return list<array{message: int, field: int, type: int, value: int}> */
    private function indexes(EditableFitFile $file, int $number): array
    {
        $sessions = [];
        $laps = [];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if (18 === $message->number) {
                $sessions[$index] = $message;
            } elseif (19 === $message->number) {
                $laps[$index] = $message;
            }
        }

        if ([] === $sessions || [] === $laps || 4096 < count($sessions) || 4096 < count($laps)) {
            throw new RepairFailed('Index repair requires sessions and laps within the FIT index range.');
        }

        foreach ([$sessions, $laps] as $messages) {
            $ordinal = 0;

            foreach ($messages as $message) {
                $existing = $message->integer(254);

                if (null !== $existing && $existing !== $ordinal) {
                    throw new RepairFailed('Existing summary indexes contradict message order.');
                }

                ++$ordinal;
            }
        }

        $nextLap = 0;

        foreach ($sessions as $session) {
            $first = $session->integer(25);
            $count = $session->integer(26);

            if (null === $first || null === $count || $first !== $nextLap || 1 > $count || $first + $count > count($laps)) {
                throw new RepairFailed('Session lap references do not establish a complete, unambiguous partition.');
            }

            $nextLap += $count;
        }

        if ($nextLap !== count($laps)) {
            throw new RepairFailed('Session references leave unassigned laps.');
        }

        $changes = [];
        $ordinal = 0;

        foreach (18 === $number ? $sessions : $laps as $index => $message) {
            if (null === $message->integer(254)) {
                $changes[] = ['message' => $index, 'field' => 254, 'type' => 0x84, 'value' => $ordinal];
            }

            ++$ordinal;
        }

        return $changes;
    }

    /** @return list<array{message: int, field: int, type: int, value: int}> */
    private function lapReferences(EditableFitFile $file): array
    {
        $sessions = [];
        $laps = [];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if (18 === $message->number) {
                $sessions[$index] = $message;
            } elseif (19 === $message->number) {
                $laps[] = $message;
            }
        }

        if (1 !== count($sessions) || [] === $laps || 4096 < count($laps)) {
            throw new RepairFailed(sprintf('Lap reference repair requires exactly one Session and 1..4096 Laps; found %d Sessions and %d Laps.', count($sessions), count($laps)));
        }

        $index = array_key_first($sessions);
        $session = $sessions[$index];
        $first = $session->integer(25);
        $count = $session->integer(26);

        if ((null !== $first && 0 !== $first) || (null !== $count && count($laps) !== $count)) {
            throw new RepairFailed('Existing Session lap references contradict the single-session lap set.');
        }

        if (null !== $first && null !== $count) {
            return [];
        }

        if (null !== $session->integer(254) && 0 !== $session->integer(254)) {
            throw new RepairFailed('Existing Session index contradicts the single-session lap set.');
        }

        [$start, $end] = $this->boundaries($session);

        foreach ($laps as $ordinal => $lap) {
            if (null !== $lap->integer(254) && $ordinal !== $lap->integer(254)) {
                throw new RepairFailed('Existing Lap indexes contradict message order.');
            }

            [$lapStart, $lapEnd] = $this->boundaries($lap);

            // Timestamp resolution is one second; elapsed time is milliseconds.
            if ($lapStart < $start || $lapStart > $end || $lapEnd > $end + 1000) {
                throw new RepairFailed('A Lap falls outside the sole Session boundaries.');
            }
        }

        $changes = [];

        if (null === $first) {
            $changes[] = ['message' => $index, 'field' => 25, 'type' => 0x84, 'value' => 0];
        }

        if (null === $count) {
            $changes[] = ['message' => $index, 'field' => 26, 'type' => 0x84, 'value' => count($laps)];
        }

        return $changes;
    }

    /** @return array{int, int} Start and calculated end in milliseconds. */
    private function boundaries(PhysicalMessage $message): array
    {
        $values = [];

        foreach ($message->fields as $field) {
            $number = $field->definition->fieldNumber;

            if (!in_array($number, [2, 7, 253], true)) {
                continue;
            }

            if (isset($values[$number]) || 4 !== $field->definition->size || 0x86 !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Summary boundaries require unique uint32 fields.');
            }

            $value = $message->integer($number);

            if (null === $value) {
                throw new RepairFailed('Summary boundaries contain an invalid value.');
            }

            $values[$number] = $value;
        }

        if (!isset($values[2], $values[7], $values[253]) || (($values[2] < 0x10000000) !== ($values[253] < 0x10000000))) {
            throw new RepairFailed('Summary start time, timestamp and elapsed time are required for lap reference repair.');
        }

        $start = $values[2] * 1000;
        $end = $start + $values[7];

        // Timestamp identifies when the summary was written. Summary-first
        // files and delayed saves need not write it at the calculated end.

        return [$start, $end];
    }

    /** @return list<array{message: int, field: int, type: int, value: int}> */
    private function localTimestamp(EditableFitFile $file, ?int $offset): array
    {
        if (null === $offset) {
            throw new RepairFailed('An explicit local time offset is required for this rule.');
        }

        $changes = [];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if (34 !== $message->number) {
                continue;
            }

            $timestamp = $message->integer(253);
            $local = $message->integer(5);

            if (null === $timestamp) {
                throw new RepairFailed('Activity timestamp is required to restore local time.');
            }

            if (null !== $local) {
                if ($local - $timestamp !== $offset) {
                    throw new RepairFailed('Existing Activity local time contradicts the explicit offset.');
                }

                continue;
            }

            $changes[] = ['message' => $index, 'field' => 5, 'type' => 0x86, 'value' => $timestamp + $offset];
        }

        return $changes;
    }
}
