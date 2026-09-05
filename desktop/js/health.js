/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

(function initLocalHomeConnectHealthModal() {
    var root = document.getElementById('div_healthLocalHomeConnect');
    if (!root) {
        return;
    }

    var activeView = 'equipment';
    var searchInput = root.querySelector('#localhomeconnectHealthSearch');
    var clearButton = root.querySelector('[data-health-clear]');
    var resultCount = root.querySelector('[data-health-result-count]');

    function normalizeText(value) {
        var normalized = String(value || '').toLocaleLowerCase('fr');
        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return normalized;
    }

    function reopenHealth() {
        jeeDialog.dialog({
            id: 'md_localhomeconnect_health',
            title: '{{Santé LocalHomeConnect}}',
            contentUrl: 'index.php?v=d&plugin=localhomeconnect&modal=health'
        });
    }

    function filterActiveView() {
        var panel = root.querySelector('[data-health-panel="' + activeView + '"]');
        if (!panel) {
            return;
        }

        var query = normalizeText(searchInput ? searchInput.value.trim() : '');
        var items = Array.from(panel.querySelectorAll('[data-health-item]'));
        var visibleItems = 0;
        items.forEach(function(item) {
            var matches = query === '' || normalizeText(item.dataset.searchValue).includes(query);
            item.hidden = !matches;
            if (matches) {
                visibleItems++;
            }
        });

        var noResult = panel.querySelector('[data-health-no-result]');
        if (noResult) {
            noResult.hidden = query === '' || visibleItems !== 0;
        }
        if (resultCount) {
            resultCount.textContent = visibleItems + ' / ' + items.length + ' {{affiché(s)}}';
        }
        if (clearButton) {
            clearButton.hidden = query === '';
        }
    }

    function activateView(viewName, focusTab) {
        activeView = viewName;
        root.querySelectorAll('[data-health-view]').forEach(function(button) {
            var isActive = button.dataset.healthView === viewName;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
            if (isActive && focusTab) {
                button.focus();
            }
        });
        root.querySelectorAll('[data-health-panel]').forEach(function(panel) {
            var isActive = panel.dataset.healthPanel === viewName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        if (searchInput) {
            searchInput.value = '';
            searchInput.placeholder = viewName === 'equipment'
                ? '{{Rechercher un équipement}}'
                : '{{Rechercher un contrôle}}';
        }
        filterActiveView();
    }

    function sortEquipment(button) {
        var table = button.closest('table');
        var body = table && table.tBodies ? table.tBodies[0] : null;
        if (!body) {
            return;
        }

        var sortKey = button.dataset.healthSort;
        var sortType = button.dataset.sortType || 'text';
        var ascending = button.dataset.direction !== 'asc';
        var rows = Array.from(body.querySelectorAll('tr[data-health-item]'));
        rows.sort(function(left, right) {
            var leftValue = left.dataset['sort' + sortKey.charAt(0).toUpperCase() + sortKey.slice(1)] || '';
            var rightValue = right.dataset['sort' + sortKey.charAt(0).toUpperCase() + sortKey.slice(1)] || '';
            var comparison;
            if (sortType === 'number') {
                comparison = (Number.parseFloat(leftValue) || 0) - (Number.parseFloat(rightValue) || 0);
            } else {
                comparison = leftValue.localeCompare(rightValue, 'fr', {numeric: true, sensitivity: 'base'});
            }
            return ascending ? comparison : -comparison;
        });
        rows.forEach(function(row) {
            body.appendChild(row);
        });

        table.querySelectorAll('[data-health-sort]').forEach(function(otherButton) {
            var header = otherButton.closest('th');
            var icon = otherButton.querySelector('i');
            if (otherButton === button) {
                otherButton.dataset.direction = ascending ? 'asc' : 'desc';
                if (header) {
                    header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                }
                if (icon) {
                    icon.className = ascending ? 'fas fa-sort-up' : 'fas fa-sort-down';
                }
            } else {
                delete otherButton.dataset.direction;
                if (header) {
                    header.setAttribute('aria-sort', 'none');
                }
                if (icon) {
                    icon.className = 'fas fa-sort';
                }
            }
        });
        filterActiveView();
    }

    var refreshButton = root.querySelector('#bt_refreshHealthLocalHomeConnect');
    if (refreshButton) {
        refreshButton.addEventListener('click', reopenHealth);
    }

    var tabs = Array.from(root.querySelectorAll('[data-health-view]'));
    tabs.forEach(function(button, index) {
        button.addEventListener('click', function() {
            activateView(button.dataset.healthView, false);
        });
        button.addEventListener('keydown', function(event) {
            var nextIndex = null;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            }
            if (nextIndex !== null) {
                event.preventDefault();
                activateView(tabs[nextIndex].dataset.healthView, true);
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', filterActiveView);
    }
    if (clearButton) {
        clearButton.addEventListener('click', function() {
            if (!searchInput) {
                return;
            }
            searchInput.value = '';
            searchInput.focus();
            filterActiveView();
        });
    }

    root.querySelectorAll('[data-health-sort]').forEach(function(button) {
        button.addEventListener('click', function() {
            sortEquipment(button);
        });
    });

  root.addEventListener("click", function (event) {
    const button = event.target.closest(".bt_testHealthLocalHomeConnect");
    if (!button) return;
    button.disabled = true;
    button.querySelector("i")?.classList.add("fa-spin");
    domUtils.ajax({
      type: "POST", url: "plugins/localhomeconnect/core/ajax/localhomeconnect.ajax.php", dataType: "json",
      data: { action: "testCommunication", id: button.getAttribute("data-eqlogic_id") },
      error: function (request, status, error) { button.disabled = false; button.querySelector("i")?.classList.remove("fa-spin"); handleAjaxError(request, status, error); },
      success: function (response) {
        if (response.state !== "ok") { button.disabled = false; button.querySelector("i")?.classList.remove("fa-spin"); jeedomUtils.showAlert({ message: response.result, level: "danger" }); return; }
        jeedomUtils.showAlert({ message: "{{Communication locale réussie}}", level: "success" });
        reopenHealth();
      },
    });
  });

    filterActiveView();
})();
