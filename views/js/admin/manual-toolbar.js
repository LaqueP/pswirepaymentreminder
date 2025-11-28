/* pswirepaymentreminder – admin manual toolbar */
(function () {
    function findListForm(cfg) {
        var forms = document.querySelectorAll("form");
        for (var i = 0; i < forms.length; i++) {
            if (
                forms[i].querySelector(
                    "input[name='ordersBox[]'], input[name='" + cfg.listId + "Box[]']"
                )
            ) {
                return forms[i];
            }
        }
        return null;
    }

    function getCheckedCount(form, cfg) {
        if (!form) return 0;
        return form.querySelectorAll(
            "input[name='ordersBox[]']:checked, input[name='" +
            cfg.listId +
            "Box[]']:checked"
        ).length;
    }

    function submitBulk(form, cfg) {
        if (!form) {
            alert(cfg.labels.formNotFound);
            return;
        }
        if (getCheckedCount(form, cfg) === 0) {
            alert(cfg.labels.noSel);
            return;
        }
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = cfg.submitName; // submitBulkpswpr_sendreminderorders
        input.value = "1";
        form.appendChild(input);
        form.method = form.method || "post";
        form.submit();
    }

    function injectToolbarBtn(form, cfg) {
        // Panel de acciones nativo (iconos a la derecha del título del panel)
        var slot = document.querySelector(
            ".bootstrap .panel .panel-heading .panel-heading-action"
        );
        if (slot && !document.getElementById("pswpr-send-top")) {
            var a = document.createElement("a");
            a.href = "#";
            a.id = "pswpr-send-top";
            a.className = "list-toolbar-btn";
            a.innerHTML =
                '<span class="label-tooltip" data-toggle="tooltip" title="' +
                cfg.labels.send +
                '"><i class="process-icon-envelope"></i></span>';

            // Insertar como primer hijo → queda a la IZQUIERDA de los iconos existentes
            slot.insertBefore(a, slot.firstChild);

            if (window.jQuery && jQuery.fn.tooltip)
                jQuery(a).find(".label-tooltip").tooltip();

            a.addEventListener("click", function (e) {
                e.preventDefault();
                submitBulk(form, cfg);
            });
            return true;
        }
        return false;
    }

    function fallbackInlineBtn(form, cfg) {
        if (!form || document.getElementById("pswpr-send-top-inline")) return;
        var wrap = document.createElement("div");
        wrap.id = "pswpr-send-top-inline";
        wrap.style.textAlign = "right";
        wrap.style.margin = "8px 0 10px";
        wrap.innerHTML =
            '<a href="#" class="list-toolbar-btn" id="pswpr-send-top-inline-a">' +
            '<span class="label-tooltip" data-toggle="tooltip" title="' +
            cfg.labels.send +
            '"><i class="process-icon-envelope"></i></span></a>';

        form.parentNode.insertBefore(wrap, form);

        var a = document.getElementById("pswpr-send-top-inline-a");
        if (window.jQuery && jQuery.fn.tooltip)
            jQuery(a).find(".label-tooltip").tooltip();
        a.addEventListener("click", function (e) {
            e.preventDefault();
            submitBulk(form, cfg);
        });
    }

    function init() {
        var cfg = window.pswprManual || {};
        if (!cfg.listId || !cfg.table || !cfg.submitName || !cfg.labels) return;

        var form = findListForm(cfg);
        if (!injectToolbarBtn(form, cfg)) {
            fallbackInlineBtn(form, cfg);
        }

        // Si tu tema pinta un botón en el header, lo “enganchamos” para que haga POST en lugar de GET
        var headerCandidates = [
            "#page-header-desc-" + (cfg.controllerName || "") + "-pswpr_send_btn",
            ".page-head .page-head-actions a.process-icon-envelope",
            ".page-head .toolbarBox a.process-icon-envelope",
        ];
        for (var i = 0; i < headerCandidates.length; i++) {
            var el = document.querySelector(headerCandidates[i]);
            if (el) {
                el.addEventListener("click", function (e) {
                    e.preventDefault();
                    submitBulk(form, cfg);
                });
                break;
            }
        }
    }

    if (document.readyState !== "loading") init();
    else document.addEventListener("DOMContentLoaded", init);
})();
