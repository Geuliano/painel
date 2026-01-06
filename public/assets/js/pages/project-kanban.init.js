/**
 * Theme: Approx - Bootstrap 5 Responsive Admin Dashboard
 * Author: Mannatthemes
 * Project Kanban Js
 */

(function () {
    if (typeof dragula === 'undefined') {
        return;
    }

    function updateKanbanStats() {
        var board = document.querySelector('.kanban-board');
        if (!board) return;

        var cols = Array.prototype.slice.call(board.querySelectorAll('.kanban-col'));
        if (!cols.length) return;

        var columnsInfo = [];

        cols.forEach(function (col) {
            var headerTitle = col.querySelector('.my-3 h6.fw-semibold.fs-16');
            var headerCount = col.querySelector('.my-3 h6.fs-13.fw-semibold');
            var body = col.querySelector('.kanban-col > div[id]') || col.querySelector('div[id^="col-"]');

            var cards = body ? body.querySelectorAll('.card') : [];
            var count = cards ? cards.length : 0;

            if (headerCount) {
                headerCount.textContent = count + ' tarefas';
            }

            var titleText = headerTitle ? (headerTitle.textContent || '') : '';
            columnsInfo.push({
                element: col,
                body: body,
                title: titleText.trim().toLowerCase(),
                count: count
            });
        });

        var totalCards = columnsInfo.reduce(function (acc, c) { return acc + c.count; }, 0);
        var percent = 0;

        if (totalCards > 0 && columnsInfo.length) {
            var colCount = columnsInfo.length;
            var sum = 0;

            columnsInfo.forEach(function (c, idx) {
                var w;
                if (colCount === 1) {
                    w = 1;
                } else {
                    w = idx / Math.max(colCount - 1, 1);
                }
                sum += w * c.count;
            });

            percent = Math.round((sum / totalCards) * 100);
            if (percent < 0) percent = 0;
            if (percent > 100) percent = 100;
        }

        var progressContainer = document.getElementById('project-progress');
        if (progressContainer) {
            var bar = progressContainer.querySelector('.progress-bar');
            var label = progressContainer.querySelector('span.fw-semibold');
            if (bar) {
                bar.style.width = percent + '%';
                bar.setAttribute('aria-valuenow', String(percent));
            }
            if (label) {
                label.textContent = percent + '%';
            }
        }
    }

    // expõe para outros scripts (ex: polling no listar.php)
    // exp�e para outros scripts (ex: polling no listar.php)
    window.updateKanbanStats = updateKanbanStats;

    // Drag de LISTAS (colunas) - reordena as colunas no board
    var boardEl = document.querySelector('.kanban-board');
    if (boardEl) {
        var drakeColumns = dragula([boardEl], {
            direction: 'horizontal',
            moves: function (el, source, handle) {
                return handle && handle.closest && handle.closest('.kanban-col-header');
            }
        });

        drakeColumns.on('drop', function () {
            var cols = Array.prototype.slice.call(boardEl.querySelectorAll('.kanban-col'));
            var ordem = cols.map(function (col, idx) {
                var id = parseInt(col.getAttribute('data-col-id') || '0', 10);
                return { id: id, pos: idx };
            }).filter(function (item) { return item.id; });

            if (!ordem.length ||
                typeof window.kanbanControllerUrl === 'undefined' ||
                typeof window.kanbanCsrfToken === 'undefined' ||
                typeof window.kanbanProjectId === 'undefined') {
                return;
            }

            var fdCols = new FormData();
            fdCols.append('action', 'reorder_columns');
            fdCols.append('csrf_token', window.kanbanCsrfToken);
            fdCols.append('projeto', window.kanbanProjectId);
            fdCols.append('ordem', JSON.stringify(ordem));

            fetch(window.kanbanControllerUrl, {
                method: 'POST',
                body: fdCols,
                credentials: 'same-origin'
            }).then(function (resp) {
                return resp.json().catch(function () { return null; });
            }).then(function (data) {
                if (!data || !data.success) {
                    window.location.reload();
                    return;
                }
                try { updateKanbanStats(); } catch (e) {}
            }).catch(function () {
                window.location.reload();
            });
        });
    }

    var columnNodes = Array.prototype.slice.call(
        document.querySelectorAll('.kanban-board .kanban-col > div[id^="col-"]')
    );

    if (!columnNodes.length) {
        return;
    }

    var drake = dragula(columnNodes);

    drake.on('drop', function (el, target, source, sibling) {
        if (!el || !target) return;
        var cardId = el.getAttribute('data-card-id');
        if (!cardId) return;

        try { updateKanbanStats(); } catch (e) {}

        // o id da coluna está no atributo id do container (project-list-* ou col-<id>)
        var targetId = target.getAttribute('id') || '';
        var columnId = null;
        if (targetId.indexOf('col-') === 0) {
            columnId = parseInt(targetId.replace('col-', ''), 10);
        } else {
            // mapeamos ids conhecidos via data-col-id, se existir
            var dataCol = target.getAttribute('data-col-id');
            if (dataCol) {
                columnId = parseInt(dataCol, 10);
            }
        }
        if (!columnId) {
            return;
        }

        // projectId e controllerUrl são definidos em listar.php no escopo global
        if (typeof window.kanbanControllerUrl === 'undefined' ||
            typeof window.kanbanCsrfToken === 'undefined' ||
            typeof window.kanbanProjectId === 'undefined') {
            return;
        }

        var fd = new FormData();
        fd.append('action', 'move_card');
        fd.append('csrf_token', window.kanbanCsrfToken);
        fd.append('card_id', cardId);
        fd.append('column_id', columnId);
        fd.append('projeto', window.kanbanProjectId);

        fetch(window.kanbanControllerUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (resp) {
            return resp.json().catch(function () { return null; });
        }).then(function (data) {
            if (!data || !data.success) {
                // se deu erro, recarrega para não ficar inconsistente
                window.location.reload();
            }
        }).catch(function () {
            window.location.reload();
        });
    });
})();


