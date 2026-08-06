document.addEventListener('input', (event) => {
    const input = event.target;
    if (!input.matches('.vote-form input[type="number"]')) {
        return;
    }

    const value = Number(input.value);
    if (Number.isNaN(value)) {
        return;
    }

    if (value < 0) {
        input.value = 0;
    }

    if (value > 10) {
        input.value = 10;
    }
});

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!input.matches('.score-picker input[type="radio"]')) {
        return;
    }

    const row = input.closest('.criterion-row');
    if (!row) {
        return;
    }

    row.querySelectorAll('.score-picker label').forEach((label) => {
        label.classList.toggle('checked', label.contains(input));
    });

    const scoreBox = row.querySelector('.score-box');
    if (scoreBox) {
        scoreBox.value = Number(input.value).toFixed(1);
    }
});

document.addEventListener('input', (event) => {
    const input = event.target;
    if (!input.matches('.score-box')) {
        return;
    }

    const value = Math.max(0, Math.min(10, Number(String(input.value).replace(',', '.'))));
    if (Number.isNaN(value)) {
        return;
    }

    const row = input.closest('.criterion-row');
    if (!row) {
        return;
    }

    row.querySelectorAll('.score-picker label').forEach((label) => {
        const radio = label.querySelector('input[type="radio"]');
        const checked = radio && Number(radio.value) === value;
        label.classList.toggle('checked', checked);
        if (radio) {
            radio.checked = checked;
        }
    });
});

document.querySelectorAll('[data-toggle-tutorial]').forEach((button) => {
    button.addEventListener('click', () => {
        const content = document.querySelector('[data-tutorial-content]');
        if (content) {
            content.hidden = !content.hidden;
        }
    });
});

setTimeout(() => {
    const flash = document.querySelector('.flash');
    if (flash) {
        flash.style.transition = 'opacity 250ms ease';
        flash.style.opacity = '0';
    }
}, 4200);

const liveScoreboard = document.querySelector('[data-refresh-seconds]');
if (liveScoreboard) {
    const seconds = Number(liveScoreboard.dataset.refreshSeconds || 20);
    setTimeout(() => window.location.reload(), seconds * 1000);
}

const judgeTimer = document.getElementById('judge-timer');
if (judgeTimer) {
    const deadline = new Date(judgeTimer.dataset.deadline).getTime();
    const tick = () => {
        const remaining = Math.max(0, deadline - Date.now());
        const totalSeconds = Math.floor(remaining / 1000);
        const hours = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
        const seconds = String(totalSeconds % 60).padStart(2, '0');
        judgeTimer.textContent = `${hours}:${minutes}:${seconds}`;

        if (remaining <= 0) {
            document.querySelectorAll('.criteria-vote-form input, .criteria-vote-form textarea, .criteria-vote-form button')
                .forEach((element) => {
                    element.disabled = true;
                });
        }
    };

    tick();
    setInterval(tick, 1000);
}

document.querySelectorAll('[data-phase-toggle]').forEach((select) => {
    const form = select.closest('form');
    const phaseFields = form ? form.querySelector('.phase-fields') : null;
    const syncPhaseFields = () => {
        if (phaseFields) {
            phaseFields.hidden = select.value !== 'fases';
        }
    };

    select.addEventListener('change', syncPhaseFields);
    syncPhaseFields();
});

document.querySelectorAll('[data-period-mode]').forEach((select) => {
    const form = select.closest('form');
    const syncPeriodRows = () => {
        if (!form) {
            return;
        }

        form.querySelectorAll('[data-period-row]').forEach((row) => {
            row.hidden = row.dataset.periodRow !== select.value;
        });
    };

    select.addEventListener('change', syncPeriodRows);
    syncPeriodRows();
});

