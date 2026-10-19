export const OFFLINE_ACCESS_MS = 7 * 24 * 60 * 60 * 1000;
export function canUnlockOffline(session, apiBase, now = Date.now()) {
  return Boolean(session?.offlineEnabled && session.token && session.customer?.id
    && session.apiBase === apiBase
    && Number.isFinite(session.verifiedAt) && Number.isFinite(session.expiresAt)
    && now >= session.verifiedAt && now < session.expiresAt
    && now - session.verifiedAt < OFFLINE_ACCESS_MS);
}
