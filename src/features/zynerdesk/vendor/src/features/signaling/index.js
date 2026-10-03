"use strict";

const WebSocket = require("ws");

function registerSignaling(ctx) {
  const {
    server,
    pool,
    getSessionUser,
    canAccessAgent,
    isAdmin,
    isAgentTokenAuthorized,
    limitedString
  } = ctx;

  const rooms = new Map(); // agentId -> { agent, agentAudioAuthorized, agentCameraAuthorized, viewers, audioSession, cameraSession }
  function getRoom(id) {
    if (!rooms.has(id)) rooms.set(id, {
      agent: null,
      agentAudioAuthorized: false,
      agentCameraAuthorized: false,
      viewers: new Set(),
      audioSession: null,
      cameraSession: null
    });
    return rooms.get(id);
  }
  function wsSend(ws, msg) { if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(msg)); }

  function clearAudioSessionTimers(session) {
    if (!session) return;
    if (session.authTimer) clearInterval(session.authTimer);
    if (session.startTimer) clearTimeout(session.startTimer);
    if (session.stopTimer) clearTimeout(session.stopTimer);
  }

  async function setAudioAuditState(session, state, reason = null, errorDetail = null) {
    if (!session || !session.auditId) return;
    if (state === "listening") {
      await pool.query(
        `UPDATE audio_listening_sessions
            SET status='listening', started_at=COALESCE(started_at,NOW(3)), last_state_at=NOW(3)
          WHERE id=? AND ended_at IS NULL`, [session.auditId]);
      return;
    }
    await pool.query(
      `UPDATE audio_listening_sessions
          SET status=?, end_reason=COALESCE(?,end_reason), error_detail=COALESCE(?,error_detail),
              last_state_at=NOW(3)
        WHERE id=? AND ended_at IS NULL`, [state, reason, errorDetail, session.auditId]);
  }

  async function finishAudioSession(room, status, reason, errorDetail = null, notifyAgent = false) {
    const session = room && room.audioSession;
    if (!session) return;
    room.audioSession = null;
    clearAudioSessionTimers(session);
    if (notifyAgent && room.agent) {
      wsSend(room.agent, { type: "audio:stop", agentId: session.agentId, reason });
    }
    wsSend(session.viewer, { type: "audio:ended", state: status, reason, error: errorDetail || "" });
    try {
      await pool.query(
        `UPDATE audio_listening_sessions
            SET status=?, ended_at=COALESCE(ended_at,NOW(3)), end_reason=?,
                error_detail=COALESCE(?,error_detail), last_state_at=NOW(3)
          WHERE id=?`, [status, reason, errorDetail, session.auditId]);
    } catch (error) {
      console.error("[audio audit]", error.message);
    }
  }

  // ---- Camara web bajo demanda: mismo patron que audio (solo-ver, sin
  // grabacion, candado de una sesion por agente, auditoria en MySQL). ----

  function clearCameraSessionTimers(session) {
    if (!session) return;
    if (session.authTimer) clearInterval(session.authTimer);
    if (session.startTimer) clearTimeout(session.startTimer);
    if (session.stopTimer) clearTimeout(session.stopTimer);
  }

  async function setCameraAuditState(session, state, reason = null, errorDetail = null) {
    if (!session || !session.auditId) return;
    if (state === "viewing") {
      await pool.query(
        `UPDATE camera_viewing_sessions
            SET status='viewing', started_at=COALESCE(started_at,NOW(3)), last_state_at=NOW(3)
          WHERE id=? AND ended_at IS NULL`, [session.auditId]);
      return;
    }
    await pool.query(
      `UPDATE camera_viewing_sessions
          SET status=?, end_reason=COALESCE(?,end_reason), error_detail=COALESCE(?,error_detail),
              last_state_at=NOW(3)
        WHERE id=? AND ended_at IS NULL`, [state, reason, errorDetail, session.auditId]);
  }

  async function finishCameraSession(room, status, reason, errorDetail = null, notifyAgent = false) {
    const session = room && room.cameraSession;
    if (!session) return;
    room.cameraSession = null;
    clearCameraSessionTimers(session);
    if (notifyAgent && room.agent) {
      wsSend(room.agent, { type: "camera:stop", agentId: session.agentId, reason });
    }
    wsSend(session.viewer, { type: "camera:ended", state: status, reason, error: errorDetail || "" });
    try {
      await pool.query(
        `UPDATE camera_viewing_sessions
            SET status=?, ended_at=COALESCE(ended_at,NOW(3)), end_reason=?,
                error_detail=COALESCE(?,error_detail), last_state_at=NOW(3)
          WHERE id=?`, [status, reason, errorDetail, session.auditId]);
    } catch (error) {
      console.error("[camera audit]", error.message);
    }
  }

  const wsServer = new WebSocket.Server({ server, path: "/ws" });

  wsServer.on("connection", async (ws, req) => {
    const user = await getSessionUser(req);   // viewer autenticado; agente no trae cookie
    let agentId = null;
    let isAgent = false;
    let authorizedViewer = false;

    ws.on("message", async (raw) => {
      let msg; try { msg = JSON.parse(raw); } catch { return; }
      const type = msg.type;

      if (type === "agent:register") {
        agentId = msg.agentId; isAgent = true;
        const room = getRoom(agentId);
        if (room.agent && room.agent !== ws && room.audioSession) {
          await finishAudioSession(room, "stopped", "agent_replaced", null, true);
        }
        if (room.agent && room.agent !== ws && room.cameraSession) {
          await finishCameraSession(room, "stopped", "agent_replaced", null, true);
        }
        room.agent = ws;
        room.agentAudioAuthorized = Array.isArray(msg.capabilities)
          && msg.capabilities.includes("system-audio")
          && isAgentTokenAuthorized(msg.audioToken);
        room.agentCameraAuthorized = Array.isArray(msg.capabilities)
          && msg.capabilities.includes("webcam")
          && isAgentTokenAuthorized(msg.audioToken);
        room.viewers.forEach((viewer) => wsSend(viewer, {
          type: "agent:capabilities",
          audioAgentCapable: Boolean(room.agentAudioAuthorized),
          cameraAgentCapable: Boolean(room.agentCameraAuthorized)
        }));
        return;
      }

      if (type === "session:join") {
        if (!user) return wsSend(ws, { type: "error", detail: "No autenticado" });
        if (!(await canAccessAgent(user, msg.agentId))) {
          return wsSend(ws, { type: "error", detail: "Grupo no autorizado" });
        }
        agentId = msg.agentId;
        authorizedViewer = true;
        const room = getRoom(agentId);
        room.viewers.add(ws);
        wsSend(ws, {
          type: "session:ready",
          audioAllowed: Boolean(isAdmin(user) && room.agentAudioAuthorized),
          audioAdminAllowed: Boolean(isAdmin(user)),
          audioAgentCapable: Boolean(room.agentAudioAuthorized),
          cameraAllowed: Boolean(isAdmin(user) && room.agentCameraAuthorized),
          cameraAdminAllowed: Boolean(isAdmin(user)),
          cameraAgentCapable: Boolean(room.agentCameraAuthorized)
        });
        if (room.agent) wsSend(room.agent, { type: "viewer:joined" });
        else wsSend(ws, { type: "agent:offline" });
        return;
      }

      // relay WebRTC + control
      if (["webrtc:offer", "webrtc:answer", "webrtc:ice-candidate", "screen:frame"].includes(type)) {
        const room = getRoom(msg.agentId);
        if (isAgent) {
          room.viewers.forEach((v) => wsSend(v, { ...msg, from: "agent" }));
        } else if (authorizedViewer && msg.agentId === agentId) {
          if (room.agent) wsSend(room.agent, { ...msg, from: "viewer" });
        }
        return;
      }

      if (type === "audio:start") {
        const currentUser = await getSessionUser(req);
        if (!currentUser || !isAdmin(currentUser)) {
          return wsSend(ws, { type: "audio:error", detail: "Escucha permitida solo a administradores" });
        }
        if (!authorizedViewer || msg.agentId !== agentId || !(await canAccessAgent(currentUser, agentId))) {
          return wsSend(ws, { type: "audio:error", detail: "Agente no autorizado" });
        }
        const room = getRoom(agentId);
        if (!room.agent) return wsSend(ws, { type: "audio:error", detail: "Agente sin conexion" });
        if (!room.agentAudioAuthorized) {
          return wsSend(ws, { type: "audio:error", detail: "El agente no admite escucha autenticada" });
        }
        if (room.audioSession) {
          return wsSend(ws, { type: "audio:error", detail: "Ya existe una escucha activa para este agente" });
        }
        const [result] = await pool.query(
          `INSERT INTO audio_listening_sessions
             (agent_id,user_id,supervisor_username,status,requested_at,last_state_at)
           VALUES (?,?,?,'requested',NOW(3),NOW(3))`,
          [agentId, currentUser.id, currentUser.username]);
        const audioSession = {
          agentId,
          viewer: ws,
          userId: currentUser.id,
          request: req,
          auditId: result.insertId,
          authTimer: null,
          startTimer: null,
          stopTimer: null
        };
        room.audioSession = audioSession;
        audioSession.authTimer = setInterval(async () => {
          if (room.audioSession !== audioSession) return;
          try {
            const refreshedUser = await getSessionUser(req);
            if (!refreshedUser || !isAdmin(refreshedUser)
              || !(await canAccessAgent(refreshedUser, agentId))) {
              await finishAudioSession(room, "stopped", "authorization_lost", null, true);
            }
          } catch (error) {
            console.error("[audio authorization]", error.message);
          }
        }, 10000);
        audioSession.authTimer.unref?.();
        audioSession.startTimer = setTimeout(() => {
          void finishAudioSession(room, "error", "start_timeout", "El agente no inicio audio a tiempo", true);
        }, 15000);
        audioSession.startTimer.unref?.();
        wsSend(room.agent, {
          type: "audio:start",
          agentId,
          supervisorId: String(currentUser.id),
          supervisorName: currentUser.username
        });
        wsSend(ws, { type: "audio:status", state: "starting", reason: "request_sent" });
        return;
      }

      if (type === "audio:stop") {
        const room = getRoom(msg.agentId);
        const audioSession = room.audioSession;
        if (!authorizedViewer || msg.agentId !== agentId || !audioSession || audioSession.viewer !== ws) {
          return wsSend(ws, { type: "audio:error", detail: "No existe una escucha propia activa" });
        }
        wsSend(room.agent, { type: "audio:stop", agentId, reason: "supervisor_stop" });
        wsSend(ws, { type: "audio:status", state: "stopping", reason: "supervisor_stop" });
        await setAudioAuditState(audioSession, "stopping", "supervisor_stop");
        audioSession.stopTimer = setTimeout(() => {
          void finishAudioSession(room, "stopped", "stop_timeout", null, false);
        }, 5000);
        audioSession.stopTimer.unref?.();
        return;
      }

      if (!isAgent && ["audio:answer", "audio:ice-candidate"].includes(type)) {
        const room = getRoom(msg.agentId);
        const audioSession = room.audioSession;
        if (!authorizedViewer || msg.agentId !== agentId || !audioSession || audioSession.viewer !== ws) {
          return wsSend(ws, { type: "audio:error", detail: "Sesion de escucha no autorizada" });
        }
        if (room.agent) wsSend(room.agent, { ...msg, from: "viewer" });
        return;
      }

      if (["audio:offer", "audio:ice-candidate", "audio:status", "audio:stopped"].includes(type) && isAgent) {
        const room = getRoom(agentId);
        const audioSession = room.audioSession;
        if (!audioSession || room.agent !== ws || msg.agentId !== agentId) return;
        if (type === "audio:offer" || type === "audio:ice-candidate") {
          wsSend(audioSession.viewer, { ...msg, from: "agent" });
          return;
        }
        if (type === "audio:stopped") {
          await finishAudioSession(room, "stopped", limitedString(msg.reason, 120) || "agent_stopped");
          return;
        }
        const state = ["starting", "listening", "stopping", "stopped", "error"].includes(msg.state)
          ? msg.state : null;
        if (!state) return;
        const reason = limitedString(msg.reason, 120);
        wsSend(audioSession.viewer, { type: "audio:status", state, reason: reason || "" });
        if (state === "listening") {
          if (audioSession.startTimer) clearTimeout(audioSession.startTimer);
          audioSession.startTimer = null;
          await setAudioAuditState(audioSession, state, reason);
        }
        else if (state === "error") await finishAudioSession(room, "error", reason || "agent_error", reason);
        else if (state === "stopped") await finishAudioSession(room, "stopped", reason || "agent_stopped");
        else await setAudioAuditState(audioSession, state, reason);
        return;
      }

      if (type === "camera:start") {
        const currentUser = await getSessionUser(req);
        if (!currentUser || !isAdmin(currentUser)) {
          return wsSend(ws, { type: "camera:error", detail: "Ver camara permitido solo a administradores" });
        }
        if (!authorizedViewer || msg.agentId !== agentId || !(await canAccessAgent(currentUser, agentId))) {
          return wsSend(ws, { type: "camera:error", detail: "Agente no autorizado" });
        }
        const room = getRoom(agentId);
        if (!room.agent) return wsSend(ws, { type: "camera:error", detail: "Agente sin conexion" });
        if (!room.agentCameraAuthorized) {
          return wsSend(ws, { type: "camera:error", detail: "El agente no admite camara autenticada" });
        }
        if (room.cameraSession) {
          return wsSend(ws, { type: "camera:error", detail: "Ya existe una visualizacion de camara activa para este agente" });
        }
        const [result] = await pool.query(
          `INSERT INTO camera_viewing_sessions
             (agent_id,user_id,supervisor_username,status,requested_at,last_state_at)
           VALUES (?,?,?,'requested',NOW(3),NOW(3))`,
          [agentId, currentUser.id, currentUser.username]);
        const cameraSession = {
          agentId,
          viewer: ws,
          userId: currentUser.id,
          request: req,
          auditId: result.insertId,
          authTimer: null,
          startTimer: null,
          stopTimer: null
        };
        room.cameraSession = cameraSession;
        cameraSession.authTimer = setInterval(async () => {
          if (room.cameraSession !== cameraSession) return;
          try {
            const refreshedUser = await getSessionUser(req);
            if (!refreshedUser || !isAdmin(refreshedUser)
              || !(await canAccessAgent(refreshedUser, agentId))) {
              await finishCameraSession(room, "stopped", "authorization_lost", null, true);
            }
          } catch (error) {
            console.error("[camera authorization]", error.message);
          }
        }, 10000);
        cameraSession.authTimer.unref?.();
        cameraSession.startTimer = setTimeout(() => {
          void finishCameraSession(room, "error", "start_timeout", "El agente no inicio camara a tiempo", true);
        }, 15000);
        cameraSession.startTimer.unref?.();
        wsSend(room.agent, {
          type: "camera:start",
          agentId,
          supervisorId: String(currentUser.id),
          supervisorName: currentUser.username
        });
        wsSend(ws, { type: "camera:status", state: "starting", reason: "request_sent" });
        return;
      }

      if (type === "camera:stop") {
        const room = getRoom(msg.agentId);
        const cameraSession = room.cameraSession;
        if (!authorizedViewer || msg.agentId !== agentId || !cameraSession || cameraSession.viewer !== ws) {
          return wsSend(ws, { type: "camera:error", detail: "No existe una visualizacion propia activa" });
        }
        wsSend(room.agent, { type: "camera:stop", agentId, reason: "supervisor_stop" });
        wsSend(ws, { type: "camera:status", state: "stopping", reason: "supervisor_stop" });
        await setCameraAuditState(cameraSession, "stopping", "supervisor_stop");
        cameraSession.stopTimer = setTimeout(() => {
          void finishCameraSession(room, "stopped", "stop_timeout", null, false);
        }, 5000);
        cameraSession.stopTimer.unref?.();
        return;
      }

      if (!isAgent && ["camera:answer", "camera:ice-candidate"].includes(type)) {
        const room = getRoom(msg.agentId);
        const cameraSession = room.cameraSession;
        if (!authorizedViewer || msg.agentId !== agentId || !cameraSession || cameraSession.viewer !== ws) {
          return wsSend(ws, { type: "camera:error", detail: "Sesion de camara no autorizada" });
        }
        if (room.agent) wsSend(room.agent, { ...msg, from: "viewer" });
        return;
      }

      if (["camera:offer", "camera:ice-candidate", "camera:status", "camera:stopped"].includes(type) && isAgent) {
        const room = getRoom(agentId);
        const cameraSession = room.cameraSession;
        if (!cameraSession || room.agent !== ws || msg.agentId !== agentId) return;
        if (type === "camera:offer" || type === "camera:ice-candidate") {
          wsSend(cameraSession.viewer, { ...msg, from: "agent" });
          return;
        }
        if (type === "camera:stopped") {
          await finishCameraSession(room, "stopped", limitedString(msg.reason, 120) || "agent_stopped");
          return;
        }
        const state = ["starting", "viewing", "stopping", "stopped", "error"].includes(msg.state)
          ? msg.state : null;
        if (!state) return;
        const reason = limitedString(msg.reason, 120);
        wsSend(cameraSession.viewer, { type: "camera:status", state, reason: reason || "" });
        if (state === "viewing") {
          if (cameraSession.startTimer) clearTimeout(cameraSession.startTimer);
          cameraSession.startTimer = null;
          await setCameraAuditState(cameraSession, state, reason);
        }
        else if (state === "error") await finishCameraSession(room, "error", reason || "agent_error", reason);
        else if (state === "stopped") await finishCameraSession(room, "stopped", reason || "agent_stopped");
        else await setCameraAuditState(cameraSession, state, reason);
        return;
      }

      if (type === "control:input") {
        if (!user) return wsSend(ws, { type: "error", detail: "No autenticado" });
        if (!authorizedViewer || msg.agentId !== agentId) {
          return wsSend(ws, { type: "error", detail: "Grupo no autorizado" });
        }
        const room = getRoom(msg.agentId);
        if (room.agent) wsSend(room.agent, { ...msg, from: "viewer" });
        return;
      }
    });

    ws.on("close", () => {
      if (!agentId) return;
      const room = rooms.get(agentId);
      if (!room) return;
      if (room.agent === ws) {
        room.agent = null;
        room.agentAudioAuthorized = false;
        room.agentCameraAuthorized = false;
        room.viewers.forEach((viewer) => wsSend(viewer, {
          type: "agent:capabilities",
          audioAgentCapable: false,
          cameraAgentCapable: false
        }));
        if (room.audioSession) void finishAudioSession(room, "stopped", "agent_disconnected");
        if (room.cameraSession) void finishCameraSession(room, "stopped", "agent_disconnected");
      } else {
        if (room.audioSession && room.audioSession.viewer === ws) {
          void finishAudioSession(room, "stopped", "viewer_disconnected", null, true);
        }
        if (room.cameraSession && room.cameraSession.viewer === ws) {
          void finishCameraSession(room, "stopped", "viewer_disconnected", null, true);
        }
      }
      room.viewers.delete(ws);
      if (!room.agent && room.viewers.size === 0) rooms.delete(agentId);
    });
  });

  return { wsServer, rooms };
}

module.exports = { registerSignaling };
