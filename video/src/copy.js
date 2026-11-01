// Every word on screen, in one place, so the video can be reworded or translated without touching
// the scenes. Most of it comes from the landing page (frontend/src/i18n/locales/en.json).
// A "₂" is drawn as a subscript (components/Text.jsx).
export const copy = {
  tagline: 'Energy intelligence for Kosovo SMEs',

  problem: {
    eyebrow: 'The problem',
    title: 'One bill a month.',
    subtitle: 'Never which machine, when or why.',
    billTitle: 'Electricity bill',
    billPeriod: 'October 2026',
    billLines: ['Active energy', 'Reactive energy', 'Engaged power', 'VAT'],
    billTotal: 'Total',
    questions: ['Which machine?', 'When?', 'Why?'],
  },

  carbon: {
    eyebrow: 'Why it matters in Kosovo',
    title: 'A kilowatt-hour matters more in Kosovo.',
    stat: 'kg CO₂e per kWh',
    statNote: "from Kosovo's grid in 2025, mostly lignite",
    kosovo: 'Kosovo',
    eu: 'EU average',
    unit: 'g CO₂e/kWh',
    multiple: 'the CO₂ of an average EU kWh',
    source: 'Sources: Ember Yearly Electricity Data via Our World in Data; Kosovo draft NECP 2025–2030.',
  },

  flow: {
    eyebrow: 'Meet EnergyFlow',
    title: 'Measure every machine. Stop the waste.',
    steps: ['Measure', 'Detect', 'Act', 'Prove'],
    grid: 'Grid',
    meter: 'Main meter',
    machines: ['Compressor', 'Moulder', 'Chiller', 'Lighting'],
    shiftEnds: 'Shift ends 21:00',
    afterHours: 'After hours',
    detected: 'After-hours running detected',
    detectedDetail: 'Compressor · since 21:00',
    turnOff: 'Turn off',
    turningOff: 'Turning off…',
    verified: 'Turned off · verified by the meter',
    verifiedDetail: 'Compressor · 0.0 kW since 21:41',
    verifiedNote: 'The saving is tracked in Impact.',
    demoNote: 'Demo company with simulated machines',
  },

  showcase: {
    overview: {
      eyebrow: 'Overview',
      title: 'See what is running, right now',
      body: "Live power, today's energy, the projected bill and CO₂e, and an alert the moment a machine runs after hours.",
    },
    waste: {
      eyebrow: 'Waste & Alerts',
      title: 'Find the waste',
      body: 'Waste by type and by machine, in kWh, € and CO₂e, with the evidence behind it.',
    },
    carbon: {
      eyebrow: 'Carbon & ESG',
      title: 'Report your carbon',
      body: 'Scope 1 and 2 from your own meters and fuel receipts, VSME datapoints and PDF reports.',
    },
  },

  assistant: {
    eyebrow: 'Ask EnergyFlow',
    title: 'Ask in plain words',
    body: 'Answers come from your own data and are checked before you see them. It switches a machine off only after you confirm.',
    // The panel's own words (assistant.* in the app's en.json).
    panelTitle: 'Ask EnergyFlow',
    panelSubtitle: 'Your data and general energy expertise',
    placeholder: 'Ask about energy, costs, waste or CO₂…',
    footer: 'Answers about your company are checked against your data. Machines are only switched off after you confirm.',
    thinking: 'Thinking…',
    fromData: 'From your data',
    sources: 'Sources',
    // The conversation, answered as backend/src/Services/Assistant/DataAnswers.php answers it, with the demo company's figures.
    question1: 'How much energy did we waste this month?',
    answer1: [
      '**This month**: EnergyFlow found **695 kWh** of waste (5.1% of consumption), worth **€51.45** and 626 kg CO₂e, in 12 events.',
      '- After hours: 451 kWh, €30.19',
      '- Efficiency drift: 243 kWh, €21.10',
      '- Idle in production: 1.8 kWh, €0.16',
      'Biggest source: **CMP-01** Screw air compressor · 11 kW, 451 kWh (€30.19).',
    ],
    source1: 'Waste · This month',
    question2: 'Turn off the compressor',
    answer2: [
      'Turn off **Screw air compressor · 11 kW (CMP-01)** now? It is drawing 5.5 kW.',
      'It has run 40 min after hours: 3.6 kWh, €0.32, 3.3 kg CO₂e so far.',
    ],
    source2: 'Live readings · 21:40',
    yes: 'Yes, turn it off',
    no: 'No',
    sent: 'Command sent to CMP-01',
    steps: ['Command queued', 'Picked up by EF-001 · CH3', 'Relay opened (remote STOP)', 'Meter confirms 0.0 kW'],
    waiting: ['', "Waiting for the node's next uplink", 'Waiting for the relay', 'Waiting for the meter to confirm'],
    times: ['21:40:12', '21:40:14 +2 s', '21:40:15 +3 s', '21:40:17 +5 s'],
  },

  features: {
    eyebrow: 'Features',
    title: 'From measurement to proof',
    items: [
      { icon: 'live', title: 'Live monitoring', body: 'Power, cost and CO₂e per machine, every few seconds' },
      { icon: 'waste', title: 'Waste detection', body: 'After hours, idle, leaks, drift, spikes, power factor' },
      { icon: 'opportunities', title: 'Recommendations', body: 'Ranked fixes from your own data, with a What-if simulator' },
      { icon: 'control', title: 'Turn Off & automations', body: 'Only after you confirm, always verified by the meter' },
      { icon: 'impact', title: 'Verified savings', body: 'Before/after proof with a 90 % confidence interval' },
      { icon: 'carbon', title: 'Carbon & ESG', body: 'Scope 1 and 2, VSME B3 datapoints and methodology' },
      { icon: 'reports', title: 'Reports', body: 'Daily, weekly and monthly reports, ready as PDF' },
      { icon: 'scan', title: 'Scan bills & receipts', body: 'Check bills against the tariff, scan fuel receipts' },
      { icon: 'assistant', title: 'Ask EnergyFlow', body: 'AI answers, with your figures checked against your data' },
    ],
    note: 'Every feature can be tried in the demo company.',
  },

  outro: {
    title: ['Know what every machine costs.', 'Stop paying for what it wastes.'],
    demo: 'Try the live demo',
    url: 'energyflow-ks.vercel.app/demo',
    footer: 'Built for the GPEK Digital Green Innovation Competition 2026',
  },
}
