// The energy-flow intro in index.html lifts once the first page is on screen
// (and never later than 7 s), so it also covers the time the page takes to load.
export function signalPageReady() {
  window.dispatchEvent(new Event('energyflow:ready'))
}
