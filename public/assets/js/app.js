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

const hideAllDecimalPickers = (exceptRow = null) => {
    document.querySelectorAll('[data-decimal-picker]').forEach((picker) => {
        if (exceptRow && exceptRow.contains(picker)) {
            return;
        }
        picker.hidden = true;
        picker.innerHTML = '';
    });
};

const syncDecimalPicker = (row, numericValue, keepOpen = true) => {
    if (!row) {
        return;
    }
    const picker = row.querySelector('[data-decimal-picker]');
    if (!picker) {
        return;
    }

    const value = Number(numericValue);
    if (Number.isNaN(value)) {
        picker.hidden = true;
        picker.innerHTML = '';
        return;
    }

    const integerPart = Math.floor(value);
    if (integerPart >= 10) {
        picker.hidden = true;
        picker.innerHTML = '';
        return;
    }

    hideAllDecimalPickers(row);
    if (!keepOpen) {
        picker.hidden = true;
        picker.innerHTML = '';
        return;
    }

    picker.hidden = false;
    const currentText = value.toFixed(1);
    let html = '';
    for (let decimal = 0; decimal <= 9; decimal++) {
        const decimalValue = `${integerPart}.${decimal}`;
        const activeClass = currentText === decimalValue ? 'active' : '';
        html += `<button type="button" class="${activeClass}" data-decimal-value="${decimalValue}">${decimalValue.replace('.', ',')}</button>`;
    }
    picker.innerHTML = html;
};

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
        const integerValue = Number(input.value);
        scoreBox.value = integerValue.toFixed(1);
        if (integerValue >= 10) {
            hideAllDecimalPickers();
        } else {
            syncDecimalPicker(row, integerValue, true);
        }
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
        const integerPart = Math.floor(value);
        const checked = radio && ((Number(radio.value) === 10 && value === 10) || (Number(radio.value) === integerPart && value < 10));
        label.classList.toggle('checked', checked);
        if (radio) {
            radio.checked = checked;
        }
    });
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-decimal-value]');
    if (!button) {
        if (!event.target.closest('.score-picker-wrap')) {
            hideAllDecimalPickers();
        }
        return;
    }

    const row = button.closest('.criterion-row');
    if (!row) {
        return;
    }

    const scoreBox = row.querySelector('.score-box');
    const decimalValue = Number(button.dataset.decimalValue);
    if (!scoreBox || Number.isNaN(decimalValue)) {
        return;
    }

    scoreBox.value = decimalValue.toFixed(1);
    scoreBox.dispatchEvent(new Event('input', { bubbles: true }));
    hideAllDecimalPickers();
});

document.querySelectorAll('[data-toggle-tutorial]').forEach((button) => {
    button.addEventListener('click', () => {
        const content = document.querySelector('[data-tutorial-content]');
        if (content) {
            content.hidden = !content.hidden;
        }
    });
});

/* O menu lateral passou a ser tratado em ui.js, que cuida dos dois modos:
   rail recolhivel no desktop e gaveta sobreposta no celular. Manter o
   antigo aqui faria os dois responderem ao mesmo clique, cada um mexendo
   em uma classe diferente. */

document.querySelectorAll('[data-signature-block]').forEach((block) => {
    const modeInputs = Array.from(block.querySelectorAll('input[name="signature_mode"]'));
    const textField = block.querySelector('[data-signature-text]');
    const padWrap = block.querySelector('[data-signature-pad-wrap]');
    const canvas = block.querySelector('[data-signature-pad]');
    const clearButton = block.querySelector('[data-signature-clear]');
    const output = block.closest('form')?.querySelector('[data-signature-output]');
    if (!canvas || !output || !modeInputs.length) {
        return;
    }

    const context = canvas.getContext('2d');
    if (!context) {
        return;
    }

    const resizeCanvas = () => {
        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        const width = Math.max(280, Math.floor(rect.width || canvas.width));
        const height = Math.max(140, Math.floor(rect.height || canvas.height));
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.scale(ratio, ratio);
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.lineWidth = 2.2;
        context.strokeStyle = '#0b2f7f';
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
    };

    const drawStoredImage = () => {
        if (!output.value) {
            return;
        }
        const image = new Image();
        image.onload = () => {
            const rect = canvas.getBoundingClientRect();
            const width = Math.max(280, Math.floor(rect.width || canvas.width));
            const height = Math.max(140, Math.floor(rect.height || canvas.height));
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.drawImage(image, 0, 0, width, height);
        };
        image.src = output.value;
    };

    resizeCanvas();
    drawStoredImage();

    let drawing = false;
    let moved = false;

    const getPoint = (event) => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    };

    const startDraw = (event) => {
        if (canvas.closest('[hidden]')) {
            return;
        }
        drawing = true;
        moved = false;
        const point = getPoint(event);
        context.beginPath();
        context.moveTo(point.x, point.y);
        event.preventDefault();
    };

    const moveDraw = (event) => {
        if (!drawing) {
            return;
        }
        moved = true;
        const point = getPoint(event);
        context.lineTo(point.x, point.y);
        context.stroke();
        event.preventDefault();
    };

    const stopDraw = () => {
        if (!drawing) {
            return;
        }
        drawing = false;
        if (moved) {
            output.value = canvas.toDataURL('image/png');
            output.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };

    canvas.addEventListener('pointerdown', startDraw);
    canvas.addEventListener('pointermove', moveDraw);
    canvas.addEventListener('pointerup', stopDraw);
    canvas.addEventListener('pointerleave', stopDraw);
    canvas.addEventListener('signature:restore', drawStoredImage);

    clearButton?.addEventListener('click', () => {
        resizeCanvas();
        output.value = '';
        output.dispatchEvent(new Event('input', { bubbles: true }));
    });

    const syncMode = () => {
        const mode = modeInputs.find((input) => input.checked)?.value || 'text';
        if (textField) {
            textField.hidden = mode !== 'text';
        }
        if (padWrap) {
            padWrap.hidden = mode !== 'touch';
        }
    };

    modeInputs.forEach((input) => input.addEventListener('change', syncMode));
    window.addEventListener('resize', () => {
        const currentValue = output.value;
        resizeCanvas();
        if (currentValue) {
            output.value = currentValue;
            drawStoredImage();
        }
    });
    syncMode();
});

