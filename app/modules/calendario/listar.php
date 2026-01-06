<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';
require_once __DIR__ . '/../clientes/clientes_helper.php';

ensureClientesTable($pdo);
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card" id="cardCalendarioVencimentos">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1">Calendario de vencimentos</h5>
                    <p class="text-muted mb-0">Clientes posicionados nos dias de validade com atualizacao em tempo real.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" type="button" id="btnCalendarReload">
                        <i class="las la-sync-alt me-1"></i> Atualizar
                    </button>
                    <button class="btn btn-outline-primary" type="button" id="btnHoje">
                        <i class="las la-calendar-day me-1"></i> Hoje
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-center mb-3" aria-label="Legenda do calendario">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill" style="background:#dc3545;">Vencido</span>
                        <small class="text-muted">Data ja passou</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill" style="background:#ffc107; color:#111;">Vence em breve</span>
                        <small class="text-muted">Proximos 7 dias</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill" style="background:#0d6efd;">Em dia</span>
                        <small class="text-muted">Clientes ativos</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill" style="background:#6c757d;">Inativo</span>
                        <small class="text-muted">Cliente pausado</small>
                    </div>
                </div>

                <div id="calendarioAlert" class="alert d-none" role="alert"></div>

                <div class="position-relative">
                    <div id="calendarLoading" class="text-center py-5 d-none">
                        <div class="spinner-border text-primary" role="status" aria-label="Carregando calendario"></div>
                        <p class="mt-2 mb-0 text-muted small">Carregando vencimentos...</p>
                    </div>
                    <div id="vencimentosCalendar"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1">Proximos vencimentos (15 dias)</h6>
                    <p class="text-muted mb-0">Resumo em lista do que esta chegando.</p>
                </div>
                <span class="badge bg-light text-body-secondary border">Auto</span>
            </div>
            <div class="card-body" style="max-height: 620px; overflow-y: auto;">
                <div id="proximosPlaceholder" class="text-muted small">Carregue o calendario para ver os proximos.</div>
                <ul class="list-group list-group-flush" id="proximosList"></ul>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>public/assets/libs/fullcalendar/index.global.min.js"></script>
