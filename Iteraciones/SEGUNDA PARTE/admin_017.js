(() => {
    // =====================================================
    // Modal imágenes:
    // - Al hacer click en una miniatura (js-thumb) se abre modal
    // - ESC o click fuera cierra
    // =====================================================
    const modal = document.getElementById("imgModal");
    if (modal) {
        const modalImg = modal.querySelector("img");

        function openModal(src) {
            modalImg.src = src;
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
        }
        function closeModal() {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
            modalImg.src = "";
        }

        document.addEventListener("click", (e) => {
            const t = e.target;
            if (t && t.classList && t.classList.contains("js-thumb")) {
                e.preventDefault();
                openModal(t.dataset.full || t.src);
            }
        });

        modal.addEventListener("click", (e) => {
            if (e.target === modal || (e.target && e.target.hasAttribute("data-close"))) closeModal();
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape") closeModal();
        });
    }

    // =====================================================
    // Sorting simple en TH:
    // - Click en th.sortable[data-sort-col]
    // - Preserva filtros (porque se quedan en la URL)
    // - Limpia params de view/ops para volver al listado
    // =====================================================
    const ths = document.querySelectorAll("th.sortable[data-sort-col]");
    if (ths.length) {
        ths.forEach(th => {
            th.addEventListener("click", () => {
                const col = th.dataset.sortCol;
                if (!col) return;

                const url = new URL(window.location.href);
                const sp = url.searchParams;

                const curCol = sp.get("sort") || "";
                const curDir = (sp.get("dir") || "asc").toLowerCase();

                // Si clickeas la misma columna: alterna asc/desc
                let nextDir = "asc";
                if (curCol === col) nextDir = (curDir === "asc") ? "desc" : "asc";

                sp.set("sort", col);
                sp.set("dir", nextDir);

                // Volver al listado (si vienes de view u ops)
                sp.delete("view");
                sp.delete("refresh");
                sp.delete("page");
                sp.delete("tab");
                sp.delete("do");
                sp.delete("id");

                url.hash = "";
                window.location.href = url.toString();
            });
        });
    }

    // =====================================================
    // ✅ Confirmación de ELIMINAR:
    // - Captura submit de cualquier form con clase js-delete-form
    // - Lee mensaje desde data-confirm=""
    // - Si cancela => preventDefault()
    // =====================================================
    document.addEventListener("submit", (e) => {
        const form = e.target;
        if (!form || !form.classList || !form.classList.contains("js-delete-form")) return;

        const msg = form.dataset.confirm || "¿Seguro que quieres eliminar este registro?";
        if (!window.confirm(msg)) e.preventDefault();
    });
})();
