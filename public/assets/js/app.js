/**
 * IMSS Conservación - JavaScript Core
 * Módulos: Audio Chime (Web Audio API), Polling AJAX, Stepper dinámico, Gestión de Modales
 */

// 1. Sintetizador de Alerta Sonora Institucional (Web Audio API)
class SoundNotifier {
    static playChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();

            const playTone = (freq, startTime, duration) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, startTime);
                gain.gain.setValueAtTime(0.15, startTime);
                gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(startTime);
                osc.stop(startTime + duration);
            };

            const now = ctx.currentTime;
            
            // Repeating chime pattern for better noticeability (~2.5s duration)
            playTone(587.33, now, 0.4);         
            playTone(880.00, now + 0.3, 0.5);   
            
            playTone(587.33, now + 1.0, 0.4);   
            playTone(880.00, now + 1.3, 0.5);   

            playTone(587.33, now + 2.0, 0.4);   
            playTone(880.00, now + 2.3, 0.6);   
        } catch (e) {
            console.warn('Alerta de audio silenciada o bloqueada por política del navegador', e);
        }
    }
}

// 2. Control de Modales
function openModal(modalId) {
    if (!modalId) return;
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    if (modalId && typeof modalId === 'string') {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            modal.classList.remove('open');
        }
    } else {
        document.querySelectorAll('.modal-overlay, .modal-bg').forEach(m => {
            m.classList.remove('active');
            m.classList.remove('open');
        });
    }
    document.body.style.overflow = 'auto';
}

// 2.1 Control de Menú Lateral en Móviles / Pantallas Pequeñas
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    let backdrop = document.getElementById('sidebarBackdrop');
    
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'sidebarBackdrop';
        backdrop.className = 'sidebar-backdrop';
        backdrop.onclick = toggleMobileSidebar;
        document.body.appendChild(backdrop);
    }
    
    if (sidebar) {
        const isOpen = sidebar.classList.toggle('open');
        backdrop.classList.toggle('active', isOpen);
    }
}

