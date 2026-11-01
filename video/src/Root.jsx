import { Composition } from 'remotion'
import { DURATION, FPS, Promo } from './Promo'

export function RemotionRoot() {
  return <Composition id="EnergyFlowPromo" component={Promo} durationInFrames={DURATION} fps={FPS} width={1920} height={1080} />
}
