/* CEAD — Intro 3D del home (WebGL/Three.js)
 *
 * Las cuatro baldosas de la marca flotan dispersas, se ensamblan en la
 * cuadrícula 2x2 del logo bajo luz direccional, la palabra CEAD emerge al
 * frente (HTML serif, ver intro.php) y la cámara hace zoom a través de la
 * escena hacia el hero. Parallax sutil con el puntero. Skip con click/tecla.
 *
 * three.js se importa dinámicamente: ante cualquier fallo (CDN caído, WebGL
 * roto) se suelta el overlay con un fade y la página sigue normal.
 */

const overlay = document.getElementById('intro-overlay');
const mount   = document.getElementById('intro-3d-mount');
const active  = document.documentElement.classList.contains('cead-intro-3d');

function clearFailsafe() {
  if (window.__ceadIntroFailsafe) {
    clearTimeout(window.__ceadIntroFailsafe);
    window.__ceadIntroFailsafe = null;
  }
}

function finishFast() {
  clearFailsafe();
  if (overlay) { overlay.classList.add('is-done'); }
}

if (active && overlay && mount) {
  run().catch(finishFast);
}

async function run() {
  const THREE = await import('https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js');

  // ---------- Colores de marca (toma los del Customizer si están) ----------
  const css = getComputedStyle(document.documentElement);
  const readColor = (name, fallback) => {
    const v = css.getPropertyValue(name).trim();
    return v || fallback;
  };
  const COLORS = [
    readColor('--brand',      '#E93B3C'), // arriba-izquierda
    readColor('--acc-blue',   '#49A3C8'), // arriba-derecha
    readColor('--acc-yellow', '#EDDF58'), // abajo-izquierda
    readColor('--acc-orange', '#F4B74C'), // abajo-derecha
  ];
  const INK = 0x0b0b0b;

  // ---------- Escena ----------
  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setClearColor(INK, 1);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setSize(window.innerWidth, window.innerHeight);
  mount.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(INK, 10, 18);

  const camera = new THREE.PerspectiveCamera(38, window.innerWidth / window.innerHeight, 0.1, 60);
  camera.position.set(0, 0, 8);

  scene.add(new THREE.AmbientLight(0xffffff, 0.55));
  const key = new THREE.DirectionalLight(0xffffff, 1.1);
  key.position.set(4, 6, 8);
  scene.add(key);
  const rim = new THREE.DirectionalLight(0xf4b74c, 0.35); // contraluz cálida
  rim.position.set(-6, -3, -6);
  scene.add(rim);

  // ---------- Baldosas ----------
  const SIZE = 1.15, DEPTH = 0.34, GAP = 0.14;
  const OFF = (SIZE + GAP) / 2;
  const targets = [
    new THREE.Vector3(-OFF,  OFF, 0),
    new THREE.Vector3( OFF,  OFF, 0),
    new THREE.Vector3(-OFF, -OFF, 0),
    new THREE.Vector3( OFF, -OFF, 0),
  ];

  const group = new THREE.Group();
  scene.add(group);

  const geo = new THREE.BoxGeometry(SIZE, SIZE, DEPTH);
  const tiles = COLORS.map((hex, i) => {
    const mat  = new THREE.MeshStandardMaterial({ color: new THREE.Color(hex), roughness: 0.38, metalness: 0.12 });
    const mesh = new THREE.Mesh(geo, mat);
    // Posición inicial dispersa: anillo amplio con profundidad aleatoria.
    const a = (i / 4) * Math.PI * 2 + 0.7;
    const r = 5.5 + Math.random() * 2.5;
    mesh.position.set(Math.cos(a) * r, Math.sin(a) * r * 0.7, -3 + Math.random() * 4);
    mesh.rotation.set(Math.random() * Math.PI * 2, Math.random() * Math.PI * 2, Math.random() * Math.PI);
    mesh.userData.from     = mesh.position.clone();
    mesh.userData.fromRot  = mesh.rotation.clone();
    mesh.userData.to       = targets[i];
    group.add(mesh);
    return mesh;
  });

  // ---------- Timeline ----------
  const ASSEMBLE_START = 0.25, ASSEMBLE_DUR = 1.5, TILE_DELAY = 0.13;
  const TEXT_AT = 1.75;
  let   exitAt  = 3.05;            // se adelanta con el skip
  const EXIT_DUR = 0.95;

  const easeInOutCubic = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
  const easeOutCubic   = (t) => 1 - Math.pow(1 - t, 3);
  const easeInCubic    = (t) => t * t * t;
  const clamp01        = (t) => Math.min(1, Math.max(0, t));

  // Parallax con el puntero.
  let targetRX = 0, targetRY = 0;
  const onPointer = (e) => {
    const x = (e.clientX || 0) / window.innerWidth  - 0.5;
    const y = (e.clientY || 0) / window.innerHeight - 0.5;
    targetRY = x * 0.28;
    targetRX = y * 0.18;
  };
  window.addEventListener('pointermove', onPointer, { passive: true });

  const onResize = () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
  };
  window.addEventListener('resize', onResize);

  // Skip: adelanta la salida (no corta en seco, mantiene la elegancia).
  let started = performance.now();
  const skip = () => {
    const t = (performance.now() - started) / 1000;
    if (t < exitAt) { exitAt = Math.max(t, 0.2); }
  };
  window.addEventListener('pointerdown', skip, { once: true });
  window.addEventListener('keydown', skip, { once: true });

  clearFailsafe();
  mount.classList.add('is-on');

  let raf = 0, textShown = false, exiting = false, finished = false;

  function cleanup() {
    if (finished) { return; }
    finished = true;
    cancelAnimationFrame(raf);
    window.removeEventListener('pointermove', onPointer);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('pointerdown', skip);
    window.removeEventListener('keydown', skip);
    geo.dispose();
    tiles.forEach((m) => m.material.dispose());
    renderer.dispose();
    if (renderer.domElement.parentNode) { renderer.domElement.parentNode.removeChild(renderer.domElement); }
  }

  function frame(now) {
    const t = (now - started) / 1000;

    // Ensamblaje de baldosas.
    tiles.forEach((m, i) => {
      const k = easeInOutCubic(clamp01((t - ASSEMBLE_START - i * TILE_DELAY) / ASSEMBLE_DUR));
      m.position.lerpVectors(m.userData.from, m.userData.to, k);
      const rk = easeOutCubic(k);
      m.rotation.set(m.userData.fromRot.x * (1 - rk), m.userData.fromRot.y * (1 - rk), m.userData.fromRot.z * (1 - rk));
      // Respiración sutil una vez asentadas.
      if (k === 1 && !exiting) {
        m.position.y = m.userData.to.y + Math.sin(t * 1.6 + i * 1.3) * 0.018;
      }
    });

    // Texto al frente.
    if (!textShown && t >= TEXT_AT) {
      textShown = true;
      overlay.classList.add('is-text');
    }

    // Parallax (se relaja durante la salida).
    const damp = exiting ? 0.02 : 0.06;
    group.rotation.x += (targetRX - group.rotation.x) * damp;
    group.rotation.y += (targetRY - group.rotation.y) * damp;

    // Salida: zoom a través de la escena + fade del overlay (CSS).
    if (t >= exitAt) {
      if (!exiting) {
        exiting = true;
        overlay.classList.add('is-exit');
        overlay.classList.add('is-done');
      }
      const z = easeInCubic(clamp01((t - exitAt) / EXIT_DUR));
      camera.position.z = 8 - z * 5.2;
      camera.fov = 38 + z * 14;
      camera.updateProjectionMatrix();
      if (z === 1) {
        renderer.render(scene, camera);
        cleanup();
        return;
      }
    }

    renderer.render(scene, camera);
    raf = requestAnimationFrame(frame);
  }

  raf = requestAnimationFrame(frame);
}
