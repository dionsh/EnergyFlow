// Mirrors the API's role order (backend RequireRole): viewer < worker < admin < owner.
// Only used to hide actions a user cannot take; the API enforces every rule itself.
const RANK = { viewer: 1, worker: 2, admin: 3, owner: 4 }

export const roleTranslationKey = (role) => `roles.${role === 'manager' ? 'worker' : role || 'worker'}`

export const hasRole = (user, minimum) => (RANK[user?.role] ?? 0) >= RANK[minimum]
