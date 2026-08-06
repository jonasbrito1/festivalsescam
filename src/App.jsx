import { NavLink, Route, Routes, useLocation, useNavigate } from "react-router-dom";
import { useEffect, useMemo, useState } from "react";
import { useFestivalData } from "./data/useFestivalData";
import { formatScore, formatStatus, initials } from "./utils/festival";

const adminNav = [
  ["Painel", "/admin"],
  ["Eventos", "/admin/events"],
  ["Jurados", "/admin/judges"],
  ["Participantes", "/admin/participants"],
  ["Criterios", "/admin/criteria"],
  ["Relatorios", "/admin/reports"],
  ["Configuracoes", "/admin/settings"],
];

const judgeNav = [
  ["Votacao", "/judge/vote"],
  ["Participantes", "/judge/participants"],
  ["Criterios", "/judge/criteria"],
  ["Resumo", "/judge/resume"],
  ["Instrucoes", "/judge/instructions"],
];

function EventPicker({ events, eventId, onChange }) {
  if (!events.length) return null;

  return (
    <select
      className="select-control compact-select"
      value={eventId ?? ""}
      onChange={(event) => onChange(Number(event.target.value))}
    >
      {events.map((item) => (
        <option key={item.id} value={item.id}>
          {item.name}
        </option>
      ))}
    </select>
  );
}