const offlineForm = document.querySelector('[data-offline-form]');
if (offlineForm) {
    const eventId = offlineForm.dataset.eventId;
    const judgeId = offlineForm.dataset.judgeId;
    const participantId = offlineForm.dataset.participantId;
    const queueKey = `festival_pending_votes_${eventId}_${judgeId}`;
    const draftKey = `festival_vote_draft_${eventId}_${judgeId}_${participantId}`;
    const statusBox = offlineForm.querySelector('[data-offline-status]');

    const setStatus = (message, kind = "pending") => {
        if (!statusBox) {
            return;
        }
        statusBox.hidden = !message;
        statusBox.className = `offline-status ${kind}`;
        statusBox.textContent = message;
    };

    const serializeForm = () => {
        const formData = new FormData(offlineForm);
        return Array.from(formData.entries());
    };

    const saveDraft = () => {
        localStorage.setItem(draftKey, JSON.stringify(serializeForm()));
    };

    const loadDraft = () => {
        const raw = localStorage.getItem(draftKey);
        if (!raw) {
            return;
        }
        try {
            const entries = JSON.parse(raw);
            entries.forEach(([name, value]) => {
                const field = offlineForm.querySelector(`[name="${CSS.escape(name)}"]`);
                if (field && field.type !== "hidden") {
                    field.value = value;
                    field.dispatchEvent(new Event("input", { bubbles: true }));
                }
            });
        } catch (error) {
            localStorage.removeItem(draftKey);
        }
    };

    const readQueue = () => {
        try {
            return JSON.parse(localStorage.getItem(queueKey) || "[]");
        } catch (error) {
            return [];
        }
    };

    const writeQueue = (items) => {
        localStorage.setItem(queueKey, JSON.stringify(items));
    };

    const enqueueCurrent = () => {
        const queue = readQueue().filter((item) => item.participantId !== participantId);
        queue.push({ participantId, entries: serializeForm(), url: window.location.pathname + window.location.search });
        writeQueue(queue);
        saveDraft();
        setStatus("Sem conexao. As notas foram guardadas e serao enviadas automaticamente quando a internet voltar.", "pending");
    };

    const sendEntries = async (entries) => {
        const body = new URLSearchParams();
        entries.forEach(([name, value]) => body.append(name, value));
        const response = await fetch(window.location.pathname + window.location.search, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json"
            },
            body: body.toString()
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || "Falha ao salvar notas.");
        }
        return payload;
    };

    const flushQueue = async () => {
        if (!navigator.onLine) {
            return;
        }
        let queue = readQueue();
        if (!queue.length) {
            return;
        }

        setStatus(`Reconectado. Enviando ${queue.length} avaliacao(oes) pendente(s)...`, "pending");
        const remaining = [];
        for (const item of queue) {
            try {
                await sendEntries(item.entries);
            } catch (error) {
                remaining.push(item);
            }
        }
        writeQueue(remaining);
        if (!remaining.length) {
            localStorage.removeItem(draftKey);
            setStatus("Conexao restaurada. As notas pendentes foram salvas com sucesso.", "success");
        } else {
            setStatus("Algumas notas continuam pendentes. Elas serao reenviadas na proxima reconexao.", "pending");
        }
    };

    loadDraft();
    if (readQueue().length) {
        setStatus("Ha notas pendentes neste dispositivo. O sistema vai tentar reenviar automaticamente.", "pending");
    }

    offlineForm.addEventListener("input", saveDraft);

    offlineForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        saveDraft();
        if (!navigator.onLine) {
            enqueueCurrent();
            return;
        }
        try {
            const payload = await sendEntries(serializeForm());
            localStorage.removeItem(draftKey);
            const queue = readQueue().filter((item) => item.participantId !== participantId);
            writeQueue(queue);
            window.location.href = payload.redirect || window.location.href;
        } catch (error) {
            enqueueCurrent();
        }
    });

    window.addEventListener("online", flushQueue);
    window.addEventListener("offline", () => {
        setStatus("Conexao perdida. Continue preenchendo; as notas serao guardadas neste dispositivo.", "pending");
    });

    flushQueue();
}

const networkBanner = document.querySelector('[data-network-banner]');
if (networkBanner) {
    const syncNetworkBanner = () => {
        if (navigator.onLine) {
            networkBanner.hidden = true;
            networkBanner.textContent = "";
            return;
        }

        networkBanner.hidden = false;
        networkBanner.textContent = "Sem conexao no momento. Voce pode continuar preenchendo; o app vai tentar recuperar o envio quando a internet voltar.";
    };

    window.addEventListener("online", syncNetworkBanner);
    window.addEventListener("offline", syncNetworkBanner);
    syncNetworkBanner();
}

let deferredInstallPrompt = null;
const pwaBanner = document.querySelector('[data-pwa-banner]');
const installButton = document.querySelector('[data-pwa-install]');
const dismissInstallButton = document.querySelector('[data-pwa-dismiss]');

const hideInstallBanner = () => {
    if (pwaBanner) {
        pwaBanner.hidden = true;
    }
};

const showInstallBanner = () => {
    if (pwaBanner && !window.matchMedia('(display-mode: standalone)').matches) {
        pwaBanner.hidden = false;
    }
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    showInstallBanner();
});

if (installButton) {
    installButton.addEventListener('click', async () => {
        if (!deferredInstallPrompt) {
            return;
        }

        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice;
        deferredInstallPrompt = null;
        hideInstallBanner();
    });
}

if (dismissInstallButton) {
    dismissInstallButton.addEventListener('click', hideInstallBanner);
}

window.addEventListener('appinstalled', hideInstallBanner);

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {
            // Mantem a experiencia funcionando mesmo sem service worker.
        });
    });
}

const loginLayout = document.querySelector('[data-default-login-panel]');
if (loginLayout) {
    const panels = Array.from(document.querySelectorAll('[data-login-panel]'));
    const showPanel = (name) => {
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.loginPanel !== name;
        });
    };

    document.querySelectorAll('[data-open-login-panel]').forEach((button) => {
        button.addEventListener('click', () => {
            showPanel(button.dataset.openLoginPanel || '');
        });
    });

    document.querySelectorAll('[data-close-login-panel]').forEach((button) => {
        button.addEventListener('click', () => {
            panels.forEach((panel) => {
                panel.hidden = true;
            });
        });
    });

    const defaultPanel = loginLayout.dataset.defaultLoginPanel;
    if (defaultPanel) {
        showPanel(defaultPanel);
    }
}

document.querySelectorAll('[data-enable-target]').forEach((checkbox) => {
    const target = document.getElementById(checkbox.dataset.enableTarget || '');
    if (!target) {
        return;
    }

    const syncTarget = () => {
        const enabled = checkbox.checked;
        target.classList.toggle('is-disabled', !enabled);
        target.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    };

    checkbox.addEventListener('change', syncTarget);
    target.addEventListener('click', (event) => {
        if (target.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
        }
    });
    syncTarget();
});

document.querySelectorAll('[data-participant-jump]').forEach((select) => {
    select.addEventListener('change', () => {
        if (!select.value) {
            return;
        }

        const baseUrl = select.dataset.baseUrl || '?page=judge-panel&section=votacao';
        window.location.href = `${baseUrl}&participant_id=${encodeURIComponent(select.value)}`;
    });
});
