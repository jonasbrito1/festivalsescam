import { useEffect, useMemo, useState } from "react";
import {
  buildDetailedRows,
  buildPhaseClassification,
  buildTotals,
  getCriteriaForEvent,
  getEvent,
  getJudgesForEvent,
  getParticipantsForEvent,
  normalizeDatabase,
} from "../utils/festival";

export function useFestivalData() {
  const [db, setDb] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [eventId, setEventId] = useState(null);

  useEffect(() => {
    let cancelled = false;

    fetch("/data/db.json")
      .then((response) => response.json())
      .then((payload) => {
        if (cancelled) return;

        const normalized = normalizeDatabase(payload);
        const preferredEvent =
          normalized.events.find((event) => event.status === "aberto") ??
          normalized.events[0] ??
          null;

        setDb(normalized);
        setEventId(preferredEvent?.id ?? null);
      })
      .catch(() => {
        if (cancelled) return;
        setError("Nao foi possivel carregar os dados do festival.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const derived = useMemo(() => {
    if (!db || !eventId) {
      return {
        events: db?.events ?? [],
        currentEvent: null,
        participants: [],
        judges: [],
        criteria: [],
        participantTotals: [],
        judgeProgress: [],
        detailedRows: [],
        phaseClassification: [],
        totalExpectedVotes: 0,
        savedVotes: 0,
      };
    }

    const totals = buildTotals(db, eventId);

    return {
      events: db.events,
      currentEvent: getEvent(db, eventId),
      participants: getParticipantsForEvent(db, eventId),
      judges: getJudgesForEvent(db, eventId),
      criteria: getCriteriaForEvent(db, eventId),
      participantTotals: totals.participantTotals,
      judgeProgress: totals.judgeProgress,
      detailedRows: buildDetailedRows(db, eventId),
      phaseClassification: buildPhaseClassification(db, eventId),
      totalExpectedVotes: totals.totalExpectedVotes,
      savedVotes: totals.savedVotes,
    };
  }, [db, eventId]);

  return {
    db,
    loading,
    error,
    eventId,
    setEventId,
    ...derived,
  };
}
