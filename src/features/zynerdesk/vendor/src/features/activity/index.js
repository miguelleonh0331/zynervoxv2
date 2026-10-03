"use strict";

const config = require("../../shared/config");

const ACTIVITY_EVENT_TYPES = new Set([
  "app_opened", "app_closed", "foreground_started", "foreground_ended", "input_state", "clipboard_text",
  "keyboard_block"
]);
const ALLOW_CLIPBOARD_TEXT = config.ALLOW_CLIPBOARD_TEXT;

function createActivityService({ pool, limitedString }) {
  function normalizeProcessName(value) {
    const normalized = String(value || "").trim().toLowerCase().slice(0, 156);
    if (!normalized || !/^[a-z0-9_. -]+$/.test(normalized)) return null;
    return normalized.endsWith(".exe") ? normalized : `${normalized}.exe`;
  }

  function normalizeActivityEvent(raw) {
    if (!raw || typeof raw !== "object") return null;
    const eventId = String(raw.id || "");
    const type = String(raw.type || "");
    const timestamp = Number(raw.timestamp);
    const now = Date.now();
    if (!/^[a-f0-9]{64}$/i.test(eventId) || !ACTIVITY_EVENT_TYPES.has(type) || !Number.isFinite(timestamp)) return null;
    if (timestamp < now - 90 * 86400000 || timestamp > now + 5 * 60000) return null;

    let keyboardBlock = null;
    if (type === "keyboard_block") {
      const blockStart = Number(raw.blockStart);
      const blockEnd = Number(raw.blockEnd);
      const keypressCount = Number(raw.keypressCount);
      const firstKeyAt = raw.firstKeyAt === null ? null : Number(raw.firstKeyAt);
      const lastKeyAt = raw.lastKeyAt === null ? null : Number(raw.lastKeyAt);
      const duration = blockEnd - blockStart;
      if (!Number.isFinite(blockStart) || !Number.isFinite(blockEnd) || duration <= 0 || duration > 5 * 60000
        || Math.abs(timestamp - blockEnd) > 1000 || blockStart < now - 90 * 86400000 || blockEnd > now + 5 * 60000
        || !Number.isInteger(keypressCount) || keypressCount < 0 || keypressCount > 50000) return null;
      if (keypressCount === 0 && (firstKeyAt !== null || lastKeyAt !== null)) return null;
      if (keypressCount > 0 && (!Number.isFinite(firstKeyAt) || !Number.isFinite(lastKeyAt)
        || firstKeyAt > lastKeyAt || firstKeyAt < blockStart - 1000 || lastKeyAt > blockEnd + 1000)) return null;
      keyboardBlock = {
        blockStart: new Date(blockStart),
        blockEnd: new Date(blockEnd),
        keypressCount,
        firstKeyAt: firstKeyAt === null ? null : new Date(firstKeyAt),
        lastKeyAt: lastKeyAt === null ? null : new Date(lastKeyAt),
        complete: raw.complete === true
      };
    }

    const durationValue = type === "app_closed" ? raw.openDurationMs
      : type === "foreground_ended" ? raw.foregroundDurationMs : null;
    const durationMs = Number.isFinite(Number(durationValue)) ? Math.max(0, Math.round(Number(durationValue))) : null;
    const idleMs = Number.isFinite(Number(raw.idleMs)) ? Math.max(0, Math.round(Number(raw.idleMs))) : null;
    const charCount = Number.isFinite(Number(raw.charCount)) ? Math.max(0, Math.round(Number(raw.charCount))) : null;
    const clipboardHash = /^[a-f0-9]{64}$/i.test(String(raw.sha256 || "")) ? String(raw.sha256).toLowerCase() : null;

    return {
      id: eventId.toLowerCase(),
      type,
      eventTime: new Date(timestamp),
      processName: normalizeProcessName(raw.processName),
      windowTitle: limitedString(raw.windowTitle, 500),
      state: ["active", "idle"].includes(raw.state) ? raw.state : null,
      durationMs,
      idleMs,
      charCount,
      clipboardSha256: clipboardHash,
      clipboardText: ALLOW_CLIPBOARD_TEXT && type === "clipboard_text" && raw.text !== null && raw.text !== undefined
        ? String(raw.text).slice(0, 16000) : null,
      sourceProcess: normalizeProcessName(raw.inferredSourceProcess),
      sourceWindowTitle: limitedString(raw.inferredWindowTitle, 500),
      keyboardBlock
    };
  }

  const ACTIVITY_TIME_ZONE = "America/Lima";
  const REPORT_GAP_MAX_MS = 2 * 60 * 1000;
  const MIN_STATS_EVALUATED_SECONDS = 30 * 60;
  const ACTIVITY_BREAK_START = config.ACTIVITY_BREAK_START;
  const ACTIVITY_BREAK_END = config.ACTIVITY_BREAK_END;
  const PAUSE_ALLOWANCE_SECONDS_PER_HOUR = 5 * 60;
  // v3: after 3+ consecutive keyboard blocks without keypresses (15+ min), monitored-app
  // foreground stops counting as assisted activity and the time is counted as inactive.
  const ACTIVITY_ASSISTED_MAX_IDLE_BLOCKS = 3;

  function limaDate(value) {
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone: ACTIVITY_TIME_ZONE, year: "numeric", month: "2-digit", day: "2-digit"
    }).formatToParts(value);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
  }

  function mergeActivityIntervals(intervals, start, end) {
    const clipped = intervals.map(([a, b]) => [Math.max(a, start), Math.min(b, end)])
      .filter(([a, b]) => b > a).sort((a, b) => a[0] - b[0]);
    const merged = [];
    for (const interval of clipped) {
      const last = merged[merged.length - 1];
      if (last && interval[0] <= last[1]) last[1] = Math.max(last[1], interval[1]);
      else merged.push(interval);
    }
    return merged;
  }

  function intervalSeconds(intervals) {
    return Math.max(0, Math.round(intervals.reduce((total, [start, end]) => total + end - start, 0) / 1000));
  }

  function maxInactiveSeconds(activeIntervals, start, end) {
    let maxGap = 0;
    let cursor = start;
    for (const [activeStart, activeEnd] of activeIntervals) {
      maxGap = Math.max(maxGap, activeStart - cursor);
      cursor = Math.max(cursor, activeEnd);
    }
    return Math.max(0, Math.round(Math.max(maxGap, end - cursor) / 1000));
  }

  function localScheduleTimestamp(workDay, timeText) {
    const validTime = /^([01]\d|2[0-3]):[0-5]\d$/.test(String(timeText));
    if (!validTime) return null;
    const timestamp = Date.parse(`${workDay}T${timeText}:00-05:00`);
    return Number.isFinite(timestamp) ? timestamp : null;
  }

  function subtractInterval(start, end, excludedStart, excludedEnd) {
    if (!Number.isFinite(excludedStart) || !Number.isFinite(excludedEnd) || excludedEnd <= start || excludedStart >= end) {
      return [[start, end]];
    }
    const intervals = [];
    if (excludedStart > start) intervals.push([start, Math.min(end, excludedStart)]);
    if (excludedEnd < end) intervals.push([Math.max(start, excludedEnd), end]);
    return intervals.filter(([a, b]) => b > a);
  }

  function overlapMilliseconds(start, end, intervals) {
    return intervals.reduce((total, [intervalStart, intervalEnd]) => {
      return total + Math.max(0, Math.min(end, intervalEnd) - Math.max(start, intervalStart));
    }, 0);
  }

  async function recalculateAgentDailyStats(agentId, workDay) {
    const [reports] = await pool.query(
      `SELECT reported_at FROM agent_activity_reports
        WHERE agent_id=? AND DATE(CONVERT_TZ(reported_at,'+00:00','-05:00'))=?
        ORDER BY reported_at`, [agentId, workDay]);
    if (!reports.length) return;

    const reportTimes = reports.map((row) => new Date(row.reported_at).getTime());
    const telemetryStart = reportTimes[0];
    const telemetryEnd = reportTimes[reportTimes.length - 1];
    let telemetryEvaluatedMs = 0;
    for (let index = 1; index < reportTimes.length; index += 1) {
      const gap = reportTimes[index] - reportTimes[index - 1];
      if (gap > 0 && gap <= REPORT_GAP_MAX_MS) telemetryEvaluatedMs += gap;
    }

    const [keyboardRows] = await pool.query(
      `SELECT block_start, block_end, keypress_count
         FROM agent_keyboard_blocks
        WHERE agent_id=? AND DATE(CONVERT_TZ(block_start,'+00:00','-05:00'))=?
        ORDER BY block_start`, [agentId, workDay]);
    const [foregroundRows] = await pool.query(
      `SELECT event_time, duration_ms, process_name
         FROM agent_activity_events
        WHERE agent_id=? AND event_type='foreground_ended'
          AND DATE(CONVERT_TZ(event_time,'+00:00','-05:00'))=?`, [agentId, workDay]);
    const [monitoredApps] = await pool.query(`SELECT process_name FROM monitored_apps`);
    const productiveProcesses = new Set(monitoredApps.map((row) => String(row.process_name || "").trim().toLowerCase()).filter(Boolean));
    const keyboardIntervals = keyboardRows.filter((row) => Number(row.keypress_count) > 0)
      .map((row) => [new Date(row.block_start).getTime(), new Date(row.block_end).getTime()]);
    const foregroundIntervals = foregroundRows.filter((row) => Number(row.duration_ms) > 0
        && productiveProcesses.has(String(row.process_name || "").trim().toLowerCase()))
      .map((row) => {
        const end = new Date(row.event_time).getTime();
        return [end - Number(row.duration_ms), end];
      });
    let breakStart = localScheduleTimestamp(workDay, ACTIVITY_BREAK_START);
    let breakEnd = localScheduleTimestamp(workDay, ACTIVITY_BREAK_END);
    if (Number.isFinite(breakStart) && Number.isFinite(breakEnd) && breakEnd <= breakStart) breakEnd += 24 * 60 * 60 * 1000;
    const allKeyboardIntervals = keyboardRows.map((row) => [new Date(row.block_start).getTime(), new Date(row.block_end).getTime()]);
    const keyboardStart = allKeyboardIntervals.length ? Math.min(...allKeyboardIntervals.map(([start]) => start)) : null;
    const keyboardEnd = allKeyboardIntervals.length ? Math.max(...allKeyboardIntervals.map(([, end]) => end)) : null;
    const activityIntervals = [...keyboardIntervals, ...foregroundIntervals]
      .flatMap(([start, end]) => subtractInterval(start, end, breakStart, breakEnd));
    const observedStart = activityIntervals.length
      ? Math.min(...activityIntervals.map(([start]) => start))
      : keyboardStart ?? telemetryStart;
    const observedEnd = activityIntervals.length
      ? Math.max(...activityIntervals.map(([, end]) => end))
      : keyboardEnd ?? telemetryEnd;
    const mergedForeground = mergeActivityIntervals(foregroundIntervals, observedStart, observedEnd);
    let evaluatedMs = 0;
    let keyboardMs = 0;
    let foregroundMs = 0;
    let assistedMs = 0;
    let inactiveMs = 0;
    let excludedBreakMs = 0;
    let keypressCount = 0;
    let activeBlocks = 0;
    let inactiveBlocks = 0;
    let idleBlockStreak = 0;
    const inactiveIntervals = [];

    for (const row of keyboardRows) {
      const rawStart = new Date(row.block_start).getTime();
      const rawEnd = new Date(row.block_end).getTime();
      const start = Math.max(rawStart, observedStart);
      const end = Math.min(rawEnd, observedEnd);
      if (end <= start) continue;
      const excludedMs = Number.isFinite(breakStart) && Number.isFinite(breakEnd)
        ? Math.max(0, Math.min(end, breakEnd) - Math.max(start, breakStart)) : 0;
      excludedBreakMs += excludedMs;
      const segments = subtractInterval(start, end, breakStart, breakEnd);
      if (!segments.length) continue;
      const hasKeyboard = Number(row.keypress_count) > 0;
      if (hasKeyboard) idleBlockStreak = 0;
      const assistedBlocked = !hasKeyboard && idleBlockStreak >= ACTIVITY_ASSISTED_MAX_IDLE_BLOCKS;
      let blockEvaluated = false;
      for (const [segmentStart, segmentEnd] of segments) {
        const segmentMs = segmentEnd - segmentStart;
        if (segmentMs <= 0) continue;
        blockEvaluated = true;
        evaluatedMs += segmentMs;
        const processOverlapMs = overlapMilliseconds(segmentStart, segmentEnd, mergedForeground);
        foregroundMs += Math.min(segmentMs, processOverlapMs);
        if (hasKeyboard) {
          keyboardMs += segmentMs;
        } else if (processOverlapMs > 0 && !assistedBlocked) {
          assistedMs += segmentMs;
        } else {
          inactiveMs += segmentMs;
          inactiveIntervals.push([segmentStart, segmentEnd]);
        }
      }
      if (blockEvaluated) {
        keypressCount += Number(row.keypress_count || 0);
        if (hasKeyboard) {
          activeBlocks += 1;
        } else {
          inactiveBlocks += 1;
          idleBlockStreak += 1;
        }
      }
    }

    if (!keyboardRows.length) evaluatedMs = telemetryEvaluatedMs;
    const evaluatedSeconds = Math.max(0, Math.round(evaluatedMs / 1000));
    const keyboardSeconds = Math.min(evaluatedSeconds, Math.round(keyboardMs / 1000));
    const assistedSeconds = Math.min(evaluatedSeconds - keyboardSeconds, Math.round(assistedMs / 1000));
    const activeSeconds = Math.min(evaluatedSeconds, keyboardSeconds + assistedSeconds);
    const foregroundSeconds = Math.min(evaluatedSeconds, Math.round(foregroundMs / 1000));
    const inactiveSeconds = Math.min(evaluatedSeconds, Math.round(inactiveMs / 1000));
    const mergedInactive = mergeActivityIntervals(inactiveIntervals, observedStart, observedEnd);
    const maxInactive = mergedInactive.length
      ? Math.max(...mergedInactive.map(([start, end]) => Math.round((end - start) / 1000))) : 0;
    const allowedPauseSeconds = Math.round(evaluatedSeconds * PAUSE_ALLOWANCE_SECONDS_PER_HOUR / 3600);
    const excessPauseSeconds = Math.max(0, inactiveSeconds - allowedPauseSeconds);
    const activityPct = evaluatedSeconds ? Math.min(100, (activeSeconds / evaluatedSeconds) * 100) : 0;
    const semaphore = evaluatedSeconds < MIN_STATS_EVALUATED_SECONDS ? "gray"
      : inactiveSeconds <= allowedPauseSeconds ? "green"
        : inactiveSeconds <= allowedPauseSeconds * 2 ? "yellow" : "red";
    const mergedActive = mergeActivityIntervals(activityIntervals, observedStart, observedEnd);
    const firstActiveAt = mergedActive.length ? new Date(mergedActive[0][0]) : null;
    const lastActiveAt = mergedActive.length ? new Date(mergedActive[mergedActive.length - 1][1]) : null;
    const spanSeconds = firstActiveAt && lastActiveAt ? Math.max(0, Math.round((lastActiveAt - firstActiveAt) / 1000)) : 0;

    await pool.query(
      `INSERT INTO agent_daily_stats
         (agent_id, work_day, first_reported_at, last_reported_at, report_count,
          first_active_at, last_active_at, keypress_count, active_blocks, inactive_blocks, span_seconds,
          evaluated_seconds, active_seconds, keyboard_seconds, foreground_seconds, assisted_seconds,
          inactive_seconds, max_inactive_seconds, authorized_break_seconds, unclassified_break_seconds,
          allowed_pause_seconds, excess_pause_seconds, activity_pct, semaphore, automatic_semaphore,
          calculation_version, calculated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'v3',NOW(3))
       ON DUPLICATE KEY UPDATE
         first_reported_at=VALUES(first_reported_at), last_reported_at=VALUES(last_reported_at),
         report_count=VALUES(report_count), evaluated_seconds=VALUES(evaluated_seconds),
         first_active_at=VALUES(first_active_at), last_active_at=VALUES(last_active_at),
         keypress_count=VALUES(keypress_count), active_blocks=VALUES(active_blocks),
         inactive_blocks=VALUES(inactive_blocks), span_seconds=VALUES(span_seconds),
         active_seconds=VALUES(active_seconds), keyboard_seconds=VALUES(keyboard_seconds),
         foreground_seconds=VALUES(foreground_seconds), assisted_seconds=VALUES(assisted_seconds),
         inactive_seconds=VALUES(inactive_seconds),
         max_inactive_seconds=VALUES(max_inactive_seconds), activity_pct=VALUES(activity_pct),
         authorized_break_seconds=VALUES(authorized_break_seconds),
         unclassified_break_seconds=VALUES(unclassified_break_seconds),
         allowed_pause_seconds=VALUES(allowed_pause_seconds), excess_pause_seconds=VALUES(excess_pause_seconds),
         automatic_semaphore=VALUES(automatic_semaphore),
         semaphore=IF(semaphore_overridden=1,semaphore,VALUES(automatic_semaphore)),
         calculation_version=VALUES(calculation_version),
         calculated_at=VALUES(calculated_at)`,
      [agentId, workDay, new Date(telemetryStart), new Date(telemetryEnd), reports.length,
        firstActiveAt, lastActiveAt, keypressCount, activeBlocks, inactiveBlocks, spanSeconds,
        evaluatedSeconds, activeSeconds, keyboardSeconds, foregroundSeconds, assistedSeconds,
        inactiveSeconds, maxInactive, Math.round(excludedBreakMs / 1000), inactiveSeconds,
        allowedPauseSeconds, excessPauseSeconds, activityPct, semaphore, semaphore]
    );
  }

  return { normalizeActivityEvent, recalculateAgentDailyStats, limaDate };
}

module.exports = { createActivityService };
