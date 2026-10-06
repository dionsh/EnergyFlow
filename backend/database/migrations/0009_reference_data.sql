-- Reference data shared by every company.

INSERT INTO machine_types (code, label_en, label_sq, icon, sort_order) VALUES
  ('compressor', 'Air compressor', 'Kompresor ajri', 'wind', 10),
  ('injection_moulding', 'Injection moulding machine', 'Makinë injektimi', 'factory', 20),
  ('cnc', 'CNC machine', 'Makinë CNC', 'cog', 30),
  ('hvac', 'HVAC', 'Ngrohje dhe klimatizim', 'thermometer', 40),
  ('chiller', 'Chiller', 'Ftohës (chiller)', 'snowflake', 50),
  ('refrigeration', 'Refrigeration', 'Frigorifer industrial', 'refrigerator', 60),
  ('pump', 'Pump', 'Pompë', 'droplets', 70),
  ('lighting', 'Lighting', 'Ndriçim', 'lightbulb', 80),
  ('oven', 'Oven', 'Furrë', 'flame', 90),
  ('office', 'Office and IT', 'Zyra dhe IT', 'monitor', 100),
  ('incomer', 'Main incomer', 'Matësi kryesor', 'gauge', 110),
  ('other', 'Other equipment', 'Pajisje tjetër', 'plug', 120);

-- Default Kosovo grid factor. Source: Ember Yearly Electricity Data, processed by
-- Our World in Data (dataset updated 2026-06-30). See docs/01-research.md section 4.1.
INSERT INTO emission_factors
  (company_id, scope, activity, region, value, unit, reference_year, valid_from, valid_to,
   methodology, source_name, source_url, notes, is_default)
VALUES
  (NULL, 'scope2_location', 'grid_electricity', 'XK', 0.900940, 'kgCO2e/kWh', 2025, '2025-01-01', NULL,
   'lifecycle',
   'Ember - Yearly Electricity Data (via Our World in Data), Kosovo 2025',
   'https://ourworldindata.org/grapher/carbon-intensity-electricity',
   'Lifecycle carbon intensity of electricity generated in Kosovo (generation-based, imports not reflected). Ember applies standard lifecycle factors per fuel type, not plant-specific measurements. Annual average, so it does not vary by hour.',
   1);