<script>
(function () {
    const calendarEl = document.getElementById('vencimentosCalendar');
    const alertEl = document.getElementById('calendarioAlert');
    const loadingEl = document.getElementById('calendarLoading');
    const proximosList = document.getElementById('proximosList');
    const proximosPlaceholder = document.getElementById('proximosPlaceholder');
    const endpoint = '<?= BASE_URL ?>app/modules/calendario/calendario_controller.php';

    if (!calendarEl || !window.FullCalendar) {
        return;
    }

    const moneyFormatter = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
    });

    function showAlert(type, message) {
        if (!alertEl) return;
        alertEl.className = 'alert alert-' + type;
        alertEl.textContent = message || '';
        alertEl.classList.remove('d-none');
    }

    function hideAlert() {
        if (!alertEl) return;
        alertEl.classList.add('d-none');
    }

    function toggleLoading(isLoading) {
        if (!loadingEl) return;
        loadingEl.classList.toggle('d-none', !isLoading);
    }

    function formatDateBr(dateStr) {
        if (!dateStr) return '';
        const [y, m, d] = dateStr.split('-').map(Number);
        if (!y || !m || !d) return dateStr;
        return String(d).padStart(2, '0') + '/' + String(m).padStart(2, '0');
    }

    function formatPhone(phone) {
        if (!phone) return '';
        const digits = String(phone).replace(/\D+/g, '');
        if (digits.length === 13) {
            return `+${digits.slice(0, 2)} (${digits.slice(2, 4)}) ${digits.slice(4, 9)}-${digits.slice(9)}`;
        }
        if (digits.length === 12) {
            return `+${digits.slice(0, 2)} (${digits.slice(2, 4)}) ${digits.slice(4, 8)}-${digits.slice(8)}`;
        }
        if (digits.length === 11) {
            return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
        }
        if (digits.length === 10) {
            return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
        }
        return phone;
    }

    function eventContent(info) {
        const props = info.event.extendedProps || {};
        const wrapper = document.createElement('div');
        wrapper.className = 'fc-event-main-content';

        const title = document.createElement('div');
        title.className = 'fw-semibold';
        title.textContent = info.event.title || 'Cliente';

        const detail = document.createElement('div');
        detail.className = 'small';
        const bits = [];
        if (props.plano) bits.push(props.plano);
        if (props.valor_plano !== null && props.valor_plano !== undefined && props.valor_plano !== '') {
            bits.push(moneyFormatter.format(Number(props.valor_plano)));
        }
        if (props.status) bits.push(props.status);
        detail.textContent = bits.join(' • ');

        wrapper.appendChild(title);
        wrapper.appendChild(detail);
        return { domNodes: [wrapper] };
    }

    function updateUpcoming(events) {
        proximosList.innerHTML = '';
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const limit = new Date(today);
        limit.setDate(limit.getDate() + 15);

        const items = (events || [])
            .map((ev) => {
                const date = ev.start ? new Date(ev.start) : null;
                if (!date) return null;
                return {
                    nome: ev.title || 'Cliente',
                    data: date,
                    status: ev.extendedProps?.status || '',
                    valor: ev.extendedProps?.valor_plano,
                };
            })
            .filter(Boolean)
            .filter((item) => item.data >= today && item.data <= limit)
            .sort((a, b) => a.data - b.data)
            .slice(0, 12);

        if (items.length === 0) {
            proximosPlaceholder.classList.remove('d-none');
            proximosPlaceholder.textContent = 'Nenhum vencimento nos proximos 15 dias.';
            return;
        }

        proximosPlaceholder.classList.add('d-none');
        items.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-start';

            const info = document.createElement('div');
            info.className = 'me-3';
            info.innerHTML = `<div class="fw-semibold">${item.nome}</div>
                <div class="text-muted small">${item.status || 'Em dia'}</div>`;

            const badge = document.createElement('div');
            badge.className = 'text-end';
            badge.innerHTML = `<div class="badge bg-light text-body-secondary">${formatDateBr(item.data.toISOString().slice(0, 10))}</div>
                ${item.valor ? `<div class="small text-muted mt-1">${moneyFormatter.format(Number(item.valor))}</div>` : ''}`;

            li.appendChild(info);
            li.appendChild(badge);
            proximosList.appendChild(li);
        });
    }

    function fetchEvents(info, successCallback, failureCallback) {
        const params = new URLSearchParams({
            action: 'events',
            start: (info.startStr || '').slice(0, 10),
            end: (info.endStr || '').slice(0, 10),
        });

        toggleLoading(true);
        hideAlert();

        fetch(endpoint + '?' + params.toString(), {
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data || data.success !== true) {
                    const message = data?.message || 'Nao foi possivel carregar o calendario.';
                    showAlert('danger', message);
                    failureCallback(new Error(message));
                    return;
                }
                successCallback(data.events || []);
                updateUpcoming(data.events || []);
            })
            .catch((err) => {
                console.error(err);
                showAlert('danger', 'Erro ao consultar os vencimentos.');
                failureCallback(err);
            })
            .finally(() => toggleLoading(false));
    }

    function onEventClick(info) {
        info.jsEvent?.preventDefault();
        const props = info.event.extendedProps || {};
        const validade = props.validade || info.event.startStr || '';

        const detalhes = [
            `<b>Validade:</b> ${formatDateBr(validade)}`,
            props.plano ? `<b>Plano:</b> ${props.plano}` : '',
            props.valor_plano !== null && props.valor_plano !== undefined && props.valor_plano !== '' ? `<b>Valor:</b> ${moneyFormatter.format(Number(props.valor_plano))}` : '',
            props.status ? `<b>Status:</b> ${props.status}` : '',
            props.telefone ? `<b>Telefone:</b> ${formatPhone(props.telefone)}` : '',
            props.provedor ? `<b>Provedor:</b> ${props.provedor}` : '',
            props.quantidade_telas ? `<b>Telas:</b> ${props.quantidade_telas}` : '',
        ].filter(Boolean).join('<br>');

        if (window.Swal) {
            Swal.fire({
                title: info.event.title || 'Cliente',
                html: detalhes || 'Sem detalhes adicionais.',
                confirmButtonText: 'Fechar',
                customClass: { confirmButton: 'btn btn-primary' },
                buttonsStyling: false,
            });
        }
    }

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'pt-br',
        locales: FullCalendar.globalLocales || [],
        themeSystem: 'standard',
        height: 'auto',
        firstDay: 1,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek',
        },
        buttonText: {
            today: 'Hoje',
            month: 'Mes',
            week: 'Semana',
            day: 'Dia',
            list: 'Lista',
        },
        events: fetchEvents,
        loading: toggleLoading,
        eventContent,
        displayEventTime: false,
        eventClick: onEventClick,
    });

    calendar.render();

    document.getElementById('btnCalendarReload')?.addEventListener('click', () => {
        calendar.refetchEvents();
    });

    document.getElementById('btnHoje')?.addEventListener('click', () => {
        calendar.today();
    });
})();
</script>
