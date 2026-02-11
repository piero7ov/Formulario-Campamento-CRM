(() => {
    // ========= Modal imágenes =========
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

        // Delegación: cualquier .js-thumb abre modal
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

    // ========= Sorting en LISTADO (solo si hay THs ordenables) =========
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

                let nextDir = "asc";
                if (curCol === col) nextDir = (curDir === "asc") ? "desc" : "asc";

                sp.set("sort", col);
                sp.set("dir", nextDir);

                // asegurar vista listado (por si venías de informe)
                sp.delete("view");
                sp.delete("refresh");
                sp.delete("page"); // si alguien lo pone a mano
                url.hash = "";
                window.location.href = url.toString();
            });
        });
    }
})();
