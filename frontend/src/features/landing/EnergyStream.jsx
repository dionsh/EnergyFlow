import { useEffect, useRef } from 'react'
import * as THREE from 'three'

const VERTEX_SHADER = `
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`

const FRAGMENT_SHADER = `
uniform float uTime;
uniform float uAspect;
varying vec2 vUv;

float hash(vec2 p) {
  p = fract(p * vec2(123.34, 456.21));
  p += dot(p, p + 45.32);
  return fract(p.x * p.y);
}

void main() {
  vec2 uv = vUv;
  float t = uTime;
  float wave = 0.25 + 0.055 * sin(uv.x * 7.2 - t * 0.72) + 0.022 * sin(uv.x * 17.0 + t * 0.48);
  float dy = uv.y - wave;
  float mainLine = exp(-abs(dy) * 210.0);
  float innerGlow = exp(-abs(dy) * 45.0);
  float ambient = exp(-abs(dy) * 10.0);
  float filaments = 0.0;
  for (int i = 0; i < 5; i++) {
    float fi = float(i);
    float threadOffset = (fi - 2.0) * 0.042;
    float ripple = 0.014 * sin(uv.x * (13.0 + fi * 2.0) - t * (0.55 + fi * 0.08) + fi * 1.7);
    float distanceToThread = abs(uv.y - (wave + threadOffset + ripple));
    filaments += exp(-distanceToThread * (135.0 + fi * 8.0)) * (0.35 - fi * 0.035);
  }

  vec3 emerald = vec3(0.18, 0.95, 0.56);
  vec3 blue = vec3(0.18, 0.58, 1.0);
  float blueMix = smoothstep(0.0, 1.0, uv.x + 0.14 * sin(t * 0.24));
  vec3 energy = mix(emerald, blue, blueMix * 0.32);

  vec2 grid = vec2(uv.x * uAspect * 30.0 - t * 1.1, uv.y * 21.0);
  vec2 cell = floor(grid);
  vec2 local = fract(grid) - 0.5;
  float seed = hash(cell);
  vec2 particleOffset = vec2(hash(cell + 7.1) - 0.5, hash(cell + 19.7) - 0.5) * 0.72;
  float particle = (1.0 - smoothstep(0.035, 0.11, length(local - particleOffset))) * step(0.91, seed);
  float nearWave = exp(-abs(dy) * 4.5);
  float particles = particle * nearWave * (0.35 + 0.65 * sin(t * 2.4 + seed * 20.0));

  float tailFade = smoothstep(0.0, 0.1, uv.x) * smoothstep(0.0, 0.12, 1.0 - uv.x);
  float alpha = tailFade * (ambient * 0.24 + innerGlow * 0.2 + mainLine * 0.42 + filaments * 0.58 + particles * 0.54);
  vec3 color = energy * (ambient * 0.16 + innerGlow * 0.32 + mainLine * 0.72 + filaments * 0.88 + particles) + vec3(0.82, 1.0, 0.94) * mainLine * 0.7;
  gl_FragColor = vec4(color, clamp(alpha, 0.0, 0.88));
}`

export function EnergyStream() {
  const host = useRef(null)

  useEffect(() => {
    const element = host.current
    if (!element) return undefined

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    let renderer
    try {
      renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'low-power' })
    } catch {
      element.dataset.fallback = 'true'
      return undefined
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5))
    renderer.setSize(element.clientWidth, element.clientHeight)
    renderer.setClearColor(0x000000, 0)
    renderer.domElement.className = 'ef-energy-stream-canvas'
    renderer.domElement.setAttribute('aria-hidden', 'true')
    element.appendChild(renderer.domElement)

    const scene = new THREE.Scene()
    const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 10)
    camera.position.z = 1
    const material = new THREE.ShaderMaterial({
      uniforms: {
        uTime: { value: 0 },
        uAspect: { value: Math.max(element.clientWidth / Math.max(element.clientHeight, 1), 0.1) },
      },
      vertexShader: VERTEX_SHADER,
      fragmentShader: FRAGMENT_SHADER,
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    })
    const plane = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), material)
    scene.add(plane)

    let visible = true
    let raf = 0
    let clock = 0
    const render = () => renderer.render(scene, camera)
    const resize = () => {
      const width = Math.max(element.clientWidth, 1)
      const height = Math.max(element.clientHeight, 1)
      renderer.setSize(width, height, false)
      material.uniforms.uAspect.value = width / height
      render()
    }
    const observer = new ResizeObserver(resize)
    observer.observe(element)
    const animate = () => {
      cancelAnimationFrame(raf)
      if (!visible || document.hidden || reducedMotion) return
      const frame = () => {
        raf = requestAnimationFrame(frame)
        clock += 0.012
        material.uniforms.uTime.value = clock
        render()
      }
      raf = requestAnimationFrame(frame)
    }
    const intersection = new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting
      if (visible) animate()
      else cancelAnimationFrame(raf)
    })
    intersection.observe(element)
    const visibility = () => {
      if (document.hidden) cancelAnimationFrame(raf)
      else animate()
    }
    document.addEventListener('visibilitychange', visibility)

    resize()
    if (!reducedMotion) animate()

    return () => {
      cancelAnimationFrame(raf)
      observer.disconnect()
      intersection.disconnect()
      document.removeEventListener('visibilitychange', visibility)
      plane.geometry.dispose()
      material.dispose()
      renderer.dispose()
      renderer.domElement.remove()
    }
  }, [])

  return <div ref={host} className="ef-energy-stream" aria-hidden="true" />
}


