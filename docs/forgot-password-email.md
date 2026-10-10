# Password reset email setup

EnergyFlow sends password reset links through the Resend API from the backend. Reset tokens are never sent from the browser or printed in the backend terminal. The HTML email is maintained in [`backend/templates/password-reset.html`](../backend/templates/password-reset.html) and rendered with the user's selected language.

## Resend setup

1. Create a Resend account and add a domain you own under **Domains**.
2. Add the DNS records Resend provides, then wait until the domain is verified. A verified sending domain is needed to send to users' arbitrary addresses.
3. Create a Resend API key with permission to send email.
4. Put these values in `backend/.env` for local development:

   ```env
   FRONTEND_URL=http://localhost:5173
   RESEND_API_KEY=re_your_api_key
   RESEND_FROM_EMAIL=EnergyFlow <no-reply@your-verified-domain.com>
   ```

   Replace the example sender address with an address on your verified domain. Keep the API key private; do not put it in frontend code, commit it, or paste it into chat.

5. Restart the PHP backend and request a password reset. Check the backend log and Resend's email logs if delivery fails.

## Production

Set `FRONTEND_URL`, `RESEND_API_KEY`, and `RESEND_FROM_EMAIL` as Render environment variables. `FRONTEND_URL` must be the deployed Vercel app URL. Keep the API key only on the backend; do not configure it in Vercel.

If the mail settings are missing, the API returns `email_not_configured`; it does not create a reset token or print a link to the terminal.