setTimeout(() => {
    const flash = document.querySelector('.flash');
    if (flash) {
        flash.style.transition = 'opacity 250ms ease';
        flash.style.opacity = '0';
    }
}, 4200);

/* ---------------------------------------------------------------------------
   Atualizacao automatica das telas ao vivo (telao e acompanhamento).
 *
 * Antes era um `setTimeout` seco que recarregava a pagina inteira, sem
 * verificar nada. Isso derrubava o que o usuario estivesse fazendo: texto
 * digitado pela metade, nota escolhida e ainda nao enviada, menu aberto.
 * E continuava recarregando com a aba em segundo plano, batendo no banco
 * sem ninguem olhando.
 *
 * Agora a contagem so corre quando faz sentido, e para assim que houver
 * qualquer sinal de que a pessoa esta no meio de alguma coisa.
 * ------------------------------------------------------------------------- */
(function () {
    const alvo = document.querySelector('[data-refresh-seconds]');
    if (!alvo) {
        return;
    }

    // Piso de 10s: valores menores so serviam para piscar a tela.
    const intervalo = Math.max(10, Number(alvo.dataset.refreshSeconds) || 20);
    let restante = intervalo;
    let sujo = false;

    // Qualquer digitacao marca a pagina como "em uso" ate o proximo envio.
    document.addEventListener('input', () => { sujo = true; });
    document.addEventListener('submit', () => { sujo = false; });

    function podeAtualizar() {
        if (document.hidden) return false;              // aba em segundo plano
        if (sujo) return false;                         // formulario preenchido
        if (document.querySelector('.is-menu-open')) return false;  // gaveta aberta

        const foco = document.activeElement;
        if (foco && ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(foco.tagName)) {
            return false;                               // cursor dentro de um campo
        }

        return !window.getSelection || String(window.getSelection()) === ''; // texto selecionado
    }

    setInterval(() => {
        if (!podeAtualizar()) {
            restante = intervalo;   // adia enquanto a pagina estiver em uso
            return;
        }

        restante -= 1;
        if (restante <= 0) {
            window.location.reload();
        }
    }, 1000);
})();

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
    const sessionQueueKey = `festival_pending_votes_session_${eventId}_${judgeId}`;
    const draftKey = `festival_vote_draft_${eventId}_${judgeId}_${participantId}`;
    const statusBox = offlineForm.querySelector('[data-offline-status]');
    const requestTimeoutMs = 12000;

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
        const payload = JSON.stringify(serializeForm());
        localStorage.setItem(draftKey, payload);
        sessionStorage.setItem(draftKey, payload);
    };

    const loadDraft = () => {
        const raw = localStorage.getItem(draftKey) || sessionStorage.getItem(draftKey);
        if (!raw) {
            return;
        }
        try {
            const entries = JSON.parse(raw);
            entries.forEach(([name, value]) => {
                const fields = Array.from(offlineForm.querySelectorAll(`[name="${CSS.escape(name)}"]`));
                fields.forEach((field) => {
                    if (field.type === "radio") {
                        field.checked = field.value === value;
                        if (field.checked) {
                            field.dispatchEvent(new Event("change", { bubbles: true }));
                        }
                        return;
                    }
                    if (field.type === "checkbox") {
                        field.checked = value === "on" || value === "1" || value === true;
                        field.dispatchEvent(new Event("change", { bubbles: true }));
                        return;
                    }
                    field.value = value;
                    field.dispatchEvent(new Event("input", { bubbles: true }));
                });
            });
            const signatureOutput = offlineForm.querySelector('[data-signature-output]');
            const signaturePad = offlineForm.querySelector('[data-signature-pad]');
            if (signatureOutput && signaturePad && signatureOutput.value) {
                signaturePad.dispatchEvent(new Event('signature:restore'));
            }
        } catch (error) {
            localStorage.removeItem(draftKey);
            sessionStorage.removeItem(draftKey);
        }
    };

    const readQueue = () => {
        try {
            const primary = JSON.parse(localStorage.getItem(queueKey) || "[]");
            if (Array.isArray(primary) && primary.length) {
                return primary;
            }
            const fallback = JSON.parse(sessionStorage.getItem(sessionQueueKey) || "[]");
            return Array.isArray(fallback) ? fallback : [];
        } catch (error) {
            return [];
        }
    };

    const writeQueue = (items) => {
        localStorage.setItem(queueKey, JSON.stringify(items));
        sessionStorage.setItem(sessionQueueKey, JSON.stringify(items));
    };

    const enqueueCurrent = (reason = "offline") => {
        const queue = readQueue().filter((item) => item.participantId !== participantId);
        queue.push({
            participantId,
            entries: serializeForm(),
            url: window.location.pathname + window.location.search,
            savedAt: new Date().toISOString(),
            reason
        });
        writeQueue(queue);
        saveDraft();
        setStatus("Conexão instável. As notas foram guardadas neste dispositivo e serão enviadas automaticamente.", "pending");
    };

    const sendEntries = async (entries) => {
        const body = new URLSearchParams();
        entries.forEach(([name, value]) => body.append(name, value));
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);
        let response;
        try {
            response = await fetch(window.location.pathname + window.location.search, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "application/json"
                },
                body: body.toString(),
                signal: controller.signal
            });
        } catch (error) {
            if (error.name === "AbortError") {
                const timeoutError = new Error("O envio demorou demais. As notas foram guardadas para reenvio automático.");
                timeoutError.code = "TIMEOUT";
                throw timeoutError;
            }
            throw error;
        } finally {
            window.clearTimeout(timeout);
        }
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            const error = new Error(payload.message || "Falha ao salvar notas.");
            error.status = response.status;
            error.payload = payload;
            throw error;
        }
        return payload;
    };

    const validateScores = () => {
        const scoreBoxes = Array.from(offlineForm.querySelectorAll(".score-box"));
        const emptyField = scoreBoxes.find((field) => String(field.value).trim() === "");
        if (emptyField) {
            setStatus("Não foi possível salvar: preencha todas as notas antes de continuar.", "error");
            emptyField.focus();
            return false;
        }
        return offlineForm.reportValidity();
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
            sessionStorage.removeItem(draftKey);
            setStatus("Conexão restaurada. As notas pendentes foram salvas com sucesso.", "success");
        } else {
            setStatus("Algumas notas continuam pendentes. Elas serão reenviadas na próxima reconexão.", "pending");
        }
    };

    loadDraft();
    if (readQueue().length) {
        setStatus("Há notas pendentes neste dispositivo. O sistema vai tentar reenviar automaticamente.", "pending");
    }

    offlineForm.addEventListener("input", saveDraft);

    offlineForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const nextInput = offlineForm.querySelector('input[name="next_url"]');
        const submitter = event.submitter || document.activeElement;
        if (nextInput) {
            nextInput.value = submitter && submitter.dataset ? (submitter.dataset.nextUrl || "") : "";
        }
        if (!validateScores()) {
            return;
        }
        saveDraft();
        if (!navigator.onLine) {
            enqueueCurrent();
            return;
        }
        try {
            setStatus("Salvando notas...", "pending");
            const payload = await sendEntries(serializeForm());
            localStorage.removeItem(draftKey);
            sessionStorage.removeItem(draftKey);
            const queue = readQueue().filter((item) => item.participantId !== participantId);
            writeQueue(queue);
            setStatus("Notas salvas com sucesso.", "success");
            window.location.href = payload.redirect || window.location.href;
        } catch (error) {
            if (error.status && error.status < 500) {
                setStatus(error.message || "Não foi possível salvar as notas.", "error");
                return;
            }
            enqueueCurrent(error.code === "TIMEOUT" ? "timeout" : "network");
        }
    });

    window.addEventListener("online", flushQueue);
    window.addEventListener("offline", () => {
        setStatus("Conexão perdida. Continue preenchendo; as notas serão guardadas neste dispositivo.", "pending");
    });

    flushQueue();
}