function Shell({
  title,
  subtitle,
  navItems,
  children,
  sideFooter,
  profileLabel = "Administrador",
  events = [],
  eventId = null,
  onEventChange = null,
}) {
  const [open, setOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();

  return (
    <div className="app-shell">
      <aside className={`sidebar ${open ? "open" : ""}`}>
        <div className="sidebar-brand">
          <div className="sidebar-logo">Sesc</div>
          <div>
            <strong>Sistema de Notas</strong>
            <span>Festival de Calouros</span>
          </div>
        </div>

        <nav className="sidebar-nav">
          {navItems.map(([label, path]) => (
            <NavLink
              key={path}
              to={path}
              className={({ isActive }) => `sidebar-link ${isActive ? "active" : ""}`}
              onClick={() => setOpen(false)}
            >
              <span>{label.slice(0, 2).toUpperCase()}</span>
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-actions">
          <button className="ghost-button sidebar-logout" onClick={() => navigate("/")}>
            Sair
          </button>
        </div>

        {sideFooter}
      </aside>

      {open ? <button className="sidebar-backdrop" onClick={() => setOpen(false)} aria-label="Fechar menu" /> : null}

      <div className="app-main">
        <header className="page-header">
          <div className="header-copy">
            <button className="menu-button" onClick={() => setOpen((value) => !value)}>
              Menu
            </button>
            <div>
              <h1>{title}</h1>
              <p>{subtitle}</p>
            </div>
          </div>

          <div className="header-actions">
            {onEventChange ? (
              <EventPicker events={events} eventId={eventId} onChange={onEventChange} />
            ) : null}
            <span className="pill">{location.pathname}</span>
            <span className="profile-pill">{profileLabel}</span>
          </div>
        </header>

        <main className="page-content">{children}</main>
      </div>
    </div>
  );
}

function LoginScreen() {
  const navigate = useNavigate();

  return (
    <div className="login-screen">
      <section className="login-hero-panel">
        <div className="hero-mark">Sesc</div>
        <h1>Sistema de Notas de Jurados</h1>
        <p>Interface React + Vite desenhada para celular, tablet e computador.</p>
      </section>

      <section className="login-card-grid">
        <article className="login-profile-card">
          <div className="profile-icon blue">AD</div>
          <h2>Administrador</h2>
          <p>Acesse a gestao completa do evento com relatarios, jurados e acompanhamento.</p>
          <div className="form-mock">
            <input placeholder="Usuario" />
            <input placeholder="Senha" type="password" />
          </div>
          <button className="primary-button" onClick={() => navigate("/admin")}>
            Entrar como Administrador
          </button>
        </article>

        <article className="login-profile-card">
          <div className="profile-icon gold">JU</div>
          <h2>Jurado</h2>
          <p>Fluxo rapido para votacao, checklist e assinatura com foco em toque.</p>
          <div className="form-mock">
            <input placeholder="Usuario ou e-mail" />
            <input placeholder="Senha" type="password" />
          </div>
          <button className="secondary-button" onClick={() => navigate("/judge/vote")}>
            Entrar como Jurado
          </button>
        </article>
      </section>
    </div>
  );
}

function StatCard({ label, value, hint }) {
  return (
    <article className="stat-card">
      <span>{label}</span>
      <strong>{value}</strong>
      {hint ? <small>{hint}</small> : null}
    </article>
  );
}

function DataTable({ columns, rows, empty = "Sem dados." }) {
  return (
    <div className="table-shell">
      <table className="responsive-table">
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key}>{column.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {!rows.length ? (
            <tr>
              <td colSpan={columns.length}>{empty}</td>
            </tr>
          ) : (
            rows.map((row, index) => (
              <tr key={row.id ?? row.key ?? index}>
                {columns.map((column) => (
                  <td key={column.key} data-label={column.label}>
                    {column.render ? column.render(row, index) : row[column.key]}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function AdminHome({
  currentEvent,
  participants,
  judges,
  criteria,
  participantTotals,
  judgeProgress,
  totalExpectedVotes,
  savedVotes,
  events,
  eventId,
  onEventChange,
}) {
  return (
    <Shell
      title="Ola, Administrador"
      subtitle="Painel responsivo em React para gestao do festival."
      navItems={adminNav}
      sideFooter={<div className="sidebar-footer">React + Vite layout preview</div>}
      events={events}
      eventId={eventId}
      onEventChange={onEventChange}
    >
      <section className="dashboard-grid">
        <StatCard label="Evento atual" value={currentEvent?.name ?? "-"} hint={formatStatus(currentEvent?.status)} />
        <StatCard label="Participantes" value={participants.length} hint="inscritos" />
        <StatCard label="Jurados" value={judges.length} hint="ativos" />
        <StatCard label="Criterios" value={criteria.length} hint="quesitos" />
        <StatCard label="Notas salvas" value={savedVotes} hint={`de ${totalExpectedVotes}`} />
      </section>

      <section className="split-grid">
        <article className="panel-card">
          <div className="panel-head">
            <h2>Classificacao por somatoria</h2>
          </div>
          <DataTable
            columns={[
              { key: "position", label: "#", render: (_, index) => index + 1 },
              {
                key: "participant",
                label: "Participante",
                render: (row) => (
                  <div className="name-cell">
                    <span className="avatar-chip">{initials(row.participant.name)}</span>
                    <div>
                      <strong>{row.participant.name}</strong>
                      <small>{row.participant.category}</small>
                    </div>
                  </div>
                ),
              },
              { key: "totalScore", label: "Somatoria", render: (row) => formatScore(row.totalScore, 1) },
              { key: "voteCount", label: "Notas" },
              { key: "judgeCount", label: "Jurados" },
            ]}
            rows={participantTotals}
          />
        </article>

        <article className="panel-card">
          <div className="panel-head">
            <h2>Status dos jurados</h2>
          </div>
          <DataTable
            columns={[
              { key: "judge", label: "Jurado", render: (row) => row.judge.name },
              { key: "participantsDone", label: "Avaliados", render: (row) => `${row.participantsDone}/${row.participantsTotal}` },
              { key: "checklistDone", label: "Checklist", render: (row) => `${row.checklistDone}/${row.participantsTotal}` },
              {
                key: "ready",
                label: "Pronto",
                render: (row) => <span className={`status-pill ${row.ready ? "ok" : "waiting"}`}>{row.ready ? "Sim" : "Pendente"}</span>,
              },
            ]}
            rows={judgeProgress}
          />
        </article>
      </section>
    </Shell>
  );
}

function AdminGenericPage({ title, subtitle, navItems = adminNav, children, events, eventId, onEventChange, profileLabel = "Administrador" }) {
  return (
    <Shell
      title={title}
      subtitle={subtitle}
      navItems={navItems}
      sideFooter={<div className="sidebar-footer">Sesc Jurados React</div>}
      events={events}
      eventId={eventId}
      onEventChange={onEventChange}
      profileLabel={profileLabel}
    >
      {children}
    </Shell>
  );
}

function JudgeScreen({ currentEvent, participants, criteria, participantTotals, detailedRows, events, eventId, onEventChange }) {
  const [selectedParticipantId, setSelectedParticipantId] = useState(participants[0]?.id ?? null);
  const [scores, setScores] = useState({});
  const [openDecimalFor, setOpenDecimalFor] = useState(null);

  useEffect(() => {
    setSelectedParticipantId(participants[0]?.id ?? null);
    setScores({});
    setOpenDecimalFor(null);
  }, [participants]);

  const selectedParticipant =
    participants.find((participant) => Number(participant.id) === Number(selectedParticipantId)) ?? participants[0] ?? null;

  const selectedTotals =
    participantTotals.find((row) => Number(row.participant.id) === Number(selectedParticipant?.id)) ?? null;

  const participantRows = detailedRows.filter((row) => Number(row.participant.id) === Number(selectedParticipant?.id));
  const checklistReady = participantRows.length > 0 && participantRows.every((row) => row.vote?.score !== null && row.vote?.score !== undefined);

  const chooseInteger = (criterionId, integerValue) => {
    if (integerValue === 10) {
      setScores((prev) => ({ ...prev, [criterionId]: 10 }));
      setOpenDecimalFor(null);
      return;
    }

    setOpenDecimalFor({ criterionId, integerValue });
  };

  const chooseDecimal = (criterionId, value) => {
    setScores((prev) => ({ ...prev, [criterionId]: value }));
    setOpenDecimalFor(null);
  };

  return (
    <Shell
      title="Ola, Jurado"
      subtitle="Fluxo responsivo com foco em votacao rapida."
      navItems={judgeNav}
      sideFooter={<div className="sidebar-footer">Atualizacao offline-friendly</div>}
      profileLabel="Jurado"
      events={events}
      eventId={eventId}
      onEventChange={onEventChange}
    >
      <section className="judge-summary">
        <StatCard label="Evento" value={currentEvent?.name ?? "-"} hint={currentEvent?.location ?? ""} />
        <StatCard label="Participante" value={selectedParticipant?.name ?? "-"} hint={selectedParticipant?.category ?? ""} />
        <StatCard label="Somatoria atual" value={selectedTotals ? formatScore(selectedTotals.totalScore, 1) : "0,0"} hint="todas as notas" />
      </section>

      <section className="judge-layout">
        <article className="panel-card">
          <div className="panel-head">
            <h2>Avaliar participante</h2>
            <select
              className="select-control"
              value={selectedParticipantId ?? ""}
              onChange={(event) => setSelectedParticipantId(Number(event.target.value))}
            >
              {participants.map((participant) => (
                <option key={participant.id} value={participant.id}>
                  {String(participant.order ?? 0).padStart(2, "0")} - {participant.name}
                </option>
              ))}
            </select>
          </div>

          <div className="participant-banner">
            <span className="avatar-large">{initials(selectedParticipant?.name ?? "PA")}</span>
            <div>
              <strong>{selectedParticipant?.name ?? "Participante"}</strong>
              <small>
                Categoria {selectedParticipant?.category ?? "-"} - Ordem {String(selectedParticipant?.order ?? 0).padStart(2, "0")}
              </small>
            </div>
          </div>

          <div className="criteria-stack">
            {criteria.map((criterion) => {
              const currentScore =
                scores[criterion.id] ??
                participantRows.find((row) => Number(row.criterion.id) === Number(criterion.id))?.vote?.score ??
                null;

              const decimalOpen =
                openDecimalFor && Number(openDecimalFor.criterionId) === Number(criterion.id)
                  ? openDecimalFor.integerValue
                  : null;

              return (
                <div className="criterion-card" key={criterion.id}>
                  <div className="criterion-copy">
                    <strong>{criterion.name}</strong>
                    <small>{criterion.description || "Avaliacao por criterio"}</small>
                  </div>

                  <div className="criterion-controls">
                    <div className="integer-strip">
                      {Array.from({ length: 11 }, (_, value) => (
                        <button
                          type="button"
                          key={value}
                          className={`mini-score ${Math.floor(Number(currentScore ?? -1)) === value ? "active" : ""}`}
                          onClick={() => chooseInteger(criterion.id, value)}
                        >
                          {value}
                        </button>
                      ))}
                    </div>

                    {decimalOpen !== null ? (
                      <div className="decimal-strip">
                        {Array.from({ length: 10 }, (_, decimal) => {
                          const value = Number(`${decimalOpen}.${decimal}`);
                          return (
                            <button
                              type="button"
                              key={value}
                              className={`mini-score decimal ${Number(currentScore) === value ? "active" : ""}`}
                              onClick={() => chooseDecimal(criterion.id, value)}
                            >
                              {value.toFixed(1).replace(".", ",")}
                            </button>
                          );
                        })}
                      </div>
                    ) : null}
                  </div>

                  <div className="score-output">{currentScore !== null ? formatScore(currentScore, 1) : "--"}</div>
                </div>
              );
            })}
          </div>
        </article>

        <aside className="panel-card judge-side">
          <div className="panel-head">
            <h2>Resumo</h2>
          </div>

          <div className="judge-resume-list">
            {participantRows.map((row) => (
              <div key={`${row.participant.id}-${row.criterion.id}`} className="resume-line">
                <span>{row.criterion.name}</span>
                <strong>{formatScore(scores[row.criterion.id] ?? row.vote?.score ?? 0, 1)}</strong>
              </div>
            ))}
          </div>

          <div className="signature-card">
            <strong>Checklist final</strong>
            <label className="check-row">
              <input type="checkbox" checked={checklistReady} readOnly />
              <span>Todos os criterios preenchidos</span>
            </label>
            <label className="check-row">
              <input type="checkbox" checked={true} readOnly />
              <span>Assinatura pronta para envio</span>
            </label>
          </div>

          <button className="primary-button wide-button">Salvar notas</button>
          <button className="secondary-button wide-button">Proximo participante</button>
        </aside>
      </section>
    </Shell>
  );
}

function MonitoringScreen({ currentEvent, participantTotals, judgeProgress }) {
  return (
    <div className="monitor-shell">
      <header className="monitor-header">
        <div>
          <span className="kicker">Acompanhamento ao vivo</span>
          <h1>{currentEvent?.name ?? "Festival"}</h1>
        </div>
        <NavLink className="secondary-button" to="/admin/reports">
          Abrir relatorios
        </NavLink>
      </header>

      <section className="dashboard-grid">
        <StatCard label="Participantes" value={participantTotals.length} />
        <StatCard label="Jurados" value={judgeProgress.length} />
        <StatCard label="Concluidos" value={participantTotals.filter((row) => row.checklistCount === judgeProgress.length).length} />
        <StatCard label="Pendentes" value={participantTotals.filter((row) => row.checklistCount !== judgeProgress.length).length} />
      </section>

      <article className="panel-card">
        <div className="panel-head">
          <h2>Painel de conclusao</h2>
        </div>
        <DataTable
          columns={[
            {
              key: "participant",
              label: "Participante",
              render: (row) => (
                <div className="name-cell">
                  <span className="avatar-chip">{initials(row.participant.name)}</span>
                  <div>
                    <strong>{row.participant.name}</strong>
                    <small>{row.participant.category}</small>
                  </div>
                </div>
              ),
            },
            { key: "totalScore", label: "Somatoria", render: (row) => formatScore(row.totalScore, 1) },
            {
              key: "judgeCount",
              label: "Jurados",
              render: (row) => (
                <span className={`status-pill ${row.checklistCount === judgeProgress.length ? "ok" : "waiting"}`}>
                  {row.checklistCount}/{judgeProgress.length} concluidos
                </span>
              ),
            },
          ]}
          rows={participantTotals}
        />
      </article>
    </div>
  );
}

export default function App() {
  const {
    loading,
    error,
    events,
    eventId,
    setEventId,
    currentEvent,
    participants,
    judges,
    criteria,
    participantTotals,
    judgeProgress,
    detailedRows,
    phaseClassification,
    totalExpectedVotes,
    savedVotes,
  } = useFestivalData();

  const eventsRows = useMemo(
    () =>
      events.map((event) => ({
        id: event.id,
        name: event.name,
        date: event.date,
        location: event.location,
        status: formatStatus(event.status),
        format: event.event_format === "fases" ? "Por fases" : "Etapa unica",
      })),
    [events]
  );

  const signatureRows = useMemo(() => {
    const byJudge = new Map();

    detailedRows.forEach((row) => {
      if (!row.review) return;
      const key = row.judge.id;
      if (!byJudge.has(key)) {
        byJudge.set(key, {
          id: key,
          judge: row.judge.name,
          signature: row.review.signature_text || row.review.signature || "Sem assinatura",
          mode: row.review.signature_mode || "texto",
          checklist: row.review.checklist_done ? "Concluido" : "Pendente",
        });
      }
    });

    return Array.from(byJudge.values());
  }, [detailedRows]);

  if (loading) {
    return <div className="loading-screen">Carregando interface React do festival...</div>;
  }

  if (error) {
    return <div className="loading-screen">{error}</div>;
  }

  return (
    <Routes>
      <Route path="/" element={<LoginScreen />} />
      <Route
        path="/admin"
        element={
          <AdminHome
            currentEvent={currentEvent}
            participants={participants}
            judges={judges}
            criteria={criteria}
            participantTotals={participantTotals}
            judgeProgress={judgeProgress}
            totalExpectedVotes={totalExpectedVotes}
            savedVotes={savedVotes}
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          />
        }
      />
      <Route
        path="/admin/events"
        element={
          <AdminGenericPage
            title="Eventos"
            subtitle="Gerencie eventos em um layout responsivo."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <div className="panel-head">
                <h2>Eventos cadastrados</h2>
                <button className="primary-button">Criar evento</button>
              </div>
              <DataTable
                columns={[
                  { key: "name", label: "Evento" },
                  { key: "date", label: "Data" },
                  { key: "location", label: "Local" },
                  { key: "status", label: "Status" },
                  { key: "format", label: "Formato" },
                ]}
                rows={eventsRows}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/admin/judges"
        element={
          <AdminGenericPage
            title="Jurados"
            subtitle="Cadastro e acompanhamento da equipe de avaliacao."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <div className="panel-head">
                <h2>Lista de jurados</h2>
                <button className="primary-button">Adicionar jurado</button>
              </div>
              <DataTable
                columns={[
                  { key: "name", label: "Nome" },
                  { key: "username", label: "Usuario" },
                  { key: "status", label: "Status", render: (row) => formatStatus(row.status ?? "ativo") },
                ]}
                rows={judges}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/admin/participants"
        element={
          <AdminGenericPage
            title="Participantes"
            subtitle="Grade adaptada para tablet, desktop e celular."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <div className="panel-head">
                <h2>Participantes do evento</h2>
                <button className="primary-button">Adicionar participante</button>
              </div>
              <DataTable
                columns={[
                  {
                    key: "name",
                    label: "Nome",
                    render: (row) => (
                      <div className="name-cell">
                        <span className="avatar-chip">{initials(row.name)}</span>
                        <div>
                          <strong>{row.name}</strong>
                          <small>{row.song}</small>
                        </div>
                      </div>
                    ),
                  },
                  { key: "category", label: "Categoria" },
                  { key: "order", label: "Ordem", render: (row) => String(row.order ?? 0).padStart(2, "0") },
                  { key: "status", label: "Status", render: (row) => formatStatus(row.status ?? "ativo") },
                ]}
                rows={participants}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/admin/criteria"
        element={
          <AdminGenericPage
            title="Criterios"
            subtitle="Quesitos organizados com leitura confortavel em telas pequenas."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <div className="panel-head">
                <h2>Criterios do evento</h2>
                <button className="primary-button">Novo criterio</button>
              </div>
              <DataTable
                columns={[
                  { key: "name", label: "Criterio" },
                  { key: "description", label: "Descricao", render: (row) => row.description || "Avaliacao por criterio" },
                  { key: "weight", label: "Peso", render: (row) => `${formatScore(row.weight, 1)}x` },
                ]}
                rows={criteria}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/admin/reports"
        element={
          <AdminGenericPage
            title="Relatorios"
            subtitle="Somatorias, checklist, assinaturas e classificacao por fase."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <section className="dashboard-grid">
              <StatCard label="Maior somatoria" value={participantTotals[0] ? formatScore(participantTotals[0].totalScore, 1) : "-"} />
              <StatCard label="Notas lancadas" value={savedVotes} />
              <StatCard label="Jurados prontos" value={judgeProgress.filter((row) => row.ready).length} />
              <StatCard label="Fases" value={phaseClassification.filter((phase) => phase.items.length).length} />
            </section>

            <article className="panel-card">
              <div className="panel-head">
                <h2>Classificacao por somatoria</h2>
              </div>
              <DataTable
                columns={[
                  { key: "position", label: "#", render: (_, index) => index + 1 },
                  { key: "participant", label: "Participante", render: (row) => row.participant.name },
                  { key: "totalScore", label: "Somatoria", render: (row) => formatScore(row.totalScore, 1) },
                  { key: "voteCount", label: "Notas" },
                ]}
                rows={participantTotals}
              />
            </article>

            <article className="panel-card">
              <div className="panel-head">
                <h2>Classificados por fase</h2>
              </div>
              <div className="phase-grid">
                {phaseClassification.map((phase) => (
                  <div className="phase-card" key={phase.key}>
                    <strong>{phase.label}</strong>
                    <ul>
                      {phase.items.length ? (
                        phase.items.map((item, index) => (
                          <li key={`${phase.key}-${item.participant.id}`}>
                            <span>{index + 1}. {item.participant.name}</span>
                            <b>{formatScore(item.totalScore, 1)}</b>
                          </li>
                        ))
                      ) : (
                        <li>Sem classificados ainda</li>
                      )}
                    </ul>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel-card">
              <div className="panel-head">
                <h2>Checklist e assinatura dos jurados</h2>
              </div>
              <DataTable
                columns={[
                  { key: "judge", label: "Jurado" },
                  { key: "signature", label: "Assinatura" },
                  { key: "mode", label: "Modo", render: (row) => (row.mode === "touch" ? "Toque" : "Digitada") },
                  {
                    key: "checklist",
                    label: "Checklist",
                    render: (row) => <span className={`status-pill ${row.checklist === "Concluido" ? "ok" : "waiting"}`}>{row.checklist}</span>,
                  },
                ]}
                rows={signatureRows}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/admin/settings"
        element={
          <AdminGenericPage
            title="Configuracoes"
            subtitle="Blocos responsivos para periodos, publicacao e regras do evento."
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <section className="settings-grid">
              <article className="panel-card">
                <h2>Informacoes gerais</h2>
                <div className="settings-list">
                  <div><span>Nome</span><strong>{currentEvent?.name}</strong></div>
                  <div><span>Formato</span><strong>{currentEvent?.event_format === "fases" ? "Por fases" : "Etapa unica"}</strong></div>
                  <div><span>Duracao</span><strong>{currentEvent?.evaluation_minutes} min</strong></div>
                </div>
              </article>

              <article className="panel-card">
                <h2>Fases</h2>
                <div className="settings-list">
                  {phaseClassification.map((phase) => (
                    <div key={phase.key}>
                      <span>{phase.label}</span>
                      <strong>{phase.items.length} classificados</strong>
                    </div>
                  ))}
                </div>
              </article>
            </section>
          </AdminGenericPage>
        }
      />
      <Route
        path="/judge/vote"
        element={
          <JudgeScreen
            currentEvent={currentEvent}
            participants={participants}
            criteria={criteria}
            participantTotals={participantTotals}
            detailedRows={detailedRows}
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          />
        }
      />
      <Route
        path="/judge/participants"
        element={
          <AdminGenericPage
            title="Participantes"
            subtitle="Lista enxuta para o jurado navegar por ordem de apresentacao."
            navItems={judgeNav}
            profileLabel="Jurado"
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <DataTable
                columns={[
                  { key: "order", label: "Ordem", render: (row) => String(row.order ?? 0).padStart(2, "0") },
                  { key: "name", label: "Participante" },
                  { key: "category", label: "Categoria" },
                  {
                    key: "status",
                    label: "Checklist",
                    render: (row) => {
                      const total = participantTotals.find((item) => Number(item.participant.id) === Number(row.id));
                      return (
                        <span className={`status-pill ${total?.checklistCount === judges.length ? "ok" : "waiting"}`}>
                          {total?.checklistCount === judges.length ? "Concluido" : "Pendente"}
                        </span>
                      );
                    },
                  },
                ]}
                rows={participants}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/judge/criteria"
        element={
          <AdminGenericPage
            title="Criterios"
            subtitle="Visual simplificado para o jurado entender os quesitos."
            navItems={judgeNav}
            profileLabel="Jurado"
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <DataTable
                columns={[
                  { key: "name", label: "Criterio" },
                  { key: "description", label: "Descricao", render: (row) => row.description || "Sem descricao" },
                  { key: "weight", label: "Peso", render: (row) => `${formatScore(row.weight, 1)}x` },
                ]}
                rows={criteria}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/judge/resume"
        element={
          <AdminGenericPage
            title="Resumo de notas"
            subtitle="Painel compacto com somatorias por participante."
            navItems={judgeNav}
            profileLabel="Jurado"
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <article className="panel-card">
              <DataTable
                columns={[
                  { key: "position", label: "#", render: (_, index) => index + 1 },
                  { key: "participant", label: "Participante", render: (row) => row.participant.name },
                  { key: "totalScore", label: "Somatoria", render: (row) => formatScore(row.totalScore, 1) },
                  { key: "voteCount", label: "Notas" },
                ]}
                rows={participantTotals}
              />
            </article>
          </AdminGenericPage>
        }
      />
      <Route
        path="/judge/instructions"
        element={
          <AdminGenericPage
            title="Instrucoes"
            subtitle="Checklist visual do fluxo de avaliacao."
            navItems={judgeNav}
            profileLabel="Jurado"
            events={events}
            eventId={eventId}
            onEventChange={setEventId}
          >
            <section className="instruction-grid">
              <article className="panel-card">
                <h2>Como votar</h2>
                <ol className="instruction-list">
                  <li>Escolha primeiro a escala inteira de 0 a 10.</li>
                  <li>Toque no decimal logo abaixo para fechar a nota.</li>
                  <li>Revise checklist e assinatura antes de salvar.</li>
                  <li>Use tablet, celular ou desktop sem perder o contexto visual.</li>
                </ol>
              </article>

              <article className="panel-card">
                <h2>Protecao contra falhas</h2>
                <ul className="instruction-list">
                  <li>Fila local em caso de instabilidade.</li>
                  <li>Espelho em sessao do navegador.</li>
                  <li>Retentativa automatica quando a conexao volta.</li>
                </ul>
              </article>
            </section>
          </AdminGenericPage>
        }
      />
      <Route path="/monitoring" element={<MonitoringScreen currentEvent={currentEvent} participantTotals={participantTotals} judgeProgress={judgeProgress} />} />
      <Route path="*" element={<LoginScreen />} />
    </Routes>
  );
}
