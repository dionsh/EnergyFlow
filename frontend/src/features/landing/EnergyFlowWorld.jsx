import { useEffect, useMemo, useRef, useState } from 'react'
import { Canvas, useFrame } from '@react-three/fiber'
import { MeshDistortMaterial, OrbitControls, Sparkles } from '@react-three/drei'
import { Bloom, EffectComposer } from '@react-three/postprocessing'
import * as THREE from 'three'

const AMBER = '#ffb347'
const CYAN = '#5ce1ff'

function PlasmaCore() {
  const group = useRef(null)
  const heart = useRef(null)
  const shell = useRef(null)

  useFrame(({ clock }) => {
    const t = clock.getElapsedTime()
    const pulse = 1 + Math.sin(t * 1.7) * 0.035
    group.current.scale.setScalar(pulse)
    heart.current.rotation.y = t * 0.28
    heart.current.rotation.x = Math.sin(t * 0.35) * 0.12
    shell.current.rotation.x = t * 0.12
    shell.current.rotation.y = -t * 0.17
  })

  return (
    <group ref={group}>
      <mesh ref={heart}>
        <sphereGeometry args={[0.55, 48, 48]} />
        <meshBasicMaterial color={new THREE.Color(2.3, 1.05, 0.34)} toneMapped={false} />
      </mesh>
      <mesh>
        <sphereGeometry args={[0.95, 64, 64]} />
        <MeshDistortMaterial
          color="#1b6fa8"
          emissive={CYAN}
          emissiveIntensity={0.75}
          transparent
          opacity={0.55}
          distort={0.4}
          speed={0.65}
          roughness={0.18}
          metalness={0.15}
          depthWrite={false}
        />
      </mesh>
      <mesh ref={shell}>
        <icosahedronGeometry args={[1.35, 1]} />
        <meshBasicMaterial color={new THREE.Color(0.35, 1.8, 2.3)} wireframe transparent opacity={0.5} toneMapped={false} />
      </mesh>
    </group>
  )
}

function OrbitElectron({ radius, tilt, speed, phase }) {
  const orbit = useRef(null)
  const electron = useRef(null)
  const glow = useRef(null)

  useFrame(({ clock }) => {
    const angle = clock.getElapsedTime() * speed + phase
    const x = Math.cos(angle) * radius
    const y = Math.sin(angle) * radius
    electron.current.position.set(x, y, 0)
    glow.current.position.set(x, y, 0)
    glow.current.material.opacity = 0.16 + (Math.sin(angle * 1.6) + 1) * 0.04
  })

  return (
    <group ref={orbit} rotation={tilt}>
      <mesh>
        <torusGeometry args={[radius, 0.012, 8, 160]} />
        <meshBasicMaterial color={new THREE.Color(0.28, 1.5, 2.1)} transparent opacity={0.62} toneMapped={false} />
      </mesh>
      <mesh ref={glow}>
        <sphereGeometry args={[0.2, 20, 20]} />
        <meshBasicMaterial color={AMBER} transparent opacity={0.2} depthWrite={false} toneMapped={false} />
      </mesh>
      <mesh ref={electron}>
        <sphereGeometry args={[0.09, 24, 24]} />
        <meshBasicMaterial color={new THREE.Color(2.4, 1.2, 0.35)} toneMapped={false} />
      </mesh>
    </group>
  )
}

function randomBolt(index) {
  const direction = new THREE.Vector3(Math.random() * 2 - 1, Math.random() * 2 - 1, Math.random() * 2 - 1).normalize()
  const start = direction.clone().multiplyScalar(0.95)
  const end = direction.clone().multiplyScalar(2.3 + Math.random() * 0.8)
  const axis = new THREE.Vector3().crossVectors(direction, new THREE.Vector3(0, 1, 0))
  if (axis.lengthSq() < 0.01) axis.set(1, 0, 0)
  axis.normalize()
  const side = new THREE.Vector3().crossVectors(direction, axis).normalize()
  const points = []
  for (let step = 0; step <= 14; step += 1) {
    const progress = step / 14
    const point = start.clone().lerp(end, progress)
    const envelope = Math.sin(Math.PI * progress)
    if (step > 0 && step < 14) {
      point.addScaledVector(axis, (Math.random() - 0.5) * 0.62 * envelope)
      point.addScaledVector(side, (Math.random() - 0.5) * 0.62 * envelope)
    }
    points.push(point)
  }
  return { id: index, points, visible: Math.random() > 0.3 }
}

function LightningArcs() {
  const [bolts, setBolts] = useState(() => Array.from({ length: 8 }, (_, i) => randomBolt(i)))
  useEffect(() => {
    const timer = window.setInterval(() => setBolts(Array.from({ length: 8 }, (_, i) => randomBolt(i))), 100)
    return () => window.clearInterval(timer)
  }, [])
  return bolts.map((bolt) => <LightningBolt key={bolt.id} bolt={bolt} />)
}

function LightningBolt({ bolt }) {
  const material = useRef(null)
  const geometry = useMemo(() => new THREE.BufferGeometry().setFromPoints(bolt.points), [bolt.points])
  const electricWhite = useMemo(() => new THREE.Color(1.8, 2.25, 2.6), [])
  useFrame(() => {
    if (material.current) material.current.opacity = 0.5 + Math.random() * 0.5
  })
  useEffect(() => () => geometry.dispose(), [geometry])
  return (
    <line geometry={geometry} visible={bolt.visible}>
      <lineBasicMaterial ref={material} color={electricWhite} transparent opacity={0.8} toneMapped={false} />
    </line>
  )
}

function ReactorScene() {
  return (
    <>
      <color attach="background" args={['#070b18']} />
      <fog attach="fog" args={['#070b18', 9, 18]} />
      <ambientLight intensity={0.15} />
      <pointLight position={[0, 0, 0]} color={CYAN} intensity={18} distance={12} />
      <pointLight position={[0, 0, 0]} color={AMBER} intensity={3.5} distance={5} />

      <PlasmaCore />
      <OrbitElectron radius={2.2} tilt={[0.72, 0.18, 0.08]} speed={0.42} phase={0.2} />
      <OrbitElectron radius={2.6} tilt={[-0.88, 0.64, 0.48]} speed={-0.3} phase={2.1} />
      <OrbitElectron radius={3} tilt={[0.3, -0.72, 1.08]} speed={0.22} phase={4.2} />
      <LightningArcs />
      <Sparkles count={220} scale={10} size={2} speed={0.35} opacity={0.78} color={AMBER} noise={0.45} />

      <OrbitControls enablePan={false} minDistance={4} maxDistance={12} autoRotate autoRotateSpeed={0.35} />
      <EffectComposer>
        <Bloom intensity={1.5} luminanceThreshold={0.5} luminanceSmoothing={0.3} mipmapBlur />
      </EffectComposer>
    </>
  )
}

export function EnergyFlowWorld() {
  return (
    <div className="ef-world3d" aria-hidden="true">
      <Canvas camera={{ position: [0, 1.2, 7.5], fov: 45 }} dpr={[1, 1.6]} gl={{ antialias: true, alpha: false, powerPreference: 'high-performance' }}>
        <ReactorScene />
      </Canvas>
    </div>
  )
}
