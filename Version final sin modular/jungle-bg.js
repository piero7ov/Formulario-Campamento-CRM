// ======================================================
//  Fondo selva “aventura” en Canvas (reutilizable)
//  - Capa estática (selva + camino + hojas)
//  - Luciérnagas animadas
//  - Repulsión con mouse/touch (se apartan del puntero)
// ======================================================

(() => {
    const canvas = document.getElementById("jungle-bg");
    if (!canvas) return;

    const ctx = canvas.getContext("2d", { alpha: true });

    // ====== AJUSTES RÁPIDOS ======
    const FIREFLIES = 100;      // <-- AQUÍ cambias la cantidad
    const REPEL_RADIUS = 140;  // radio de empuje (px)
    const REPEL_FORCE = 0.0022; // fuerza base del empuje
    // ============================

    // Capa estática para no redibujar todo cada frame
    const staticLayer = document.createElement("canvas");
    const sctx = staticLayer.getContext("2d");

    // Partículas tipo luciérnagas
    const fireflies = [];

    // Puntero (mouse/touch) para apartar luciérnagas
    const pointer = { x: 0, y: 0 };
    let lastPointerAt = 0;

    let W = 0, H = 0, DPR = 1;
    let t0 = performance.now();

    function rand(min, max) {
        return min + Math.random() * (max - min);
    }

    function clamp(v, a, b) {
        return Math.max(a, Math.min(b, v));
    }

    function updatePointer(e) {
        pointer.x = e.clientX;
        pointer.y = e.clientY;
        lastPointerAt = performance.now();
    }

    function setSize() {
        W = window.innerWidth;
        H = window.innerHeight;
        DPR = clamp(window.devicePixelRatio || 1, 1, 2);

        // Canvas principal
        canvas.width = Math.floor(W * DPR);
        canvas.height = Math.floor(H * DPR);
        canvas.style.width = W + "px";
        canvas.style.height = H + "px";
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

        // Capa estática
        staticLayer.width = Math.floor(W * DPR);
        staticLayer.height = Math.floor(H * DPR);
        sctx.setTransform(DPR, 0, 0, DPR, 0, 0);

        buildStatic();
        seedFireflies();
    }

    function buildStatic() {
        sctx.clearRect(0, 0, W, H);

        // 1) Fondo base (gradiente selva)
        const sky = sctx.createLinearGradient(0, 0, 0, H);
        sky.addColorStop(0.00, "#0f6b4a");
        sky.addColorStop(0.50, "#0b3d2e");
        sky.addColorStop(1.00, "#06261c");
        sctx.fillStyle = sky;
        sctx.fillRect(0, 0, W, H);

        // 2) “Rayo de sol” suave
        const sunX = W * 0.18;
        const sunY = H * 0.14;
        const sun = sctx.createRadialGradient(sunX, sunY, 10, sunX, sunY, Math.max(W, H) * 0.55);
        sun.addColorStop(0.00, "rgba(255, 255, 210, 0.20)");
        sun.addColorStop(0.25, "rgba(255, 240, 170, 0.08)");
        sun.addColorStop(1.00, "rgba(0, 0, 0, 0)");
        sctx.fillStyle = sun;
        sctx.fillRect(0, 0, W, H);

        // 3) Niebla ligera
        for (let i = 0; i < 4; i++) {
            const y = H * rand(0.15, 0.85);
            const fog = sctx.createRadialGradient(W * rand(0.1, 0.9), y, 0, W * rand(0.1, 0.9), y, W * rand(0.25, 0.45));
            fog.addColorStop(0, "rgba(255,255,255,0.06)");
            fog.addColorStop(1, "rgba(255,255,255,0)");
            sctx.fillStyle = fog;
            sctx.fillRect(0, 0, W, H);
        }

        // 4) Colinas y palmeras lejanas
        drawHills();

        // 5) Camino/clarito central
        drawPath();

        // 6) Vegetación (canopy, lianas, hojas)
        drawCanopy();
        drawVines();
        drawForegroundLeaves();

        // 7) Viñeta suave
        const vign = sctx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.15, W / 2, H / 2, Math.max(W, H) * 0.75);
        vign.addColorStop(0.0, "rgba(0,0,0,0)");
        vign.addColorStop(1.0, "rgba(0,0,0,0.32)");
        sctx.fillStyle = vign;
        sctx.fillRect(0, 0, W, H);

        // 8) Grano
        addGrain(0.06);
    }

    function drawHills() {
        sctx.save();
        sctx.globalAlpha = 0.65;

        // Capa 1
        sctx.fillStyle = "#083022";
        sctx.beginPath();
        sctx.moveTo(0, H * 0.62);
        sctx.bezierCurveTo(W * 0.20, H * 0.54, W * 0.35, H * 0.70, W * 0.55, H * 0.60);
        sctx.bezierCurveTo(W * 0.72, H * 0.52, W * 0.86, H * 0.70, W, H * 0.62);
        sctx.lineTo(W, H);
        sctx.lineTo(0, H);
        sctx.closePath();
        sctx.fill();

        // Capa 2
        sctx.globalAlpha = 0.75;
        sctx.fillStyle = "#06261c";
        sctx.beginPath();
        sctx.moveTo(0, H * 0.74);
        sctx.bezierCurveTo(W * 0.22, H * 0.66, W * 0.42, H * 0.86, W * 0.62, H * 0.72);
        sctx.bezierCurveTo(W * 0.78, H * 0.62, W * 0.90, H * 0.86, W, H * 0.74);
        sctx.lineTo(W, H);
        sctx.lineTo(0, H);
        sctx.closePath();
        sctx.fill();

        sctx.restore();

        // Palmeras
        for (let i = 0; i < 9; i++) {
            const x = W * (i / 8);
            const y = H * rand(0.54, 0.72);
            drawPalmSilhouette(x + rand(-40, 40), y, rand(35, 70), 0.22);
        }
    }

    function drawPalmSilhouette(x, y, size, alpha) {
        sctx.save();
        sctx.globalAlpha = alpha;
        sctx.fillStyle = "#041c14";

        // Tronco
        sctx.beginPath();
        sctx.moveTo(x, y);
        sctx.quadraticCurveTo(x + size * 0.08, y + size * 0.55, x + size * 0.12, y + size * 1.1);
        sctx.quadraticCurveTo(x + size * 0.02, y + size * 1.12, x - size * 0.04, y + size * 1.1);
        sctx.quadraticCurveTo(x - size * 0.02, y + size * 0.55, x, y);
        sctx.closePath();
        sctx.fill();

        // Hojas
        const fronds = 7;
        for (let i = 0; i < fronds; i++) {
            const a = (-Math.PI * 0.75) + (i * (Math.PI * 1.05 / (fronds - 1)));
            const len = size * rand(0.75, 1.05);
            sctx.beginPath();
            sctx.moveTo(x, y);
            sctx.quadraticCurveTo(x + Math.cos(a) * len * 0.55, y + Math.sin(a) * len * 0.55, x + Math.cos(a) * len, y + Math.sin(a) * len);
            sctx.quadraticCurveTo(x + Math.cos(a) * len * 0.85, y + Math.sin(a) * len * 0.78, x, y);
            sctx.closePath();
            sctx.fill();
        }

        sctx.restore();
    }

    function drawPath() {
        const path = sctx.createRadialGradient(W * 0.52, H * 0.78, 20, W * 0.52, H * 0.78, Math.min(W, H) * 0.60);
        path.addColorStop(0.00, "rgba(217,197,155,0.26)");
        path.addColorStop(0.35, "rgba(217,197,155,0.10)");
        path.addColorStop(1.00, "rgba(217,197,155,0)");
        sctx.fillStyle = path;
        sctx.fillRect(0, 0, W, H);

        // Huellitas sutiles
        sctx.save();
        sctx.globalAlpha = 0.08;
        sctx.fillStyle = "#f2e6c8";
        const steps = 12;
        for (let i = 0; i < steps; i++) {
            const px = W * 0.50 + Math.sin(i * 0.7) * W * 0.03;
            const py = H * 0.92 - i * (H * 0.05);
            drawFootprint(px, py, 10 + i * 0.2, i % 2 === 0 ? -0.25 : 0.25);
        }
        sctx.restore();
    }

    function drawFootprint(x, y, s, rot) {
        sctx.save();
        sctx.translate(x, y);
        sctx.rotate(rot);

        sctx.beginPath();
        sctx.ellipse(0, 0, s * 0.45, s * 0.30, 0, 0, Math.PI * 2);
        sctx.fill();

        sctx.beginPath();
        sctx.ellipse(0, -s * 0.55, s * 0.38, s * 0.55, 0, 0, Math.PI * 2);
        sctx.fill();

        for (let i = 0; i < 4; i++) {
            sctx.beginPath();
            sctx.ellipse(-s * 0.25 + i * (s * 0.18), -s * 1.10, s * 0.12, s * 0.12, 0, 0, Math.PI * 2);
            sctx.fill();
        }

        sctx.restore();
    }

    function drawCanopy() {
        sctx.save();
        sctx.globalAlpha = 0.90;
        sctx.fillStyle = "#041c14";
        sctx.beginPath();
        sctx.moveTo(0, 0);
        sctx.lineTo(W, 0);
        sctx.lineTo(W, H * 0.12);
        sctx.bezierCurveTo(W * 0.80, H * 0.08, W * 0.68, H * 0.18, W * 0.50, H * 0.12);
        sctx.bezierCurveTo(W * 0.33, H * 0.06, W * 0.20, H * 0.18, 0, H * 0.12);
        sctx.closePath();
        sctx.fill();
        sctx.restore();
    }

    function drawVines() {
        sctx.save();
        sctx.strokeStyle = "rgba(0,0,0,0.25)";
        sctx.lineWidth = 3;
        sctx.lineCap = "round";

        const vines = 7;
        for (let i = 0; i < vines; i++) {
            const x = W * (i / (vines - 1));
            const top = -20;
            const len = H * rand(0.45, 0.78);
            const sway = W * rand(0.02, 0.06);

            sctx.beginPath();
            sctx.moveTo(x, top);
            sctx.quadraticCurveTo(x + sway, len * 0.33, x - sway * 0.6, len);
            sctx.stroke();

            // Hojitas en la liana
            const leaves = Math.floor(rand(5, 10));
            for (let j = 0; j < leaves; j++) {
                const p = j / (leaves - 1);
                const lx = x + Math.sin(p * 3.2) * sway * 0.75;
                const ly = top + p * len;
                drawLeaf(sctx, lx, ly, rand(14, 26), rand(-1.2, 1.2), "rgba(15,107,74,0.65)");
            }
        }

        sctx.restore();
    }

    function drawForegroundLeaves() {
        const colors = ["rgba(15,107,74,0.85)", "rgba(11,61,46,0.85)", "rgba(6,38,28,0.85)"];

        for (let i = 0; i < 28; i++) {
            const side = Math.random() < 0.5 ? "L" : "R";
            const x = side === "L" ? rand(-40, W * 0.18) : rand(W * 0.82, W + 40);
            const y = rand(H * 0.10, H * 0.95);
            const sz = rand(30, 110);
            const rot = side === "L" ? rand(-0.8, 0.3) : rand(-0.3, 0.8);
            const c = colors[Math.floor(rand(0, colors.length))];
            drawLeaf(sctx, x, y, sz, rot, c);
        }
    }

    function drawLeaf(c, x, y, size, rot, color) {
        c.save();
        c.translate(x, y);
        c.rotate(rot);
        c.fillStyle = color;

        c.beginPath();
        c.moveTo(0, -size * 0.55);
        c.bezierCurveTo(size * 0.65, -size * 0.45, size * 0.75, size * 0.10, 0, size * 0.60);
        c.bezierCurveTo(-size * 0.75, size * 0.10, -size * 0.65, -size * 0.45, 0, -size * 0.55);
        c.closePath();
        c.fill();

        c.globalAlpha *= 0.55;
        c.strokeStyle = "rgba(255,255,255,0.18)";
        c.lineWidth = Math.max(1, size * 0.03);
        c.beginPath();
        c.moveTo(0, -size * 0.45);
        c.quadraticCurveTo(size * 0.08, 0, 0, size * 0.50);
        c.stroke();

        c.restore();
    }

    function addGrain(alpha) {
        const count = Math.floor((W * H) / 9000);
        sctx.save();
        sctx.globalAlpha = alpha;
        sctx.fillStyle = "rgba(0,0,0,0.35)";
        for (let i = 0; i < count; i++) {
            const x = Math.random() * W;
            const y = Math.random() * H;
            sctx.fillRect(x, y, 1, 1);
        }
        sctx.restore();
    }

    function seedFireflies() {
        fireflies.length = 0;
        for (let i = 0; i < FIREFLIES; i++) {
            fireflies.push({
                x: rand(0, W),
                y: rand(H * 0.15, H * 0.92),
                vx: rand(-0.18, 0.18),
                vy: rand(-0.06, 0.06),
                r: rand(1.2, 2.8),
                phase: rand(0, Math.PI * 2),
                speed: rand(0.7, 1.6)
            });
        }
    }

    function drawFireflies(dt, now) {
        const pointerActive = (now - lastPointerAt) < 1200;

        ctx.save();

        for (const f of fireflies) {
            // Repulsión del puntero
            if (pointerActive) {
                const dx = f.x - pointer.x;
                const dy = f.y - pointer.y;
                const dist = Math.hypot(dx, dy);

                if (dist > 0.0001 && dist < REPEL_RADIUS) {
                    const push = (1 - dist / REPEL_RADIUS);
                    const ax = (dx / dist) * REPEL_FORCE * push;
                    const ay = (dy / dist) * REPEL_FORCE * push;

                    f.vx += ax * dt;
                    f.vy += ay * dt;

                    const vmax = 0.65;
                    f.vx = clamp(f.vx, -vmax, vmax);
                    f.vy = clamp(f.vy, -vmax, vmax);
                }
            }

            // Fricción suave
            f.vx *= 0.995;
            f.vy *= 0.995;

            // Movimiento base
            f.phase += dt * 0.002 * f.speed;
            f.x += f.vx * dt;
            f.y += f.vy * dt + Math.sin(f.phase) * 0.02 * dt;

            // Wrap suave (vuelven a entrar por el otro lado)
            if (f.x < -20) f.x = W + 20;
            if (f.x > W + 20) f.x = -20;
            if (f.y < H * 0.10) f.y = H * 0.90;
            if (f.y > H * 0.95) f.y = H * 0.12;

            const glow = 0.18 + 0.22 * (0.5 + 0.5 * Math.sin(f.phase));

            const g = ctx.createRadialGradient(f.x, f.y, 0, f.x, f.y, f.r * 10);
            g.addColorStop(0, `rgba(255, 245, 170, ${glow})`);
            g.addColorStop(0.4, `rgba(255, 245, 170, ${glow * 0.35})`);
            g.addColorStop(1, "rgba(255, 245, 170, 0)");

            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.arc(f.x, f.y, f.r * 10, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = `rgba(255, 250, 210, ${glow * 1.2})`;
            ctx.beginPath();
            ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.restore();
    }

    function frame(now) {
        const dt = now - t0;
        t0 = now;

        // 1) Capa estática
        ctx.clearRect(0, 0, W, H);
        ctx.drawImage(staticLayer, 0, 0, W, H);

        // 2) Animación luciérnagas
        drawFireflies(dt, now);

        requestAnimationFrame(frame);
    }

    // Init
    setSize();
    window.addEventListener("resize", setSize);
    window.addEventListener("pointermove", updatePointer, { passive: true });
    window.addEventListener("pointerdown", updatePointer, { passive: true });
    window.addEventListener("blur", () => { lastPointerAt = 0; });

    requestAnimationFrame((n) => {
        t0 = n;
        requestAnimationFrame(frame);
    });
})();
