import { oppText } from '../opportunities/oppText'

/** "Auto-off 15 min after the schedule ends" or the maintenance opportunity's title. */
export function actionLabel(t, iv) {
  if (iv.kind === 'policy') return t(`impactPage.actions.${iv.label}`, { grace: iv.policy_params?.grace_min ?? 15, defaultValue: iv.label })
  return iv.recommendation ? oppText(t, iv.recommendation).title : t(`impactPage.actions.${iv.label}`, { defaultValue: iv.label })
}
