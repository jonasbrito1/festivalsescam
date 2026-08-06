-- ============================================================================
-- Correcao aplicada em bancos ja criados com o schema inicial.
--
-- As chaves estrangeiras de votes e judge_observations vieram do DDL SQL
-- Server como RESTRICT. Aquilo funcionava por acidente: o codigo antigo
-- apagava todas as notas antes de cada gravacao, entao a restricao nunca era
-- exercitada. Com a escrita dirigida, excluir um jurado que ja votou passa a
-- esbarrar na chave e a operacao inteira falha.
--
-- Uso:  mysql festival_v2 < mysql_02_cascade.sql
-- ============================================================================

USE festival_v2;

ALTER TABLE votes DROP FOREIGN KEY fk_votes_event;
ALTER TABLE votes DROP FOREIGN KEY fk_votes_judge;
ALTER TABLE votes DROP FOREIGN KEY fk_votes_participant;
ALTER TABLE votes DROP FOREIGN KEY fk_votes_criterion;

ALTER TABLE votes
  ADD CONSTRAINT fk_votes_event       FOREIGN KEY (event_id)       REFERENCES events(id)       ON DELETE CASCADE,
  ADD CONSTRAINT fk_votes_judge       FOREIGN KEY (judge_id)       REFERENCES judges(id)       ON DELETE CASCADE,
  ADD CONSTRAINT fk_votes_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_votes_criterion   FOREIGN KEY (criterion_id)   REFERENCES criteria(id)     ON DELETE CASCADE;

ALTER TABLE judge_observations DROP FOREIGN KEY fk_obs_event;
ALTER TABLE judge_observations DROP FOREIGN KEY fk_obs_judge;
ALTER TABLE judge_observations DROP FOREIGN KEY fk_obs_participant;

ALTER TABLE judge_observations
  ADD CONSTRAINT fk_obs_event       FOREIGN KEY (event_id)       REFERENCES events(id)       ON DELETE CASCADE,
  ADD CONSTRAINT fk_obs_judge       FOREIGN KEY (judge_id)       REFERENCES judges(id)       ON DELETE CASCADE,
  ADD CONSTRAINT fk_obs_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE;