// 3. Manejador de Formulario Público de Levantamiento de Reportes
document.addEventListener('DOMContentLoaded', () => {
    const formReporte = document.getElementById('formNuevoReporte');
    if (formReporte) {
        formReporte.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = formReporte.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Generando Folio Institucional...';

            const formData = new FormData(formReporte);

            try {
                const response = await fetch('api_public.php?action=create_report', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Modal de confirmación con el folio
                    document.getElementById('modalFolioGenerado').textContent = result.folio;
                    document.getElementById('modalAreaGenerada').textContent = result.area;
                    document.getElementById('modalFechaGenerada').textContent = result.fecha;
                    
                    const btnPdf = document.getElementById('modalBtnPdf');
                    if (btnPdf) btnPdf.href = `reporte_pdf.php?folio=${result.folio}`;
                    
                    const btnEstatus = document.getElementById('modalBtnEstatus');
                    if (btnEstatus) btnEstatus.href = `estatus.php?folio=${result.folio}`;

                    openModal('modalExitoReporte');
                    formReporte.reset();
                } else {
                    alert('Error al registrar el reporte: ' + (result.error || 'Verifique los campos.'));
                }
            } catch (error) {
                alert('Ocurrió un error en la comunicación con el servidor.');
                console.error(error);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    }

    // Inicializar Polling si estamos en Dashboard
    if (document.getElementById('adminDashboardTable')) {
        initAdminPolling();
    }
});

// 4. Polling en Tiempo Real para el Dashboard Administrativo
let lastKnownTicketId = 0;

function initAdminPolling() {
    const tableEl = document.getElementById('adminDashboardTable');
    if (tableEl && tableEl.dataset.lastId) {
        lastKnownTicketId = parseInt(tableEl.dataset.lastId, 10) || 0;
    }

    // Revisar nuevos tickets cada 6 segundos
    setInterval(async () => {
        try {
            const resp = await fetch(`api.php?action=check_updates&last_id=${lastKnownTicketId}`);
            const data = await resp.json();

            if (data.success && data.new_count > 0) {
                // Disparar Chime sonoro y badge visual
                SoundNotifier.playChime();
                showNotificationBadge(data.new_count);
                updateDashboardTable(data.tickets, data.latest_id);
                lastKnownTicketId = data.latest_id;
            }
        } catch (err) {
            console.debug('Error en polling de tickets:', err);
        }
    }, 6000);
}

function showNotificationBadge(newCount) {
    const banner = document.getElementById('newTicketsBanner');
    if (banner) {
        banner.style.display = 'flex';
        const msgEl = document.getElementById('newTicketsMsg');
        if (msgEl) {
            msgEl.textContent = `¡Atención! Se han recibido ${newCount} nuevos reporte(s) de servicio.`;
        }
    }
}

function updateDashboardTable(tickets, latestId) {
    const tbody = document.querySelector('#adminDashboardTable tbody');
    if (!tbody || !tickets) return;

    // Recargar tabla dinámicamente
    location.reload(); // Recarga limpia para mantener sincronizado el listado
}

// 5. Modal Dinámico de Gestión (Asignación y Cierre)
async function gestionarTicket(ticketId) {
    try {
        const resp = await fetch(`api.php?action=get_ticket&id=${ticketId}`);
        const data = await resp.json();

        if (!data.success) {
            alert('No se pudo cargar la información del ticket: ' + data.error);
            return;
        }

        const t = data.ticket;
        window.ticketActual = t;
        document.getElementById('mgFolio').textContent = t.folio;
        document.getElementById('mgArea').textContent = t.nombre_area;
        document.getElementById('mgSolicitante').textContent = `${t.cargo_solicitante || ''} ${t.solicitante_ap_paterno} ${t.solicitante_ap_materno} ${t.solicitante_nombres}`;
        document.getElementById('mgFalla').textContent = t.descripcion_falla;
        document.getElementById('mgFechaRecepcion').textContent = t.fecha_recepcion;
        document.getElementById('mgTicketId').value = t.id;

        // Fases del Modal según Estatus
        const secAsignar = document.getElementById('seccionAsignacion');
        const secCierre = document.getElementById('seccionCierre');
        const secConcluido = document.getElementById('seccionConcluido');

        secAsignar.style.display = 'none';
        secCierre.style.display = 'none';
        secConcluido.style.display = 'none';

        if (t.estatus === 'Pendiente') {
            secAsignar.style.display = 'block';
            initTecnicoAutocomplete(t.unidad_id);
            if (t.tecnico_asignado_id && t.nombre_tecnico) {
                seleccionarTecnico({
                    id: t.tecnico_asignado_id,
                    nombre: t.nombre_tecnico,
                    matricula: '',
                    categoria: t.categoria_tecnico || '',
                    turno: '',
                    clave_unidad: ''
                });
            }
        } else if (t.estatus === 'Asignado') {
            secCierre.style.display = 'block';
            document.getElementById('mgTecnicoAsignadoNombre').textContent = t.nombre_tecnico || 'Asignado';
            document.getElementById('mgObservacionesCierre').value = '';
            document.getElementById('mgMaterialesRefacciones').value = '';
            document.getElementById('mgCondicionFinal').value = '';
            document.getElementById('mgRecomendaciones').value = '';
            document.getElementById('mgRecibeNombre').value = '';
            document.getElementById('mgRecibeMatricula').value = '';
            
            // Pre-llenar fecha de recepción editable
            const inpFechaRec = document.getElementById('fecha_recepcion_edit');
            if (inpFechaRec && t.fecha_recepcion) {
                const dtR = new Date(t.fecha_recepcion.replace(' ', 'T'));
                if (!isNaN(dtR)) {
                    const tzoffset = dtR.getTimezoneOffset() * 60000;
                    inpFechaRec.value = (new Date(dtR.getTime() - tzoffset)).toISOString().slice(0, 16);
                }
            }
            const inpFechaCierre = document.getElementById('fecha_conclusion_manual');
            if (inpFechaCierre) {
                inpFechaCierre.value = '';
            }
            
            const chkSinRecepcion = document.getElementById('chkSinRecepcion');
            if (chkSinRecepcion) {
                chkSinRecepcion.checked = false;
                document.getElementById('seccionRecepcion').style.display = 'grid';
            }

            const chkSinFotos = document.getElementById('chkSinFotos');
            if (chkSinFotos) {
                chkSinFotos.checked = false;
                document.getElementById('seccionFotos').style.display = 'block';
            }

            const rCorrectivo = document.getElementById('tipoServicioCorrectivo');
            if (rCorrectivo) rCorrectivo.checked = true;
        } else if (t.estatus === 'Concluido') {
            secConcluido.style.display = 'block';
            document.getElementById('mgConcluidoTecnico').textContent = t.nombre_tecnico;
            document.getElementById('mgConcluidoFecha').textContent = t.fecha_conclusion;
            document.getElementById('mgConcluidoElaboro').textContent = t.nombre_elaboro || 'No registrado';
            document.getElementById('mgConcluidoOrigen').textContent = t.tipo_servicio || 'Correctivo';
            document.getElementById('mgConcluidoObs').textContent = t.solucion_cierre || 'Sin descripción';
            document.getElementById('mgConcluidoMateriales').textContent = t.materiales_refacciones || 'Ninguno especificado';
            document.getElementById('mgConcluidoCondicion').textContent = t.condicion_final || 'No registrada';
            document.getElementById('mgConcluidoRecomendaciones').textContent = t.recomendaciones || 'Sin recomendaciones';
            document.getElementById('mgConcluidoRecibeNombre').textContent = t.recibe_nombre || 'No registrado';
            document.getElementById('mgConcluidoRecibeMatricula').textContent = t.recibe_matricula ? `(${t.recibe_matricula})` : '';
            
            // Enlace al Formato Oficial Normado (14C2-003-002)
            document.getElementById('mgBtnPdfNormado').href = `../orden_servicio.php?id=${t.id}`;

            // Guardar rutas globalmente para el visor
            window.fotosTicketActual = {
                antes1: t.foto_antes_path,
                antes2: t.foto_antes_path_2,
                despues1: t.foto_despues_path,
                despues2: t.foto_despues_path_2
            };

            const secEvidencia = document.getElementById('seccionEvidencia');
            const secNoEvidencia = document.getElementById('noEvidencia');
            const btnAntes = document.getElementById('btnVerAntes');
            const btnDespues = document.getElementById('btnVerDespues');

            if (t.foto_antes_path || t.foto_despues_path) {
                secEvidencia.style.display = 'block';
                secNoEvidencia.style.display = 'none';
                
                btnAntes.style.display = (t.foto_antes_path || t.foto_antes_path_2) ? 'block' : 'none';
                btnDespues.style.display = (t.foto_despues_path || t.foto_despues_path_2) ? 'block' : 'none';
            } else {
                secEvidencia.style.display = 'none';
                secNoEvidencia.style.display = 'block';
            }
        }

        openModal('modalGestionTicket');
    } catch (e) {
        console.error(e);
        alert('Error al abrir la gestión del ticket.');
    }
}

// Autocompletado de Técnicos en Tiempo Real
let debounceTimerTecnico = null;

function seleccionarTecnico(tec) {
    const hiddenInput = document.getElementById('selectTecnicoAsignar');
    const inputContainer = document.getElementById('tecnicoInputContainer');
    const badgeSeleccionado = document.getElementById('autoTecnicoSeleccionado');
    const dropdown = document.getElementById('autoTecnicoDropdown');

    if (hiddenInput) hiddenInput.value = tec.id;
    if (document.getElementById('autoTecnicoNombre')) {
        document.getElementById('autoTecnicoNombre').textContent = tec.nombre;
    }
    if (document.getElementById('autoTecnicoDetalle')) {
        const mat = tec.matricula ? `Matrícula: ${tec.matricula} • ` : '';
        const cat = tec.categoria ? `${tec.categoria} • ` : '';
        const uni = tec.clave_unidad ? `Unidad: ${tec.clave_unidad}` : '';
        document.getElementById('autoTecnicoDetalle').textContent = `${mat}${cat}${uni}`;
    }

    if (inputContainer) inputContainer.style.display = 'none';
    if (badgeSeleccionado) badgeSeleccionado.style.display = 'flex';
    if (dropdown) dropdown.style.display = 'none';
}

function limpiarTecnicoSeleccionado() {
    const hiddenInput = document.getElementById('selectTecnicoAsignar');
    const inputContainer = document.getElementById('tecnicoInputContainer');
    const badgeSeleccionado = document.getElementById('autoTecnicoSeleccionado');
    const autoInput = document.getElementById('autoTecnicoInput');
    const dropdown = document.getElementById('autoTecnicoDropdown');

    if (hiddenInput) hiddenInput.value = '';
    if (inputContainer) inputContainer.style.display = 'block';
    if (badgeSeleccionado) badgeSeleccionado.style.display = 'none';
    if (autoInput) {
        autoInput.value = '';
        setTimeout(() => autoInput.focus(), 100);
    }
    if (dropdown) dropdown.style.display = 'none';
}

async function buscarTecnicosPredictivo(query, unidadId) {
    const dropdown = document.getElementById('autoTecnicoDropdown');
    if (!dropdown) return;

    dropdown.innerHTML = '<div style="padding:0.75rem; color:#A1A1AA; font-size:0.85rem; text-align:center;">Buscando plantilla técnica...</div>';
    dropdown.style.display = 'block';

    try {
        const res = await fetch(`api.php?action=search_tecnicos_global&q=${encodeURIComponent(query)}&unidad_id=${unidadId || 0}`);
        const data = await res.json();

        if (data.success && data.tecnicos && data.tecnicos.length > 0) {
            dropdown.innerHTML = '';
            data.tecnicos.forEach(tec => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="color:#FFFFFF; font-weight:700; font-size:0.9rem;">${tec.nombre}</span>
                        <span style="font-size:0.75rem; background:#374151; color:#A7F3D0; padding:0.15rem 0.4rem; border-radius:4px; font-weight:600;">${tec.clave_unidad}</span>
                    </div>
                    <div style="font-size:0.78rem; color:#D1D5DB; margin-top:0.2rem;">
                        Matrícula: <strong style="color:#FACC15;">${tec.matricula}</strong> • ${tec.categoria} (Turno ${tec.turno})
                    </div>
                `;
                item.onclick = () => seleccionarTecnico(tec);
                dropdown.appendChild(item);
            });
        } else {
            dropdown.innerHTML = '<div style="padding:0.75rem; color:#9CA3AF; font-size:0.85rem; text-align:center;">No se encontraron técnicos con ese criterio.</div>';
        }
    } catch (e) {
        dropdown.innerHTML = '<div style="padding:0.75rem; color:#F87171; font-size:0.85rem; text-align:center;">Error al consultar técnicos.</div>';
    }
}

function initTecnicoAutocomplete(unidadId) {
    const autoInput = document.getElementById('autoTecnicoInput');
    const dropdown = document.getElementById('autoTecnicoDropdown');
    if (!autoInput) return;

    limpiarTecnicoSeleccionado();

    autoInput.oninput = (e) => {
        clearTimeout(debounceTimerTecnico);
        const val = e.target.value.trim();
        debounceTimerTecnico = setTimeout(() => {
            buscarTecnicosPredictivo(val, unidadId);
        }, 200);
    };

    autoInput.onfocus = () => {
        if (!document.getElementById('selectTecnicoAsignar').value) {
            buscarTecnicosPredictivo(autoInput.value.trim(), unidadId);
        }
    };

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#tecnicoInputContainer')) {
            if (dropdown) dropdown.style.display = 'none';
        }
    });
}

// 6. Pop-up Modal de Edición de Ticket
async function abrirModalEdicionTicket(ticketId, event) {
    if (event && event.target && event.target.closest('.btn-action')) {
        return;
    }

    try {
        const resp = await fetch(`api.php?action=get_ticket&id=${ticketId}`);
        const data = await resp.json();

        if (!data.success || !data.ticket) {
            alert('No se pudo cargar la información del ticket: ' + (data.error || ''));
            return;
        }

        const t = data.ticket;
        document.getElementById('editTicketId').value = t.id;
        document.getElementById('editModalFolio').textContent = `${t.folio} (${t.estatus})`;

        if (t.fecha_recepcion) {
            const dtR = new Date(t.fecha_recepcion.replace(' ', 'T'));
            if (!isNaN(dtR)) {
                const tzoffset = dtR.getTimezoneOffset() * 60000;
                document.getElementById('editFechaRecepcion').value = (new Date(dtR.getTime() - tzoffset)).toISOString().slice(0, 16);
            }
        }

        document.getElementById('editUnidadId').value = t.unidad_id;
        await cargarAreasEdicion(t.unidad_id, t.area_id);

        document.getElementById('editCargoSolicitante').value = t.cargo_solicitante || '';
        document.getElementById('editDescripcionFalla').value = t.descripcion_falla || '';
        document.getElementById('editSolicitanteApPaterno').value = t.solicitante_ap_paterno || '';
        document.getElementById('editSolicitanteApMaterno').value = t.solicitante_ap_materno || '';
        document.getElementById('editSolicitanteNombres').value = t.solicitante_nombres || '';
        document.getElementById('editObservaciones').value = t.observaciones || '';

        openModal('modalEditarTicket');
    } catch (e) {
        console.error(e);
        alert('Error al abrir la edición del ticket.');
    }
}

async function cargarAreasEdicion(unidadId, selectedAreaId) {
    const areaSelect = document.getElementById('editAreaId');
    if (!areaSelect) return;
    areaSelect.innerHTML = '<option value="">Cargando áreas...</option>';

    if (!unidadId) {
        areaSelect.innerHTML = '<option value="">-- Seleccione una unidad --</option>';
        return;
    }

    try {
        const res = await fetch(`../api_public.php?action=get_areas&unidad_id=${unidadId}`);
        const data = await res.json();
        if (data.success) {
            areaSelect.innerHTML = '<option value="">-- Seleccione un área --</option>';
            data.areas.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.nombre_area;
                if (selectedAreaId && a.id == selectedAreaId) opt.selected = true;
                areaSelect.appendChild(opt);
            });
        }
    } catch (e) {
        areaSelect.innerHTML = '<option value="">Error al cargar áreas</option>';
    }
}

async function guardarEdicionTicket() {
    const btn = document.getElementById('btnGuardarEdicionTicket');
    const form = document.getElementById('formEditarTicket');
    if (!form) return;

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const fd = new FormData(form);
    const inpFecha = document.getElementById('editFechaRecepcion').value;
    if (inpFecha) {
        const dt = new Date(inpFecha);
        if (!isNaN(dt)) {
            const tzo = dt.getTimezoneOffset() * 60000;
            const mySqlDate = new Date(dt.getTime() - tzo).toISOString().slice(0, 19).replace('T', ' ');
            fd.set('fecha_recepcion', mySqlDate);
        }
    }

    btn.disabled = true;
    btn.innerHTML = 'Guardando...';

    try {
        const res = await fetch('api.php?action=edit_ticket_details', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            alert('Ticket actualizado exitosamente.');
            closeModal('modalEditarTicket');
            location.reload();
        } else {
            alert('Error: ' + data.error);
            btn.disabled = false;
            btn.innerHTML = '💾 Guardar Cambios';
        }
    } catch (e) {
        alert('Error al comunicar con el servidor.');
        btn.disabled = false;
        btn.innerHTML = '💾 Guardar Cambios';
    }
}

function verFotos(fase) {
    const contenedor = document.getElementById('visorContenedor');
    const titulo = document.getElementById('visorTitulo');
    contenedor.innerHTML = '';
    
    let f1, f2, label;
    if (fase === 'antes') {
        f1 = window.fotosTicketActual.antes1;
        f2 = window.fotosTicketActual.antes2;
        label = 'ANTES';
        titulo.textContent = 'Evidencia Fotográfica: ANTES del Servicio';
    } else {
        f1 = window.fotosTicketActual.despues1;
        f2 = window.fotosTicketActual.despues2;
        label = 'DESPUÉS';
        titulo.textContent = 'Evidencia Fotográfica: DESPUÉS del Servicio';
    }

    if (f1) {
        contenedor.innerHTML += `
            <div>
                <div style="font-size: 0.85rem; color: #9CA3AF; margin-bottom: 0.5rem;">Foto ${label} #1</div>
                <img src="../${f1}" style="width:100%; max-height:60vh; object-fit:contain; border-radius:8px; border:1px solid #333; background:#121212;">
            </div>
        `;
    }
    if (f2) {
        contenedor.innerHTML += `
            <div>
                <div style="font-size: 0.85rem; color: #9CA3AF; margin-bottom: 0.5rem;">Foto ${label} #2</div>
                <img src="../${f2}" style="width:100%; max-height:60vh; object-fit:contain; border-radius:8px; border:1px solid #333; background:#121212;">
            </div>
        `;
    }

    if ((f1 && !f2) || (!f1 && f2)) {
        contenedor.className = 'grid grid-cols-1';
    } else {
        contenedor.className = 'grid grid-cols-2';
    }

    openModal('modalVisorFotos');
}
