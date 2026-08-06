function mapEvent(event) {
  return {
    ...event,
    evaluation_minutes: Number(event.evaluation_minutes ?? 136),
  };
}

function mapJudge(judge) {
  return {
    ...judge,
    status: judge.status ?? "ativo",
  };
}

function mapParticipant(participant) {
  return {
    ...participant,
    order: Number(participant.order ?? participant.presentation_order ?? 0),
    status: participant.status ?? "ativo",
    song: participant.song ?? participant.music ?? "",
  };
}

function mapCriterion(criterion) {
  return {
    ...criterion,
    weight: Number(criterion.weight ?? 1),
    display_order: Number(criterion.display_order ?? criterion.id ?? 0),
  };
}

export function normalizeDatabase(data) {
  const db = data ?? {};

  return {
    admins: db.admins ?? [],
    events: (db.events ?? []).map(mapEvent),
    judges: (db.judges ?? []).map(mapJudge),
    participants: (db.participants ?? []).map(mapParticipant),
    criteria: (db.criteria ?? []).map(mapCriterion),
    votes: db.votes ?? [],
    observations: db.observations ?? [],
    judge_reviews: db.judge_reviews ?? [],
  };
}

export function getEvent(db, eventId) {
  return db.events.find((event) => Number(event.id) === Number(eventId)) ?? db.events[0] ?? null;
}

export function getJudgesForEvent(db, eventId) {
  return db.judges.filter((judge) => Number(judge.event_id) === Number(eventId));
}

export function getParticipantsForEvent(db, eventId) {
  return db.participants
    .filter((participant) => Number(participant.event_id) === Number(eventId))
    .sort((a, b) => Number(a.order ?? 0) - Number(b.order ?? 0));
}

export function getCriteriaForEvent(db, eventId) {
  return db.criteria
    .filter((criterion) => Number(criterion.event_id) === Number(eventId))
    .sort((a, b) => Number(a.display_order ?? a.id ?? 0) - Number(b.display_order ?? b.id ?? 0));
}

export function getVotesForEvent(db, eventId) {
  return db.votes.filter((vote) => Number(vote.event_id) === Number(eventId));
}

export function getReviewsForEvent(db, eventId) {
  return db.judge_reviews.filter((review) => Number(review.event_id) === Number(eventId));
}

export function getObservationsForEvent(db, eventId) {
  return db.observations.filter((observation) => Number(observation.event_id) === Number(eventId));
}

export function buildTotals(db, eventId) {
  const participants = getParticipantsForEvent(db, eventId);
  const judges = getJudgesForEvent(db, eventId);
  const votes = getVotesForEvent(db, eventId);
  const criteria = getCriteriaForEvent(db, eventId);
  const reviews = getReviewsForEvent(db, eventId);

  const participantTotals = participants.map((participant) => {
    const participantVotes = votes.filter((vote) => Number(vote.participant_id) === Number(participant.id));
    const judgeIds = new Set(participantVotes.map((vote) => Number(vote.judge_id)));
    const totalScore = participantVotes.reduce((sum, vote) => sum + Number(vote.score ?? 0), 0);
    const checklists = reviews.filter(
      (review) => Number(review.participant_id) === Number(participant.id) && review.checklist_done
    ).length;

    return {
      participant,
      totalScore,
      voteCount: participantVotes.length,
      judgeCount: judgeIds.size,
      checklistCount: checklists,
    };
  });

  const judgeProgress = judges.map((judge) => {
    const judgeVotes = votes.filter((vote) => Number(vote.judge_id) === Number(judge.id));
    const reviewedParticipants = new Set(judgeVotes.map((vote) => Number(vote.participant_id)));
    const checklistDone = reviews.filter(
      (review) => Number(review.judge_id) === Number(judge.id) && review.checklist_done
    ).length;

    return {
      judge,
      participantsDone: reviewedParticipants.size,
      participantsTotal: participants.length,
      checklistDone,
      pending: Math.max(participants.length - reviewedParticipants.size, 0),
      notesCount: judgeVotes.length,
      ready: reviewedParticipants.size === participants.length && checklistDone === participants.length,
    };
  });

  const criteriaCount = criteria.length;
  const totalExpectedVotes = participants.length * judges.length * criteriaCount;

  return {
    participantTotals: participantTotals.sort((a, b) => b.totalScore - a.totalScore),
    judgeProgress,
    totalExpectedVotes,
    savedVotes: votes.length,
  };
}

export function buildDetailedRows(db, eventId) {
  const participants = getParticipantsForEvent(db, eventId);
  const judges = getJudgesForEvent(db, eventId);
  const criteria = getCriteriaForEvent(db, eventId);
  const votes = getVotesForEvent(db, eventId);
  const reviews = getReviewsForEvent(db, eventId);
  const observations = getObservationsForEvent(db, eventId);

  return participants.flatMap((participant) =>
    judges.flatMap((judge) => {
      const participantVotes = votes.filter(
        (vote) => Number(vote.participant_id) === Number(participant.id) && Number(vote.judge_id) === Number(judge.id)
      );
      const judgeTotal = participantVotes.reduce((sum, vote) => sum + Number(vote.score ?? 0), 0);
      const participantTotal = votes
        .filter((vote) => Number(vote.participant_id) === Number(participant.id))
        .reduce((sum, vote) => sum + Number(vote.score ?? 0), 0);
      const review =
        reviews.find(
          (item) => Number(item.participant_id) === Number(participant.id) && Number(item.judge_id) === Number(judge.id)
        ) ?? null;
      const observation =
        observations.find(
          (item) => Number(item.participant_id) === Number(participant.id) && Number(item.judge_id) === Number(judge.id)
        ) ?? null;

      return criteria.map((criterion) => ({
        participant,
        judge,
        criterion,
        vote: participantVotes.find((item) => Number(item.criterion_id) === Number(criterion.id)) ?? null,
        judgeTotal,
        participantTotal,
        review,
        observation,
      }));
    })
  );
}

export function buildPhaseClassification(db, eventId) {
  const event = getEvent(db, eventId);
  const totals = buildTotals(db, eventId).participantTotals;
  const advancers = event?.phase_advancers ?? {
    classificatoria: 12,
    semifinal: 6,
    final: 3,
  };

  return [
    {
      key: "classificatoria",
      label: "Classificatoria",
      items: totals.slice(0, Number(advancers.classificatoria ?? 0)),
    },
    {
      key: "semifinal",
      label: "Semifinal",
      items: totals.slice(0, Number(advancers.semifinal ?? 0)),
    },
    {
      key: "final",
      label: "Final",
      items: totals.slice(0, Number(advancers.final ?? 0)),
    },
  ];
}

export function formatScore(value, digits = 1) {
  return Number(value ?? 0).toLocaleString("pt-BR", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

export function formatStatus(value = "") {
  const text = String(value || "").trim();
  if (!text) return "-";
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function initials(name = "") {
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}
