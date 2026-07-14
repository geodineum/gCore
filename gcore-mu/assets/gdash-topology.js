/**
 * gdash-topology.js — 3D "Constellation cone" for the Geodineum Dashboard.
 *
 * Reads window.GDASH_TOPOLOGY = { nodes:[{id,label,role,type,tier,sub}], edges:[{from,to}] }
 * injected by DashboardAdmin::renderServicesListPage(). Renders a rotatable
 * cone: the core trio (ValKey / gMath / gNode) at the apex, registered
 * components fanning out on the ring below, every component edge converging up
 * to gNode — because nothing talks directly; everything flows through the hub.
 *
 * Depends on THREE (r128, core only — no OrbitControls, so drag-rotate is
 * implemented here). Degrades to nothing if THREE or the data is absent.
 */
(function () {
    'use strict';

    function boot() {
        var host = document.getElementById('gdash-topo');
        var data = window.GDASH_TOPOLOGY;
        if (!host || typeof THREE === 'undefined' || !data || !data.nodes || !data.nodes.length) {
            if (host) { host.classList.add('gdash-topo-fallback'); }
            return;
        }

        var W = host.clientWidth || 900;
        var H = host.clientHeight || 460;

        // --- palette (gdash gold/dark brand) ---
        var COLOR = {
            gnode: 0xf4d27e,   // gold — the apex / Sun
            valkey: 0xd64541,  // red  — datastore
            gmath: 0x39b36a,   // green — compute
            component: 0x4a9eea, // blue — services
            edge: 0x6b7688,
            edgeCore: 0xf4d27e
        };
        function nodeColor(n) {
            if (n.id === 'gnode') { return COLOR.gnode; }
            if (n.id === 'valkey') { return COLOR.valkey; }
            if (n.id === 'gmath') { return COLOR.gmath; }
            return COLOR.component;
        }

        // --- layout: apex trio on top, components on a wider ring below ---
        var APEX_Y = 82, COMP_Y = -34, APEX_SPREAD = 42, COMP_R = 104;
        var pos = {};
        var core = data.nodes.filter(function (n) { return n.role === 'core'; });
        var comps = data.nodes.filter(function (n) { return n.role !== 'core'; });

        // gNode centred at the true apex; ValKey/gMath flank it.
        var coreOrder = ['valkey', 'gnode', 'gmath'];
        var placedCore = core.slice().sort(function (a, b) {
            return coreOrder.indexOf(a.id) - coreOrder.indexOf(b.id);
        });
        placedCore.forEach(function (n, i) {
            var x = (i - (placedCore.length - 1) / 2) * APEX_SPREAD;
            var y = n.id === 'gnode' ? APEX_Y + 14 : APEX_Y;   // gNode slightly higher
            pos[n.id] = new THREE.Vector3(x, y, 0);
        });
        // components evenly around the base ring
        comps.forEach(function (n, i) {
            var a = (i / Math.max(1, comps.length)) * Math.PI * 2;
            pos[n.id] = new THREE.Vector3(Math.cos(a) * COMP_R, COMP_Y, Math.sin(a) * COMP_R);
        });

        // --- live activity (drives edge flow + apex pulse) ---
        var activity = data.activity || { total: 0 };
        var actNorm = Math.min(1, Math.log(1 + (activity.total || 0)) / Math.log(10000));
        var pulseSpeed = 0.0035 + actNorm * 0.02;
        var pulsesPerEdge = (activity.total || 0) > 0 ? 2 : 1;

        // --- three.js scaffold ---
        var scene = new THREE.Scene();
        var camera = new THREE.PerspectiveCamera(46, W / H, 1, 2000);
        camera.position.set(0, 40, 300);
        camera.lookAt(0, 10, 0);

        var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setPixelRatio(window.devicePixelRatio || 1);
        renderer.setSize(W, H);
        host.appendChild(renderer.domElement);

        var group = new THREE.Group();
        scene.add(group);

        // edges first (so nodes draw on top)
        (data.edges || []).forEach(function (e) {
            var a = pos[e.from], b = pos[e.to];
            if (!a || !b) { return; }
            var isCore = (e.from === 'gnode' || e.to === 'gnode') &&
                         (['valkey', 'gmath', 'gnode'].indexOf(e.from) >= 0 &&
                          ['valkey', 'gmath', 'gnode'].indexOf(e.to) >= 0);
            var geom = new THREE.BufferGeometry().setFromPoints([a, b]);
            var mat = new THREE.LineBasicMaterial({
                color: isCore ? COLOR.edgeCore : COLOR.edge,
                transparent: true,
                opacity: isCore ? 0.55 : 0.28
            });
            group.add(new THREE.Line(geom, mat));
        });

        // flow pulses travelling each edge toward gNode (the hub); density and
        // speed scale with live FCALL activity.
        var pulses = [];
        (data.edges || []).forEach(function (e) {
            var a = pos[e.from], b = pos[e.to];
            if (!a || !b) { return; }
            for (var k = 0; k < pulsesPerEdge; k++) {
                var pm = new THREE.Mesh(
                    new THREE.SphereGeometry(1.7, 8, 8),
                    new THREE.MeshBasicMaterial({ color: 0xf4d27e, transparent: true, opacity: 0.85 })
                );
                group.add(pm);
                pulses.push({ mesh: pm, a: a, b: b, t: k / pulsesPerEdge });
            }
        });

        // nodes + HTML labels
        var apexHalo = null;
        var labelLayer = document.createElement('div');
        labelLayer.className = 'gdash-topo-labels';
        host.appendChild(labelLayer);

        var meshes = [];
        data.nodes.forEach(function (n) {
            var p = pos[n.id];
            if (!p) { return; }
            var r = n.id === 'gnode' ? 13 : (n.role === 'core' ? 9 : 7);
            var mesh = new THREE.Mesh(
                new THREE.SphereGeometry(r, 28, 28),
                new THREE.MeshBasicMaterial({ color: nodeColor(n) })
            );
            mesh.position.copy(p);
            group.add(mesh);

            // faint halo for the apex
            if (n.role === 'core') {
                var halo = new THREE.Mesh(
                    new THREE.SphereGeometry(r * 1.7, 20, 20),
                    new THREE.MeshBasicMaterial({ color: nodeColor(n), transparent: true, opacity: 0.12 })
                );
                halo.position.copy(p);
                group.add(halo);
                if (n.id === 'gnode') { apexHalo = halo; }
            }

            var label = document.createElement('div');
            label.className = 'gdash-topo-label' + (n.role === 'core' ? ' is-core' : '');
            label.innerHTML = '<span class="l-name">' + escapeHtml(n.label) + '</span>' +
                (n.sub ? '<span class="l-sub">' + escapeHtml(n.sub) + '</span>' : '');
            labelLayer.appendChild(label);
            meshes.push({ node: n, mesh: mesh, label: label, base: p.clone() });
        });

        // --- drag-to-rotate + gentle auto-spin ---
        var rotY = 0.5, rotX = -0.15, dragging = false, autoSpin = true, lastX = 0, lastY = 0;
        renderer.domElement.style.cursor = 'grab';
        renderer.domElement.addEventListener('pointerdown', function (e) {
            dragging = true; autoSpin = false; lastX = e.clientX; lastY = e.clientY;
            renderer.domElement.style.cursor = 'grabbing';
        });
        window.addEventListener('pointerup', function () {
            dragging = false; renderer.domElement.style.cursor = 'grab';
        });
        window.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            rotY += (e.clientX - lastX) * 0.008;
            rotX += (e.clientY - lastY) * 0.006;
            rotX = Math.max(-0.9, Math.min(0.9, rotX));
            lastX = e.clientX; lastY = e.clientY;
        });

        function onResize() {
            W = host.clientWidth || W; H = host.clientHeight || H;
            camera.aspect = W / H; camera.updateProjectionMatrix();
            renderer.setSize(W, H);
        }
        window.addEventListener('resize', onResize);

        var v = new THREE.Vector3();
        var clock = 0;
        function tick() {
            if (autoSpin) { rotY += 0.0016; }
            group.rotation.y = rotY;
            group.rotation.x = rotX;

            clock += 1;
            // advance flow pulses (component → gNode)
            for (var pi = 0; pi < pulses.length; pi++) {
                var pu = pulses[pi];
                pu.t += pulseSpeed;
                if (pu.t > 1) { pu.t -= 1; }
                pu.mesh.position.lerpVectors(pu.a, pu.b, pu.t);
                pu.mesh.material.opacity = 0.85 * (1 - Math.abs(pu.t - 0.5) * 0.7);
            }
            // apex breathes with activity
            if (apexHalo) {
                apexHalo.scale.setScalar(1 + Math.sin(clock * 0.05) * (0.12 + actNorm * 0.35));
                apexHalo.material.opacity = 0.10 + actNorm * 0.12;
            }

            renderer.render(scene, camera);

            // project node world positions → screen px for the HTML labels
            for (var i = 0; i < meshes.length; i++) {
                var m = meshes[i];
                m.mesh.getWorldPosition(v);
                var depth = v.z;
                v.project(camera);
                var x = (v.x * 0.5 + 0.5) * W;
                var y = (-v.y * 0.5 + 0.5) * H;
                var vis = v.z < 1;
                m.label.style.transform = 'translate(-50%,-140%) translate(' + x + 'px,' + y + 'px)';
                m.label.style.opacity = vis ? (depth > -40 ? '1' : '0.45') : '0';
            }
            requestAnimationFrame(tick);
        }
        tick();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
