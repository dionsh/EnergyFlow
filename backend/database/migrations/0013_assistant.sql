-- Ask EnergyFlow: clickable actions (navigate, confirmable Turn Off, follow-up
-- questions), the grounding verdict and the page context of each answer.
ALTER TABLE ai_messages
  ADD COLUMN actions JSON NULL AFTER sources,
  ADD COLUMN grounded TINYINT(1) NULL AFTER actions,
  ADD COLUMN context JSON NULL AFTER grounded;

-- The demo's read-only guest account is shared by every visitor: its
-- conversations are kept apart per browser session (a hash, never the token).
ALTER TABLE ai_conversations
  ADD COLUMN session_key CHAR(64) NULL AFTER user_id,
  ADD KEY idx_ai_conversations_session (user_id, session_key, updated_at);
