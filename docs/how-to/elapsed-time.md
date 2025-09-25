# Elapsed Time on Target Page — Expected Behavior

Purpose:
Display how long the current target has been running (or took to complete) to give users immediate feedback on progress and performance.

When it appears:
- Show the elapsed time whenever a target is in an active lifecycle state (e.g., queued or processing).
- Keep showing a final “Total time” once the target completes (e.g., done), fails, or is cancelled.

Timing sources:
- Start time: Prefer the timestamp indicating when processing started. If not available, fall back to when the target was created.
- Stop time: Prefer the timestamp indicating when processing completed. If not available and the target is still running, use the current time (client clock) for a live counter.

Core behavior:
- While running: Show a live-updating elapsed counter from Start → Now.
- On completion: Freeze at the final elapsed duration from Start → Stop and label as “Total time”.
- On error/cancel: Freeze at the last known Stop timestamp and label as “Stopped”.
- If no reliable Start timestamp is available: Hide the timer or show a placeholder (e.g., —) and do not animate.

Display format:
- Under 1 hour: mm:ss (e.g., 07:12).
- 1 hour or more: h:mm:ss (e.g., 1:03:04).
- 24 hours or more: d h:mm:ss (e.g., 2d 3:05:09).
- Always expose the exact Start and Stop timestamps via an accessible tooltip or details popover (e.g., ISO or localized format).
- Label examples:
  - “Elapsed: 12:34” (running)
  - “Total time: 4:20” (completed)
  - “Stopped: 2:11 (status: failed)” (failed/cancelled)

Update cadence:
- Update the live counter every 1 second while running.
- If the page is hidden (e.g., tab not visible), avoid unnecessary work and catch up on visibility change.
- Stop the timer immediately when the terminal state is detected (done/failed/cancelled).

Status transitions:
- queued → processing: Start the live counter (if a Start timestamp becomes available).
- processing → done: Freeze at final elapsed time and switch label to “Total time”.
- processing → failed/cancelled: Freeze at last known time and switch label to “Stopped”.
- queued with no Start timestamp for an extended period (e.g., > 2 minutes): Optionally display a small “waiting for worker” hint.

Edge cases and safeguards:
- Clock skew: Clamp negative durations to 0 and prefer server-provided timestamps when available.
- Restarted jobs: If the system supports restarts, the elapsed time should by default represent continuous time from the original start. If a reset is required, only do so when a new Start timestamp is explicitly provided.
- Missing timestamps: If Start is missing, hide the timer (or show —) and do not animate to avoid misleading information.
- Accessibility: Wrap the time text in an aria-live="polite" region to announce changes; do not rely on color alone to indicate state.

UI placement:
- Place the elapsed time near the target title and status indicator in a subdued, secondary text style.
- Use concise labels, e.g., “Elapsed: 02:31” during processing, “Total time: 04:20” when done.

Performance considerations:
- Keep a single interval/timer per page to update the element.
- Only update the DOM when the displayed value actually changes.

QA checklist:
1. Running target shows a live second-by-second counter and increases monotonically.
2. Upon completion, the label changes to “Total time” and the value freezes.
3. On failure/cancellation, the label changes to “Stopped” and the value freezes.
4. Hiding the tab pauses unnecessary work; returning to the tab shows a correct, caught-up value.
5. For long-running targets, format changes to h:mm:ss and to d h:mm:ss at the correct thresholds.
6. With no Start timestamp, the timer is hidden or shows a placeholder — and does not animate.
7. Tooltip or details show Start and Stop times in a precise format.
8. Screen readers announce updates without causing excessive chatter (polite live region).

Troubleshooting tips:
- If the timer never appears: verify that a Start timestamp is available once processing begins.
- If the timer never stops: verify that a terminal state and/or Stop timestamp is recorded when the work completes.
- If the value jumps backwards: check for client/server time mismatches; prefer server timestamps for Start/Stop.
- If it keeps counting while the work is done: ensure the page receives the terminal state and stops its interval.
